// Short, non-blocking notifications ("Ссылка скопирована"). The region is aria-live="polite".

import { el } from './dom.js';

const DURATION_MS = 3500;

export class Toasts {
  /** @param {HTMLElement} region */
  constructor(region) {
    this.region = region;
  }

  /**
   * @param {string} message
   * @param {{kind?: 'info'|'error'}} [options]
   */
  show(message, { kind = 'info' } = {}) {
    const toast = el('div', {
      className: `toast toast--${kind}`,
      text: message,
      attrs: { role: kind === 'error' ? 'alert' : 'status' },
    });
    this.region.append(toast);
    while (this.region.children.length > 3) {
      this.region.firstElementChild?.remove();
    }
    setTimeout(() => {
      toast.dataset.leaving = 'true';
      setTimeout(() => toast.remove(), 250);
    }, DURATION_MS);
  }
}
