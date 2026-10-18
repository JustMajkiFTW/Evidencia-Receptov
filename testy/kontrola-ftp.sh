#!/usr/bin/env bash
# Kontrola pred nahratím na server (spúšťa ju nasadenie.yml):
#   - prihlásenie na FTP funguje a spojenie sa dá zašifrovať (FTPS),
#   - v priečinku FTP_DIR už appka je (login.php a sw.js) — poistka, aby sa súbory
#     omylom nenahrali do iného webu na tom istom hostingu.
#
# Údaje berie z premenných FTP_SERVER, FTP_USERNAME, FTP_PASSWORD a FTP_DIR.
# Návratový kód: 0 = v poriadku, 1 = nahrávať sa nebude.

set -uo pipefail
# shellcheck source=testy/spolocne.sh
source "$(dirname "$0")/spolocne.sh"

nadpis "Kontrola FTP pred nahratím"

SERVER="${FTP_SERVER#ftp://}"
SERVER="${SERVER#ftps://}"
SERVER="${SERVER%/}"
PRIECINOK="${FTP_DIR:-}"
PRIECINOK="${PRIECINOK#./}"
PRIECINOK="${PRIECINOK#/}"
[ -n "$PRIECINOK" ] && [ "${PRIECINOK: -1}" != "/" ] && PRIECINOK="$PRIECINOK/"

DOCASNY="$(mktemp -d)"
trap 'rm -rf "$DOCASNY"' EXIT
# Heslo ide cez súbor, nie cez príkazový riadok (nebude ho vidno v zozname procesov).
printf 'machine %s login %s password %s\n' "${SERVER%%:*}" "$FTP_USERNAME" "$FTP_PASSWORD" > "$DOCASNY/netrc"
chmod 600 "$DOCASNY/netrc"

zoznam=$(curl -sS --ssl-reqd --insecure --list-only --max-time 60 \
    --netrc-file "$DOCASNY/netrc" "ftp://$SERVER/$PRIECINOK" 2>"$DOCASNY/chyba")
kod=$?

if [ $kod -ne 0 ]; then
    case $kod in
        6)  chyba "FTP server sa nedá nájsť — skontroluj Secret FTP_SERVER." ;;
        7)  chyba "K FTP serveru sa nedá pripojiť. Skontroluj FTP_SERVER; ak hosting povoľuje FTP len z niektorých krajín alebo adries, GitHub sa nepripojí." ;;
        9)  chyba "Priečinok z FTP_DIR na serveri neexistuje alebo doň FTP účet nemá prístup." ;;
        28) chyba "FTP server neodpovedá (vypršal čas)." ;;
        35|64) chyba "FTP server neumožnil šifrované spojenie (FTPS)." ;;
        67) chyba "FTP prihlásenie zlyhalo — skontroluj FTP_USERNAME a FTP_PASSWORD." ;;
        *)  chyba "FTP zlyhalo (curl $kod): $(cat "$DOCASNY/chyba")" ;;
    esac
    exit 1
fi
ok "Prihlásenie na FTP cez šifrované spojenie funguje"

chyba_appky=0
for subor in login.php sw.js; do
    if printf '%s\n' "$zoznam" | tr -d '\r' | grep -qxF "$subor"; then
        ok "V priečinku na serveri je $subor"
    else
        chyba_appky=1
    fi
done
if [ $chyba_appky -ne 0 ]; then
    chyba "V priečinku z FTP_DIR nie je appka (chýba login.php alebo sw.js), preto sa nič nenahrá. Skontroluj FTP_DIR — musí to byť priečinok, kde na serveri leží index.php appky."
    exit 1
fi
ok "Priečinok na serveri je správny, môže sa nahrávať"
