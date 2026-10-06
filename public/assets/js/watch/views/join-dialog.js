// "Как вас называть?" — the name dialog for joining a room and for creating one.

import { roomErrorText } from '../messages.js';
import { setBusy } from './dom.js';

const NAME_MAX = 24;

export class NameDialog {
  /**
   * @param {{dialog: HTMLDialogElement, form: HTMLFormElement, input: HTMLInputElement, error: HTMLElement,
   *   submit: HTMLButtonElement}} elements
   * @param {{lastName: () => string}} storage
   */
  constructor(elements, storage) {
    this.el = elements;
    this.storage = storage;
    this.handler = null;
    this.cancellable = false;
    this.resolve = null;

    elements.form.addEventListener('submit', (event) => {
      event.preventDefault();
      void this.submit();
    });
    elements.input.addEventListener('input', () => this.showError(''));
    elements.dialog.addEventListener('cancel', (event) => {
      // Without a name there is no way into the room; creating can be cancelled.
      if (!this.cancellable) {
        event.preventDefault();
      }
    });
    elements.dialog.addEventListener('close', () => {
      this.resolve?.(false);
      this.resolve = null;
    });
  }

  /**
   * @param {{submitLabel: string, cancellable: boolean, onSubmit: (name: string) => Promise<void>}} options
   * @returns {Promise<boolean>} true once onSubmit succeeded, false if cancelled
   */
  open({ submitLabel, cancellable, onSubmit }) {
    this.handler = onSubmit;
    this.cancellable = cancellable;
    this.el.submit.querySelector('.btn__label').textContent = submitLabel;
    this.el.input.value = this.el.input.value || this.storage.lastName();
    this.showError('');
    setBusy(this.el.submit, false);

    return new Promise((resolve) => {
      this.resolve = resolve;
      if (!this.el.dialog.open) {
        this.el.dialog.showModal();
      }
      this.el.input.focus();
      this.el.input.select();
    });
  }

  close() {
    if (this.el.dialog.open) {
      this.el.dialog.close();
    }
  }

  async submit() {
    const name = this.el.input.value.trim().replace(/\s+/g, ' ');
    const length = Array.from(name).length;
    if (length === 0 || length > NAME_MAX) {
      this.showError(length === 0 ? 'Введите имя.' : roomErrorText('NAME_INVALID'));
      this.el.input.focus();
      return;
    }
    if (this.handler === null) {
      return;
    }

    setBusy(this.el.submit, true);
    try {
      await this.handler(name);
      const resolve = this.resolve;
      this.resolve = null;
      this.close();
      resolve?.(true);
    } catch (e) {
      this.showError(roomErrorText(e?.code));
      this.el.input.focus();
    } finally {
      setBusy(this.el.submit, false);
    }
  }

  showError(text) {
    this.el.error.textContent = text;
    this.el.input.setAttribute('aria-invalid', String(text !== ''));
  }
}
