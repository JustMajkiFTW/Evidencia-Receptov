<?php
/**
 * kucharky.php — kuchárky v PDF (verzia 2.8).
 *
 * PDF v priečinku kucharky/ (chránený .htaccess, z internetu nedostupný) appka
 * prečíta po stranách pribalenou knižnicou smalot/pdfparser (includes/kniznice)
 * a text uloží do tabuliek kucharky a kucharky_strany (migracia-2.8.sql).
 * Hlasový asistent aj asistent cez MCP v nich potom hľadajú nástrojom
 * hladaj_v_kucharkach a odporúčajú recepty aj s knihou a stranou.
 *
 * - Nahrať: FTP do kucharky/, alebo Správa → Kuchárky (admin/kucharky.php).
 * - Spracovať: tlačidlo v Správe (po jednom súbore), alebo samo cez cron.php.
 * - Hľadá sa bez ohľadu na diakritiku, slová aj so slovenskými koncovkami
 *   („kuracie" nájde „kurací", „kuracích"): FULLTEXT v režime BOOLEAN so začiatkom slova.
 * - Skenované PDF (obrázky bez textu) sa označia „bez textu" — text z nich nevieme.
 *
 * Priečinok sa dá zmeniť v .env: KUCHARKY_PRIECINOK=/cesta/ku/kucharky
 */

const KUCHARKY_MAX_VELKOST = 200 * 1024 * 1024; // väčšie PDF sa nespracúvajú (pamäť hostingu)
const KUCHARKY_MAX_ZNAKOV_STRANY = 30000;
const KUCHARKY_UKAZKA_ZNAKOV = 3500;            // koľko textu zo strany dostane asistent

/** Priečinok s PDF (predvolene kucharky/ v koreni appky). */
function kucharky_priecinok(): string
{
    $env = getenv('KUCHARKY_PRIECINOK');
    return rtrim($env !== false && trim($env) !== '' ? trim($env) : dirname(__DIR__) . '/kucharky', '/\\');
}

/** Načíta pribalenú knižnicu na PDF (vlastný autoloader, bez Composera). */
function kucharky_kniznica(): bool
{
    static $registrovane = false;
    if (!$registrovane) {
        $registrovane = true;
        spl_autoload_register(static function (string $trieda): void {
            if (strncmp($trieda, 'Smalot\\PdfParser\\', 17) === 0) {
                $subor = __DIR__ . '/kniznice/pdfparser/src/' . str_replace('\\', '/', $trieda) . '.php';
                if (is_file($subor)) {
                    require $subor;
                }
            }
        });
    }
    return class_exists('Smalot\\PdfParser\\Parser') && function_exists('gzuncompress') && function_exists('iconv');
}

/** Sú tabuľky z migrácie 2.8? Bez nich appka beží ďalej, len bez kuchárok. */
function kucharky_available(PDO $pdo): bool
{
    static $available = null;
    if ($available === null) {
        try {
            $pdo->query('SELECT 1 FROM kucharky LIMIT 1');
            $pdo->query('SELECT 1 FROM kucharky_strany LIMIT 1');
            $available = true;
        } catch (Throwable $e) {
            debug_log('KUCHÁRKY: chýbajú tabuľky, spusti sql/migracia-2.8.sql (' . $e->getMessage() . ')');
            $available = false;
        }
    }
    return $available;
}

/** Koľko strán textu je spracovaných (0 = asistent kuchárky nedostane ako nástroj). */
function kucharky_pocet_stran(PDO $pdo): int
{
    static $pocet = null;
    if ($pocet === null) {
        $pocet = kucharky_available($pdo) ? (int) $pdo->query('SELECT COUNT(*) FROM kucharky_strany')->fetchColumn() : 0;
    }
    return $pocet;
}

/** Názov súboru je bezpečný (len meno v priečinku, .pdf)? */
function kucharky_platny_subor(string $subor): bool
{
    return $subor !== '' && $subor === basename($subor) && $subor[0] !== '.'
        && preg_match('/\.pdf$/i', $subor) === 1 && strlen($subor) <= 255;
}

/** PDF v priečinku: [subor => ['velkost' => …, 'zmenene' => …]]. */
function kucharky_na_disku(): array
{
    $priecinok = kucharky_priecinok();
    if (!is_dir($priecinok)) {
        return [];
    }
    $subory = [];
    foreach (scandir($priecinok) ?: [] as $subor) {
        $cesta = $priecinok . '/' . $subor;
        if (kucharky_platny_subor($subor) && is_file($cesta)) {
            $subory[$subor] = ['velkost' => (int) filesize($cesta), 'zmenene' => (int) filemtime($cesta)];
        }
    }
    ksort($subory, SORT_NATURAL | SORT_FLAG_CASE);
    return $subory;
}

/** „rodinna_kucharka-2024.pdf" → „Rodinna kucharka 2024". */
function kucharky_nazov_zo_suboru(string $subor): string
{
    $nazov = trim(preg_replace('/[\s_\-]+/u', ' ', preg_replace('/\.pdf$/i', '', $subor)) ?? '');
    return $nazov === '' ? $subor : mb_strtoupper(mb_substr($nazov, 0, 1)) . mb_substr($nazov, 1);
}

/**
 * Prehľad pre Správu: všetky PDF na disku aj spracované v databáze.
 * stav: nove | zmenene | ok | bez_textu | chyba | zmazane (je v databáze, súbor už nie je)
 */
function kucharky_zoznam(PDO $pdo): array
{
    $vDatabaze = [];
    foreach ($pdo->query('SELECT * FROM kucharky ORDER BY subor') as $r) {
        $vDatabaze[$r['subor']] = $r;
    }
    $zoznam = [];
    foreach (kucharky_na_disku() as $subor => $disk) {
        $db = $vDatabaze[$subor] ?? null;
        unset($vDatabaze[$subor]);
        $stav = !$db ? 'nove' : (((int) $db['zmenene'] !== $disk['zmenene'] || (int) $db['velkost'] !== $disk['velkost']) ? 'zmenene' : $db['stav']);
        $zoznam[] = [
            'subor' => $subor, 'nazov' => $db['nazov'] ?? kucharky_nazov_zo_suboru($subor), 'velkost' => $disk['velkost'],
            'stav' => $stav, 'pocet_stran' => (int) ($db['pocet_stran'] ?? 0), 'chyba' => $db['chyba'] ?? null,
            'spracovane' => $db['spracovane_at'] ?? null,
        ];
    }
    foreach ($vDatabaze as $subor => $db) {
        $zoznam[] = ['subor' => $subor, 'nazov' => $db['nazov'], 'velkost' => (int) $db['velkost'], 'stav' => 'zmazane',
            'pocet_stran' => (int) $db['pocet_stran'], 'chyba' => null, 'spracovane' => $db['spracovane_at']];
    }
    return $zoznam;
}

/** Text strany: bez riadiacich znakov a nadbytočných medzier, riadky ostanú. */
function kucharky_vycisti_text(string $text): string
{
    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $text) ?? '';
    $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? '';
    $text = preg_replace('/ *\R */u', "\n", $text) ?? '';
    $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? '';
    return mb_substr(trim($text), 0, KUCHARKY_MAX_ZNAKOV_STRANY, 'UTF-8');
}

/**
 * Prečíta jeden PDF a uloží jeho text po stranách (predošlý text toho súboru nahradí).
 * Návrat: ['success' => true, 'strany' => n, 'stav' => 'ok'|'bez_textu'] alebo ['success' => false, 'error' => …].
 */
function kucharky_spracuj(PDO $pdo, string $subor): array
{
    if (!kucharky_platny_subor($subor)) {
        return ['success' => false, 'error' => 'Neplatný názov súboru.'];
    }
    $cesta = kucharky_priecinok() . '/' . $subor;
    if (!is_file($cesta)) {
        return ['success' => false, 'error' => 'Súbor ' . $subor . ' v priečinku nie je.'];
    }
    if (!kucharky_kniznica()) {
        return ['success' => false, 'error' => 'Chýba knižnica na čítanie PDF (includes/kniznice/pdfparser) alebo rozšírenie PHP zlib/iconv.'];
    }
    $velkost = (int) filesize($cesta);
    $zmenene = (int) filemtime($cesta);
    $ulozChybu = static function (string $chyba) use ($pdo, $subor, $velkost, $zmenene): array {
        $pdo->prepare('INSERT INTO kucharky (subor, nazov, velkost, zmenene, pocet_stran, stav, chyba) VALUES (?, ?, ?, ?, 0, \'chyba\', ?)
            ON DUPLICATE KEY UPDATE velkost = VALUES(velkost), zmenene = VALUES(zmenene), stav = \'chyba\', chyba = VALUES(chyba), spracovane_at = NOW()')
            ->execute([$subor, kucharky_nazov_zo_suboru($subor), $velkost, $zmenene, mb_substr($chyba, 0, 255, 'UTF-8')]);
        debug_log('KUCHÁRKY: ' . $subor . ' sa nepodarilo prečítať: ' . $chyba);
        return ['success' => false, 'error' => $chyba];
    };
    if ($velkost > KUCHARKY_MAX_VELKOST) {
        return $ulozChybu('PDF je väčšie ako ' . (KUCHARKY_MAX_VELKOST / 1024 / 1024) . ' MB.');
    }

    @set_time_limit(300);
    @ini_set('memory_limit', '512M');
    $zaciatok = microtime(true);
    try {
        $config = new Smalot\PdfParser\Config();
        $config->setRetainImageContent(false); // obrázky netreba, šetrí pamäť
        $pdf = (new Smalot\PdfParser\Parser([], $config))->parseFile($cesta);
        $strany = [];
        foreach ($pdf->getPages() as $i => $strana) {
            try {
                $strany[$i + 1] = kucharky_vycisti_text($strana->getText());
            } catch (Throwable $e) {
                $strany[$i + 1] = ''; // jedna pokazená strana nezastaví celú knihu
            }
        }
        $detaily = $pdf->getDetails();
        $titul = is_string($detaily['Title'] ?? null) ? kucharky_vycisti_text($detaily['Title']) : '';
    } catch (Throwable $e) {
        return $ulozChybu('PDF sa nedá prečítať (' . mb_substr($e->getMessage(), 0, 150, 'UTF-8') . ').');
    }

    $znakov = array_sum(array_map(static fn($t) => mb_strlen($t, 'UTF-8'), $strany));
    // Skeny majú pár znakov na stranu (len čísla strán) — text z obrázkov bez OCR nevieme.
    $stav = ($strany && $znakov / count($strany) >= 40) ? 'ok' : 'bez_textu';
    $nazov = mb_strlen($titul, 'UTF-8') >= 3 && !preg_match('/^(untitled|microsoft word|document)/i', $titul)
        ? mb_substr($titul, 0, 255, 'UTF-8') : kucharky_nazov_zo_suboru($subor);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO kucharky (subor, nazov, velkost, zmenene, pocet_stran, stav, chyba) VALUES (?, ?, ?, ?, ?, ?, NULL)
            ON DUPLICATE KEY UPDATE nazov = VALUES(nazov), velkost = VALUES(velkost), zmenene = VALUES(zmenene),
                pocet_stran = VALUES(pocet_stran), stav = VALUES(stav), chyba = NULL, spracovane_at = NOW()')
            ->execute([$subor, $nazov, $velkost, $zmenene, count($strany), $stav]);
        $najdi = $pdo->prepare('SELECT id FROM kucharky WHERE subor = ?');
        $najdi->execute([$subor]);
        $id = (int) $najdi->fetchColumn();
        $pdo->prepare('DELETE FROM kucharky_strany WHERE kucharka_id = ?')->execute([$id]);
        $vloz = $pdo->prepare('INSERT INTO kucharky_strany (kucharka_id, strana, text, kluc) VALUES (?, ?, ?, ?)');
        foreach ($strany as $cislo => $text) {
            if (trim($text) !== '') {
                $vloz->execute([$id, $cislo, $text, surovina_key($text)]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return $ulozChybu('Text sa nepodarilo uložiť (' . mb_substr($e->getMessage(), 0, 150, 'UTF-8') . ').');
    }
    debug_log(sprintf('KUCHÁRKY: %s spracovaná, strán: %d, znakov: %d, %.1f s, stav %s', $subor, count($strany), $znakov,
        microtime(true) - $zaciatok, $stav));
    return ['success' => true, 'strany' => count($strany), 'stav' => $stav, 'nazov' => $nazov];
}

/** Spracuje nové a zmenené PDF (najviac $max naraz) a odstráni z databázy zmazané. Pre cron.php. */
function kucharky_spracuj_nove(PDO $pdo, int $max = 3): array
{
    $vysledky = [];
    foreach (kucharky_zoznam($pdo) as $k) {
        if ($k['stav'] === 'zmazane') {
            $pdo->prepare('DELETE FROM kucharky WHERE subor = ?')->execute([$k['subor']]);
        } elseif (in_array($k['stav'], ['nove', 'zmenene'], true) && count($vysledky) < $max) {
            $vysledky[$k['subor']] = kucharky_spracuj($pdo, $k['subor']);
        }
    }
    return $vysledky;
}

/** Zmaže kuchárku z databázy aj z priečinka. */
function kucharky_zmaz(PDO $pdo, string $subor): array
{
    if (!kucharky_platny_subor($subor)) {
        return ['success' => false, 'error' => 'Neplatný názov súboru.'];
    }
    $pdo->prepare('DELETE FROM kucharky WHERE subor = ?')->execute([$subor]);
    $cesta = kucharky_priecinok() . '/' . $subor;
    if (is_file($cesta) && !@unlink($cesta)) {
        return ['success' => false, 'error' => 'Z databázy je preč, ale súbor sa nedá zmazať (práva priečinka). Zmaž ho cez FTP.'];
    }
    return ['success' => true];
}

/**
 * Uloží PDF nahraté v Správe do priečinka (rovnaký názov prepíše). Názov súboru
 * sa zjednoduší na písmená bez diakritiky, čísla, pomlčky a podčiarkovníky.
 */
function kucharky_nahraj(array $subor): array
{
    if (($subor['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $kod = (int) ($subor['error'] ?? UPLOAD_ERR_NO_FILE);
        return ['success' => false, 'error' => in_array($kod, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'Súbor je väčší, ako server dovolí nahrať (' . ini_get('upload_max_filesize') . '). Nahraj ho cez FTP do priečinka kucharky/.'
            : 'Súbor sa nepodarilo nahrať (kód ' . $kod . ').'];
    }
    $povodny = (string) ($subor['name'] ?? '');
    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
    $mime = $finfo ? (string) finfo_file($finfo, (string) $subor['tmp_name']) : '';
    $hlavicka = (string) file_get_contents((string) $subor['tmp_name'], false, null, 0, 5);
    if (!preg_match('/\.pdf$/i', $povodny) || ($mime !== '' && $mime !== 'application/pdf') || $hlavicka !== '%PDF-') {
        return ['success' => false, 'error' => '„' . $povodny . '" nie je PDF.'];
    }
    $meno = trim(preg_replace('/[^a-z0-9_\-]+/', '-', surovina_key(preg_replace('/\.pdf$/i', '', $povodny))) ?? '', '-');
    $meno = ($meno !== '' ? mb_substr($meno, 0, 120) : 'kucharka-' . date('Ymd-His')) . '.pdf';

    $priecinok = kucharky_priecinok();
    if (!is_dir($priecinok) && !@mkdir($priecinok, 0755, true)) {
        return ['success' => false, 'error' => 'Priečinok ' . $priecinok . ' neexistuje a nedá sa vytvoriť.'];
    }
    if (!is_file($priecinok . '/.htaccess')) {
        // Priečinok vytvorený appkou musí byť rovnako chránený ako ten z repozitára.
        @file_put_contents($priecinok . '/.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    if (!move_uploaded_file((string) $subor['tmp_name'], $priecinok . '/' . $meno)) {
        return ['success' => false, 'error' => 'Do priečinka ' . $priecinok . ' sa nedá zapisovať.'];
    }
    return ['success' => true, 'subor' => $meno];
}

/**
 * Hľadanie v kuchárkach. Slová bez diakritiky; dlhšie slová sa hľadajú podľa
 * začiatku (slovenské koncovky): „kuracie" → kurac*, „ryža" → ryz*.
 * Návrat: [['kniha', 'strana', 'text', 'skore'], …] — text strany (+ začiatok ďalšej, recept môže pokračovať).
 */
function kucharky_hladaj(PDO $pdo, string $dotaz, int $limit = 5): array
{
    $vyrazy = [];
    foreach (array_unique(preg_split('/[^a-z0-9]+/', surovina_key($dotaz), -1, PREG_SPLIT_NO_EMPTY) ?: []) as $slovo) {
        $dlzka = strlen($slovo);
        if ($dlzka < 3) {
            continue;
        }
        $zaklad = $dlzka >= 6 ? substr($slovo, 0, max(4, $dlzka - 2)) : ($dlzka >= 4 ? substr($slovo, 0, $dlzka - 1) : $slovo);
        $vyrazy[] = $zaklad . '*';
    }
    if (!$vyrazy) {
        return [];
    }
    $boolean = implode(' ', array_slice($vyrazy, 0, 12));
    $stmt = $pdo->prepare(
        'SELECT s.kucharka_id, s.strana, s.text, k.nazov, MATCH(s.kluc) AGAINST(? IN BOOLEAN MODE) AS skore
         FROM kucharky_strany s JOIN kucharky k ON k.id = s.kucharka_id
         WHERE MATCH(s.kluc) AGAINST(? IN BOOLEAN MODE)
         ORDER BY skore DESC, k.nazov, s.strana LIMIT ' . max(1, min(10, $limit))
    );
    $stmt->execute([$boolean, $boolean]);
    $dalsia = $pdo->prepare('SELECT text FROM kucharky_strany WHERE kucharka_id = ? AND strana = ?');
    $vysledky = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $text = $r['text'];
        if (mb_strlen($text, 'UTF-8') < KUCHARKY_UKAZKA_ZNAKOV - 500) {
            $dalsia->execute([(int) $r['kucharka_id'], (int) $r['strana'] + 1]);
            $pokracovanie = (string) $dalsia->fetchColumn();
            if ($pokracovanie !== '') {
                $text .= "\n[pokračovanie na strane " . ((int) $r['strana'] + 1) . "]\n" . $pokracovanie;
            }
        }
        $vysledky[] = [
            'kniha'  => $r['nazov'],
            'strana' => (int) $r['strana'],
            'text'   => mb_substr($text, 0, KUCHARKY_UKAZKA_ZNAKOV, 'UTF-8'),
            'skore'  => round((float) $r['skore'], 2),
        ];
    }
    return $vysledky;
}
