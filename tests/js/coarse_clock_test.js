import assert from 'node:assert/strict';
import { CoarseClock } from '../../public/assets/js/watch/players/coarse-clock.js';

const close = (actual, expected, message) =>
  assert.ok(Math.abs(actual - expected) < 1e-9, `${message}: ${actual}`);

Deno.test('a whole-second report becomes a smooth position from the moment it changes', () => {
  const clock = new CoarseClock();
  clock.setRunning(true, 0);
  clock.report(10, 1000); // the position just crossed 10 s
  close(clock.value(1250), 10.25, 'between reports');
  clock.report(10, 1500); // same value again: keep the anchor
  close(clock.value(1600), 10.6, 'unchanged report keeps the anchor');
  clock.report(11, 2000);
  close(clock.value(2100), 11.1, 'next step');
});

Deno.test('the estimate never runs more than a step past the last report', () => {
  const clock = new CoarseClock();
  clock.setRunning(true, 0);
  clock.report(5, 0);
  close(clock.value(5000), 6, 'a stalled player');
});

Deno.test('pausing freezes the estimate, resuming continues from it', () => {
  const clock = new CoarseClock();
  clock.setRunning(true, 0);
  clock.report(20, 0);
  clock.setRunning(false, 400);
  close(clock.value(3000), 20.4, 'paused');
  clock.setRunning(true, 3000);
  close(clock.value(3100), 20.5, 'resumed');
});

Deno.test('own seeks are trusted until the player reports, jumps re-anchor', () => {
  const clock = new CoarseClock();
  clock.setRunning(true, 0);
  clock.report(100, 0);
  clock.seek(42.5, 1000);
  close(clock.value(1200), 42.7, 'after our seek');
  clock.report(42, 1300); // the player confirms the step we expect
  close(clock.value(1300), 42.8, 'confirmation keeps the precise anchor');
  clock.report(300, 1400); // seeked by someone else (the platform's own controls)
  close(clock.value(1400), 300, 'external jump');
  clock.report(Number.NaN, 1500);
  close(clock.value(1500), 300.1, 'garbage ignored');
});
