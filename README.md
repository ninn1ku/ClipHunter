# ClipHunter

Минималистичный веб-сервис: вставляете ссылку на видео, выбираете качество и скачиваете файл.
Под капотом yt-dlp и ffmpeg, бэкенд на PHP 8.3+, фронтенд на HTML, CSS и vanilla JS.

Production: https://cliphunterapp.duckdns.org

Архитектура, API, модель угроз и план работ описаны в [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).

## Требования

- PHP 8.3+ с расширениями `intl`, `mbstring`, `json`
- Composer 2
- yt-dlp, ffmpeg, ffprobe

## Локальный запуск

```bash
composer install
cp .env.example .env            # затем заполните APP_SECRET
php -S 127.0.0.1:8080 -t public public/index.php
php bin/worker.php              # воркер скачиваний, в отдельном терминале
```

## Проверки

```bash
composer check                  # PHP-CS-Fixer (PSR-12) + PHPStan (max) + PHPUnit
composer test                   # только тесты
vendor/bin/phpunit --group network   # тесты с реальным yt-dlp и сетью
```

## Структура

| Путь | Назначение |
|---|---|
| `public/` | Единственный web root: статика и фронт-контроллер API |
| `src/` | Код приложения (PSR-4, `ClipHunter\`) |
| `config/` | Allowlist платформ, карта форматов |
| `bin/` | Воркер, очистка, smoke-проверка |
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
| Firewall | `deploy/bin/apply-firewall.sh` (с автооткатом), затем `--persist` |

Деплой сам прогоняет smoke-тест до переключения и health-check после; при сбое возвращает предыдущий релиз.
Сервисы: `nginx`, `php8.4-fpm` (пул `cliphunter`), `cliphunter-worker@N` (N = `MAX_CONCURRENT_DOWNLOADS`),
таймеры `cliphunter-cleanup` (каждые 5 минут) и `cliphunter-ytdlp-update` (ежедневно, со smoke-тестом и откатом).

## Правовое

Пользователь сам отвечает за наличие прав на скачиваемый контент.
ClipHunter не обходит DRM, paywall и ограничения доступа.
