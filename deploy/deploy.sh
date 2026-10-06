#!/usr/bin/env bash
# ClipHunter — deploy a Git commit, or roll back.
#
#   sudo deploy.sh <git-ref>      build releases/<sha>, smoke-test, switch, health-check
#   sudo deploy.sh --rollback     switch back to the previous release
#
# Every release is an immutable directory named after its commit; `current` is an atomic
# symlink. If the health check fails after switching, the previous release is restored.
set -euo pipefail

APP=/var/www/cliphunter
REPO_URL="${CLIPHUNTER_REPO:-git@github.com:ninn1ku/ClipHunter.git}"
DEPLOY_KEY=/root/.ssh/cliphunter_deploy
PHP=php8.4
KEEP_RELEASES=3
ENV_FILE="$APP/shared/.env"
DENO=/usr/local/bin/deno
ROOMS_URL=ws://127.0.0.1:8790/ws/rooms
# The same permissions as deploy/systemd/cliphunter-rooms.service.
ROOMS_FLAGS=(--quiet --no-prompt --no-config --no-lock --no-remote --no-npm
    --allow-net=127.0.0.1:8790 '--allow-env=APP_ENV,APP_URL,LOG_LEVEL,STORAGE_PATH,ROOMS_*'
    "--allow-read=$APP/shared/storage/rooms" "--allow-write=$APP/shared/storage/rooms")

export GIT_SSH_COMMAND="ssh -i $DEPLOY_KEY -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new"
export COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1

log() { printf '==> %s\n' "$*"; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
[[ $EUID -eq 0 ]] || die "run as root"
[[ -f "$ENV_FILE" ]] || die "$ENV_FILE is missing"

env_value() { grep -E "^$1=" "$ENV_FILE" | tail -n1 | cut -d= -f2- | tr -d '"' | tr -d "'"; }
DOMAIN=$(env_value APP_URL | sed -E 's#^https?://##; s#/.*$##')
CONTACT_EMAIL=$(env_value CONTACT_EMAIL)
WORKERS=$(env_value MAX_CONCURRENT_DOWNLOADS); WORKERS=${WORKERS:-1}
[[ "$DOMAIN" =~ ^[a-z0-9.-]+$ ]] || die "APP_URL in .env is not valid"
[[ "$CONTACT_EMAIL" =~ ^[^@[:space:]]+@[^@[:space:]]+\.[a-z]{2,}$ ]] || die "CONTACT_EMAIL in .env is missing or invalid"
[[ "$WORKERS" =~ ^[1-9]$ ]] || die "MAX_CONCURRENT_DOWNLOADS must be 1-9"
[[ "$(env_value ROOMS_SECRET)" =~ ^[a-f0-9]{64,}$ ]] || die "ROOMS_SECRET in .env is missing or not 64+ lowercase hex (openssl rand -hex 32)"

# Runs deno as the app user, with the cache directory the rooms unit uses.
as_app_deno() {
    runuser -u cliphunter -- env PATH=/usr/local/bin:/usr/bin:/bin HOME="$APP/shared/storage/cache" \
        DENO_DIR="$APP/shared/storage/cache/deno-rooms" DENO_NO_UPDATE_CHECK=1 "$DENO" "$@"
}

restart_services() {
    systemctl reload "${PHP}-fpm"
    systemctl stop 'cliphunter-worker@*.service' 2>/dev/null || true
    for i in $(seq 1 "$WORKERS"); do
        systemctl enable --now "cliphunter-worker@$i.service" >/dev/null 2>&1
    done
    # Releases before watch rooms have no rooms/: a rollback to one stops the service.
    if [[ -f "$APP/current/rooms/main.ts" ]]; then
        systemctl enable cliphunter-rooms.service >/dev/null 2>&1
        systemctl restart cliphunter-rooms.service
    else
        systemctl stop cliphunter-rooms.service 2>/dev/null || true
    fi
}

switch_to() {
    ln -sfn "$1" "$APP/current.next"
    mv -Tf "$APP/current.next" "$APP/current"
    restart_services
}

health_check() {
    local base="https://$DOMAIN" curl_opts=(-fsS --max-time 15 --resolve "$DOMAIN:443:127.0.0.1")
    curl "${curl_opts[@]}" "$base/api/health" | grep -q '"status":"ok"' || { echo "api/health failed"; return 1; }
    curl "${curl_opts[@]}" -o /dev/null "$base/" || { echo "home page failed"; return 1; }
    for path in /.env /composer.json /src/Kernel.php /index.php /storage/logs/app.log /vendor/autoload.php /_files/x; do
        code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 --resolve "$DOMAIN:443:127.0.0.1" "$base$path")
        [[ "$code" == "404" ]] || { echo "$path is exposed (HTTP $code)"; return 1; }
    done
    sleep 2
    systemctl is-active --quiet cliphunter-worker@1.service || { echo "worker is not running"; return 1; }
    if [[ -f "$APP/current/rooms/main.ts" ]]; then
        health_check_rooms || return 1
    fi
}

# WebSocket handshake through Nginx; prints the HTTP status. curl keeps an upgraded connection
# open until --max-time (exit 28), so only the printed code matters.
ws_handshake() {
    curl -s -o /dev/null -w '%{http_code}' --http1.1 --max-time 3 -H 'Connection: Upgrade' -H 'Upgrade: websocket' \
        -H 'Sec-WebSocket-Version: 13' -H 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==' -H "Origin: $1" \
        --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN/ws/rooms" || true
}

health_check_rooms() {
    local base="https://$DOMAIN" code headers path
    systemctl is-active --quiet cliphunter-rooms.service || { echo "rooms service is not running"; return 1; }
    local tries=0
    until curl -fsS --max-time 3 http://127.0.0.1:8790/healthz 2>/dev/null | grep -q '"status":"ok"'; do
        (( ++tries < 10 )) || { echo "rooms /healthz failed"; return 1; }
        sleep 1
    done
    for path in /watch /watch/ABCDEFGHJKMN; do
        headers=$(curl -sS -D - -o /dev/null --max-time 10 --resolve "$DOMAIN:443:127.0.0.1" "$base$path")
        grep -q '^HTTP/[0-9.]* 200' <<<"$headers" || { echo "$path is not 200"; return 1; }
        grep -qi '^content-security-policy:.*youtube' <<<"$headers" || { echo "$path lacks the watch CSP"; return 1; }
    done
    for path in /rooms/main.ts /storage/rooms/state.json /deno.json; do
        code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 --resolve "$DOMAIN:443:127.0.0.1" "$base$path")
        [[ "$code" == "404" ]] || { echo "$path is exposed (HTTP $code)"; return 1; }
    done
    code=$(ws_handshake "https://$DOMAIN")
    [[ "$code" == "101" ]] || { echo "WebSocket handshake gave $code, expected 101"; return 1; }
    code=$(ws_handshake "https://evil.example")
    [[ "$code" == "403" ]] || { echo "foreign Origin gave $code, expected 403"; return 1; }
    (cd "$APP/current" && as_app_deno run --quiet --no-prompt --no-config --no-lock --no-remote --no-npm \
        "--env-file=$ENV_FILE" --allow-net=127.0.0.1:8790 --allow-env=APP_ENV,ROOMS_SECRET \
        "$APP/current/rooms/tools/smoke.ts" "$ROOMS_URL") || { echo "rooms smoke test failed"; return 1; }
}

if [[ "${1:-}" == "--rollback" ]]; then
    previous=$(cat "$APP/shared/previous_release" 2>/dev/null || true)
    [[ "$previous" == "$APP"/releases/* && -d "$previous" ]] || die "no previous release recorded"
    log "Rolling back to $(basename "$previous")"
    echo "$(readlink -f "$APP/current")" > "$APP/shared/previous_release"
    switch_to "$previous"
    health_check || die "health check failed after rollback"
    log "Rolled back to $(basename "$previous")"
    exit 0
fi

REF="${1:?usage: deploy.sh <git-ref> | --rollback}"

log "Fetching $REF"
if [[ ! -d "$APP/repo.git" ]]; then
    git clone --quiet --bare "$REPO_URL" "$APP/repo.git"
fi
git --git-dir="$APP/repo.git" fetch --quiet --prune origin '+refs/heads/*:refs/heads/*' '+refs/tags/*:refs/tags/*'
SHA=$(git --git-dir="$APP/repo.git" rev-parse --verify "$REF^{commit}")
SHORT=${SHA:0:12}
RELEASE="$APP/releases/$SHA"
# Only a real release directory counts (readlink -f returns the path itself when the link is
# missing, which on a first deploy would make "current" point at itself on rollback).
CURRENT=$(readlink -e "$APP/current" 2>/dev/null || true)
[[ "$CURRENT" == "$APP"/releases/* && -d "$CURRENT" ]] || CURRENT=""
[[ "$CURRENT" != "$RELEASE" ]] || die "$SHORT is already live"

log "Building release $SHORT"
rm -rf "$RELEASE"
mkdir -p "$RELEASE"
git --git-dir="$APP/repo.git" archive "$SHA" | tar -x -C "$RELEASE"
rm -rf "$RELEASE/storage"
ln -s "$APP/shared/storage" "$RELEASE/storage"
ln -s "$ENV_FILE" "$RELEASE/.env"
composer install --quiet --no-dev --prefer-dist --optimize-autoloader --classmap-authoritative --working-dir="$RELEASE"
find "$RELEASE/public" -name '*.html' -exec sed -i \
    -e "s#__ASSET_VERSION__#$SHORT#g" \
    -e "s#__ABUSE_EMAIL__#$CONTACT_EMAIL#g" {} +
echo "$SHA" > "$RELEASE/REVISION"

# Code is owned by root and only readable by the app group: the app cannot modify itself.
chown -R root:cliphunter "$RELEASE"
find "$RELEASE" -type d -exec chmod 750 {} +
find "$RELEASE" -type f -exec chmod 640 {} +
chmod 750 "$RELEASE"/deploy/*.sh "$RELEASE"/deploy/bin/*.sh

log "Smoke test (as cliphunter)"
# runuser keeps the caller's cwd (e.g. /root), which the app user cannot enter.
cd "$RELEASE"
runuser -u cliphunter -- env PATH=/usr/local/bin:/usr/bin:/bin "$PHP" "$RELEASE/bin/smoke.php" \
    || { rm -rf "$RELEASE"; die "smoke test failed; nothing was switched"; }
if [[ -f "$RELEASE/rooms/main.ts" ]]; then
    as_app_deno check --quiet --no-config --no-lock "$RELEASE/rooms/main.ts" \
        || { rm -rf "$RELEASE"; die "rooms type check failed; nothing was switched"; }
    as_app_deno run "${ROOMS_FLAGS[@]}" "--env-file=$ENV_FILE" "$RELEASE/rooms/main.ts" --self-check \
        || { rm -rf "$RELEASE"; die "rooms self-check failed; nothing was switched"; }
fi

log "Switching current -> $SHORT"
echo "$CURRENT" > "$APP/shared/previous_release"
switch_to "$RELEASE"

log "Health check"
if ! health_check; then
    if [[ -n "$CURRENT" && -d "$CURRENT" ]]; then
        log "Health check failed — rolling back to $(basename "$CURRENT")"
        switch_to "$CURRENT"
    fi
    die "deploy of $SHORT failed"
fi

log "Pruning old releases (keeping $KEEP_RELEASES)"
ls -1dt "$APP"/releases/*/ | tail -n +$((KEEP_RELEASES + 1)) | while read -r old; do
    old=${old%/}
    [[ "$old" == "$RELEASE" || "$old" == "$CURRENT" ]] || rm -rf "$old"
done

log "Deployed $SHORT ($(git --git-dir="$APP/repo.git" log -1 --format=%s "$SHA"))"
