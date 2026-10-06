// The player area: overlays for every non-playing state (no video yet, preparing a file, errors)
// and, once media is playable, the stage where a player adapter mounts its element.

import { preparationErrorText } from '../messages.js';
import { el, icon, setBusy } from './dom.js';
import { sourceErrorText } from './source-dialog.js';

/**
 * @typedef {'empty'|'preparing'|'failed'|'expired'|'ready'} StageKind
 */

export class PlayerView {
  /**
   * @param {{player: HTMLElement, mount: HTMLElement, poster: HTMLElement, overlay: HTMLElement}} elements
   * @param {{chooseSource: (url: string) => Promise<void>, openSourceDialog: () => void,
   *   prepareOnServer: () => Promise<void>, unlockAutoplay: () => void, replay: () => void}} actions
   */
  constructor(elements, actions) {
    this.el = elements;
    this.actions = actions;
    this.key = '';
    this.posterUrl = null;
    this.progress = null;
  }

  /**
   * @param {import('../store.js').RoomState} state
   * @param {{status: string, percent: number|null, queuePosition: number|null, error: any}|null} preparation
   * @param {any} [syncOverlay] what the player itself needs to show (SyncStatus.overlay)
   * @returns {StageKind}
   */
  render(state, preparation, syncOverlay = null) {
    const media = state.media;
    const host = state.me !== null && state.me === state.hostId;
    const kind = stageKind(media, preparation);

    this.el.player.dataset.state = kind;
    this.setPoster(media?.thumbnailUrl ?? null);

    const sync = kind === 'ready' ? syncOverlay : null;
    const syncKey = sync === null ? '' : typeof sync === 'object' ? `error:${sync.error}` : sync;
    const failure = kind === 'failed' ? preparation?.error?.code ?? '' : '';
    const key = `${kind}:${host}:${media?.ref ?? ''}:${failure}:${syncKey}`;
    if (key !== this.key) {
      this.key = key;
      this.progress = null;
      this.el.overlay.replaceChildren(
        ...(kind === 'ready' ? this.syncOverlay(sync, host, media) : this.overlay(kind, host, preparation)),
      );
    }
    if (kind === 'preparing' && this.progress !== null) {
      this.updateProgress(preparation);
    }

    return kind;
  }

  overlay(kind, host, preparation) {
    switch (kind) {
      case 'empty':
        return host ? [this.sourceForm()] : [
          el(
            'div',
            { className: 'stage-card' },
            el('span', { className: 'stage-card__icon', attrs: { 'aria-hidden': 'true' } }, icon('film')),
            el('p', { className: 'stage-card__title', text: 'Ведущий скоро выберет видео' }),
            el('p', {
              className: 'stage-card__text',
              text: 'Как только видео появится, оно начнётся у всех одновременно.',
            }),
          ),
        ];
      case 'preparing': {
        const bar = el('span', { className: 'progress__bar' });
        const label = el('p', { className: 'stage-card__title' });
        const detail = el('p', { className: 'stage-card__text' });
        this.progress = { bar, label, detail, track: null };
        const track = el('span', {
          className: 'progress',
          attrs: {
            role: 'progressbar',
            'aria-valuemin': '0',
            'aria-valuemax': '100',
            'aria-label': 'Подготовка видео',
          },
        }, bar);
        this.progress.track = track;
        this.updateProgress(preparation);

        return [
          el(
            'div',
            { className: 'stage-card stage-card--glass' },
            el('span', { className: 'spinner', attrs: { 'aria-hidden': 'true' } }),
            label,
            track,
            detail,
          ),
        ];
      }
      case 'failed':
      case 'expired': {
        const text = kind === 'expired'
          ? 'Файл видео уже удалён с сервера: комнатные файлы хранятся 6 часов.'
          : preparationErrorText(preparation?.error?.code, preparation?.error?.message);
        return [
          el(
            'div',
            { className: 'stage-card' },
            el('span', {
              className: 'stage-card__icon stage-card__icon--error',
              attrs: { 'aria-hidden': 'true' },
            }, icon('alert')),
            el('p', {
              className: 'stage-card__title',
              text: kind === 'expired' ? 'Видео больше недоступно' : 'Не удалось подготовить видео',
            }),
            el('p', { className: 'stage-card__text', text }),
            host
              ? el('button', {
                className: 'btn btn--primary btn--sm',
                text: 'Выбрать другое видео',
                attrs: { type: 'button' },
                on: { click: () => this.actions.openSourceDialog() },
              })
              : el('p', { className: 'stage-card__text', text: 'Ведущий может выбрать другое видео.' }),
          ),
        ];
      }
      default:
        return [];
    }
  }

  /** Overlays while media is playable: loading, autoplay blocked, finished, player errors. */
  syncOverlay(overlay, host, media) {
    if (overlay === null) {
      return [];
    }
    if (overlay === 'loading') {
      return [
        el('span', { className: 'spinner spinner--lg', attrs: { 'aria-hidden': 'true' } }),
        el('span', { className: 'visually-hidden', text: 'Загружаем плеер…' }),
      ];
    }
    if (overlay === 'autoplay') {
      return [
        el(
          'button',
          {
            className: 'stage-cta',
            attrs: { type: 'button' },
            on: { click: () => this.actions.unlockAutoplay() },
          },
          el('span', { className: 'stage-cta__icon', attrs: { 'aria-hidden': 'true' } }, icon('play')),
          el('span', { text: 'Нажмите, чтобы смотреть вместе' }),
        ),
      ];
    }
    if (overlay === 'ended') {
      return [
        el(
          'div',
          { className: 'stage-card stage-card--glass' },
          el('p', { className: 'stage-card__title', text: 'Видео закончилось' }),
          el(
            'button',
            {
              className: 'btn btn--primary btn--sm',
              attrs: { type: 'button' },
              on: { click: () => this.actions.replay() },
            },
            icon('replay'),
            el('span', { text: 'Смотреть заново' }),
          ),
        ),
      ];
    }

    const code = overlay.error;
    const embedBlocked = code === 'YT_101' || code === 'YT_150';
    const texts = {
      YT_100: 'Видео недоступно: его удалили или сделали приватным.',
      YT_101: 'Владелец запретил показывать это видео на других сайтах.',
      YT_150: 'Владелец запретил показывать это видео на других сайтах.',
      YT_153: 'Плеер YouTube не получил адрес страницы. Обновите страницу.',
      YT_API_UNAVAILABLE: 'Не удалось загрузить плеер YouTube. Проверьте, не блокирует ли его браузер.',
      MEDIA_UNSUPPORTED: 'Браузер не может воспроизвести этот файл.',
    };
    let action;
    if (host && embedBlocked && media?.kind === 'youtube') {
      const button = el(
        'button',
        { className: 'btn btn--primary btn--sm', attrs: { type: 'button' } },
        el('span', { className: 'spinner', attrs: { 'aria-hidden': 'true' } }),
        el('span', { className: 'btn__label', text: 'Подготовить через сервер' }),
      );
      const error = el('p', { className: 'w-field-error', attrs: { 'aria-live': 'polite' } });
      button.addEventListener('click', async () => {
        setBusy(button, true, 'Готовим…');
        try {
          await this.actions.prepareOnServer();
        } catch (e) {
          error.textContent = sourceErrorText(e);
          setBusy(button, false, 'Подготовить через сервер');
        }
      });
      action = el('div', { className: 'stage-card__actions' }, button, error);
    } else if (host) {
      action = el('button', {
        className: 'btn btn--secondary btn--sm',
        text: 'Выбрать другое видео',
        attrs: { type: 'button' },
        on: { click: () => this.actions.openSourceDialog() },
      });
    } else {
      action = el('p', {
        className: 'stage-card__text',
        text: embedBlocked
          ? 'Ведущий может подготовить видео через наш сервер.'
          : 'Ведущий может выбрать другое видео.',
      });
    }

    return [
      el(
        'div',
        { className: 'stage-card stage-card--glass' },
        el('span', {
          className: 'stage-card__icon stage-card__icon--error',
          attrs: { 'aria-hidden': 'true' },
        }, icon('alert')),
        el('p', { className: 'stage-card__title', text: 'Видео не воспроизводится' }),
        el('p', { className: 'stage-card__text', text: texts[code] ?? 'Не удалось воспроизвести видео.' }),
        action,
      ),
    ];
  }

  updateProgress(preparation) {
    const { bar, label, detail, track } = this.progress;
    const percent = typeof preparation?.percent === 'number'
      ? Math.max(0, Math.min(100, preparation.percent))
      : null;
    const queued = preparation?.status === 'queued';

    label.textContent = queued
      ? 'Видео в очереди на подготовку'
      : percent === null
      ? 'Готовим видео…'
      : `Готовим видео… ${Math.floor(percent)} %`;
    bar.style.width = `${percent ?? (queued ? 0 : 8)}%`;
    track.setAttribute('aria-valuenow', String(Math.floor(percent ?? 0)));
    detail.textContent =
      queued && typeof preparation?.queuePosition === 'number' && preparation.queuePosition > 0
        ? `Перед нами в очереди: ${preparation.queuePosition}`
        : 'Сервер скачивает видео, чтобы все смотрели его синхронно.';
  }

  sourceForm() {
    const input = el('input', {
      className: 'w-field',
      attrs: {
        type: 'url',
        inputmode: 'url',
        autocomplete: 'off',
        autocapitalize: 'off',
        spellcheck: 'false',
        maxlength: '2048',
        placeholder: 'https://www.youtube.com/watch?v=…',
        'aria-label': 'Ссылка на видео',
        'aria-describedby': 'stage-source-error',
      },
    });
    const error = el('p', {
      className: 'w-field-error',
      attrs: { id: 'stage-source-error', 'aria-live': 'polite' },
    });
    const submit = el(
      'button',
      { className: 'btn btn--primary', attrs: { type: 'submit' } },
      el('span', { className: 'spinner', attrs: { 'aria-hidden': 'true' } }),
      el('span', { className: 'btn__label', text: 'Показать всем' }),
    );
    const form = el(
      'form',
      { className: 'stage-card stage-form', attrs: { novalidate: true } },
      el('span', { className: 'stage-card__icon', attrs: { 'aria-hidden': 'true' } }, icon('link')),
      el('p', { className: 'stage-card__title', text: 'Вставьте ссылку на видео' }),
      el('p', {
        className: 'stage-card__text',
        text: 'YouTube, ВКонтакте и другие площадки. Видео появится у всех на паузе.',
      }),
      el('div', { className: 'stage-form__row' }, input, submit),
      error,
    );
    input.addEventListener('input', () => {
      error.textContent = '';
    });
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const url = input.value.trim();
      if (url === '') {
        error.textContent = 'Вставьте ссылку на видео.';
        input.focus();
        return;
      }
      setBusy(submit, true, 'Проверяем ссылку…');
      try {
        await this.actions.chooseSource(url);
      } catch (e) {
        error.textContent = sourceErrorText(e);
        input.focus();
      } finally {
        if (submit.isConnected) {
          setBusy(submit, false, 'Показать всем');
        }
      }
    });

    return form;
  }

  setPoster(url) {
    if (url === this.posterUrl) {
      return;
    }
    this.posterUrl = url;
    this.el.poster.replaceChildren(
      ...(url === null
        ? []
        : [el('img', { attrs: { src: url, alt: '', referrerpolicy: 'no-referrer', decoding: 'async' } })]),
    );
  }
}

/**
 * @param {import('../store.js').Media|null} media
 * @param {{status: string}|null} preparation
 * @returns {StageKind}
 */
export function stageKind(media, preparation) {
  if (media === null) {
    return 'empty';
  }
  if (media.kind === 'youtube') {
    return 'ready';
  }
  switch (preparation?.status) {
    case 'ready':
      return 'ready';
    case 'failed':
      return 'failed';
    case 'expired':
      return 'expired';
    default:
      return 'preparing';
  }
}
