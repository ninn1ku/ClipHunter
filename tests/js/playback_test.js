import assert from 'node:assert/strict';
import {
  decideCorrection,
  expectedPosition,
  inSyncWindow,
  isAtEnd,
} from '../../public/assets/js/watch/playback.js';

const T0 = 1_800_000_000_000;
const playing = { status: 'playing', positionSec: 100, updatedAt: T0, rate: 1, seq: 1, by: 'A' };

Deno.test('expected position advances while playing and is clamped', () => {
  assert.equal(expectedPosition(playing, T0 + 2500, 600), 102.5);
  assert.equal(expectedPosition({ ...playing, status: 'paused' }, T0 + 2500, 600), 100);
  assert.equal(expectedPosition(playing, T0 + 10_000_000, 600), 600);
  assert.equal(expectedPosition(playing, T0 - 5000, 600), 100, 'server time slightly behind never rewinds');
  assert.equal(expectedPosition(playing, T0 + 1000, null), 101, 'unknown duration is not clamped');
  assert.equal(expectedPosition({ ...playing, positionSec: -3 }, T0, 600), 0);
});

Deno.test('html5: seek above 0.5 s, nudge the rate between 0.06 and 0.5 s', () => {
  assert.deepEqual(decideCorrection({ drift: 0.51, kind: 'html5' }), { action: 'seek' });
  assert.deepEqual(decideCorrection({ drift: -0.51, kind: 'html5' }), { action: 'seek' });
  assert.deepEqual(decideCorrection({ drift: 0.5, kind: 'html5' }), { action: 'rate', rate: 0.9 });
  assert.deepEqual(decideCorrection({ drift: -0.5, kind: 'html5' }), { action: 'rate', rate: 1.1 });
  assert.deepEqual(decideCorrection({ drift: 0.25, kind: 'html5' }), { action: 'rate', rate: 0.95 });
  assert.deepEqual(decideCorrection({ drift: 0.061, kind: 'html5' }), { action: 'rate', rate: 0.988 });
  assert.deepEqual(decideCorrection({ drift: 0.06, kind: 'html5' }), { action: 'none' });
  assert.deepEqual(decideCorrection({ drift: 0.02, kind: 'html5', rateCorrecting: true }), {
    action: 'rate',
    rate: 1,
  });
});

Deno.test('youtube: seek only above 1 s, never touch the rate', () => {
  assert.deepEqual(decideCorrection({ drift: 1.01, kind: 'youtube' }), { action: 'seek' });
  assert.deepEqual(decideCorrection({ drift: -1.01, kind: 'youtube' }), { action: 'seek' });
  assert.deepEqual(decideCorrection({ drift: 1, kind: 'youtube' }), { action: 'none' });
  assert.deepEqual(decideCorrection({ drift: 0.3, kind: 'youtube', rateCorrecting: true }), {
    action: 'none',
  });
});

Deno.test('corrections are frozen right after a seek and ignore NaN', () => {
  assert.deepEqual(decideCorrection({ drift: 5, kind: 'html5', frozen: true }), { action: 'none' });
  assert.deepEqual(decideCorrection({ drift: Number.NaN, kind: 'html5' }), { action: 'none' });
});

Deno.test('end detection and the sync window', () => {
  assert.ok(isAtEnd(599.75, 600));
  assert.ok(!isAtEnd(599.7, 600));
  assert.ok(!isAtEnd(1e9, null));
  assert.ok(inSyncWindow(0.2, 'html5'));
  assert.ok(!inSyncWindow(0.3, 'html5'));
  assert.ok(inSyncWindow(0.9, 'youtube'));
});
