// Rendering from <template> elements in index.html. External data (titles, names, URLs from
// video platforms) is only ever assigned via textContent or validated attributes — never as HTML.

import { formatBytes, formatDuration, formatEta, formatPercent, plural } from './format.js';

function clone(id) {
  const template = document.getElementById(id);
  return template.content.cloneNode(true);
}

function slot(root, name) {
  return root.querySelector(`[data-slot="${name}"]`);
}

export function renderLoading(container) {
  container.replaceChildren(clone('tpl-loading'));
}

/**
 * Media header (thumbnail, title, meta). Returns the body element for the next step.
 */
export function renderMedia(container, video) {
  const fragment = clone('tpl-media');

  const thumb = slot(fragment, 'thumb');
  if (typeof video.thumbnailUrl === 'string' && video.thumbnailUrl.startsWith('https://')) {
    thumb.src = video.thumbnailUrl;
    thumb.hidden = false;
    thumb.addEventListener('error', () => {
      thumb.hidden = true;
    }, { once: true });
  }

  const duration = formatDuration(video.durationSec);
  if (duration) {
    const badge = slot(fragment, 'duration');
    badge.textContent = duration;
    badge.hidden = false;
  }

  slot(fragment, 'platform').textContent = video.platform ?? '';
  slot(fragment, 'title').textContent = video.title ?? 'Без названия';
  slot(fragment, 'meta').textContent = [video.uploader, duration].filter(Boolean).join(' · ');

  container.replaceChildren(fragment);

  return slot(container, 'body');
}

function optionSize(option) {
  if (typeof option.sizeBytes !== 'number') {
    return 'размер неизвестен';
  }
  return `${option.sizeIsApprox ? '≈ ' : ''}${formatBytes(option.sizeBytes)}`;
}

/**
 * Format picker. Calls onSubmit(optionId).
 */
export function renderOptions(body, options, preselectedId, onSubmit) {
  const fragment = clone('tpl-options');
  const groups = { video: slot(fragment, 'video'), audio: slot(fragment, 'audio') };

  for (const option of options) {
    const item = clone('tpl-option');
    const input = item.querySelector('input');
    input.value = option.id;
    input.checked = option.id === preselectedId;
    slot(item, 'label').textContent = option.label;
    slot(item, 'size').textContent = optionSize(option);
    (option.kind === 'audio' ? groups.audio : groups.video).append(item);
  }

  slot(fragment, 'video-group').hidden = groups.video.childElementCount === 0;
  slot(fragment, 'audio-group').hidden = groups.audio.childElementCount === 0;

  const form = slot(fragment, 'form');
  const submit = slot(fragment, 'submit');
  form.addEventListener('submit', (event) => {
    event.preventDefault();
    const selected = form.querySelector('input[name="option"]:checked');
    if (selected) {
      onSubmit(selected.value, submit);
    }
  });

  body.replaceChildren(fragment);

  return { form, submit };
}

/**
 * Progress view. Returns update(statusPayload).
 */
export function renderProgress(body) {
  body.replaceChildren(clone('tpl-progress'));

  const status = slot(body, 'status');
  const percent = slot(body, 'percent');
  const track = slot(body, 'track');
  const bar = slot(body, 'bar');
  const details = slot(body, 'details');

  return function update(job) {
    const progress = job.progress ?? null;
    const value = typeof progress?.percent === 'number' ? progress.percent : null;
    let label = 'Подготовка…';
    let determinate = false;

    if (job.status === 'queued') {
      const ahead = typeof job.queuePosition === 'number' ? job.queuePosition : 0;
      label = ahead > 0 ? `В очереди: перед вами ${ahead} ${plural(ahead, 'загрузка', 'загрузки', 'загрузок')}` : 'В очереди…';
    } else if (job.status === 'processing') {
      label = 'Обработка файла…';
    } else if (job.status === 'downloading' && value !== null && value > 0) {
      label = 'Загрузка';
      determinate = true;
    }

    status.textContent = label;
    track.dataset.indeterminate = String(!determinate);
    if (determinate) {
      percent.textContent = formatPercent(value);
      bar.style.width = `${Math.min(100, Math.max(2, value))}%`;
      track.setAttribute('aria-valuenow', String(Math.floor(value)));
      track.removeAttribute('aria-valuetext');
    } else {
      percent.textContent = '';
      bar.style.width = '';
      track.removeAttribute('aria-valuenow');
      track.setAttribute('aria-valuetext', label);
    }

    const parts = [];
    if (determinate && typeof progress?.downloadedBytes === 'number') {
      parts.push(typeof progress.totalBytes === 'number'
        ? `${formatBytes(progress.downloadedBytes)} из ${formatBytes(progress.totalBytes)}`
        : formatBytes(progress.downloadedBytes));
    }
    if (determinate && typeof progress?.speedBps === 'number' && progress.speedBps > 0) {
      parts.push(`${formatBytes(progress.speedBps)}/с`);
    }
    const eta = determinate ? formatEta(progress?.etaSec) : '';
    if (eta) {
      parts.push(`осталось ${eta}`);
    }
    details.textContent = parts.join(' · ');
  };
}

export function renderDone(body, file) {
  body.replaceChildren(clone('tpl-done'));
  const link = slot(body, 'file-link');
  link.href = file.url;
  link.setAttribute('download', file.name);
  slot(body, 'file-meta').textContent = [file.name, formatBytes(file.sizeBytes)].filter(Boolean).join(' · ');
}

export function renderError(container, { title, message }) {
  container.replaceChildren(clone('tpl-error'));
  slot(container, 'title').textContent = title;
  slot(container, 'message').textContent = message;
}
