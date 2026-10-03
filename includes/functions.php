<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/webpush.php';
/**
 * functions.php — CSRF ochrana + CRUD logika nad receptami.
 *
 * Prihlásenie/odhlásenie/registrácia je teraz VLASTNÉ (pozri includes/auth.php).
 * Očakáva, že premenná $pdo (PDO inštancia z config.php) je už definovaná.
 */

require_once __DIR__ . '/auth.php';

/* ======================================================================
   CSRF ochrana
   ====================================================================== */

// Uistite sa, že session je spustená na začiatku každého skriptu
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Vygeneruje alebo vráti existujúci CSRF token zo session
 */
function csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Overí, či odoslaný token zodpovedá tokenu v session
 */
function csrf_verify(?string $token): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}
/* ======================================================================
   current_user(), is_logged_in(), is_admin(), require_login(),
   get_current_user_id() a enforce_session_idle_timeout() sú teraz vo
   vlastnom includes/auth.php (appka má vlastné prihlasovanie).
   ====================================================================== */

/* ======================================================================
   Recepty — čítanie
   ====================================================================== */

/**
 * Vráti všetky recepty od naposledy vareného po najdávnejšie varený; recepty,
 * ktoré sa ešte nevarili, sú na konci (od najnovšie pridaného). Ostatné poradia
 * si používateľ vyberie v zozname „zoradenie".
 */
function get_all_recipes(PDO $pdo): array
{
    // LEFT JOIN: recept sa zobrazí aj vtedy, keď jeho autor v tabuľke users
    // neexistuje (napr. po presune DB sa zmenili ID používateľov, alebo bol
    // autor zmazaný). Obyčajný JOIN by taký recept potichu vynechal.
    // Zobrazuje sa celé meno autora; username len ak celé meno nie je vyplnené.
    $sql = "SELECT r.*, COALESCE(NULLIF(TRIM(u.full_name), ''), u.username, 'neznámy') AS created_by_username
            FROM recipes r
            LEFT JOIN users u ON u.id = r.created_by
            ORDER BY (r.last_made_date IS NULL) ASC, r.last_made_date DESC, r.id DESC";
    return $pdo->query($sql)->fetchAll();
}

function get_recipe(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM recipes WHERE id = ?');
    $stmt->execute([$id]);
    $recipe = $stmt->fetch();
    return $recipe ?: null;
}

/** Koľko dní ubehlo od poslednej prípravy (alebo null, ak sa ešte nikdy nerobil). */
function days_since(?string $date): ?int
{
    if ($date === null) {
        return null;
    }
    $diff = (new DateTime())->diff(new DateTime($date));
    return (int) $diff->days;
}

/* ======================================================================
   Spoločná validácia vstupov pre add/update
   ====================================================================== */

/**
 * Skontroluje a znormalizuje vstupy z formulára. Vracia buď
 * ['error' => '...'] alebo ['name'=>, 'ingredientsJson'=>, 'url'=>, 'lastMadeDate'=>].
 */
function validate_recipe_input(string $name, ?string $lastMadeDate): array
{
    $name = trim($name);
    if ($name === '') {
        return ['error' => 'Názov receptu je povinný.'];
    }
    if (mb_strlen($name) > 150) {
        return ['error' => 'Názov receptu je príliš dlhý (max 150 znakov).'];
    }

    $lastMadeDate = ($lastMadeDate !== null && trim($lastMadeDate) !== '') ? trim($lastMadeDate) : null;

    return [
        'name'            => $name,
        'lastMadeDate'    => $lastMadeDate,
    ];
}

/* ======================================================================
   Recepty — fotky (upload, zmenšenie, mazanie)
   ====================================================================== */

/** Priečinok na disku, kam sa ukladajú fotky (mimo /includes, v koreni appky). */
function get_recipe_uploads_dir(): string
{
    return dirname(__DIR__) . '/uploads/recipes/';
}

/** Relatívna URL cesta k priečinku s fotkami (tak, ako sa ukladá do DB a používa v <img src>). */
function get_recipe_uploads_url(): string
{
    return 'uploads/recipes/';
}

/**
 * Spracuje nahraný súbor z $_FILES['recipe_image']: overí ho, zmenší
 * (max 1200x800, zachová pomer strán) a uloží ako JPEG do uploads/recipes/.
 *
 * Návratové hodnoty:
 *  - []                          → žiadny súbor nebol nahraný (nič sa nedeje)
 *  - ['error' => '...']          → nahrávanie zlyhalo, chyba na zobrazenie
 *  - ['path' => 'uploads/...']   → úspech, cesta na uloženie do DB
 */
function process_recipe_image_upload(array $file): array
{
	ini_set('memory_limit', '256M');
    if (!isset($file['error']) || is_array($file['error'])) {
        return ['error' => 'Neplatný upload súboru.'];
    }

    switch ($file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_NO_FILE:
            return []; // fotka je nepovinná — jednoducho žiadna nebola priložená
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return ['error' => 'Fotka je príliš veľká.'];
        default:
            return ['error' => 'Nahrávanie fotky zlyhalo, skús to znova.'];
    }

    $maxBytes = 8 * 1024 * 1024; // 8 MB pred zmenšením
    if ($file['size'] > $maxBytes) {
        return ['error' => 'Fotka je príliš veľká (max 8 MB).'];
    }

    // Overíme SKUTOČNÝ typ obsahu súboru (nespoliehame sa na príponu/MIME z requestu)
    $imageInfo = @getimagesize($file['tmp_name']);
    if ($imageInfo === false) {
        return ['error' => 'Súbor nie je platný obrázok.'];
    }

    $mime = $imageInfo['mime'];
    $srcImage = null;
    switch ($mime) {
        case 'image/jpeg':
            $srcImage = @imagecreatefromjpeg($file['tmp_name']);
            break;
        case 'image/png':
            $srcImage = @imagecreatefrompng($file['tmp_name']);
            break;
        case 'image/webp':
            if (function_exists('imagecreatefromwebp')) {
                $srcImage = @imagecreatefromwebp($file['tmp_name']);
            }
            break;
    }

    if (!$srcImage) {
        return ['error' => 'Povolené sú len JPG, PNG alebo WEBP obrázky.'];
    }

    // Zmenšenie na max 1200x800 (nezväčšuje menšie obrázky, len zmenšuje)
    $maxW = 1200;
    $maxH = 800;
    $origW = imagesx($srcImage);
    $origH = imagesy($srcImage);
    $ratio = min($maxW / $origW, $maxH / $origH, 1);
    $newW = max(1, (int) round($origW * $ratio));
    $newH = max(1, (int) round($origH * $ratio));

    $dstImage = imagecreatetruecolor($newW, $newH);
    // Biele pozadie — dôležité pri konverzii priehľadných PNG na JPEG
    $white = imagecolorallocate($dstImage, 255, 255, 255);
    imagefill($dstImage, 0, 0, $white);
    imagecopyresampled($dstImage, $srcImage, 0, 0, 0, 0, $newW, $newH, $origW, $origH);

    $dir = get_recipe_uploads_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        imagedestroy($srcImage);
        imagedestroy($dstImage);
        return ['error' => 'Priečinok na fotky neexistuje alebo doň appka nemôže zapisovať (uploads/recipes/).'];
    }

    $filename = uniqid('recipe_', true) . '.jpg';
    $fullPath = $dir . $filename;

    $saved = imagejpeg($dstImage, $fullPath, 82);

    imagedestroy($srcImage);
    imagedestroy($dstImage);

    if (!$saved) {
        return ['error' => 'Nepodarilo sa uložiť fotku na server.'];
    }

    return ['path' => get_recipe_uploads_url() . $filename];
}

/** Zmaže súbor fotky z disku (ak existuje). Ticho ignoruje chýbajúci súbor. */
function delete_recipe_image(?string $imagePath): void
{
    if (!$imagePath) {
        return;
    }
    $full = dirname(__DIR__) . '/' . ltrim($imagePath, '/');
    if (is_file($full)) {
        @unlink($full);
    }
}

/* ======================================================================
   Recepty — hviezdičkové hodnotenia
   ====================================================================== */

/**
 * Priemer a počet hodnotení pre VŠETKY recepty naraz (jeden dotaz),
 * vhodné pri vykresľovaní celej tabuľky.
 * Návrat: [recipe_id => ['avg' => float, 'count' => int]]
 */
function get_recipe_ratings_summary(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT recipe_id, AVG(rating) AS avg_rating, COUNT(*) AS rating_count 
         FROM recipe_ratings 
         GROUP BY recipe_id'
    );

    $result = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $result[(int) $row['recipe_id']] = [
            'avg'   => round((float) $row['avg_rating'], 1),
            'count' => (int) $row['rating_count'],
        ];
    }
    return $result;
}

/**
 * Hodnotenia AKTUÁLNEHO používateľa pre všetky recepty (jeden dotaz).
 * Návrat: [recipe_id => rating (1-5)]
 */
function get_user_recipe_ratings(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT recipe_id, rating FROM recipe_ratings WHERE user_id = ?');
    $stmt->execute([$userId]);

    $result = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $result[(int) $row['recipe_id']] = (int) $row['rating'];
    }
    return $result;
}

/**
 * Uloží/aktualizuje hodnotenie jedného používateľa pre recept (1-5 hviezdičiek).
 * Ak už predtým hodnotil, hodnotenie sa prepíše (jeden človek = jeden hlas).
 */
function rate_recipe(PDO $pdo, int $recipeId, int $userId, int $rating): array
{
    if ($rating < 1 || $rating > 5) {
        return ['success' => false, 'error' => 'Neplatné hodnotenie.'];
    }

    $recipe = get_recipe($pdo, $recipeId);
    if (!$recipe) {
        return ['success' => false, 'error' => 'Recept neexistuje.'];
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO recipe_ratings (recipe_id, user_id, rating) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE rating = VALUES(rating), updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([$recipeId, $userId, $rating]);

        log_recipe_action($pdo, $recipeId, $userId, 'rated', $rating . '/5');

        // Rovno vrátime čerstvý priemer a počet, nech to JS hneď vie zobraziť
        $summaryStmt = $pdo->prepare(
            'SELECT AVG(rating) AS avg_rating, COUNT(*) AS rating_count 
             FROM recipe_ratings WHERE recipe_id = ?'
        );
        $summaryStmt->execute([$recipeId]);
        $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

        return [
            'success'    => true,
            'average'    => round((float) $summary['avg_rating'], 1),
            'count'      => (int) $summary['rating_count'],
            'yourRating' => $rating,
        ];
    } catch (Throwable $e) {
        error_log('rate_recipe zlyhalo: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Hodnotenie sa nepodarilo uložiť.'];
    }
}

/* ======================================================================
   Suroviny — číselník (tabuľka suroviny) + riadky surovín pri receptoch
   (tabuľka recept_suroviny). Recept už suroviny nedrží ako voľný text,
   ale ako odkazy na číselník, takže „Múka hladká", „muka hladka" aj
   „MÚKA  hladká" sú vždy tá istá surovina.
   ====================================================================== */

/** Jednotky ponúkané vo formulári (stĺpec recept_suroviny.jednotka). */
const SUROVINA_JEDNOTKY = ['g', 'kg', 'ml', 'dl', 'l', 'ks', 'PL', 'ČL', 'šálka', 'štipka', 'balenie', 'plátok', 'strúčik'];

/**
 * Normalizovaný kľúč na porovnávanie: malé písmená, bez diakritiky, jedna
 * medzera medzi slovami. MUSÍ dávať rovnaký výsledok ako stĺpec suroviny.kluc
 * v migracia_suroviny.sql a ako normKey() v suroviny.js.
 */
function surovina_key(string $text): string
{
    $text = mb_strtolower(trim($text), 'UTF-8');

    // Vlastná tabuľka namiesto rozšírenia intl — to na hostingu nemusí byť.
    static $map = [
        'á' => 'a', 'ä' => 'a', 'à' => 'a', 'â' => 'a', 'č' => 'c', 'ć' => 'c', 'ç' => 'c', 'ď' => 'd',
        'é' => 'e', 'ě' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ĺ' => 'l', 'ľ' => 'l', 'ň' => 'n', 'ń' => 'n', 'ñ' => 'n', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o',
        'ő' => 'o', 'ŕ' => 'r', 'ř' => 'r', 'š' => 's', 'ś' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u',
        'ü' => 'u', 'ű' => 'u', 'ý' => 'y', 'ž' => 'z', 'ź' => 'z', 'ż' => 'z',
    ];
    $text = strtr($text, $map);

    return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
}

/** Názov suroviny tak, ako sa uloží a zobrazí: bez zbytočných medzier, s veľkým prvým písmenom. */
function surovina_display_name(string $text): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    if ($text === '') {
        return '';
    }
    $text = mb_substr($text, 0, 100, 'UTF-8');
    return mb_strtoupper(mb_substr($text, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($text, 1, null, 'UTF-8');
}

/**
 * Existujú už tabuľky pre suroviny? Ak niekto nahrá nové súbory skôr, než
 * spustí migracia_suroviny.sql, appka má ďalej fungovať po starom (len bez
 * výberu surovín), nie spadnúť.
 */
function suroviny_available(PDO $pdo): bool
{
    static $available = null;
    if ($available === null) {
        try {
            $pdo->query('SELECT 1 FROM suroviny LIMIT 1');
            $pdo->query('SELECT 1 FROM recept_suroviny LIMIT 1');
            $available = true;
        } catch (Throwable $e) {
            debug_log('SUROVINY: tabuľky chýbajú, spusti migracia_suroviny.sql (' . $e->getMessage() . ')');
            $available = false;
        }
    }
    return $available;
}

/** Celý číselník surovín pre vyhľadávací výber vo formulári. */
function get_all_suroviny(PDO $pdo): array
{
    if (!suroviny_available($pdo)) {
        return [];
    }
    $rows = $pdo->query('SELECT id, nazov, kategoria FROM suroviny ORDER BY nazov')->fetchAll(PDO::FETCH_ASSOC);
    return array_map(static fn(array $r): array => [
        'id'        => (int) $r['id'],
        'nazov'     => $r['nazov'],
        'kategoria' => $r['kategoria'] ?? '',
    ], $rows);
}

/**
 * Suroviny VŠETKÝCH receptov naraz (jeden dotaz), v poradí, v akom boli zadané.
 * Návrat: [recipe_id => [['id'=>, 'nazov'=>, 'mnozstvo'=>, 'jednotka'=>, 'poznamka'=>], ...]]
 */
function get_all_recipe_suroviny(PDO $pdo): array
{
    if (!suroviny_available($pdo)) {
        return [];
    }
    $stmt = $pdo->query(
        'SELECT rs.recept_id, s.id, s.nazov, rs.mnozstvo, rs.jednotka, rs.poznamka
         FROM recept_suroviny rs
         JOIN suroviny s ON s.id = rs.surovina_id
         ORDER BY rs.recept_id, rs.poradie, rs.id'
    );

    $result = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $result[(int) $row['recept_id']][] = [
            'id'       => (int) $row['id'],
            'nazov'    => $row['nazov'],
            'mnozstvo' => $row['mnozstvo'] !== null ? (float) $row['mnozstvo'] : null,
            'jednotka' => $row['jednotka'] ?? '',
            'poznamka' => $row['poznamka'] ?? '',
        ];
    }
    return $result;
}

/** Množstvo z formulára („200", „0,5", „1/2") → kladné číslo, inak null. */
function parse_surovina_mnozstvo($value): ?float
{
    if ($value === null || is_array($value)) {
        return null;
    }
    $value = str_replace(',', '.', trim((string) $value));
    if ($value === '') {
        return null;
    }
    if (preg_match('~^(\d+)\s*/\s*(\d+)$~', $value, $m) && (int) $m[2] > 0) {
        $number = (int) $m[1] / (int) $m[2];
    } elseif (is_numeric($value)) {
        $number = (float) $value;
    } else {
        return null;
    }
    $number = round($number, 2);
    return ($number > 0 && $number < 1000000) ? $number : null;
}

/**
 * Vyčistí riadky surovín poslané z formulára (JSON v poli suroviny_json).
 * Návrat: zoznam ['id'=>?int, 'nazov'=>string, 'mnozstvo'=>?float, 'jednotka'=>?string, 'poznamka'=>?string]
 */
function parse_suroviny_rows(?string $json): array
{
    $data = json_decode($json ?? '', true);
    if (!is_array($data)) {
        return [];
    }

    $rows = [];
    foreach ($data as $item) {
        if (!is_array($item)) {
            continue;
        }
        $id = (isset($item['id']) && (int) $item['id'] > 0) ? (int) $item['id'] : null;
        $nazov = surovina_display_name((string) ($item['nazov'] ?? ''));
        if ($id === null && $nazov === '') {
            continue;
        }

        $jednotka = trim((string) ($item['jednotka'] ?? ''));
        $poznamka = trim(preg_replace('/\s+/u', ' ', (string) ($item['poznamka'] ?? '')) ?? '');

        $rows[] = [
            'id'       => $id,
            'nazov'    => $nazov,
            'mnozstvo' => parse_surovina_mnozstvo($item['mnozstvo'] ?? null),
            'jednotka' => in_array($jednotka, SUROVINA_JEDNOTKY, true) ? $jednotka : null,
            'poznamka' => $poznamka !== '' ? mb_substr($poznamka, 0, 100, 'UTF-8') : null,
        ];
        if (count($rows) >= 100) {
            break;
        }
    }
    return $rows;
}

/**
 * Nájde surovinu podľa normalizovaného kľúča, alebo ju založí. Vďaka tomu
 * „Bataty", „bataty" aj „Batáty" skončia ako jeden záznam.
 */
function find_or_create_surovina(PDO $pdo, string $nazov): ?int
{
    $nazov = surovina_display_name($nazov);
    $kluc = surovina_key($nazov);
    if ($kluc === '') {
        return null;
    }

    $find = $pdo->prepare('SELECT id FROM suroviny WHERE kluc = ?');
    $find->execute([$kluc]);
    $id = $find->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }

    try {
        $pdo->prepare('INSERT INTO suroviny (nazov, kluc) VALUES (?, ?)')->execute([$nazov, $kluc]);
        return (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        // Dvaja používatelia založili tú istú surovinu v rovnakej chvíli — použijeme tú, ktorá vyhrala.
        $find->execute([$kluc]);
        $id = $find->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
        throw $e;
    }
}

/**
 * Nahradí suroviny receptu novým zoznamom (riadky z parse_suroviny_rows()).
 * Transakciu rieši volajúci, aby sa recept a jeho suroviny uložili naraz.
 */
function save_recipe_suroviny(PDO $pdo, int $recipeId, array $rows): void
{
    $pdo->prepare('DELETE FROM recept_suroviny WHERE recept_id = ?')->execute([$recipeId]);
    if (!$rows) {
        return;
    }

    $exists = $pdo->prepare('SELECT id FROM suroviny WHERE id = ?');
    $insert = $pdo->prepare(
        'INSERT INTO recept_suroviny (recept_id, surovina_id, mnozstvo, jednotka, poznamka, poradie)
         VALUES (?, ?, ?, ?, ?, ?)'
    );

    $used = [];
    $poradie = 0;
    foreach ($rows as $row) {
        $surovinaId = null;
        if (!empty($row['id'])) {
            $exists->execute([(int) $row['id']]);
            $found = $exists->fetchColumn();
            $surovinaId = $found !== false ? (int) $found : null;
        }
        if ($surovinaId === null && ($row['nazov'] ?? '') !== '') {
            $surovinaId = find_or_create_surovina($pdo, $row['nazov']);
        }
        // Tá istá surovina dvakrát v jednom recepte nedáva zmysel — necháme prvý výskyt.
        if ($surovinaId === null || isset($used[$surovinaId])) {
            continue;
        }
        $used[$surovinaId] = true;

        $insert->execute([
            $recipeId,
            $surovinaId,
            $row['mnozstvo'] !== null ? number_format((float) $row['mnozstvo'], 2, '.', '') : null,
            $row['jednotka'] ?? null,
            $row['poznamka'] ?? null,
            $poradie++,
        ]);
    }
}

/* ======================================================================
   Nákupný zoznam (tabuľka nakupny_zoznam) — jeden spoločný zoznam pre
   všetkých používateľov. Suroviny z viacerých receptov sa zlučujú do
   jedného riadku a množstvá sa sčítavajú.
   ====================================================================== */

/** Existuje už tabuľka nakupny_zoznam? Bez nej appka funguje ďalej, len bez nákupného zoznamu. */
function shopping_available(PDO $pdo): bool
{
    static $available = null;
    if ($available === null) {
        try {
            $pdo->query('SELECT 1 FROM nakupny_zoznam LIMIT 1');
            $available = suroviny_available($pdo);
        } catch (Throwable $e) {
            debug_log('NAKUP: chýba tabuľka nakupny_zoznam, spusti migracia_nakup.sql (' . $e->getMessage() . ')');
            $available = false;
        }
    }
    return $available;
}

/**
 * Prevedie množstvo na základnú jednotku, aby sa dalo sčítať: kg → g,
 * dl a l → ml, „ks" je to isté ako množstvo bez jednotky.
 * Návrat: [?float množstvo, ?string jednotka]
 */
function shopping_base_unit(?float $mnozstvo, ?string $jednotka): array
{
    $jednotka = ($jednotka === null || $jednotka === '' || $jednotka === 'ks') ? null : $jednotka;
    if ($mnozstvo === null) {
        return [null, $jednotka];
    }
    switch ($jednotka) {
        case 'kg': return [$mnozstvo * 1000, 'g'];
        case 'dl': return [$mnozstvo * 100, 'ml'];
        case 'l':  return [$mnozstvo * 1000, 'ml'];
        default:   return [$mnozstvo, $jednotka];
    }
}

/** Pripíše názov receptu k zdrojom položky („Lievance, Pizza"), bez opakovania. */
function shopping_merge_zdroj(?string $existing, ?string $new): ?string
{
    $parts = array_values(array_filter(array_map('trim', explode(',', (string) $existing)), 'strlen'));
    $new = trim((string) $new);
    if ($new !== '' && !in_array($new, $parts, true)) {
        $parts[] = $new;
    }
    return $parts ? mb_substr(implode(', ', $parts), 0, 255, 'UTF-8') : null;
}

/**
 * Pridá položku do zoznamu, alebo ju zlúči s rovnakou ešte nekúpenou položkou.
 * - rovnaká surovina + rovnaká jednotka → množstvá sa sčítajú
 * - položka bez množstva sa pripojí k existujúcej (kupuje sa tak či tak)
 * - rôzne jednotky (g a PL) ostávajú ako dva riadky
 */
function shopping_add_item(PDO $pdo, int $userId, ?int $surovinaId, string $nazov, ?float $mnozstvo, ?string $jednotka, ?string $zdroj): void
{
    $nazov = surovina_display_name($nazov);
    $kluc = surovina_key($nazov);
    if ($kluc === '') {
        return;
    }
    [$mnozstvo, $jednotka] = shopping_base_unit($mnozstvo, $jednotka);

    if ($surovinaId !== null) {
        $stmt = $pdo->prepare('SELECT * FROM nakupny_zoznam WHERE kupene = 0 AND surovina_id = ? ORDER BY id');
        $stmt->execute([$surovinaId]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM nakupny_zoznam WHERE kupene = 0 AND surovina_id IS NULL AND kluc = ? ORDER BY id');
        $stmt->execute([$kluc]);
    }
    $existing = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $target = null;        // riadok, do ktorého sa nová položka zlúči
    $newMnozstvo = null;
    $newJednotka = null;

    if ($mnozstvo === null) {
        // bez množstva: stačí, že surovina už na zozname je
        $target = $existing[0] ?? null;
        if ($target) {
            $newMnozstvo = $target['mnozstvo'];
            $newJednotka = $target['jednotka'];
        }
    } else {
        foreach ($existing as $row) {
            if ($row['mnozstvo'] !== null && (string) $row['jednotka'] === (string) $jednotka) {
                $target = $row;
                $newMnozstvo = (float) $row['mnozstvo'] + $mnozstvo;
                $newJednotka = $jednotka;
                break;
            }
        }
        if (!$target) {
            foreach ($existing as $row) {
                if ($row['mnozstvo'] === null) {
                    $target = $row;
                    $newMnozstvo = $mnozstvo;
                    $newJednotka = $jednotka;
                    break;
                }
            }
        }
    }

    if ($target) {
        $pdo->prepare('UPDATE nakupny_zoznam SET mnozstvo = ?, jednotka = ?, zdroj = ? WHERE id = ?')->execute([
            $newMnozstvo !== null ? number_format((float) $newMnozstvo, 2, '.', '') : null,
            $newJednotka,
            shopping_merge_zdroj($target['zdroj'], $zdroj),
            $target['id'],
        ]);
        return;
    }

    $pdo->prepare(
        'INSERT INTO nakupny_zoznam (surovina_id, nazov, kluc, mnozstvo, jednotka, zdroj, pridal) VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $surovinaId,
        $nazov,
        $kluc,
        $mnozstvo !== null ? number_format($mnozstvo, 2, '.', '') : null,
        $jednotka,
        shopping_merge_zdroj(null, $zdroj),
        $userId,
    ]);
}

/**
 * Pridá všetky suroviny receptu do nákupného zoznamu. $targetServings = počet
 * porcií, pre ktorý sa má nakúpiť (null = toľko, koľko má recept zapísané).
 */
function shopping_add_recipe(PDO $pdo, int $recipeId, int $userId, ?int $targetServings): array
{
    $recipe = get_recipe($pdo, $recipeId);
    if (!$recipe) {
        return ['success' => false, 'error' => 'Recept neexistuje.'];
    }

    $stmt = $pdo->prepare(
        'SELECT s.id, s.nazov, rs.mnozstvo, rs.jednotka
         FROM recept_suroviny rs JOIN suroviny s ON s.id = rs.surovina_id
         WHERE rs.recept_id = ? ORDER BY rs.poradie, rs.id'
    );
    $stmt->execute([$recipeId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return ['success' => false, 'error' => 'Tento recept nemá suroviny vybrané zo zoznamu.'];
    }

    $base = !empty($recipe['servings']) ? (int) $recipe['servings'] : null;
    $factor = ($base && $targetServings && $targetServings >= 1 && $targetServings <= 99) ? $targetServings / $base : 1.0;

    $pdo->beginTransaction();
    try {
        foreach ($rows as $row) {
            $mnozstvo = $row['mnozstvo'] !== null ? round((float) $row['mnozstvo'] * $factor, 2) : null;
            shopping_add_item($pdo, $userId, (int) $row['id'], $row['nazov'], $mnozstvo, $row['jednotka'], $recipe['name']);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        debug_log('NAKUP: pridanie receptu zlyhalo: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Suroviny sa nepodarilo pridať do nákupu.'];
    }

    return ['success' => true, 'added' => count($rows)];
}

/** Celý nákupný zoznam: najprv nekúpené podľa kategórie a názvu, potom kúpené. */
function get_shopping_list(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT n.id, COALESCE(s.nazov, n.nazov) AS nazov, COALESCE(s.kategoria, '') AS kategoria,
                n.mnozstvo, n.jednotka, n.zdroj, n.kupene
         FROM nakupny_zoznam n
         LEFT JOIN suroviny s ON s.id = n.surovina_id
         ORDER BY n.kupene, (COALESCE(s.kategoria, '') = ''), s.kategoria, nazov, n.id"
    )->fetchAll(PDO::FETCH_ASSOC);

    return array_map(static fn(array $r): array => [
        'id'        => (int) $r['id'],
        'nazov'     => $r['nazov'],
        'kategoria' => $r['kategoria'],
        'mnozstvo'  => $r['mnozstvo'] !== null ? (float) $r['mnozstvo'] : null,
        'jednotka'  => $r['jednotka'] ?? '',
        'zdroj'     => $r['zdroj'] ?? '',
        'kupene'    => (bool) $r['kupene'],
    ], $rows);
}

/** Počet položiek, ktoré ešte treba kúpiť (odznak pri tlačidle Nákup). */
function shopping_open_count(PDO $pdo): int
{
    return shopping_available($pdo)
        ? (int) $pdo->query('SELECT COUNT(*) FROM nakupny_zoznam WHERE kupene = 0')->fetchColumn()
        : 0;
}

/* ======================================================================
   Počet porcií (stĺpec recipes.servings) — základ pre prepočet množstiev
   surovín v náhľade receptu.
   ====================================================================== */

/**
 * Existuje už stĺpec recipes.servings? Ak niekto nahrá nové súbory skôr, než
 * spustí ALTER TABLE, appka funguje ďalej, len bez poľa „Porcie".
 */
function recipes_have_servings(PDO $pdo): bool
{
    static $available = null;
    if ($available === null) {
        try {
            $pdo->query('SELECT servings FROM recipes LIMIT 0');
            $available = true;
        } catch (Throwable $e) {
            debug_log('PORCIE: chýba stĺpec recipes.servings (' . $e->getMessage() . ')');
            $available = false;
        }
    }
    return $available;
}

/** Počet porcií z formulára → celé číslo 1–99, inak null (nevyplnené). */
function parse_servings($value): ?int
{
    if ($value === null || is_array($value) || !ctype_digit(trim((string) $value))) {
        return null;
    }
    $servings = (int) trim((string) $value);
    return ($servings >= 1 && $servings <= 99) ? $servings : null;
}

/* ======================================================================
   Recepty — zápis (add / update / mark made / delete)
   ====================================================================== */

/**
 * Pridanie nového receptu. $surovinyRows = riadky z parse_suroviny_rows(),
 * alebo null, ak sa suroviny nemajú ukladať vôbec.
 */
function add_recipe(PDO $pdo, int $userId, string $name, ?string $ingredients, ?string $url, ?int $prepTime, ?string $category, ?string $lastMadeDate = null, ?string $imagePath = null, ?array $surovinyRows = null, ?int $servings = null): array
{
    if (empty(trim($name))) {
        return ['success' => false, 'error' => 'Názov receptu je povinný.'];
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'INSERT INTO recipes (name, ingredients, url, prep_time, category, last_made_date, created_by, image_path) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            trim($name), 
            $ingredients ? trim($ingredients) : null, 
            $url ? trim($url) : null, 
            $prepTime, 
            $category ? trim($category) : null, 
            $lastMadeDate ?: null, 
            $userId,
            $imagePath
        ]);
        
        $recipeId = (int)$pdo->lastInsertId();
        if ($servings !== null && recipes_have_servings($pdo)) {
            $pdo->prepare('UPDATE recipes SET servings = ? WHERE id = ?')->execute([$servings, $recipeId]);
        }
        if ($surovinyRows !== null && suroviny_available($pdo)) {
            save_recipe_suroviny($pdo, $recipeId, $surovinyRows);
        }
        log_recipe_action($pdo, $recipeId, $userId, 'created');
        $pdo->commit();

        return ['success' => true, 'id' => $recipeId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        debug_log('add_recipe zlyhalo: ' . $e->getMessage());
        // Ak sa recept nepodarilo uložiť do DB, nenechávame na disku osirotenú fotku
        delete_recipe_image($imagePath);
        return ['success' => false, 'error' => 'Nepodarilo sa uložiť recept.'];
    }
}

/**
 * Upraví existujúci recept (názov, ingrediencie, odkaz, dátum poslednej
 * prípravy) — umožňuje spätne opraviť čokoľvek, čo bolo pri pridaní zle
 * alebo sa medzičasom zmenilo. Prístupné každému prihlásenému používateľovi
 * (rovnako ako pridávanie); mazanie ostáva vyhradené pre admina.
 */
/**
 * Úprava existujúceho receptu
 */
function update_recipe(PDO $pdo, int $recipeId, int $userId, string $name, ?string $ingredients, ?string $url, ?int $prepTime, ?string $category, ?string $lastMadeDate = null, ?string $newImagePath = null, bool $removeImage = false, ?array $surovinyRows = null, ?int $servings = null): array
{
    if ($recipeId <= 0 || empty(trim($name))) {
        return ['success' => false, 'error' => 'Neplatné vstupné údaje.'];
    }

    // Overíme existenciu
    $recipe = get_recipe($pdo, $recipeId);
    if (!$recipe) {
        return ['success' => false, 'error' => 'Recept neexistuje.'];
    }

    // Zistíme finálnu cestu k fotke: nová nahraná fotka > zaškrtnuté "odstrániť" > ponechať pôvodnú
    $oldImagePath = $recipe['image_path'] ?? null;
    $finalImagePath = $oldImagePath;
    if ($newImagePath !== null) {
        $finalImagePath = $newImagePath;
    } elseif ($removeImage) {
        $finalImagePath = null;
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'UPDATE recipes
             SET name = ?, ingredients = ?, url = ?, prep_time = ?, category = ?, last_made_date = ?, image_path = ? 
             WHERE id = ?'
        );
        $stmt->execute([
            trim($name), 
            $ingredients ? trim($ingredients) : null, 
            $url ? trim($url) : null, 
            $prepTime, 
            $category ? trim($category) : null, 
            $lastMadeDate ?: null, 
            $finalImagePath,
            $recipeId
        ]);

        // null = pole ostalo prázdne → počet porcií sa zmaže
        if (recipes_have_servings($pdo)) {
            $pdo->prepare('UPDATE recipes SET servings = ? WHERE id = ?')->execute([$servings, $recipeId]);
        }

        if ($surovinyRows !== null && suroviny_available($pdo)) {
            save_recipe_suroviny($pdo, $recipeId, $surovinyRows);
        }

        log_recipe_action($pdo, $recipeId, $userId, 'updated');
        $pdo->commit();

        // Starú fotku zmažeme z disku až po úspešnom uložení, a len ak sa naozaj zmenila
        if ($finalImagePath !== $oldImagePath) {
            delete_recipe_image($oldImagePath);
        }

        return ['success' => true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        debug_log('update_recipe zlyhalo: ' . $e->getMessage());
        // DB update zlyhal — ak sme medzitým nahrali novú fotku, zmažeme ju, aby nezostala osirotená
        if ($newImagePath !== null) {
            delete_recipe_image($newImagePath);
        }
        return ['success' => false, 'error' => 'Úprava receptu zlyhala.'];
    }
}
/** Označí recept ako práve pripravený (nastaví last_made_date na dnes). */
function mark_recipe_made(PDO $pdo, int $recipeId, int $userId): array
{
    $recipe = get_recipe($pdo, $recipeId);
    if (!$recipe) {
        return ['success' => false, 'error' => 'Recept neexistuje.'];
    }

    $stmt = $pdo->prepare('UPDATE recipes SET last_made_date = CURDATE() WHERE id = ?');
    $stmt->execute([$recipeId]);

    log_recipe_action($pdo, $recipeId, $userId, 'made', $recipe['name']);

    return ['success' => true];
}

/**
 * Vymaže recept — výhradne pre rolu 'admin'. Kontrola role sa robí TU
 * (server-side), nielen skrytím tlačidla v UI.
 */
function delete_recipe(PDO $pdo, int $recipeId, int $userId, string $role): array
{
    if ($role !== 'admin') {
        return ['success' => false, 'error' => 'Na túto akciu nemáš oprávnenie.'];
    }

    $recipe = get_recipe($pdo, $recipeId);
    if (!$recipe) {
        return ['success' => false, 'error' => 'Recept neexistuje.'];
    }

    $pdo->beginTransaction();
    try {
        // Log zapisujeme PRED zmazaním; FK recipe_logs.recipe_id má ON DELETE
        // SET NULL, takže záznam v logu prežije aj zmazanie receptu.
        log_recipe_action($pdo, $recipeId, $userId, 'deleted', $recipe['name']);

        $stmt = $pdo->prepare('DELETE FROM recipes WHERE id = ?');
        $stmt->execute([$recipeId]);

        $pdo->commit();

        // Fotku mažeme z disku až PO úspešnom commite — ak by DB transakcia zlyhala, fotka ostáva
        delete_recipe_image($recipe['image_path'] ?? null);

        return ['success' => true];
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('delete_recipe zlyhalo: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Mazanie zlyhalo, skúste to znova.'];
    }
}

/** Zápis do recipe_logs — kto, kedy a čo s receptom urobil. */
function log_recipe_action(PDO $pdo, int $recipeId, int $userId, string $action, ?string $note = null): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO recipe_logs (recipe_id, user_id, action, note) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$recipeId, $userId, $action, $note]);
}

/**
 * Pošle push notifikáciu všetkým prihláseným zariadeniam daného používateľa
 * (alebo všetkým používateľom, ak sa $userId nezadá). $url je adresa, ktorá sa
 * otvorí po kliknutí na upozornenie (napr. './?recept=12' otvorí daný recept).
 *
 * Odosiela sa vlastným kódom v includes/webpush.php (openssl + curl) — appka
 * už nepotrebuje knižnicu minishlink/web-push vo vendor/.
 */
function send_push_notification(PDO $pdo, ?int $userId, string $title, string $body, string $url = './', ?int $excludeUserId = null): void
{
    try {
        $sql = 'SELECT * FROM push_subscriptions';
        $conditions = [];
        $params = [];

        if ($userId !== null) {
            $conditions[] = 'user_id = ?';
            $params[] = $userId;
        }
        if ($excludeUserId !== null) {
            $conditions[] = 'user_id != ?';
            $params[] = $excludeUserId;
        }
        if ($conditions) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $subs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$subs) {
            return;
        }

        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'url' => $url,
        ], JSON_UNESCAPED_UNICODE);

        foreach ($subs as $sub) {
            $result = webpush_send(
                $sub['endpoint'],
                $sub['p256dh'],
                $sub['auth'],
                $payload,
                VAPID_SUBJECT,
                VAPID_PUBLIC_KEY,
                VAPID_PRIVATE_KEY
            );

            if ($result['error'] === null) {
                continue;
            }
            // 404/410 = zariadenie odber zrušilo (odinštalovaná appka, vymazané dáta) → záznam zmažeme.
            if (in_array($result['status'], [404, 410], true)) {
                $pdo->prepare('DELETE FROM push_subscriptions WHERE endpoint = ?')->execute([$sub['endpoint']]);
            } else {
                debug_log('PUSH ZLYHAL (používateľ ' . $sub['user_id'] . '): ' . $result['error']);
            }
        }
    } catch (Throwable $e) {
        // Zlyhanie posielania push notifikácie (napr. výpadok siete) nesmie
        // zhodiť samotné pridanie/ohodnotenie receptu — len to zalogujeme.
        debug_log('PUSH ZLYHAL: ' . $e->getMessage());
    }
}

/* ======================================================================
   Pripomienka „tento recept ste dlho nevarili" — po 90 dňoch od posledného
   varenia, najviac jedna denne a pri každom recepte len raz, kým sa znova
   neuvarí (stĺpec recipes.reminder_sent_at).
   ====================================================================== */

const RECIPE_REMINDER_DAYS = 90;

/** Existuje už stĺpec recipes.reminder_sent_at? Bez neho sa pripomienky neposielajú. */
function recipes_have_reminder(PDO $pdo): bool
{
    static $available = null;
    if ($available === null) {
        try {
            $pdo->query('SELECT reminder_sent_at FROM recipes LIMIT 0');
            $available = true;
        } catch (Throwable $e) {
            $available = false;
        }
    }
    return $available;
}

/**
 * Ak je čas, pošle všetkým používateľom jednu pripomienku na recept, ktorý
 * sa najdlhšie nevaril. Volá sa z cron.php aj pri bežnom otvorení appky —
 * je bezpečné volať ju hocikoľkokrát, viac než jedna denne sa nepošle.
 *
 * $onlyDaytime = true obmedzí odoslanie na 9:00–20:00 (aby upozornenie
 * neprišlo v noci len preto, že niekto appku otvoril o polnoci).
 * Návrat: názov receptu, na ktorý sa pripomienka poslala, alebo null.
 */
function send_due_recipe_reminder(PDO $pdo, bool $onlyDaytime = true): ?string
{
    if (!recipes_have_reminder($pdo)) {
        return null;
    }

    $now = new DateTime('now', new DateTimeZone('Europe/Bratislava'));
    $hour = (int) $now->format('G');
    if ($onlyDaytime && ($hour < 9 || $hour >= 20)) {
        return null;
    }
    $today = $now->format('Y-m-d');

    try {
        // Zámok, aby dve súčasné návštevy neposlali dve pripomienky naraz.
        if ((int) $pdo->query("SELECT GET_LOCK('recepty_pripomienka', 0)")->fetchColumn() !== 1) {
            return null;
        }

        try {
            $sentToday = $pdo->prepare('SELECT COUNT(*) FROM recipes WHERE reminder_sent_at = ?');
            $sentToday->execute([$today]);
            if ((int) $sentToday->fetchColumn() > 0) {
                return null;
            }

            $stmt = $pdo->prepare(
                'SELECT id, name, last_made_date, DATEDIFF(?, last_made_date) AS days
                 FROM recipes
                 WHERE last_made_date IS NOT NULL
                   AND DATEDIFF(?, last_made_date) >= ?
                   AND (reminder_sent_at IS NULL OR reminder_sent_at < last_made_date)
                 ORDER BY last_made_date ASC, id ASC
                 LIMIT 1'
            );
            $stmt->execute([$today, $today, RECIPE_REMINDER_DAYS]);
            $recipe = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$recipe) {
                return null;
            }

            // Najprv si poznačíme odoslanie — ak by push zlyhal, radšej žiadna než tá istá každý deň.
            $pdo->prepare('UPDATE recipes SET reminder_sent_at = ? WHERE id = ?')->execute([$today, $recipe['id']]);
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('recepty_pripomienka')");
        }

        send_push_notification(
            $pdo,
            null,
            'Dlho ste nevarili 🍽️',
            '„' . $recipe['name'] . '" ste naposledy varili pred ' . (int) $recipe['days'] . ' dňami. Čo tak si ho zopakovať?',
            './?recept=' . (int) $recipe['id']
        );

        return $recipe['name'];
    } catch (Throwable $e) {
        debug_log('PRIPOMIENKA zlyhala: ' . $e->getMessage());
        return null;
    }
}
