// The start page (/watch): an optional video link and "Создать комнату".

import { AnimeSearch, looksLikeUrl } from './anime-search.js';
import { setBusy } from './dom.js';
import { resolveSource, sourceErrorText } from './source-dialog.js';

export class StartView {
  /**
   * @param {{form: HTMLFormElement, input: HTMLInputElement, submit: HTMLButtonElement, hint: HTMLElement,
   *   results: HTMLElement}} elements
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
    this.search = new AnimeSearch(elements.results, (release) => this.start(release.pageUrl));
  }

  /** @param {string|null} prefill from /watch?url=… */
  show(prefill) {
    if (prefill) {
      this.el.input.value = prefill;
    }
    setBusy(this.el.submit, false, 'Создать комнату');
    this.hint('');
    this.search.clear();
  }

  async submit() {
    const text = this.el.input.value.trim();
    if (text !== '' && !looksLikeUrl(text)) {
      setBusy(this.el.submit, true, 'Ищем…');
      try {
        await this.search.run(text);
      } catch (e) {
        this.hint(sourceErrorText(e));
      } finally {
        setBusy(this.el.submit, false, 'Создать комнату');
      }
      return;
    }
    await this.start(text);
  }

  /** @param {string} url a pasted link, the page of a found title, or '' for a room without video */
  async start(url) {
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
