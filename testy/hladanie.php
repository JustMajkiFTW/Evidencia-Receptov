<?php
/**
 * hladanie.php — test hľadania so skloňovaním a textu upozornenia na nákup (verzia 2.9).
 *
 * Bez databázy overí hladanie_zhoda() (includes/functions.php). S premennými
 * TEST_DB_HOST, TEST_DB_PORT, TEST_DB_USER, TEST_DB_PASS, TEST_DB_NAME overí aj
 * shopping_notify_text() na testovacej databáze — po sebe všetko uprace.
 *
 * Použitie: php testy/hladanie.php   (návratový kód 0 = všetko sedí)
 */

require __DIR__ . '/../includes/functions.php';

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

echo "Hľadanie so skloňovaním\n";
// [otázka, názov receptu, s preklepom?, má nájsť?]
$pripady = [
    ['kuraciu cigánsku', 'Kuracia cigánska', false, true],
    ['Kuraciu ciganskU', 'Kuracia cigánska', false, true],
    ['kuracej cigánskej', 'Kuracia cigánska', false, true],
    ['kurac', 'Kuracia cigánska', false, true],
    ['sviečkovú', 'Sviečková na smotane', false, true],
    ['halušky s bryndzou', 'Bryndzové halušky', false, true],
    ['gulášu', 'Hovädzí guláš', false, true],
    ['bábovku', 'Bábovka', false, true],
    ['pizzu', 'Pizza margherita', false, true],
    ['maku', 'Makový koláč', false, true],
    ['maku', 'Makaróny so syrom', false, false],
    ['sviečková', 'Kuracia cigánska', false, false],
    ['rezne', 'Vyprážaný rezeň', false, false],
    ['rezne', 'Vyprážaný rezeň', true, true],
    ['palaciniek', 'Palacinky', true, true],
    ['kuraciu ciganku', 'Kuracia cigánska', true, true],
    ['sviečková', 'Kuracia cigánska', true, false],
];
foreach ($pripady as [$otazka, $nazov, $podobne, $ocakavane]) {
    over(hladanie_zhoda($otazka, $nazov, $podobne) === $ocakavane,
        '„' . $otazka . '" ' . ($ocakavane ? 'nájde' : 'nenájde') . ' „' . $nazov . '"' . ($podobne ? ' (s preklepom)' : ''));
}

if (getenv('TEST_DB_NAME')) {
    echo "\nUpozornenie na nákup\n";
    $port = getenv('TEST_DB_PORT') ?: '3306';
    $pdo = new PDO('mysql:host=' . (getenv('TEST_DB_HOST') ?: '127.0.0.1') . ';port=' . $port . ';dbname=' . getenv('TEST_DB_NAME') . ';charset=utf8mb4',
        getenv('TEST_DB_USER') ?: 'root', (string) getenv('TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $kto = 987654; // používateľ, ktorý v testovacej databáze nie je — riadky sa dajú bezpečne zmazať
    $pdo->prepare('INSERT INTO recipes (name, created_by) VALUES (?, ?)')->execute(['Kuracia cigánska (test nákupu)', $kto]);
    $receptId = (int) $pdo->lastInsertId();
    try {
        $u = shopping_notify_text($pdo, $kto, 'Majki', $receptId, null, 7);
        over(($u['text'] ?? '') === 'Majki pridal(a) do nákupu suroviny na „Kuracia cigánska (test nákupu)" (7)'
            && $u['tag'] === 'nakup-recept-' . $receptId, 'Recept v nákupe: názov receptu a počet surovín', json_encode($u, JSON_UNESCAPED_UNICODE));
        $u = shopping_notify_text($pdo, $kto, 'Majki', $receptId, null, 2, 'hlasového asistenta');
        over(str_ends_with($u['text'] ?? '', '(2) (cez hlasového asistenta)'), 'Cez asistenta je to v upozornení', json_encode($u, JSON_UNESCAPED_UNICODE));

        foreach (['Mlieko', 'Chlieb'] as $nazov) {
            shopping_add_item($pdo, $kto, null, $nazov, null, null, null);
        }
        $u = shopping_notify_text($pdo, $kto, 'Majki', null, 'Maslo');
        over(($u['text'] ?? '') === 'Majki pridal(a) do nákupu: Mlieko, Chlieb, Maslo' && $u['tag'] === 'nakup-polozky-' . $kto,
            'Viac položiek krátko po sebe je v jednom upozornení', json_encode($u, JSON_UNESCAPED_UNICODE));
        $u = shopping_notify_text($pdo, $kto, 'Majki', null, 'mlieko');
        over(($u['text'] ?? '') === 'Majki pridal(a) do nákupu: Mlieko, Chlieb', 'Rovnaká položka sa v upozornení nezopakuje', json_encode($u, JSON_UNESCAPED_UNICODE));
        $pdo->prepare('UPDATE nakupny_zoznam SET kupene = 1 WHERE pridal = ? AND kluc = ?')->execute([$kto, 'chlieb']);
        $pdo->prepare('UPDATE nakupny_zoznam SET created_at = NOW() - INTERVAL 1 HOUR WHERE pridal = ? AND kluc = ?')->execute([$kto, 'mlieko']);
        $u = shopping_notify_text($pdo, $kto, 'Majki', null, 'Maslo');
        over(($u['text'] ?? '') === 'Majki pridal(a) do nákupu: Maslo', 'Kúpené a staré položky v upozornení nie sú', json_encode($u, JSON_UNESCAPED_UNICODE));
        over(shopping_notify_text($pdo, $kto, 'Majki', null, '  ') === null && shopping_notify_text($pdo, $kto, 'Majki', 999999999, null) === null,
            'Bez položky alebo s neexistujúcim receptom sa nič nepošle');
    } finally {
        $pdo->prepare('DELETE FROM nakupny_zoznam WHERE pridal = ?')->execute([$kto]);
        $pdo->prepare('DELETE FROM recipes WHERE id = ?')->execute([$receptId]);
    }
}

exit($zlyhania > 0 ? 1 : 0);
