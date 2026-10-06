// Title search on AniLiberty for the source fields: whatever does not look like a link is a
// title to look up (GET /api/watch/aniliberty/search). Picking a result resolves the release's
// page like a pasted link: the room starts with its first episode.

import { api } from '../../api.js';
import { el } from './dom.js';

/**
 * A link rather than a title: a scheme, or a domain-like first word ("youtu.be/…", "vk.com").
 * Pure.
 * @param {string} text
 */
export function looksLikeUrl(text) {
  const value = text.trim();
  return /^[a-z][a-z0-9+.-]*:\/\//i.test(value) || /^[^\s/]+\.[a-z]{2,}(?:[/?#:]|$)/i.test(value);
}

export class AnimeSearch {
  /**
   * @param {HTMLElement} list the <ul> for results (hidden while empty)
   * @param {(release: {title: string, pageUrl: string}) => Promise<void>} pick
   */
  constructor(list, pick) {
    this.list = list;
    this.pick = pick;
    this.seq = 0;
  }

  /**
   * @param {string} query
   * @returns {Promise<void>} rejects with the API error
   */
  async run(query) {
    const seq = ++this.seq;
    const { results } = await api(
      'GET',
      `/api/watch/aniliberty/search?q=${encodeURIComponent(query.trim())}`,
      null,
      { timeoutMs: 15000 },
    );
    if (seq !== this.seq) {
      return;
    }
    const playable = (Array.isArray(results) ? results : []).filter((r) => r && !r.blocked);
    this.list.replaceChildren(
      ...(playable.length === 0
        ? [
          el('li', {
            className: 'anime-results__empty',
            text: 'На AniLiberty ничего не нашлось. Проверьте название.',
          }),
        ]
        : playable.map((release) => this.item(release))),
    );
    this.list.hidden = false;
  }

  item(release) {
    const meta = [release.titleEnglish, release.year].filter(Boolean).join(' · ');
    const button = el(
      'button',
      { className: 'anime-results__item', attrs: { type: 'button' } },
      release.posterUrl
        ? el('img', {
          className: 'anime-results__poster',
          attrs: { src: release.posterUrl, alt: '', loading: 'lazy', referrerpolicy: 'no-referrer' },
        })
        : el('span', { className: 'anime-results__poster', attrs: { 'aria-hidden': 'true' } }),
      el(
        'span',
        { className: 'anime-results__text' },
        el('span', { className: 'anime-results__title', text: release.title }),
        meta === '' ? null : el('span', { className: 'anime-results__meta', text: meta }),
      ),
    );
    button.addEventListener('click', async () => {
      for (const other of this.list.querySelectorAll('button')) {
        other.disabled = true;
      }
      button.setAttribute('aria-busy', 'true');
      try {
        await this.pick(release);
      } finally {
        if (button.isConnected) {
          button.removeAttribute('aria-busy');
          for (const other of this.list.querySelectorAll('button')) {
            other.disabled = false;
          }
        }
      }
    });

    return el('li', {}, button);
  }

  clear() {
    this.seq++;
    this.list.replaceChildren();
    this.list.hidden = true;
  }
}
