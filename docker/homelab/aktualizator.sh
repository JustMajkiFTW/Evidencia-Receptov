#!/bin/sh
# Aktualizátor testovacej appky na domácom serveri (beží v kontajneri docker:cli).
#
# Každých RECEPTY_INTERVAL sekúnd (predvolene 60) sa pozrie na vetvu RECEPTY_VETVA
# (predvolene „test") na GitHube. Keď je tam nový commit:
#   1. stiahne ho (git reset --hard — ručné zmeny v priečinku na serveri sa zahodia;
#      fotky, kuchárky a databáza sú mimo neho a ostanú),
#   2. pri zmene v docker/ appku znova postaví, inak ju len reštartuje
#      (pri štarte sa spustia migrácie a pri zmene composer.lock aj composer install),
#   3. počká, kým appka odpovie, a zapíše nasadene.txt
#      (http://ADRESA/nasadene.txt — ktorý commit práve beží a či je v poriadku),
#   4. ak je nastavené RECEPTY_NTFY, pošle upozornenie cez ntfy.

set -u
cd "$RECEPTY_CESTA" || exit 1
# git, curl (kontrola appky, ntfy) a časové pásmo — obraz docker:cli niektoré z nich nemá.
apk add --no-cache git curl tzdata >/dev/null 2>&1 || echo "[aktualizator] Nepodarilo sa doinštalovať git/curl/tzdata"
git config --global --add safe.directory "$RECEPTY_CESTA"

VETVA="${RECEPTY_VETVA:-test}"
INTERVAL="${RECEPTY_INTERVAL:-60}"
SUBOR="docker/homelab/docker-compose.yml"
COMPOSE="docker compose -f $SUBOR"

info() { echo "[aktualizator] $(date '+%Y-%m-%d %H:%M:%S') $*"; }

zapis_stav() { # $1 = stav
    printf 'vetva: %s\ncommit: %s\nsprava: %s\nstav: %s\ncas: %s\n' "$VETVA" "$(git rev-parse --short HEAD)" \
        "$(git log -1 --format=%s)" "$1" "$(date '+%Y-%m-%d %H:%M:%S')" > nasadene.txt
}

upozorni() { # $1 = nadpis, $2 = text
    [ -z "${RECEPTY_NTFY:-}" ] && return 0
    curl -fsS -m 15 -H "Title: $1" -H "Tags: package" -H "Click: ${RECEPTY_ADRESA:-}" \
        -d "$2" "$RECEPTY_NTFY" >/dev/null 2>&1 || info "Upozornenie cez ntfy sa neodoslalo"
}

# Počká, kým appka po štarte (migrácie, knižnice) odpovie, a ohlási výsledok.
over_a_ohlas() {
    KRATKY="$(git rev-parse --short HEAD)"
    SPRAVA="$(git log -1 --format=%s)"
    # Pýtame sa priamo appky (služba „app" v tej istej sieti), nie stavu z Dockeru:
    # ten po reštarte ešte chvíľu ukazuje starý „healthy".
    OK=0
    sleep 5
    for _ in $(seq 1 60); do
        curl -fsS -m 10 -o /dev/null http://app/login.php 2>/dev/null && { OK=1; break; }
        sleep 5
    done
    if [ "$OK" -eq 1 ]; then
        zapis_stav "nasadené"
        info "Nasadené $KRATKY: $SPRAVA"
        upozorni "Recepty test: $KRATKY" "$SPRAVA"
    else
        zapis_stav "CHYBA — appka po nasadení neodpovedá (docker compose logs app)"
        info "Appka po nasadení $KRATKY neodpovedá"
        upozorni "Recepty test: CHYBA pri $KRATKY" "Appka po nasadení neodpovedá. $SPRAVA"
    fi
}

info "Sledujem vetvu $VETVA každých $INTERVAL s (teraz $(git rev-parse --short HEAD))"
if grep -q '^stav: nasadzujem' nasadene.txt 2>/dev/null; then
    # Predošlý aktualizátor obnovil celý stack aj seba — dokončíme jeho nasadenie.
    over_a_ohlas
elif [ ! -f nasadene.txt ]; then
    zapis_stav "beží"
fi

while true; do
    if git fetch -q origin "$VETVA" 2>/dev/null; then
        STARA="$(git rev-parse HEAD)"
        NOVA="$(git rev-parse FETCH_HEAD)"
        if [ "$STARA" != "$NOVA" ]; then
            ZMENY="$(git diff --name-only "$STARA" "$NOVA" 2>/dev/null || echo docker/homelab/)"
            git checkout -q -f -B "$VETVA" "$NOVA"
            info "Nová verzia $(git rev-parse --short HEAD): $(git log -1 --format=%s)"

            if echo "$ZMENY" | grep -q '^docker/homelab/'; then
                # Zmenilo sa nastavenie stacku alebo tento skript. Kontajner nemôže spoľahlivo
                # obnoviť sám seba, preto to spraví pomocný kontajner mimo stacku. Aktualizátor
                # vytvorí vždy nanovo (--force-recreate), aj keď sa zmenil len tento skript, a nový
                # aktualizátor potom podľa „nasadzujem" v nasadene.txt dokončí kontrolu.
                info "Zmena v docker/homelab/ — obnovujem celý stack"
                zapis_stav "nasadzujem"
                docker run -d --rm --name recepty-test-obnova \
                    -v "$RECEPTY_CESTA:$RECEPTY_CESTA" -w "$RECEPTY_CESTA" \
                    -v /var/run/docker.sock:/var/run/docker.sock \
                    docker:cli sh -c "docker compose -f '$SUBOR' up -d --build --remove-orphans \
                        && docker compose -f '$SUBOR' up -d --force-recreate --no-deps aktualizator" >/dev/null
                sleep 600 # tento kontajner pomocník medzitým nahradí novým
                continue
            elif echo "$ZMENY" | grep -q '^docker/'; then
                info "Zmena v docker/ — staviam appku znova"
                $COMPOSE up -d --build app db
            else
                $COMPOSE restart app
            fi
            over_a_ohlas
        fi
    else
        info "Vetvu $VETVA sa nepodarilo stiahnuť (internet, GitHub?) — skúsim znova"
    fi
    sleep "$INTERVAL"
done
