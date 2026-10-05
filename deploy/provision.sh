#!/usr/bin/env bash
# ClipHunter — one-time server provisioning for Debian 12. Idempotent: safe to re-run.
#
#   sudo ./deploy/provision.sh <domain> [letsencrypt-email]
#
# Installs: nginx, PHP 8.4 (packages.sury.org), Composer, ffmpeg, deno, yt-dlp (venv),
# certbot, unattended-upgrades; creates the cliphunter system user, directory layout,
# PHP-FPM pool, systemd units and logrotate config. Does NOT touch the firewall or SSH.
set -euo pipefail

DOMAIN="${1:?usage: provision.sh <domain> [letsencrypt-email]}"
LE_EMAIL="${2:-}"
APP=/var/www/cliphunter
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHP=8.4
export DEBIAN_FRONTEND=noninteractive

log() { printf '\n==> %s\n' "$*"; }
[[ $EUID -eq 0 ]] || { echo "run as root" >&2; exit 1; }
[[ "$DOMAIN" =~ ^[a-z0-9.-]+$ ]] || { echo "bad domain" >&2; exit 1; }

log "Swap (2 GB) for a 1 GB RAM server"
if ! swapon --show | grep -q .; then
    fallocate -l 2G /swapfile
    chmod 600 /swapfile
    mkswap /swapfile >/dev/null
    swapon /swapfile
    grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi
echo 'vm.swappiness=10' > /etc/sysctl.d/90-cliphunter.conf
sysctl -q -p /etc/sysctl.d/90-cliphunter.conf

log "Base packages"
apt-get update -q
apt-get install -y -q ca-certificates curl gnupg git unzip nginx ffmpeg python3-venv \
    certbot logrotate unattended-upgrades

log "PHP ${PHP} from packages.sury.org"
if [[ ! -f /etc/apt/sources.list.d/php-sury.list ]]; then
    curl -fsSL https://packages.sury.org/php/apt.gpg -o /usr/share/keyrings/deb.sury.org-php.gpg
    echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ $(. /etc/os-release && echo "$VERSION_CODENAME") main" \
        > /etc/apt/sources.list.d/php-sury.list
    apt-get update -q
fi
apt-get install -y -q "php${PHP}-cli" "php${PHP}-fpm" "php${PHP}-intl" "php${PHP}-mbstring" \
    "php${PHP}-xml" "php${PHP}-curl" "php${PHP}-zip" "php${PHP}-opcache"

log "Composer (installer signature verified)"
if ! command -v composer >/dev/null; then
    tmp=$(mktemp -d)
    expected=$(curl -fsSL https://composer.github.io/installer.sig)
    curl -fsSL https://getcomposer.org/installer -o "$tmp/composer-setup.php"
    actual=$(sha384sum "$tmp/composer-setup.php" | cut -d' ' -f1)
    [[ "$expected" == "$actual" ]] || { echo "composer installer signature mismatch" >&2; exit 1; }
    php "$tmp/composer-setup.php" --quiet --install-dir=/usr/local/bin --filename=composer
    rm -rf "$tmp"
fi

log "deno (JavaScript runtime yt-dlp needs for YouTube)"
if ! command -v deno >/dev/null; then
    tmp=$(mktemp -d)
    base=https://github.com/denoland/deno/releases/latest/download
    curl -fsSL "$base/deno-x86_64-unknown-linux-gnu.zip" -o "$tmp/deno.zip"
    curl -fsSL "$base/deno-x86_64-unknown-linux-gnu.zip.sha256sum" -o "$tmp/deno.sha256"
    (cd "$tmp" && sed -E 's#\s+.*$#  deno.zip#' deno.sha256 | sha256sum -c --quiet -)
    unzip -q -o "$tmp/deno.zip" -d /usr/local/bin
    chmod 755 /usr/local/bin/deno
    rm -rf "$tmp"
fi

log "yt-dlp in /opt/yt-dlp/venv"
if [[ ! -x /opt/yt-dlp/venv/bin/yt-dlp ]]; then
    python3 -m venv /opt/yt-dlp/venv
fi
/opt/yt-dlp/venv/bin/pip install -q -U pip
/opt/yt-dlp/venv/bin/pip install -q -U "yt-dlp[default,curl-cffi]"

log "System user and directories"
id cliphunter >/dev/null 2>&1 || useradd --system --home-dir "$APP" --no-create-home --shell /usr/sbin/nologin cliphunter
usermod -a -G cliphunter www-data
install -d -o root -g root -m 755 "$APP" "$APP/releases" "$APP/shared" /var/www/certbot
install -d -o cliphunter -g cliphunter -m 2750 "$APP/shared/storage"
for d in analyses jobs jobs/queue jobs/running tmp downloads ratelimit locks cache cache/yt-dlp cache/deno logs; do
    install -d -o cliphunter -g cliphunter -m 2750 "$APP/shared/storage/$d"
done

log "PHP configuration"
install -m 644 "$SRC/php/99-cliphunter.ini" "/etc/php/${PHP}/fpm/conf.d/99-cliphunter.ini"
install -m 644 "$SRC/php/99-cliphunter.ini" "/etc/php/${PHP}/cli/conf.d/99-cliphunter.ini"
install -m 644 "$SRC/php/cliphunter-fpm.conf" "/etc/php/${PHP}/fpm/pool.d/cliphunter.conf"
# The default "www" pool is not used; disable it to save memory.
if [[ -f "/etc/php/${PHP}/fpm/pool.d/www.conf" ]]; then
    mv "/etc/php/${PHP}/fpm/pool.d/www.conf" "/etc/php/${PHP}/fpm/pool.d/www.conf.disabled"
fi
systemctl enable --now "php${PHP}-fpm"
systemctl reload "php${PHP}-fpm"

log "Nginx"
install -m 644 "$SRC/nginx/cliphunter-headers.conf" /etc/nginx/snippets/cliphunter-headers.conf
install -m 644 "$SRC/nginx/cliphunter-php.conf" /etc/nginx/snippets/cliphunter-php.conf
rm -f /etc/nginx/sites-enabled/default
if [[ -f "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" ]]; then
    sed -e "s#__DOMAIN__#$DOMAIN#g" -e "s#__APP__#$APP#g" "$SRC/nginx/cliphunter.conf" > /etc/nginx/sites-available/cliphunter.conf
else
    # Bootstrap: HTTP only, just enough for the ACME challenge.
    cat > /etc/nginx/sites-available/cliphunter.conf <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN;
    server_tokens off;
    location ^~ /.well-known/acme-challenge/ { root /var/www/certbot; default_type text/plain; }
    location / { return 503; }
}
EOF
fi
ln -sf /etc/nginx/sites-available/cliphunter.conf /etc/nginx/sites-enabled/cliphunter.conf
nginx -t
systemctl enable --now nginx
systemctl reload nginx

log "TLS certificate (Let's Encrypt, HTTP-01 webroot)"
if [[ ! -f "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" ]]; then
    if [[ -n "$LE_EMAIL" ]]; then account=(--email "$LE_EMAIL" --no-eff-email); else account=(--register-unsafely-without-email); fi
    certbot certonly --webroot -w /var/www/certbot -d "$DOMAIN" "${account[@]}" --agree-tos --non-interactive
    sed -e "s#__DOMAIN__#$DOMAIN#g" -e "s#__APP__#$APP#g" "$SRC/nginx/cliphunter.conf" > /etc/nginx/sites-available/cliphunter.conf
    nginx -t
    systemctl reload nginx
fi
mkdir -p /etc/letsencrypt/renewal-hooks/deploy
printf '#!/bin/sh\nsystemctl reload nginx\n' > /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh
chmod 755 /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh

log "systemd units and logrotate"
install -m 644 "$SRC"/systemd/cliphunter-* /etc/systemd/system/
install -m 644 "$SRC/logrotate/cliphunter" /etc/logrotate.d/cliphunter
systemctl daemon-reload
systemctl enable cliphunter-cleanup.timer cliphunter-ytdlp-update.timer cliphunter-worker@1.service

log "Automatic security updates"
echo 'APT::Periodic::Update-Package-Lists "1"; APT::Periodic::Unattended-Upgrade "1";' > /etc/apt/apt.conf.d/20auto-upgrades

log "Done. Next: create $APP/shared/.env and run deploy/deploy.sh <git-ref>."
