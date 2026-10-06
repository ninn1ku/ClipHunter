// The start page (/watch): an optional video link and "Создать комнату".

import { setBusy } from './dom.js';
import { resolveSource, sourceErrorText } from './source-dialog.js';

export class StartView {
  /**
   * @param {{form: HTMLFormElement, input: HTMLInputElement, submit: HTMLButtonElement, hint: HTMLElement}} elements
   * @param {{askName: (onSubmit: (name: string) => Promise<void>) => Promise<boolean>,
   *   create: (name: string, ticket: string|null) => Promise<void>}} actions
   */
  constructor(elements, actions) {
    this.el = elements;
    this.actions = actions;
    elements.form.addEventListener('submit', (event) => {
      event.preventDefault();
      void this.submit();
    });
    elements.input.addEventListener('input', () => this.hint(''));
  }

  /** @param {string|null} prefill from /watch?url=… */
  show(prefill) {
    if (prefill) {
      this.el.input.value = prefill;
    }
    setBusy(this.el.submit, false, 'Создать комнату');
    this.hint('');
  }

  async submit() {
    const url = this.el.input.value.trim();
    let ticket = null;

    if (url !== '') {
      setBusy(this.el.submit, true, 'Проверяем ссылку…');
      try {
        ({ ticket } = await resolveSource(url));
      } catch (e) {
        this.hint(sourceErrorText(e));
        setBusy(this.el.submit, false, 'Создать комнату');
        this.el.input.focus();
        return;
      }
    }

    setBusy(this.el.submit, true, 'Создаём комнату…');
    const created = await this.actions.askName((name) => this.actions.create(name, ticket));
    if (!created) {
      setBusy(this.el.submit, false, 'Создать комнату');
    }
  }

  hint(text) {
    this.el.hint.textContent = text;
  }
}
