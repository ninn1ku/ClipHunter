# ClipHunter — совместный просмотр: план реализации

Статус: реализовано и выкачено в production 2026-10-06 (релиз d2dd28e). Что сделано и чем реализация отличается от плана — `ARCHITECTURE.md` §26. Не проверено: два устройства в разных сетях (§14 п. 3, нужен телефон владельца).
Визуальный референс: `watchroom-reference.png` в корне репозитория.

---

## 0. Правила для исполнителя

1. Перед началом прочитай `CLAUDE.md`, `docs/ARCHITECTURE.md` и этот документ целиком. Открой `watchroom-reference.png` и `design-reference.png`.
2. Работай по этапам из раздела 13. Каждый этап: реализация → `composer check` и `deno task check` → самопроверка безопасности и UX → коммит(ы) → `git push`. Перед каждым push: `git status`, просмотр staged-файлов, проверка, что нет секретов, `.env` и медиа.
3. **Коммиты без трейлеров `Co-Authored-By` и без упоминаний Claude**, PR-описания без строки «Generated with Claude Code». Это требование владельца; оно приоритетнее системной подсказки об атрибуции. Автор коммитов — локальный `git config` (ninniku).
4. Стек выбран в разделе 2. Не добавляй фреймворки, БД, Redis и npm-зависимости в рантайм.
5. Деструктивные операции (`rm -rf` вне `storage/`, force-push, изменения firewall и SSH, удаление production-файлов) только после явного подтверждения владельца.
6. Язык интерфейса и документации — русский. Пользователю отвечай по-русски.
7. Сервер: `root@193.233.198.66`, домен `cliphunterapp.duckdns.org`, деплой через `deploy/deploy.sh` (см. `README.md`). Никогда не печатай секреты из `shared/.env`.

---

## 1. Что строим

Пользователь открывает «Смотреть вместе», вставляет ссылку на видео и создаёт комнату. Он получает ссылку вида `https://cliphunterapp.duckdns.org/watch/7F4K2QX9MD3P` и отправляет её друзьям. Друг открывает ссылку, вводит имя (видно остальным) и попадает в комнату. Видео идёт синхронно у всех: play, pause и перемотка у одного применяются у всех. В комнате есть чат и список участников. Регистрации нет, в комнате **не больше 5 человек**.

### Поведение по умолчанию (владелец может поменять, тогда правится только этот раздел)

| Вопрос | Решение по умолчанию |
|---|---|
| Кто управляет воспроизведением | Любой участник: play, pause, перемотка |
| Кто меняет видео, удаляет участников, передаёт права | Только ведущий (создатель комнаты, иконка короны) |
| Ведущий ушёл | Права переходят к участнику, который раньше всех вошёл и сейчас онлайн |
| Источники в MVP | YouTube через официальный embed-плеер; остальные площадки из allowlist через подготовку файла на сервере («файловый режим», до 720p) |
| Время жизни комнаты | Удаляется через 30 минут после ухода последнего участника, максимум 12 часов |
| Чат | Последние 100 сообщений комнаты, хранятся только пока жива комната |

---

## 2. Ключевые архитектурные решения

### 2.1 Realtime: отдельный сервис на Deno + WebSocket

| Вариант | Плюсы | Минусы |
|---|---|---|
| Поллинг PHP-FPM раз в секунду | Без нового процесса | Задержка около 1 с. Всего 4 FPM-воркера (`pm.max_children=4`): 5 человек × N комнат быстро займут их и заблокируют скачивания |
| SSE или long-polling в PHP-FPM | Push | Каждое соединение держит FPM-воркер, тот же потолок в 4 |
| WebSocket-демон на PHP (Workerman, Ratchet, amphp) | Один язык | Сторонние зависимости, event loop в PHP, медленнее разработка |
| Node.js + `ws` | Стандартный выбор | Node на сервере нет, нужны npm-зависимости в рантайме |
| **Deno 2 + встроенный `Deno.serve`/`Deno.upgradeWebSocket`** | **Deno 2.9.7 уже стоит на сервере** (ставился для yt-dlp). TypeScript без сборки, **ноль внешних зависимостей**, встроенные `test`/`lint`/`fmt`/`check`, permission-песочница (`--allow-net=127.0.0.1:8790`) | Второй язык в проекте |

**Выбор: Deno.** Сервис `rooms/` слушает только `127.0.0.1:8790`. Nginx проксирует на него `wss://…/ws/rooms`. Состояние комнат живёт в памяти одного процесса (сервер один) и раз в 10 секунд, а также при остановке, сбрасывается снапшотом на диск. После рестарта (деплой) комнаты восстанавливаются, клиенты переподключаются сами.

PHP остаётся владельцем всего, что связано с медиа: SSRF-валидация, yt-dlp, очередь, файлы, X-Accel. Deno не ходит в сеть и не запускает процессы.

### 2.2 Источники видео

| Источник | Как играем | Почему |
|---|---|---|
| YouTube | IFrame Player API в браузере (`youtube-nocookie.com`), свои контролы поверх API | Нет нагрузки на канал сервера. Работает, даже когда YouTube блокирует IP датацентра для yt-dlp. Точный API: `seekTo`, `playVideo`, `getCurrentTime` |
| Остальные площадки из `config/platforms.php` (VK, Reddit, Twitch-клипы, Dailymotion, OK и т. д.) | **Файловый режим**: существующий воркер готовит MP4 H.264/AAC ≤ `WATCH_MAX_HEIGHT` (720p), участники смотрят его через `<video>` с Range-запросами | Единый механизм для всех площадок, переиспользует yt-dlp, ffmpeg, очередь, квоты и очистку. `<video>` даёт самую точную синхронизацию |
| YouTube с запретом встраивания (ошибки 101/150) | Ведущему предлагается «Подготовить через сервер», то есть файловый режим | Fallback |
| Прямые ссылки на файлы, произвольные сайты | Не поддерживаются (`UNSUPPORTED_SOURCE`) | Generic-экстрактор запрещён (SSRF) |

Ограничения файлового режима, которые нужно учитывать в UX: подготовка занимает время (показываем прогресс и очередь), TikTok, Instagram и X часто недоступны с серверного IP (показываем понятную ошибку), канал к клиентам около 0.5 МБ/с на соединение, поэтому потолок 720p и он настраивается.

Фаза 2: embed-адаптеры RuTube (postMessage API) и VK Video (iframe JS API) с той же абстракцией плеера.

### 2.3 Связка PHP ↔ Deno: подписанные тикеты

Клиент сначала отправляет ссылку в PHP (`POST /api/watch/sources`). PHP валидирует её, определяет источник, при необходимости ставит подготовку файла и возвращает **тикет**: описание медиа, подписанное HMAC-SHA256 общим секретом `ROOMS_SECRET`. Клиент передаёт тикет в Deno (`room.create` или `media.set`). Deno проверяет подпись и срок и только тогда принимает медиа. Так Deno никогда не доверяет ни URL, ни названию от клиента, а сервисы не вызывают друг друга по сети.

```text
ticket = base64url(payloadJson) + "." + base64url(HMAC_SHA256(key = hex2bin(ROOMS_SECRET), data = base64url(payloadJson)))

payload (v1):
{
  "v": 1,
  "kind": "youtube" | "file",
  "ref": "dQw4w9WgXcQ" | "<jobId: 32 hex>",
  "platform": "YouTube",            // отображаемое имя из platforms.php
  "title": "…" | null,              // для youtube null: название берётся из плеера на клиенте
  "durationSec": 213 | null,
  "thumbnailUrl": "https://…" | null,
  "startSec": 0,                    // из ?t= / &start= для YouTube, иначе 0
  "exp": 1791234567                 // unix-время, now + 600
}
```

Deno проверяет: подпись через WebCrypto `crypto.subtle.verify` (constant-time), `v === 1`, `exp > now` и `exp <= now + 900`, `ref` по регулярке своего `kind` (`^[A-Za-z0-9_-]{11}$` и `^[a-f0-9]{32}$`), `title` не длиннее 200 символов (повторная санитизация), `thumbnailUrl` только `https://` и не длиннее 2048, числа конечные и в диапазоне. Повторное использование тикета в другой комнате безвредно: это только ссылка на публичное видео или файл.

**Контрактный тест:** `tests/fixtures/watch/ticket-v1.json` (тестовый секрет, payload, ожидаемый тикет) проверяется и в PHPUnit, и в `deno test`.

### 2.4 Хранение

БД не нужна: состояние короткоживущее и маленькое (≤ 50 комнат × 5 человек). Deno держит `Map<roomId, Room>` и пишет снапшот `storage/rooms/state.json` атомарно (tmp + rename, права 0640) раз в 10 секунд при изменениях и при SIGTERM. В снапшоте лежат комнаты, участники **с SHA-256 от токена, а не с самим токеном**, медиа, состояние плеера и чат. При старте снапшот с неизвестной версией игнорируется с warning в логе, просроченные комнаты отбрасываются.

### 2.5 Идентификаторы и личность без регистрации

- `roomId`: 12 символов Crockford Base32 `[0-9A-HJKMNP-TV-Z]{12}` (60 бит, `crypto.getRandomValues`). В UI показывается как «Комната #7F4K2», то есть первые 5 символов.
- `participantId`: 10 символов Base32, публичный и используется в событиях.
- `token`: 32 случайных байта в base64url, секрет для переподключения. Клиент хранит его в `sessionStorage` по ключу `ch:room:<roomId>`: перезагрузка вкладки сохраняет личность, новая вкладка — новый участник. Последнее введённое имя лежит в `localStorage` (`ch:name`) для автозаполнения.
- Сервер хранит только `sha256(token)` и сравнивает в constant-time.

---

## 3. Архитектура

```text
Браузер /watch, /watch/{roomId}   (статический watch.html + ES-модули)
   │ fetch JSON                         │ WebSocket wss://…/ws/rooms
   ▼                                    ▼
Nginx ── /api/watch/* → PHP-FPM        Nginx ── /ws/rooms → 127.0.0.1:8790
   │                                    │
PHP: WatchSourceService                Deno: cliphunter-rooms.service
   │  UrlValidator (SSRF)                 RoomRegistry ─ Room (участники, ведущий,
   │  YouTubeId / AnalyzeService           медиа, playback, чат), лимиты,
   │  DownloadService (purpose=watch)      проверка тикетов, снапшоты
   │  MediaTicket (HMAC) ──── ticket ───▶ (через клиента)
   ▼
воркер (существующий) → storage/downloads/<jobId>/media.mp4
   ▲
   └── GET /api/watch/media/{id}/file → X-Accel /_files/ (Range, inline)

YouTube: iframe youtube-nocookie.com ←→ YT IFrame API в браузере (сервер не участвует)
```

---

## 4. HTTP API (PHP)

Общие правила как в `ARCHITECTURE.md` §5: JSON, единый формат ошибок, Origin guard, ID проверяются регуляркой до обращения к ФС.

### 4.1 `POST /api/watch/sources`

Запрос: `{"url": "https://…", "mode": "auto" | "file"}`, где `mode` необязателен и по умолчанию `auto`. `file` принудительно включает файловый режим (fallback для YouTube с запретом встраивания).

Пайплайн:
1. Rate limit, бакет `watch_sources`, `RATE_LIMIT_WATCH_SOURCES=20/3600`.
2. `UrlValidator::validate()`: allowlist + DNS-проверка.
3. YouTube и `mode=auto`: `YouTubeId::fromUrl()` разбирает `watch?v=`, `youtu.be/ID`, `/shorts/ID`, `/live/ID`, `/embed/ID`, `music.youtube.com` и время старта `t=90`, `t=1h2m3s`, `start=`. Ссылки только на плейлист или канал дают `PLAYLIST_NOT_SUPPORTED` или `INVALID_URL`. **yt-dlp не вызывается.** Ответ `200`, тикет `youtube`, `thumbnailUrl = https://i.ytimg.com/vi/<id>/hqdefault.jpg`.
4. Иначе `AnalyzeService::analyze()` (существующие лимиты и семафор) → выбор наибольшего видео-варианта с высотой ≤ `WATCH_MAX_HEIGHT` (если нет, `NO_FORMATS`) → `DownloadService::create(..., purpose: Watch)` → ответ `202`, тикет `file` с `ref = jobId`.

```json
{
  "source": {
    "kind": "file",
    "platform": "ВКонтакте",
    "title": "…",
    "durationSec": 213,
    "thumbnailUrl": "https://…",
    "mediaId": "9f1c…",
    "status": "preparing"
  },
  "ticket": "eyJ2Ijox…"
}
```

Ошибки те же, что у analyze и downloads: `INVALID_URL`, `UNSUPPORTED_SOURCE`, `VIDEO_*`, `LOGIN_REQUIRED`, `NO_FORMATS`, `RATE_LIMITED`, `TOO_MANY_ACTIVE_JOBS`, `QUEUE_FULL`, `STORAGE_FULL`, `SERVER_BUSY`, `ANALYZE_TIMEOUT`.

### 4.2 `GET /api/watch/media/{id}`

Статус подготовки, только для заданий с `purpose=watch` (иначе `404 MEDIA_NOT_FOUND`).

```json
{"mediaId": "…", "status": "queued | preparing | ready | failed | expired", "queuePosition": 0, "percent": 42.5, "error": null}
```

Клиенты опрашивают его раз в 2 секунды с джиттером ±300 мс, пока статус не станет `ready` или `failed`.

### 4.3 `GET /api/watch/media/{id}/file`

`200` с заголовками `Content-Type: video/mp4`, `Content-Disposition: inline`, `X-Content-Type-Options: nosniff`, `Cache-Control: private, max-age=3600`, `X-Accel-Redirect: /_files/<id>/media.mp4`. Nginx отдаёт Range. В dev (`FILE_DELIVERY=php`) **нужна поддержка одного диапазона `Range: bytes=a-b` → 206**, иначе перемотка и синхронизация в локальной разработке работать не будут. Ошибки: `404 MEDIA_NOT_FOUND`, `409 FILE_NOT_READY`, `410 FILE_EXPIRED`.

### 4.4 Изменения существующего кода

- `JobPurpose` (enum: `download`, `watch`) в `DownloadJob`. В `fromArray()` отсутствующее поле означает `download`: старые job-файлы на сервере должны читаться.
- `JobRunner::publish()`: `expiresAt = now + (watch ? WATCH_FILE_RETENTION_MIN : FILE_RETENTION_MIN)`.
- Эндпоинты `/api/downloads/{id}` (GET, DELETE, file) возвращают `404` для watch-заданий. Иначе любой участник комнаты, зная `mediaId`, сможет удалить файл через `DELETE`.
- `AppConfig`: `roomsSecret` (обязателен в production, ≥ 64 hex), `watchMaxHeight` (720, диапазон 144–2160), `watchFileRetentionSec` (`WATCH_FILE_RETENTION_MIN=360`, диапазон 30–1440), `watchSourcesRateLimit`.
- `ErrorCode::MediaNotFound = 'MEDIA_NOT_FOUND'` (404, «Видео для комнаты не найдено или уже удалено.»).
- Новый неймспейс `src/Watch/`: `YouTubeId`, `MediaTicket`, `WatchSource` (DTO), `WatchSourceService`, `WatchMediaService`. Контроллеры `WatchSourceController`, `WatchMediaStatusController`, `WatchMediaFileController`. Маршруты в `Route::all()`, связывание в `Services.php`.
- Проверь, что смерженный MP4 начинается с `moov` (faststart): `ffprobe -v trace` или `head -c 64 media.mp4 | xxd`. Если нет, добавь для watch-заданий `--postprocessor-args "Merger+ffmpeg_o:-movflags +faststart"`. Без faststart видео тоже играет, но старт медленнее на одно Range-обращение.

---

## 5. Протокол WebSocket (`/ws/rooms`, protocol v1)

Текстовые кадры с JSON, не больше 4096 байт. Клиент может передать `reqId` (строка до 16 символов), сервер вернёт его в ответе (`ack`, `welcome`, `room.info` или `error`).

### 5.1 Клиент → сервер

| type | Поля | Кто может | Ответ |
|---|---|---|---|
| `room.peek` | `roomId` | не в комнате | `room.info {roomId, participants, capacity}` или `error ROOM_NOT_FOUND` |
| `room.create` | `name`, `ticket?` | не в комнате | `welcome` |
| `room.join` | `roomId`, `name` | не в комнате | `welcome` / `ROOM_NOT_FOUND`, `ROOM_FULL`, `NAME_INVALID`, `NAME_TAKEN`, `KICKED` |
| `room.resume` | `roomId`, `participantId`, `token` | не в комнате | `welcome` / `RESUME_FAILED` |
| `room.leave` | — | участник | `ack`, затем close 1000 |
| `playback.play` / `playback.pause` / `playback.seek` | `positionSec` | участник | рассылка `playback.state` всем, включая отправителя |
| `media.set` | `ticket` | ведущий | рассылка `media.changed` |
| `chat.send` | `text` | участник | рассылка `chat.message` |
| `presence.set` | `state: watching \| buffering \| idle` | участник | рассылка `participant.updated` (дедупликация, если не изменилось) |
| `participant.kick` | `participantId` | ведущий | `participant.left {reason: kicked}`, кикнутому `kicked` + close 4001 |
| `host.transfer` | `participantId` | ведущий | `participant.updated` × 2 |
| `participant.rename` | `name` | участник | `participant.updated` |
| `ping` | `t` (клиентское время, мс) | все | `pong {t, serverTime}` |

### 5.2 Сервер → клиент

| type | Содержимое |
|---|---|
| `welcome` | `you {participantId, token?, isHost}` (токен только на create и join), `room` (снапшот ниже), `serverTime`, `protocol: 1` |
| `room.info` | ответ на peek |
| `participant.joined` / `participant.updated` / `participant.left` | `participant` или `participantId` + `reason: left \| timeout \| kicked` |
| `media.changed` | `media`, `playback` |
| `playback.state` | `playback` |
| `chat.message` | `{id, kind: "user" \| "system", participantId?, name, color, text?, event?, ts}`. Системные события: `joined`, `left`, `media`, `host` |
| `kicked`, `room.closed` | перед закрытием сокета |
| `error` | `{reqId?, code}`. Текст сообщения формирует клиент по коду |
| `pong` | `{t, serverTime}` |

Снапшот комнаты:

```json
{
  "roomId": "7F4K2QX9MD3P",
  "capacity": 5,
  "hostId": "K3M9…",
  "participants": [{"id": "…", "name": "Маша", "color": 2, "isHost": false, "connected": true, "status": "watching", "joinedAt": 1791230000000}],
  "media": {"kind": "file", "ref": "…", "platform": "ВКонтакте", "title": "…", "durationSec": 213, "thumbnailUrl": "…", "setAt": 1791230000000} ,
  "playback": {"status": "playing", "positionSec": 768.2, "updatedAt": 1791230123456, "rate": 1, "seq": 41, "by": "K3M9…"},
  "chat": ["… последние 50 сообщений …"]
}
```

Коды ошибок: `BAD_MESSAGE`, `UNKNOWN_TYPE`, `NOT_IN_ROOM`, `ALREADY_IN_ROOM`, `ROOM_NOT_FOUND`, `ROOM_FULL`, `ROOM_LIMIT`, `NAME_INVALID`, `NAME_TAKEN`, `RESUME_FAILED`, `FORBIDDEN`, `INVALID_TICKET`, `TICKET_EXPIRED`, `INVALID_POSITION`, `CHAT_INVALID`, `RATE_LIMITED`, `KICKED`, `SERVER_BUSY`, `INTERNAL_ERROR`.

Close-коды: 1000 (выход), 1008 (нарушение протокола или флуд), 1009 (слишком большой кадр), 1012 (рестарт сервиса: клиент переподключается сразу), 1013 (сервер перегружен), 4001 (кикнут), 4004 (комната закрыта).

### 5.3 Правила домена

- **Вместимость**: участник в grace-периоде после разрыва (`ROOMS_RECONNECT_GRACE_SEC=45`) занимает место. По истечении grace он удаляется (`participant.left {reason: timeout}`).
- **Имя**: NFC, удаление управляющих, bidi (`U+202A–202E`, `U+2066–2069`) и zero-width символов, схлопывание пробелов, 1–24 символа (код-поинты), уникальность в комнате без учёта регистра, зарезервировано «Вы». Цвет аватара (0–7) сервер выдаёт по первому свободному индексу.
- **Чат**: 1–500 символов после той же санитизации, переводы строк заменяются пробелом. В комнате последние 100 сообщений, в `welcome` уходят последние 50. Сообщения кикнутых не удаляются.
- **Кик**: токен отзывается, `ipHash` блокируется в этой комнате на 10 минут (`KICKED` при входе).
- **Ведущий**: при уходе или таймауте права переходят к самому раннему подключённому участнику. Системное сообщение `host`.
- **Медиа**: `media.set` сбрасывает `playback` в `{paused, positionSec: startSec, seq+1}`.
- **Позиция**: `positionSec` — конечное число ≥ 0 и ≤ `durationSec + 5`, если длительность известна, иначе ≤ 86400. Иначе `INVALID_POSITION`.
- **Порядок**: last-writer-wins по порядку прихода на сервер. `seq` монотонный. Клиент игнорирует `playback.state` с `seq` ≤ уже применённого.

---

## 6. Алгоритм синхронизации (клиент)

1. **Часы.** `ping {t0}` → `pong {t0, serverTime}` → на приёме `t1`, `rtt = t1 − t0`, `offset = serverTime + rtt/2 − t1`. 5 пингов подряд после подключения, затем каждые 20 с. Берётся offset пробы с минимальным RTT из последних 8. Время клиента: `performance.timeOrigin + performance.now()`.
2. **Ожидаемая позиция:** `expected = playing ? positionSec + (serverNow − updatedAt)/1000 × rate : positionSec`, ограниченная `[0, duration]`.
3. **Применение** при каждом `playback.state` и по тику (раз в 500 мс при воспроизведении, раз в 2 с на паузе):
   - статус расходится → `play()`/`pause()` (помечается как программное действие);
   - `drift = currentTime − expected`;
   - HTML5: `|drift| > 0.5 с` → seek; `0.06 < |drift| ≤ 0.5` → `playbackRate = 1 − clamp(drift, −0.5, 0.5) × 0.2` до возврата в окно 0.06 с, затем `rate = 1`;
   - YouTube: `|drift| > 1.0 с` → `seekTo(expected, true)`, rate не трогаем;
   - после seek коррекция замораживается на 1.5 с.
4. **Намерения пользователя** идут из **наших** контролов: команда уходит сразу, локально применяется оптимистично, сервер подтверждает её через `seq`. Перемотка ползунком отправляется на `change` (отпускание), не на каждый `input`, не чаще 4 раз в секунду.
5. **Действия в обход наших контролов** (клик по iframe YouTube, медиаклавиши, нативные контролы iOS в полноэкранном режиме): событие play, pause или seeked без ожидаемого программного действия в окне 700 мс считается намерением пользователя и отправляется на сервер.
6. **Буферизация:** `waiting` или YT `BUFFERING` дольше 500 мс → `presence.set buffering`, после восстановления → `watching`. Остальных не останавливаем (MVP).
7. **Автоплей:** если `play()` отклонён (`NotAllowedError`) или YouTube за 2 с не перешёл в PLAYING, показываем оверлей «Нажмите, чтобы смотреть вместе». Клик запускает play и пересинхронизацию, на сервер ничего не уходит.
8. **Конец видео:** при `expected ≥ duration − 0.25` показываем «Смотреть заново» (seek 0 + play).
9. **Громкость и mute** локальные, не синхронизируются.
10. **Бейдж синхронизации:** «Синхронизировано» (зелёный, сокет подключён и drift в окне), «Синхронизация…» (жёлтый, коррекция или буферизация), «Нет соединения» (красный).

Чистая логика (часы, ожидаемая позиция, решение о коррекции, редьюсер состояния комнаты) живёт в модулях без DOM и покрывается unit-тестами.

---

## 7. Сервис `rooms/` (Deno)

```text
rooms/
├── deno.json                 tasks: dev, start, test, check (fmt --check + lint + check + test); strict TS
├── main.ts                   config → logger → registry → restore snapshot → Deno.serve → сигналы
├── src/
│   ├── config.ts             чтение и валидация env, fail fast
│   ├── log.ts                JSON-строки в stdout (journald)
│   ├── clock.ts              Clock (system | fake для тестов)
│   ├── server.ts             /healthz, upgrade /ws/rooms: Origin, IP, лимиты соединений
│   ├── connection.ts         сессия сокета: парсинг, валидация, rate limit, диспетчер
│   ├── protocol.ts           типы сообщений + ручные валидаторы (без zod)
│   ├── domain/room.ts        агрегат Room: чистые методы, возвращают события для рассылки
│   ├── domain/registry.ts    создание, поиск, таймеры истечения, глобальные лимиты
│   ├── domain/playback.ts    чистая математика позиции и валидации
│   ├── domain/text.ts        санитизация имён и чата
│   ├── security/ticket.ts    проверка HMAC (WebCrypto)
│   ├── security/ids.ts       roomId, participantId, token, sha256
│   ├── security/rate-limit.ts token bucket + fixed window по ключу
│   └── persistence/snapshot.ts атомарная запись и чтение, версия схемы
├── tools/smoke.ts            WS-смоук: 2 клиента, create → join → play → chat → leave
├── tools/loadtest.ts         N комнат × 5 клиентов
└── tests/*_test.ts           deno test; ассерты из node:assert/strict (без внешних зависимостей)
```

- **Зависимостей ноль**: только Web API, `Deno.*` и `node:` built-ins. В production запуск с `--no-remote --no-npm`.
- **Upgrade:** `Origin` есть и не совпадает с origin `APP_URL` → 403 (как `OriginGuardMiddleware`: отсутствующий Origin пропускается, не-браузерные клиенты ограничиваются лимитами). IP клиента берётся из `X-Real-IP` **только** если `remoteAddr` равен 127.0.0.1. Дальше `Deno.upgradeWebSocket(req, { idleTimeout: 30 })`.
- **Размер кадра:** проверь, есть ли у `Deno.upgradeWebSocket` лимит размера сообщения. Если нет, закрывай 1009 при `data.length > 4096` и полагайся на `MemoryMax` в systemd. Зафиксируй вывод в `docs/ARCHITECTURE.md`.
- **Лимиты** (env, значения по умолчанию): `ROOMS_MAX_PARTICIPANTS=5` (допустимо 2–5), `ROOMS_MAX_ROOMS=50`, `ROOMS_MAX_CONNECTIONS=250`, `ROOMS_MAX_CONNECTIONS_PER_IP=6`, `ROOMS_RATE_CREATE=10/3600` на IP, `ROOMS_RATE_JOIN=60/600` на IP (peek, join и resume вместе). На соединение: всего 30 сообщений/с (burst 60), playback 4/с (burst 10), чат 1/с (burst 5), presence 2/с. Три нарушения подряд → close 1008.
- **Жизненный цикл:** `ROOMS_EMPTY_TTL_MIN=30`, `ROOMS_MAX_AGE_HOURS=12`, `ROOMS_RECONNECT_GRACE_SEC=45` (после рестарта grace отсчитывается заново). Таймер очистки раз в 15 с.
- **Остановка:** SIGTERM и SIGINT (на Windows только SIGINT) → снапшот → close 1012 всем → выход.
- **`GET /healthz`:** `{"status":"ok","rooms":N,"connections":M,"uptimeSec":…}`. Nginx его не проксирует, доступен только с loopback.
- **Логи:** события `server.start/stop`, `ws.open/close` (code, длительность), `ws.rejected` (reason), `room.created/joined/resumed/left/expired`, `room.full`, `media.set` (kind, platform), `ticket.invalid` (reason), `limit.hit`, `snapshot.saved/restored`. Поля `room` (первые 4 символа id), `ip_hash` (HMAC от `ROOMS_SECRET`, 16 hex). **Никогда** не логировать текст чата, имена, токены, тикеты, полные `roomId` и сырые IP.
- **Dev-режим:** `deno task dev` при `APP_ENV=development` проксирует не-WS запросы на PHP dev-сервер (`ROOMS_DEV_UPSTREAM=http://127.0.0.1:8080`). Разработчик открывает `http://127.0.0.1:8790/watch`, и всё same-origin. Прокси работает только к одному фиксированному upstream и в production невозможен дважды: config запрещает его при `APP_ENV=production`, а флаг `--allow-net` production-юнита не даёт исходящих соединений.

---

## 8. Фронтенд

### 8.1 Страницы и маршруты

- `public/watch.html` — одна статическая страница для `/watch` (старт) и `/watch/{roomId}` (комната). Роутинг в `watch/main.js` по `location.pathname`.
- `public/index.php` (только dev-сервер): отдавать `watch.html` для `^/watch(/[0-9A-HJKMNP-TV-Z]{12})?$`.
- Подключение: `new URL('/ws/rooms', location.href)` со схемой `ws:` или `wss:`.

### 8.2 Модули

```text
public/assets/js/watch/
├── main.js             вход, роутинг, сборка зависимостей
├── room-client.js      WebSocket: reconnect (500 мс × 2^n до 8 с, джиттер 0–30 %), reqId → Promise, события
├── clock.js            ClockSync (чистый)
├── playback.js         expectedPosition, decideCorrection (чистые)
├── store.js            состояние комнаты + редьюсер серверных событий (чистый)
├── sync.js             SyncController: store ↔ PlayerAdapter, намерения → команды
├── players/html5.js    Html5PlayerAdapter
├── players/youtube.js  YouTubePlayerAdapter (ленивая загрузка iframe_api)
├── views/              start.js, join-dialog.js, player.js (оверлеи + контролы), chat.js,
│                       participants.js, source-dialog.js, header.js, toast.js, emoji.js
├── messages.js         коды ошибок WS и HTTP → русские тексты
└── text.js             буква аватара (Intl.Segmenter), склонение «участник/участника/участников», mm:ss / h:mm:ss
public/assets/css/watch.css   тёмная тема и раскладка комнаты
```

Переиспользуй `../api.js` (fetch-обёртка), `tokens.css`, `base.css`, `components.css`.

Интерфейс `PlayerAdapter`:

```text
load(media, {startSec}) → Promise; play() → Promise; pause(); seek(sec); getCurrentTime(); getDuration();
isPlaying(); setVolume(0..1); getVolume(); setMuted(b); isMuted(); setRate(r) (YouTube: no-op); destroy();
on('ready' | 'play' | 'pause' | 'seeked' | 'buffering' | 'ended' | 'error' | 'timeupdate', cb)
```

YouTube-адаптер: `new YT.Player(el, { host: 'https://www.youtube-nocookie.com', videoId, playerVars: { controls: 0, disablekb: 1, playsinline: 1, rel: 0, iv_load_policy: 3, fs: 0, enablejsapi: 1, origin: location.origin, start } })`. Обработать `onError`: 2, 5, 100, 101/150 (встраивание запрещено → ведущему предложить файловый режим), 153 (нет Referer, см. §9).

### 8.3 Раскладка по референсу (тёмная тема)

Значения цветов сними пипеткой с `watchroom-reference.png` и проверь контраст (текст ≥ 4.5:1). Ориентиры: фон около `#0b0d13`, поверхности `#12151e` и `#181c27`, граница `rgb(255 255 255 / .07)`, текст `#eceef3`, приглушённый около `#9097a8`, primary — индиго-фиолетовый градиент (`#6c5cff → #8b5cf6`), успех зелёный, «Покинуть» — красный outline. Палитра аватаров из 8 цветов. Тёмные токены переопределяют `--color-*` в `.theme-watch`, без дублирования компонентов. Шрифт Manrope (уже self-hosted).

- **Desktop (≥ 1280px):** шапка с логотипом, «Совместный просмотр», бейджем «Комната #7F4K2», справа «Скопировать ссылку» и «Выйти из комнаты». Сетка `minmax(0,1fr) 320px 260px`: плеер, чат, участники.
  - Плеер 16:9. Слева сверху чип с названием и временем, справа сверху бейдж синхронизации, по центру большая кнопка play/pause (видна на паузе и при hover). Внизу контролы: прогресс-слайдер, play/pause, громкость, время «12:48 / 52:16», шестерёнка (меню: «Пересинхронизировать», «Сменить видео» для ведущего), полноэкранный режим. Кнопку субтитров из референса **не показываем** в MVP (функции нет, мёртвых кнопок не делаем).
  - Под плеером: H1 с названием, «Сейчас смотрим вместе • 4 участника», для ведущего «Сменить видео», «Покинуть комнату».
  - Чат: заголовок «Чат» и число участников; сообщения с аватаром-буквой, именем, временем HH:MM и текстом; системные строки («Маша присоединилась»). Внизу кнопка эмодзи (поповер ~32 эмодзи), поле «Написать сообщение…» и круглая кнопка отправки. Enter отправляет. Автоскролл, если пользователь внизу, иначе плашка «Новые сообщения ↓».
  - Участники: «Участники 4 / 5», строки с аватаром, именем («Вы» для себя), короной у ведущего и статусом «Смотрит», «Загружается» или «Переподключается…». Kebab-меню только у ведущего для других: «Сделать ведущим», «Удалить из комнаты». Ниже «+ Пригласить друга» (копирует ссылку, на мобильных `navigator.share`; неактивно при 5/5).
- **Планшет (768–1279px):** плеер + правая колонка 340px с вкладками «Чат» и «Участники 4/5».
- **Мобильный (< 768px)**, как в референсе: компактная шапка (логотип, код комнаты, бургер с «Скопировать ссылку», «Участники», «Выйти»), видео на всю ширину, название, «4 участника», «Покинуть», вкладки «Чат» и «Участники», поле ввода прибито к низу. `100dvh` и `<meta name="viewport" content="…, interactive-widget=resizes-content">` для экранной клавиатуры.
- **Диалог имени** (нижний левый макет): `<dialog>` поверх размытой комнаты, иконка людей, «Как вас называть?», подпись, поле «Ваше имя» (`maxlength=24`, `autocomplete=nickname`, автозаполнение из `localStorage`), кнопка «Войти в комнату», сноска «Комната до 5 человек • Без регистрации». Ошибки `NAME_TAKEN` и `NAME_INVALID` показываются inline.
- **Модалка участников** (нижний правый макет) для планшета и мобильного: список, «Пригласить друга», кнопка «Копировать ссылку».
- **Стартовая страница `/watch`** (в референсе нет, делаем в той же тёмной стилистике): шапка с логотипом и ссылкой «Скачать видео» на главную; H1 «Смотрите видео вместе»; подзаголовок «Создайте комнату и отправьте ссылку друзьям — видео пойдёт синхронно у всех. До 5 человек, без регистрации.»; поле ссылки + CTA «Создать комнату» (ссылка необязательна: можно создать пустую комнату); три шага: создайте → отправьте ссылку → смотрите вместе; строка совместимости «YouTube, ВКонтакте и другие площадки». Поддержать `/watch?url=<encoded>`: поле предзаполнено.

### 8.4 Состояния, которые пользователь должен видеть

| Состояние | Что показываем |
|---|---|
| Старт | Форма создания комнаты |
| Проверка ссылки | Кнопка со спиннером «Проверяем ссылку…» |
| Вход | Диалог имени; если комната полна или не найдена, сообщение и CTA «Создать свою комнату» |
| Комната без видео | В области плеера: ведущему форма «Вставьте ссылку на видео», гостям «Ведущий скоро выберет видео» |
| Подготовка файла | Размытое превью, «Готовим видео… 42 %», позиция в очереди |
| Воспроизведение | Плеер, бейдж синхронизации |
| Автоплей заблокирован | Оверлей «Нажмите, чтобы смотреть вместе» |
| Переподключение | Плашка «Связь потеряна, переподключаемся…», контролы неактивны |
| Ошибка видео | Понятный текст по коду; ведущему «Выбрать другое видео» или «Подготовить через сервер» |
| Кикнут, комната закрыта | Экран с объяснением и CTA «Создать свою комнату» |

### 8.5 Доступность

Семантика (`header`, `main`, `section`, `aside`). Чат — `role="log"`. Объявления состояний в `aria-live="polite"`. У всех иконок-кнопок `aria-label`. Слайдер прогресса — `input type=range` с `aria-valuetext` («12 минут 48 секунд из 52 минут 16 секунд»). Горячие клавиши только при фокусе на плеере: Space/K, ←/→ ±5 с, M, F. `:focus-visible` на тёмном фоне. `prefers-reduced-motion`. `<dialog>` с возвратом фокуса. Ширина 320px без горизонтального скролла. Полноэкранный режим — `requestFullscreen()` на контейнере плеера (наши контролы остаются). iOS + файловый режим — `video.webkitEnterFullscreen()`.

### 8.6 Точки входа на основном сайте

- В шапке `index.html` (и в мобильном меню) акцентная ссылка-пилюля «Смотреть вместе» → `/watch`.
- Секция-промо после «Преимуществ»: «Новое: совместный просмотр» с коротким текстом и кнопкой «Создать комнату».
- В карточке результата анализа вторичная кнопка «Смотреть вместе» → `/watch?url=<текущая ссылка>`.
- Ссылка в футере, один вопрос в FAQ.
- Обнови `privacy.html` (комнаты: имя, чат, состояние хранятся в памяти и временном снапшоте до 12 часов; YouTube-плеер грузится с `youtube-nocookie.com` и на него распространяется политика Google; IP только в виде хэша), `terms.html` (пользователи отвечают за то, что смотрят и пишут в комнатах) и `abuse.html`.

### 8.7 Кэш ES-модулей (существующий баг, исправить)

`main.js` подключается как `?v=<sha>`, но вложенные импорты (`./app.js`, `./api.js`…) запрашиваются **без** версии и кэшируются на год как `immutable`. После деплоя у вернувшихся пользователей новый `main.js` получит старые модули. Исправление в Nginx: `map $arg_v $ch_asset_cache { "" "no-cache"; default "public, max-age=31536000, immutable"; }` и `add_header Cache-Control $ch_asset_cache always;` в `/assets/`. Версионированные файлы кэшируются навсегда, остальные ревалидируются по ETag. Проверь в браузере после деплоя.

---

## 9. Безопасность (дополнение к `ARCHITECTURE.md` §8)

| Угроза | Защита |
|---|---|
| Подмена медиа в комнате, SSRF через комнату | Медиа принимается только по тикету, подписанному PHP после `UrlValidator`. Deno не делает исходящих запросов (permissions) |
| Подбор `roomId` | 60 бит случайности, `ROOMS_RATE_JOIN` на IP, комнаты нигде не перечисляются. В логах Nginx `roomId` маскируется (`map $uri` → `/watch/:room`) |
| Кража личности участника | Токен 256 бит, только в `sessionStorage`. На сервере хранится sha256, сравнение constant-time |
| Cross-site WebSocket hijacking | Проверка `Origin` при upgrade. Cookies нет |
| XSS через имя, чат, название | Санитизация на сервере + **только** `textContent`/`createElement` на клиенте, никакого `innerHTML` с данными. Строгая CSP |
| Флуд и DoS | Nginx `limit_conn` на `/ws/rooms`, лимиты соединений, комнат и сообщений в Deno, лимит кадра, `idleTimeout`, systemd `MemoryMax`/`CPUQuota` |
| Удаление чужого watch-файла | Watch-задания недоступны через `/api/downloads/*` (§4.4). `mediaId` — capability на 6 часов, как `jobId` у скачиваний |
| Злоупотребление файловым режимом как хостингом | Только площадки из allowlist, лимиты analyze и downloads, квота хранилища, TTL 6 часов, комнаты приватные, максимум 5 зрителей |
| Утечка секрета | `ROOMS_SECRET` генерируется на сервере, лежит в `shared/.env` (0640), в Deno доступен только через `--allow-env` |

**CSP и заголовки для `/watch*`** (отдельный snippet `cliphunter-watch-headers.conf`):

```text
Content-Security-Policy: default-src 'self'; script-src 'self' https://www.youtube.com; frame-src https://www.youtube-nocookie.com https://www.youtube.com; img-src 'self' https: data:; media-src 'self'; connect-src 'self' wss://<домен>; style-src 'self'; font-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'
Referrer-Policy: strict-origin-when-cross-origin
```

**Важно:** YouTube-embed **требует HTTP Referer** (ошибка 153 «Video player configuration error» при `no-referrer`). Поэтому на странице просмотра `strict-origin-when-cross-origin`: уходит только origin, без пути с `roomId`. В `watch.html` **не копируй** `<meta name="referrer" content="no-referrer">` из `index.html`. После реализации проверь консоль браузера: нет нарушений CSP, видео играет.

---

## 10. Конфигурация (`.env.example`)

```dotenv
# Совместный просмотр
# 64 hex. Общий секрет PHP и rooms-сервиса (подпись тикетов, хэш IP в логах rooms).
# Генерировать на сервере: openssl rand -hex 32
ROOMS_SECRET=
WATCH_MAX_HEIGHT=720
WATCH_FILE_RETENTION_MIN=360
RATE_LIMIT_WATCH_SOURCES=20/3600

ROOMS_LISTEN=127.0.0.1:8790
ROOMS_MAX_PARTICIPANTS=5
ROOMS_MAX_ROOMS=50
ROOMS_MAX_CONNECTIONS=250
ROOMS_MAX_CONNECTIONS_PER_IP=6
ROOMS_RATE_CREATE=10/3600
ROOMS_RATE_JOIN=60/600
ROOMS_EMPTY_TTL_MIN=30
ROOMS_MAX_AGE_HOURS=12
ROOMS_RECONNECT_GRACE_SEC=45
# Только для разработки: куда rooms проксирует не-WS запросы
ROOMS_DEV_UPSTREAM=http://127.0.0.1:8080
```

Каталог снапшотов: `${STORAGE_PATH}/rooms` (+ `storage/rooms/.gitkeep`).

---

## 11. Инфраструктура

### 11.1 Nginx (`deploy/nginx/cliphunter.conf`)

- `map $http_upgrade $connection_upgrade { default upgrade; '' close; }`, `limit_conn_zone … zone=ch_ws`, `limit_req_zone … zone=ch_watch rate=10r/s`.
- `location = /ws/rooms`: `limit_conn ch_ws 6`, `proxy_pass http://127.0.0.1:8790`, `proxy_http_version 1.1`, `Upgrade`/`Connection`, `Host`, `X-Real-IP $remote_addr`, `proxy_read_timeout 120s`, `proxy_buffering off`.
- `location ^~ /api/watch/` с `limit_req zone=ch_watch burst=40 nodelay` + php snippet.
- `location = /watch` и `location ~ "^/watch/[0-9A-HJKMNP-TV-Z]{12}$"`: `try_files /watch.html =404`, `Cache-Control: no-cache`, watch-заголовки. `location = /watch.html { return 301 /watch; }`.
- Маскирование `roomId` в `log_format` и кэш ассетов из §8.7.
- nginx 1.22 не умеет WebSocket поверх HTTP/2 (RFC 8441). Браузер сам откроет отдельное HTTP/1.1-соединение, это нормально.

### 11.2 systemd `deploy/systemd/cliphunter-rooms.service`

```ini
[Service]
User=cliphunter
Group=cliphunter
EnvironmentFile=/var/www/cliphunter/shared/.env
Environment=DENO_DIR=/var/www/cliphunter/shared/storage/cache/deno-rooms
Environment=DENO_NO_UPDATE_CHECK=1
WorkingDirectory=/var/www/cliphunter/current
ExecStart=/usr/local/bin/deno run --quiet --no-prompt --no-remote --no-npm \
  --allow-net=127.0.0.1:8790 \
  --allow-env=APP_ENV,APP_URL,LOG_LEVEL,STORAGE_PATH,ROOMS_* \
  --allow-read=/var/www/cliphunter/shared/storage/rooms \
  --allow-write=/var/www/cliphunter/shared/storage/rooms \
  --v8-flags=--max-old-space-size=96 \
  /var/www/cliphunter/current/rooms/main.ts
Restart=always
RestartSec=2
KillSignal=SIGTERM
TimeoutStopSec=10
MemoryMax=192M
CPUQuota=50%
TasksMax=32
LimitNOFILE=4096
# + та же песочница, что у cliphunter-worker@.service; ReadWritePaths=…/storage/rooms …/storage/cache/deno-rooms
```

Проверь, что Deno 2.9 принимает wildcard `ROOMS_*` в `--allow-env`. Если нет, перечисли переменные явно. nftables уже разрешает uid `cliphunter` трафик через `lo`, менять firewall не нужно.

### 11.3 `provision.sh` и `deploy.sh`

- `provision.sh`: создать `storage/rooms` и `storage/cache/deno-rooms`, установить `cliphunter-watch-headers.conf` и юнит, `systemctl enable cliphunter-rooms` (запуск делает deploy).
- `deploy.sh`:
  - проверять, что `ROOMS_SECRET` в `.env` есть и похож на 64+ hex (по образцу `CONTACT_EMAIL`);
  - smoke до переключения: `deno check rooms/main.ts` и `deno run … rooms/main.ts --self-check` (загрузка конфига, выход 0) от пользователя `cliphunter`;
  - в `restart_services`: если `current/rooms/main.ts` существует, `systemctl restart cliphunter-rooms`, иначе `systemctl stop` (откат на релиз до этой фичи);
  - в `health_check`, когда в релизе есть `rooms/`: `systemctl is-active cliphunter-rooms`; `curl http://127.0.0.1:8790/healthz`; `/watch` и `/watch/ABCDEFGHJKMN` отвечают 200 с CSP, содержащей youtube; WS-рукопожатие через Nginx с правильным Origin даёт **101**, с чужим — **403**:
    `curl -s -o /dev/null -w '%{http_code}' --http1.1 --max-time 3 -H 'Connection: Upgrade' -H 'Upgrade: websocket' -H 'Sec-WebSocket-Version: 13' -H 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==' -H "Origin: https://$DOMAIN" --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN/ws/rooms"`
    (curl выйдет по таймауту с кодом 28 после 101, это ожидаемо, смотри на напечатанный код);
  - `deno run … rooms/tools/smoke.ts ws://127.0.0.1:8790/ws/rooms`.
- **Порядок первого выката** (юнит и nginx-конфиг ставит `provision.sh`, а health-check нового релиза уже ждёт rooms):
  1. На сервере добавить секрет, не печатая его: `grep -q '^ROOMS_SECRET=' /var/www/cliphunter/shared/.env || printf 'ROOMS_SECRET=%s\n' "$(openssl rand -hex 32)" >> /var/www/cliphunter/shared/.env`.
  2. Достать `deploy/` нового коммита во временный каталог: `git --git-dir=/var/www/cliphunter/repo.git fetch --prune origin '+refs/heads/*:refs/heads/*'`, затем `tmp=$(mktemp -d); git --git-dir=/var/www/cliphunter/repo.git archive <sha> deploy | tar -x -C "$tmp"`, и запустить `"$tmp/deploy/provision.sh" cliphunterapp.duckdns.org`. Скрипт идемпотентен. Он обновит nginx (`nginx -t` перед reload) и установит юнит.
  3. Деплоить **новым** скриптом из того же каталога: `"$tmp/deploy/deploy.sh" <sha>`. `deploy.sh` работает с абсолютными путями `/var/www/cliphunter`, поэтому его можно запускать не из `current`. Старый `deploy.sh` из `current` не знает про rooms: он не запустит сервис и не проверит его. Со следующего деплоя достаточно обычного `/var/www/cliphunter/current/deploy/deploy.sh main`. Опиши процедуру в `README.md`.

### 11.4 CI (`.github/workflows/ci.yml`)

Новый job `rooms`: `denoland/setup-deno@v2` (`deno-version: v2.x`), затем `deno fmt --check`, `deno lint`, `deno check rooms/main.ts`, `deno test rooms tests/js` (чистые фронтенд-модули тоже тестируются Deno). PHP-джобы без изменений, плюс новые тесты.

---

## 12. Тестирование

| Уровень | Что проверяем |
|---|---|
| PHP unit | `YouTubeId` (все формы URL, `t=`, отказ для плейлистов, каналов, ID длиной 10 и 12 символов); `MediaTicket` (формат, совпадение с фикстурой); выбор варианта ≤ `WATCH_MAX_HEIGHT`; `DownloadJob` без `purpose` читается как `download`; `AppConfig` (секрет обязателен в prod) |
| PHP API | `/api/watch/sources`: YouTube → 200 без вызова yt-dlp; VK через `tests/bin/fake-yt-dlp.php` → 202, задание `purpose=watch`; SSRF и мусорный URL; rate limit. Статус и файл: inline, `video/mp4`, X-Accel-путь, dev-Range → 206. Watch-задание недоступно через `/api/downloads/*` (GET, DELETE, file → 404). Ретеншн watch-файлов в `JobRunner` и `Cleaner` |
| Deno unit | ids; санитизация имён и чата (NUL, bidi `U+202E`, zero-width, NFC, эмодзи, длина, «Вы»); тикеты (валидный, подменённый payload или подпись, просроченный, `exp` слишком далеко, чужой ключ, мусор, **фикстура из PHP**); Room (5 мест, 6-й → `ROOM_FULL`, grace держит место, истечение grace освобождает, уникальность имён, передача прав, кик + бан IP, resume, лимит чата, валидация позиции, `seq`); rate limit на фейковых часах; registry (глобальный лимит, истечение пустых и старых комнат); снапшот (round-trip без сырых токенов, неизвестная версия игнорируется) |
| Deno интеграция | Реальный `Deno.serve` на случайном порту + `WebSocket`-клиенты: create → join → broadcast → resume после разрыва → кик → Origin 403 → флуд → 1008 → большой кадр → 1009 |
| JS unit (`deno test tests/js`) | `playback.js` (expected, решения коррекции на границах порогов), `clock.js` (выбор пробы с min RTT), `store.js` (редьюсер всех серверных событий, игнор старого `seq`), `text.js` (склонения, формат времени, буква аватара для эмодзи и составных символов) |
| Нагрузка | `rooms/tools/loadtest.ts`: 50 комнат × 5 клиентов, пинги, playback и чат. Цель на сервере: RSS < 150 МБ, p95 задержки рассылки < 50 мс, ни одного разрыва |
| E2E (рекомендуется, локально) | Playwright через `npx` (только dev, вне CI): два контекста браузера, файловый режим на fake yt-dlp с 60-секундным роликом (`ffmpeg -f lavfi -i testsrc…`), play, pause и seek у одного видны у другого, расхождение < 0.3 с через 30 с |
| Ручной чек-лист | Chrome + Firefox + телефон: создание, вход, занятое имя, 6-й участник, play, pause и seek < 0.5 с, drift через 10 минут, F5 сохраняет личность, обрыв сети и переподключение, кик, уход ведущего, YouTube с запретом встраивания, прогресс подготовки файла, 320px, клавиатура, скринридер, reduced motion |

---

## 13. Этапы

Каждый этап заканчивается зелёными проверками, коммитом и push. Сообщения коммитов ниже — ориентир, следуй §36 `CLAUDE.md`.

| # | Этап | Результат и критерий приёмки | Коммиты |
|---|---|---|---|
| 0 | Подготовка | Установить Deno локально (`winget install DenoLand.Deno`, версия ≥ 2.5). Прочитать документы. | — |
| 1 | PHP: источники и медиа | §4 целиком, конфиг, `.env.example`, тесты; `composer check` зелёный | `feat: resolve watch sources and sign media tickets`, `feat: serve prepared watch media inline` |
| 2 | Deno: домен | `rooms/` с `deno.json`, домен, тикеты, ids, санитизация, rate limit, снапшоты + unit-тесты; `deno task check` зелёный | `feat: add rooms service domain` |
| 3 | Deno: шлюз | `server.ts`, `connection.ts`, протокол §5, лимиты, логи, сигналы, healthz, dev-прокси, интеграционные тесты, smoke и loadtest; CI-job | `feat: add rooms websocket gateway`, `ci: check rooms service` |
| 4 | Фронтенд: каркас комнаты | `watch.html`, `watch.css`, старт, диалог имени, шапка, участники, чат через WS, все состояния §8.4 кроме плеера; адаптив 320–1920 | `feat: add watch room layout and join flow`, `feat: add room chat and participants` |
| 5 | Синхронизация | Адаптеры HTML5 и YouTube, контролы, ClockSync, SyncController, подготовка файла, смена видео, ошибки плеера, автоплей; JS unit-тесты; E2E | `feat: add synchronized playback` |
| 6 | Сайт и право | Точки входа §8.6, FAQ, privacy, terms, abuse | `feat: link watch rooms from the landing page`, `docs: describe watch rooms in legal pages` |
| 7 | Ops | Nginx, watch-заголовки, кэш ассетов, systemd, provision, deploy, README, раздел «Совместный просмотр» в `ARCHITECTURE.md` | `ops: run rooms service behind nginx`, `fix: revalidate unversioned js modules` |
| 8 | Hardening | Security-ревью диффа (`/security-review`), проверка a11y и адаптива, нагрузочный прогон, исправления | `fix: …`, `test: …` |
| 9 | Production | Выкат по §11.3, проверка §14, отчёт владельцу | — |

---

## 14. Проверка в production (обязательна, без неё фича не готова)

1. `deploy.sh` прошёл, health-check зелёный, в `journalctl -u cliphunter-rooms` нет ошибок.
2. `https://cliphunterapp.duckdns.org/` — кнопка «Смотреть вместе» ведёт на `/watch`.
3. Два реальных устройства в **разных сетях** (ПК владельца и телефон на мобильном интернете): YouTube-комната, вход по ссылке, имена видны, play, pause и seek синхронны, чат работает.
4. Файловый режим: ссылка VK или Reddit → прогресс подготовки → синхронный просмотр.
5. Шестой участник получает «Комната заполнена». F5 сохраняет участника. `systemctl restart cliphunter-rooms` → клиенты переподключились, комната и чат на месте.
6. Безопасность: `/ws/rooms` с чужим Origin → 403; `/.env`, `/rooms/main.ts`, `/storage/rooms/state.json` → 404; в консоли браузера нет нарушений CSP.
7. Логи: `app.log`, `journalctl -u cliphunter-rooms`, `/var/log/nginx/cliphunter.error.log` без ошибок; в логах нет имён, текстов чата и полных `roomId`.
8. Откат `deploy.sh --rollback` проверен: rooms корректно останавливается или возвращается.

---

## 15. Риски

| Риск | Митигация |
|---|---|
| YouTube запрещает встраивание конкретного видео или требует подтвердить возраст | Понятная ошибка + fallback в файловый режим (может не сработать, если YouTube блокирует IP сервера; это честно сообщаем) |
| YouTube меняет правила embed (Referer, CSP-хосты) | §9, проверка консоли после каждого деплоя, ошибки плеера логируются на клиенте в UI |
| Политики автоплея | Оверлей с кликом, вход по клику уже даёт user activation |
| Канал сервера около 0.5 МБ/с на клиента | 720p по умолчанию, `WATCH_MAX_HEIGHT` настраивается; YouTube сервер не нагружает |
| Рестарт сервиса во время просмотра | Снапшоты, close 1012, переподключение с токеном |
| Один процесс Deno упал | `Restart=always`, снапшот не старше 10 секунд |
| 1 vCPU делят yt-dlp и rooms | Воркер `Nice=10`, `CPUQuota`; rooms лёгкий (JSON-рассылка) |
| Правовой риск файлового режима (копия показывается до 5 зрителям) | Только площадки из allowlist, приватные комнаты, TTL, Terms и Abuse; DRM и авторизация не обходятся |

---

## 16. Definition of Done

- Интерфейс комнаты соответствует `watchroom-reference.png` на desktop, планшете и мобильном; стартовая страница и точки входа на сайте есть.
- Создание комнаты → ссылка → вход с именем → не больше 5 человек; ведущий, кик, передача прав работают.
- Play, pause и seek расходятся всем; установившееся расхождение ≤ 0.3 с (HTML5) и ≤ 1 с (YouTube).
- Переподключение и рестарт сервиса не теряют комнату.
- Чат работает и устойчив к XSS.
- YouTube и как минимум одна площадка в файловом режиме работают в production.
- Все тесты и CI зелёные, security-ревью пройдено, документация обновлена.
- Задеплоено через `deploy.sh`, health-check включает rooms, откат проверен.
- Все коммиты в GitHub, без трейлеров `Co-Authored-By`.

## 17. Фаза 2

- Embed-адаптеры RuTube и VK Video.
- Режим «управляет только ведущий».
- Очередь видео и плейлист комнаты.
- Реакции поверх видео, индикатор «печатает…».
- Дедупликация подготовки файла по URL.
- Пауза для всех, пока кто-то буферизуется (опционально).
