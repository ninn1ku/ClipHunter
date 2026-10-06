import assert from 'node:assert/strict';
import {
  decideCorrection,
  EMBED_PLAYER,
  expectedPosition,
  inSyncWindow,
  isAtEnd,
  PRECISE_PLAYER,
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
  assert.deepEqual(decideCorrection({ drift: 0.51, player: PRECISE_PLAYER }), { action: 'seek' });
  assert.deepEqual(decideCorrection({ drift: -0.51, player: PRECISE_PLAYER }), { action: 'seek' });
  assert.deepEqual(decideCorrection({ drift: 0.5, player: PRECISE_PLAYER }), { action: 'rate', rate: 0.9 });
  assert.deepEqual(decideCorrection({ drift: -0.5, player: PRECISE_PLAYER }), { action: 'rate', rate: 1.1 });
  assert.deepEqual(decideCorrection({ drift: 0.25, player: PRECISE_PLAYER }), { action: 'rate', rate: 0.95 });
  assert.deepEqual(decideCorrection({ drift: 0.061, player: PRECISE_PLAYER }), {
    action: 'rate',
    rate: 0.988,
  });
  assert.deepEqual(decideCorrection({ drift: 0.06, player: PRECISE_PLAYER }), { action: 'none' });
  assert.deepEqual(decideCorrection({ drift: 0.02, player: PRECISE_PLAYER, rateCorrecting: true }), {
    action: 'rate',
    rate: 1,
  });
});

Deno.test('youtube: seek only above 1 s, never touch the rate', () => {
  assert.deepEqual(decideCorrection({ drift: 1.01, player: EMBED_PLAYER }), { action: 'seek' });
  assert.deepEqual(decideCorrection({ drift: -1.01, player: EMBED_PLAYER }), { action: 'seek' });
  assert.deepEqual(decideCorrection({ drift: 1, player: EMBED_PLAYER }), { action: 'none' });
  assert.deepEqual(decideCorrection({ drift: 0.3, player: EMBED_PLAYER, rateCorrecting: true }), {
    action: 'none',
  });
});

Deno.test('corrections are frozen right after a seek and ignore NaN', () => {
  assert.deepEqual(decideCorrection({ drift: 5, player: PRECISE_PLAYER, frozen: true }), { action: 'none' });
  assert.deepEqual(decideCorrection({ drift: Number.NaN, player: PRECISE_PLAYER }), { action: 'none' });
});

Deno.test('end detection and the sync window', () => {
  assert.ok(isAtEnd(599.75, 600));
  assert.ok(!isAtEnd(599.7, 600));
  assert.ok(!isAtEnd(1e9, null));
  assert.ok(inSyncWindow(0.2, PRECISE_PLAYER));
  assert.ok(!inSyncWindow(0.3, PRECISE_PLAYER));
  assert.ok(inSyncWindow(0.9, EMBED_PLAYER));
});

Deno.test('a seek the player ignored is not repeated for a while', async () => {
  const { seekIgnored, SEEK_RETRY_MS } = await import('../../public/assets/js/watch/playback.js');
  const last = { at: 1000, from: 121.2, to: 140 };
  assert.ok(seekIgnored(last, 121.3, 3000), 'still where it was: an ad or loading');
  assert.ok(!seekIgnored(last, 139.5, 3000), 'the seek took effect');
  assert.ok(!seekIgnored(last, 121.3, 1000 + SEEK_RETRY_MS), 'retry after the pause');
  assert.ok(!seekIgnored(null, 121.3, 3000));
  assert.ok(!seekIgnored({ at: 1000, from: 50, to: 50.5 }, 50, 2000), 'tiny seeks are not judged');
});

Deno.test('a playing player whose position stands still is stalled', async () => {
  const { isStalled, trackMotion, STALL_MS } = await import('../../public/assets/js/watch/playback.js');
  let motion = trackMotion(null, 120, 0);
  motion = trackMotion(motion, 120.02, 500);
  assert.equal(motion.at, 0, 'jitter is not movement');
  assert.ok(!isStalled(motion, true, STALL_MS), 'not yet');
  assert.ok(isStalled(motion, true, STALL_MS + 1), 'an ad or buffering');
  assert.ok(!isStalled(motion, false, 10_000), 'paused players are not stalled');
  motion = trackMotion(motion, 121, 3000);
  assert.ok(!isStalled(motion, true, 3500), 'moving again');
  assert.ok(!isStalled(null, true, 10_000));
});
