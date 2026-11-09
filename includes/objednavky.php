<?php
/**
 * objednavky.php — Objednané jedlo (verzia 2.2, fotky od 2.4).
 *
 * Zvlášť od receptov: jedlo objednané cez Wolt, Bolt Food, Bistro.sk a podobne.
 * Pri každej objednávke je dátum, odkiaľ sa objednávalo, reštaurácia, čo sme
 * mali a nepovinne cena. V appke slúži hlavne na otázku „kedy sme naposledy
 * mali objednané jedlo a čo to bolo".
 *
 * Tabuľka objednane_jedla pribudla v sql/migracia-2.2.sql. Kým migrácia nie je
 * naimportovaná, objednavky_available() vráti false a appka beží ďalej bez tejto
 * časti (ponuka sa neukáže, akcie vrátia vysvetlenie).
 *
 * Objednávky pridáva a upravuje každý prihlásený, zmazať ju môže ten, kto ju
 * zapísal, alebo admin.
 *
 * Fotka (verzia 2.4, stĺpec foto z sql/migracia-2.4.sql): jedna na objednávku,
 * spracuje sa rovnako ako fotka receptu (process_recipe_image_upload — overenie,
 * zmenšenie, JPEG) a uloží do uploads/objfood/. Pri novej fotke, odstránení fotky
 * alebo zmazaní objednávky sa starý súbor zmaže z disku. Bez migrácie 2.4
 * Objednané jedlo funguje ďalej, len bez fotiek.
 *
 * Očakáva, že functions.php je už načítaný (app_today, parse_app_date, debug_log).
 */

/**
 * Odkiaľ bolo jedlo: kľúč uložený v databáze => názov na obrazovke. Poradie = poradie tlačidiel.
 * Verzia 2.3.1: foodora nahradilo Bistro.sk. Staré záznamy s kľúčom, ktorý tu už nie je
 * (napr. „foodora"), sa ukazujú ako Iné (get_objednavky) a v databáze sa nemenia.
 */
const OBJEDNAVKY_SLUZBY = [
    'wolt'   => 'Wolt',
    'bolt'   => 'Bolt Food',
    'bistro' => 'Bistro.sk',
    'ine'    => 'Iné',
];

/** Písmená vo farebnom krúžku služby (Bolt Food a Bistro.sk by inak mali obe „B"). Iné má ikonu obchodu. */
const OBJEDNAVKY_ZNAKY = [
    'wolt'   => 'W',
    'bolt'   => 'B',
    'bistro' => 'Bi',
];

const OBJEDNAVKA_RESTAURACIA_MAX = 150; // ako stĺpec objednane_jedla.restauracia
const OBJEDNAVKA_JEDLO_MAX = 1000;
const OBJEDNAVKA_CENA_MAX = 9999.99;
const OBJEDNAVKY_FOTKY_PRIECINOK = 'uploads/objfood/'; // relatívne ku koreňu appky, ako uploads/recipes/

/** Existuje už tabuľka objednane_jedla? Bez nej appka beží ďalej, len bez Objednaného jedla. */
function objednavky_available(PDO $pdo): bool
{
    static $available = null;
    if ($available === null) {
        try {
            $pdo->query('SELECT 1 FROM objednane_jedla LIMIT 1');
            $available = true;
        } catch (Throwable $e) {
            debug_log('OBJEDNAVKY: chýba tabuľka objednane_jedla, spusti sql/migracia-2.2.sql (' . $e->getMessage() . ')');
            $available = false;
        }
    }
    return $available;
}

/** Má tabuľka objednane_jedla už stĺpec foto (migracia-2.4.sql)? */
function objednavky_maju_fotky(PDO $pdo): bool
{
    static $maju = null;
    if ($maju === null) {
        $maju = false;
        if (objednavky_available($pdo)) {
            try {
                $pdo->query('SELECT foto FROM objednane_jedla LIMIT 1');
                $maju = true;
            } catch (Throwable $e) {
                debug_log('OBJEDNAVKY: chýba stĺpec objednane_jedla.foto, spusti sql/migracia-2.4.sql (' . $e->getMessage() . ')');
            }
        }
    }
    return $maju;
}

/**
 * Cena z formulára: „24,90", „24.90", „24,90 €", „ 7 " → float. Prázdne = null.
 * Návrat: ['ok' => bool, 'cena' => ?float]
 */
function parse_objednavka_cena($value): array
{
    $text = trim(str_replace(['€', ' ', "\u{00A0}"], '', (string) ($value ?? '')));
    if ($text === '') {
        return ['ok' => true, 'cena' => null];
    }
    $text = str_replace(',', '.', $text);
    if (!preg_match('/^\d{1,4}(\.\d{1,2})?$/', $text)) {
        return ['ok' => false, 'cena' => null];
    }
    $cena = round((float) $text, 2);
    return ['ok' => $cena <= OBJEDNAVKA_CENA_MAX, 'cena' => $cena];
}

/** Text z formulára orezaný na najviac $max znakov; prázdny = null. */
function objednavka_text($value, int $max): ?string
{
    // Viac medzier za sebou → jedna, riadky ostávajú. Neplatné UTF-8 (preg_replace vráti null) = prázdne.
    $text = trim(preg_replace('/[ \t]+/u', ' ', str_replace("\r\n", "\n", (string) ($value ?? ''))) ?? '');
    if ($text === '') {
        return null;
    }
    return mb_substr($text, 0, $max);
}

/**
 * Všetky objednávky od najnovšej. Každá má aj meno toho, kto ju zapísal, a či ju
 * aktuálny používateľ môže zmazať (autor alebo admin).
 */
function get_objednavky(PDO $pdo, ?int $userId, bool $jeAdmin): array
{
    if (!objednavky_available($pdo)) {
        return [];
    }
    $foto = objednavky_maju_fotky($pdo) ? 'o.foto' : 'NULL';
    $rows = $pdo->query(
        "SELECT o.id, o.datum, o.sluzba, o.restauracia, o.jedlo, o.cena, $foto AS foto, o.pridal,
                COALESCE(NULLIF(TRIM(u.full_name), ''), u.username) AS pridal_meno
         FROM objednane_jedla o
         LEFT JOIN users u ON u.id = o.pridal
         ORDER BY o.datum DESC, o.id DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    return array_map(static fn(array $r): array => [
        'id'          => (int) $r['id'],
        'datum'       => $r['datum'],
        'sluzba'      => isset(OBJEDNAVKY_SLUZBY[$r['sluzba']]) ? $r['sluzba'] : 'ine',
        'restauracia' => $r['restauracia'] ?? '',
        'jedlo'       => $r['jedlo'] ?? '',
        'cena'        => $r['cena'] !== null ? (float) $r['cena'] : null,
        'foto'        => $r['foto'] ?? '',
        'pridal'      => $r['pridal_meno'] ?? '',
        'mozeZmazat'  => $jeAdmin || ($userId !== null && (int) $r['pridal'] === $userId),
    ], $rows);
}

/**
 * Pridá novú objednávku ($id = 0) alebo upraví existujúcu.
 * $foto = nahraný súbor z $_FILES (alebo null), $odstranitFotku = zmazať doterajšiu fotku.
 * Fotka sa spracuje až po kontrole ostatných polí, aby po chybe neostal na disku zbytočný súbor.
 * Návrat: ['success' => bool, 'error' => ?string, 'id' => int]
 */
function save_objednavka(PDO $pdo, int $id, int $userId, array $vstup, ?array $foto = null, bool $odstranitFotku = false): array
{
    if (!objednavky_available($pdo)) {
        return ['success' => false, 'error' => 'Objednané jedlo ešte nie je v databáze pripravené (sql/migracia-2.2.sql).'];
    }

    $restauracia = objednavka_text($vstup['restauracia'] ?? null, OBJEDNAVKA_RESTAURACIA_MAX);
    $jedlo = objednavka_text($vstup['jedlo'] ?? null, OBJEDNAVKA_JEDLO_MAX);
    if ($restauracia === null && $jedlo === null) {
        return ['success' => false, 'error' => 'Napíš reštauráciu alebo čo ste mali.'];
    }

    $datumText = trim((string) ($vstup['datum'] ?? ''));
    $datum = $datumText === '' ? app_today() : parse_app_date($datumText);
    if ($datum === null) {
        return ['success' => false, 'error' => 'Dátum nesedí. Vyber deň, keď ste jedlo mali (nie v budúcnosti).'];
    }

    $cena = parse_objednavka_cena($vstup['cena'] ?? null);
    if (!$cena['ok']) {
        return ['success' => false, 'error' => 'Cena musí byť číslo v eurách, napr. 24,90.'];
    }

    $sluzba = (string) ($vstup['sluzba'] ?? '');
    if (!isset(OBJEDNAVKY_SLUZBY[$sluzba])) {
        $sluzba = 'ine';
    }

    $fotky = objednavky_maju_fotky($pdo);
    $staraFotka = null;
    if ($id > 0) {
        $existuje = $pdo->prepare('SELECT ' . ($fotky ? 'foto' : "''") . ' FROM objednane_jedla WHERE id = ?');
        $existuje->execute([$id]);
        $staraFotka = $existuje->fetchColumn();
        if ($staraFotka === false) {
            return ['success' => false, 'error' => 'Táto objednávka už neexistuje (niekto ju medzitým zmazal).'];
        }
        $staraFotka = $staraFotka ?: null;
    }

    // Fotka: rovnaké spracovanie ako pri receptoch, len do uploads/objfood/.
    $novaFotka = null;
    if ($fotky && $foto !== null && !empty($foto['name'])) {
        $nahratie = process_recipe_image_upload($foto, OBJEDNAVKY_FOTKY_PRIECINOK, 'objfood_');
        if (isset($nahratie['error'])) {
            return ['success' => false, 'error' => $nahratie['error']];
        }
        $novaFotka = $nahratie['path'] ?? null;
    }
    // Ktorá fotka ostane pri objednávke: nová, žiadna (odstránená) alebo doterajšia.
    $fotka = $novaFotka ?? ($odstranitFotku ? null : $staraFotka);

    try {
        if ($id > 0) {
            $sql = 'UPDATE objednane_jedla SET datum = ?, sluzba = ?, restauracia = ?, jedlo = ?, cena = ?' . ($fotky ? ', foto = ?' : '') . ' WHERE id = ?';
            $hodnoty = [$datum, $sluzba, $restauracia, $jedlo, $cena['cena']];
            if ($fotky) {
                $hodnoty[] = $fotka;
            }
            $hodnoty[] = $id;
            $pdo->prepare($sql)->execute($hodnoty);
        } else {
            $sql = 'INSERT INTO objednane_jedla (datum, sluzba, restauracia, jedlo, cena, pridal' . ($fotky ? ', foto' : '') . ')'
                 . ' VALUES (?, ?, ?, ?, ?, ?' . ($fotky ? ', ?' : '') . ')';
            $hodnoty = [$datum, $sluzba, $restauracia, $jedlo, $cena['cena'], $userId];
            if ($fotky) {
                $hodnoty[] = $fotka;
            }
            $pdo->prepare($sql)->execute($hodnoty);
            $id = (int) $pdo->lastInsertId();
        }
    } catch (Throwable $e) {
        delete_recipe_image($novaFotka); // uloženie zlyhalo — nová fotka by na disku len zavadzala
        throw $e;
    }

    // Doterajšiu fotku zmažeme z disku až po úspešnom uložení, keď ju nahradila nová alebo bola odstránená.
    if ($staraFotka !== null && $staraFotka !== $fotka) {
        delete_recipe_image($staraFotka);
    }
    return ['success' => true, 'error' => null, 'id' => $id];
}

/** Zmaže objednávku — len ten, kto ju zapísal, alebo admin (kontrola na serveri, nie len skrytým tlačidlom). */
function delete_objednavka(PDO $pdo, int $id, int $userId, bool $jeAdmin): array
{
    if (!objednavky_available($pdo)) {
        return ['success' => false, 'error' => 'Objednané jedlo ešte nie je v databáze pripravené (sql/migracia-2.2.sql).'];
    }
    $stmt = $pdo->prepare('SELECT pridal, ' . (objednavky_maju_fotky($pdo) ? 'foto' : 'NULL') . ' AS foto FROM objednane_jedla WHERE id = ?');
    $stmt->execute([$id]);
    $objednavka = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$objednavka) {
        return ['success' => false, 'error' => 'Táto objednávka už neexistuje.'];
    }
    if (!$jeAdmin && (int) $objednavka['pridal'] !== $userId) {
        return ['success' => false, 'error' => 'Zmazať ju môže len ten, kto ju zapísal, alebo admin.'];
    }
    $pdo->prepare('DELETE FROM objednane_jedla WHERE id = ?')->execute([$id]);
    delete_recipe_image($objednavka['foto'] ?: null); // fotku z disku až po zmazaní z databázy
    return ['success' => true, 'error' => null];
}
