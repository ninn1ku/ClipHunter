# ClipHunter — архитектура и план реализации

Статус: черновик архитектурного этапа, 2026-10-05. Реализация ещё не начата.

---

## 0. Результаты инспекции окружения

### Локальная машина (Windows 11, `C:\Users\halsy\Desktop\ClipHunter`)

| Инструмент | Состояние |
|---|---|
| Каталог проекта | Содержит только `CLAUDE.md` и `design-reference.png`. Git-репозитория нет. Это правильный каталог ClipHunter. |
| Git | 2.55, `user.name=ninniku`, `user.email=halsyye90@gmail.com`, `init.defaultBranch=main` |
| GitHub CLI (`gh`) | **не установлен** |
| Доступ к GitHub | SSH-ключ `~/.ssh/id_ed25519` на GitHub **не добавлен** (`Permission denied (publickey)`). В Windows Credential Manager есть записи для `github.com` (пользователь `ninn1ku`), но проверить токен не удалось. Репозиториев `ninn1ku/ClipHunter` и `ninn1ku/cliphunter` без авторизации не видно. |
| PHP, Composer | **не установлены** |
| yt-dlp, ffmpeg | **не установлены** |
| Docker, WSL | **не установлены** |
| Есть | Node 24, Python 3.14, winget, Chocolatey, OpenSSH 9.5, 15.7 ГБ RAM |

### Production-сервер `root@193.233.198.66`

| Параметр | Значение |
|---|---|
| ОС | Debian 12 (bookworm), ядро 6.1 |
| Ресурсы | **1 vCPU (AMD EPYC), 958 МБ RAM, swap нет, диск 9.9 ГБ (8.1 ГБ свободно)** |
| Расположение | Франкфурт, Digital Hosting Provider LLC (IP датацентра) |
| Установлено | только `python3` 3.11, `curl`, `sshd`, `cron` |
| Нет | nginx, PHP (в apt Debian только 8.2), Composer, git, yt-dlp, ffmpeg, certbot, firewall, `sudo` (работаем от root) |
| Открытые порты | только 22 |
| Каталог деплоя | `/var/www` не существует; готового пути нет |
| Исходящая сеть | YouTube, TikTok, VK, Instagram, Twitch, GitHub, packages.sury.org, DuckDNS доступны; RuTube ответил `403` (возможно, гео-ограничение) |
| Проба yt-dlp 2026.08.19 (временный бинарник в `/tmp`, удалён) | YouTube — метаданные и 24 формата получены. Reddit — работает. Vimeo — требует логин, это вне рамок проекта (не обходим авторизацию). |

**Главный вывод:** сервер очень маленький. Архитектура рассчитана на 1 одновременное скачивание, swap на 2 ГБ, жёсткие лимиты на размер и длительность, и ни одного лишнего процесса: без Docker, БД и Redis.

---

## 1. Executive Summary

ClipHunter — одностраничный сервис: пользователь вставляет ссылку, видит превью и варианты качества, выбирает вариант и скачивает готовый файл.

Ключевые решения:

1. **PHP 8.4 без фреймворка.** Тонкое ядро из небольших библиотек: FastRoute, PSR-7 (nyholm), `symfony/process`, Monolog, phpdotenv. Минимальная требуемая версия в `composer.json` — `>=8.3`.
2. **Статический фронтенд** на HTML, CSS и vanilla JS (ES-модули) без сборки. Nginx отдаёт статику сам, PHP обслуживает только `/api/*`.
3. **Асинхронные скачивания.** Задание (job) ставится в очередь, его выполняет отдельный **systemd-воркер** на PHP. Анализ ссылки остаётся синхронным, с таймаутом 30 с.
4. **Без базы данных.** Задания, результаты анализа и счётчики лимитов хранятся как JSON-файлы в `storage/` с атомарной записью и `flock`. Хранилище спрятано за интерфейсами, поэтому позже его можно заменить на SQLite или Redis без переписывания сервисов.
5. **Безопасность.** Allowlist доменов платформ, запрет generic-экстрактора yt-dlp (главная защита от SSRF), запуск процессов только массивом аргументов с разделителем `--`, имена файлов на диске генерируются сервером, файл отдаётся через Nginx `X-Accel-Redirect`.
6. **Native Linux без Docker.** Nginx, PHP-FPM, systemd. Деплой из Git по схеме `releases/<sha>` + симлинк `current`, откат сводится к переключению симлинка.
7. **HTTPS** через Let's Encrypt (certbot) на поддомене DuckDNS.

---

## 2. Architecture Overview

```text
Браузер (static HTML/CSS/JS)
   │  fetch JSON
   ▼
Nginx ── статика public/ ── X-Accel: internal /_files/ → storage/downloads
   │  /api/* → FastCGI
   ▼
PHP-FPM: public/index.php → Kernel → Router → Controllers
   │                                   │
   │                         Services (Analyze, Download, RateLimit)
   │                                   │
   │            ┌──────────────────────┼─────────────────────┐
   │            ▼                      ▼                     ▼
   │     UrlValidator          MediaExtractor (yt-dlp)   JobRepository / AnalysisRepository
   │     (SSRF guard)          ProcessRunner              (filesystem JSON + flock)
   │                                                          ▲
   ▼                                                          │
systemd: cliphunter-worker (bin/worker.php) ──────────────────┘
   │  берёт job из очереди, запускает yt-dlp (+ffmpeg через yt-dlp), проверяет ffprobe
   ▼
storage/tmp/<jobId>/ → storage/downloads/<jobId>/media.<ext>

systemd timer: cliphunter-cleanup (каждые 5 мин) — удаляет просроченные файлы, задания и анализы
systemd timer: cliphunter-ytdlp-update (раз в сутки) — обновляет yt-dlp и прогоняет smoke-тест
```

Слои соответствуют разделу 8 `CLAUDE.md`. Отклонение одно: вместо отдельного «Download subsystem» внутри HTTP-запроса работает отдельный процесс-воркер. Только так длинные скачивания не держат PHP-FPM-воркеры, а на 1 ГБ RAM их всего 4.

---

## 3. Technology Stack

| Слой | Выбор | Почему |
|---|---|---|
| Язык | PHP 8.4 (пакеты sury.org), `strict_types` | Требование 8.3+; в Debian 12 есть только 8.2 |
| HTTP | `nyholm/psr7`, `nyholm/psr7-server`, `nikic/fast-route` | PSR-7 удобен в тестах; фреймворк ради 5 эндпоинтов не нужен |
| Процессы | `symfony/process` | Аргументы массивом без shell, таймауты, idle-таймауты, сигналы |
| Конфиг | `vlucas/phpdotenv` | `.env` в dev; в prod — `/var/www/cliphunter/shared/.env` |
| Логи | `monolog/monolog` (JSON) | Структурированные логи |
| DI | Ручной контейнер (`App\Container`, фабрики) | Около 15 сервисов, autowiring-контейнер избыточен |
| Тесты | PHPUnit 11, PHPStan (level max), PHP-CS-Fixer (PSR-12) | |
| Загрузчик | yt-dlp в venv `/opt/yt-dlp` (`pip install "yt-dlp[default]"`) + **deno** | `[default]` подтягивает `curl_cffi` (impersonation для TikTok и др.). Для полного списка форматов YouTube yt-dlp нужен JS-рантайм (deno). Обновление через pip. |
| Медиа | ffmpeg / ffprobe 5.1 (apt Debian) | Merge (remux без перекодирования), извлечение MP3, проверка результата |
| Web | Nginx (apt), PHP-FPM `pm=ondemand` | |
| TLS | certbot (`--nginx`), HTTP-01 | |
| Фронтенд | HTML5, CSS custom properties, ES-модули | Без фреймворка и сборки |
| Шрифт | Manrope (OFL, кириллица), self-hosted woff2 | Без Google Fonts: сторонние запросы не нужны, это важно для privacy |
| CI | GitHub Actions (ubuntu, PHP 8.3 и 8.4, ffmpeg, yt-dlp) | Проверка на Linux, раз локально Windows |

---

## 4. UX Flow

```text
Idle ──(ввод URL + «Скачать»)──▶ Analyzing ──ok──▶ Result ──(выбор качества + «Скачать файл»)──▶ Downloading
  ▲                                │                  │                                            │
  │                                └──err──▶ Error ◀──┴──────────────────────err────────────────────┤
  │                                           │                                                    ▼
  └────────────(«Попробовать снова» / новая ссылка)◀──────────────────────────────────────── Completed
```

| Состояние | Что видит пользователь |
|---|---|
| Idle | Hero, поле ввода с иконкой ссылки, основная кнопка «Скачать». Вставка из буфера по Enter. |
| Analyzing | Кнопка в состоянии загрузки (спиннер, `aria-busy`), поле заблокировано, ниже скелет карточки результата |
| Result | Карточка: превью, название, автор, длительность, платформа. Выбор формата: вкладки «Видео / Аудио» и плитки качества (radio) с размером «≈ 245 МБ», если он известен. Кнопка «Скачать файл». |
| Downloading | Прогресс-бар (`role=progressbar`), этапы «В очереди → Загрузка 42 % → Обработка», скорость и ETA, кнопка «Отменить» |
| Completed | «Файл готов». Скачивание стартует автоматически, плюс кнопка-ссылка «Скачать ещё раз» и пометка «доступно 30 минут». «Скачать другое видео» возвращает в Idle. |
| Error | Понятное сообщение по коду ошибки и действие («Проверьте ссылку», «Попробуйте через N минут»). Технических деталей нет. |

Все смены состояний объявляются через `aria-live="polite"`; фокус переносится на заголовок карточки результата и на сообщение об ошибке.

---

## 5. API Specification

Общие правила:

- База `/api`, только JSON. Для `POST` обязателен `Content-Type: application/json` (иначе `415`), тело не больше 4 КБ.
- Ошибки всегда в одном формате:
  `{"error": {"code": "UNSUPPORTED_SOURCE", "message": "Этот сайт не поддерживается."}}`. При `429` и `503` добавляется `Retry-After`.
- В каждом ответе есть заголовок `X-Request-Id`.
- CORS-заголовков нет. Запросы с чужим `Origin` или `Sec-Fetch-Site: cross-site` отклоняются с `403 FORBIDDEN_ORIGIN`.
- ID — 32 hex-символа (`bin2hex(random_bytes(16))`) и проверяются регуляркой `^[a-f0-9]{32}$` **до** любого обращения к ФС.

### 5.1 `POST /api/analyze`

Запрос: `{"url": "https://www.youtube.com/watch?v=..."}`

Валидация: `url` — строка от 1 до 2048 символов, далее полный пайплайн из раздела 8.2.

Ответ `200`:

```json
{
  "analysisId": "9f1c…",
  "expiresAt": "2026-10-05T12:30:00Z",
  "video": {
    "platform": "YouTube",
    "title": "…",
    "uploader": "…",
    "durationSec": 213,
    "thumbnailUrl": "https://i.ytimg.com/…",
    "webpageUrl": "https://www.youtube.com/watch?v=…"
  },
  "options": [
    {"id": "v1080", "kind": "video", "label": "1080p", "container": "mp4", "height": 1080, "sizeBytes": 256000000, "sizeIsApprox": true},
    {"id": "v720",  "kind": "video", "label": "720p",  "container": "mp4", "height": 720,  "sizeBytes": null, "sizeIsApprox": false},
    {"id": "a-m4a", "kind": "audio", "label": "Аудио M4A", "container": "m4a", "sizeBytes": 3400000, "sizeIsApprox": false},
    {"id": "a-mp3", "kind": "audio", "label": "Аудио MP3", "container": "mp3", "sizeBytes": null, "sizeIsApprox": false}
  ]
}
```

| Код | `error.code` | Когда |
|---|---|---|
| 400 | `INVALID_JSON`, `INVALID_URL` | Невалидный JSON, схема URL не http/https, мусор вместо URL |
| 403 | `FORBIDDEN_ORIGIN` | Кросс-доменный запрос |
| 415 | `UNSUPPORTED_MEDIA_TYPE` | Не JSON |
| 422 | `UNSUPPORTED_SOURCE` | Домен не в allowlist или нет подходящего экстрактора |
| 422 | `VIDEO_UNAVAILABLE`, `VIDEO_PRIVATE`, `LOGIN_REQUIRED`, `GEO_RESTRICTED`, `LIVE_NOT_SUPPORTED`, `PLAYLIST_NOT_SUPPORTED`, `VIDEO_TOO_LONG`, `NO_FORMATS` | Разбор stderr и полей yt-dlp |
| 429 | `RATE_LIMITED` | Превышен лимит на IP |
| 502 | `EXTRACTOR_FAILED` | yt-dlp завершился с ошибкой неизвестного типа |
| 503 | `SERVER_BUSY` | Все слоты анализа заняты |
| 504 | `ANALYZE_TIMEOUT` | Таймаут yt-dlp |
| 500 | `INTERNAL_ERROR` | Всё прочее (детали только в логе) |

Лимиты: 20 анализов на IP за 10 минут, не больше 2 одновременных анализов на весь сервер. Плюс Nginx `limit_req` 5 r/s с burst 10 на `/api/`.

### 5.2 `POST /api/downloads`

Запрос: `{"analysisId": "9f1c…", "optionId": "v1080"}`

Клиент **не передаёт** ни URL, ни строку формата yt-dlp, только ID варианта, который сервер сам предложил на этапе анализа. Сервер переводит `optionId` в фиксированный набор аргументов yt-dlp.

Ответ `202`: `{"jobId": "…", "status": "queued", "queuePosition": 0, "statusUrl": "/api/downloads/…"}`

| Код | `error.code` |
|---|---|
| 400 | `INVALID_JSON`, `INVALID_REQUEST` |
| 404 | `ANALYSIS_NOT_FOUND` (истёк или не существует) |
| 422 | `INVALID_OPTION`, `FILE_TOO_LARGE` (если размер известен заранее) |
| 429 | `RATE_LIMITED` (10 скачиваний в час на IP), `TOO_MANY_ACTIVE_JOBS` (не больше 1 активного задания на IP) |
| 503 | `QUEUE_FULL` (больше 10 заданий в очереди), `STORAGE_FULL` (квота или мало свободного места) |

### 5.3 `GET /api/downloads/{jobId}`

Ответ `200`:

```json
{
  "jobId": "…",
  "status": "queued | downloading | processing | completed | failed | cancelled | expired",
  "queuePosition": 0,
  "progress": {"percent": 42.5, "downloadedBytes": 108000000, "totalBytes": 254000000, "speedBps": 5100000, "etaSec": 28},
  "file": {"name": "Название видео.mp4", "sizeBytes": 254112233, "url": "/api/downloads/…/file", "expiresAt": "…"},
  "error": {"code": "FILE_TOO_LARGE", "message": "…"}
}
```

Поля `progress`, `file` и `error` равны `null`, когда неприменимы. Ошибки: `404 JOB_NOT_FOUND`. Клиент опрашивает статус раз в секунду, Nginx разрешает это с запасом.

### 5.4 `GET /api/downloads/{jobId}/file`

`200`: PHP проверяет статус и отвечает заголовками `X-Accel-Redirect: /_files/<jobId>/media.mp4`, `Content-Type` (по варианту, а **не** по метаданным), `Content-Disposition: attachment; filename="video.mp4"; filename*=UTF-8''<очищенное название>`, `X-Content-Type-Options: nosniff`. Сам файл стримит Nginx, поэтому работают Range-запросы и докачка.

Ошибки: `404 JOB_NOT_FOUND`, `409 FILE_NOT_READY`, `410 FILE_EXPIRED`.

### 5.5 `DELETE /api/downloads/{jobId}`

Отмена задания: `204`. Воркер получает флаг отмены, отправляет SIGTERM группе процессов и чистит `tmp`. Для завершённого задания удаляет файл досрочно.

### 5.6 `GET /api/health`

Публично отдаёт `{"status": "ok"}`. С `127.0.0.1` расширенный ответ: версии yt-dlp и ffmpeg, свободное место, длина очереди, heartbeat воркера. Используется в деплой-скрипте и smoke-тестах.

**ID задания как capability.** Знать `jobId` (128 бит случайности) — значит иметь право скачать файл. Аккаунтов нет, поэтому это стандартная модель. ID нигде не логируется вместе с полным URL видео.

---

## 6. yt-dlp Integration

### 6.1 Интерфейсы

```php
interface MediaExtractor {
    public function analyze(ValidatedUrl $url): MediaInfo;  // DTO, уже санитизированный
}
interface MediaDownloader {
    public function download(DownloadJob $job, ProgressListener $listener, CancellationToken $cancel): DownloadedFile;
}
final class YtDlpClient implements MediaExtractor, MediaDownloader { /* ... */ }
```

`ProcessRunner` — тонкая обёртка над `symfony/process`, которую в тестах подменяет фейковый бинарник.

### 6.2 Анализ (вызывается из FPM)

```text
yt-dlp --ignore-config --no-plugin-dirs --no-playlist --skip-download
       --dump-single-json --no-warnings
       --use-extractors <allowlist-экстракторов>      # без generic
       --socket-timeout 10 --retries 2
       --cache-dir <storage>/cache/yt-dlp
       -- <URL>
```

- Аргументы передаются **массивом**, shell не используется. URL стоит **после `--`**, поэтому строка вроде `--exec=...` не может стать опцией (argument injection). Кроме того, URL к этому моменту уже прошёл валидацию и начинается с `https://`.
- Таймаут 30 с (`ANALYZE_TIMEOUT`). При превышении SIGTERM, через 2 с SIGKILL всей группе процессов.
- Ограничения: `nice -n 5`, лимит stdout 5 МБ (больший JSON — ошибка), stderr хранится последними 8 КБ и идёт только в лог.
- Глобальный семафор на 2 слота: файлы `storage/locks/analyze-{0,1}.lock` с неблокирующим `flock`. Если оба заняты, ответ `503 SERVER_BUSY`.
- Ошибки классифицируются по exit code и известным шаблонам `ERROR:` в stderr (Private video, Video unavailable, Sign in to confirm, geo restriction и т. п.). Человекочитаемый вывод используется **только** для выбора кода ошибки, данные берутся из JSON.

### 6.3 Нормализация метаданных

`MetadataSanitizer` строит `MediaInfo` из JSON и не доверяет ни одному полю:

- `title` и `uploader`: строка, NFC-нормализация, удаление управляющих и bidi-символов, обрезка до 200 и 100 символов;
- `duration`: int от 0 до лимита. Если `is_live` или `live_status` не равен `not_live`, ошибка `LIVE_NOT_SUPPORTED`. Если `_type == playlist`, ошибка `PLAYLIST_NOT_SUPPORTED`;
- `thumbnail`: только `https://`, без userinfo, длина до 2048, иначе `null`;
- форматы: из `formats[]` берутся только числовые `height`, `filesize` и `filesize_approx`, `vcodec` и `acodec` (`none` или не `none`). `format_id` клиенту не отдаются.

### 6.4 Варианты (OptionBuilder)

- **Видео:** высоты из набора `{2160, 1440, 1080, 720, 480, 360}`, которые реально есть у видео, не выше `MAX_VIDEO_HEIGHT`. Размер = видеопоток + лучший аудиопоток, «≈» если хоть одна часть приблизительная. Варианты, которые точно больше `MAX_FILE_SIZE`, не показываются.
- **Аудио:** `a-m4a` (без перекодирования) и `a-mp3` (перекодирование ffmpeg, 192 kbps).
- Если у источника нет раздельных потоков (TikTok, VK часто), остаются только реально доступные высоты комбинированных форматов.

### 6.5 Скачивание (воркер)

Карта `optionId → аргументы`, например для `v1080`:

```text
-f "bv*[height<=1080]+ba/b[height<=1080]"
-S "res:1080,vcodec:h264,acodec:m4a"       # до 1080p предпочитаем H.264/AAC ради совместимости
--merge-output-format mp4
--max-filesize <MAX_FILE_SIZE>
--match-filter "!is_live & duration <= <MAX_VIDEO_DURATION>"
--ffmpeg-location <FFMPEG_PATH>
--paths temp:<storage>/tmp/<jobId> --paths home:<storage>/tmp/<jobId>
-o "media.%(ext)s"                           # имя файла из метаданных НЕ используется
--no-mtime
--newline --progress-template "download:CH-PROGRESS %(progress)j"
-- <URL из сохранённого анализа>
```

Плюс все безопасные базовые флаги из 6.2.

- Прогресс: строки с маркером `CH-PROGRESS` содержат JSON (`downloaded_bytes`, `total_bytes(_estimate)`, `speed`, `eta`). Воркер пишет его в job-файл не чаще раза в секунду.
- Сторожевой таймер воркера раз в секунду проверяет: общий таймаут `DOWNLOAD_TIMEOUT`, размер `tmp/<jobId>` не больше `MAX_FILE_SIZE × 2` (видео + аудио + промежуточный merge), флаг отмены и свободное место. Если условие нарушено, kill группы процессов и ошибка `FILE_TOO_LARGE` / `TIMEOUT` / `CANCELLED` / `STORAGE_FULL`.
- После успеха в `tmp/<jobId>/` должен остаться ровно один файл `media.<ожидаемое расширение>`. Затем проверка ffprobe (раздел 7), `rename` в `downloads/<jobId>/` (атомарно, одна ФС) и статус `completed`.

### 6.6 Обновление yt-dlp

Площадки постоянно ломают старые версии, поэтому systemd-таймер раз в сутки делает `pip install -U yt-dlp[default]` в venv, затем запускает smoke-анализ эталонного YouTube-ролика. Если smoke-анализ не прошёл, откат на предыдущую версию через `pip install yt-dlp==<old>` и запись в лог.

---

## 7. ffmpeg Integration

- ffmpeg вызывает **сам yt-dlp** через `--ffmpeg-location`:
  - `yt-dlp → готовый файл`: если выбранный формат комбинированный (`b[...]`), ffmpeg не вызывается;
  - `yt-dlp → видео + аудио → ffmpeg merge (-c copy, remux) → mp4`: копирование потоков без перекодирования, нагрузка на CPU минимальна;
  - `a-mp3`: `-x --audio-format mp3 --audio-quality 192K`, единственное перекодирование. Для часового аудио на 1 vCPU это около 1 минуты.
- После скачивания `MediaProbe` (ffprobe, JSON-вывод, таймаут 15 с) проверяет, что файл читается, контейнер соответствует варианту, длительность больше 0 и совпадает с заявленной ±10 %, а у видео есть видеопоток. Если нет, ошибка `PROCESSING_FAILED`, файл удаляется.
- Перекодирования видео в MVP нет: на 1 vCPU это слишком дорого.

---

## 8. Security / Threat Model

### 8.1 Активы и злоумышленники

Активы: сервер (CPU, RAM, диск, канал), внутренняя сеть и metadata-эндпоинты хостера, репутация IP, файлы пользователей (временные).
Злоумышленники: анонимные пользователи, боты, сторонние сайты, которые хотят использовать API как бесплатный бэкенд, вредоносные метаданные с площадок.

### 8.2 SSRF — многоуровневая защита

1. **Парсинг URL** (`UrlValidator`):
   - `trim`, длина не больше 2048, без управляющих символов и пробелов;
   - схема только `http` или `https`. `file`, `ftp`, `data`, `javascript`, `gopher` и прочие отклоняются;
   - нет userinfo (`user:pass@`), порт пустой, 80 или 443;
   - **IP-литералы запрещены** (`http://127.0.0.1`, `http://[::1]`, `http://2130706433`, `0x7f.1` и т. п.). Хост должен быть доменным именем;
   - IDN приводится к punycode (`idn_to_ascii`), хост в нижний регистр, точка в конце убирается.
2. **Allowlist доменов** (`config/platforms.php`): хост должен совпадать с доменом платформы или быть его поддоменом. Например `youtube.com`, `youtu.be`, `tiktok.com`, `vk.com`, `vk.ru`, `vkvideo.ru`, `instagram.com`, `x.com`, `twitter.com`, `reddit.com`, `redd.it`, `twitch.tv`, `rutube.ru`, `dailymotion.com`, `ok.ru`. Каждой платформе соответствует список имён экстракторов yt-dlp.
3. **DNS-проверка**: все A/AAAA-записи хоста должны быть публичными. Отклоняются `127/8`, `10/8`, `172.16/12`, `192.168/16`, `100.64/10`, `169.254/16` (включая `169.254.169.254`), `0/8`, `224/4`, `240/4`, `::1`, `fc00::/7`, `fe80::/10`, IPv4-mapped `::ffff:0:0/96` и т. д. Это защита от ошибки в allowlist.
4. **Ограничение yt-dlp**: `--use-extractors` только для экстракторов разрешённых платформ, **generic отключён**. Даже если площадка вернёт редирект на произвольный URL, yt-dlp не станет его скачивать как «какой-то файл».
5. **На уровне ОС** (требует подтверждения, см. раздел 18): nftables-правило, которое запрещает процессам пользователя `cliphunter` исходящие соединения в приватные, link-local и metadata-диапазоны. Даже обход в коде не даст достучаться до внутренней сети.

Повторный DNS-резолв yt-dlp (DNS rebinding) закрывают уровни 4 и 5.

### 8.3 Command / argument injection

Только `symfony/process` с массивом аргументов, без shell. Пользовательский URL встречается в argv ровно один раз, после `--`. Конфигурация yt-dlp из системы игнорируется (`--ignore-config`), плагины отключены. `--exec` и подобные флаги не используются никогда.

### 8.4 Path traversal

- На диске есть только пути `storage/<area>/<jobId>/media.<ext>`. `jobId` генерирует сервер, проверка регуляркой выполняется перед любым обращением к ФС, `ext` берётся из allowlist (`mp4`, `m4a`, `mp3`, `webm`).
- Название видео используется **только** в `Content-Disposition`: удаляются `/ \ : * ? " < > |`, управляющие символы, ведущие точки, лимит 120 символов, ASCII-fallback в `filename=` и UTF-8 в `filename*=`.
- `realpath()` результата должен начинаться с `realpath(storage)`.

### 8.5 XSS

- Фронтенд строит DOM только через `textContent` и `createElement`, `innerHTML` с данными не используется.
- Строгая CSP: `default-src 'self'; img-src 'self' https: data:; script-src 'self'; style-src 'self'; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'`.
- Превью загружается напрямую с CDN площадки с `referrerpolicy="no-referrer"`. Проксирование превью через сервер не делаем: это лишняя SSRF-поверхность.

### 8.6 CSRF и злоупотребление API

Сессий, cookies и авторизации нет, поэтому классической CSRF-угрозы (действия от имени пользователя) нет, и CSRF-токены не нужны. Остаётся риск, что чужой сайт будет использовать наш API из браузеров своих посетителей. Защита:
- обязательный `Content-Type: application/json`, из-за которого кросс-доменный запрос требует preflight;
- отсутствие CORS-заголовков;
- проверка `Origin` и `Sec-Fetch-Site`.

### 8.7 MIME spoofing

`Content-Type` ответа определяется выбранным вариантом и проверкой ffprobe, а не метаданными и не расширением от площадки. Плюс `X-Content-Type-Options: nosniff` и `Content-Disposition: attachment`.

### 8.8 DoS и исчерпание ресурсов

Лимиты описаны в разделе 9. Уровни защиты: Nginx `limit_req` и `limit_conn`, лимиты на IP в приложении, глобальные семафоры, ограничение очереди, systemd-лимиты воркера (`MemoryMax=600M`, `CPUQuota=90%`, `TasksMax=64`, `Nice=10`, `IOSchedulingClass=idle`), квота хранилища и резерв свободного места.

### 8.9 Прочее

- Nginx отдаёт только `public/`. `location ~ /\.` запрещён, `.env` лежит вне корня (`shared/.env`, права `0640 root:cliphunter`).
- Заголовки: HSTS, `Referrer-Policy: no-referrer`, `Permissions-Policy`, CSP, `X-Frame-Options: DENY`.
- Стек-трейсы и пути не попадают в ответы: `APP_DEBUG=false` в prod, единый `ErrorHandler`.
- IP в логах и счётчиках хранится как `HMAC-SHA256(APP_SECRET, ip)`, укороченный до 16 hex-символов.
- Обход DRM, paywall и авторизации не делается: без cookies, без логинов, `--allow-unplayable-formats` не используется. Если yt-dlp сообщает о DRM, ошибка `VIDEO_UNAVAILABLE`.

---

## 9. Resource Limits (значения по умолчанию для сервера 1 vCPU / 1 ГБ / 10 ГБ)

| Переменная | Default | Комментарий |
|---|---|---|
| `MAX_FILE_SIZE_MB` | 1024 | Итоговый файл |
| `MAX_VIDEO_DURATION_SEC` | 7200 | 2 часа |
| `MAX_VIDEO_HEIGHT` | 2160 | 4K разрешаем, но лимит по размеру срежет тяжёлые варианты |
| `ANALYZE_TIMEOUT_SEC` | 30 | |
| `DOWNLOAD_TIMEOUT_SEC` | 900 | 15 минут на задание |
| `MAX_CONCURRENT_ANALYZE` | 2 | Анализ yt-dlp занимает 100–150 МБ RAM |
| `MAX_CONCURRENT_DOWNLOADS` | 1 | 1 vCPU; можно поднять до 2 после нагрузочного теста |
| `MAX_QUEUE_LENGTH` | 10 | |
| `MAX_ACTIVE_JOBS_PER_IP` | 1 | |
| `RATE_LIMIT_ANALYZE` | `20/600` | Запросов за окно в секундах |
| `RATE_LIMIT_DOWNLOADS` | `10/3600` | |
| `STORAGE_QUOTA_MB` | 4096 | `tmp` + `downloads` |
| `MIN_FREE_DISK_MB` | 1536 | Резерв для ОС и логов, задания не принимаются при меньшем остатке |
| `FILE_RETENTION_MIN` | 30 | Через сколько удаляется готовый файл |
| `ANALYSIS_TTL_MIN` | 30 | |
| `JOB_TTL_HOURS` | 24 | Через сколько удаляется сам job-файл |

PHP-FPM: `pm=ondemand`, `pm.max_children=4`, `memory_limit=128M`, `request_terminate_timeout=45s`. Swap 2 ГБ (`vm.swappiness=10`) как страховка от OOM.

---

## 10. Storage Architecture

```text
/var/www/cliphunter/shared/storage/        (owner cliphunter:cliphunter, 0750)
├── analyses/<analysisId>.json             результат анализа: нормализованный URL, экстрактор, варианты; TTL 30 мин
├── jobs/
│   ├── queue/<ts>-<jobId>                 маркер в очереди; воркер забирает задание атомарным rename в running/
│   ├── running/<jobId>
│   └── <jobId>.json                       состояние задания (атомарная запись: tmp-файл + rename)
├── tmp/<jobId>/                           рабочий каталог yt-dlp; удаляется по завершении или ошибке
├── downloads/<jobId>/media.<ext>          готовые файлы (group www-data, 0750/0640: Nginx читает через X-Accel)
├── ratelimit/<bucket>/<ipHash>.json       счётчики fixed window (flock)
├── locks/                                 семафоры анализа и скачиваний, lock воркера
├── cache/yt-dlp/                          кэш yt-dlp (сигнатуры YouTube)
└── logs/app.log                           JSON-логи (logrotate: ежедневно, 14 дней)
```

Очистка (`bin/cleanup.php`, systemd timer каждые 5 минут, плюс при старте воркера):
- `downloads/*` старше `FILE_RETENTION_MIN`, после этого задание получает статус `expired`;
- `tmp/*` без живого задания или старше `DOWNLOAD_TIMEOUT + 5 мин`;
- `analyses/*`, `jobs/*.json` и `ratelimit/*` с истёкшим TTL;
- зависшие `running/*` (heartbeat воркера старше 2 минут) переводятся в `failed`;
- каждое удаление логируется (`event=cleanup`, количество и объём).

`umask 0027` для PHP-FPM и воркера.

---

## 11. Database Decision

**MVP работает без базы данных.** Обоснование:
- состояние короткоживущее (минуты или часы), объём крошечный (десятки заданий);
- то, что дала бы БД, закрывается ФС: атомарный захват задания через `rename()`, согласованность через `flock`, TTL через очистку;
- меньше движущихся частей на 1 ГБ RAM.

Хранилища спрятаны за интерфейсами `JobRepository`, `AnalysisRepository` и `RateLimiterStore`. Если понадобится несколько воркеров на разных машинах или аналитика, их реализацию можно заменить на SQLite (одна машина) или Redis (несколько машин) без изменения сервисов.

---

## 12. Frontend Architecture

```text
public/
├── index.html                 лендинг + приложение (семантическая разметка: header/main/section/footer)
├── terms.html  privacy.html  abuse.html  404.html
├── assets/
│   ├── css/
│   │   ├── tokens.css         дизайн-токены (раздел 13)
│   │   ├── base.css           reset, типографика, утилиты, фокус, reduced-motion
│   │   ├── components.css     кнопки, поле, бейдж, карточки, радиоплитки, прогресс, алерты
│   │   └── layout.css         header, hero, секции, футер, сетки и брейкпоинты
│   ├── js/
│   │   ├── main.js            инициализация, связывание формы
│   │   ├── api.js             fetch-обёртка, таймауты (AbortController), разбор формата ошибок
│   │   ├── state.js           конечный автомат состояний (раздел 4) и единственная точка смены состояния
│   │   ├── views/             render-функции: analyzing.js, result.js, download.js, error.js
│   │   └── messages.js        тексты ошибок по коду на русском
│   ├── fonts/manrope-*.woff2
│   └── img/                   логотип (SVG), иконки платформ (SVG, simple-icons, CC0), иллюстрация hero (SVG)
└── index.php                  фронт-контроллер ТОЛЬКО для /api/*
```

- Прогрессивное улучшение: без JS форма показывает сообщение «Для работы нужен JavaScript» (`<noscript>`).
- Поллинг статуса раз в секунду с экспоненциальной задержкой при ошибках сети. Вкладка в фоне опрашивает раз в 3 секунды (`visibilitychange`).
- Готовый файл скачивается через `<a href="/api/downloads/<id>/file">`, так как `Content-Disposition: attachment` задаёт сервер. Blob в JS не используется: файлы большие.
- Кэш: CSS и JS подключаются с `?v=<git-sha>`, версию подставляет деплой-скрипт. Nginx отдаёт `Cache-Control: max-age=31536000, immutable` для версионированных ассетов и шрифтов и `no-cache` для HTML.
- Доступность: выбор формата сделан как `fieldset`/`legend` с нативными `radio`, видимый `:focus-visible`, контраст не ниже 4.5:1, `prefers-reduced-motion` отключает анимации, skip-link, `lang="ru"`.

---

## 13. Design System (по `design-reference.png`)

### Анализ референса

- **Структура:** header (логотип и 3 якорные ссылки) → hero в две колонки (бейдж, крупный H1 в 2 строки, подзаголовок, поле ввода с CTA внутри, 3 галочки-преимущества; справа иллюстрация «окно браузера с видео и круглой кнопкой загрузки») → 4 карточки преимуществ с иконками в мягких квадратах → «Поддерживаемые платформы» (надзаголовок капсом, H2 по центру, сетка из 7 логотипов, последний «И другие») → тёмный блок «3 простых шага» с нумерованным списком и иллюстрацией → футер в одну строку.
- **Визуальный язык:** светлый холодный фон, очень много воздуха, один акцентный цвет (индиго / сине-фиолетовый), мягкие тени, крупные радиусы, геометрический гротеск с плотным жирным начертанием, иконки в тонких контурах.
- **Иерархия:** H1 крупнее всего остального примерно в 2 раза, CTA — единственный насыщенный элемент над сгибом.

### Что меняем осознанно

- **Тексты должны быть правдивыми.** «Мы не храним ваши ссылки» неверно (технические логи есть) → «Файлы удаляются через 30 минут, регистрация не нужна». «Скачивание занимает несколько секунд» → «Быстрая обработка без лишних шагов».
- Добавляем короткий **FAQ** (5–6 вопросов, включая правовой) и состояния приложения прямо в hero: карточка результата появляется под полем ввода.
- В тёмном блоке шаги приводим в соответствие с реальным флоу: «Вставь ссылку → Выбери качество → Скачай файл».
- В футере добавляем ссылки «Условия», «Конфиденциальность», «Жалобы».
- Логотипы платформ используются только как указание на совместимость (номинативно) и выводятся монохромно-цветными SVG в одном размере.

### Токены (начальные значения, сверяются по контрасту при реализации)

| Токен | Значение | Использование |
|---|---|---|
| `--color-bg` | `#F7F8FC` | Фон страницы |
| `--color-bg-alt` | `#FFFFFF` | Чередующиеся секции |
| `--color-surface` | `#FFFFFF` | Карточки, поле ввода |
| `--color-surface-dark` | `#1B2030` | Блок «3 шага» |
| `--color-primary` | `#5B5CF0` | CTA, акценты, ссылки |
| `--color-primary-hover` | `#4A4BDB` | |
| `--color-primary-soft` | `#ECECFE` | Бейджи, фон иконок |
| `--color-secondary` | `#E9ECF4` | Вторичные кнопки, плитки |
| `--color-text` | `#141A2B` | Заголовки, основной текст |
| `--color-text-muted` | `#5A6275` | Подписи (≥ 4.5:1 на фоне) |
| `--color-border` | `#E3E6EF` | |
| `--color-success` | `#1F9D63` | Галочки, «Файл готов» |
| `--color-warning` | `#B7791F` | |
| `--color-error` | `#D64545` | |
| `--radius-sm/md/lg/xl` | `8 / 12 / 16 / 24px` | Плитки / поле / карточки / тёмный блок |
| `--shadow-sm` | `0 1px 2px rgb(20 26 43 / .06)` | |
| `--shadow-md` | `0 8px 24px rgb(20 26 43 / .08)` | Поле ввода, карточка результата |
| `--shadow-lg` | `0 24px 60px rgb(20 26 43 / .12)` | Иллюстрация hero |
| `--space-*` | шкала 4px: `4, 8, 12, 16, 24, 32, 48, 64, 96, 128` | |
| `--font-sans` | `"Manrope", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif` | |
| Типографика | H1 `clamp(2.25rem, 5vw, 3.5rem)/1.1/800`, H2 `clamp(1.75rem, 3.5vw, 2.5rem)/1.2/800`, H3 `1.125rem/600`, body `1rem/1.6`, small `.875rem` | |
| `--container` | `1120px` (+ боковые поля `clamp(16px, 4vw, 32px)`) | |
| `--container-narrow` | `720px` | Карточка результата, FAQ |

Брейкпоинты: `≤ 640px` — hero в одну колонку, поле и CTA друг под другом с CTA на всю ширину, иллюстрация скрыта или уменьшена, навигация в меню-кнопке; `641–960px` — 2 колонки преимуществ; `≥ 961px` — как в макете.

---

## 14. Project Structure

```text
ClipHunter/
├── public/                     единственный web root (раздел 12)
├── src/
│   ├── Kernel.php              PSR-7 запрос → middleware → роутер → ответ
│   ├── Container.php           ручной DI-контейнер
│   ├── Config/AppConfig.php    типизированный конфиг из env с валидацией при старте
│   ├── Http/
│   │   ├── Controller/         AnalyzeController, DownloadController, HealthController
│   │   ├── Middleware/         RequestId, OriginGuard, JsonBody, RateLimit, ErrorHandler, SecurityHeaders
│   │   ├── JsonResponder.php
│   │   └── ClientIp.php
│   ├── Domain/                 DTO и value objects: ValidatedUrl, MediaInfo, DownloadOption, DownloadJob, JobStatus (enum), ErrorCode (enum)
│   ├── Exception/              ClipHunterException (базовое) → ValidationException, SourceException, LimitException, ProcessFailedException…
│   ├── Security/               UrlValidator, HostResolver, IpRangeChecker, FilenameSanitizer, IpHasher
│   ├── Media/                  YtDlpClient, YtDlpErrorClassifier, MetadataSanitizer, OptionBuilder, FormatArgsMap, MediaProbe
│   ├── Process/                ProcessRunner, ProcessResult, CancellationToken
│   ├── Job/                    JobRepository (interface), FilesystemJobRepository, AnalysisRepository, Worker, JobRunner
│   ├── RateLimit/              RateLimiter, FilesystemRateLimiterStore, Semaphore
│   ├── Storage/                StoragePaths, StorageGuard (квоты, свободное место), Cleaner
│   └── Logging/LoggerFactory.php
├── config/
│   ├── platforms.php           allowlist доменов → экстракторы → отображаемое имя
│   └── formats.php             optionId → аргументы yt-dlp
├── bin/
│   ├── worker.php              цикл воркера (systemd)
│   ├── cleanup.php             очистка (systemd timer)
│   └── smoke.php               проверка анализа на эталонном URL
├── deploy/
│   ├── nginx/cliphunter.conf
│   ├── php-fpm/cliphunter.conf
│   ├── systemd/                cliphunter-worker.service, cliphunter-cleanup.{service,timer}, cliphunter-ytdlp-update.{service,timer}
│   ├── nftables/cliphunter.nft
│   ├── logrotate/cliphunter
│   ├── provision.sh            установка пакетов и пользователей на чистый Debian 12 (идемпотентно)
│   └── deploy.sh               деплой релиза по git SHA + откат
├── tests/
│   ├── Unit/  Integration/  Api/  Security/
│   ├── fixtures/yt-dlp/        JSON-фикстуры площадок, включая вредоносные метаданные
│   └── bin/fake-yt-dlp         фейковый бинарник: сценарии ok / ошибка / таймаут / огромный вывод / прогресс
├── docs/ARCHITECTURE.md
├── storage/                    в dev; в .gitignore всё, кроме .gitkeep
├── .github/workflows/ci.yml
├── composer.json  phpunit.xml  phpstan.neon  .php-cs-fixer.php
├── .env.example  .gitignore  .editorconfig  README.md
└── CLAUDE.md  design-reference.png
```

---

## 15. Configuration (`.env.example`)

```dotenv
APP_ENV=production            # production | development | testing
APP_DEBUG=false
APP_URL=https://<subdomain>.duckdns.org
APP_SECRET=                   # 32+ случайных байт в hex; генерируется на сервере, НЕ коммитится
TRUSTED_PROXIES=              # пусто: Nginx на той же машине, IP клиента берём из REMOTE_ADDR

YTDLP_PATH=/opt/yt-dlp/venv/bin/yt-dlp
FFMPEG_PATH=/usr/bin/ffmpeg
FFPROBE_PATH=/usr/bin/ffprobe
STORAGE_PATH=/var/www/cliphunter/shared/storage

MAX_FILE_SIZE_MB=1024
MAX_VIDEO_DURATION_SEC=7200
MAX_VIDEO_HEIGHT=2160
ANALYZE_TIMEOUT_SEC=30
DOWNLOAD_TIMEOUT_SEC=900
MAX_CONCURRENT_ANALYZE=2
MAX_CONCURRENT_DOWNLOADS=1
MAX_QUEUE_LENGTH=10
MAX_ACTIVE_JOBS_PER_IP=1
RATE_LIMIT_ANALYZE=20/600
RATE_LIMIT_DOWNLOADS=10/3600
STORAGE_QUOTA_MB=4096
MIN_FREE_DISK_MB=1536
FILE_RETENTION_MIN=30
ANALYSIS_TTL_MIN=30
JOB_TTL_HOURS=24

LOG_LEVEL=info
```

`AppConfig` валидирует всё при старте: типы, диапазоны, существование бинарников. Ошибка конфигурации приводит к падению при запуске, а не в середине запроса.

---

## 16. Logging

- Monolog, JSON-строки в `storage/logs/app.log`. Воркер дополнительно пишет в journald (stdout).
- Общие поля: `ts`, `level`, `event`, `request_id`, `job_id`, `ip_hash`, `duration_ms`.
- События: `http.request` (метод, путь без query, статус, длительность), `analyze.start/finish/fail` (`url_host`, `url_hash`, экстрактор, длительность, exit code, код ошибки, хвост stderr до 2 КБ на уровне debug или warning), `job.queued/started/progress?/completed/failed/cancelled`, `process.exec` (бинарник, exit code, длительность, был ли таймаут; argv **без URL**), `cleanup.run`, `limit.hit`.
- Не логируются: полный URL видео (только хост и хэш), сырые IP, секреты, заголовки `Cookie` и `Authorization`.
- logrotate: ежедневно, хранение 14 дней, сжатие. Логи Nginx: стандартные, `access_log` в формате с `$request_id`.

---

## 17. Testing Strategy

| Уровень | Инструмент | Что проверяем |
|---|---|---|
| Unit | PHPUnit | `UrlValidator` (большая матрица: malformed, неподдерживаемые схемы и хосты, userinfo, порты, IP-литералы в десятичной, hex и octal записи, IPv6, IDN-омографы, trailing dot); `IpRangeChecker` (все приватные и зарезервированные диапазоны v4/v6); `MetadataSanitizer` (вредоносные title с `../`, `<script>`, bidi, NUL, гигантские строки, неверные типы); `OptionBuilder`; `FilenameSanitizer`; `YtDlpErrorClassifier`; `RateLimiter`; `AppConfig` |
| Integration | PHPUnit + `tests/bin/fake-yt-dlp` | `YtDlpClient`: успех, exit≠0, таймаут (kill группы, нет зомби), огромный stdout, мусорный JSON, прогресс; `JobRepository`: атомарность, гонка двух воркеров за одно задание; `Worker`: отмена, превышение размера, нет места; `Cleaner`: удаляет просроченное и не трогает живое; `MediaProbe` на реальных маленьких сгенерированных файлах (ffmpeg `testsrc`) |
| API | PHPUnit, Kernel в процессе (PSR-7) | Схемы ответов и коды статусов всех эндпоинтов, единый формат ошибок, 415/403/429, `X-Request-Id`, отсутствие стек-трейсов и путей в ответах |
| Security | PHPUnit (отдельный suite) | SSRF-матрица end-to-end через `/api/analyze`, path traversal в `jobId`, argument injection (`--exec` в URL), XSS-метаданные проходят санитизацию, Origin-guard |
| Real yt-dlp | `bin/smoke.php`, тесты с `@group network` | Анализ эталонных роликов YouTube и Reddit, скачивание короткого ролика с merge и MP3. В CI и на сервере, не в каждом локальном прогоне. |
| Frontend | ручной чек-лист + Lighthouse | Все 6 состояний, клавиатурная навигация, ширина 320px без горизонтального скролла, reduced motion, контраст |
| Static | PHPStan max, PHP-CS-Fixer PSR-12, `composer audit` | В CI на каждый push |

---

## 18. Deployment

### Целевая раскладка на сервере

```text
/var/www/cliphunter/
├── repo.git/                 bare-клон из GitHub (deploy key только на чтение)
├── releases/<git-sha>/       git worktree + vendor (composer install --no-dev -o)
├── current -> releases/<sha> атомарное переключение симлинка
└── shared/
    ├── .env                  0640 root:cliphunter
    └── storage/              раздел 10
```

### Provisioning (один раз, `deploy/provision.sh`, идемпотентно)

1. Swap 2 ГБ, `vm.swappiness=10`; `unattended-upgrades`.
2. Пакеты: `nginx`, `git`, `ffmpeg`, `python3-venv`, `certbot`, `python3-certbot-nginx`, `nftables`, `logrotate`; PHP 8.4 из sury.org (`php8.4-fpm`, `-cli`, `-intl`, `-mbstring`, `-xml`, `-curl`, `-opcache`); Composer (с проверкой подписи установщика); deno; yt-dlp в `/opt/yt-dlp/venv`.
3. Системный пользователь `cliphunter` (без shell). Пул PHP-FPM и воркер работают от него; `www-data` входит в группу для чтения `downloads/`.
4. Deploy key на сервере (`ssh-keygen`), публичная часть добавляется в репозиторий как read-only deploy key.
5. Nginx: vhost, `limit_req` и `limit_conn`, internal `/_files/`, заголовки безопасности; certbot для HTTPS, редирект 80 → 443, автопродление.
6. systemd: worker, cleanup timer, ytdlp-update timer.
7. **nftables** (только после подтверждения): input разрешает 22, 80, 443 и established, остальное drop; egress-фильтр для uid `cliphunter`. Правила применяются с автоматическим откатом через 2 минуты, если SSH-доступ пропал (`nft -f` + таймер отката).

### Деплой релиза (`deploy/deploy.sh <sha>`)

```text
git fetch → worktree releases/<sha> → composer install --no-dev → симлинк shared/.env и storage
→ php bin/smoke.php (конфиг, бинарники, права) → подстановка ?v=<sha> в HTML
→ ln -sfn releases/<sha> current → reload php8.4-fpm → restart cliphunter-worker
→ health-check /api/health и реальный анализ → если ошибка, автоматический откат на предыдущий релиз
```

Хранятся 3 последних релиза. Каждый релиз соответствует коммиту на GitHub, откат делается командой `deploy.sh --rollback`.

### DuckDNS

IP сервера статический, поэтому достаточно один раз указать `193.233.198.66` для поддомена в панели duckdns.org. Скрипт-обновлятор с токеном на сервере не нужен, и токен вообще не попадает ни в репозиторий, ни на сервер.

### Проверка после деплоя (раздел 42 `CLAUDE.md`)

HTTP → HTTPS-редирект и сертификат; главная страница и ассеты; `/api/health`; анализ YouTube-ролика; скачивание с merge (видео + аудио → mp4); MP3; файл отдаётся и удаляется по TTL; `.env`, `/src`, `/storage` и `/vendor` недоступны по HTTP (ожидается 404); логи приложения, Nginx и PHP-FPM без ошибок.

---

## 19. Docker Decision

**Native Linux, без Docker.**

| | Native | Docker Compose |
|---|---|---|
| RAM на 1 ГБ-сервере | ничего лишнего | демон dockerd + образы, около 100–200 МБ |
| Сложность | systemd, nginx, fpm — стандартные для Debian | плюс образ, тома, сети, проброс бинарников |
| Обновление yt-dlp | `pip -U` в venv | пересборка образа или exec в контейнере |
| Воспроизводимость | `provision.sh` + `deploy.sh` | лучше |

Сервер один, приложение одно, ресурсов мало. Docker добавляет потребление и сложность, но не решает ни одной текущей проблемы. Воспроизводимость обеспечивают идемпотентный `provision.sh` и CI на Linux.

---

## 20. MVP Scope

Входит: лендинг по референсу (hero, преимущества, платформы, 3 шага, FAQ, футер), страницы Terms, Privacy и Abuse, анализ URL с SSRF-защитой, превью и метаданные, выбор вариантов (видео MP4 360p–4K, аудио M4A и MP3), асинхронное скачивание с прогрессом и отменой, merge через ffmpeg, проверка ffprobe, выдача через X-Accel, автоочистка, лимиты на IP и глобальные, структурированные логи, тесты и CI, деплой с HTTPS и откатом.

Не входит: аккаунты, оплата, плейлисты, live-трансляции, cookies и логины на площадках, перекодирование видео, история загрузок, аналитика, многоязычность, админка.

---

## 21. Phase 2

- SSE вместо поллинга (если поллинг станет заметной нагрузкой).
- Несколько воркеров, `MAX_CONCURRENT_DOWNLOADS=2–3` после нагрузочного теста (при апгрейде сервера).
- Кэш одинаковых запросов: один и тот же URL и вариант в течение TTL не скачивается повторно.
- Обрезка фрагмента (`--download-sections`), выбор субтитров.
- Метрики (Prometheus-формат с localhost), алерты на сбои yt-dlp.
- Английская версия интерфейса.

## 22. Phase 3

- Отдельные download-ноды, очередь на Redis, общее хранилище (S3-совместимое) с presigned-ссылками.
- Telegram-бот и браузерное расширение поверх того же API.
- CDN для статики, мультирегиональность для гео-ограниченного контента (только легально доступного).

---

## 23. Implementation Roadmap

Каждый шаг заканчивается тестами, ревью, коммитом и push.

| # | Этап | Результат | Коммиты (пример) |
|---|---|---|---|
| 0 | Bootstrap | git init, `.gitignore`, `.editorconfig`, `composer.json`, инструменты качества, CI, README, этот документ; приватный репозиторий на GitHub | `chore: bootstrap project`, `ci: add github actions` |
| 1 | Ядро HTTP | `AppConfig`, `Container`, `Kernel`, роутер, `ErrorHandler`, JSON-ответы, логирование, `/api/health` | `feat: add http kernel and health endpoint` |
| 2 | URL и SSRF | `UrlValidator`, `IpRangeChecker`, `HostResolver`, allowlist платформ + security-тесты | `feat: add url validation with ssrf protection` |
| 3 | Анализ | `ProcessRunner`, `YtDlpClient::analyze`, классификатор ошибок, санитайзер, `OptionBuilder`, `/api/analyze`, rate limiting, семафор | `feat: add yt-dlp metadata extraction`, `feat: add rate limiting` |
| 4 | Скачивание | Job store, воркер, прогресс, отмена, `MediaProbe`, выдача файла, `Cleaner` | `feat: add download jobs and worker`, `feat: add file delivery`, `feat: add cleanup` |
| 5 | Фронтенд | Токены, лендинг по референсу, состояния, доступность, адаптив | `feat: add landing page`, `feat: add analyze and download flow ui` |
| 6 | Контент | FAQ, Terms, Privacy, Abuse, 404 | `docs: add legal pages` |
| 7 | Hardening | Security-ревью, CSP и заголовки, нагрузочный прогон, финальные тесты | `fix: …`, `test: …` |
| 8 | Provisioning | `provision.sh`, nginx, fpm, systemd, nftables (с подтверждением) | `ops: add server provisioning` |
| 9 | Деплой | `deploy.sh`, HTTPS, проверка полного флоу в prod, отчёт | `ops: add deploy script` |

---

## 24. Definition of Done

Совпадает с разделом 47 `CLAUDE.md`. Дополнительно:
- PHPStan max и PHP-CS-Fixer без ошибок, CI зелёный;
- все security-тесты из раздела 17 проходят;
- в production проходит полный флоу: анализ → видео с merge → MP3 → файл удалён по TTL;
- `.env`, `src`, `storage` и `vendor` недоступны по HTTP;
- откат на предыдущий релиз проверен хотя бы один раз.

---

## 25. Risks and Mitigations

| Риск | Вероятность | Митигация |
|---|---|---|
| YouTube блокирует IP датацентра («Sign in to confirm you're not a bot») | Средняя | Сейчас работает (проверено). Ежедневное обновление yt-dlp, deno, щадящие лимиты, понятное сообщение `SOURCE_TEMPORARILY_BLOCKED`. Cookies личного аккаунта **не используем**. |
| Instagram и часть TikTok требуют логин с серверных IP | Высокая | Это обход авторизации, его не делаем. Сообщение `LOGIN_REQUIRED`, на лендинге формулировка «где доступно». |
| RuTube отвечает 403 из Германии | Средняя | Проверить в этапе 3. Если не работает, убрать RuTube из блока платформ на лендинге. |
| Мало RAM и CPU (1 ГБ, 1 vCPU) | Высокая | 1 скачивание одновременно, очередь, swap, systemd MemoryMax, без перекодирования видео, `pm=ondemand` |
| Диск 10 ГБ | Средняя | Квота 4 ГБ, резерв 1.5 ГБ, TTL 30 минут, очистка каждые 5 минут |
| Площадка меняет API, yt-dlp ломается | Высокая | Автообновление со smoke-тестом и откатом, мониторинг `analyze.fail` в логах |
| Жалобы правообладателей хостеру | Средняя | Terms и Abuse policy с контактом, файлы не хранятся дольше 30 минут, каталога или публичных ссылок на файлы нет |
| Злоупотребление как бесплатный бэкенд или прокси | Средняя | Allowlist, Origin-guard, лимиты на IP, никакого проксирования произвольных URL |
| Lockout по SSH при настройке firewall | Низкая | Правила с автоматическим откатом через 2 минуты, порт 22 в allowlist, подтверждение владельца перед применением |
| Лимиты Let's Encrypt и сбои DuckDNS | Низкая | Сначала тест на staging-окружении certbot, статический IP без обновлятора |
| Локальная разработка на Windows не равна Linux | Средняя | CI на ubuntu для всех тестов, process-код через `symfony/process`, интеграционные тесты воркера в CI и на сервере |

---

## 26. Совместный просмотр

План и обоснования решений: [`WATCH_PARTY_PLAN.md`](WATCH_PARTY_PLAN.md). Здесь — что реализовано и чем реализация отличается от плана.

### Схема

```text
Браузер /watch, /watch/{roomId}  ──fetch──▶ Nginx /api/watch/* ──▶ PHP-FPM (WatchSourceService, WatchMediaService)
        │                                                              │ тикет (HMAC-SHA256, ROOMS_SECRET)
        └──WebSocket wss://…/ws/rooms──▶ Nginx ──▶ 127.0.0.1:8790 Deno (rooms/: Gateway → Connection → Room)
YouTube: iframe youtube-nocookie.com (сервер не участвует). Остальные площадки: воркер → downloads/<id>/media.mp4 → X-Accel.
```

- **PHP** (`src/Watch/`): `POST /api/watch/sources` разбирает YouTube-ссылки без yt-dlp (`YouTubeId`), остальное отдаёт в `AnalyzeService` и ставит задание `purpose=watch` с наибольшим вариантом ≤ `WATCH_MAX_HEIGHT`. Ответ содержит тикет (`MediaTicket`). `GET /api/watch/media/{id}` — статус подготовки, `/file` — файл inline (X-Accel или PHP с Range в dev). Watch-задания невидимы для `/api/downloads/*`; файлы хранятся `WATCH_FILE_RETENTION_MIN`.
- **Deno** (`rooms/`): `domain/` (Room, Registry, playback, text), `security/` (тикеты через WebCrypto, id и хэши токенов, rate limit), `persistence/snapshot.ts`, `protocol.ts`, `connection.ts`, `server.ts`, `main.ts`. Ноль внешних зависимостей, только Web API, `Deno.*` и `node:crypto`.
- **Фронтенд** (`public/watch.html`, `public/assets/js/watch/`): `session.js` (resume/join/create, переподключение), `room-client.js` (WS, reqId → Promise, backoff), `store.js` (чистый редьюсер), `clock.js` и `playback.js` (чистая математика), `sync.js` (SyncController), `players/` (HTML5 и YouTube-адаптеры), `views/`.

### Отклонения от плана и найденные факты

| Тема | Решение |
|---|---|
| `deno.json` | Один файл в корне, а не `rooms/deno.json`: фронтенд-модули из `public/assets/js/watch/` тестируются тем же Deno (`tests/js/`). На yt-dlp он не влияет: yt-dlp запускает deno с `--no-config`; production-юнит rooms тоже запускается с `--no-config`. |
| Лимит размера кадра | `Deno.upgradeWebSocket` (Deno 2.9.7) не ограничивает размер входящего сообщения (проверено: кадр 20 МБ принимается). Поэтому сервер сам закрывает соединение с 1009 при > 4096 байт (UTF-8), а от памяти защищают `MemoryMax=192M`, `--max-old-space-size=96`, лимиты соединений и `limit_conn` в Nginx. |
| `--allow-env=ROOMS_*` | Wildcard работает в Deno 2.9; чтение переменной вне списка даёт `NotCapable`. |
| faststart | yt-dlp 2026.08.19 добавляет `-movflags +faststart` ко всем выходам своих ffmpeg-постпроцессоров (merge, remux, fixup), поэтому отдельный флаг для watch-заданий не нужен. |
| Размер тикета | Тикет едет в WS-кадре ≤ 4096 байт: PHP выбрасывает из тикета превью длиннее 1024 символов. Худший случай (200 эмодзи в названии) проверен тестом. |
| Протокол | Добавлены код ошибки `NO_MEDIA` (команда воспроизведения до выбора видео), системное событие чата `kicked` и close-код **4002** (идентичность открыта в другой вкладке: старая вкладка не переподключается, иначе две вкладки выбивали бы друг друга). На каждый успешный запрос с `reqId` приходит `ack`. |
| Тексты системных сообщений | Настоящее время и формы без склонения имени («Маша присоединяется к просмотру», «Маша выходит из комнаты», «Ведущий удалил участника Маша», «Ведущий теперь — Маша»): пол по имени не угадываем, падеж имени не меняем. |
| Рендер | Комната перерисовывается дважды в секунду ради часов плеера. Пересборка списка участников на каждом тике останавливала видеодекодер Chrome (`DECODER_UNDERFLOW`, через 3–7 с после старта при двух участниках, воспроизводилось и в обычном окне). Представления чата и участников теперь рисуют только при изменении входных данных из неизменяемого стора. Слои плеера, которые обновляются поверх видео (чип, бейдж, центральная кнопка), не используют `backdrop-filter`. |
| Синхронизация (замер) | Два окна Chrome, файловый режим: расхождение после play / pause / seek 0.00–0.09 с, установившееся ±0.013 с на 13 с. YouTube: play/pause одного доходят до другого, название берётся из плеера. Нагрузка локально: 50 комнат × 5 клиентов, p95 рассылки 11 мс, без ошибок и разрывов. |

### Production

- Nginx: `location = /ws/rooms` (Upgrade, `X-Real-IP`, `limit_conn ch_ws 6`, таймауты 120 с), `^~ /api/watch/` с `limit_req ch_watch`, `/watch` и `/watch/{roomId}` → `watch.html` со сниппетом `cliphunter-watch-headers.conf` (CSP с YouTube и `wss://домен`, `Referrer-Policy: strict-origin-when-cross-origin` — без Referer YouTube-embed не играет, ошибка 153). В access-логе `roomId` заменяется на `/watch/:room`.
- Кэш ассетов: `Cache-Control` берётся из `map $arg_v` — с `?v=<sha>` год и `immutable`, без версии (вложенные ES-модули) `no-cache` с ревалидацией по ETag. Раньше вложенные модули кэшировались на год и после деплоя могли смешаться со старыми.
- `/_files/` больше не добавляет свой `Cache-Control`: заголовок задаёт PHP (`no-store` для скачиваний, `private, max-age=3600` для файлов комнат, которые `<video>` перечитывает Range-запросами).
- systemd `cliphunter-rooms.service`: пользователь `cliphunter`, Deno с `--allow-net=127.0.0.1:8790`, `--allow-env` только на нужные переменные, чтение и запись только `storage/rooms`; песочница как у воркера, `MemoryMax=192M`, `CPUQuota=50%`.
- `deploy.sh`: проверяет `ROOMS_SECRET`; до переключения — `deno check` и `--self-check` от `cliphunter`; после — `/healthz`, CSP страниц `/watch`, 404 для `rooms/main.ts`, `storage/rooms/state.json` и `deno.json`, WS-рукопожатие (101 / 403) и WS-smoke. Откат на релиз без `rooms/` останавливает сервис. Порядок первого выката описан в `README.md`.
