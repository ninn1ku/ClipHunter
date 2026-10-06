// The downloader: a small state machine driving the hero form and the result card.
//
//   idle → analyzing → result → downloading → completed
//              ↓          ↑          ↓
//            error ───────┴──────  error
//
// Every async continuation checks `generation`, so a reset or a new link always wins over
// responses that arrive late.

import { api, ApiError } from './api.js';
import { clientMessage, describeError } from './messages.js';
import { renderDone, renderError, renderLoading, renderMedia, renderOptions, renderProgress } from './views.js';

const POLL_VISIBLE_MS = 1000;
const POLL_HIDDEN_MS = 3000;
const POLL_MAX_NETWORK_FAILURES = 6;
const PREFERRED_OPTIONS = ['v1080', 'v720', 'v480'];

export class App {
  constructor(root) {
    this.form = root.querySelector('#analyze-form');
    this.input = root.querySelector('#url-input');
    this.button = root.querySelector('#analyze-button');
    this.hint = root.querySelector('#url-hint');
    this.result = root.querySelector('#result');
    this.live = root.querySelector('#live');

    this.phase = 'idle';
    this.generation = 0;
    this.lastUrl = '';
    this.analysis = null;
    this.body = null;
    this.jobId = null;
    this.pollTimer = null;
    this.networkFailures = 0;
    this.autoDownloaded = new Set();
    this.updateProgress = () => {};
    this.failedStage = null;
  }

  init() {
    this.form.addEventListener('submit', (event) => {
      event.preventDefault();
      this.analyze(this.input.value);
    });

    this.input.addEventListener('input', () => this.setHint(''));

    // Pasting a link into the empty field starts right away — the most common path.
    this.input.addEventListener('paste', () => {
      const wasEmpty = this.input.value.trim() === '';
      setTimeout(() => {
        if (wasEmpty && this.phase !== 'analyzing' && /^\s*(https?:\/\/)?\S+\.\S+\s*$/i.test(this.input.value)) {
          this.analyze(this.input.value);
        }
      }, 0);
    });

    this.result.addEventListener('click', (event) => {
      const action = event.target.closest('[data-action]')?.dataset.action;
      if (action === 'reset') {
        this.reset();
      } else if (action === 'retry') {
        this.retry();
      } else if (action === 'cancel') {
        this.cancel();
      }
    });

    document.addEventListener('visibilitychange', () => {
      if (!document.hidden && this.phase === 'downloading') {
        this.schedulePoll(0);
      }
    });
  }

  // ---------- Analyze ----------

  async analyze(rawUrl) {
    const url = rawUrl.trim();
    if (url === '') {
      this.setHint(clientMessage('EMPTY_URL'));
      this.input.focus();
      return;
    }
    if (/\s/.test(url) || !url.includes('.')) {
      this.setHint(clientMessage('NOT_A_URL'));
      this.input.focus();
      return;
    }

    const generation = this.begin('analyzing');
    this.lastUrl = url;
    this.setHint('');
    this.setBusy(true);
    this.result.hidden = false;
    renderLoading(this.result);
    this.announce('Получаем информацию о видео…');

    try {
      const analysis = await api('POST', '/api/analyze', { url }, { timeoutMs: 60000 });
      if (generation !== this.generation) {
        return;
      }
      this.analysis = analysis;
      this.showOptions();
      this.announce(`Видео найдено: ${analysis.video.title}. Выберите качество.`);
      this.result.querySelector('[data-slot="title"]')?.focus({ preventScroll: true });
      this.result.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    } catch (error) {
      if (generation === this.generation) {
        this.fail(error, 'analyze');
      }
    } finally {
      if (generation === this.generation) {
        this.setBusy(false);
      }
    }
  }

  showOptions() {
    this.phase = 'result';
    this.body = renderMedia(this.result, this.analysis.video);
    const options = this.analysis.options;
    const preselected = PREFERRED_OPTIONS.find((id) => options.some((o) => o.id === id)) ?? options[0]?.id;
    renderOptions(this.body, options, preselected, (optionId, submit) => this.download(optionId, submit));
    const watch = this.body.querySelector('[data-slot="watch-link"]');
    if (watch) {
      watch.href = `/watch?url=${encodeURIComponent(this.analysis.video.webpageUrl)}`;
    }
  }

  // ---------- Download ----------

  async download(optionId, submitButton) {
    const generation = this.begin('downloading');
    submitButton.setAttribute('aria-busy', 'true');
    submitButton.disabled = true;

    try {
      const job = await api('POST', '/api/downloads', { analysisId: this.analysis.analysisId, optionId });
      if (generation !== this.generation) {
        return;
      }
      this.jobId = job.jobId;
      this.networkFailures = 0;
      this.updateProgress = renderProgress(this.body);
      this.updateProgress(job);
      this.announce('Загрузка поставлена в очередь.');
      this.schedulePoll(POLL_VISIBLE_MS);
    } catch (error) {
      if (generation === this.generation) {
        this.fail(error, error instanceof ApiError && error.code === 'ANALYSIS_NOT_FOUND' ? 'analyze' : 'download');
      }
    }
  }

  schedulePoll(delay) {
    clearTimeout(this.pollTimer);
    const generation = this.generation;
    this.pollTimer = setTimeout(() => this.poll(generation), delay);
  }

  async poll(generation) {
    if (generation !== this.generation || this.phase !== 'downloading') {
      return;
    }

    let job;
    try {
      job = await api('GET', `/api/downloads/${this.jobId}`, null, { timeoutMs: 15000 });
    } catch (error) {
      if (generation !== this.generation) {
        return;
      }
      const transient = error instanceof ApiError && (error.status === 0 || error.status >= 500);
      if (transient && ++this.networkFailures <= POLL_MAX_NETWORK_FAILURES) {
        this.schedulePoll(Math.min(10000, 1000 * 2 ** this.networkFailures));
        return;
      }
      this.fail(error instanceof ApiError && error.code === 'JOB_NOT_FOUND' ? error : new ApiError('JOB_LOST', null, 0), 'download');
      return;
    }
    if (generation !== this.generation) {
      return;
    }
    this.networkFailures = 0;

    if (job.status === 'completed' && job.file) {
      this.complete(job);
      return;
    }
    if (['failed', 'cancelled', 'expired'].includes(job.status)) {
      const code = job.error?.code ?? (job.status === 'cancelled' ? 'CANCELLED' : 'DOWNLOAD_FAILED');
      this.fail(new ApiError(code, job.error?.message ?? null, 200), 'download');
      return;
    }

    this.updateProgress(job);
    this.schedulePoll(document.hidden ? POLL_HIDDEN_MS : POLL_VISIBLE_MS);
  }

  complete(job) {
    this.phase = 'completed';
    renderDone(this.body, job.file);
    this.announce('Файл готов, загрузка началась.');
    this.body.querySelector('[data-slot="file-link"]')?.focus({ preventScroll: true });

    // Content-Disposition: attachment makes the browser download without leaving the page.
    if (!this.autoDownloaded.has(job.jobId)) {
      this.autoDownloaded.add(job.jobId);
      window.location.assign(job.file.url);
    }
  }

  async cancel() {
    const jobId = this.jobId;
    this.jobId = null;
    this.begin('result');
    this.showOptions();
    this.announce('Загрузка отменена.');
    if (jobId) {
      try {
        await api('DELETE', `/api/downloads/${jobId}`);
      } catch {
        // The worker stops on its own when the job expires; nothing useful to show here.
      }
    }
  }

  // ---------- Errors / reset ----------

  fail(error, stage) {
    this.phase = 'error';
    this.failedStage = stage;
    const described = describeError(error instanceof ApiError ? error : new ApiError('INTERNAL_ERROR', null, 0));
    const keepMedia = stage === 'download' && this.analysis !== null && this.body !== null;
    renderError(keepMedia ? this.body : this.result, described);
    this.announce(`${described.title}. ${described.message}`);
    this.result.hidden = false;
    (keepMedia ? this.body : this.result).querySelector('[data-action="retry"]')?.focus({ preventScroll: true });
  }

  retry() {
    if (this.failedStage === 'download' && this.analysis) {
      this.begin('result');
      this.showOptions();
    } else {
      this.analyze(this.lastUrl || this.input.value);
    }
  }

  reset() {
    this.begin('idle');
    this.analysis = null;
    this.body = null;
    this.jobId = null;
    this.result.hidden = true;
    this.result.replaceChildren();
    this.input.value = '';
    this.setHint('');
    this.input.focus();
  }

  // ---------- Helpers ----------

  /** Enters a new phase and invalidates pending async work from the previous one. */
  begin(phase) {
    this.generation += 1;
    clearTimeout(this.pollTimer);
    this.phase = phase;
    return this.generation;
  }

  setBusy(busy) {
    this.button.setAttribute('aria-busy', String(busy));
    this.button.disabled = busy;
    this.input.readOnly = busy;
  }

  setHint(text) {
    this.hint.textContent = text;
    this.form.dataset.invalid = String(text !== '');
    if (text) {
      this.input.setAttribute('aria-invalid', 'true');
    } else {
      this.input.removeAttribute('aria-invalid');
    }
  }

  announce(text) {
    this.live.textContent = '';
    // A fresh text node after a tick makes screen readers announce repeated messages too.
    setTimeout(() => {
      this.live.textContent = text;
    }, 50);
  }
}
