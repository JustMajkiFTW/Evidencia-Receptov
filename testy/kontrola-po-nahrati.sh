#!/usr/bin/env bash
# Kontrola ostrej appky hneď po nahratí na server (spúšťa ju GitHub po nasadení).
#
#   bash testy/kontrola-po-nahrati.sh ADRESA_APPKY PRIECINOK_NAHRATEJ_VERZIE
#
# ADRESA_APPKY je napr. https://recepty.example.com (bez lomky na konci),
# PRIECINOK_NAHRATEJ_VERZIE je priečinok so súbormi, ktoré sa práve nahrali.
#
# Overí, že appka odpovedá, že server naozaj vydáva práve nahratú verziu
# (porovná sw.js) a že súbory s heslami nie sú dostupné z internetu.
# Návratový kód: 0 = v poriadku, 1 = niečo nesedí (GitHub potom pošle e-mail).

set -uo pipefail
# shellcheck source=testy/spolocne.sh
source "$(dirname "$0")/spolocne.sh"

ADRESA="${1%/}"
VERZIA_DIR="${2:-.}"
ZLYHANIA=0
zlyhanie() { chyba "$1"; ZLYHANIA=$((ZLYHANIA + 1)); }

if [ -z "$ADRESA" ]; then
    chyba "Chýba adresa appky (Secret APP_URL)."
    exit 1
fi

DOCASNY="$(mktemp -d)"
trap 'rm -rf "$DOCASNY"' EXIT

# stiahni CESTA → vypíše HTTP kód (000 = server neodpovedá), obsah uloží
# do $DOCASNY/telo a hlavičky do $DOCASNY/hlavicky (presmerovania sa nesledujú)
stiahni() {
    local kod
    : > "$DOCASNY/telo"
    : > "$DOCASNY/hlavicky"
    kod=$(curl -sS --max-time 30 -o "$DOCASNY/telo" -D "$DOCASNY/hlavicky" -w '%{http_code}' \
        -H 'Cache-Control: no-cache' "$ADRESA/$1" 2>/dev/null) || kod="000"
    echo "$kod"
}

nadpis "Kontrola appky po nahratí"

# 0. Server vôbec odpovedá (inak nemá zmysel kontrolovať ďalej).
if [ "$(stiahni "login.php")" = "000" ]; then
    chyba "Appka na adrese z APP_URL neodpovedá (server je nedostupný alebo je adresa nesprávna)."
    exit 1
fi

# 1. Server vydáva práve nahratú verziu. Hosting môže chvíľu držať starú verziu v cache,
#    preto sa skúša opakovane (spolu asi 2 minúty).
SPRAVNA_VERZIA=0
for pokus in $(seq 1 12); do
    kod=$(stiahni "sw.js?kontrola=${GITHUB_RUN_ID:-$RANDOM}-$pokus")
    if [ "$kod" = "200" ] && cmp -s "$DOCASNY/telo" "$VERZIA_DIR/sw.js"; then
        SPRAVNA_VERZIA=1
        break
    fi
    sleep 10
done
if [ "$SPRAVNA_VERZIA" = "1" ]; then
    ok "Server vydáva práve nahratú verziu (sw.js sa zhoduje)"
else
    zlyhanie "Server nevydáva nahratú verziu sw.js (posledná odpoveď HTTP $kod). Skontroluj Secret FTP_DIR — súbory sa možno nahrali do iného priečinka."
fi

# 2. Prihlasovacia stránka sa načíta bez chyby.
kod=$(stiahni "login.php")
if [ "$kod" = "200" ] && grep -q 'Evidencia receptov' "$DOCASNY/telo" && ! grep -q 'Nastala chyba na serveri' "$DOCASNY/telo"; then
    ok "Prihlasovacia stránka sa načíta"
else
    zlyhanie "Prihlasovacia stránka nefunguje (HTTP $kod): $(head -c 400 "$DOCASNY/telo" | tr -s '\n\t ' ' ')"
fi

# 3. Hlavná stránka bez prihlásenia presmeruje na prihlásenie (PHP aj databáza bežia).
kod=$(stiahni "index.php")
if [[ "$kod" =~ ^30[1237]$ ]] && grep -qi '^location:.*login\.php' "$DOCASNY/hlavicky"; then
    ok "Hlavná stránka presmeruje na prihlásenie"
else
    zlyhanie "Hlavná stránka sa nespráva podľa očakávania (HTTP $kod, očakávané presmerovanie na login.php)."
fi

# 4. Súbory s heslami a interné súbory nesmú byť dostupné z internetu.
for cesta in includes/.env includes/config.php includes/debug-log.txt sql/instalacia.sql; do
    kod=$(stiahni "$cesta")
    if [ "$kod" = "403" ] || [ "$kod" = "404" ]; then
        ok "$cesta nie je dostupný z internetu (HTTP $kod)"
    elif [ "$kod" = "200" ] && [ "$cesta" = "includes/config.php" ] && [ ! -s "$DOCASNY/telo" ]; then
        ok "$cesta sa spustí ako PHP a nič nevypíše"
    else
        zlyhanie "$cesta je dostupný z internetu (HTTP $kod)! Skontroluj includes/.htaccess a sql/.htaccess na serveri."
    fi
done

# 5. Sprievodca inštaláciou sa po inštalácii maže.
kod=$(stiahni "install.php")
if [ "$kod" = "404" ] || [ "$kod" = "403" ]; then
    ok "install.php na serveri nie je"
else
    pozor "Na serveri je install.php (HTTP $kod). Nič nepokazí, ale podľa návodu ho treba zmazať cez FTP."
fi

# 6. Vstup pre AI asistenta (mcp.php, od 2.6): v prehliadači 405, bez kľúča odmietne.
#    Bez migrácie 2.6 odpovie 503 — appka beží, len AI asistent je vypnutý (upozornenie, nie chyba).
if [ -f "$VERZIA_DIR/mcp.php" ]; then
    kod=$(stiahni "mcp.php")
    if [ "$kod" = "405" ] && grep -q '"jsonrpc"' "$DOCASNY/telo"; then
        ok "mcp.php odpovedá ako vstup pre AI asistenta"
    else
        zlyhanie "mcp.php neodpovedá ako vstup pre AI asistenta (HTTP $kod): $(head -c 300 "$DOCASNY/telo" | tr -s '\n\t ' ' ')"
    fi
    kod=$(curl -sS --max-time 30 -o "$DOCASNY/telo" -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
        -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' "$ADRESA/mcp.php?kluc=$(printf '0%.0s' $(seq 1 48))" 2>/dev/null) || kod="000"
    if [ "$kod" = "403" ]; then
        ok "mcp.php odmietne neplatnú adresu (HTTP 403)"
    elif [ "$kod" = "503" ]; then
        pozor "AI asistent je vypnutý: na serveri nie je spustená sql/migracia-2.6.sql (phpMyAdmin → Import)."
    else
        zlyhanie "mcp.php s neplatnou adresou odpovedal HTTP $kod namiesto 403: $(head -c 300 "$DOCASNY/telo" | tr -s '\n\t ' ' ')"
    fi
fi

if [ "$ZLYHANIA" -gt 0 ]; then
    chyba "Kontrola po nahratí našla problémy: $ZLYHANIA. Ak appka nefunguje, vráť predchádzajúcu verziu (README, časť Návrat k staršej verzii)."
    exit 1
fi
ok "Appka po nahratí funguje."
exit 0
