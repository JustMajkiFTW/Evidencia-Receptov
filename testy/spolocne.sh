# Spoločné funkcie pre skripty v priečinku testy/ (načítavajú sa príkazom source).

# Výpis s farbou len v termináli; na GitHube sa chyby a varovania zobrazia aj ako poznámky.
if [ -t 1 ]; then
    FARBA_OK=$'\e[32m'; FARBA_CHYBA=$'\e[31m'; FARBA_POZOR=$'\e[33m'; FARBA_NADPIS=$'\e[1m'; FARBA_KONIEC=$'\e[0m'
else
    FARBA_OK=''; FARBA_CHYBA=''; FARBA_POZOR=''; FARBA_NADPIS=''; FARBA_KONIEC=''
fi

nadpis() { printf '\n%s== %s ==%s\n' "$FARBA_NADPIS" "$*" "$FARBA_KONIEC"; }
ok()     { printf '  %s✔%s %s\n' "$FARBA_OK" "$FARBA_KONIEC" "$*"; }

chyba() {
    printf '  %s✘ %s%s\n' "$FARBA_CHYBA" "$*" "$FARBA_KONIEC"
    if [ "${GITHUB_ACTIONS:-}" = "true" ]; then
        echo "::error::$*"
    fi
}

pozor() {
    printf '  %s! %s%s\n' "$FARBA_POZOR" "$*" "$FARBA_KONIEC"
    if [ "${GITHUB_ACTIONS:-}" = "true" ]; then
        echo "::warning::$*"
    fi
}

# Predchádzajúca vydaná verzia = tag s najvyšším číslom, ktorý neukazuje na aktuálny commit.
# Tagy majú rôzne písmená na začiatku (v1.0.0, V1.1.0), preto sa porovnáva len číslo.
# Bez tagov vráti prázdny reťazec.
predosla_verzia() {
    local aktualny tag
    aktualny=$(git rev-parse HEAD 2>/dev/null) || return 0
    git tag --list 2>/dev/null | while read -r tag; do
        [ -z "$tag" ] && continue
        [ "$(git rev-parse "$tag^{commit}" 2>/dev/null)" = "$aktualny" ] && continue
        printf '%s %s\n' "${tag#[vV]}" "$tag"
    done | sort -V -k1,1 | tail -n 1 | cut -d' ' -f2
}

# Verzia v tvare X.Y z názvu tagu (V1.2.0 → 1.2, v1.3 → 1.3).
verzia_xy() {
    local cislo="${1#[vV]}"
    printf '%s' "$cislo" | awk -F. '{ if (NF >= 2) print $1 "." $2; else print $1 }'
}
