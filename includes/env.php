<?php
/**
 * env.php — jednoduchý loader .env súboru (bez potreby Composer/knižníc).
 *
 * Načíta riadky v tvare KEY=VALUE z daného súboru a sprístupní ich cez
 * getenv('KEY'). Riadky začínajúce # a prázdne riadky ignoruje. Hodnoty
 * môžu byť v úvodzovkách ("...") alebo bez nich.
 *
 * Ak premenná už existuje v skutočnom prostredí servera (napr. si ju
 * hosting umožňuje nastaviť v administrácii), .env ju NEPREPÍŠE — to dáva
 * prednosť "ozajstným" env premenným pred súborom, ak sú k dispozícii.
 */
function load_env(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        // Odstránenie obklopujúcich úvodzoviek, ak sú prítomné.
        if (strlen($value) >= 2 && (
            ($value[0] === '"' && $value[-1] === '"') ||
            ($value[0] === "'" && $value[-1] === "'")
        )) {
            $value = substr($value, 1, -1);
        }

        // Neprepisovať, ak už premenná v prostredí existuje (napr. nastavená hostingom).
        if (getenv($key) === false) {
            putenv("$key=$value");
        }
    }
}

/** Pomocník: povinná env premenná — zastaví appku so zrozumiteľnou chybou, ak chýba. */
function env_required(string $key): string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        http_response_code(500);
        die("Chýba povinná konfiguračná premenná '$key'. Skontroluj súbor includes/.env na serveri.");
    }
    return $value;
}
