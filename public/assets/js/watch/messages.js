// Russian texts for watch-room errors and system chat lines. The rooms service sends only codes.

const WS_ERRORS = {
  BAD_MESSAGE: 'Не удалось выполнить действие. Обновите страницу.',
  UNKNOWN_TYPE: 'Не удалось выполнить действие. Обновите страницу.',
  NOT_IN_ROOM: 'Вы не в комнате. Обновите страницу.',
  ALREADY_IN_ROOM: 'Вы уже в комнате.',
  ROOM_NOT_FOUND: 'Комната не найдена: возможно, она уже закрылась.',
  ROOM_FULL: 'Комната заполнена: в ней уже 5 человек.',
  ROOM_LIMIT: 'Сейчас открыто слишком много комнат. Попробуйте через несколько минут.',
  NAME_INVALID: 'Введите имя от 1 до 24 символов.',
  NAME_TAKEN: 'Это имя уже занято в комнате. Выберите другое.',
  RESUME_FAILED: 'Не удалось вернуться в комнату.',
  FORBIDDEN: 'Это может сделать только ведущий.',
  INVALID_TICKET: 'Не удалось подтвердить видео. Вставьте ссылку ещё раз.',
  TICKET_EXPIRED: 'Ссылка устарела. Вставьте её ещё раз.',
  INVALID_POSITION: 'Не удалось перемотать видео.',
  NO_MEDIA: 'Видео ещё не выбрано.',
  CHAT_INVALID: 'Сообщение должно быть от 1 до 500 символов.',
  RATE_LIMITED: 'Слишком много действий подряд. Подождите немного.',
  KICKED: 'Ведущий удалил вас из этой комнаты. Вернуться можно через 10 минут.',
  SERVER_BUSY: 'Сервер перегружен. Попробуйте через минуту.',
  INTERNAL_ERROR: 'Что-то пошло не так. Попробуйте ещё раз.',
  TIMEOUT: 'Сервер не ответил. Проверьте подключение.',
  DISCONNECTED: 'Нет соединения с сервером.',
};

/** @param {string} code */
export function roomErrorText(code) {
  return WS_ERRORS[code] ?? WS_ERRORS.INTERNAL_ERROR;
}

/** Full-screen states after which the room cannot continue. */
export const TERMINAL_SCREENS = {
  not_found: {
    title: 'Комната не найдена',
    text:
      'Возможно, ссылка неполная или комната уже закрылась: она живёт 30 минут после ухода последнего участника.',
  },
  full: {
    title: 'Комната заполнена',
    text: 'В комнате уже 5 человек — это максимум. Создайте свою и пригласите друзей.',
  },
  kicked: {
    title: 'Вас удалили из комнаты',
    text: 'Ведущий удалил вас из этой комнаты. Вы можете создать свою.',
  },
  closed: {
    title: 'Комната закрыта',
    text: 'Комнаты живут не дольше 12 часов. Создайте новую — это быстро.',
  },
  replaced: {
    title: 'Комната открыта в другой вкладке',
    text: 'Вы продолжили просмотр в другой вкладке или окне. Эту вкладку можно закрыть.',
  },
  error: {
    title: 'Соединение прервано',
    text: 'Сервер закрыл соединение. Обновите страницу, чтобы вернуться в комнату.',
  },
};

/**
 * A system chat line. Present-tense and impersonal forms avoid guessing anyone's gender.
 * @param {{event?: string, name: string, text?: string|null}} message
 * @returns {string}
 */
export function systemLine(message) {
  switch (message.event) {
    case 'joined':
      return `${message.name} присоединяется к просмотру`;
    case 'left':
      return `${message.name} выходит из комнаты`;
    case 'kicked':
      return `Ведущий удалил участника ${message.name}`;
    case 'host':
      return `Ведущий теперь — ${message.name}`;
    case 'media':
      return message.text
        ? `${message.name} включает «${message.text}»`
        : `${message.name} включает новое видео`;
    default:
      return message.name;
  }
}

/** Presence of a participant for the list. */
export function presenceText(participant) {
  if (!participant.connected) {
    return 'Переподключается…';
  }
  switch (participant.status) {
    case 'watching':
      return 'Смотрит';
    case 'buffering':
      return 'Загружается';
    default:
      return 'В комнате';
  }
}

/** Texts for watch-media preparation failures (codes from /api/watch/media/{id}). */
export function preparationErrorText(code, fallback) {
  const texts = {
    DOWNLOAD_FAILED: 'Площадка не отдала видео нашему серверу.',
    DOWNLOAD_TIMEOUT: 'Подготовка заняла слишком много времени.',
    FILE_TOO_LARGE: 'Видео слишком большое для совместного просмотра.',
    PROCESSING_FAILED: 'Не удалось обработать видео.',
    STORAGE_FULL: 'На сервере временно нет места. Попробуйте позже.',
  };

  return texts[code] ?? fallback ?? 'Не удалось подготовить видео.';
}

/**
 * Player errors (SyncStatus.overlay.error). embedRefused: the platform will not play the video on
 * our site, so the host may let our server prepare it instead (when the kind allows that).
 */
const PLAYER_ERRORS = {
  YT_2: { text: 'Плеер YouTube не принял ссылку на видео.' },
  YT_5: { text: 'Плеер YouTube не смог воспроизвести видео в этом браузере.' },
  YT_100: { text: 'Видео недоступно: его удалили или сделали приватным.' },
  YT_101: { text: 'Владелец запретил показывать это видео на других сайтах.', embedRefused: true },
  YT_150: { text: 'Владелец запретил показывать это видео на других сайтах.', embedRefused: true },
  YT_153: { text: 'Плеер YouTube не получил адрес страницы. Обновите страницу.' },
  YT_API_UNAVAILABLE: { text: 'Не удалось загрузить плеер YouTube. Проверьте, не блокирует ли его браузер.' },
  VK_API_UNAVAILABLE: {
    text: 'Не удалось загрузить плеер ВКонтакте. Проверьте, не блокирует ли его браузер.',
  },
  VK_UNAVAILABLE: {
    text:
      'ВКонтакте не показывает это видео на других сайтах: оно удалено, закрыто или доступно только после входа.',
    embedRefused: true,
  },
  TWITCH_API_UNAVAILABLE: {
    text: 'Не удалось загрузить плеер Twitch. Проверьте, не блокирует ли его браузер.',
  },
  TWITCH_UNAVAILABLE: {
    text:
      'Twitch не показывает эту запись на других сайтах: она удалена, только для подписчиков или требует входа.',
    embedRefused: true,
  },
  ANILIBERTY_UNAVAILABLE: { text: 'Не удалось получить серию с AniLiberty. Попробуйте позже.' },
  HLS_UNSUPPORTED: { text: 'Этот браузер не умеет воспроизводить потоковое видео (HLS).' },
  HLS_NETWORK: { text: 'Поток видео не загружается. Проверьте подключение и обновите страницу.' },
  MEDIA_UNSUPPORTED: { text: 'Браузер не может воспроизвести этот файл.' },
};

/**
 * @param {string} code
 * @returns {{text: string, embedRefused: boolean}}
 */
export function playerErrorText(code) {
  const entry = PLAYER_ERRORS[code];

  return {
    text: entry?.text ?? 'Не удалось воспроизвести видео.',
    embedRefused: entry?.embedRefused === true,
  };
}
