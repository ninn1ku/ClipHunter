import assert from 'node:assert/strict';
import { ClockSync } from '../../public/assets/js/watch/clock.js';

Deno.test('offset comes from the sample with the smallest RTT', () => {
  const clock = new ClockSync();
  // Server is 5000 ms ahead. A fast sample (rtt 20) and a slow, asymmetric one (rtt 300).
  clock.addSample(1000, 6010, 1020);
  clock.addSample(2000, 7250, 2300);

  assert.equal(clock.rtt, 20);
  assert.equal(clock.offset, 5000);
  assert.equal(clock.serverNow(10_000), 15_000);
});

Deno.test('only the last 8 samples count', () => {
  const clock = new ClockSync(8);
  clock.addSample(0, 100, 2); // very fast, offset 99
  for (let i = 1; i <= 8; i++) {
    clock.addSample(i * 1000, i * 1000 + 75, i * 1000 + 50); // rtt 50, offset 50
  }

  assert.equal(clock.samples.length, 8);
  assert.equal(clock.rtt, 50, 'the faster but older sample was evicted');
  assert.equal(clock.offset, 50);
});

Deno.test('impossible samples are ignored; no samples means no offset', () => {
  const clock = new ClockSync();
  clock.addSample(1000, 5000, 900);
  clock.addSample(1000, Number.NaN, 1100);

  assert.equal(clock.ready, false);
  assert.equal(clock.offset, 0);
  assert.equal(clock.rtt, null);
});
