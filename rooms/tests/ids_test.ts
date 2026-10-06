import assert from 'node:assert/strict';
import {
  base64url,
  base64urlDecode,
  hexToBytes,
  ipHasher,
  newParticipantId,
  newRoomId,
  newToken,
  PARTICIPANT_ID_PATTERN,
  ROOM_ID_PATTERN,
  sha256Hex,
  TOKEN_PATTERN,
  tokenMatches,
} from '../src/security/ids.ts';

Deno.test('ids have the documented shapes and do not repeat', () => {
  const rooms = new Set<string>();
  for (let i = 0; i < 2000; i++) {
    const id = newRoomId();
    assert.match(id, ROOM_ID_PATTERN);
    assert.doesNotMatch(id, /[ILOU]/, 'Crockford Base32 skips I, L, O and U');
    rooms.add(id);
  }
  assert.equal(rooms.size, 2000);
  assert.match(newParticipantId(), PARTICIPANT_ID_PATTERN);
  assert.match(newToken(), TOKEN_PATTERN);
});

Deno.test('every Base32 character is produced', () => {
  const seen = new Set<string>();
  for (let i = 0; i < 300; i++) {
    for (const ch of newRoomId()) {
      seen.add(ch);
    }
  }
  assert.equal(seen.size, 32);
});

Deno.test('tokens are compared against their stored hash only', () => {
  const token = newToken();
  const stored = sha256Hex(token);

  assert.ok(tokenMatches(token, stored));
  assert.ok(!tokenMatches(newToken(), stored));
  assert.ok(!tokenMatches(stored, stored), 'the hash itself is not a token');
  assert.ok(!tokenMatches(token.slice(1), stored));
  assert.ok(!tokenMatches(42, stored));
  assert.ok(!tokenMatches(undefined, stored));
});

Deno.test('ip hashes are keyed, short and stable', () => {
  const a = ipHasher('ab'.repeat(32));
  const b = ipHasher('cd'.repeat(32));

  assert.match(a('203.0.113.7'), /^[a-f0-9]{16}$/);
  assert.equal(a('203.0.113.7'), a('203.0.113.7'));
  assert.notEqual(a('203.0.113.7'), a('203.0.113.8'));
  assert.notEqual(a('203.0.113.7'), b('203.0.113.7'));
});

Deno.test('base64url round-trips and rejects garbage', () => {
  const bytes = crypto.getRandomValues(new Uint8Array(33));
  const encoded = base64url(bytes);

  assert.doesNotMatch(encoded, /[+/=]/);
  assert.deepEqual(base64urlDecode(encoded), bytes);
  assert.equal(base64urlDecode('a+b/'), null);
  assert.equal(base64urlDecode('abcde'), null);
  assert.deepEqual(hexToBytes('00ff10'), new Uint8Array([0, 255, 16]));
  assert.throws(() => hexToBytes('abc'));
  assert.throws(() => hexToBytes('ZZ'));
});
