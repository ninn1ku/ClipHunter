import assert from 'node:assert/strict';
import { FakeClock } from '../src/clock.ts';
import { KeyedWindowLimiter, TokenBucket } from '../src/security/rate-limit.ts';

Deno.test('token bucket allows the burst, then the sustained rate', () => {
  const clock = new FakeClock();
  const bucket = new TokenBucket(2, 5, clock);

  for (let i = 0; i < 5; i++) {
    assert.ok(bucket.take(), `burst ${i}`);
  }
  assert.ok(!bucket.take());

  clock.advance(499);
  assert.ok(!bucket.take(), 'not yet a whole token');
  clock.advance(1);
  assert.ok(bucket.take());
  assert.ok(!bucket.take());

  clock.advance(60_000);
  for (let i = 0; i < 5; i++) {
    assert.ok(bucket.take());
  }
  assert.ok(!bucket.take(), 'refill is capped at the burst');
});

Deno.test('keyed fixed window limits per key and resets', () => {
  const clock = new FakeClock();
  const limiter = new KeyedWindowLimiter(3, 60_000, clock);

  assert.ok(limiter.hit('a') && limiter.hit('a') && limiter.hit('a'));
  assert.ok(!limiter.hit('a'));
  assert.ok(limiter.hit('b'), 'other keys are independent');

  clock.advance(60_000);
  assert.ok(limiter.hit('a'));
});

Deno.test('sweep drops finished windows', () => {
  const clock = new FakeClock();
  const limiter = new KeyedWindowLimiter(1, 1000, clock);
  limiter.hit('a');
  clock.advance(500);
  limiter.hit('b');
  clock.advance(500);

  limiter.sweep();

  assert.equal(limiter.size, 1);
});
