import assert from 'node:assert/strict';
import { applyCommand, currentPosition, initialPlayback, validPosition } from '../src/domain/playback.ts';

const T0 = 1_800_000_000_000;

Deno.test('current position advances only while playing', () => {
  const paused = initialPlayback(T0, 30);
  const playing = applyCommand(paused, 'play', 30, T0, 'P');

  assert.equal(currentPosition(paused, T0 + 10_000, 600), 30);
  assert.equal(currentPosition(playing, T0 + 10_000, 600), 40);
  assert.equal(currentPosition(playing, T0 + 10_000_000, 600), 600, 'clamped to the duration');
  assert.equal(currentPosition(playing, T0 - 5_000, 600), 30, 'clock skew never moves it backwards');
  assert.equal(currentPosition({ ...playing, rate: 2 }, T0 + 1000, null), 32);
});

Deno.test('commands set status, position, author and the next seq', () => {
  const start = initialPlayback(T0, 0, 7);

  assert.deepEqual(applyCommand(start, 'play', 5, T0 + 1, 'A'), {
    status: 'playing',
    positionSec: 5,
    updatedAt: T0 + 1,
    rate: 1,
    seq: 8,
    by: 'A',
  });
  assert.equal(applyCommand({ ...start, status: 'playing' }, 'seek', 9, T0, 'B').status, 'playing');
  assert.equal(applyCommand(start, 'seek', 9, T0, 'B').status, 'paused');
});

Deno.test('positions must be finite, non-negative and within the duration', () => {
  assert.equal(validPosition(12.34567, 600), 12.346);
  assert.equal(validPosition(0, 600), 0);
  assert.equal(validPosition(605, 600), 605);
  assert.equal(validPosition(605.1, 600), null);
  assert.equal(validPosition(86_400, null), 86_400);
  assert.equal(validPosition(86_401, null), null);
  for (const bad of [-0.001, Number.NaN, Infinity, -Infinity, '1', null, undefined, {}]) {
    assert.equal(validPosition(bad, 600), null, String(bad));
  }
});
