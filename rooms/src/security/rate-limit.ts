/**
 * In-memory rate limiting: token buckets per connection, fixed windows per key (IP hash).
 */

import type { Clock } from '../clock.ts';

/** Allows `burst` events at once and `ratePerSec` sustained. */
export class TokenBucket {
  private tokens: number;
  private refilledAt: number;

  constructor(
    private readonly ratePerSec: number,
    private readonly burst: number,
    private readonly clock: Clock,
  ) {
    this.tokens = burst;
    this.refilledAt = clock.now();
  }

  take(): boolean {
    const now = this.clock.now();
    this.tokens = Math.min(this.burst, this.tokens + (now - this.refilledAt) / 1000 * this.ratePerSec);
    this.refilledAt = now;
    if (this.tokens < 1) {
      return false;
    }
    this.tokens -= 1;

    return true;
  }
}

/** "limit hits per windowMs" per key, with fixed windows starting at the first hit. */
export class KeyedWindowLimiter {
  private readonly windows = new Map<string, { start: number; count: number }>();

  constructor(
    private readonly limit: number,
    private readonly windowMs: number,
    private readonly clock: Clock,
  ) {}

  /** Counts a hit; false once the key has exhausted its window. */
  hit(key: string): boolean {
    const now = this.clock.now();
    const window = this.windows.get(key);
    if (window === undefined || now >= window.start + this.windowMs) {
      this.windows.set(key, { start: now, count: 1 });

      return true;
    }
    if (window.count >= this.limit) {
      return false;
    }
    window.count++;

    return true;
  }

  /** Drops finished windows so the map does not grow without bound. */
  sweep(): void {
    const now = this.clock.now();
    for (const [key, window] of this.windows) {
      if (now >= window.start + this.windowMs) {
        this.windows.delete(key);
      }
    }
  }

  get size(): number {
    return this.windows.size;
  }
}
