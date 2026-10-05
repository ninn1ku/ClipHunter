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

## Правовое

Пользователь сам отвечает за наличие прав на скачиваемый контент.
ClipHunter не обходит DRM, paywall и ограничения доступа.
