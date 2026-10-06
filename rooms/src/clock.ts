/** Wall-clock time in milliseconds. Injected everywhere so tests can control time. */
export interface Clock {
  now(): number;
}

export const systemClock: Clock = { now: () => Date.now() };

export class FakeClock implements Clock {
  constructor(public current = 1_800_000_000_000) {}

  now(): number {
    return this.current;
  }

  advance(ms: number): void {
    this.current += ms;
  }
}
