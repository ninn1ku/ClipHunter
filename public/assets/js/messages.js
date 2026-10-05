// User-facing error texts. The server already sends a Russian message for its own codes;
// these cover client-side failures and act as a fallback.

const MESSAGES = {
  NETWORK_ERROR: 'Нет связи с сервером. Проверьте подключение к интернету.',
  CLIENT_TIMEOUT: 'Сервер долго не отвечает. Попробуйте ещё раз.',
  EMPTY_URL: 'Вставьте ссылку на видео.',
  NOT_A_URL: 'Это не похоже на ссылку. Скопируйте адрес видео из браузера или приложения.',
  JOB_LOST: 'Не удалось получить статус загрузки. Попробуйте ещё раз.',
};

const TITLES = {
  RATE_LIMITED: 'Слишком много запросов',
  TOO_MANY_ACTIVE_JOBS: 'Загрузка уже идёт',
  SERVER_BUSY: 'Сервер занят',
  QUEUE_FULL: 'Очередь заполнена',
  STORAGE_FULL: 'Сервер занят',
  UNSUPPORTED_SOURCE: 'Не поддерживается',
  INVALID_URL: 'Неверная ссылка',
  CANCELLED: 'Загрузка отменена',
};

/**
 * @param {import('./api.js').ApiError} error
 * @returns {{title: string, message: string}}
 */
export function describeError(error) {
  const code = error?.code ?? 'INTERNAL_ERROR';
  let message = error?.serverMessage || MESSAGES[code];

  if (!message) {
    message = error?.status >= 500 || code.startsWith('HTTP_')
      ? 'Сервис временно недоступен. Попробуйте через минуту.'
      : 'Что-то пошло не так. Попробуйте ещё раз.';
  }
  if (error?.retryAfter && error.retryAfter > 0 && error.retryAfter <= 3600) {
    const minutes = Math.ceil(error.retryAfter / 60);
    message += error.retryAfter < 60 ? ` Повторите через ${error.retryAfter} с.` : ` Повторите через ${minutes} мин.`;
  }

  return { title: TITLES[code] ?? 'Не получилось', message };
}

export function clientMessage(code) {
  return MESSAGES[code] ?? '';
}
