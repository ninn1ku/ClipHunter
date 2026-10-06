# ClipHunter

Минималистичный веб-сервис: вставляете ссылку на видео, выбираете качество и скачиваете файл.
Под капотом yt-dlp и ffmpeg, бэкенд на PHP 8.3+, фронтенд на HTML, CSS и vanilla JS.
«Смотреть вместе» (`/watch`): комнаты до 5 человек с синхронным воспроизведением и чатом;
realtime-сервис `rooms/` на Deno 2 без внешних зависимостей.

Production: https://cliphunterapp.duckdns.org

Архитектура, API, модель угроз и план работ описаны в [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md),
совместный просмотр — в [`docs/WATCH_PARTY_PLAN.md`](docs/WATCH_PARTY_PLAN.md).

## Требования

- PHP 8.3+ с расширениями `intl`, `mbstring`, `json`
- Composer 2
- yt-dlp, ffmpeg, ffprobe
- Deno 2.5+ (сервис комнат и JS-тесты)

## Локальный запуск

```bash
composer install
cp .env.example .env            # затем заполните APP_SECRET
php -S 127.0.0.1:8080 -t public public/index.php
php bin/worker.php              # воркер скачиваний, в отдельном терминале
```

Совместный просмотр локально: rooms слушает 127.0.0.1:8790 и в development проксирует
остальные запросы на PHP dev-сервер, поэтому страница, API и WebSocket живут на одном origin.
Откройте http://127.0.0.1:8790/watch и задайте `APP_URL` так, чтобы его понимали оба процесса:

```bash
APP_URL=http://127.0.0.1:8790 php -S 127.0.0.1:8080 -t public public/index.php
APP_ENV=development APP_URL=http://127.0.0.1:8790 ROOMS_DEV_UPSTREAM=http://127.0.0.1:8080 deno task dev
```

## Проверки

```bash
composer check                  # PHP-CS-Fixer (PSR-12) + PHPStan (max) + PHPUnit
composer test                   # только тесты
vendor/bin/phpunit --group network   # тесты с реальным yt-dlp и сетью
deno task check                 # deno fmt --check + lint + check + тесты rooms/ и tests/js/
```

## Структура

| Путь | Назначение |
|---|---|
| `public/` | Единственный web root: статика и фронт-контроллер API |
| `src/` | Код приложения (PSR-4, `ClipHunter\`) |
| `config/` | Allowlist платформ, карта форматов |
| `bin/` | Воркер, очистка, smoke-проверка |
| `rooms/` | Сервис комнат (Deno): WebSocket, состояние комнат, снапшоты; `tools/` — smoke и нагрузочный тест |
| `deploy/` | Конфиги Nginx, PHP-FPM, systemd и скрипты provision/deploy |
| `storage/` | Временные файлы, задания и логи (не в Git) |
| `tests/` | Unit, integration, API и security-тесты |

## Production

Сервер: Debian 12, `root@193.233.198.66`, приложение в `/var/www/cliphunter`:

```text
/var/www/cliphunter/
├── repo.git/            bare-клон (deploy key только на чтение)
├── releases/<sha>/      неизменяемые релизы (код root:cliphunter, только чтение)
├── current -> releases/<sha>
└── shared/
    ├── .env             production-конфиг (0640 root:cliphunter, не в Git)
    ├── previous_release
    └── storage/         задания, временные и готовые файлы, логи
```

| Задача | Команда (на сервере, от root) |
|---|---|
| Первичная установка (идемпотентно) | `deploy/provision.sh cliphunterapp.duckdns.org` |
| Деплой коммита / ветки | `/var/www/cliphunter/current/deploy/deploy.sh main` |
| Откат на предыдущий релиз | `/var/www/cliphunter/current/deploy/deploy.sh --rollback` |
| Проверка здоровья | `cd /var/www/cliphunter/current && runuser -u cliphunter -- php8.4 bin/smoke.php --network` |
| Логи приложения | `/var/www/cliphunter/shared/storage/logs/app.log` (JSON, 14 дней) |
| Логи воркера / Nginx | `journalctl -u cliphunter-worker@1`, `/var/log/nginx/cliphunter.*.log` |
| Логи и состояние комнат | `journalctl -u cliphunter-rooms`, `curl -s http://127.0.0.1:8790/healthz` |
| Нагрузочный тест комнат | `cd current && runuser -u cliphunter -- deno run --no-config --env-file=../shared/.env --allow-net=127.0.0.1:8790 --allow-env=APP_ENV,ROOMS_SECRET rooms/tools/loadtest.ts ws://127.0.0.1:8790/ws/rooms 50 60` |
| Firewall | `deploy/bin/apply-firewall.sh` (с автооткатом), затем `--persist` |

Деплой сам прогоняет smoke-тест до переключения и health-check после; при сбое возвращает предыдущий релиз.
Сервисы: `nginx`, `php8.4-fpm` (пул `cliphunter`), `cliphunter-worker@N` (N = `MAX_CONCURRENT_DOWNLOADS`),
таймеры `cliphunter-cleanup` (каждые 5 минут) и `cliphunter-ytdlp-update` (ежедневно, со smoke-тестом и откатом),
`cliphunter-rooms` (Deno, только 127.0.0.1:8790; Nginx проксирует `/ws/rooms`). Деплой проверяет и комнаты:
`/healthz`, CSP страниц `/watch`, WebSocket-рукопожатие (101 со своим Origin, 403 с чужим) и WS-smoke.
Откат на релиз без `rooms/` останавливает сервис.

### Первый выкат совместного просмотра

Юнит rooms и nginx-конфиг ставит `provision.sh`, а старый `deploy.sh` в `current` о комнатах не знает,
поэтому первый раз (от root на сервере):

```bash
# 1. Секрет, общий для PHP и rooms (не печатается):
grep -q '^ROOMS_SECRET=' /var/www/cliphunter/shared/.env || printf 'ROOMS_SECRET=%s\n' "$(openssl rand -hex 32)" >> /var/www/cliphunter/shared/.env
# 2. deploy/ нового коммита во временный каталог и provision (идемпотентно, делает nginx -t перед reload):
git --git-dir=/var/www/cliphunter/repo.git fetch --prune origin '+refs/heads/*:refs/heads/*'
tmp=$(mktemp -d); git --git-dir=/var/www/cliphunter/repo.git archive <sha> deploy | tar -x -C "$tmp"
"$tmp/deploy/provision.sh" cliphunterapp.duckdns.org
# 3. Деплой новым скриптом (он работает с абсолютными путями):
"$tmp/deploy/deploy.sh" <sha>
```

Дальше достаточно обычного `/var/www/cliphunter/current/deploy/deploy.sh main`.

## Правовое

Пользователь сам отвечает за наличие прав на скачиваемый контент.
ClipHunter не обходит DRM, paywall и ограничения доступа.
