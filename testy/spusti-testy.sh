#!/usr/bin/env bash
# Automatický test appky. Spúšťa ho GitHub pri každom nahratí do main a pred
# nahratím novej verzie na server; dá sa spustiť aj na vlastnom počítači.
#
# Čo overí:
#   1. prostredie (PHP 8.4.1+, rozšírenia, vendor/),
#   2. syntax všetkých PHP súborov,
#   3. pravidlá pre novú verziu (testy/kontrola-pravidiel.sh),
#   4. novú inštaláciu: sql/instalacia.sql (aj dvakrát po sebe) a celý priechod appkou,
#   5. databázu z predchádzajúcej verzie BEZ migrácie — appka na nej musí bežať,
#   6. migrácie na tej istej databáze (aj dvakrát po sebe), znova priechod appkou
#      a či sa štruktúra po migrácii zhoduje s novou inštaláciou.
#
# Potrebuje bežiacu MariaDB a účet, ktorý môže zakladať databázy a používateľov:
#   TEST_DB_HOST (predvolene 127.0.0.1), TEST_DB_PORT (3306),
#   TEST_DB_ROOT_USER (root), TEST_DB_ROOT_PASS (prázdne)
# Ďalej: PHP_BIN (predvolene php), TEST_PORT (port appky, 8765),
#        TEST_PONECHAT=1 (testovacie databázy sa na konci nezmažú).
#
# Testovacie databázy sa volajú recepty_test_*; skutočnej databázy sa test nedotkne.

set -uo pipefail
KOREN="$(cd "$(dirname "$0")/.." && pwd)"
cd "$KOREN" || exit 1
# shellcheck source=testy/spolocne.sh
source testy/spolocne.sh

PHP="${PHP_BIN:-php}"
export TEST_DB_HOST="${TEST_DB_HOST:-127.0.0.1}"
export TEST_DB_PORT="${TEST_DB_PORT:-3306}"
ROOT_USER="${TEST_DB_ROOT_USER:-root}"
ROOT_PASS="${TEST_DB_ROOT_PASS:-}"
PORT="${TEST_PORT:-8765}"

APP_DB_USER="recepty_test"
APP_DB_PASS="testovacie-heslo-db"
ADMIN_MENO="testadmin"
ADMIN_HESLO="testovacie-heslo"
CRON_KEY="testovaci-kluc-pre-cron"
DB_NOVA="recepty_test_nova"
DB_STARA="recepty_test_stara"

ZLYHANIA=0
zlyhanie() { chyba "$1"; ZLYHANIA=$((ZLYHANIA + 1)); }

db_root() { TEST_DB_USER="$ROOT_USER" TEST_DB_PASS="$ROOT_PASS" TEST_DB_NAME="" "$PHP" testy/db.php "$@"; }
db_app()  { local nazov="$1"; shift; TEST_DB_USER="$APP_DB_USER" TEST_DB_PASS="$APP_DB_PASS" TEST_DB_NAME="$nazov" "$PHP" testy/db.php "$@"; }

APP=""
SERVER_PID=""
upratanie() {
    [ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null && wait "$SERVER_PID" 2>/dev/null
    if [ "${TEST_PONECHAT:-0}" != "1" ]; then
        db_root sql "DROP DATABASE IF EXISTS \`$DB_NOVA\`" >/dev/null 2>&1
        db_root sql "DROP DATABASE IF EXISTS \`$DB_STARA\`" >/dev/null 2>&1
    fi
    [ -n "$APP" ] && rm -rf "$APP"
}
trap upratanie EXIT

# ---------------------------------------------------------------------------------------
nadpis "Prostredie"
if ! "$PHP" -r 'exit(version_compare(PHP_VERSION, "8.4.1", ">=") ? 0 : 1);'; then
    zlyhanie "Treba PHP 8.4.1 alebo novšie (je $("$PHP" -r 'echo PHP_VERSION;'))."
else
    ok "PHP $("$PHP" -r 'echo PHP_VERSION;')"
fi
for rozsirenie in pdo_mysql mysqli gd curl mbstring openssl; do
    "$PHP" -r "exit(extension_loaded('$rozsirenie') ? 0 : 1);" || zlyhanie "Chýba rozšírenie PHP $rozsirenie."
done
if [ ! -f vendor/autoload.php ]; then
    zlyhanie "Chýba vendor/ — spusti najprv 'composer install --no-dev'."
    exit 1
fi
if ! db_root sql "SELECT VERSION()" >/dev/null; then
    zlyhanie "K databáze sa nedá pripojiť (TEST_DB_HOST=$TEST_DB_HOST, TEST_DB_PORT=$TEST_DB_PORT, TEST_DB_ROOT_USER=$ROOT_USER)."
    exit 1
fi
ok "Databáza $(db_root sql "SELECT VERSION()")"
[ "$ZLYHANIA" -gt 0 ] && exit 1

# ---------------------------------------------------------------------------------------
nadpis "Syntax PHP"
POCET_PHP=0
while IFS= read -r -d '' subor; do
    POCET_PHP=$((POCET_PHP + 1))
    if ! vystup=$("$PHP" -l "$subor" 2>&1); then
        zlyhanie "Chyba v syntaxi: $vystup"
    fi
done < <(find . -name '*.php' -not -path './vendor/*' -not -path './.git/*' -print0)
ok "Skontrolovaných PHP súborov: $POCET_PHP"

# ---------------------------------------------------------------------------------------
bash testy/kontrola-pravidiel.sh || ZLYHANIA=$((ZLYHANIA + 1))

# ---------------------------------------------------------------------------------------
# Kópia appky do dočasného priečinka: test tak nezmení tvoj includes/.env, záznam chýb ani fotky.
APP="$(mktemp -d "${TMPDIR:-/tmp}/recepty-test-XXXXXX")"
tar --exclude=./.git --exclude=./includes/.env --exclude=./includes/debug-log.txt \
    --exclude='./uploads/recipes/*' -cf - . | tar -xf - -C "$APP"
mkdir -p "$APP/uploads/recipes"

# Appka nepozná zvlášť port databázy, ale PDO prijme 'host;port=…" v DB_HOST.
APP_DB_HOST="$TEST_DB_HOST"
[ "$TEST_DB_PORT" != "3306" ] && APP_DB_HOST="$TEST_DB_HOST;port=$TEST_DB_PORT"

spusti_server() {
    env DB_HOST="$APP_DB_HOST" DB_NAME="$1" DB_USER="$APP_DB_USER" DB_PASS="$APP_DB_PASS" DB_CHARSET=utf8mb4 \
        APP_HOST=localhost VAPID_PUBLIC_KEY=testovaci-verejny-kluc VAPID_PRIVATE_KEY=testovaci-sukromny-kluc \
        VAPID_SUBJECT=mailto:test@example.com CRON_KEY="$CRON_KEY" APP_DEBUG=1 \
        "$PHP" -d upload_max_filesize=16M -d post_max_size=16M -S "127.0.0.1:$PORT" -t "$APP" >"$APP/server.log" 2>&1 &
    SERVER_PID=$!
    for _ in $(seq 1 50); do
        curl -s -o /dev/null "http://127.0.0.1:$PORT/login.php" && return 0
        sleep 0.2
    done
    zlyhanie "Vstavaný server PHP sa nespustil: $(cat "$APP/server.log")"
    return 1
}

zastav_server() {
    [ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null && wait "$SERVER_PID" 2>/dev/null
    SERVER_PID=""
}

kontrola_logov() {
    local log="$APP/includes/debug-log.txt"
    if [ -s "$log" ]; then
        echo "  Záznam chýb appky (includes/debug-log.txt):"
        sed 's/^/      /' "$log"
        if grep -qE 'UNCAUGHT EXCEPTION|FATAL SHUTDOWN|PHP ERROR|AJAX EXCEPTION|[Zz]lyhal|ZLYHAL' "$log"; then
            zlyhanie "V zázname chýb appky sú chyby (výpis vyššie)."
        else
            ok "V zázname sú len informácie, žiadne chyby"
        fi
    else
        ok "Záznam chýb appky je prázdny"
    fi
    if grep -qE 'PHP (Fatal error|Parse error|Warning|Notice)' "$APP/server.log"; then
        grep -E 'PHP (Fatal error|Parse error|Warning|Notice|Deprecated)' "$APP/server.log" | sed 's/^/      /'
        zlyhanie "PHP vypísal chyby alebo varovania (výpis vyššie)."
    fi
    : > "$log"
}

prechod_appkou() { # $1 = databáza, $2 = režim pre smoke.php
    spusti_server "$1" || return
    if "$PHP" testy/smoke.php "http://127.0.0.1:$PORT" "$ADMIN_MENO" "$ADMIN_HESLO" "$2" "$CRON_KEY"; then
        :
    else
        zlyhanie "Priechod appkou ($2) má zlyhané kontroly (výpis vyššie)."
    fi
    zastav_server
    kontrola_logov
}

priprav_databazu() {
    db_root sql "DROP DATABASE IF EXISTS \`$1\`" &&
    db_root sql "CREATE DATABASE \`$1\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci" &&
    for hostitel in '%' 'localhost'; do
        db_root sql "CREATE USER IF NOT EXISTS '$APP_DB_USER'@'$hostitel' IDENTIFIED BY '$APP_DB_PASS'" &&
        db_root sql "GRANT ALL PRIVILEGES ON \`$1\`.* TO '$APP_DB_USER'@'$hostitel'" || return 1
    done
}

# Import, ktorý musí prejsť aj druhýkrát po sebe bez chyby a bez zdvojenia dát.
import_dvakrat() { # $1 = databáza, $2 = súbor, $3 = popis
    if ! db_app "$1" import "$2"; then
        zlyhanie "$3: zlyhalo."
        return 1
    fi
    ok "$3: prebehlo bez chyby"
    local pred po
    pred=$(db_app "$1" pocty)
    if ! db_app "$1" import "$2"; then
        zlyhanie "$3: druhé spustenie zlyhalo."
        return 1
    fi
    po=$(db_app "$1" pocty)
    if [ "$pred" = "$po" ]; then
        ok "$3: dá sa spustiť aj opakovane, nič sa nezdvojí"
    else
        zlyhanie "$3: opakované spustenie zmenilo počty riadkov: $(diff <(echo "$pred") <(echo "$po") | grep '^[<>]' | tr '\n' ' ')"
    fi
}

# ---------------------------------------------------------------------------------------
nadpis "Nová inštalácia (sql/instalacia.sql)"
if priprav_databazu "$DB_NOVA" && import_dvakrat "$DB_NOVA" sql/instalacia.sql "Import sql/instalacia.sql"; then
    db_app "$DB_NOVA" admin "$ADMIN_MENO" "$ADMIN_HESLO"
    prechod_appkou "$DB_NOVA" plny
else
    zlyhanie "Testovaciu databázu sa nepodarilo pripraviť."
fi

# ---------------------------------------------------------------------------------------
PREDOSLA="${PREDOSLA_VERZIA:-$(predosla_verzia)}"
STARA_SCHEMA="$APP/stara-instalacia.sql"
if [ -z "$PREDOSLA" ]; then
    nadpis "Databáza z predchádzajúcej verzie"
    pozor "Nenašiel som predchádzajúcu verziu (tag), test bez migrácie sa preskočí."
elif ! git show "$PREDOSLA:sql/instalacia.sql" >"$STARA_SCHEMA" 2>/dev/null; then
    nadpis "Databáza z predchádzajúcej verzie"
    pozor "Verzia $PREDOSLA nemá sql/instalacia.sql, test bez migrácie sa preskočí."
else
    nadpis "Databáza z verzie $PREDOSLA bez migrácie"
    if priprav_databazu "$DB_STARA" && db_app "$DB_STARA" import "$STARA_SCHEMA"; then
        ok "Naimportovaná databáza z verzie $PREDOSLA"
        db_app "$DB_STARA" admin "$ADMIN_MENO" "$ADMIN_HESLO"
        prechod_appkou "$DB_STARA" bez-migracie

        MIGRACIE=()
        while IFS= read -r subor; do
            [ -z "$subor" ] && continue
            git cat-file -e "$PREDOSLA:$subor" 2>/dev/null || MIGRACIE+=("$subor")
        done < <(printf '%s\n' sql/migracia-*.sql | grep -v '\*' | sort -V)

        nadpis "Migrácia databázy z verzie $PREDOSLA"
        if [ ${#MIGRACIE[@]} -eq 0 ]; then
            ok "Nová verzia nemá žiadnu migráciu"
        else
            for subor in "${MIGRACIE[@]}"; do
                import_dvakrat "$DB_STARA" "$subor" "Migrácia $subor"
            done
            prechod_appkou "$DB_STARA" plny
        fi

        nadpis "Štruktúra po migrácii = nová inštalácia"
        if diff_vystup=$(diff <(db_app "$DB_NOVA" struktura) <(db_app "$DB_STARA" struktura)); then
            ok "Databáza po migrácii má rovnakú štruktúru ako nová inštalácia"
        else
            echo "  Rozdiely (< nová inštalácia, > databáza po migrácii):"
            echo "$diff_vystup" | grep '^[<>#]' | sed 's/^/      /'
            zlyhanie "Štruktúra po migrácii sa líši od sql/instalacia.sql — doplň migráciu alebo instalacia.sql."
        fi
    else
        zlyhanie "Databázu z verzie $PREDOSLA sa nepodarilo naimportovať."
    fi
fi

# ---------------------------------------------------------------------------------------
nadpis "Výsledok"
if [ "$ZLYHANIA" -eq 0 ]; then
    ok "Všetky testy prešli."
    exit 0
fi
chyba "Počet zlyhaní: $ZLYHANIA"
exit 1
