<?php

declare(strict_types=1);

namespace ClipHunter\Exception;

/**
 * Every error the API can return: stable machine code, HTTP status, user-facing Russian message.
 */
enum ErrorCode: string
{
    // Request-level
    case InvalidJson = 'INVALID_JSON';
    case InvalidRequest = 'INVALID_REQUEST';
    case UnsupportedMediaType = 'UNSUPPORTED_MEDIA_TYPE';
    case PayloadTooLarge = 'PAYLOAD_TOO_LARGE';
    case ForbiddenOrigin = 'FORBIDDEN_ORIGIN';
    case NotFound = 'NOT_FOUND';
    case MethodNotAllowed = 'METHOD_NOT_ALLOWED';

    // URL / source
    case InvalidUrl = 'INVALID_URL';
    case UnsupportedSource = 'UNSUPPORTED_SOURCE';

    // Video
    case VideoUnavailable = 'VIDEO_UNAVAILABLE';
    case VideoPrivate = 'VIDEO_PRIVATE';
    case LoginRequired = 'LOGIN_REQUIRED';
    case GeoRestricted = 'GEO_RESTRICTED';
    case LiveNotSupported = 'LIVE_NOT_SUPPORTED';
    case PlaylistNotSupported = 'PLAYLIST_NOT_SUPPORTED';
    case VideoTooLong = 'VIDEO_TOO_LONG';
    case NoFormats = 'NO_FORMATS';
    case SourceTemporarilyBlocked = 'SOURCE_TEMPORARILY_BLOCKED';

    // Analysis / download lifecycle
    case AnalysisNotFound = 'ANALYSIS_NOT_FOUND';
    case InvalidOption = 'INVALID_OPTION';
    case JobNotFound = 'JOB_NOT_FOUND';
    case FileNotReady = 'FILE_NOT_READY';
    case FileExpired = 'FILE_EXPIRED';
    case FileTooLarge = 'FILE_TOO_LARGE';

    // Limits
    case RateLimited = 'RATE_LIMITED';
    case TooManyActiveJobs = 'TOO_MANY_ACTIVE_JOBS';
    case ServerBusy = 'SERVER_BUSY';
    case QueueFull = 'QUEUE_FULL';
    case StorageFull = 'STORAGE_FULL';

    // Processing
    case ExtractorFailed = 'EXTRACTOR_FAILED';
    case AnalyzeTimeout = 'ANALYZE_TIMEOUT';
    case DownloadFailed = 'DOWNLOAD_FAILED';
    case DownloadTimeout = 'DOWNLOAD_TIMEOUT';
    case ProcessingFailed = 'PROCESSING_FAILED';
    case Cancelled = 'CANCELLED';

    case InternalError = 'INTERNAL_ERROR';

    public function httpStatus(): int
    {
        return match ($this) {
            self::InvalidJson, self::InvalidRequest, self::InvalidUrl => 400,
            self::ForbiddenOrigin => 403,
            self::NotFound, self::AnalysisNotFound, self::JobNotFound => 404,
            self::MethodNotAllowed => 405,
            self::FileNotReady, self::Cancelled => 409,
            self::FileExpired => 410,
            self::PayloadTooLarge => 413,
            self::UnsupportedMediaType => 415,
            self::UnsupportedSource, self::VideoUnavailable, self::VideoPrivate, self::LoginRequired,
            self::GeoRestricted, self::LiveNotSupported, self::PlaylistNotSupported, self::VideoTooLong,
            self::NoFormats, self::InvalidOption, self::FileTooLarge => 422,
            self::RateLimited, self::TooManyActiveJobs => 429,
            self::ExtractorFailed, self::SourceTemporarilyBlocked, self::DownloadFailed, self::ProcessingFailed => 502,
            self::ServerBusy, self::QueueFull, self::StorageFull => 503,
            self::AnalyzeTimeout, self::DownloadTimeout => 504,
            self::InternalError => 500,
        };
    }

    public function defaultMessage(): string
    {
        return match ($this) {
            self::InvalidJson => 'Некорректный JSON в запросе.',
            self::InvalidRequest => 'Некорректный запрос.',
            self::UnsupportedMediaType => 'Ожидается запрос в формате JSON.',
            self::PayloadTooLarge => 'Слишком большой запрос.',
            self::ForbiddenOrigin => 'Запрос с другого сайта отклонён.',
            self::NotFound => 'Не найдено.',
            self::MethodNotAllowed => 'Метод не поддерживается.',
            self::InvalidUrl => 'Это не похоже на ссылку на видео. Проверьте адрес.',
            self::UnsupportedSource => 'Этот сайт пока не поддерживается.',
            self::VideoUnavailable => 'Видео недоступно: возможно, оно удалено или скрыто.',
            self::VideoPrivate => 'Это приватное видео — скачать его нельзя.',
            self::LoginRequired => 'Видео доступно только после входа в аккаунт — такие мы не скачиваем.',
            self::GeoRestricted => 'Видео недоступно в регионе нашего сервера.',
            self::LiveNotSupported => 'Прямые трансляции не поддерживаются.',
            self::PlaylistNotSupported => 'Плейлисты не поддерживаются — вставьте ссылку на одно видео.',
            self::VideoTooLong => 'Видео слишком длинное.',
            self::NoFormats => 'Не нашли доступных для скачивания форматов.',
            self::SourceTemporarilyBlocked => 'Площадка временно ограничила доступ. Попробуйте позже.',
            self::AnalysisNotFound => 'Ссылка устарела. Вставьте её ещё раз.',
            self::InvalidOption => 'Выбранный формат недоступен.',
            self::JobNotFound => 'Загрузка не найдена.',
            self::FileNotReady => 'Файл ещё не готов.',
            self::FileExpired => 'Срок хранения файла истёк. Запустите загрузку заново.',
            self::FileTooLarge => 'Файл слишком большой. Выберите качество пониже.',
            self::RateLimited => 'Слишком много запросов. Попробуйте чуть позже.',
            self::TooManyActiveJobs => 'Дождитесь окончания текущей загрузки.',
            self::ServerBusy => 'Сервер сейчас загружен. Попробуйте через минуту.',
            self::QueueFull => 'Очередь загрузок заполнена. Попробуйте через несколько минут.',
            self::StorageFull => 'На сервере временно нет места. Попробуйте позже.',
            self::ExtractorFailed => 'Не удалось получить информацию о видео.',
            self::AnalyzeTimeout => 'Площадка слишком долго отвечает. Попробуйте ещё раз.',
            self::DownloadFailed => 'Не удалось скачать видео.',
            self::DownloadTimeout => 'Загрузка заняла слишком много времени.',
            self::ProcessingFailed => 'Не удалось обработать файл.',
            self::Cancelled => 'Загрузка отменена.',
            self::InternalError => 'Что-то пошло не так. Мы уже разбираемся.',
        };
    }
}
