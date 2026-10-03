<?php
/**
 * cron.php — pravidelná úloha appky: pripomienka „dlho ste nevarili".
 *
 * Nastav v administrácii hostingu cron, ktorý raz denne (napr. o 17:00) zavolá:
 *   https://example.com/cron.php?key=TVOJ_KLUC
 * kde TVOJ_KLUC je hodnota CRON_KEY z includes/.env (ľubovoľný dlhý náhodný
 * reťazec, aspoň 16 znakov). Bez nastaveného CRON_KEY je tento súbor vypnutý.
 *
 * Aj bez cronu sa pripomienka pošle pri prvom otvorení appky v daný deň;
 * cron zaručí, že príde aj vtedy, keď appku nikto neotvoril.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: text/plain; charset=utf-8');

$expected = getenv('CRON_KEY');
$given = (string) ($_GET['key'] ?? '');
if ($expected === false || strlen($expected) < 16 || !hash_equals($expected, $given)) {
    http_response_code(403);
    exit("Zakázané.\n");
}

// Čas si určuje cron, preto tu neplatí obmedzenie na 9:00–20:00.
$sent = send_due_recipe_reminder($pdo, false);
echo $sent !== null ? "Pripomienka odoslaná: {$sent}\n" : "Dnes nie je čo pripomenúť.\n";
