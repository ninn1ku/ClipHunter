// Choosing a video: the PHP source endpoint (URL → signed ticket) and the "Сменить видео" dialog.

import { api } from '../../api.js';
import { describeError } from '../../messages.js';
import { roomErrorText } from '../messages.js';
import { setBusy } from './dom.js';

/**
 * POST /api/watch/sources. File mode can take a while: the server analyses the link first.
 * @param {string} url
 * @param {'auto'|'file'} [mode]
 * @returns {Promise<{source: any, ticket: string}>}
 */
export function resolveSource(url, mode = 'auto') {
  return api('POST', '/api/watch/sources', { url, mode }, { timeoutMs: 45000 });
}

/** A user-facing message for a failed source request (HTTP) or media.set (WebSocket). */
export function sourceErrorText(error) {
  if (error?.name === 'ApiError' || typeof error?.status === 'number') {
    return describeError(error).message;
  }

  return roomErrorText(error?.code);
}

export class SourceDialog {
  /**
   * @param {{dialog: HTMLDialogElement, form: HTMLFormElement, input: HTMLInputElement, error: HTMLElement,
   *   submit: HTMLButtonElement}} elements
   * @param {(ticket: string) => Promise<void>} apply sends media.set
   */
  constructor(elements, apply) {
    this.el = elements;
    this.apply = apply;
    elements.form.addEventListener('submit', (event) => {
      event.preventDefault();
      void this.submit();
    });
    elements.dialog.querySelector('[data-close]')?.addEventListener('click', () => elements.dialog.close());
    elements.input.addEventListener('input', () => {
      elements.error.textContent = '';
    });
  }

  open() {
    this.el.error.textContent = '';
    setBusy(this.el.submit, false, 'Показать всем');
    this.el.dialog.showModal();
    this.el.input.focus();
  }

  async submit() {
    const url = this.el.input.value.trim();
    if (url === '') {
      this.el.error.textContent = 'Вставьте ссылку на видео.';
      return;
    }
    setBusy(this.el.submit, true, 'Проверяем ссылку…');
    try {
      const { ticket } = await resolveSource(url);
      await this.apply(ticket);
      this.el.input.value = '';
      this.el.dialog.close();
    } catch (e) {
      this.el.error.textContent = sourceErrorText(e);
    } finally {
      setBusy(this.el.submit, false, 'Показать всем');
    }
  }
}
