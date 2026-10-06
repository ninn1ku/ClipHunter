// Clock synchronisation with the rooms service (docs/WATCH_PARTY_PLAN.md §6.1). Pure: no DOM, no timers.
//
// ping {t0} → pong {t0, serverTime} received at t1:
//   rtt = t1 − t0, offset = serverTime + rtt/2 − t1.
// The offset of the sample with the smallest RTT among the last 8 wins: queuing delays only ever
// make a sample worse, so the fastest round trip is the most accurate.

/** Wall-clock time in ms with sub-millisecond resolution and no jumps from NTP adjustments. */
export function clientNow() {
  return performance.timeOrigin + performance.now();
}

export class ClockSync {
  /** @param {number} [maxSamples] */
  constructor(maxSamples = 8) {
    this.maxSamples = maxSamples;
    /** @type {Array<{rtt: number, offset: number}>} */
    this.samples = [];
  }

  /**
   * @param {number} t0 client time when the ping was sent
   * @param {number} serverTime server time from the pong
   * @param {number} t1 client time when the pong arrived
   */
  addSample(t0, serverTime, t1) {
    const rtt = t1 - t0;
    if (!Number.isFinite(rtt) || rtt < 0 || !Number.isFinite(serverTime)) {
      return;
    }
    this.samples.push({ rtt, offset: serverTime + rtt / 2 - t1 });
    if (this.samples.length > this.maxSamples) {
      this.samples.splice(0, this.samples.length - this.maxSamples);
    }
  }

  /** @returns {{rtt: number, offset: number}|null} */
  best() {
    let best = null;
    for (const sample of this.samples) {
      if (best === null || sample.rtt < best.rtt) {
        best = sample;
      }
    }

    return best;
  }

  get ready() {
    return this.samples.length > 0;
  }

  /** Milliseconds to add to client time to get server time. */
  get offset() {
    return this.best()?.offset ?? 0;
  }

  get rtt() {
    return this.best()?.rtt ?? null;
  }

  /** @param {number} [at] client time */
  serverNow(at = clientNow()) {
    return at + this.offset;
  }
}
