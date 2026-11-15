<?php
/**
 * vyziva.php — test výživových hodnôt (verzia 3.0).
 *
 * Bez databázy overí prepočet dávok na gramy a výpočet receptu na porciu
 * (includes/vyziva.php). S premennými TEST_DB_HOST, TEST_DB_PORT, TEST_DB_USER,
 * TEST_DB_PASS, TEST_DB_NAME overí aj ukladanie hodnôt (kontrola, ručné hodnoty
 * AI neprepíše), poradie surovín na doplnenie a text pre asistenta v zobraz_recept.
 * Po sebe všetko uprace.
 *
 * Použitie: php testy/vyziva.php   (návratový kód 0 = všetko sedí)
 */

if (!defined('APP_HOST')) {
    define('APP_HOST', 'localhost'); // odkaz na recept v zobraz_recept
}
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/mcp.php';

$zlyhania = 0;
function over(bool $podmienka, string $popis, string $detail = ''): void
{
    global $zlyhania;
    if ($podmienka) {
        echo "  ✔ $popis\n";
    } else {
        $zlyhania++;
        echo "  ✘ $popis" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

echo "Výživové hodnoty — prepočet\n";
$olej = ['hustota' => 0.92, 'gramy_kus' => null, 'gramy_balenie' => null];
$vajce = ['hustota' => 1.0, 'gramy_kus' => 55.0, 'gramy_balenie' => null];
$drozdie = ['hustota' => 1.0, 'gramy_kus' => null, 'gramy_balenie' => 42.0];
over(vyziva_gramy(0.5, 'kg', $olej) === 500.0 && vyziva_gramy(200.0, 'g', $olej) === 200.0, 'g a kg');
over(abs(vyziva_gramy(2.0, 'PL', $olej) - 27.6) < 0.001 && abs(vyziva_gramy(1.0, 'dl', $olej) - 92.0) < 0.001, 'Lyžice a dl podľa hustoty (olej 0,92 g/ml)');
over(vyziva_gramy(3.0, 'ks', $vajce) === 165.0 && vyziva_gramy(2.0, '', $vajce) === 110.0, 'Kusy podľa hmotnosti kusu (aj bez jednotky)');
over(vyziva_gramy(1.0, 'balenie', $drozdie) === 42.0 && vyziva_gramy(1.0, 'balenie', $vajce) === null, 'Balenie len so známou hmotnosťou');
over(vyziva_gramy(null, 'g', $olej) === null && vyziva_gramy(2.0, 'ks', $olej) === null, 'Bez množstva alebo bez hmotnosti kusu sa nezaráta');

$hodnoty = static fn(float $kcal, float $b, float $s, float $t) => ['kcal' => $kcal, 'bielkoviny' => $b, 'sacharidy' => $s, 'cukry' => 0.0,
    'tuky' => $t, 'nasytene' => 0.0, 'vlaknina' => 0.0, 'sol' => 0.0, 'hustota' => 1.0, 'gramy_kus' => 50.0, 'gramy_balenie' => null];
$vyziva = [1 => $hodnoty(350, 10, 72, 1), 2 => $hodnoty(150, 12.5, 1, 10)];
$suroviny = [
    ['id' => 1, 'nazov' => 'Múka hladká', 'mnozstvo' => 200.0, 'jednotka' => 'g'],
    ['id' => 2, 'nazov' => 'Vajcia', 'mnozstvo' => 2.0, 'jednotka' => 'ks'],
    ['id' => 1, 'nazov' => 'Múka hladká', 'mnozstvo' => null, 'jednotka' => ''],
    ['id' => 3, 'nazov' => 'Soľ', 'mnozstvo' => 1.0, 'jednotka' => 'štipka'],
];
$v = vyziva_receptu($suroviny, 4, $vyziva);
// múka 200 g = 700 kcal, 20 g B; vajcia 100 g = 150 kcal, 12,5 g B → (850 kcal, 32,5 g B) / 4 porcie
over(($v['hodnoty']['kcal'] ?? 0) === 213 && abs(($v['hodnoty']['bielkoviny'] ?? 0) - 8.1) < 0.001 && $v['na'] === 'porciu',
    'Recept na porciu: 213 kcal, 8,1 g bielkovín', json_encode($v, JSON_UNESCAPED_UNICODE));
over($v['zaratane'] === 2 && $v['spolu'] === 4 && $v['bez_hodnot'] === ['Soľ'] && $v['bez_mnozstva'] === ['Múka hladká'],
    'Vie, ktoré suroviny sa nezarátali (bez hodnôt, bez množstva)', json_encode($v, JSON_UNESCAPED_UNICODE));
$v = vyziva_receptu($suroviny, null, $vyziva);
over(($v['hodnoty']['kcal'] ?? 0) === 850 && $v['na'] === 'recept', 'Recept bez porcií: hodnoty celého receptu');
over(vyziva_receptu([['id' => 3, 'nazov' => 'Soľ', 'mnozstvo' => 1.0, 'jednotka' => 'g']], 2, $vyziva) === null
    && vyziva_receptu([], 2, $vyziva) === null, 'Bez zarátanej suroviny sa výživa neukáže');
over(str_starts_with(vyziva_text(vyziva_receptu($suroviny, 4, $vyziva)), '213 kcal, bielkoviny 8,1 g, sacharidy 36,3 g'),
    'Text pre asistenta po slovensky', vyziva_text(vyziva_receptu($suroviny, 4, $vyziva)));

if (getenv('TEST_DB_NAME')) {
    echo "\nVýživové hodnoty — databáza\n";
    $pdo = new PDO('mysql:host=' . (getenv('TEST_DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('TEST_DB_PORT') ?: '3306') . ';dbname='
        . getenv('TEST_DB_NAME') . ';charset=utf8mb4', getenv('TEST_DB_USER') ?: 'root', (string) getenv('TEST_DB_PASS'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    over(vyziva_available($pdo), 'Tabuľka suroviny_vyziva existuje');
    $znacka = 'vyziva-test-' . bin2hex(random_bytes(3));
    $ids = [];
    foreach (["Múka $znacka", "Olej $znacka"] as $nazov) {
        $pdo->prepare('INSERT INTO suroviny (nazov, kluc) VALUES (?, ?)')->execute([$nazov, surovina_key($nazov)]);
        $ids[] = (int) $pdo->lastInsertId();
    }
    $pdo->prepare('INSERT INTO recipes (name, created_by, servings) VALUES (?, 1, 2)')->execute(["Recept $znacka"]);
    $receptId = (int) $pdo->lastInsertId();
    try {
        $pdo->prepare('INSERT INTO recept_suroviny (recept_id, surovina_id, mnozstvo, jednotka, poradie) VALUES (?, ?, 100, ?, 0), (?, ?, 2, ?, 1)')
            ->execute([$receptId, $ids[0], 'g', $receptId, $ids[1], 'PL']);
        $chybajuce = vyziva_chybajuce($pdo, $ids);
        over(array_keys($chybajuce) === $ids || array_keys($chybajuce) === array_reverse($ids), 'Suroviny receptu bez hodnôt sa nájdu na doplnenie',
            json_encode($chybajuce, JSON_UNESCAPED_UNICODE));
        $vsetky = vyziva_chybajuce($pdo, null, 5000);
        over(array_search($ids[0], array_keys($vsetky), true) < 50, 'Suroviny z receptov idú na doplnenie prvé');

        $ai = ['kcal' => 364, 'bielkoviny' => 10, 'sacharidy' => 76, 'cukry' => 0.3, 'tuky' => 1, 'nasytene' => 0.2, 'vlaknina' => 2.7, 'sol' => 0,
            'hustota' => 0.55, 'gramy_kus' => 0, 'gramy_balenie' => 1000];
        over(vyziva_uloz($pdo, $ids[0], $ai, 'ai') === null, 'Odhad AI sa uloží');
        over(str_contains((string) vyziva_uloz($pdo, $ids[1], ['cukry' => 5, 'sacharidy' => 1] + $ai, 'ai'), 'Cukry'),
            'Cukry viac ako sacharidy sa odmietnu');
        over(str_contains((string) vyziva_uloz($pdo, $ids[1], ['kcal' => 'veľa'] + $ai, 'rucne'), 'Energia'), 'Nečíselná hodnota sa odmietne');
        over(vyziva_uloz($pdo, $ids[1], ['kcal' => '884', 'bielkoviny' => '0', 'sacharidy' => '0', 'cukry' => '0', 'tuky' => '100', 'nasytene' => '14',
            'vlaknina' => '0', 'sol' => '0', 'hustota' => '0,92', 'gramy_kus' => '', 'gramy_balenie' => ''], 'rucne') === null,
            'Ručné hodnoty s desatinnou čiarkou sa uložia');
        vyziva_uloz($pdo, $ids[1], ['kcal' => 1] + $ai, 'ai');
        $ulozene = vyziva_vsetky($pdo);
        over(($ulozene[$ids[1]]['kcal'] ?? 0) === 884.0 && $ulozene[$ids[1]]['zdroj'] === 'rucne' && $ulozene[$ids[1]]['gramy_kus'] === null,
            'Odhad AI neprepíše ručne zadané hodnoty', json_encode($ulozene[$ids[1]] ?? null));
        over(isset($ulozene[$ids[0]]) && $ulozene[$ids[0]]['gramy_kus'] === null && $ulozene[$ids[0]]['gramy_balenie'] === 1000.0,
            'Hmotnosť kusu 0 znamená „nevieme"');

        $text = (string) (mcp_zobraz_recept($pdo, ['id' => $receptId])['text'] ?? '');
        // 100 g múky = 364 kcal; 2 PL oleja = 30 ml × 0,92 = 27,6 g = 244 kcal → 608 / 2 porcie = 304
        over(str_contains($text, 'Výživové hodnoty na porciu') && str_contains($text, '304 kcal'), 'Asistent v zobraz_recept dostane výživu na porciu', $text);
    } finally {
        $pdo->prepare('DELETE FROM recipes WHERE id = ?')->execute([$receptId]);
        $pdo->prepare('DELETE FROM suroviny WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')')->execute();
    }
}

exit($zlyhania > 0 ? 1 : 0);
