/**
 * Load test: N rooms × 5 clients exchanging pings, playback commands and chat.
 *
 *   deno run --allow-net=127.0.0.1 --allow-env=APP_ENV,ROOMS_SECRET rooms/tools/loadtest.ts \
 *     ws://127.0.0.1:8790/ws/rooms [rooms=50] [seconds=60]
 *
 * Each room comes from its own X-Real-IP (trusted from loopback only), so the per-IP creation and
 * connection limits are not what is being measured. Reports fan-out latency percentiles and errors.
 * Target on the production server: p95 < 50 ms, no disconnects.
 */

import { INSECURE_DEV_SECRET } from '../src/config.ts';
import { signTicket, TestClient, youtubeTicketPayload } from '../tests/helpers.ts';

const url = Deno.args[0] ?? 'ws://127.0.0.1:8790/ws/rooms';
const roomCount = Number(Deno.args[1] ?? 50);
const seconds = Number(Deno.args[2] ?? 60);
const secret = Deno.env.get('ROOMS_SECRET')?.trim() ||
  (Deno.env.get('APP_ENV') === 'production' ? '' : INSECURE_DEV_SECRET);

const latencies: number[] = [];
let errors = 0;
let unexpectedCloses = 0;

async function room(index: number): Promise<TestClient[]> {
  const headers = { 'x-real-ip': `10.77.${Math.floor(index / 250)}.${index % 250 + 1}` };
  const ticket = await signTicket(youtubeTicketPayload(Math.floor(Date.now() / 1000)), secret);
  const host = await TestClient.open(url, headers);
  const welcome = await host.request({ type: 'room.create', name: `load-${index}-0`, ticket });
  if (welcome.type !== 'welcome') {
    throw new Error(`room ${index}: ${welcome.code}`);
  }
  const roomId = (welcome.room as { roomId: string }).roomId;
  const clients = [host];
  for (let i = 1; i < 5; i++) {
    const client = await TestClient.open(url, headers);
    const joined = await client.request({ type: 'room.join', roomId, name: `load-${index}-${i}` });
    if (joined.type !== 'welcome') {
      throw new Error(`room ${index} join: ${joined.code}`);
    }
    clients.push(client);
  }
  for (const client of clients) {
    client.ws.addEventListener('message', (event) => {
      const m = JSON.parse(String(event.data));
      if (
        m.type === 'chat.message' && typeof m.message?.text === 'string' && m.message.text.startsWith('t=')
      ) {
        latencies.push(Date.now() - Number(m.message.text.slice(2)));
      } else if (m.type === 'error') {
        errors++;
      }
    });
    client.ws.addEventListener('close', (event) => {
      if (event.code !== 1000) {
        unexpectedCloses++;
      }
    });
  }

  return clients;
}

const rooms = await Promise.all(Array.from({ length: roomCount }, (_, i) => room(i)));
// deno-lint-ignore no-console
console.log(`connected ${rooms.length} rooms × 5 clients; running ${seconds}s`);

const deadline = Date.now() + seconds * 1000;
let tick = 0;
while (Date.now() < deadline) {
  tick++;
  for (const clients of rooms) {
    const sender = clients[tick % clients.length] as TestClient;
    sender.send({ type: 'chat.send', text: `t=${Date.now()}` });
    sender.send({ type: tick % 2 === 0 ? 'playback.play' : 'playback.pause', positionSec: tick % 200 });
    for (const client of clients) {
      client.send({ type: 'ping', t: performance.now() });
    }
  }
  await new Promise((resolve) => setTimeout(resolve, 1000));
}

await new Promise((resolve) => setTimeout(resolve, 1000));
for (const clients of rooms) {
  for (const client of clients) {
    client.send({ type: 'room.leave' });
  }
}
await Promise.all(rooms.flat().map((client) => client.closed));

latencies.sort((a, b) => a - b);
const pct = (p: number): number =>
  latencies[Math.min(latencies.length - 1, Math.floor(latencies.length * p))] ?? NaN;
// deno-lint-ignore no-console
console.log(JSON.stringify({
  rooms: roomCount,
  clients: roomCount * 5,
  deliveries: latencies.length,
  p50Ms: pct(0.5),
  p95Ms: pct(0.95),
  p99Ms: pct(0.99),
  maxMs: latencies.at(-1) ?? NaN,
  errors,
  unexpectedCloses,
}));
