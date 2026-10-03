<?php
/**
 * Session — nastavenia cookie MUSIA byť zadané PRED session_start(). Po štarte
 * session ich PHP už neprijme (len vypíše varovanie), takže by sa vôbec
 * nepoužili. Preto sú tu, úplne na začiatku, a nie až na konci súboru.
 */
if (session_status() === PHP_SESSION_NONE) {
    // HTTPS priamo, alebo cez proxy hostingu, ktorá posiela X-Forwarded-Proto.
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;

    ini_set('session.cookie_httponly', '1');   // cookie nie je čitateľná z JavaScriptu
    ini_set('session.use_strict_mode', '1');   // server neprijme cudzie/vymyslené session ID
    ini_set('session.cookie_samesite', 'Lax');
    // Secure = cookie sa posiela len cez HTTPS. Zapíname ju len pri HTTPS požiadavke,
    // inak by sa cez obyčajné HTTP nedalo vôbec prihlásiť.
    ini_set('session.cookie_secure', $isHttps ? '1' : '0');

    // HSTS: prehliadač, ktorý appku raz otvoril cez HTTPS, ju pol roka nebude
    // otvárať nešifrovane, ani keď niekto zadá adresu s http://.
    if ($isHttps && !headers_sent()) {
        header('Strict-Transport-Security: max-age=15552000');
    }

    session_start();
}
/**
 * Spracovanie chýb.
 *
 * - Chyby sa zapisujú do includes/debug-log.txt. Priečinok includes/ má
 *   v .htaccess zakázaný prístup z webu, takže log si nikto cudzí neotvorí
 *   (v koreni appky bol verejne dostupný na /debug-log.txt). Ak sa zapísať
 *   nedá, idú chyby do štandardného PHP error logu hostingu.
 * - LADIACI REŽIM: pridaj do includes/.env riadok APP_DEBUG=1 a presná chyba
 *   (správa, súbor, riadok) sa zobrazí priamo na obrazovke. Po vyriešení
 *   problému riadok zmaž alebo nastav APP_DEBUG=0 — v bežnej prevádzke sa
 *   podrobnosti chyby návštevníkom NEzobrazujú.
 */
ini_set('display_errors', 0);
error_reporting(E_ALL);

define('DEBUG_LOG_FILE', __DIR__ . '/debug-log.txt');

// Jednorazové upratanie: starý log z koreňa appky presunieme k novému a z webu zmizne.
$oldDebugLog = __DIR__ . '/../debug-log.txt';
if (is_file($oldDebugLog)) {
    $oldContent = @file_get_contents($oldDebugLog);
    if ($oldContent === false || $oldContent === ''
        || @file_put_contents(DEBUG_LOG_FILE, $oldContent, FILE_APPEND | LOCK_EX) !== false) {
        @unlink($oldDebugLog);
    }
}
unset($oldDebugLog, $oldContent);

function debug_log(string $message): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    $written = @file_put_contents(DEBUG_LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    if ($written === false) {
        // debug-log.txt nie je zapisovateľný → aspoň do PHP error logu hostingu
        error_log('[recepty] ' . $message);
    }
}

function app_debug_enabled(): bool
{
    $v = getenv('APP_DEBUG');
    return $v !== false && in_array(strtolower(trim($v)), ['1', 'true', 'on', 'yes'], true);
}

/** Je aktuálna požiadavka AJAX/JSON (fetch z appky), alebo bežné načítanie stránky? */
function request_wants_json(): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        return true; // všetky AJAX akcie appky sú POST
    }
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    return stripos($accept, 'text/html') === false;
}

function render_fatal_error(string $publicMessage, string $detail): void
{
    $showDetail = app_debug_enabled();
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: ' . (request_wants_json() ? 'application/json' : 'text/html') . '; charset=utf-8');
    }
    if (request_wants_json()) {
        $out = ['success' => false, 'error' => $publicMessage];
        if ($showDetail) {
            $out['debug'] = $detail;
        }
        echo json_encode($out);
        return;
    }
    echo '<!DOCTYPE html><html lang="sk"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Chyba — Evidencia receptov</title></head>'
        . '<body style="font-family:system-ui,sans-serif;max-width:720px;margin:3rem auto;padding:0 1rem;line-height:1.5">'
        . '<h1 style="font-size:1.3rem">Nastala chyba na serveri</h1>'
        . '<p>' . htmlspecialchars($publicMessage) . '</p>';
    if ($showDetail) {
        echo '<pre style="white-space:pre-wrap;background:#fee;border:1px solid #c33;padding:1rem;border-radius:8px">'
            . htmlspecialchars($detail) . '</pre>'
            . '<p style="color:#777;font-size:.9rem">Ladiaci režim je zapnutý (APP_DEBUG=1 v includes/.env). Po vyriešení ho vypni.</p>';
    }
    echo '<p><a href="login.php">Späť na prihlásenie</a></p></body></html>';
}

set_exception_handler(function (Throwable $e) {
    $detail = get_class($e) . ': ' . $e->getMessage() . "\nv " . $e->getFile() . ':' . $e->getLine();
    debug_log('UNCAUGHT EXCEPTION: ' . str_replace("\n", ' ', $detail));
    render_fatal_error('Nastala neočakávaná chyba na serveri.', $detail);
    exit;
});

set_error_handler(function ($severity, $message, $file, $line) {
    // Upozornenia knižníc na zastarané parametre (napr. pri každom prihlásení
    // biometriou) nie sú chyby appky — do logu ich nezapisujeme.
    if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
        return true;
    }
    debug_log("PHP ERROR [$severity]: $message in $file:$line");
    return false; // necháme PHP pokračovať v štandardnom správaní (napr. fatal zostane fatal)
});

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $detail = $error['message'] . "\nv " . $error['file'] . ':' . $error['line'];
        debug_log('FATAL SHUTDOWN: ' . str_replace("\n", ' ', $detail));
        render_fatal_error('Nastala neočakávaná chyba na serveri (fatal).', $detail);
    }
});
/**
 * config.php — pripojenie k databáze appky.
 *
 * Appka má VLASTNÉ prihlasovanie (includes/auth.php) aj vlastný admin
 * panel (admin/index.php) — je úplne nezávislá.
 *
 * BEZPEČNOSŤ: skutočné heslo k DB je v includes/.env (mimo tohto súboru,
 * mimo gitu, chránené cez .htaccess), config.php ho už len číta cez getenv().
 */

require_once __DIR__ . '/env.php';
load_env(__DIR__ . '/.env');

define('DB_HOST', env_required('DB_HOST'));
define('DB_NAME', env_required('DB_NAME'));
define('DB_USER', env_required('DB_USER'));
define('DB_PASS', env_required('DB_PASS'));
define('DB_CHARSET', getenv('DB_CHARSET') ?: 'utf8mb4');
define('VAPID_PUBLIC_KEY', env_required('VAPID_PUBLIC_KEY'));
define('VAPID_PRIVATE_KEY', env_required('VAPID_PRIVATE_KEY'));
define('VAPID_SUBJECT', env_required('VAPID_SUBJECT'));
define('APP_HOST', env_required('APP_HOST'));

define('SESSION_IDLE_SECONDS', 30 * 60);

// Relying Party appky pre WebAuthn/biometriu — MUSÍ zodpovedať doméne appky.
define('WEBAUTHN_RP_ID', APP_HOST);
define('WEBAUTHN_RP_NAME', 'Evidencia receptov');
define('WEBAUTHN_ORIGIN', 'https://' . APP_HOST);

$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    debug_log('Pripojenie k DB zlyhalo: ' . $e->getMessage());
    render_fatal_error('Aplikácia sa nevie pripojiť k databáze. Skúste to prosím neskôr.', 'Pripojenie k DB zlyhalo: ' . $e->getMessage());
    exit;
}

// Nastavenia session cookie sú na začiatku tohto súboru (pred session_start()).
