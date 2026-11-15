<?php
/**
 * mcp.php — vstup pre AI asistenta (Claude a iní) cez MCP, verzia 2.6.
 *
 * Asistent posiela správy JSON-RPC metódou POST na adresu
 *   https://…/mcp.php?kluc=TAJNY_KLUC
 * (MCP „Streamable HTTP" bez stavu: každá požiadavka dostane hneď odpoveď JSON,
 * server nedrží session ani otvorený stream). Kľúč si používateľ vytvorí v appke
 * (Viac / Nastavenia → Pripojiť k Claude), asistent potom koná pod jeho menom.
 * Kľúč sa dá poslať aj v hlavičke Authorization: Bearer KLUC.
 *
 * Samotná logika (pripojenia, nástroje) je v includes/mcp.php.
 */
define('APP_BEZ_SESSION', true); // bez prihlásenia v prehliadači, session netreba

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ai.php';   // ai_podobna_surovina() — párovanie surovín s preklepom
require_once __DIR__ . '/includes/mcp.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

/** Odpoveď s chybou JSON-RPC a HTTP kódom (pred spracovaním správ). */
function mcp_koniec(int $httpKod, int $kod, string $sprava): never
{
    http_response_code($httpKod);
    echo json_encode(mcp_chyba(null, $kod, $sprava), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Neočakávaná chyba → odpoveď JSON-RPC namiesto HTML stránky s chybou appky.
set_exception_handler(function (Throwable $e) {
    debug_log('AI ASISTENT: UNCAUGHT EXCEPTION ' . get_class($e) . ': ' . $e->getMessage() . ' v ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(mcp_chyba(null, -32603, 'Chyba na serveri appky.'), JSON_UNESCAPED_UNICODE);
    exit;
});

$metoda = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($metoda !== 'POST') {
    // Server neposiela správy sám od seba (žiadny stream cez GET) — MCP vtedy predpisuje 405.
    header('Allow: POST');
    mcp_koniec(405, -32000, 'Táto adresa je pre AI asistenta (MCP). Vlož ju do nastavení asistenta, v prehliadači sa neotvára.');
}

if (!ai_pripojenia_available($pdo)) {
    mcp_koniec(503, -32000, 'Pripojenie k AI asistentovi ešte nie je v databáze appky pripravené (sql/migracia-2.6.sql).');
}

// Kľúč: ?kluc=… v adrese, /mcp.php/KLUC, alebo hlavička Authorization: Bearer KLUC.
$kluc = is_string($_GET['kluc'] ?? null) ? $_GET['kluc'] : '';
if ($kluc === '' && !empty($_SERVER['PATH_INFO'])) {
    $kluc = trim((string) $_SERVER['PATH_INFO'], '/');
}
$autorizacia = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($kluc === '' && preg_match('/^Bearer\s+(\S+)$/i', $autorizacia, $m)) {
    $kluc = $m[1];
}
$pripojenie = ai_pripojenie_over($pdo, $kluc);
if ($pripojenie === null) {
    // 403, nie 401: na 401 by asistent skúšal prihlásenie cez OAuth, ktoré appka nemá.
    mcp_koniec(403, -32000, 'Neplatná alebo zrušená adresa. V appke si vytvor novú (Viac / Nastavenia → Pripojiť k Claude).');
}

$telo = file_get_contents('php://input');
$data = json_decode($telo === false ? '' : $telo, true);
if (!is_array($data)) {
    mcp_koniec(400, -32700, 'Telo požiadavky nie je platný JSON.');
}

// Jedna správa, alebo dávka (zoznam správ — staršie verzie protokolu).
$jeDavka = array_is_list($data) && $data !== [];
$odpovede = [];
foreach ($jeDavka ? $data : [$data] as $sprava) {
    $odpoved = mcp_spracuj_spravu($pdo, $pripojenie, $sprava);
    if ($odpoved !== null) {
        $odpovede[] = $odpoved;
    }
}

if (!$odpovede) {
    http_response_code(202); // len oznámenia (napr. notifications/initialized) — bez odpovede
    exit;
}
echo json_encode($jeDavka ? $odpovede : $odpovede[0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
