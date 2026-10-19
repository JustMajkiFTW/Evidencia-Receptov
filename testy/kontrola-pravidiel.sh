#!/usr/bin/env bash
# Kontrola pravidiel projektu oproti predchádzajúcej vydanej verzii (poslednému tagu):
#
#   - zmenil sa súbor, ktorý si prehliadač drží v cache (CSS, JS, offline stránka)
#       → musí byť zvýšený CACHE_NAME v sw.js a číslo za ?v= v index.php / login.php,
#   - zmenil sa composer.lock → upozornenie, že vendor/ treba na server nahrať ručne,
#   - zmenil sa sql/instalacia.sql, ale nepribudla migrácia → upozornenie,
#   - pri vydaní verzie (premenná VERZIA, napr. V1.2.0) → README má záznam v Histórii verzií,
#   - knižnice z CDN v index.php musia byť aj v CDN_ASSETS v sw.js (offline režim), bez ohľadu na tag.
#
# Porovnáva sa s aktuálnym stavom priečinka, takže zachytí aj zmeny, ktoré ešte nie sú v commite.
# Návratový kód: 0 = v poriadku (môžu byť upozornenia), 1 = porušené pravidlo.

set -uo pipefail
cd "$(dirname "$0")/.." || exit 1
# shellcheck source=testy/spolocne.sh
source testy/spolocne.sh

nadpis "Pravidlá pre novú verziu"

# --- Knižnice z CDN pre offline režim ---------------------------------------------------
# Nezávisí od predchádzajúcej verzie: každá adresa z CDN v index.php musí byť aj
# v CDN_ASSETS v sw.js, inak by uložená kópia stránky offline nemala vzhľad.
CDN_CHYBA=0
CDN_POCET=0
while IFS= read -r adresa; do
    [ -z "$adresa" ] && continue
    CDN_POCET=$((CDN_POCET + 1))
    if ! grep -qF "'$adresa'" sw.js; then
        chyba "Knižnica $adresa z index.php chýba v CDN_ASSETS v sw.js — offline by appka nemala vzhľad."
        CDN_CHYBA=1
    fi
done < <(grep -oE 'https://cdn\.jsdelivr\.net/[^"]+' index.php | sort -u)
if [ "$CDN_CHYBA" -eq 0 ]; then
    ok "Knižnice z CDN v index.php sú v sw.js pre offline režim ($CDN_POCET)"
fi

if ! git rev-parse --git-dir >/dev/null 2>&1; then
    pozor "Priečinok nie je git repozitár — kontrola pravidiel sa preskočí."
    exit "$CDN_CHYBA"
fi

PREDOSLA="${PREDOSLA_VERZIA:-$(predosla_verzia)}"
if [ -z "$PREDOSLA" ]; then
    pozor "Nenašiel som žiadnu predchádzajúcu verziu (tag) — kontrola pravidiel sa preskočí."
    exit "$CDN_CHYBA"
fi
echo "  Porovnávam s verziou $PREDOSLA"

PORUSENIA=$CDN_CHYBA
porusenie() { chyba "$1"; PORUSENIA=$((PORUSENIA + 1)); }

zmeneny() { ! git diff --quiet "$PREDOSLA" -- "$1" 2>/dev/null; }

# --- Súbory v cache prehliadača ---------------------------------------------------------
CACHOVANE=(style.css script.js suroviny.js theme.js webauthn-client.js offline.html app-icons/apple-touch-icon.png)
ZMENENE_CACHOVANE=()
for subor in "${CACHOVANE[@]}"; do
    if [ -e "$subor" ] && zmeneny "$subor"; then
        ZMENENE_CACHOVANE+=("$subor")
    fi
done

if [ ${#ZMENENE_CACHOVANE[@]} -eq 0 ]; then
    ok "CSS ani JS sa nezmenili, CACHE_NAME netreba meniť"
else
    echo "  Zmenené súbory v cache prehliadača: ${ZMENENE_CACHOVANE[*]}"
    STARA_CACHE=$(git show "$PREDOSLA:sw.js" 2>/dev/null | grep -oE "CACHE_NAME = '[^']+'" | head -n 1)
    NOVA_CACHE=$(grep -oE "CACHE_NAME = '[^']+'" sw.js | head -n 1)
    if [ -n "$NOVA_CACHE" ] && [ "$STARA_CACHE" = "$NOVA_CACHE" ]; then
        porusenie "CACHE_NAME v sw.js treba zvýšiť (stále $NOVA_CACHE), inak používatelia uvidia starý vzhľad."
    else
        ok "CACHE_NAME v sw.js je zvýšený (${STARA_CACHE:-žiadny} → $NOVA_CACHE)"
    fi

    for subor in "${ZMENENE_CACHOVANE[@]}"; do
        vzor="$(printf '%s' "$subor" | sed 's/\./\\./g')\\?v=[0-9A-Za-z._-]+"
        for stranka in index.php login.php; do
            nove=$(grep -oE "$vzor" "$stranka" 2>/dev/null | sort -u | tr '\n' ' ')
            [ -z "$nove" ] && continue
            stare=$(git show "$PREDOSLA:$stranka" 2>/dev/null | grep -oE "$vzor" | sort -u | tr '\n' ' ')
            if [ "$nove" = "$stare" ]; then
                porusenie "$subor sa zmenil, ale v $stranka ostalo ${nove% } — zvýš číslo za ?v=."
            else
                ok "$stranka načítava novú verziu: ${nove% }"
            fi
        done
    done
fi

# --- Knižnice ---------------------------------------------------------------------------
if zmeneny composer.lock; then
    pozor "composer.lock sa zmenil: automatické nahrávanie priečinok vendor/ nenahrá. Spusti 'composer install --no-dev' a vendor/ nahraj na server ručne cez FTP ešte pred vydaním verzie."
else
    ok "Knižnice (composer.lock) sa nezmenili"
fi

# --- Databáza ---------------------------------------------------------------------------
NOVE_MIGRACIE=()
for subor in sql/migracia-*.sql; do
    [ -e "$subor" ] || continue
    git cat-file -e "$PREDOSLA:$subor" 2>/dev/null || NOVE_MIGRACIE+=("$subor")
done
if zmeneny sql/instalacia.sql && [ ${#NOVE_MIGRACIE[@]} -eq 0 ]; then
    pozor "sql/instalacia.sql sa zmenil, ale nepribudla žiadna sql/migracia-X.Y.sql. Ak ide o novú tabuľku, stĺpec alebo základné dáta, existujúca databáza na serveri ich bez migrácie nedostane."
elif [ ${#NOVE_MIGRACIE[@]} -gt 0 ]; then
    ok "Nové migrácie: ${NOVE_MIGRACIE[*]}"
else
    ok "Databáza sa nemení"
fi

# --- README pri vydaní verzie -----------------------------------------------------------
if [ -n "${VERZIA:-}" ]; then
    XY=$(verzia_xy "$VERZIA")
    if grep -qF "**$XY**" README.md; then
        ok "README obsahuje verziu $XY v Histórii verzií"
    else
        pozor "README: v Histórii verzií chýba záznam **$XY** pre verziu $VERZIA."
    fi
fi

if [ "$PORUSENIA" -gt 0 ]; then
    exit 1
fi
exit 0
