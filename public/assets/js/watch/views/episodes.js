// The episode bar under the player for AniLiberty media: which episode the room watches, a link to
// the title on AniLiberty (the source), and for the host a picker and "Следующая серия". Changing
// the episode is a media change for everyone (POST /api/watch/sources with the episode's page).
// Renders only when its inputs change: the room re-renders twice a second.

import { api } from '../../api.js';
import { el, setBusy } from './dom.js';

/**
 * The episode after the current one in a release's list, or null. Pure.
 * @param {Array<{id: string, playable: boolean}>} episodes sorted
 * @param {string} currentId
 */
export function nextEpisode(episodes, currentId) {
  const index = episodes.findIndex((e) => e.id === currentId);
  return index < 0 ? null : episodes.slice(index + 1).find((e) => e.playable) ?? null;
}

export class EpisodesView {
  /**
   * @param {HTMLElement} bar
   * @param {{choose: (pageUrl: string) => Promise<void>, toast: (text: string, options?: object) => void,
   *   errorText: (error: any) => string}} actions
   */
  constructor(bar, actions) {
    this.bar = bar;
    this.actions = actions;
    /** @type {Map<string, any>} releaseId → release (or a pending Promise) */
    this.releases = new Map();
    this.key = '';
  }

  /** @param {import('../store.js').RoomState} state */
  render(state) {
    const media = state.media;
    if (media?.kind !== 'aniliberty') {
      if (this.key !== '') {
        this.key = '';
        this.bar.hidden = true;
        this.bar.replaceChildren();
      }
      return;
    }
    const [releaseId, episodeId] = media.ref.split(':');
    const host = state.me !== null && state.me === state.hostId;
    const release = this.release(releaseId);
    const key = `${releaseId}:${episodeId}:${host}:${release === null ? 'loading' : 'ready'}`;
    if (key === this.key) {
      return;
    }
    this.key = key;
    this.bar.hidden = false;
    this.bar.replaceChildren(...this.content(release, episodeId, host));
  }

  /** The release from the cache; starts loading it (once) and re-renders when it arrives. */
  release(releaseId) {
    const cached = this.releases.get(releaseId);
    if (cached !== undefined && !(cached instanceof Promise)) {
      return cached;
    }
    if (cached === undefined) {
      const loading = api('GET', `/api/watch/aniliberty/releases/${encodeURIComponent(releaseId)}`, null, {
        timeoutMs: 15000,
      }).then(({ release }) => {
        this.releases.set(releaseId, release);
      }).catch(() => {
        this.releases.delete(releaseId);
      }).finally(() => {
        this.key = '';
      });
      this.releases.set(releaseId, loading);
    }
    return null;
  }

  content(release, episodeId, host) {
    if (release === null) {
      return [el('span', { className: 'episode-bar__label', text: 'Загружаем список серий…' })];
    }
    const episodes = Array.isArray(release.episodes) ? release.episodes : [];
    const current = episodes.find((e) => e.id === episodeId) ?? null;
    const total = release.episodesTotal ?? episodes.length;
    const label = current === null ? 'Серия' : `Серия ${current.label} из ${total}`;
    const parts = [];

    if (host && episodes.length > 1) {
      const select = el(
        'select',
        {
          className: 'w-field episode-bar__select',
          attrs: { 'aria-label': 'Выбрать серию для всех' },
        },
        ...episodes.map((e) =>
          el('option', {
            text: e.name ? `${e.label}. ${e.name}` : `Серия ${e.label}`,
            attrs: { value: e.id, selected: e.id === episodeId, disabled: !e.playable },
          })
        ),
      );
      select.addEventListener('change', () => void this.change(release, select.value, select));
      parts.push(select);
      const next = nextEpisode(episodes, episodeId);
      if (next !== null) {
        const button = el(
          'button',
          { className: 'btn btn--secondary btn--sm', attrs: { type: 'button' } },
          el('span', { className: 'spinner', attrs: { 'aria-hidden': 'true' } }),
          el('span', { className: 'btn__label', text: 'Следующая серия' }),
        );
        button.addEventListener('click', () => void this.change(release, next.id, button));
        parts.push(button);
      }
    } else {
      parts.push(el('span', { className: 'episode-bar__label', text: label }));
    }
    parts.push(el('a', {
      className: 'w-link episode-bar__source',
      text: 'Тайтл на AniLiberty',
      attrs: { href: release.pageUrl, target: '_blank', rel: 'noopener noreferrer' },
    }));

    return parts;
  }

  async change(release, episodeId, control) {
    const origin = new URL(release.pageUrl).origin;
    const isButton = control instanceof HTMLButtonElement;
    if (isButton) {
      setBusy(control, true, 'Переключаем…');
    } else {
      control.disabled = true;
    }
    try {
      await this.actions.choose(`${origin}/anime/video/episode/${episodeId}`);
    } catch (e) {
      this.actions.toast(this.actions.errorText(e), { kind: 'error' });
      this.key = '';
    } finally {
      if (control.isConnected) {
        if (isButton) {
          setBusy(control, false, 'Следующая серия');
        } else {
          control.disabled = false;
        }
      }
    }
  }
}
