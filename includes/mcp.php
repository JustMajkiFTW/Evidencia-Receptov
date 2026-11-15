<?php
/**
 * mcp.php (includes) — pripojenie k AI asistentovi cez MCP (verzia 2.6).
 *
 * MCP (Model Context Protocol) je spoločný jazyk, cez ktorý AI asistent volá
 * funkcie inej aplikácie. Claude (a neskôr aj iní) sa pripojí na adresu
 * https://…/mcp.php?kluc=TAJNY_KLUC a potom na pokyn používateľa — aj hlasom —
 * hľadá recepty, pridáva ich, zapisuje varenie a pridáva suroviny do nákupu.
 *
 * - Každý používateľ si v appke (Viac / Nastavenia → Pripojiť k Claude) vytvorí
 *   vlastnú adresu. Všetko, čo asistent urobí, sa zapíše pod jeho menom.
 * - V databáze (tabuľka ai_pripojenia, migracia-2.6.sql) je len odtlačok
 *   kľúča (SHA-256), samotnú adresu appka ukáže iba raz.
 * - Nástroje používajú tie isté funkcie ako formulár appky (add_recipe,
 *   mark_recipe_made, shopping_add_recipe…), nič sa neobchádza.
 *
 * Tento súbor má len logiku (testovateľnú), HTTP rieši mcp.php v koreni appky.
 */

require_once __DIR__ . '/kucharky.php'; // kuchárky v PDF (2.8): nástroj hladaj_v_kucharkach
require_once __DIR__ . '/vyziva.php';   // výživové hodnoty (3.0): v zobraz_recept, odhad po uložení receptu

/** Verzie protokolu MCP, ktoré server pozná (prvá = najnovšia). */
const MCP_VERZIE_PROTOKOLU = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];
/** Koľko adries (pripojení) môže mať jeden používateľ naraz. */
const MCP_MAX_PRIPOJENI = 5;
const MCP_MAX_VYSLEDKOV = 50;

/* ======================================================================
   Pripojenia (tabuľka ai_pripojenia)
   ====================================================================== */

/** Existuje tabuľka ai_pripojenia? Bez migrácie 2.6 appka beží ďalej, len bez AI asistenta. */
function ai_pripojenia_available(PDO $pdo): bool
{
    static $available = null;
    if ($available === null) {
        try {
            $pdo->query('SELECT 1 FROM ai_pripojenia LIMIT 1');
            $available = true;
        } catch (Throwable $e) {
            debug_log('AI ASISTENT: chýba tabuľka ai_pripojenia, spusti sql/migracia-2.6.sql (' . $e->getMessage() . ')');
            $available = false;
        }
    }
    return $available;
}

/** Pripojenia používateľa (bez kľúčov — tie sa neukladajú). */
function ai_pripojenia_zoznam(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT id, nazov, created_at, last_used_at FROM ai_pripojenia WHERE user_id = ? ORDER BY id');
    $stmt->execute([$userId]);
    return array_map(static fn(array $r): array => [
        'id'        => (int) $r['id'],
        'nazov'     => $r['nazov'],
        'vytvorene' => $r['created_at'],
        'pouzite'   => $r['last_used_at'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Nové pripojenie: vygeneruje tajný kľúč, uloží jeho odtlačok a vráti celú
 * adresu. Adresa sa ukáže len teraz — neskôr sa už zistiť nedá.
 */
function ai_pripojenie_vytvor(PDO $pdo, int $userId, string $nazov): array
{
    $nazov = trim(preg_replace('/\s+/u', ' ', $nazov) ?? '');
    $nazov = $nazov === '' ? 'Claude' : mb_substr($nazov, 0, 50, 'UTF-8');

    $pocet = $pdo->prepare('SELECT COUNT(*) FROM ai_pripojenia WHERE user_id = ?');
    $pocet->execute([$userId]);
    if ((int) $pocet->fetchColumn() >= MCP_MAX_PRIPOJENI) {
        return ['success' => false, 'error' => 'Môžeš mať najviac ' . MCP_MAX_PRIPOJENI . ' pripojení. Nepoužívané najprv zruš.'];
    }

    $kluc = bin2hex(random_bytes(24));
    $pdo->prepare('INSERT INTO ai_pripojenia (user_id, nazov, kluc_hash) VALUES (?, ?, ?)')
        ->execute([$userId, $nazov, hash('sha256', $kluc)]);

    return ['success' => true, 'adresa' => mcp_adresa($kluc), 'pripojenia' => ai_pripojenia_zoznam($pdo, $userId)];
}

/** Zruší pripojenie — asistent s touto adresou sa už nedostane k appke. Len vlastné. */
function ai_pripojenie_zrus(PDO $pdo, int $userId, int $id): array
{
    $pdo->prepare('DELETE FROM ai_pripojenia WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
    return ['success' => true, 'pripojenia' => ai_pripojenia_zoznam($pdo, $userId)];
}

/** Celá adresa pre asistenta: https://doména/priečinok-appky/mcp.php?kluc=… */
function mcp_adresa(string $kluc): string
{
    $priecinok = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return 'https://' . APP_HOST . $priecinok . '/mcp.php?kluc=' . $kluc;
}

/**
 * Overí kľúč z adresy. Vráti pripojenie s používateľom, alebo null.
 * Neaktívny (zablokovaný) používateľ sa nepripojí.
 */
function ai_pripojenie_over(PDO $pdo, string $kluc): ?array
{
    if (!preg_match('/^[a-f0-9]{48}$/', $kluc)) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT p.id, p.nazov, p.user_id, u.username, u.full_name, u.role, u.active
         FROM ai_pripojenia p JOIN users u ON u.id = p.user_id
         WHERE p.kluc_hash = ?'
    );
    $stmt->execute([hash('sha256', $kluc)]);
    $riadok = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$riadok || !(int) $riadok['active']) {
        return null;
    }
    // „Naposledy použité" stačí s presnosťou na minútu — nezapisujeme pri každom volaní.
    $pdo->prepare(
        'UPDATE ai_pripojenia SET last_used_at = NOW()
         WHERE id = ? AND (last_used_at IS NULL OR last_used_at < NOW() - INTERVAL 1 MINUTE)'
    )->execute([(int) $riadok['id']]);

    return [
        'id'      => (int) $riadok['id'],
        'nazov'   => $riadok['nazov'],
        'user_id' => (int) $riadok['user_id'],
        'meno'    => trim((string) $riadok['full_name']) !== '' ? $riadok['full_name'] : $riadok['username'],
        'role'    => $riadok['role'],
    ];
}

/* ======================================================================
   JSON-RPC: spracovanie jednej správy od asistenta
   ====================================================================== */

/**
 * Spracuje jednu správu JSON-RPC. Vráti odpoveď, alebo null pri oznámení
 * (správa bez id, napr. notifications/initialized), na ktoré sa neodpovedá.
 */
function mcp_spracuj_spravu(PDO $pdo, array $pripojenie, $sprava): ?array
{
    if (!is_array($sprava) || ($sprava['jsonrpc'] ?? null) !== '2.0' || !is_string($sprava['method'] ?? null)) {
        return mcp_chyba($sprava['id'] ?? null, -32600, 'Neplatná požiadavka.');
    }
    $id = $sprava['id'] ?? null;
    $jeOznamenie = !array_key_exists('id', $sprava);
    $metoda = $sprava['method'];
    $parametre = is_array($sprava['params'] ?? null) ? $sprava['params'] : [];

    if ($jeOznamenie) {
        return null; // notifications/initialized, notifications/cancelled…
    }

    switch ($metoda) {
        case 'initialize':
            $ziadana = (string) ($parametre['protocolVersion'] ?? '');
            return mcp_vysledok($id, [
                'protocolVersion' => in_array($ziadana, MCP_VERZIE_PROTOKOLU, true) ? $ziadana : MCP_VERZIE_PROTOKOLU[0],
                'capabilities'    => ['tools' => ['listChanged' => false]],
                'serverInfo'      => ['name' => 'evidencia-receptov', 'title' => 'Evidencia receptov', 'version' => '2.6'],
                'instructions'    => mcp_pokyny($pripojenie),
            ]);
        case 'ping':
            return mcp_vysledok($id, new stdClass());
        case 'tools/list':
            return mcp_vysledok($id, ['tools' => mcp_nastroje($pdo)]);
        case 'tools/call':
            $nazov = (string) ($parametre['name'] ?? '');
            $argumenty = is_array($parametre['arguments'] ?? null) ? $parametre['arguments'] : [];
            if (!in_array($nazov, array_column(mcp_nastroje($pdo), 'name'), true)) {
                return mcp_chyba($id, -32602, 'Neznámy nástroj: ' . $nazov);
            }
            return mcp_vysledok($id, mcp_vykonaj($pdo, $pripojenie, $nazov, $argumenty));
        case 'resources/list':
            return mcp_vysledok($id, ['resources' => []]);
        case 'prompts/list':
            return mcp_vysledok($id, ['prompts' => []]);
        default:
            return mcp_chyba($id, -32601, 'Metóda nie je podporovaná: ' . $metoda);
    }
}

function mcp_vysledok($id, $vysledok): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $vysledok];
}

function mcp_chyba($id, int $kod, string $sprava): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $kod, 'message' => $sprava]];
}

/** Pokyny pre asistenta — dostane ich pri pripojení. */
function mcp_pokyny(array $pripojenie): string
{
    return 'Evidencia receptov je rodinná appka na recepty (Slovensko). Pripojený používateľ: ' . $pripojenie['meno'] . '. '
        . 'Všetko, čo cez nástroje urobíš, sa v appke zapíše pod jeho menom, ostatní členovia rodiny to uvidia. '
        . 'Rozprávaj po slovensky a stručne, appku používajú aj starší ľudia. '
        . 'Pred pridaním receptu skontroluj cez najdi_recepty, či už v appke nie je. '
        . 'Recept ukladaj až vtedy, keď o to používateľ požiada alebo to potvrdí. '
        . 'Suroviny rozpíš po jednej: slovenský názov v 1. páde, podstatné meno na začiatku ako v zozname appky '
        . '(„Múka hladká", „Vývar kurací", „Cibuľa", „Vajcia"), '
        . 'množstvo ako číslo a jednotku len z povoleného zoznamu. Postup napíš v krátkych očíslovaných krokoch. '
        . 'Po uložení povedz, čo sa uložilo a ktoré suroviny boli v appke nové. '
        . 'Recept po česky alebo v inom jazyku (z kuchárky, z webu) vždy prelož do slovenčiny — názov, suroviny aj postup. '
        . 'Keď chce len suroviny do nákupu, pridaj ich cez pridaj_do_nakupu a recept kvôli tomu neukladaj.';
}

/* ======================================================================
   Nástroje — popis pre asistenta
   ====================================================================== */

function mcp_nastroje(PDO $pdo): array
{
    $kategorie = array_values(array_filter(get_recipe_categories($pdo), static fn($k) => !$k['mimo_zoznamu']));
    $kategorieText = implode(', ', array_map(static fn($k) => $k['nazov'], $kategorie));
    $datum = ['type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$', 'description' => 'Dátum v tvare RRRR-MM-DD, nie v budúcnosti.'];
    $idReceptu = ['type' => 'integer', 'minimum' => 1, 'description' => 'Číslo receptu (id) z najdi_recepty.'];

    $nastroje = [
        [
            'name'        => 'najdi_recepty',
            'title'       => 'Nájdi recepty',
            'description' => 'Vyhľadá recepty v rodinnej appke podľa názvu, surovín alebo kategórie (diakritika ani veľké písmená nevadia). '
                . 'Bez dotazu vráti všetky recepty. Ku každému vráti číslo (id), kategóriu, kedy sa naposledy varil a hodnotenie. '
                . 'Použi aj na otázky typu „kedy sme naposledy robili…", „čo sme dlho nevarili" alebo „čo uvariť z kuracieho mäsa". '
                . 'Všetky varenia receptu, kto ho varil a ako ho kto ohodnotil vráti historia_receptu.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'dotaz'     => ['type' => 'string', 'description' => 'Hľadané slová, napr. „sviečková" alebo „kuracie ryža". Každé slovo musí sedieť, '
                        . 'pád nevadí (napíš ich tak, ako ich človek povedal — „kuraciu cigánsku" nájde „Kuracia cigánska"). Keď presný recept nie je, appka vráti podobné.'],
                    'kategoria' => ['type' => 'string', 'description' => 'Len recepty z tejto kategórie. Kategórie: ' . $kategorieText . '.'],
                    'zoradenie' => ['type' => 'string', 'enum' => ['naposledy_varene', 'najdlhsie_nevarene', 'hodnotenie', 'nazov'],
                        'description' => 'Poradie výsledkov (predvolene naposledy_varene).'],
                    'limit'     => ['type' => 'integer', 'minimum' => 1, 'maximum' => MCP_MAX_VYSLEDKOV, 'description' => 'Najviac koľko receptov vrátiť (predvolene 15).'],
                ],
                'additionalProperties' => false,
            ],
            'annotations' => ['title' => 'Nájdi recepty', 'readOnlyHint' => true, 'openWorldHint' => false],
        ],
        [
            'name'        => 'zobraz_recept',
            'title'       => 'Zobraz recept',
            'description' => 'Celý recept z appky: suroviny s množstvami, postup, porcie, čas prípravy, kategória, kedy sa naposledy varil, odkaz do appky '
                . 'a výživové hodnoty na porciu (kcal, bielkoviny, sacharidy, tuky… — všeobecný odhad zo surovín, tak to aj povedz). '
                . 'Použi aj na otázky typu „koľko kalórií má…", „koľko bielkovín má…".',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => ['id' => $idReceptu],
                'required'   => ['id'],
                'additionalProperties' => false,
            ],
            'annotations' => ['title' => 'Zobraz recept', 'readOnlyHint' => true, 'openWorldHint' => false],
        ],
        [
            'name'        => 'pridaj_recept',
            'title'       => 'Pridaj recept',
            'description' => 'Uloží nový recept do rodinnej appky pod menom pripojeného používateľa a pošle ostatným upozornenie. '
                . 'Predtým over cez najdi_recepty, že taký recept ešte nie je. Suroviny daj po jednej: názov v 1. páde po slovensky '
                . 's podstatným menom na začiatku („Múka hladká", „Vývar kurací"), '
                . 'množstvo ako číslo a jednotku len z povoleného zoznamu (inak ju vynechaj a daj ju do poznámky). '
                . 'Suroviny, ktoré appka pozná (aj s preklepom), sa napárujú samé, nové sa pridajú do zoznamu surovín.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'nazov'    => ['type' => 'string', 'minLength' => 1, 'maxLength' => 150, 'description' => 'Názov receptu, napr. „Bábovka s kakaom".'],
                    'suroviny' => [
                        'type'  => 'array',
                        'maxItems' => 60,
                        'description' => 'Suroviny receptu.',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'nazov'    => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100, 'description' => 'Názov suroviny, napr. „Múka hladká".'],
                                'mnozstvo' => ['type' => 'number', 'exclusiveMinimum' => 0, 'description' => 'Množstvo ako číslo, napr. 250 alebo 0.5.'],
                                'jednotka' => ['type' => 'string', 'enum' => SUROVINA_JEDNOTKY, 'description' => 'Jednotka (PL = polievková lyžica, ČL = čajová lyžička).'],
                                'poznamka' => ['type' => 'string', 'maxLength' => 100, 'description' => 'Napr. „najemno nakrájaná", „podľa chuti".'],
                            ],
                            'required'   => ['nazov'],
                            'additionalProperties' => false,
                        ],
                    ],
                    'postup'          => ['type' => 'string', 'maxLength' => 5000, 'description' => 'Postup v krátkych očíslovaných krokoch, prípadne poznámky.'],
                    'kategoria'       => ['type' => 'string', 'description' => 'Jedna z kategórií: ' . $kategorieText . '.'],
                    'porcie'          => ['type' => 'integer', 'minimum' => 1, 'maximum' => 99, 'description' => 'Pre koľko porcií sú množstvá.'],
                    'cas_pripravy'    => ['type' => 'integer', 'minimum' => 1, 'maximum' => 999, 'description' => 'Čas prípravy v minútach.'],
                    'odkaz'           => ['type' => 'string', 'description' => 'Odkaz na pôvodný recept na webe (https://…), ak nejaký je.'],
                    'naposledy_varene' => $datum + ['description' => 'Kedy sa naposledy varil (RRRR-MM-DD), len ak to používateľ povedal.'],
                ],
                'required'   => ['nazov'],
                'additionalProperties' => false,
            ],
            'annotations' => ['title' => 'Pridaj recept', 'readOnlyHint' => false, 'destructiveHint' => false,
                'idempotentHint' => false, 'openWorldHint' => false],
        ],
        [
            'name'        => 'historia_receptu',
            'title'       => 'História receptu',
            'description' => 'Kedy všetky sa recept varil (od najnovšieho) a kto ho varil, koľkokrát spolu, ako ho kto ohodnotil (hviezdičky 1–5), '
                . 'kto a kedy ho pridal a jeho hlavné suroviny. Použi na „kedy sme robili…", „ako často robíme…", „kto varil…", „ako nám chutilo…".',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => ['id' => $idReceptu],
                'required'   => ['id'],
                'additionalProperties' => false,
            ],
            'annotations' => ['title' => 'História receptu', 'readOnlyHint' => true, 'openWorldHint' => false],
        ],
        [
            'name'        => 'co_sme_varili',
            'title'       => 'Čo sme varili',
            'description' => 'Čo sa v rodine varilo v zadanom období (deň po dni, kto varil). Použi na „čo sme varili minulý týždeň", '
                . '„čo sme mali v nedeľu", „čo sme robili v októbri". Dátumy vypočítaj z dnešného dňa. Bez dátumov = posledných 7 dní.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'od' => $datum + ['description' => 'Prvý deň obdobia (RRRR-MM-DD).'],
                    'do' => $datum + ['description' => 'Posledný deň obdobia (RRRR-MM-DD), predvolene dnes.'],
                ],
                'additionalProperties' => false,
            ],
            'annotations' => ['title' => 'Čo sme varili', 'readOnlyHint' => true, 'openWorldHint' => false],
        ],
        [
            'name'        => 'ohodnot_recept',
            'title'       => 'Ohodnoť recept',
            'description' => 'Uloží hodnotenie receptu hviezdičkami (1–5) pod menom používateľa; jeho predošlé hodnotenie toho receptu sa nahradí. '
                . 'Použi, len keď používateľ jasne povie hodnotenie („daj tomu päť hviezdičiek", „chutilo nám na štyri").',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'id'          => $idReceptu,
                    'hodnotenie'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5, 'description' => 'Počet hviezdičiek 1 až 5.'],
                ],
                'required'   => ['id', 'hodnotenie'],
                'additionalProperties' => false,
            ],
            'annotations' => ['title' => 'Ohodnoť recept', 'readOnlyHint' => false, 'destructiveHint' => false,
                'idempotentHint' => true, 'openWorldHint' => false],
        ],
        [
            'name'        => 'zapis_varenie',
            'title'       => 'Zapíš varenie',
            'description' => 'Zapíše, že sa recept uvaril (ako tlačidlo Uvarené v appke). Bez dátumu = dnes.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => ['id' => $idReceptu, 'datum' => $datum + ['description' => 'Kedy sa varilo (RRRR-MM-DD), predvolene dnes.']],
                'required'   => ['id'],
                'additionalProperties' => false,
            ],
            'annotations' => ['title' => 'Zapíš varenie', 'readOnlyHint' => false, 'destructiveHint' => false,
                'idempotentHint' => false, 'openWorldHint' => false],
        ],
    ];

    if (kucharky_pocet_stran($pdo) > 0) {
        $knihy = implode(', ', array_column($pdo->query("SELECT nazov FROM kucharky WHERE stav = 'ok' ORDER BY nazov")->fetchAll(PDO::FETCH_ASSOC), 'nazov'));
        $nastroje[] = [
            'name'        => 'hladaj_v_kucharkach',
            'title'       => 'Hľadaj v kuchárkach',
            'description' => 'Hľadá v rodinných kuchárkach (PDF knihy: ' . $knihy . ') a vráti strany s najlepšou zhodou: názov knihy, číslo strany a text. '
                . 'Použi, keď sa používateľ pýta, čo uvariť, hľadá inšpiráciu alebo recept, ktorý v appke nie je. Hľadaj podľa jedla alebo surovín '
                . '(napr. „kuracie mäso ryža", „bábovka", „cuketa"). Odporuč konkrétny recept a povedz knihu a stranu. Keď ho chce uložiť do appky, '
                . 'použi pridaj_recept a do postupu na koniec pridaj „Zdroj: kniha, strana". Keď chce len nakúpiť suroviny, použi pridaj_do_nakupu so surovinami '
                . '(recept neukladaj). Niektoré kuchárky sú po česky — používateľovi všetko povedz a zapíš po slovensky (názov, suroviny aj postup prelož). '
                . 'Hľadaj slovensky aj česky (napr. „zemiaky brambory").',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'dotaz' => ['type' => 'string', 'minLength' => 2, 'description' => 'Jedlo alebo suroviny, napr. „sviečková", „kuracie stehná zemiaky".'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 8, 'description' => 'Koľko strán vrátiť (predvolene 4).'],
                ],
                'required'   => ['dotaz'],
                'additionalProperties' => false,
            ],
            'annotations' => ['title' => 'Hľadaj v kuchárkach', 'readOnlyHint' => true, 'openWorldHint' => false],
        ];
    }

    if (shopping_available($pdo)) {
        $nastroje[] = [
            'name'        => 'pridaj_do_nakupu',
            'title'       => 'Pridaj suroviny do nákupu',
            'description' => 'Pridá suroviny do spoločného nákupného zoznamu v appke a ostatným príde upozornenie. Rovnaké položky sa zlúčia a množstvá sčítajú. '
                . 'Recept z appky: zadaj id (pri inom počte porcií sa množstvá prepočítajú). '
                . 'Recept, ktorý v appke nie je (z kuchárky, z webu, tvoj návrh), alebo jednotlivé veci („pridaj mlieko a chlieb"): zadaj suroviny '
                . '(slovenský názov v 1. páde ako v zozname appky, aj z českej kuchárky preložené: brambory → Zemiaky, hladká mouka → Múka hladká) '
                . 'a do jedlo názov jedla po slovensky. Kvôli nákupu recept neukladaj — pridaj_recept len keď používateľ výslovne chce recept uložiť.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'id'       => $idReceptu,
                    'porcie'   => ['type' => 'integer', 'minimum' => 1, 'maximum' => 99, 'description' => 'Na koľko porcií nakúpiť (len s id).'],
                    'suroviny' => [
                        'type'     => 'array',
                        'maxItems' => 60,
                        'description' => 'Suroviny na nákup, keď recept nie je v appke (namiesto id).',
                        'items'    => [
                            'type'       => 'object',
                            'properties' => [
                                'nazov'    => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100, 'description' => 'Slovenský názov suroviny, napr. „Múka hladká".'],
                                'mnozstvo' => ['type' => 'number', 'exclusiveMinimum' => 0, 'description' => 'Množstvo ako číslo, ak je známe.'],
                                'jednotka' => ['type' => 'string', 'enum' => SUROVINA_JEDNOTKY, 'description' => 'Jednotka (PL = polievková lyžica, ČL = čajová lyžička).'],
                            ],
                            'required'   => ['nazov'],
                            'additionalProperties' => false,
                        ],
                    ],
                    'jedlo'    => ['type' => 'string', 'maxLength' => 150, 'description' => 'Na aké jedlo sa nakupuje (po slovensky), napr. „Zemiakové placky". Pri jednotlivých veciach vynechaj.'],
                ],
                'additionalProperties' => false,
            ],
            'annotations' => ['title' => 'Pridaj suroviny do nákupu', 'readOnlyHint' => false, 'destructiveHint' => false,
                'idempotentHint' => false, 'openWorldHint' => false],
        ];
        $nastroje[] = [
            'name'        => 'zobraz_nakup',
            'title'       => 'Zobraz nákupný zoznam',
            'description' => 'Spoločný nákupný zoznam z appky: čo treba kúpiť (podľa kategórií obchodu) a čo je už kúpené.',
            'inputSchema' => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
            'annotations' => ['title' => 'Zobraz nákupný zoznam', 'readOnlyHint' => true, 'openWorldHint' => false],
        ];
    }
    return $nastroje;
}

/* ======================================================================
   Nástroje — vykonanie
   ====================================================================== */

/** Spustí nástroj a vráti výsledok v tvare MCP (content + structuredContent, pri chybe isError). */
function mcp_vykonaj(PDO $pdo, array $pripojenie, string $nazov, array $argumenty): array
{
    try {
        $vystup = match ($nazov) {
            'najdi_recepty'    => mcp_najdi_recepty($pdo, $argumenty),
            'zobraz_recept'    => mcp_zobraz_recept($pdo, $argumenty),
            'pridaj_recept'    => mcp_pridaj_recept($pdo, $pripojenie, $argumenty),
            'zapis_varenie'    => mcp_zapis_varenie($pdo, $pripojenie, $argumenty),
            'historia_receptu' => mcp_historia_receptu($pdo, $argumenty),
            'co_sme_varili'    => mcp_co_sme_varili($pdo, $argumenty),
            'ohodnot_recept'   => mcp_ohodnot_recept($pdo, $pripojenie, $argumenty),
            'pridaj_do_nakupu' => mcp_pridaj_do_nakupu($pdo, $pripojenie, $argumenty),
            'zobraz_nakup'     => mcp_zobraz_nakup($pdo),
            'hladaj_v_kucharkach' => mcp_hladaj_v_kucharkach($pdo, $argumenty),
        };
    } catch (Throwable $e) {
        debug_log('AI ASISTENT: nástroj ' . $nazov . ' zlyhal: ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')');
        $vystup = ['chyba' => 'Pri vykonaní nastala chyba na serveri appky. Skús to znova.'];
    }

    if (isset($vystup['chyba'])) {
        return ['content' => [['type' => 'text', 'text' => $vystup['chyba']]], 'isError' => true];
    }
    return [
        'content'           => [['type' => 'text', 'text' => $vystup['text']]],
        'structuredContent' => $vystup['data'],
        'isError'           => false,
    ];
}

/** Celé číslo z argumentu: 12 aj „12" (niektorí klienti posielajú čísla ako text), inak null. */
function mcp_cele_cislo($hodnota): ?int
{
    if (is_int($hodnota)) {
        return $hodnota;
    }
    if (is_float($hodnota) && floor($hodnota) === $hodnota && abs($hodnota) < PHP_INT_MAX) {
        return (int) $hodnota;
    }
    return (is_string($hodnota) && preg_match('/^\s*-?\d{1,9}\s*$/', $hodnota)) ? (int) trim($hodnota) : null;
}

/** Odkaz, ktorý otvorí recept priamo v appke. */
function mcp_odkaz_receptu(int $id): string
{
    $priecinok = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return 'https://' . APP_HOST . $priecinok . '/?recept=' . $id;
}

/** Slovenské skloňovanie podľa počtu: 1 recept, 2 recepty, 5 receptov. */
function mcp_sklonuj(int $n, string $jeden, string $dva, string $pat): string
{
    return $n . ' ' . ($n === 1 ? $jeden : ($n >= 2 && $n <= 4 ? $dva : $pat));
}

/** 0.5 → „0,5", 250.0 → „250". */
function mcp_cislo(float $cislo): string
{
    return str_replace('.', ',', rtrim(rtrim(number_format($cislo, 2, '.', ''), '0'), '.'));
}

/** „250 g Hladká múka (preosiata)" */
function mcp_davka(array $surovina): string
{
    $davka = trim(($surovina['mnozstvo'] !== null ? mcp_cislo((float) $surovina['mnozstvo']) : '') . ' ' . ($surovina['jednotka'] ?? ''));
    return trim($davka . ' ' . $surovina['nazov']) . (!empty($surovina['poznamka']) ? ' (' . $surovina['poznamka'] . ')' : '');
}

/** Kategória podľa kľúča alebo názvu (bez ohľadu na diakritiku) → kľúč, inak null. */
function mcp_kategoria(PDO $pdo, $hodnota): ?string
{
    if (!is_string($hodnota) || trim($hodnota) === '') {
        return null;
    }
    foreach (get_recipe_categories($pdo) as $k) {
        if (surovina_key($k['kluc']) === surovina_key($hodnota) || surovina_key($k['nazov']) === surovina_key($hodnota)) {
            return $k['kluc'];
        }
    }
    return null;
}

/** Kľúč bez ohľadu na poradie slov: „Hladká múka" aj „Múka hladká" → „hladka muka". */
function mcp_slova_kluc(string $nazov): string
{
    $slova = explode(' ', surovina_key($nazov));
    sort($slova);
    return implode(' ', $slova);
}

/** „pred 12 dňami", „dnes", „ešte nikdy" */
function mcp_kedy_varene(?string $datum): string
{
    $dni = days_since($datum);
    if ($dni === null) {
        return 'ešte sa nevaril';
    }
    $kedy = $dni === 0 ? 'dnes' : ($dni === 1 ? 'včera' : 'pred ' . $dni . ' dňami');
    return 'naposledy varené ' . format_sk_date($datum) . ' (' . $kedy . ')';
}

function mcp_najdi_recepty(PDO $pdo, array $a): array
{
    $kategorie = get_recipe_categories($pdo);
    $suroviny = get_all_recipe_suroviny($pdo);
    $hodnotenia = get_recipe_ratings_summary($pdo);
    $dotaz = is_string($a['dotaz'] ?? null) ? trim($a['dotaz']) : '';
    $slova = hladanie_slova($dotaz);
    $kategoria = null;
    if (is_string($a['kategoria'] ?? null) && trim($a['kategoria']) !== '') {
        $kategoria = mcp_kategoria($pdo, $a['kategoria']);
        if ($kategoria === null) {
            return ['chyba' => 'Kategóriu „' . $a['kategoria'] . '" appka nepozná. Kategórie: '
                . implode(', ', array_column($kategorie, 'nazov')) . '.'];
        }
    }
    $limit = mcp_cele_cislo($a['limit'] ?? null);
    $limit = ($limit !== null && $limit >= 1) ? min($limit, MCP_MAX_VYSLEDKOV) : 15;

    // 2.9: slová v hocijakom páde („kuraciu cigánsku"). Keď nič, druhé kolo pripustí preklep,
    // ale už len v názve, surovinách a kategórii (v dlhom postupe by „podobné" sedelo na všeličo).
    $sedi = static function (array $r, bool $podobne) use ($kategoria, $kategorie, $suroviny, $slova, $dotaz): bool {
        if ($kategoria !== null && surovina_key((string) $r['category']) !== surovina_key($kategoria)) {
            return false;
        }
        if (!$slova) {
            return true;
        }
        $text = $r['name'] . ' ' . implode(' ', array_column($suroviny[(int) $r['id']] ?? [], 'nazov')) . ' '
            . recipe_category_label($kategorie, $r['category']) . ($podobne ? '' : ' ' . ($r['ingredients'] ?? ''));
        return hladanie_zhoda($dotaz, $text, $podobne);
    };
    $recepty = get_all_recipes($pdo);
    $podobne = false;
    $vybrane = array_filter($recepty, static fn($r) => $sedi($r, false));
    if (!$vybrane && $slova) {
        $podobne = true;
        $vybrane = array_filter($recepty, static fn($r) => $sedi($r, true));
    }

    $najdene = [];
    foreach ($vybrane as $r) {
        $id = (int) $r['id'];
        $najdene[] = [
            'id'               => $id,
            'nazov'            => $r['name'],
            'kategoria'        => recipe_category_label($kategorie, $r['category']),
            'naposledy_varene' => $r['last_made_date'],
            'dni_od_varenia'   => days_since($r['last_made_date']),
            'hodnotenie'       => $hodnotenia[$id]['avg'] ?? null,
            'pocet_hodnoteni'  => $hodnotenia[$id]['count'] ?? 0,
            'suroviny'         => array_slice(array_column($suroviny[$id] ?? [], 'nazov'), 0, 15),
            'odkaz'            => mcp_odkaz_receptu($id),
        ];
    }

    // get_all_recipes() vracia od naposledy vareného, ostatné poradia tu.
    $zoradenie = $a['zoradenie'] ?? 'naposledy_varene';
    if ($zoradenie === 'najdlhsie_nevarene') {
        // nikdy nevarené najprv, potom od najstaršieho varenia
        usort($najdene, static fn($x, $y) => [$x['naposledy_varene'] !== null, $x['naposledy_varene']]
            <=> [$y['naposledy_varene'] !== null, $y['naposledy_varene']]);
    } elseif ($zoradenie === 'hodnotenie') {
        usort($najdene, static fn($x, $y) => [$y['hodnotenie'] ?? 0, $y['pocet_hodnoteni']] <=> [$x['hodnotenie'] ?? 0, $x['pocet_hodnoteni']]);
    } elseif ($zoradenie === 'nazov') {
        usort($najdene, static fn($x, $y) => strcmp(surovina_key($x['nazov']), surovina_key($y['nazov'])));
    }

    $spolu = count($najdene);
    $najdene = array_slice($najdene, 0, $limit);
    if (!$najdene) {
        return ['text' => 'Nenašiel som žiadny recept' . ($slova ? ' pre „' . $a['dotaz'] . '"' : '') . '.',
            'data' => ['pocet' => 0, 'recepty' => []]];
    }

    $riadky = array_map(static fn($r) => '#' . $r['id'] . ' ' . $r['nazov']
        . ($r['kategoria'] !== '' ? ' [' . $r['kategoria'] . ']' : '')
        . ' — ' . mcp_kedy_varene($r['naposledy_varene'])
        . ($r['hodnotenie'] !== null ? ', hodnotenie ' . mcp_cislo($r['hodnotenie']) . '/5' : ''), $najdene);
    $uvod = ($podobne ? 'Presne „' . $dotaz . '" v appke nie je, ale našiel som podobn' . ($spolu === 1 ? 'ý ' : 'é ') : 'Našiel som ')
        . mcp_sklonuj($spolu, 'recept', 'recepty', 'receptov')
        . ($spolu > count($najdene) ? ', ukazujem prvých ' . count($najdene) : '') . ':';

    return ['text' => $uvod . "\n" . implode("\n", $riadky), 'data' => ['pocet' => $spolu, 'podobne' => $podobne, 'recepty' => $najdene]];
}

function mcp_zobraz_recept(PDO $pdo, array $a): array
{
    $id = mcp_cele_cislo($a['id'] ?? null) ?? 0;
    $r = $id > 0 ? get_recipe($pdo, $id) : null;
    if (!$r) {
        return ['chyba' => 'Recept s číslom ' . $id . ' v appke nie je. Nájdi ho cez najdi_recepty.'];
    }
    $kategorie = get_recipe_categories($pdo);
    $suroviny = get_all_recipe_suroviny($pdo)[$id] ?? [];
    $hodnotenie = get_recipe_ratings_summary($pdo)[$id] ?? null;
    $autor = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(full_name), ''), username) FROM users WHERE id = ?");
    $autor->execute([(int) $r['created_by']]);

    $data = [
        'id'               => $id,
        'nazov'            => $r['name'],
        'kategoria'        => recipe_category_label($kategorie, $r['category']),
        'porcie'           => isset($r['servings']) && $r['servings'] !== null ? (int) $r['servings'] : null,
        'cas_pripravy'     => $r['prep_time'] !== null ? (int) $r['prep_time'] : null,
        'suroviny'         => $suroviny,
        'postup'           => $r['ingredients'] ?? '',
        'odkaz_na_web'     => $r['url'] ?? '',
        'naposledy_varene' => $r['last_made_date'],
        'hodnotenie'       => $hodnotenie['avg'] ?? null,
        'pridal'           => $autor->fetchColumn() ?: null,
        'odkaz'            => mcp_odkaz_receptu($id),
        'vyziva'           => vyziva_receptu($suroviny, isset($r['servings']) && $r['servings'] !== null ? (int) $r['servings'] : null, vyziva_vsetky($pdo)),
    ];

    $text = [$r['name'] . ' (#' . $id . ')'];
    $info = array_filter([
        $data['kategoria'],
        $data['porcie'] ? mcp_sklonuj($data['porcie'], 'porcia', 'porcie', 'porcií') : '',
        $data['cas_pripravy'] ? $data['cas_pripravy'] . ' min' : '',
        mcp_kedy_varene($r['last_made_date']),
    ]);
    $text[] = implode(' · ', $info);
    if ($suroviny) {
        $text[] = "\nSuroviny:\n" . implode("\n", array_map(static fn($s) => '- ' . mcp_davka($s), $suroviny));
    }
    if (trim((string) $data['postup']) !== '') {
        $text[] = "\nPostup / poznámky:\n" . trim($data['postup']);
    }
    if ($data['vyziva']) {
        $v = $data['vyziva'];
        $nezaratane = array_merge($v['bez_mnozstva'], $v['bez_hodnot']);
        $text[] = "\nVýživové hodnoty " . ($v['na'] === 'porciu' ? 'na porciu' : 'na celý recept') . ' (všeobecný odhad'
            . ($nezaratane ? ', nezarátané: ' . implode(', ', $nezaratane) : '') . '): ' . vyziva_text($v) . '.';
    }
    if ($data['odkaz_na_web'] !== '') {
        $text[] = "\nPôvodný recept: " . $data['odkaz_na_web'];
    }
    $text[] = "\nV appke: " . $data['odkaz'];

    return ['text' => implode("\n", $text), 'data' => $data];
}

/**
 * Suroviny od asistenta napárované na číselník appky (presne, poradie slov, alebo preklep ako pri AI z fotky).
 * Návrat: [riadky (id|null, nazov, mnozstvo, jednotka, poznamka), nové názvy, opravené „x → y"].
 * Spoločné pre pridaj_recept a pridaj_do_nakupu (2.9.3).
 */
function mcp_naparuj_suroviny(PDO $pdo, array $suroviny): array
{
    $text = static fn($hodnota, int $max): string => is_scalar($hodnota)
        ? mb_substr(trim(preg_replace('/[ \t]+/u', ' ', (string) $hodnota) ?? ''), 0, $max, 'UTF-8') : '';
    $katalog = [];
    $podlaSlov = []; // „hladka muka" nájde „Múka hladká" — v appke je podstatné meno na začiatku
    foreach (get_all_suroviny($pdo) as $s) {
        $katalog[surovina_key($s['nazov'])] = ['id' => (int) $s['id'], 'nazov' => $s['nazov']];
        $podlaSlov[mcp_slova_kluc($s['nazov'])] ??= ['id' => (int) $s['id'], 'nazov' => $s['nazov']];
    }
    $jednotky = [];
    foreach (SUROVINA_JEDNOTKY as $j) {
        $jednotky[mb_strtolower($j, 'UTF-8')] = $j;
    }
    $riadky = [];
    $nove = [];
    $opravene = [];
    $videne = [];
    foreach (array_slice($suroviny, 0, 60) as $polozka) {
        if (!is_array($polozka)) {
            continue;
        }
        $nazovSuroviny = surovina_display_name($text($polozka['nazov'] ?? '', 100));
        $kluc = surovina_key($nazovSuroviny);
        if ($kluc === '') {
            continue;
        }
        $zhoda = $katalog[$kluc] ?? null;
        $podobna = null;
        if (!$zhoda && $katalog) {
            $zhoda = $podlaSlov[mcp_slova_kluc($nazovSuroviny)] ?? null;
            $navrh = $zhoda ? null : ai_podobna_surovina($kluc, $katalog);
            if ($navrh && $navrh['druh'] === 'preklep') {
                $zhoda = ['id' => $navrh['id'], 'nazov' => $navrh['nazov']];
            } elseif ($navrh) {
                $podobna = $navrh['nazov']; // „Vanilkový extrakt Bourbon" ~ „Vanilkový extrakt" — necháme novú, len povieme
            }
            if ($zhoda) {
                $opravene[] = $nazovSuroviny . ' → ' . $zhoda['nazov'];
            }
        }
        $kluc = $zhoda ? surovina_key($zhoda['nazov']) : $kluc;
        if (isset($videne[$kluc])) {
            continue;
        }
        $videne[$kluc] = true;
        if (!$zhoda) {
            $nove[] = $nazovSuroviny . ($podobna ? ' (podobná v appke: ' . $podobna . ')' : '');
        }

        $jednotka = $text($polozka['jednotka'] ?? '', 20);
        $poznamka = $text($polozka['poznamka'] ?? '', 100);
        $znama = $jednotky[mb_strtolower($jednotka, 'UTF-8')] ?? null;
        if ($jednotka !== '' && $znama === null) {
            $poznamka = trim($jednotka . ($poznamka !== '' ? ', ' . $poznamka : '')); // „hrnček" a pod. do poznámky
        }
        $riadky[] = [
            'id'       => $zhoda['id'] ?? null,
            'nazov'    => $zhoda['nazov'] ?? $nazovSuroviny,
            'mnozstvo' => parse_surovina_mnozstvo(is_scalar($polozka['mnozstvo'] ?? null) ? (string) $polozka['mnozstvo'] : null),
            'jednotka' => $znama,
            'poznamka' => $poznamka !== '' ? mb_substr($poznamka, 0, 100, 'UTF-8') : null,
        ];
    }
    return [$riadky, $nove, $opravene];
}

function mcp_pridaj_recept(PDO $pdo, array $pripojenie, array $a): array
{
    $text = static fn($hodnota, int $max): string => is_scalar($hodnota)
        ? mb_substr(trim(preg_replace('/[ \t]+/u', ' ', (string) $hodnota) ?? ''), 0, $max, 'UTF-8') : '';

    $nazov = $text($a['nazov'] ?? '', 150);
    if ($nazov === '') {
        return ['chyba' => 'Chýba názov receptu.'];
    }
    // Rovnaký názov (bez ohľadu na diakritiku) — asi ten istý recept, napr. zopakovaný hlasový pokyn.
    foreach ($pdo->query('SELECT id, name FROM recipes') as $r) {
        if (surovina_key($r['name']) === surovina_key($nazov)) {
            return ['chyba' => 'Recept „' . $r['name'] . '" už v appke je (#' . $r['id'] . '). Nič som neuložil. '
                . 'Ak ide o iný recept, ulož ho pod iným názvom (napr. s menom alebo prívlastkom).'];
        }
    }

    $kategoria = null;
    $upozornenia = [];
    if ($text($a['kategoria'] ?? '', 50) !== '') {
        $kategoria = mcp_kategoria($pdo, $a['kategoria']);
        if ($kategoria === null) {
            $upozornenia[] = 'Kategóriu „' . $text($a['kategoria'], 50) . '" appka nepozná, recept je bez kategórie.';
        }
    }
    $porcie = mcp_cele_cislo($a['porcie'] ?? null);
    $porcie = ($porcie !== null && $porcie >= 1 && $porcie <= 99) ? $porcie : null;
    $cas = mcp_cele_cislo($a['cas_pripravy'] ?? null);
    $cas = ($cas !== null && $cas >= 1 && $cas <= 999) ? $cas : null;
    $odkaz = $text($a['odkaz'] ?? '', 512);
    if ($odkaz !== '' && (!filter_var($odkaz, FILTER_VALIDATE_URL) || !preg_match('~^https?://~i', $odkaz))) {
        $upozornenia[] = 'Odkaz „' . $odkaz . '" nie je platná webová adresa, neuložil som ho.';
        $odkaz = '';
    }
    $naposledy = null;
    if ($text($a['naposledy_varene'] ?? '', 10) !== '') {
        $naposledy = parse_app_date($a['naposledy_varene']);
        if ($naposledy === null) {
            return ['chyba' => 'Dátum „' . $text($a['naposledy_varene'], 20) . '" nie je platný (RRRR-MM-DD, nie v budúcnosti).'];
        }
    }
    $postup = is_string($a['postup'] ?? null) ? mb_substr(trim($a['postup']), 0, 5000, 'UTF-8') : '';

    // Suroviny: napárovať na číselník (presne, alebo preklep ako pri AI z fotky), nové sa založia pri uložení.
    [$riadky, $nove, $opravene] = mcp_naparuj_suroviny($pdo, is_array($a['suroviny'] ?? null) ? $a['suroviny'] : []);

    if (suroviny_available($pdo)) {
        $surovinyRows = $riadky;
    } else {
        // Stará databáza bez číselníka surovín: suroviny idú do textu pred postup (ako pri AI z fotky).
        $surovinyRows = null;
        $zoznam = implode("\n", array_map(static fn($s) => mcp_davka($s), $riadky));
        $postup = trim($zoznam . ($zoznam !== '' && $postup !== '' ? "\n\n" : '') . $postup);
        $nove = $opravene = [];
    }

    $vysledok = add_recipe($pdo, $pripojenie['user_id'], $nazov, $postup !== '' ? $postup : null, $odkaz !== '' ? $odkaz : null,
        $cas, $kategoria, $naposledy, null, $surovinyRows, $porcie, null);
    if (!$vysledok['success']) {
        return ['chyba' => $vysledok['error'] ?? 'Recept sa nepodarilo uložiť.'];
    }
    $id = (int) $vysledok['id'];
    vyziva_dopln_recept($pdo, $id); // výživové hodnoty nových surovín, AI až po odpovedi (3.0)

    // V histórii receptu bude vidno, že ho pridal asistent (pod menom používateľa).
    // 'poznamka' a 'cez' posiela hlasový asistent v appke (2.7), pri MCP sa skladajú z názvu pripojenia.
    $pdo->prepare("UPDATE recipe_logs SET note = ? WHERE recipe_id = ? AND action = 'created'")
        ->execute([$pripojenie['poznamka'] ?? 'Pridané cez AI asistenta (' . $pripojenie['nazov'] . ')', $id]);
    send_push_notification(
        $pdo,
        null,
        'Nový recept 🍽️',
        $pripojenie['meno'] . ' pridal(a) recept „' . $nazov . '" (cez ' . ($pripojenie['cez'] ?? $pripojenie['nazov']) . ')',
        './?recept=' . $id,
        $pripojenie['user_id']
    );

    $sprava = ['Recept „' . $nazov . '" je uložený v appke (#' . $id . ') s ' . mcp_sklonuj(count($riadky), 'surovinou', 'surovinami', 'surovinami') . '.'];
    if ($nove) {
        $sprava[] = 'Nové suroviny v zozname appky: ' . implode(', ', $nove) . '.';
    }
    if ($opravene) {
        $sprava[] = 'Napárované na suroviny z appky: ' . implode(', ', $opravene) . '.';
    }
    $sprava = array_merge($sprava, $upozornenia);
    $sprava[] = 'Odkaz: ' . mcp_odkaz_receptu($id);

    return ['text' => implode("\n", $sprava), 'data' => [
        'id' => $id, 'nazov' => $nazov, 'pocet_surovin' => count($riadky), 'nove_suroviny' => $nove,
        'naparovane_suroviny' => $opravene, 'upozornenia' => $upozornenia, 'odkaz' => mcp_odkaz_receptu($id),
    ]];
}

function mcp_zapis_varenie(PDO $pdo, array $pripojenie, array $a): array
{
    $id = mcp_cele_cislo($a['id'] ?? null) ?? 0;
    $recept = $id > 0 ? get_recipe($pdo, $id) : null;
    if (!$recept) {
        return ['chyba' => 'Recept s číslom ' . $id . ' v appke nie je. Nájdi ho cez najdi_recepty.'];
    }
    $datum = null;
    if (is_string($a['datum'] ?? null) && trim($a['datum']) !== '') {
        $datum = parse_app_date($a['datum']);
        if ($datum === null) {
            return ['chyba' => 'Dátum „' . $a['datum'] . '" nie je platný (RRRR-MM-DD, nie v budúcnosti).'];
        }
    }
    $vysledok = mark_recipe_made($pdo, $id, $pripojenie['user_id'], $datum);
    if (!$vysledok['success']) {
        return ['chyba' => $vysledok['error'] ?? 'Varenie sa nepodarilo zapísať.'];
    }
    $datum = $datum ?? app_today();
    return ['text' => 'Zapísané: „' . $recept['name'] . '" uvarené ' . format_sk_date($datum)
        . ($datum === app_today() ? ' (dnes)' : '') . '.', 'data' => ['id' => $id, 'datum' => $datum]];
}

/** „15. 11. 2026 (nedeľa)" */
function mcp_datum_s_dnom(string $datum): string
{
    $dni = ['nedeľa', 'pondelok', 'utorok', 'streda', 'štvrtok', 'piatok', 'sobota'];
    $d = DateTime::createFromFormat('!Y-m-d', $datum);
    return format_sk_date($datum) . ($d ? ' (' . $dni[(int) $d->format('w')] . ')' : '');
}

/** História receptu pre asistenta (3.3): všetky varenia, kto varil, hodnotenia, kto pridal. */
function mcp_historia_receptu(PDO $pdo, array $a): array
{
    $id = mcp_cele_cislo($a['id'] ?? null) ?? 0;
    $r = $id > 0 ? get_recipe($pdo, $id) : null;
    if (!$r) {
        return ['chyba' => 'Recept s číslom ' . $id . ' v appke nie je. Nájdi ho cez najdi_recepty.'];
    }
    $varenia = recipe_cooking_history($pdo, $id);
    $hodnotenia = recipe_ratings_by_user($pdo, $id);
    $suroviny = array_column(get_all_recipe_suroviny($pdo)[$id] ?? [], 'nazov');
    $pridal = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(u.full_name), ''), u.username) AS kto, l.created_at
        FROM recipe_logs l LEFT JOIN users u ON u.id = l.user_id WHERE l.recipe_id = ? AND l.action = 'created' ORDER BY l.id LIMIT 1");
    $pridal->execute([$id]);
    $pridal = $pridal->fetch(PDO::FETCH_ASSOC) ?: null;

    $text = [$r['name'] . ' (#' . $id . ')'];
    if ($varenia) {
        $text[] = 'Varené ' . mcp_sklonuj(count($varenia), 'raz', 'krát', 'krát') . ' (od najnovšieho): '
            . implode('; ', array_map(static fn($v) => mcp_datum_s_dnom($v['datum']) . ($v['kto'] ? ' — ' . $v['kto'] : ''), array_slice($varenia, 0, 30)))
            . (count($varenia) > 30 ? '; a ďalšie staršie' : '') . '.';
        $dni = (int) days_since($varenia[0]['datum']);
        $text[] = 'Naposledy ' . ($dni === 0 ? 'dnes' : ($dni === 1 ? 'včera' : 'pred ' . $dni . ' dňami')) . '.';
    } else {
        $text[] = 'Ešte sa nevaril (žiadne varenie nie je zapísané).';
    }
    if ($hodnotenia) {
        $priemer = array_sum(array_column($hodnotenia, 'hodnotenie')) / count($hodnotenia);
        $text[] = 'Hodnotenie: priemer ' . mcp_cislo(round($priemer, 1)) . ' z 5 ('
            . implode(', ', array_map(static fn($h) => $h['kto'] . ' ' . $h['hodnotenie'], $hodnotenia)) . ').';
    } else {
        $text[] = 'Zatiaľ ho nikto neohodnotil.';
    }
    if ($pridal) {
        $text[] = 'Pridal(a): ' . ($pridal['kto'] ?? '?') . ' ' . format_sk_date(substr((string) $pridal['created_at'], 0, 10)) . '.';
    }
    if ($suroviny) {
        $text[] = 'Suroviny: ' . implode(', ', $suroviny) . '.';
    }
    return ['text' => implode("\n", $text), 'data' => [
        'id' => $id, 'nazov' => $r['name'], 'pocet_vareni' => count($varenia), 'varenia' => $varenia,
        'hodnotenia' => $hodnotenia, 'pridal' => $pridal['kto'] ?? null, 'suroviny' => $suroviny,
    ]];
}

/** Čo sa varilo v období (3.3): „čo sme varili minulý týždeň". */
function mcp_co_sme_varili(PDO $pdo, array $a): array
{
    $dnes = app_today();
    $datum = static function ($hodnota) {
        return is_string($hodnota) && trim($hodnota) !== '' ? parse_app_date($hodnota) : null;
    };
    $do = $datum($a['do'] ?? null) ?? $dnes;
    $od = $datum($a['od'] ?? null) ?? (new DateTime($do))->modify('-6 days')->format('Y-m-d');
    if ($od > $do) {
        [$od, $do] = [$do, $od];
    }
    $varenia = recipe_cooking_history($pdo, null, $od, $do);
    $obdobie = $od === $do ? mcp_datum_s_dnom($od) : 'od ' . mcp_datum_s_dnom($od) . ' do ' . mcp_datum_s_dnom($do);
    if (!$varenia) {
        return ['text' => 'V období ' . $obdobie . ' nie je zapísané žiadne varenie.', 'data' => ['od' => $od, 'do' => $do, 'varenia' => []]];
    }
    $riadky = array_map(static fn($v) => '- ' . mcp_datum_s_dnom($v['datum']) . ': ' . $v['nazov'] . ($v['kto'] ? ' (varil(a) ' . $v['kto'] . ')' : ''),
        array_slice($varenia, 0, 60));
    return ['text' => 'Varené v období ' . $obdobie . ' — ' . mcp_sklonuj(count($varenia), 'jedlo', 'jedlá', 'jedál') . ":\n" . implode("\n", $riadky),
        'data' => ['od' => $od, 'do' => $do, 'varenia' => $varenia]];
}

/** Hodnotenie receptu hlasom (3.3). */
function mcp_ohodnot_recept(PDO $pdo, array $pripojenie, array $a): array
{
    $id = mcp_cele_cislo($a['id'] ?? null) ?? 0;
    $recept = $id > 0 ? get_recipe($pdo, $id) : null;
    if (!$recept) {
        return ['chyba' => 'Recept s číslom ' . $id . ' v appke nie je. Nájdi ho cez najdi_recepty.'];
    }
    $hviezdicky = mcp_cele_cislo($a['hodnotenie'] ?? null) ?? 0;
    if ($hviezdicky < 1 || $hviezdicky > 5) {
        return ['chyba' => 'Hodnotenie musí byť 1 až 5 hviezdičiek.'];
    }
    $vysledok = rate_recipe($pdo, $id, $pripojenie['user_id'], $hviezdicky);
    if (!$vysledok['success']) {
        return ['chyba' => $vysledok['error'] ?? 'Hodnotenie sa nepodarilo uložiť.'];
    }
    return ['text' => 'Zapísané: „' . $recept['name'] . '" — ' . mcp_sklonuj($hviezdicky, 'hviezdička', 'hviezdičky', 'hviezdičiek')
        . ' od ' . $pripojenie['meno'] . '. Priemer je teraz ' . mcp_cislo((float) $vysledok['average']) . ' z 5.',
        'data' => ['id' => $id, 'hodnotenie' => $hviezdicky, 'priemer' => $vysledok['average']]];
}

function mcp_pridaj_do_nakupu(PDO $pdo, array $pripojenie, array $a): array
{
    $cez = $pripojenie['cez'] ?? $pripojenie['nazov'];
    $id = mcp_cele_cislo($a['id'] ?? null) ?? 0;
    $suroviny = is_array($a['suroviny'] ?? null) ? $a['suroviny'] : [];

    // 2.9.3: suroviny bez receptu v appke (z kuchárky, návrh, „pridaj mlieko") — recept sa neukladá.
    if ($id <= 0 && $suroviny) {
        [$riadky] = mcp_naparuj_suroviny($pdo, $suroviny);
        if (!$riadky) {
            return ['chyba' => 'Chýbajú suroviny na pridanie.'];
        }
        $jedlo = is_string($a['jedlo'] ?? null) ? mb_substr(trim($a['jedlo']), 0, 150, 'UTF-8') : '';
        $pdo->beginTransaction();
        try {
            foreach ($riadky as $r) {
                shopping_add_item($pdo, $pripojenie['user_id'], $r['id'], $r['nazov'], $r['mnozstvo'], $r['jednotka'], $jedlo !== '' ? $jedlo : null);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            debug_log('NAKUP: pridanie surovín od asistenta zlyhalo: ' . $e->getMessage());
            return ['chyba' => 'Suroviny sa nepodarilo pridať do nákupu.'];
        }
        $pocet = count($riadky);
        $posledna = $riadky[$pocet - 1]['nazov'];
        po_odpovedi(static fn() => shopping_notify($pdo, $pripojenie['user_id'], $pripojenie['meno'], null, $posledna, $pocet, $cez,
            $jedlo !== '' ? $jedlo : null));
        return ['text' => 'Do nákupného zoznamu som pridal ' . mcp_sklonuj($pocet, 'položku', 'položky', 'položiek')
            . ($jedlo !== '' ? ' na „' . $jedlo . '"' : '') . ': ' . implode(', ', array_column($riadky, 'nazov')) . '. Recept som do appky neukladal.',
            'data' => ['pridane' => $pocet, 'polozky' => array_column($riadky, 'nazov')]];
    }

    $recept = $id > 0 ? get_recipe($pdo, $id) : null;
    if (!$recept) {
        return ['chyba' => $id > 0 ? 'Recept s číslom ' . $id . ' v appke nie je. Nájdi ho cez najdi_recepty, alebo zadaj suroviny.'
            : 'Zadaj id receptu z appky alebo suroviny na nákup.'];
    }
    $porcie = mcp_cele_cislo($a['porcie'] ?? null);
    $porcie = ($porcie !== null && $porcie >= 1 && $porcie <= 99) ? $porcie : null;
    $vysledok = shopping_add_recipe($pdo, $id, $pripojenie['user_id'], $porcie);
    if (!$vysledok['success']) {
        return ['chyba' => $vysledok['error'] ?? 'Suroviny sa nepodarilo pridať do nákupu.'];
    }
    $pocet = (int) $vysledok['added'];
    po_odpovedi(static fn() => shopping_notify($pdo, $pripojenie['user_id'], $pripojenie['meno'], $id, null, $pocet, $cez));
    $zaklad = !empty($recept['servings']) ? (int) $recept['servings'] : null;
    $naPorcie = ($porcie && $zaklad) ? ' (prepočítané na ' . mcp_sklonuj($porcie, 'porciu', 'porcie', 'porcií') . ')' : ($porcie && !$zaklad
        ? ' (recept nemá zadané porcie, množstvá sú ako v recepte)' : '');
    return ['text' => 'Do nákupného zoznamu som pridal ' . mcp_sklonuj($pocet, 'surovinu', 'suroviny', 'surovín') . ' z receptu „' . $recept['name'] . '"' . $naPorcie . '.',
        'data' => ['id' => $id, 'pridane' => $pocet]];
}

function mcp_hladaj_v_kucharkach(PDO $pdo, array $a): array
{
    $dotaz = is_string($a['dotaz'] ?? null) ? trim($a['dotaz']) : '';
    if (mb_strlen($dotaz, 'UTF-8') < 2) {
        return ['chyba' => 'Napíš, čo hľadať (jedlo alebo suroviny).'];
    }
    $limit = mcp_cele_cislo($a['limit'] ?? null);
    $vysledky = kucharky_hladaj($pdo, $dotaz, ($limit !== null && $limit >= 1) ? min($limit, 8) : 4);
    if (!$vysledky) {
        return ['text' => 'V kuchárkach som pre „' . $dotaz . '" nič nenašiel. Skús iné slová (napr. len hlavnú surovinu).',
            'data' => ['pocet' => 0, 'strany' => []]];
    }
    $casti = array_map(static fn($v) => '=== ' . $v['kniha'] . ', strana ' . $v['strana'] . " ===\n" . $v['text'], $vysledky);
    return ['text' => 'Našiel som ' . mcp_sklonuj(count($vysledky), 'stranu', 'strany', 'strán') . " v kuchárkach:\n\n" . implode("\n\n", $casti)
        . "\n\n(Ak je text po česky, používateľovi odpovedaj a všetko zapisuj po slovensky.)",
        'data' => ['pocet' => count($vysledky), 'strany' => $vysledky]];
}

function mcp_zobraz_nakup(PDO $pdo): array
{
    $polozky = get_shopping_list($pdo);
    $kupit = array_values(array_filter($polozky, static fn($p) => !$p['kupene']));
    $kupene = array_values(array_filter($polozky, static fn($p) => $p['kupene']));
    if (!$polozky) {
        return ['text' => 'Nákupný zoznam je prázdny.', 'data' => ['kupit' => [], 'kupene' => []]];
    }

    $riadky = [];
    $kategoria = null;
    foreach ($kupit as $p) {
        if ($p['kategoria'] !== $kategoria) {
            $kategoria = $p['kategoria'];
            $riadky[] = ($kategoria !== '' ? $kategoria : 'Ostatné') . ':';
        }
        $riadky[] = '- ' . mcp_davka($p + ['poznamka' => '']);
    }
    $text = $kupit ? 'Treba kúpiť (' . count($kupit) . "):\n" . implode("\n", $riadky) : 'Všetko je kúpené.';
    if ($kupene) {
        $text .= "\n\nUž kúpené: " . implode(', ', array_column($kupene, 'nazov')) . '.';
    }
    return ['text' => $text, 'data' => ['kupit' => $kupit, 'kupene' => $kupene]];
}
