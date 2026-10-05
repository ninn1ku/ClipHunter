#!/usr/bin/env bash
# Daily yt-dlp update with a real smoke test; rolls back to the previous version on failure.
set -euo pipefail

VENV=/opt/yt-dlp/venv
APP=/var/www/cliphunter

old=$("$VENV/bin/yt-dlp" --version)
"$VENV/bin/pip" install -q -U "yt-dlp[default]"
new=$("$VENV/bin/yt-dlp" --version)

if [[ "$old" == "$new" ]]; then
    exit 0
fi

if runuser -u cliphunter -- env PATH=/usr/local/bin:/usr/bin:/bin \
        HOME="$APP/shared/storage/cache" DENO_DIR="$APP/shared/storage/cache/deno" \
        php8.4 "$APP/current/bin/smoke.php" --network >/dev/null; then
    logger -t cliphunter "yt-dlp updated $old -> $new"
    systemctl restart 'cliphunter-worker@*.service' || true
else
    "$VENV/bin/pip" install -q "yt-dlp[default]==$old"
    logger -t cliphunter "yt-dlp $new failed the smoke test; rolled back to $old"
    exit 1
fi
