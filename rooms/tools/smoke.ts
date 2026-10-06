/**
 * WebSocket smoke test used by deploy/deploy.sh:
 *   deno run --allow-net=127.0.0.1:8790 --allow-env=APP_ENV,ROOMS_SECRET rooms/tools/smoke.ts ws://127.0.0.1:8790/ws/rooms
 *
 * Two clients: create (with a signed YouTube ticket) → peek → join → play → chat → leave.
 * Exits 0 on success, 1 with a reason otherwise. Prints no secrets.
 */

import { INSECURE_DEV_SECRET } from '../src/config.ts';
import { type Message, signTicket, TestClient, youtubeTicketPayload } from '../tests/helpers.ts';

const url = Deno.args[0] ?? 'ws://127.0.0.1:8790/ws/rooms';
const secret = Deno.env.get('ROOMS_SECRET')?.trim() ||
  (Deno.env.get('APP_ENV') === 'production' ? '' : INSECURE_DEV_SECRET);

function expect(condition: boolean, what: string): void {
  if (!condition) {
    throw new Error(what);
  }
}

async function run(): Promise<void> {
  expect(secret !== '', 'ROOMS_SECRET is not set');
  const ticket = await signTicket(youtubeTicketPayload(Math.floor(Date.now() / 1000)), secret);
  const a = await TestClient.open(url);
  const b = await TestClient.open(url);

  try {
    const welcome = await a.request({ type: 'room.create', name: 'smoke-a', ticket });
    expect(welcome.type === 'welcome', `create: ${welcome.code ?? welcome.type}`);
    const roomId = (welcome.room as { roomId: string }).roomId;

    const info = await b.request({ type: 'room.peek', roomId });
    expect(info.type === 'room.info' && info.participants === 1, `peek: ${info.code ?? info.type}`);

    const joined = await b.request({ type: 'room.join', roomId, name: 'smoke-b' });
    expect(joined.type === 'welcome', `join: ${joined.code ?? joined.type}`);
    await a.next('participant.joined');

    a.send({ type: 'playback.play', positionSec: 1 });
    const state = await b.next('playback.state');
    expect(
      (state.playback as { status: string }).status === 'playing',
      'playback did not reach the second client',
    );

    b.send({ type: 'chat.send', text: 'smoke' });
    await a.next((m: Message) =>
      m.type === 'chat.message' && (m.message as { text?: string }).text === 'smoke'
    );

    expect((await b.request({ type: 'room.leave' })).type === 'ack', 'leave b');
    await a.next('participant.left');
    expect((await a.request({ type: 'room.leave' })).type === 'ack', 'leave a');
  } finally {
    await Promise.all([a.close(), b.close()]);
  }
}

try {
  await run();
  // deno-lint-ignore no-console
  console.log('rooms smoke: ok');
} catch (e) {
  // deno-lint-ignore no-console
  console.error(`rooms smoke: FAILED: ${e instanceof Error ? e.message : String(e)}`);
  Deno.exit(1);
}
