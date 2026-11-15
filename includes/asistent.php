<?php
/**
 * asistent.php — hlasový asistent priamo v appke (verzia 2.7).
 *
 * Telefón rozpozná reč (alebo ju používateľ nadiktuje z klávesnice) a pošle
 * text sem. AI služba z .env (tie isté kľúče ako recept z fotky, poradie
 * AI_PORADIE) dostane nástroje appky — tie isté, aké má AI asistent cez MCP
 * (includes/mcp.php: nájdi, zobraz, pridaj recept, zapíš varenie, nákup) —
 * a volá ich, kým nemá odpoveď. Odpoveď je krátka, telefón ju prečíta nahlas
 * svojím slovenským hlasom.
 *
 * - Všetko sa zapisuje pod menom prihláseného používateľa.
 * - Denný limit AI_ASISTENT_DENNY_LIMIT (predvolene 100 otázok na používateľa),
 *   počíta sa v tabuľke ai_rozpoznania (poskytovateľ „asistent:…"), zvlášť od fotiek.
 * - Keď služba zlyhá skôr, než asistent niečo zapísal, skúsi sa ďalšia v poradí.
 */

const ASISTENT_MAX_KOL = 6;               // koľkokrát smie AI v jednej odpovedi zavolať nástroje
const ASISTENT_MAX_HISTORIA = 12;         // posledné správy rozhovoru, ktoré sa posielajú AI
const ASISTENT_MAX_TEXT = 1000;           // znakov na jednu otázku
const ASISTENT_DENNY_LIMIT_PREDVOLENY = 100;
const ASISTENT_ZAPISUJUCE = ['pridaj_recept', 'zapis_varenie', 'pridaj_do_nakupu'];

function asistent_denny_limit(): int
{
    $hodnota = ai_env('AI_ASISTENT_DENNY_LIMIT');
    return ctype_digit($hodnota) ? (int) $hodnota : ASISTENT_DENNY_LIMIT_PREDVOLENY;
}

function asistent_dnes_pouzite(PDO $pdo, int $userId): int
{
    if (ai_log_available($pdo)) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ai_rozpoznania
            WHERE user_id = ? AND uspech = 1 AND poskytovatel LIKE 'asistent:%' AND created_at >= CURDATE()");
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }
    $session = $_SESSION['ai_asistent'] ?? null;
    return (is_array($session) && ($session['den'] ?? '') === date('Y-m-d')) ? (int) $session['pocet'] : 0;
}

function asistent_zapis_pouzitie(PDO $pdo, int $userId, ?string $sluzba, bool $uspech, ?string $chyba): void
{
    if (ai_log_available($pdo)) {
        $pdo->prepare('INSERT INTO ai_rozpoznania (user_id, poskytovatel, uspech, chyba) VALUES (?, ?, ?, ?)')
            ->execute([$userId, 'asistent:' . ($sluzba ?? '-'), $uspech ? 1 : 0, $chyba !== null ? mb_substr($chyba, 0, 255, 'UTF-8') : null]);
        return;
    }
    if ($uspech && session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['ai_asistent'] = ['den' => date('Y-m-d'), 'pocet' => asistent_dnes_pouzite($pdo, $userId) + 1];
    }
}

/** Pokyny pre AI: tie isté ako pre asistenta cez MCP + pravidlá pre odpoveď čítanú nahlas. */
function asistent_pokyny(array $pripojenie): string
{
    $dni = ['nedeľa', 'pondelok', 'utorok', 'streda', 'štvrtok', 'piatok', 'sobota'];
    $dnes = new DateTime(app_today());
    return mcp_pokyny($pripojenie) . "\n\n"
        . 'Používateľ s tebou hovorí cez hlasového asistenta priamo v appke a tvoja odpoveď sa mu prečíta nahlas slovenským hlasom. Preto: '
        . 'odpovedaj krátko, najviac dve-tri vety, celými vetami po slovensky. Nepoužívaj markdown, odrážky, emoji, odkazy ani čísla receptov (#12). '
        . 'Množstvá a jednotky píš tak, ako sa vyslovujú (napr. „dve polievkové lyžice", „pol kila"). '
        . 'Keď vymenúvaš suroviny alebo nákup, spoj ich do vety; pri dlhom zozname povedz najdôležitejšie a koľko je ďalších. '
        . 'Ak chce nový recept, navrhni bežný overený recept z vlastných vedomostí. Keď ho chce uložiť, ulož ho celý (všetky suroviny s množstvami aj postup), '
        . 'aj keď nahlas povieš len krátke zhrnutie. Ak nie je jasné, čo chce, krátko sa opýtaj. '
        . 'Dnes je ' . $dni[(int) $dnes->format('w')] . ' ' . format_sk_date(app_today()) . '.';
}

/**
 * Odpoveď asistenta na jednu otázku.
 * $historia = predošlé správy [['rola' => 'pouzivatel'|'asistent', 'text' => …], …] (od klienta, len text).
 * Návrat: ['success' => true, 'odpoved' => text, 'zmeny' => bool, 'akcie' => […], 'zostava' => ?int] alebo chyba.
 */
function asistent_odpovedz(PDO $pdo, array $pripojenie, string $text, array $historia, bool $podrobnosti = false): array
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    if ($text === '') {
        return ['success' => false, 'error' => 'Nič som nepočul. Skús to znova.'];
    }
    $text = mb_substr($text, 0, ASISTENT_MAX_TEXT, 'UTF-8');
    if (!ai_dostupne()) {
        return ['success' => false, 'error' => 'Asistent nie je zapnutý: v nastaveniach appky chýba kľúč k AI.'];
    }
    $limit = asistent_denny_limit();
    if ($limit > 0 && asistent_dnes_pouzite($pdo, $pripojenie['user_id']) >= $limit) {
        return ['success' => false, 'error' => 'Dnešný počet otázok pre asistenta je minutý. Zajtra to pôjde znova.'];
    }

    // Rozhovor: len text, striedavo používateľ a asistent, začína používateľom.
    $spravy = [];
    foreach (array_slice($historia, -ASISTENT_MAX_HISTORIA) as $s) {
        if (!is_array($s) || !is_string($s['text'] ?? null) || trim($s['text']) === '') {
            continue;
        }
        $rola = ($s['rola'] ?? '') === 'asistent' ? 'asistent' : 'pouzivatel';
        if (!$spravy && $rola === 'asistent') {
            continue;
        }
        $obsah = mb_substr(trim($s['text']), 0, 2000, 'UTF-8');
        if ($spravy && end($spravy)['rola'] === $rola) {
            $spravy[count($spravy) - 1]['text'] .= "\n" . $obsah;
        } else {
            $spravy[] = ['rola' => $rola, 'text' => $obsah];
        }
    }
    if ($spravy && end($spravy)['rola'] === 'pouzivatel') {
        array_pop($spravy); // otázka bez odpovede (napr. výpadok) — nová otázka ju nahradí
    }
    $spravy[] = ['rola' => 'pouzivatel', 'text' => $text];

    $nastroje = array_map(static fn(array $n): array => [
        'name' => $n['name'], 'description' => $n['description'], 'schema' => $n['inputSchema'],
    ], mcp_nastroje($pdo));
    $pokyny = asistent_pokyny($pripojenie);

    // Vykonanie nástroja: tie isté funkcie ako cez MCP. Zapisuje sa, čo asistent urobil.
    $stav = ['akcie' => [], 'zmeny' => false, 'konal' => false];
    $vykonaj = static function (string $nazov, array $argumenty) use ($pdo, $pripojenie, $nastroje, &$stav): array {
        if (!in_array($nazov, array_column($nastroje, 'name'), true)) {
            return ['text' => 'Neznámy nástroj ' . $nazov . '.', 'chyba' => true];
        }
        $vysledok = mcp_vykonaj($pdo, $pripojenie, $nazov, $argumenty);
        $chyba = !empty($vysledok['isError']);
        $zapisuje = in_array($nazov, ASISTENT_ZAPISUJUCE, true);
        if ($zapisuje) {
            $stav['konal'] = true;
            $stav['zmeny'] = $stav['zmeny'] || !$chyba;
        }
        $akcia = ['nastroj' => $nazov, 'ok' => !$chyba];
        if (!$chyba && isset($vysledok['structuredContent']['odkaz']) && $nazov === 'pridaj_recept') {
            $akcia['recept_id'] = $vysledok['structuredContent']['id'];
        }
        $stav['akcie'][] = $akcia;
        return ['text' => (string) ($vysledok['content'][0]['text'] ?? ''), 'chyba' => $chyba];
    };

    $pokusy = [];
    foreach (ai_poradie() as $sluzba) {
        foreach (ai_modely($sluzba) as $model) {
            $pokusy[] = [$sluzba, $model];
        }
    }
    @set_time_limit(AI_TIMEOUT_SEKUND * (ASISTENT_MAX_KOL + 1) + 60);

    $chyby = [];
    $zlyKluc = [];
    foreach ($pokusy as [$sluzba, $model]) {
        if (isset($zlyKluc[$sluzba])) {
            continue;
        }
        $zaciatok = microtime(true);
        $vysledok = match ($sluzba) {
            'gemini' => asistent_gemini($model, $pokyny, $spravy, $nastroje, $vykonaj),
            'claude' => asistent_claude($model, $pokyny, $spravy, $nastroje, $vykonaj),
            default  => asistent_openai($model, $pokyny, $spravy, $nastroje, $vykonaj),
        };
        if ($vysledok['ok']) {
            asistent_zapis_pouzitie($pdo, $pripojenie['user_id'], $sluzba, true, $chyby ? implode('; ', $chyby) : null);
            debug_log('AI ASISTENT ' . $sluzba . ' (' . $model . '): odpoveď za ' . sprintf('%.1f s', microtime(true) - $zaciatok)
                . ($stav['akcie'] ? ', nástroje: ' . implode(', ', array_column($stav['akcie'], 'nastroj')) : ''));
            $odpoved = trim($vysledok['text']);
            return [
                'success' => true,
                'odpoved' => $odpoved !== '' ? $odpoved : 'Hotovo.',
                'zmeny'   => $stav['zmeny'],
                'akcie'   => $stav['akcie'],
                'poskytovatel' => AI_NAZVY[$sluzba],
                'zostava' => $limit > 0 ? max(0, $limit - asistent_dnes_pouzite($pdo, $pripojenie['user_id'])) : null,
            ];
        }
        if (!empty($vysledok['zly_kluc'])) {
            $zlyKluc[$sluzba] = true;
        }
        $chyby[] = AI_NAZVY[$sluzba] . ': ' . $vysledok['chyba'];
        debug_log('AI ASISTENT ' . $sluzba . ' nedokončil: ' . $vysledok['chyba']);
        // Keď už asistent niečo zapísal, ďalšia služba by to mohla urobiť druhýkrát.
        if ($stav['konal']) {
            break;
        }
    }

    asistent_zapis_pouzitie($pdo, $pripojenie['user_id'], null, false, implode('; ', $chyby));
    $odpoved = [
        'success' => false,
        'error'   => $stav['konal']
            ? 'Asistent nestihol odpovedať, ale niečo už v appke urobil. Skontroluj recepty alebo nákup.'
            : 'Asistent teraz neodpovedá. Skús to o chvíľu znova.',
        'zmeny'   => $stav['zmeny'],
    ];
    if ($podrobnosti) {
        $odpoved['detail'] = implode(' · ', $chyby);
    }
    return $odpoved;
}

/** Chyba volania služby v jednotnom tvare (ako ai_zavolaj). */
function asistent_chyba_sluzby(string $model, int $kod, string $telo, string $chybaCurl): array
{
    $chyba = $model . ' — ' . ai_popis_chyby($kod, $telo, $chybaCurl);
    if ($chybaCurl !== '' && str_contains(strtolower($chybaCurl), 'timed out')) {
        $chyba = $model . ' — neodpovedal do ' . AI_TIMEOUT_SEKUND . ' sekúnd';
    }
    return ['ok' => false, 'chyba' => $chyba, 'zly_kluc' => in_array($kod, [401, 403], true)];
}

/* ----------------------------------------------------------------------
   Claude (Messages API): tool_use → tool_result, kým nepríde text.
   ---------------------------------------------------------------------- */
function asistent_claude(string $model, string $pokyny, array $spravy, array $nastroje, callable $vykonaj): array
{
    $sprava = array_map(static fn($s) => ['role' => $s['rola'] === 'asistent' ? 'assistant' : 'user', 'content' => $s['text']], $spravy);
    $tools = array_map(static fn($n) => ['name' => $n['name'], 'description' => $n['description'], 'input_schema' => $n['schema']], $nastroje);

    for ($kolo = 0; $kolo <= ASISTENT_MAX_KOL; $kolo++) {
        [$kod, $telo, $chybaCurl] = ai_http_post(
            ai_adresa('claude') . '/messages',
            ['x-api-key: ' . ai_env(AI_KLUCE_ENV['claude']), 'anthropic-version: 2023-06-01'],
            ['model' => $model, 'max_tokens' => 4000, 'system' => $pokyny, 'tools' => $tools, 'messages' => $sprava]
                + ($kolo === ASISTENT_MAX_KOL ? ['tool_choice' => ['type' => 'none']] : [])
        );
        if ($kod !== 200) {
            return asistent_chyba_sluzby($model, $kod, $telo, $chybaCurl);
        }
        $data = json_decode($telo, true);
        $bloky = is_array($data['content'] ?? null) ? $data['content'] : [];
        $volania = array_values(array_filter($bloky, static fn($b) => ($b['type'] ?? '') === 'tool_use'));
        if (!$volania) {
            $text = ai_text_odpovede('claude', $data);
            return $text !== null ? ['ok' => true, 'text' => $text] : ['ok' => false, 'chyba' => $model . ' — prázdna odpoveď'];
        }
        $sprava[] = ['role' => 'assistant', 'content' => $bloky];
        $vysledky = [];
        foreach ($volania as $volanie) {
            $r = $vykonaj((string) ($volanie['name'] ?? ''), is_array($volanie['input'] ?? null) ? $volanie['input'] : []);
            $vysledky[] = ['type' => 'tool_result', 'tool_use_id' => $volanie['id'] ?? '', 'content' => $r['text'], 'is_error' => $r['chyba']];
        }
        $sprava[] = ['role' => 'user', 'content' => $vysledky];
    }
    return ['ok' => false, 'chyba' => $model . ' — priveľa krokov'];
}

/* ----------------------------------------------------------------------
   Gemini (generateContent): functionCall → functionResponse. Odpoveď modelu
   sa posiela späť bez zmeny (Gemini 3 vyžaduje vrátiť „thoughtSignature").
   ---------------------------------------------------------------------- */
function asistent_gemini(string $model, string $pokyny, array $spravy, array $nastroje, callable $vykonaj): array
{
    $obsah = array_map(static fn($s) => ['role' => $s['rola'] === 'asistent' ? 'model' : 'user', 'parts' => [['text' => $s['text']]]], $spravy);
    $deklaracie = [];
    foreach ($nastroje as $n) {
        $d = ['name' => $n['name'], 'description' => $n['description']];
        if (!empty((array) ($n['schema']['properties'] ?? []))) {
            $d['parameters'] = asistent_schema_gemini($n['schema']);
        }
        $deklaracie[] = $d;
    }
    $rychlo = true; // krátke premýšľanie; keď ho model nepozná (400), skúsi sa bez neho

    for ($kolo = 0; $kolo <= ASISTENT_MAX_KOL; $kolo++) {
        $telo = [
            'systemInstruction' => ['parts' => [['text' => $pokyny]]],
            'contents'          => $obsah,
            'tools'             => [['functionDeclarations' => $deklaracie]],
            'toolConfig'        => ['functionCallingConfig' => ['mode' => $kolo === ASISTENT_MAX_KOL ? 'NONE' : 'AUTO']],
        ];
        if ($rychlo) {
            $telo['generationConfig'] = ['thinkingConfig' => ['thinkingLevel' => 'low']];
        }
        [$kod, $odpoved, $chybaCurl] = ai_http_post(
            ai_adresa('gemini') . '/models/' . rawurlencode($model) . ':generateContent',
            ['x-goog-api-key: ' . ai_env(AI_KLUCE_ENV['gemini'])],
            $telo
        );
        if ($kod === 400 && $rychlo) {
            $rychlo = false;
            $kolo--;
            continue;
        }
        if ($kod !== 200) {
            return asistent_chyba_sluzby($model, $kod, $odpoved, $chybaCurl);
        }
        $data = json_decode($odpoved, true);
        $obsahModelu = $data['candidates'][0]['content'] ?? null;
        if (!is_array($obsahModelu) || !is_array($obsahModelu['parts'] ?? null)) {
            return ['ok' => false, 'chyba' => $model . ' — prázdna odpoveď (' . ($data['candidates'][0]['finishReason'] ?? 'bez dôvodu') . ')'];
        }
        $volania = array_values(array_filter($obsahModelu['parts'], static fn($p) => isset($p['functionCall'])));
        if (!$volania) {
            $text = ai_text_odpovede('gemini', $data);
            return $text !== null ? ['ok' => true, 'text' => $text] : ['ok' => false, 'chyba' => $model . ' — prázdna odpoveď'];
        }
        $obsahModelu['role'] = 'model';
        $obsah[] = $obsahModelu;
        $odpovede = [];
        foreach ($volania as $p) {
            $volanie = $p['functionCall'];
            $r = $vykonaj((string) ($volanie['name'] ?? ''), is_array($volanie['args'] ?? null) ? $volanie['args'] : []);
            $vysledok = ['name' => $volanie['name'] ?? '', 'response' => $r['chyba'] ? ['error' => $r['text']] : ['result' => $r['text']]];
            if (isset($volanie['id'])) {
                $vysledok['id'] = $volanie['id'];
            }
            $odpovede[] = ['functionResponse' => $vysledok];
        }
        $obsah[] = ['role' => 'user', 'parts' => $odpovede];
    }
    return ['ok' => false, 'chyba' => $model . ' — priveľa krokov'];
}

/** Schéma pre Gemini: len kľúče, ktoré jeho „parameters" poznajú (bez additionalProperties, pattern…). */
function asistent_schema_gemini(array $schema): array
{
    $povolene = ['type', 'description', 'enum', 'required', 'minimum', 'maximum', 'minItems', 'maxItems'];
    $vysledok = array_intersect_key($schema, array_flip($povolene));
    if (isset($schema['properties']) && (array) $schema['properties']) {
        $vysledok['properties'] = array_map(static fn($p) => asistent_schema_gemini((array) $p), (array) $schema['properties']);
    }
    if (isset($schema['items']) && is_array($schema['items'])) {
        $vysledok['items'] = asistent_schema_gemini($schema['items']);
    }
    return $vysledok;
}

/* ----------------------------------------------------------------------
   OpenAI (Responses API): function_call → function_call_output, ďalšie kolo
   nadväzuje cez previous_response_id.
   ---------------------------------------------------------------------- */
function asistent_openai(string $model, string $pokyny, array $spravy, array $nastroje, callable $vykonaj): array
{
    $vstup = array_map(static fn($s) => ['role' => $s['rola'] === 'asistent' ? 'assistant' : 'user', 'content' => $s['text']], $spravy);
    $tools = array_map(static fn($n) => ['type' => 'function', 'name' => $n['name'], 'description' => $n['description'],
        'parameters' => $n['schema'], 'strict' => false], $nastroje);
    $predosla = null;
    $rychlo = true;

    for ($kolo = 0; $kolo <= ASISTENT_MAX_KOL; $kolo++) {
        $telo = ['model' => $model, 'instructions' => $pokyny, 'input' => $vstup, 'tools' => $tools, 'max_output_tokens' => 4000,
            'tool_choice' => $kolo === ASISTENT_MAX_KOL ? 'none' : 'auto'];
        if ($predosla !== null) {
            $telo['previous_response_id'] = $predosla;
        }
        if ($rychlo) {
            $telo['reasoning'] = ['effort' => 'low'];
        }
        [$kod, $odpoved, $chybaCurl] = ai_http_post(ai_adresa('openai') . '/responses', ['Authorization: Bearer ' . ai_env(AI_KLUCE_ENV['openai'])], $telo);
        if ($kod === 400 && $rychlo) {
            $rychlo = false;
            $kolo--;
            continue;
        }
        if ($kod !== 200) {
            return asistent_chyba_sluzby($model, $kod, $odpoved, $chybaCurl);
        }
        $data = json_decode($odpoved, true);
        $volania = array_values(array_filter(is_array($data['output'] ?? null) ? $data['output'] : [],
            static fn($o) => ($o['type'] ?? '') === 'function_call'));
        if (!$volania) {
            $text = ai_text_odpovede('openai', $data);
            return $text !== null ? ['ok' => true, 'text' => $text] : ['ok' => false, 'chyba' => $model . ' — prázdna odpoveď'];
        }
        $predosla = (string) ($data['id'] ?? '');
        $vstup = [];
        foreach ($volania as $volanie) {
            $argumenty = json_decode((string) ($volanie['arguments'] ?? ''), true);
            $r = $vykonaj((string) ($volanie['name'] ?? ''), is_array($argumenty) ? $argumenty : []);
            $vstup[] = ['type' => 'function_call_output', 'call_id' => $volanie['call_id'] ?? '', 'output' => ($r['chyba'] ? 'CHYBA: ' : '') . $r['text']];
        }
    }
    return ['ok' => false, 'chyba' => $model . ' — priveľa krokov'];
}
