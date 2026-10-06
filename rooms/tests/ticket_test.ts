import assert from 'node:assert/strict';
import { base64url } from '../src/security/ids.ts';
import { TicketVerifier } from '../src/security/ticket.ts';

const fixture = JSON.parse(
  await Deno.readTextFile(new URL('../../tests/fixtures/watch/ticket-v1.json', import.meta.url)),
) as { secret: string; payload: Record<string, unknown>; ticket: string };

const SECRET = 'ab'.repeat(32);
const NOW = 1_800_000_000;

async function sign(payload: unknown, secretHex = SECRET): Promise<string> {
  const body = base64url(new TextEncoder().encode(JSON.stringify(payload)));
  const key = await crypto.subtle.importKey(
    'raw',
    Uint8Array.from(secretHex.match(/../g) ?? [], (h) => parseInt(h, 16)),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign'],
  );
  const signature = new Uint8Array(await crypto.subtle.sign('HMAC', key, new TextEncoder().encode(body)));

  return `${body}.${base64url(signature)}`;
}

function payload(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    v: 1,
    kind: 'youtube',
    ref: 'dQw4w9WgXcQ',
    platform: 'YouTube',
    title: null,
    durationSec: null,
    thumbnailUrl: 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg',
    startSec: 42,
    exp: NOW + 600,
    ...overrides,
  };
}

Deno.test('accepts the ticket PHP produced for the contract fixture', async () => {
  const verifier = new TicketVerifier(fixture.secret);
  const result = await verifier.verify(fixture.ticket, (fixture.payload.exp as number) - 300);

  assert.ok(result.ok, JSON.stringify(result));
  assert.deepEqual(result.media, {
    kind: fixture.payload.kind,
    ref: fixture.payload.ref,
    platform: fixture.payload.platform,
    title: fixture.payload.title,
    durationSec: fixture.payload.durationSec,
    thumbnailUrl: fixture.payload.thumbnailUrl,
    startSec: fixture.payload.startSec,
  });
});

Deno.test('accepts a valid ticket of either kind', async () => {
  const verifier = new TicketVerifier(SECRET);

  const youtube = await verifier.verify(await sign(payload()), NOW);
  const file = await verifier.verify(
    await sign(payload({ kind: 'file', ref: 'a'.repeat(32), title: 'Видео', durationSec: 213 })),
    NOW,
  );

  assert.ok(youtube.ok && youtube.media.startSec === 42);
  assert.ok(file.ok && file.media.title === 'Видео' && file.media.durationSec === 213);
});

Deno.test('rejects tampering, foreign keys and garbage', async () => {
  const verifier = new TicketVerifier(SECRET);
  const good = await sign(payload());
  const [body, signature] = good.split('.') as [string, string];
  const forgedBody = base64url(new TextEncoder().encode(JSON.stringify(payload({ ref: 'XXXXXXXXXXX' }))));
  const flipped = signature.slice(0, -2) + (signature.at(-2) === 'A' ? 'B' : 'A') + signature.at(-1);

  const cases: Array<[string, unknown]> = [
    ['payload swapped', `${forgedBody}.${signature}`],
    ['signature altered', `${body}.${flipped}`],
    ['other secret', await sign(payload(), 'cd'.repeat(32))],
    ['no signature', body],
    ['empty', ''],
    ['not a string', { ticket: good }],
    ['too long', `${'a'.repeat(5000)}.${signature}`],
    ['padding', `${body}=.${signature}`],
  ];
  for (const [name, ticket] of cases) {
    const result = await verifier.verify(ticket, NOW);
    assert.ok(!result.ok && result.code === 'INVALID_TICKET', name);
  }
});

Deno.test('enforces the expiry window', async () => {
  const verifier = new TicketVerifier(SECRET);

  const expired = await verifier.verify(await sign(payload({ exp: NOW })), NOW);
  const tooFar = await verifier.verify(await sign(payload({ exp: NOW + 901 })), NOW);
  const noExp = await verifier.verify(await sign(payload({ exp: '1800000600' })), NOW);

  assert.ok(!expired.ok && expired.code === 'TICKET_EXPIRED');
  assert.ok(!tooFar.ok && tooFar.code === 'INVALID_TICKET');
  assert.ok(!noExp.ok && noExp.code === 'INVALID_TICKET');
});

Deno.test('validates every payload field even when correctly signed', async () => {
  const verifier = new TicketVerifier(SECRET);
  const bad: Array<[string, Record<string, unknown>]> = [
    ['version', { v: 2 }],
    ['kind', { kind: 'url' }],
    ['youtube ref', { ref: 'short' }],
    ['file ref for youtube', { ref: 'a'.repeat(32) }],
    ['file ref', { kind: 'file', ref: '../../etc/passwd' }],
    ['platform', { platform: '' }],
    ['title type', { title: 42 }],
    ['duration', { durationSec: -1 }],
    ['duration NaN', { durationSec: 'NaN' }],
    ['duration fraction', { durationSec: 1.5 }],
    ['start', { startSec: -5 }],
    ['thumbnail scheme', { thumbnailUrl: 'javascript:alert(1)' }],
    ['thumbnail http', { thumbnailUrl: 'http://example.com/a.jpg' }],
    ['thumbnail userinfo', { thumbnailUrl: 'https://user:pass@example.com/a.jpg' }],
  ];
  for (const [name, overrides] of bad) {
    const result = await verifier.verify(await sign(payload(overrides)), NOW);
    assert.ok(!result.ok && result.code === 'INVALID_TICKET', name);
  }

  const array = await verifier.verify(await sign([1, 2, 3]), NOW);
  assert.ok(!array.ok);
});

Deno.test('titles from tickets are sanitized again', async () => {
  const verifier = new TicketVerifier(SECRET);
  const result = await verifier.verify(
    await sign(payload({ kind: 'file', ref: 'f'.repeat(32), title: ' \u202Eevil\u0000 title ' })),
    NOW,
  );

  assert.ok(result.ok && result.media.title === 'evil title');
});
