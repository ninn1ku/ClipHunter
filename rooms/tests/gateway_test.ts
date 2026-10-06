/**
 * Integration: a real Deno.serve on a random port and real WebSocket clients.
 */

import assert from 'node:assert/strict';
import { FakeClock } from '../src/clock.ts';
import { type Config, loadConfig } from '../src/config.ts';
import { memoryLogger } from '../src/log.ts';
import { Gateway } from '../src/server.ts';
import { type Message, signTicket, TestClient, youtubeTicketPayload } from './helpers.ts';

const ORIGIN = 'https://cliphunter.test';
const SECRET = 'ab'.repeat(32);

interface Harness {
  gateway: Gateway;
  clock: FakeClock;
  log: ReturnType<typeof memoryLogger>;
  config: Config;
  url: string;
  http: string;
  connect(headers?: Record<string, string>): Promise<TestClient>;
  ticket(overrides?: Record<string, unknown>): Promise<string>;
  /** Restarts the gateway on the same snapshot directory (simulates a deploy). */
  restart(): Promise<void>;
  close(): Promise<void>;
}

async function harness(overrides: Partial<Config> = {}, dir?: string): Promise<Harness> {
  const snapshotDir = dir ?? await Deno.makeTempDir({ prefix: 'rooms-gw-' });
  const config: Config = {
    ...loadConfig((name) => ({ APP_ENV: 'testing', APP_URL: ORIGIN, ROOMS_SECRET: SECRET })[name], '/unused'),
    snapshotDir,
    ...overrides,
  };
  const clock = new FakeClock(Date.now());
  const log = memoryLogger();
  const clients: TestClient[] = [];
  let gateway = new Gateway(config, log, clock);
  let server = Deno.serve({ hostname: '127.0.0.1', port: 0, onListen(): void {} }, gateway.handler);

  const h: Harness = {
    get gateway() {
      return gateway;
    },
    clock,
    log,
    config,
    get url() {
      return `ws://127.0.0.1:${server.addr.port}/ws/rooms`;
    },
    get http() {
      return `http://127.0.0.1:${server.addr.port}`;
    },
    async connect(headers = {}): Promise<TestClient> {
      const client = await TestClient.open(h.url, headers);
      clients.push(client);
      return client;
    },
    ticket: (o = {}) => signTicket(youtubeTicketPayload(Math.floor(clock.now() / 1000), o), SECRET),
    async restart(): Promise<void> {
      await gateway.stop();
      await Promise.all(clients.map((c) => c.closed));
      await server.shutdown();
      gateway = new Gateway(config, log, clock);
      await gateway.restore();
      server = Deno.serve({ hostname: '127.0.0.1', port: 0, onListen(): void {} }, gateway.handler);
    },
    async close(): Promise<void> {
      await Promise.all(clients.map((c) => c.close()));
      await gateway.stop();
      await server.shutdown();
      if (dir === undefined) {
        await Deno.remove(snapshotDir, { recursive: true });
      }
    },
  };

  return h;
}

function test(name: string, fn: (h: Harness) => Promise<void>, overrides: Partial<Config> = {}): void {
  Deno.test(name, async () => {
    const h = await harness(overrides);
    try {
      await fn(h);
    } finally {
      await h.close();
    }
  });
}

async function createRoom(
  h: Harness,
  name = 'Маша',
  withMedia = true,
): Promise<{ client: TestClient; welcome: Message }> {
  const client = await h.connect();
  const welcome = await client.request({
    type: 'room.create',
    name,
    ticket: withMedia ? await h.ticket() : null,
  });
  assert.equal(welcome.type, 'welcome', JSON.stringify(welcome));

  return { client, welcome };
}

async function joinRoom(
  h: Harness,
  roomId: string,
  name: string,
  headers: Record<string, string> = {},
): Promise<{
  client: TestClient;
  welcome: Message;
}> {
  const client = await h.connect(headers);
  const welcome = await client.request({ type: 'room.join', roomId, name });

  return { client, welcome };
}

type Room = {
  roomId: string;
  participants: Array<{ id: string; name: string; isHost: boolean }>;
  media: unknown;
};
const roomOf = (welcome: Message): Room => welcome.room as Room;
const you = (welcome: Message): { participantId: string; token?: string; isHost: boolean } =>
  welcome.you as { participantId: string; token?: string; isHost: boolean };

test('create → join → playback and chat reach everyone', async (h) => {
  const host = await createRoom(h);
  const roomId = roomOf(host.welcome).roomId;
  assert.match(roomId, /^[0-9A-HJKMNP-TV-Z]{12}$/);
  assert.ok(you(host.welcome).isHost);
  assert.match(you(host.welcome).token ?? '', /^[A-Za-z0-9_-]{43}$/);
  assert.equal(host.welcome.protocol, 1);
  assert.equal((roomOf(host.welcome).media as { ref: string }).ref, 'dQw4w9WgXcQ');

  const peek = await (await h.connect()).request({ type: 'room.peek', roomId });
  assert.deepEqual([peek.type, peek.participants, peek.capacity], ['room.info', 1, 5]);

  const guest = await joinRoom(h, roomId, 'Дима');
  assert.equal(guest.welcome.type, 'welcome');
  assert.equal(you(guest.welcome).isHost, false);
  assert.deepEqual(roomOf(guest.welcome).participants.map((p) => p.name), ['Маша', 'Дима']);
  const joined = await host.client.next('participant.joined');
  assert.equal((joined.participant as { name: string }).name, 'Дима');

  const ack = await guest.client.request({ type: 'playback.play', positionSec: 12.5 });
  assert.equal(ack.type, 'ack');
  const [a, b] = await Promise.all([host.client.next('playback.state'), guest.client.next('playback.state')]);
  assert.deepEqual(a, b, 'the sender gets the same state as everyone else');
  assert.equal((a.playback as { status: string }).status, 'playing');

  guest.client.send({ type: 'chat.send', text: '<img src=x onerror=alert(1)> привет' });
  const chat = await host.client.next((m) =>
    m.type === 'chat.message' && (m.message as { kind: string }).kind === 'user'
  );
  assert.equal((chat.message as { text: string }).text, '<img src=x onerror=alert(1)> привет');
});

test('the room holds five; the sixth is refused', async (h) => {
  const host = await createRoom(h);
  const roomId = roomOf(host.welcome).roomId;
  for (const name of ['B', 'C', 'D', 'E']) {
    const { welcome } = await joinRoom(h, roomId, name, { 'x-real-ip': `198.51.100.${name.charCodeAt(0)}` });
    assert.equal(welcome.type, 'welcome');
  }

  const sixth = await joinRoom(h, roomId, 'F', { 'x-real-ip': '198.51.100.99' });

  assert.deepEqual([sixth.welcome.type, sixth.welcome.code], ['error', 'ROOM_FULL']);
  assert.ok(h.log.entries.some((e) => e.event === 'room.full'));
});

test('a reconnect with the token resumes the same participant', async (h) => {
  const host = await createRoom(h);
  const roomId = roomOf(host.welcome).roomId;
  const guest = await joinRoom(h, roomId, 'Дима');
  const { participantId, token } = you(guest.welcome);

  await guest.client.close();
  const offline = await host.client.next('participant.updated');
  assert.equal((offline.participant as { connected: boolean }).connected, false);

  const again = await h.connect();
  const welcome = await again.request({ type: 'room.resume', roomId, participantId, token });
  assert.equal(welcome.type, 'welcome');
  assert.equal(you(welcome).participantId, participantId);
  assert.equal(you(welcome).token, undefined, 'the token is only sent once');
  const online = await host.client.next('participant.updated');
  assert.equal((online.participant as { connected: boolean }).connected, true);

  const wrong = await (await h.connect()).request({
    type: 'room.resume',
    roomId,
    participantId,
    token: 'x'.repeat(43),
  });
  assert.equal(wrong.code, 'RESUME_FAILED');
});

test('resuming from a second socket replaces the first (close 4002)', async (h) => {
  const host = await createRoom(h);
  const { participantId, token } = you(host.welcome);
  const roomId = roomOf(host.welcome).roomId;

  const second = await h.connect();
  assert.equal((await second.request({ type: 'room.resume', roomId, participantId, token })).type, 'welcome');

  assert.equal((await host.client.closed).code, 4002);
  second.send({ type: 'chat.send', text: 'still here' });
  await second.next('chat.message');
});

test('the host kicks: close 4001, the IP is banned, others are told', async (h) => {
  const host = await createRoom(h);
  const roomId = roomOf(host.welcome).roomId;
  const guest = await joinRoom(h, roomId, 'Дима', { 'x-real-ip': '198.51.100.7' });
  const third = await joinRoom(h, roomId, 'Алекс', { 'x-real-ip': '198.51.100.8' });

  const denied = await third.client.request({
    type: 'participant.kick',
    participantId: you(guest.welcome).participantId,
  });
  assert.equal(denied.code, 'FORBIDDEN');

  host.client.send({ type: 'participant.kick', participantId: you(guest.welcome).participantId });
  assert.equal((await guest.client.next('kicked')).type, 'kicked');
  assert.equal((await guest.client.closed).code, 4001);
  const left = await third.client.next('participant.left');
  assert.equal(left.reason, 'kicked');

  const back = await joinRoom(h, roomId, 'Дима 2', { 'x-real-ip': '198.51.100.7' });
  assert.equal(back.welcome.code, 'KICKED');
});

test('media can only be changed by the host and only with a valid ticket', async (h) => {
  const host = await createRoom(h, 'Маша', false);
  const roomId = roomOf(host.welcome).roomId;
  const guest = await joinRoom(h, roomId, 'Дима');

  assert.equal(
    (await guest.client.request({ type: 'media.set', ticket: await h.ticket() })).code,
    'FORBIDDEN',
  );
  assert.equal(
    (await host.client.request({ type: 'media.set', ticket: 'forged.ticket' })).code,
    'INVALID_TICKET',
  );
  assert.equal(
    (await host.client.request({ type: 'media.set', ticket: await h.ticket({ exp: 1 }) })).code,
    'TICKET_EXPIRED',
  );
  assert.equal((await guest.client.request({ type: 'playback.play', positionSec: 0 })).code, 'NO_MEDIA');

  assert.equal(
    (await host.client.request({ type: 'media.set', ticket: await h.ticket({ startSec: 30 }) })).type,
    'ack',
  );
  const changed = await guest.client.next('media.changed');
  assert.equal((changed.playback as { positionSec: number }).positionSec, 30);
  assert.ok(h.log.entries.some((e) => e.event === 'ticket.invalid'));
});

test('an invalid ticket on create creates no room', async (h) => {
  const client = await h.connect();
  const reply = await client.request({ type: 'room.create', name: 'Маша', ticket: 'garbage' });

  assert.equal(reply.code, 'INVALID_TICKET');
  assert.equal(h.gateway.registry.size, 0);
});

test('lobby and room messages are kept apart', async (h) => {
  const client = await h.connect();

  assert.equal((await client.request({ type: 'chat.send', text: 'hi' })).code, 'NOT_IN_ROOM');
  assert.equal((await client.request({ type: 'room.peek', roomId: 'ABCDEFGHJKMN' })).code, 'ROOM_NOT_FOUND');
  assert.equal((await client.request({ type: 'room.peek', roomId: '../../etc' })).code, 'BAD_MESSAGE');
  assert.equal((await client.request({ type: 'room.create', name: '' })).code, 'NAME_INVALID');
  assert.equal((await client.request({ type: 'no.such.type' })).code, 'UNKNOWN_TYPE');
  const pong = await client.request({ type: 'ping', t: 123.5 });
  assert.equal(pong.t, 123.5);
  assert.equal(typeof pong.serverTime, 'number');

  const host = await createRoom(h);
  assert.equal(
    (await host.client.request({ type: 'room.join', roomId: 'ABCDEFGHJKMN', name: 'X' })).code,
    'ALREADY_IN_ROOM',
  );
});

test('leaving acknowledges, closes with 1000 and hands over the host role', async (h) => {
  const host = await createRoom(h);
  const roomId = roomOf(host.welcome).roomId;
  const guest = await joinRoom(h, roomId, 'Дима');

  assert.equal((await host.client.request({ type: 'room.leave' })).type, 'ack');
  assert.equal((await host.client.closed).code, 1000);

  const promoted = await guest.client.next((m) =>
    m.type === 'participant.updated' && (m.participant as { isHost: boolean }).isHost
  );
  assert.equal((promoted.participant as { id: string }).id, you(guest.welcome).participantId);
  assert.equal((await guest.client.request({ type: 'media.set', ticket: await h.ticket() })).type, 'ack');
});

test('participants whose grace ran out are removed by the sweep', async (h) => {
  const host = await createRoom(h);
  const guest = await joinRoom(h, roomOf(host.welcome).roomId, 'Дима');
  await guest.client.close();
  await host.client.next('participant.updated');

  h.clock.advance(h.config.reconnectGraceMs);
  h.gateway.sweep();

  const left = await host.client.next('participant.left');
  assert.equal(left.reason, 'timeout');
});

test('rooms past their maximum age are closed with 4004', async (h) => {
  const host = await createRoom(h);

  h.clock.advance(h.config.maxAgeMs);
  h.gateway.sweep();

  assert.equal((await host.client.next('room.closed')).reason, 'expired');
  assert.equal((await host.client.closed).code, 4004);
});

test('a restart keeps rooms and chat; clients get 1012 and resume', async (h) => {
  const host = await createRoom(h);
  const roomId = roomOf(host.welcome).roomId;
  const { participantId, token } = you(host.welcome);
  host.client.send({ type: 'chat.send', text: 'до рестарта' });
  await host.client.next('chat.message');

  await h.restart();

  assert.equal((await host.client.closed).code, 1012);
  const again = await h.connect();
  const welcome = await again.request({ type: 'room.resume', roomId, participantId, token });
  assert.equal(welcome.type, 'welcome');
  const chat = (welcome.room as { chat: Array<{ text?: string }> }).chat;
  assert.ok(chat.some((m) => m.text === 'до рестарта'));
  const raw = await Deno.readTextFile(`${h.config.snapshotDir}/state.json`);
  assert.ok(!raw.includes(token as string), 'no raw token on disk');
});

test('a foreign Origin is refused with 403; same origin and no origin are accepted', async (h) => {
  const handshake = (origin?: string) =>
    fetch(h.url.replace('ws://', 'http://'), {
      headers: {
        connection: 'Upgrade',
        upgrade: 'websocket',
        'sec-websocket-version': '13',
        'sec-websocket-key': 'dGhlIHNhbXBsZSBub25jZQ==',
        ...(origin === undefined ? {} : { origin }),
      },
    });

  const foreign = await handshake('https://evil.example');
  await foreign.body?.cancel();
  assert.equal(foreign.status, 403);
  assert.ok(h.log.entries.some((e) => e.event === 'ws.rejected' && e.reason === 'origin'));

  const same = await h.connect({ origin: ORIGIN });
  const none = await h.connect();
  assert.equal(same.ws.readyState, WebSocket.OPEN);
  assert.equal(none.ws.readyState, WebSocket.OPEN);

  const plain = await fetch(h.url.replace('ws://', 'http://'));
  await plain.body?.cancel();
  assert.equal(plain.status, 426);
});

test('connections per IP are limited', async (h) => {
  const ip = { 'x-real-ip': '203.0.113.50' };
  for (let i = 0; i < h.config.maxConnectionsPerIp; i++) {
    await h.connect(ip);
  }

  await assert.rejects(TestClient.open(h.url, ip));
  await h.connect({ 'x-real-ip': '203.0.113.51' });
}, { maxConnectionsPerIp: 3 });

test('flooding closes the socket with 1008', async (h) => {
  const client = await h.connect();
  // The burst (60) passes; the next three are violations and the third one closes the socket.
  for (let i = 0; i < 63; i++) {
    client.send({ type: 'ping', t: i });
  }

  assert.equal((await client.closed).code, 1008);
  assert.ok(h.log.entries.some((e) => e.event === 'ws.flood'));
});

test('repeated garbage closes the socket with 1008', async (h) => {
  const client = await h.connect();
  client.sendRaw('not json');
  client.sendRaw('[1,2]');
  client.sendRaw('{"type":42}');

  assert.equal((await client.closed).code, 1008);
});

test('frames over 4096 bytes close the socket with 1009', async (h) => {
  const client = await h.connect();
  client.send({ type: 'chat.send', text: 'ж'.repeat(2100) });

  assert.equal((await client.closed).code, 1009);
});

test('join attempts are rate limited per IP', async (h) => {
  const client = await h.connect();
  const codes: unknown[] = [];
  for (let i = 0; i < 3; i++) {
    codes.push((await client.request({ type: 'room.peek', roomId: 'ABCDEFGHJKMN' })).code);
  }

  assert.deepEqual(codes, ['ROOM_NOT_FOUND', 'ROOM_NOT_FOUND', 'RATE_LIMITED']);
}, { rateJoin: { limit: 2, windowMs: 600_000 } });

test('healthz answers on loopback only', async (h) => {
  const ok = await fetch(`${h.http}/healthz`);
  const body = await ok.json();
  const proxied = await fetch(`${h.http}/healthz`, { headers: { 'x-real-ip': '203.0.113.1' } });
  await proxied.body?.cancel();
  const other = await fetch(`${h.http}/watch`);
  await other.body?.cancel();

  assert.equal(body.status, 'ok');
  assert.equal(typeof body.rooms, 'number');
  assert.equal(proxied.status, 404);
  assert.equal(other.status, 404, 'no dev proxy outside development');
});

test('logs never contain names, chat text, tokens or full room ids', async (h) => {
  const host = await createRoom(h, 'СекретноеИмя');
  const roomId = roomOf(host.welcome).roomId;
  host.client.send({ type: 'chat.send', text: 'секретный текст' });
  await host.client.next('chat.message');
  const guest = await joinRoom(h, roomId, 'ДругоеИмя');
  await guest.client.close();

  const logs = JSON.stringify(h.log.entries);
  for (
    const secret of [
      'СекретноеИмя',
      'ДругоеИмя',
      'секретный текст',
      roomId,
      you(host.welcome).token as string,
    ]
  ) {
    assert.ok(!logs.includes(secret), `logs leak ${secret}`);
  }
});
