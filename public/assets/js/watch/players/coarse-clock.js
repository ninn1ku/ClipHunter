// Turns a player position reported in coarse steps (VK reports whole seconds) into a smooth one.
// Pure: the caller passes the time.
//
// The reported value changes at the moment the real position crosses a step, so that moment is
// a precise anchor: from it the position advances with wall-clock time. The estimate never runs
// more than one step ahead of the last report (the player may have stalled without telling).

export class CoarseClock {
  /** @param {number} [stepSec] the resolution of the reported position */
  constructor(stepSec = 1) {
    this.step = stepSec;
    this.reported = 0;
    this.anchorSec = 0;
    this.anchorAt = 0;
    this.running = false;
  }

  /**
   * A position report from the player.
   * @param {number} reported position as reported
   * @param {number} nowMs
   */
  report(reported, nowMs) {
    if (!Number.isFinite(reported)) {
      return;
    }
    const estimate = this.value(nowMs);
    const changed = reported !== this.reported;
    this.reported = reported;
    if (changed || Math.abs(estimate - reported) > this.step) {
      // A new step began just now (or a jump the estimate cannot explain): re-anchor.
      this.anchorSec = reported;
      this.anchorAt = nowMs;
    }
  }

  /**
   * Playback started or stopped. Stopping freezes the estimate where it is.
   * @param {boolean} running
   * @param {number} nowMs
   */
  setRunning(running, nowMs) {
    if (running === this.running) {
      return;
    }
    this.anchorSec = this.value(nowMs);
    this.anchorAt = nowMs;
    this.running = running;
  }

  /**
   * We moved the player ourselves: trust the target until the player reports otherwise.
   * @param {number} seconds
   * @param {number} nowMs
   */
  seek(seconds, nowMs) {
    this.anchorSec = seconds;
    this.anchorAt = nowMs;
    this.reported = Math.floor(seconds / this.step) * this.step;
  }

  /** @param {number} nowMs */
  value(nowMs) {
    if (!this.running) {
      return this.anchorSec;
    }
    const elapsed = Math.max(0, nowMs - this.anchorAt) / 1000;

    return Math.min(this.anchorSec + elapsed, Math.max(this.anchorSec, this.reported) + this.step);
  }
}
