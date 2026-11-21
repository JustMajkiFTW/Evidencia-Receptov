#!/usr/bin/env bash
# Príprava appky pri štarte kontajnera (lokálny Docker), potom spustí Apache.
#
#   1. vendor/ — composer install, keď chýba alebo sa zmenil composer.lock,
#   2. priečinky na fotky a kuchárky, do ktorých musí Apache vedieť zapisovať,
#   3. databáza — sql/instalacia.sql a všetky migrácie (dajú sa spúšťať opakovane,
#      takže po stiahnutí novej verzie sa databáza doplní sama),
#   4. lokálny admin (LOKALNY_ADMIN_MENO / LOKALNY_ADMIN_HESLO), ak ešte neexistuje.
#
# Príprava sa dá vypnúť premennou RECEPTY_PRIPRAVA=0.

set -euo pipefail
cd /var/www/html

info() { echo "[recepty] $*"; }

if [ "${RECEPTY_PRIPRAVA:-1}" != "0" ]; then

    # --- 1. Knižnice --------------------------------------------------------------------
    if [ ! -f vendor/autoload.php ] || ! cmp -s composer.lock vendor/.docker-composer.lock; then
        info "Sťahujem knižnice (composer install)…"
        composer install --no-dev --no-interaction --no-progress --optimize-autoloader
        cp composer.lock vendor/.docker-composer.lock
    fi

    # --- 2. Priečinky na zápis ----------------------------------------------------------
    for priecinok in uploads uploads/recipes uploads/objfood kucharky; do
        mkdir -p "$priecinok"
        if ! runuser -u www-data -- test -w "$priecinok"; then
            chown www-data:www-data "$priecinok" 2>/dev/null || chmod a+rwx "$priecinok" 2>/dev/null || true
        fi
    done

    # --- 3. Databáza --------------------------------------------------------------------
    # Pomocník z testov (testy/db.php) importuje SQL rovnako ako phpMyAdmin.
    db() { TEST_DB_HOST="$DB_HOST" TEST_DB_USER="$DB_USER" TEST_DB_PASS="$DB_PASS" TEST_DB_NAME="$DB_NAME" php testy/db.php "$@"; }

    info "Čakám na databázu…"
    for _ in $(seq 1 60); do
        db sql "SELECT 1" >/dev/null 2>&1 && break
        sleep 1
    done
    db sql "SELECT 1" >/dev/null

    info "Databáza: sql/instalacia.sql a migrácie"
    db import sql/instalacia.sql >/dev/null
    while IFS= read -r migracia; do
        db import "$migracia" >/dev/null
    done < <(ls sql/migracia-*.sql 2>/dev/null | sort -V)

    # --- 4. Lokálny admin ---------------------------------------------------------------
    if [ -n "${LOKALNY_ADMIN_MENO:-}" ] && [ -n "${LOKALNY_ADMIN_HESLO:-}" ]; then
        db admin "$LOKALNY_ADMIN_MENO" "$LOKALNY_ADMIN_HESLO" >/dev/null
    fi

    info "Appka je pripravená: http://localhost:${RECEPTY_PORT:-8080} (meno ${LOKALNY_ADMIN_MENO:-–})"
fi

exec "$@"
