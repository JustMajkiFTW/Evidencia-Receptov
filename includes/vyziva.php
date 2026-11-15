<?php
/**
 * Výživové hodnoty (verzia 3.0).
 *
 * Ku každej surovine sa v tabuľke suroviny_vyziva drží všeobecný odhad na 100 g
 * (kcal, bielkoviny, sacharidy, z toho cukry, tuky, z toho nasýtené, vláknina, soľ)
 * a údaje na prepočet jednotiek: hustota (g na 1 ml), hmotnosť kusu a balenia.
 * Z nich sa recept dopočíta na porciu — hodnoty sa odhadnú raz na surovinu a platia
 * vo všetkých receptoch, úprava receptu ani iný počet porcií AI nepotrebuje.
 *
 * Hodnoty dopĺňa AI (rovnaké kľúče ako recept z fotky): po uložení receptu (len jeho
 * suroviny, až po odpovedi), cronom a tlačidlom v Správe → Suroviny. Ručne opravené
 * hodnoty (zdroj = 'rucne') AI neprepíše. Bez tabuľky (nespustená migrácia 3.0)
 * appka beží ďalej, len výživu neukazuje.
 */

require_once __DIR__ . '/ai.php';

/** Hodnoty na 100 g v poradí, v akom sa ukazujú. */
const VYZIVA_POLIA = ['kcal', 'bielkoviny', 'sacharidy', 'cukry', 'tuky', 'nasytene', 'vlaknina', 'sol'];
const VYZIVA_NAZVY = [
    'kcal' => 'Energia', 'bielkoviny' => 'Bielkoviny', 'sacharidy' => 'Sacharidy', 'cukry' => 'z toho cukry',
    'tuky' => 'Tuky', 'nasytene' => 'z toho nasýtené', 'vlaknina' => 'Vláknina', 'sol' => 'Soľ',
];
/** Najvyššia rozumná hodnota na 100 g (kontrola odpovede AI aj ručného zadania). */
const VYZIVA_MAX = ['kcal' => 900, 'bielkoviny' => 100, 'sacharidy' => 100, 'cukry' => 100, 'tuky' => 100,
    'nasytene' => 100, 'vlaknina' => 100, 'sol' => 100];
const VYZIVA_DAVKA_AI = 40; // surovín v jednej otázke na AI

/** Existuje tabuľka suroviny_vyziva? (migrácia 3.0) */
function vyziva_available(PDO $pdo): bool
{
    static $available = null;
    if ($available === null) {
        try {
            $pdo->query('SELECT surovina_id FROM suroviny_vyziva LIMIT 0');
            $available = true;
        } catch (Throwable $e) {
            $available = false;
        }
    }
    return $available;
}

/** Všetky uložené hodnoty: [surovina_id => [kcal => …, hustota => …, gramy_kus => ?float, zdroj => …]]. */
function vyziva_vsetky(PDO $pdo): array
{
    if (!vyziva_available($pdo)) {
        return [];
    }
    $vysledok = [];
    foreach ($pdo->query('SELECT * FROM suroviny_vyziva')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $riadok = [];
        foreach (VYZIVA_POLIA as $pole) {
            $riadok[$pole] = (float) $r[$pole];
        }
        $riadok['hustota'] = (float) $r['hustota'] > 0 ? (float) $r['hustota'] : 1.0;
        $riadok['gramy_kus'] = $r['gramy_kus'] !== null ? (float) $r['gramy_kus'] : null;
        $riadok['gramy_balenie'] = $r['gramy_balenie'] !== null ? (float) $r['gramy_balenie'] : null;
        $riadok['zdroj'] = $r['zdroj'];
        $vysledok[(int) $r['surovina_id']] = $riadok;
    }
    return $vysledok;
}

/**
 * Koľko gramov je dávka suroviny (2 PL, 3 ks, 0,5 l…). null = nedá sa určiť
 * (bez množstva, „podľa chuti", kusy bez známej hmotnosti kusu).
 */
function vyziva_gramy(?float $mnozstvo, ?string $jednotka, array $v): ?float
{
    if ($mnozstvo === null || $mnozstvo <= 0) {
        return null;
    }
    $hustota = (float) ($v['hustota'] ?? 1.0) ?: 1.0;
    return match ((string) $jednotka) {
        'g'       => $mnozstvo,
        'kg'      => $mnozstvo * 1000,
        'ml'      => $mnozstvo * $hustota,
        'dl'      => $mnozstvo * 100 * $hustota,
        'l'       => $mnozstvo * 1000 * $hustota,
        'PL'      => $mnozstvo * 15 * $hustota,
        'ČL'      => $mnozstvo * 5 * $hustota,
        'šálka'   => $mnozstvo * 250 * $hustota,
        'štipka'  => $mnozstvo * 0.5,
        '', 'ks', 'plátok', 'strúčik' => isset($v['gramy_kus']) && $v['gramy_kus'] > 0 ? $mnozstvo * $v['gramy_kus'] : null,
        'balenie' => isset($v['gramy_balenie']) && $v['gramy_balenie'] > 0 ? $mnozstvo * $v['gramy_balenie'] : null,
        default   => null,
    };
}

/**
 * Výživové hodnoty receptu na porciu (alebo na celý recept, keď nemá porcie).
 * $suroviny = riadky z get_all_recipe_suroviny() (id, nazov, mnozstvo, jednotka).
 * Návrat null, keď sa nedala zarátať ani jedna surovina.
 */
function vyziva_receptu(array $suroviny, ?int $porcie, array $vyziva): ?array
{
    if (!$suroviny || !$vyziva) {
        return null;
    }
    $sucet = array_fill_keys(VYZIVA_POLIA, 0.0);
    $zaratane = 0;
    $bezHodnot = [];
    $bezMnozstva = [];
    foreach ($suroviny as $s) {
        $v = $vyziva[(int) $s['id']] ?? null;
        if ($v === null) {
            $bezHodnot[] = $s['nazov'];
            continue;
        }
        $gramy = vyziva_gramy($s['mnozstvo'] !== null ? (float) $s['mnozstvo'] : null, $s['jednotka'] ?? '', $v);
        if ($gramy === null) {
            $bezMnozstva[] = $s['nazov'];
            continue;
        }
        foreach (VYZIVA_POLIA as $pole) {
            $sucet[$pole] += $v[$pole] * $gramy / 100;
        }
        $zaratane++;
    }
    if ($zaratane === 0) {
        return null;
    }
    $delitel = ($porcie !== null && $porcie > 0) ? $porcie : 1;
    $hodnoty = [];
    foreach (VYZIVA_POLIA as $pole) {
        $hodnota = $sucet[$pole] / $delitel;
        $hodnoty[$pole] = $pole === 'kcal' ? (int) round($hodnota) : round($hodnota, $pole === 'sol' ? 2 : 1);
    }
    return [
        'na'           => ($porcie !== null && $porcie > 0) ? 'porciu' : 'recept',
        'hodnoty'      => $hodnoty,
        'zaratane'     => $zaratane,
        'spolu'        => count($suroviny),
        'bez_hodnot'   => $bezHodnot,
        'bez_mnozstva' => $bezMnozstva,
    ];
}

/** „g" číslo po slovensky: 12,5 / 3 / 0,25 */
function vyziva_cislo(float $cislo): string
{
    return str_replace('.', ',', rtrim(rtrim(number_format($cislo, 2, '.', ''), '0'), '.'));
}

/** Jeden riadok textu pre asistenta a zdieľanie: „520 kcal, bielkoviny 35 g, …". */
function vyziva_text(array $v): string
{
    $h = $v['hodnoty'];
    return $h['kcal'] . ' kcal, bielkoviny ' . vyziva_cislo($h['bielkoviny']) . ' g, sacharidy ' . vyziva_cislo($h['sacharidy'])
        . ' g (z toho cukry ' . vyziva_cislo($h['cukry']) . ' g), tuky ' . vyziva_cislo($h['tuky']) . ' g (z toho nasýtené '
        . vyziva_cislo($h['nasytene']) . ' g), vláknina ' . vyziva_cislo($h['vlaknina']) . ' g, soľ ' . vyziva_cislo($h['sol']) . ' g';
}

/* ----------------------------------------------------------------------
   Uloženie hodnôt (AI aj ručne)
   ---------------------------------------------------------------------- */

/**
 * Skontroluje a uloží hodnoty jednej suroviny. $data môže prísť od AI (čísla) aj z formulára
 * (text s čiarkou). Návrat: null = uložené, inak text chyby.
 */
function vyziva_uloz(PDO $pdo, int $surovinaId, array $data, string $zdroj): ?string
{
    $cislo = static function ($hodnota): ?float {
        if (is_int($hodnota) || is_float($hodnota)) {
            return (float) $hodnota;
        }
        $hodnota = str_replace([',', ' '], ['.', ''], trim((string) $hodnota));
        return $hodnota !== '' && is_numeric($hodnota) ? (float) $hodnota : null;
    };
    $riadok = [];
    foreach (VYZIVA_POLIA as $pole) {
        $hodnota = $cislo($data[$pole] ?? null);
        if ($hodnota === null || $hodnota < 0 || $hodnota > VYZIVA_MAX[$pole]) {
            return VYZIVA_NAZVY[$pole] . ': zadaj číslo od 0 do ' . VYZIVA_MAX[$pole] . ($pole === 'kcal' ? ' kcal' : ' g') . ' na 100 g.';
        }
        $riadok[$pole] = $hodnota;
    }
    if ($riadok['cukry'] > $riadok['sacharidy'] + 0.05 || $riadok['nasytene'] > $riadok['tuky'] + 0.05) {
        return 'Cukry nemôžu byť viac ako sacharidy a nasýtené tuky viac ako tuky.';
    }
    if ($riadok['bielkoviny'] + $riadok['sacharidy'] + $riadok['tuky'] + $riadok['vlaknina'] > 105) {
        return 'Bielkoviny, sacharidy, tuky a vláknina spolu nemôžu mať viac ako 100 g na 100 g.';
    }
    $hustota = $cislo($data['hustota'] ?? null);
    $riadok['hustota'] = ($hustota !== null && $hustota >= 0.1 && $hustota <= 3) ? $hustota : 1.0;
    foreach (['gramy_kus', 'gramy_balenie'] as $pole) {
        $hodnota = $cislo($data[$pole] ?? null);
        $riadok[$pole] = ($hodnota !== null && $hodnota > 0 && $hodnota <= 10000) ? $hodnota : null; // 0 / prázdne = nevieme
    }

    $stlpce = array_merge(VYZIVA_POLIA, ['hustota', 'gramy_kus', 'gramy_balenie', 'zdroj']);
    $riadok['zdroj'] = $zdroj === 'rucne' ? 'rucne' : 'ai';
    $sql = 'INSERT INTO suroviny_vyziva (surovina_id, ' . implode(', ', $stlpce) . ') VALUES (?' . str_repeat(', ?', count($stlpce)) . ')'
        . ' ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn($s) => $s . ' = VALUES(' . $s . ')', $stlpce));
    if ($zdroj !== 'rucne') {
        // Odhad AI neprepíše ručne opravené hodnoty.
        $sql = 'INSERT IGNORE INTO suroviny_vyziva (surovina_id, ' . implode(', ', $stlpce) . ') VALUES (?' . str_repeat(', ?', count($stlpce)) . ')';
    }
    $pdo->prepare($sql)->execute(array_merge([$surovinaId], array_map(static fn($s) => $riadok[$s], $stlpce)));
    return null;
}

/* ----------------------------------------------------------------------
   Odhad cez AI
   ---------------------------------------------------------------------- */

/**
 * Suroviny bez výživových hodnôt [id => názov]: najprv tie, ktoré sú v receptoch.
 * $ids obmedzí výber (suroviny jedného receptu).
 */
function vyziva_chybajuce(PDO $pdo, ?array $ids = null, int $limit = VYZIVA_DAVKA_AI): array
{
    if (!vyziva_available($pdo)) {
        return [];
    }
    $podmienka = '';
    $parametre = [];
    if ($ids !== null) {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $podmienka = ' AND s.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $parametre = $ids;
    }
    $stmt = $pdo->prepare(
        'SELECT s.id, s.nazov FROM suroviny s LEFT JOIN suroviny_vyziva v ON v.surovina_id = s.id
         WHERE v.surovina_id IS NULL' . $podmienka . '
         ORDER BY EXISTS (SELECT 1 FROM recept_suroviny rs WHERE rs.surovina_id = s.id) DESC, s.nazov
         LIMIT ' . max(1, $limit)
    );
    $stmt->execute($parametre);
    $vysledok = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $vysledok[(int) $r['id']] = $r['nazov'];
    }
    return $vysledok;
}

/** Koľko surovín z receptov ešte nemá hodnoty / koľko ich v receptoch je. */
function vyziva_stav(PDO $pdo): array
{
    if (!vyziva_available($pdo)) {
        return ['v_receptoch' => 0, 'chyba' => 0, 'vsetky_chyba' => 0];
    }
    $r = $pdo->query(
        'SELECT COUNT(DISTINCT rs.surovina_id) AS v_receptoch,
                COUNT(DISTINCT CASE WHEN v.surovina_id IS NULL THEN rs.surovina_id END) AS chyba
         FROM recept_suroviny rs LEFT JOIN suroviny_vyziva v ON v.surovina_id = rs.surovina_id'
    )->fetch(PDO::FETCH_ASSOC);
    $vsetky = (int) $pdo->query('SELECT COUNT(*) FROM suroviny s LEFT JOIN suroviny_vyziva v ON v.surovina_id = s.id WHERE v.surovina_id IS NULL')->fetchColumn();
    return ['v_receptoch' => (int) $r['v_receptoch'], 'chyba' => (int) $r['chyba'], 'vsetky_chyba' => $vsetky];
}

function vyziva_schema(): array
{
    $cislo = ['type' => 'number'];
    $polia = array_fill_keys(array_merge(VYZIVA_POLIA, ['hustota', 'gramy_kus', 'gramy_balenie']), $cislo);
    return [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['suroviny'],
        'properties' => [
            'suroviny' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => array_merge(['id'], array_keys($polia)),
                    'properties' => ['id' => ['type' => 'integer']] + $polia,
                ],
            ],
        ],
    ];
}

function vyziva_pokyny(): string
{
    return <<<TXT
Si pomocník v slovenskej rodinnej appke na recepty. Odhadni výživové hodnoty surovín a vráť JSON podľa schémy.

Pre každú surovinu (id: slovenský názov) vráť jeden riadok s rovnakým id:
- kcal, bielkoviny, sacharidy, cukry (z toho cukry), tuky, nasytene (z toho nasýtené mastné kyseliny), vlaknina, sol (soľ) — na 100 g,
  bežné hodnoty ako v nutričných tabuľkách, pre surovinu tak, ako sa kupuje a dáva do receptu (surové mäso, suchá ryža a cestoviny, múka).
  Cukry nie sú viac ako sacharidy, nasýtené nie viac ako tuky. Voda a čaj majú takmer nuly, soľ má sol = 100.
- hustota: koľko gramov má 1 ml (voda 1, olej 0.92, mlieko 1.03, med 1.4, múka 0.55, cukor kryštál 0.85, ryža 0.85, kakao 0.45);
  pri surovinách, ktoré sa na objem nemerajú, 1.
- gramy_kus: koľko váži jeden kus tak, ako sa surovina v receptoch počíta (vajce 55, cibuľa 100, zemiak 150, cesnak — strúčik 5,
  citrón 120, rožok 45, slanina — plátok 15, toastový chlieb — plátok 25); 0, keď sa na kusy nepočíta.
- gramy_balenie: bežné balenie v slovenskom obchode (droždie 42, prášok do pečiva 12, vanilkový cukor 20, smotana na šľahanie 250,
  maslo 250, cestoviny 500, paradajkový pretlak 70); 0, keď sa v balení nepredáva.
Čísla s desatinnou bodkou. Ak surovinu nepoznáš, odhadni podľa najpodobnejšej.
TXT;
}

/** Odhad AI pre suroviny [id => názov]. Návrat: ['ok' => true, 'suroviny' => [...]] alebo ['ok' => false, 'chyba' => …]. */
function vyziva_ai_odhad(array $suroviny): array
{
    $uloha = "Suroviny (id: názov):\n" . implode("\n", array_map(static fn($id, $nazov) => $id . ': ' . $nazov, array_keys($suroviny), $suroviny));
    $chyby = [];
    $zlyKluc = [];
    foreach (ai_poradie() as $sluzba) {
        foreach (ai_modely($sluzba) as $model) {
            if (isset($zlyKluc[$sluzba])) {
                continue;
            }
            $vysledok = ai_zavolaj($sluzba, $model, vyziva_pokyny(), null, vyziva_schema(), AI_TIMEOUT_SEKUND, $uloha);
            if ($vysledok['ok'] && is_array($vysledok['data']['suroviny'] ?? null)) {
                return ['ok' => true, 'suroviny' => $vysledok['data']['suroviny']];
            }
            if (!empty($vysledok['zly_kluc'])) {
                $zlyKluc[$sluzba] = true;
            }
            $chyby[] = AI_NAZVY[$sluzba] . ': ' . ($vysledok['chyba'] ?? $model . ' — odpoveď bez zoznamu surovín');
        }
    }
    return ['ok' => false, 'chyba' => $chyby ? implode('; ', $chyby) : 'žiadna AI služba nie je nastavená'];
}

/**
 * Doplní cez AI hodnoty surovinám, ktoré ich nemajú ($ids = len tieto, null = všetky, najprv tie z receptov).
 * $davok = najviac koľko otázok na AI (po VYZIVA_DAVKA_AI surovín).
 * Návrat: ['doplnene' => počet, 'chyba' => ?text].
 */
function vyziva_dopln_ai(PDO $pdo, ?array $ids = null, int $davok = 1): array
{
    if (!vyziva_available($pdo) || !ai_dostupne()) {
        return ['doplnene' => 0, 'chyba' => vyziva_available($pdo) ? 'V nastaveniach appky chýba kľúč k AI.' : null];
    }
    @set_time_limit(AI_TIMEOUT_SEKUND * 3 * $davok + 30);
    $doplnene = 0;
    $skusene = [];
    for ($i = 0; $i < $davok; $i++) {
        $chybajuce = array_diff_key(vyziva_chybajuce($pdo, $ids, VYZIVA_DAVKA_AI + count($skusene)), $skusene);
        $chybajuce = array_slice($chybajuce, 0, VYZIVA_DAVKA_AI, true);
        if (!$chybajuce) {
            break;
        }
        $odhad = vyziva_ai_odhad($chybajuce);
        if (!$odhad['ok']) {
            debug_log('VÝŽIVA: AI nedoplnilo hodnoty — ' . $odhad['chyba']);
            return ['doplnene' => $doplnene, 'chyba' => str_contains($odhad['chyba'], '(429)') && ai_limit_minuty(explode('; ', $odhad['chyba']))
                ? AI_HLASKA_LIMIT : 'AI teraz hodnoty neodhadla. Skús to neskôr.'];
        }
        foreach ($odhad['suroviny'] as $riadok) {
            $id = (int) ($riadok['id'] ?? 0);
            if (!isset($chybajuce[$id])) {
                continue;
            }
            $problem = vyziva_uloz($pdo, $id, $riadok, 'ai');
            if ($problem === null) {
                $doplnene++;
            } else {
                debug_log('VÝŽIVA: odhad pre „' . $chybajuce[$id] . '" som neuložil — ' . $problem);
            }
        }
        $skusene += $chybajuce; // čo AI vynechalo alebo poslalo zle, v tomto behu znova neskúšať
    }
    if ($doplnene) {
        debug_log('VÝŽIVA: AI doplnilo hodnoty ' . $doplnene . ' surovinám');
    }
    return ['doplnene' => $doplnene, 'chyba' => null];
}

/** Po uložení receptu: odhad pre jeho suroviny, ktoré ešte nemajú hodnoty (spúšťa sa až po odpovedi). */
function vyziva_dopln_recept(PDO $pdo, int $receptId): void
{
    if ($receptId <= 0 || !vyziva_available($pdo) || !ai_dostupne()) {
        return;
    }
    $stmt = $pdo->prepare('SELECT surovina_id FROM recept_suroviny WHERE recept_id = ?');
    $stmt->execute([$receptId]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if ($ids && vyziva_chybajuce($pdo, $ids, 1)) {
        po_odpovedi(static fn() => vyziva_dopln_ai($pdo, $ids, 2));
    }
}
