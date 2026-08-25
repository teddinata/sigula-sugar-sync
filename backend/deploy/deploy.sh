#!/usr/bin/env bash
#
# SIGULA — Deploy update (dipakai setiap kali ada perubahan kode)
#
# Alur: maintenance mode -> pull -> composer -> migrate -> cache ulang -> live.
# Bila ada langkah yang gagal, aplikasi otomatis dikeluarkan dari maintenance
# supaya tidak tertinggal dalam kondisi "Down".
#
# Pemakaian (dari dalam folder aplikasi, sebagai root):
#   sudo bash deploy/deploy.sh
#   sudo bash deploy/deploy.sh --no-migrate     # lewati migrasi
#   sudo bash deploy/deploy.sh --branch develop
#   sudo bash deploy/deploy.sh --remote fork    # tarik dari remote selain origin
#   sudo bash deploy/deploy.sh --no-frontend    # backend saja, tidak build SPA

set -euo pipefail

BRANCH=""; RUN_MIGRATE=1; BUILD_FRONTEND=1; REMOTE="origin"; PHP_VERSION="${PHP_VERSION:-8.3}"

while [ $# -gt 0 ]; do
    case "$1" in
        --branch)      BRANCH="$2";   shift 2 ;;
        --remote)      REMOTE="$2";   shift 2 ;;
        --no-migrate)  RUN_MIGRATE=0; shift ;;
        --no-frontend) BUILD_FRONTEND=0; shift ;;
        --php)         PHP_VERSION="$2"; shift 2 ;;
        *) echo "Opsi tidak dikenal: $1" >&2; exit 1 ;;
    esac
done

info()  { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
ok()    { printf '\033[0;32m    OK  %s\033[0m\n' "$*"; }
fatal() { printf '\n\033[0;31mGAGAL: %s\033[0m\n' "$*" >&2; exit 1; }

# Selalu bekerja dari root folder aplikasi (satu tingkat di atas deploy/)
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP_DIR"

[ -f artisan ] || fatal "artisan tidak ditemukan di $APP_DIR"
[ -f .env ]    || fatal ".env tidak ditemukan. Jalankan setup-app.sh lebih dulu."

REPO_DIR="$(git -C "$APP_DIR" rev-parse --show-toplevel 2>/dev/null || echo "")"
[ -n "$REPO_DIR" ] || fatal "$APP_DIR bukan bagian dari git repository"

BRANCH="${BRANCH:-$(git -C "$REPO_DIR" rev-parse --abbrev-ref HEAD)}"

# Apa pun yang terjadi, pastikan aplikasi kembali hidup.
kembali_hidup() { php artisan up >/dev/null 2>&1 || true; }
trap kembali_hidup EXIT

info "Mengaktifkan maintenance mode"
php artisan down --retry=15 --render="errors::503" >/dev/null 2>&1 || php artisan down --retry=15
ok "Aplikasi sementara offline"

info "Mengambil kode terbaru (remote: $REMOTE, branch: $BRANCH)"
SEBELUM="$(git -C "$REPO_DIR" rev-parse --short HEAD)"
git -C "$REPO_DIR" fetch --prune "$REMOTE"
git -C "$REPO_DIR" merge --ff-only "$REMOTE/$BRANCH"
SESUDAH="$(git -C "$REPO_DIR" rev-parse --short HEAD)"
ok "$SEBELUM -> $SESUDAH"

info "Memasang dependency"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction
ok "Vendor terbarui"

if [ "$RUN_MIGRATE" -eq 1 ]; then
    # Backup SEBELUM migrasi: sebagian migrasi memindahkan data lama ke struktur
    # baru, jadi ini satu-satunya titik pulih bila hasilnya tidak sesuai.
    info "Backup database sebelum migrasi"
    php artisan sigula:backup-db
    ok "Backup tersimpan di storage/app/backups"

    info "Menjalankan migrasi database"
    php artisan migrate --force --no-interaction
    ok "Migrasi selesai"
else
    info "Migrasi dilewati (--no-migrate)"
fi

info "Menyegarkan cache"
php artisan optimize:clear
php artisan optimize
ok "Config, route, dan event di-cache ulang"

info "Merapikan izin folder"
chown -R www-data:www-data storage bootstrap/cache
ok "storage/ dan bootstrap/cache/ milik www-data"

# Frontend SPA disajikan Nginx dari .output/public pada repo root (bukan folder
# backend), jadi build-nya ikut di sini supaya API dan web selalu sama versinya.
if [ "$BUILD_FRONTEND" -eq 1 ] && [ -f "$REPO_DIR/package.json" ]; then
    info "Membangun frontend SPA"
    ( cd "$REPO_DIR" && npm run build:spa )

    PUBLIC_DIR="$REPO_DIR/.output/public"
    rm -rf "$PUBLIC_DIR"
    mkdir -p "$PUBLIC_DIR"
    cp -r "$REPO_DIR/dist-spa/." "$PUBLIC_DIR/"
    chown -R www-data:www-data "$PUBLIC_DIR"

    VERSI_WEB="$(grep -o '"buildId": *"[^"]*"' "$PUBLIC_DIR/version.json" | cut -d'"' -f4 || echo '?')"
    ok "SPA ter-deploy (build $VERSI_WEB)"
elif [ "$BUILD_FRONTEND" -eq 1 ]; then
    info "package.json tidak ada di repo root — build frontend dilewati"
fi

info "Me-restart PHP-FPM"
systemctl reload "php${PHP_VERSION}-fpm"
ok "OPcache dibersihkan lewat reload PHP-FPM"

info "Menonaktifkan maintenance mode"
php artisan up
trap - EXIT
ok "Aplikasi kembali online"

info "Verifikasi"
HTTP_CODE="$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' http://127.0.0.1/api/v1/auth/me || echo 000)"
if [ "$HTTP_CODE" = "401" ]; then
    ok "API sehat (401 Unauthenticated tanpa token)"
else
    printf '\033[0;33m    !!  /api/v1/auth/me mengembalikan HTTP %s (diharapkan 401)\033[0m\n' "$HTTP_CODE"
    printf '        Cek: tail -50 storage/logs/laravel-*.log\n'
fi

VERSI_API="$(curl -s -H 'Accept: application/json' http://127.0.0.1/api/v1/versi | grep -o '"versi":"[^"]*"' | head -1 | cut -d'"' -f4 || echo '?')"
[ -n "$VERSI_API" ] && ok "Versi aplikasi terpasang: $VERSI_API"

printf '\n\033[0;32mDeploy selesai: %s -> %s\033[0m\n\n' "$SEBELUM" "$SESUDAH"
