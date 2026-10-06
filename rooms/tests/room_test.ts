import assert from 'node:assert/strict';
import type { Failure } from '../src/domain/errors.ts';
import { CHAT_HISTORY, KICK_BAN_MS, type Participant, Room, type RoomEvent } from '../src/domain/room.ts';
import type { TicketMedia } from '../src/security/ticket.ts';

const T0 = 1_800_000_000_000;
const GRACE = 45_000;

const MEDIA: TicketMedia = {
  kind: 'file',
  ref: 'a'.repeat(32),
  platform: 'ВКонтакте',
  title: 'Видео',
  durationSec: 600,
  thumbnailUrl: null,
  startSec: 10,
};

function ok<T extends { ok: boolean }>(result: T): Exclude<T, Failure> {
  assert.ok(result.ok, `expected success, got ${JSON.stringify(result)}`);

  return result as Exclude<T, Failure>;
}

function code(result: { ok: boolean }): string | undefined {
  return result.ok ? undefined : (result as Failure).code;
}

function join(room: Room, name: string, ip = `ip-${name}`, now = T0): Participant {
  return ok(room.join(name, ip.padEnd(16, '0').slice(0, 16), now)).participant;
}

function types(events: RoomEvent[]): string[] {
  return events.map((e) => e.type === 'chat.message' ? `chat:${e.message.event ?? 'user'}` : e.type);
}

Deno.test('the first participant becomes host and gets a token', () => {
  const room = new Room('7F4K2QX9MD3P', T0, 5);
  const result = ok(room.join('Маша', 'aaaaaaaaaaaaaaaa', T0));

  assert.equal(room.hostId, result.participant.id);
  assert.match(result.token, /^[A-Za-z0-9_-]{43}$/);
  assert.notEqual(result.participant.tokenHash, result.token);
  assert.deepEqual(types(result.events), ['participant.joined', 'chat:joined']);
  assert.equal(room.emptySince, null);
});

Deno.test('capacity is 5; the sixth gets ROOM_FULL', () => {
  const room = new Room('7F4K2QX9MD3P', T0, 5);
  for (const name of ['A', 'B', 'C', 'D', 'E']) {
    join(room, name);
  }

  assert.equal(code(room.join('F', 'ffffffffffffffff', T0)), 'ROOM_FULL');
  assert.equal(room.admissionError('ffffffffffffffff', T0), 'ROOM_FULL');
});

Deno.test('a participant in grace keeps the seat; grace expiry frees it', () => {
  const room = new Room('7F4K2QX9MD3P', T0, 2);
  join(room, 'A');
  const b = join(room, 'B');

  assert.deepEqual(types(room.disconnect(b.id, T0)), ['participant.updated']);
  assert.equal(code(room.join('C', 'cccccccccccccccc', T0 + 1000)), 'ROOM_FULL');

  assert.deepEqual(room.expireGrace(T0 + GRACE - 1, GRACE).removed, []);
  const expired = room.expireGrace(T0 + GRACE, GRACE);
  assert.deepEqual(expired.removed, [b.id]);
  assert.deepEqual(types(expired.events), ['participant.left', 'chat:left']);
  assert.ok(expired.events.some((e) => e.type === 'participant.left' && e.reason === 'timeout'));
  ok(room.join('C', 'cccccccccccccccc', T0 + GRACE));
});

Deno.test('names are unique per room regardless of case and look-alikes', () => {
  const room = new Room('7F4K2QX9MD3P', T0, 5);
  join(room, 'Алёна');

  assert.equal(code(room.join('алена', 'bbbbbbbbbbbbbbbb', T0)), 'NAME_TAKEN');
  assert.equal(code(room.join('АЛЁНА ', 'bbbbbbbbbbbbbbbb', T0)), 'NAME_TAKEN');
  assert.equal(code(room.join('', 'bbbbbbbbbbbbbbbb', T0)), 'NAME_INVALID');
  assert.equal(code(room.join('Вы', 'bbbbbbbbbbbbbbbb', T0)), 'NAME_INVALID');
  ok(room.join('Алёна 2', 'bbbbbbbbbbbbbbbb', T0));
});

Deno.test('avatar colors use the first free index', () => {
  const room = new Room('7F4K2QX9MD3P', T0, 5);
  const a = join(room, 'A');
  const b = join(room, 'B');
  join(room, 'C');
  room.leave(b.id, T0);

  assert.deepEqual([a.color, b.color], [0, 1]);
  assert.equal(join(room, 'D').color, 1);
});

Deno.test('resume needs the right token and restores the participant', () => {
  const room = new Room('7F4K2QX9MD3P', T0, 5);
  const { participant, token } = ok(room.join('Маша', 'aaaaaaaaaaaaaaaa', T0));
  room.disconnect(participant.id, T0);

  assert.equal(code(room.resume(participant.id, 'x'.repeat(43))), 'RESUME_FAILED');
  assert.equal(code(room.resume('ZZZZZZZZZZ', token)), 'RESUME_FAILED');
  assert.equal(code(room.resume(participant.id, participant.tokenHash)), 'RESUME_FAILED');

  const resumed = ok(room.resume(participant.id, token));
  assert.equal(resumed.replaced, false);
  assert.ok(room.participants.get(participant.id)?.connected);
  assert.deepEqual(types(resumed.events), ['participant.updated']);

  assert.equal(ok(room.resume(participant.id, token)).replaced, true, 'a second socket replaces the first');
});

Deno.test('host leaves: the earliest connected participant takes over', () => {
  const room = new Room('7F4K2QX9MD3P', T0, 5);
  const host = join(room, 'Host');
  const b = join(room, 'B');
  const c = join(room, 'C');
  room.disconnect(b.id, T0);

  const events = room.leave(host.id, T0 + 1);

  assert.equal(room.hostId, c.id, 'B is earlier but offline');
  assert.deepEqual(types(events), ['participant.left', 'chat:left', 'participant.updated', 'chat:host']);
});

Deno.test('host transfer and kick are host-only', () => {
  const room = new Room('7F4K2QX9MD3P', T0, 5);
  const host = join(room, 'Host');
  const b = join(room, 'B');

  assert.equal(code(room.kick(b.id, host.id, T0)), 'FORBIDDEN');
  assert.equal(code(room.transferHost(b.id, b.id, T0)), 'FORBIDDEN');
  assert.equal(code(room.transferHost(host.id, host.id, T0)), 'BAD_MESSAGE');
  assert.equal(code(room.transferHost(host.id, 'ZZZZZZZZZZ', T0)), 'BAD_MESSAGE');

  const transfer = ok(room.transferHost(host.id, b.id, T0));
  assert.equal(room.hostId, b.id);
  assert.deepEqual(types(transfer.events), ['participant.updated', 'participant.updated', 'chat:host']);
  assert.equal(code(room.setMedia(host.id, MEDIA, T0)), 'FORBIDDEN', 'the former host lost the rights');
});

Deno.test('kick removes the participant and bans the IP for 10 minutes', () => {
  const room = new Room('7F4K2QX9MD3P', T0, 5);
  const host = join(room, 'Host', 'hosthosthosthost');
  const b = join(room, 'B', 'bbbbbbbbbbbbbbbb');

  const kick = ok(room.kick(host.id, b.id, T0));

  assert.equal(kick.kickedId, b.id);
  assert.ok(!room.participants.has(b.id));
  assert.ok(kick.events.some((e) => e.type === 'participant.left' && e.reason === 'kicked'));
  assert.equal(code(room.resume(b.id, 'whatever')), 'RESUME_FAILED', 'the token is revoked');
  assert.equal(code(room.join('B2', 'bbbbbbbbbbbbbbbb', T0 + KICK_BAN_MS - 1)), 'KICKED');
  ok(room.join('B2', 'bbbbbbbbbbbbbbbb', T0 + KICK_BAN_MS));
  assert.equal(code(room.kick(host.id, host.id, T0)), 'BAD_MESSAGE');
});

Deno.test('media is host-only and resets playback to its start, paused', () => {
  const room = new Room('7F4K2QX9MD3P', T0, 5);
  const host = join(room, 'Host');
  const guest = join(room, 'Guest');
  const seqBefore = room.playback.seq;

  assert.equal(code(room.setMedia(guest.id, MEDIA, T0)), 'FORBIDDEN');
  const set = ok(room.setMedia(host.id, MEDIA, T0 + 5));

  assert.deepEqual(types(set.events), ['media.changed', 'chat:media']);
  assert.equal(room.media?.setAt, T0 + 5);
  assert.deepEqual(room.playback, {
    status: 'paused',
    positionSec: 10,
    updatedAt: T0 + 5,
    rate: 1,
    seq: seqBefore + 1,
    by: host.id,
  });
});

Deno.test('anyone can play, pause and seek; positions are validated; seq grows', () => {
  const room = new Room('7F4K2QX9MD3P', T0, 5);
  const host = join(room, 'Host');
  const guest = join(room, 'Guest');

  assert.equal(code(room.command(guest.id, 'play', 0, T0)), 'NO_MEDIA');
  ok(room.setMedia(host.id, MEDIA, T0));
  const seq = room.playback.seq;

  ok(room.command(guest.id, 'play', 12.5, T0 + 100));
  assert.equal(room.playback.status, 'playing');
  assert.equal(room.playback.by, guest.id);
  ok(room.command(host.id, 'seek', 300, T0 + 200));
  assert.equal(room.playback.status, 'playing', 'seek keeps the status');
  ok(room.command(host.id, 'pause', 301, T0 + 300));
  assert.equal(room.playback.status, 'paused');
  assert.equal(room.playback.seq, seq + 3);

  for (const bad of [-1, 606, Number.NaN, Infinity, '10', null]) {
    assert.equal(code(room.command(guest.id, 'seek', bad, T0)), 'INVALID_POSITION', String(bad));
  }
  ok(room.command(guest.id, 'seek', 604.9, T0)); // a little past the end is tolerated
  assert.equal(code(room.command('ZZZZZZZZZZ', 'play', 1, T0)), 'NOT_IN_ROOM');
});

Deno.test('chat keeps the last 100 messages; invalid text is rejected', () => {
  const room = new Room('7F4K2QX9MD3P', T0, 5);
  const a = join(room, 'A');

  assert.equal(code(room.chatMessage(a.id, '   ', T0)), 'CHAT_INVALID');
  assert.equal(code(room.chatMessage(a.id, 'x'.repeat(501), T0)), 'CHAT_INVALID');
  for (let i = 0; i < 150; i++) {
    ok(room.chatMessage(a.id, `msg ${i}`, T0 + i));
  }

  assert.equal(room.chat.length, CHAT_HISTORY);
  assert.equal(room.chat.at(-1)?.text, 'msg 149');
  assert.equal(room.snapshot().chat.length, 50);
  assert.equal(room.snapshot().chat.at(-1)?.text, 'msg 149');
});

Deno.test('messages of kicked participants stay in the chat', () => {
  const room = new Room('7F4K2QX9MD3P', T0, 5);
  const host = join(room, 'Host');
  const b = join(room, 'B');
  ok(room.chatMessage(b.id, 'hello', T0));

  ok(room.kick(host.id, b.id, T0));

  assert.ok(room.chat.some((m) => m.kind === 'user' && m.text === 'hello'));
});

Deno.test('presence and rename are deduplicated and validated', () => {
  const room = new Room('7F4K2QX9MD3P', T0, 5);
  const a = join(room, 'A');
  join(room, 'B');

  assert.equal(ok(room.setPresence(a.id, 'watching')).events.length, 1);
  assert.equal(ok(room.setPresence(a.id, 'watching')).events.length, 0);
  assert.equal(code(room.setPresence(a.id, 'dancing')), 'BAD_MESSAGE');
  assert.equal(code(room.rename(a.id, 'b')), 'NAME_TAKEN');
  assert.equal(
    ok(room.rename(a.id, 'a')).events.length,
    1,
    'changing only the case of your own name is fine',
  );
  assert.equal(code(room.rename(a.id, '')), 'NAME_INVALID');
});

Deno.test('the last one out marks the room empty', () => {
  const room = new Room('7F4K2QX9MD3P', T0, 5);
  const a = join(room, 'A');
  room.leave(a.id, T0 + 10);

  assert.equal(room.size, 0);
  assert.equal(room.hostId, null);
  assert.equal(room.emptySince, T0 + 10);
  const b = join(room, 'B', 'bbbbbbbbbbbbbbbb', T0 + 20);
  assert.equal(room.hostId, b.id, 'whoever comes back first hosts');
});

Deno.test('state round-trip keeps hashes, not tokens, and restarts grace', () => {
  const room = new Room('7F4K2QX9MD3P', T0, 5);
  const { participant, token } = ok(room.join('Маша', 'aaaaaaaaaaaaaaaa', T0));
  ok(room.setMedia(participant.id, MEDIA, T0));
  ok(room.chatMessage(participant.id, 'hi', T0));

  const state = JSON.parse(JSON.stringify(room.toState()));
  assert.ok(!JSON.stringify(state).includes(token));

  const restored = Room.fromState(state, T0 + 60_000);
  const p = restored.participants.get(participant.id);
  assert.equal(p?.connected, false);
  assert.equal(p?.disconnectedAt, T0 + 60_000);
  assert.equal(restored.hostId, participant.id);
  assert.deepEqual(restored.media, room.media);
  assert.deepEqual(restored.chat, room.chat);
  ok(restored.resume(participant.id, token));
});
