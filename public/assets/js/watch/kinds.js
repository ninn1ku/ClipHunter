// Facts about media kinds that do not depend on a player instance. Pure: no DOM.
//
//   youtube, vk, twitch, aniliberty — play in the browser straight from the platform;
//   file — prepared by our worker first (GET /api/watch/media/{id} reports the progress).

/**
 * @param {{kind: string}|null} media
 * @returns {boolean} whether the server has to prepare a file before anyone can watch
 */
export function needsPreparation(media) {
  return media?.kind === 'file';
}

/**
 * The public page of the video, for preparing it through our server when the platform's embed
 * refuses to play it ("Подготовить через сервер"). null when there is no such fallback: live
 * streams cannot be prepared, AniLiberty is not a yt-dlp source, files already are.
 *
 * @param {{kind: string, ref: string}|null} media
 * @returns {string|null}
 */
export function serverFallbackUrl(media) {
  switch (media?.kind) {
    case 'youtube':
      return `https://www.youtube.com/watch?v=${encodeURIComponent(media.ref)}`;
    case 'vk':
      return `https://vkvideo.ru/video${media.ref}`;
    case 'twitch': {
      const match = /^video:(\d+)$/.exec(media.ref);
      return match === null ? null : `https://www.twitch.tv/videos/${match[1]}`;
    }
    default:
      return null;
  }
}

/**
 * What to call the video when the ticket has no title and the player tells none.
 * @param {{kind: string, ref: string}} media
 */
export function fallbackTitle(media) {
  switch (media.kind) {
    case 'youtube':
      return 'Видео с YouTube';
    case 'vk':
      return 'Видео ВКонтакте';
    case 'twitch': {
      const channel = /^channel:(.+)$/.exec(media.ref);
      return channel === null ? 'Запись с Twitch' : `Эфир ${channel[1]} на Twitch`;
    }
    case 'aniliberty':
      return 'Аниме с AniLiberty';
    default:
      return 'Видео';
  }
}

/** Why there is no quality menu for players that choose the quality themselves. */
export function playerQualityNote(kind) {
  switch (kind) {
    case 'youtube':
      return 'У YouTube качество выбирает сам плеер — по скорости сети и размеру окна.';
    case 'vk':
      return 'Качество выбирает плеер ВКонтакте — по скорости сети.';
    case 'twitch':
      return 'Качество меняется в меню плеера Twitch.';
    default:
      return 'Качество выбирает плеер.';
  }
}
