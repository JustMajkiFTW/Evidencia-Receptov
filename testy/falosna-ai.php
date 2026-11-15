<?php
/**
 * falosna-ai.php — falošná AI služba pre test hlasového asistenta (verzia 2.7, prepis hlasu 2.8).
 *
 * Spúšťa ju testy/spusti-testy.sh ako router vstavaného servera PHP:
 *   php -S 127.0.0.1:8766 testy/falosna-ai.php
 * a appka ju dostane cez AI_ADRESA_GEMINI=http://127.0.0.1:8766/gemini atď.
 *
 * Odpovedá v tvare Gemini (generateContent), Claude (Messages) a OpenAI
 * (Responses) vrátane volania nástrojov. Podľa slov v otázke zavolá nástroj
 * appky, po výsledku nástroja odpovie „Hotovo. …". Zároveň kontroluje, či
 * appka posiela späť, čo musí (podpis pri Gemini, id volaní pri Claude
 * a OpenAI) — inak vráti 400 ako skutočná služba.
 *
 * Slová v otázke: „ahoj" (bez nástroja), „ulož recept NÁZOV", „kúpiť",
 * „nevarili", „nájdi SLOVO", „pamätáš" (zopakuje predošlú odpoveď z histórie),
 * „chyba503" (Gemini odpovie 503 — appka má skúsiť ďalšiu službu),
 * „poznámok" (priprav_na_ulozenie).
 *
 * Prepis hlasu (2.8): Gemini so zvukom v inline_data a OpenAI
 * /audio/transcriptions vrátia text, ktorý je v nahrávke za značkou „TEXT:".
 */

header('Content-Type: application/json; charset=utf-8');
$cesta = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$telo = json_decode((string) file_get_contents('php://input'), true);

/** Text za značkou TEXT: v testovacej nahrávke (skutočná AI by reč prepísala). */
function prepis_nahravky(string $data): string
{
    return preg_match('/TEXT:(.*)$/s', $data, $m) ? trim($m[1]) : '';
}

function odpovedz(int $kod, array $data): never
{
    http_response_code($kod);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function chyba(int $kod, string $sprava): never
{
    odpovedz($kod, ['error' => ['code' => $kod, 'message' => $sprava]]);
}

function bez_diakritiky(string $text): string
{
    return strtr(mb_strtolower($text, 'UTF-8'), ['á' => 'a', 'ä' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'í' => 'i', 'ĺ' => 'l', 'ľ' => 'l',
        'ň' => 'n', 'ó' => 'o', 'ô' => 'o', 'ŕ' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ý' => 'y', 'ž' => 'z']);
}

/** Čo by „model" urobil s otázkou: ['nastroj' => …, 'argumenty' => …] alebo ['text' => …]. */
function rozhodni(string $otazka, string $predoslaOdpoved): array
{
    $o = bez_diakritiky($otazka);
    if (str_contains($o, 'pamatas')) {
        return ['text' => 'Predtým som povedal: ' . ($predoslaOdpoved !== '' ? $predoslaOdpoved : '(nič)')];
    }
    if (str_contains($o, 'uloz recept')) {
        $nazov = trim(preg_replace('/^.*?ulož recept\s*/iu', '', $otazka) ?? '');
        return ['nastroj' => 'pridaj_recept', 'argumenty' => [
            'nazov' => $nazov, 'kategoria' => 'Dezerty', 'porcie' => 8, 'cas_pripravy' => 60,
            'suroviny' => [
                ['nazov' => 'Múka hladká', 'mnozstvo' => 300, 'jednotka' => 'g'],
                ['nazov' => 'Vajcia', 'mnozstvo' => 4, 'jednotka' => 'ks'],
                ['nazov' => 'Kakao', 'mnozstvo' => 2, 'jednotka' => 'PL'],
            ],
            'postup' => "1. Vymiešaj cesto.\n2. Pečieme 50 minút.",
        ]];
    }
    // 2.9.2: „zapis uvarene 12 zlyhaj" — zapíše varenie a potom „zlyhá" (ako PROHIBITED_CONTENT v Gemini).
    if (preg_match('/zapis uvarene (\d+)/u', $o, $m)) {
        return ['nastroj' => 'zapis_varenie', 'argumenty' => ['id' => (int) $m[1]]];
    }
    if (str_contains($o, 'kupit') || str_contains($o, 'nakup')) {
        return ['nastroj' => 'zobraz_nakup', 'argumenty' => []];
    }
    if (str_contains($o, 'nevarili')) {
        return ['nastroj' => 'najdi_recepty', 'argumenty' => ['zoradenie' => 'najdlhsie_nevarene', 'limit' => 3]];
    }
    if (str_contains($o, 'poznamok')) {
        return ['nastroj' => 'priprav_na_ulozenie', 'argumenty' => ['nazov' => 'Nákup', 'text' => "Múka hladká 300 g\nVajcia 4 ks"]];
    }
    if (preg_match('/najdi\s+(\S+)/u', $o, $m)) {
        return ['nastroj' => 'najdi_recepty', 'argumenty' => ['dotaz' => $m[1]]];
    }
    return ['text' => 'Ahoj, ako ti môžem pomôcť?'];
}

/**
 * Odhad výživových hodnôt (3.0): pre každý riadok „id: názov" z úlohy rovnaké hodnoty na 100 g
 * (200 kcal, 10 g bielkovín…, 1 ks = 50 g), aby test vedel prepočet overiť. Inak null.
 */
function vyziva_odpoved(string $pokyny, string $uloha): ?string
{
    if (!str_contains($pokyny, 'Odhadni výživové hodnoty')) {
        return null;
    }
    preg_match_all('/^(\d+): /m', $uloha, $m);
    $riadky = array_map(static fn($id) => ['id' => (int) $id, 'kcal' => 200, 'bielkoviny' => 10, 'sacharidy' => 20, 'cukry' => 5,
        'tuky' => 8, 'nasytene' => 3, 'vlaknina' => 2, 'sol' => 0.5, 'hustota' => 1, 'gramy_kus' => 50, 'gramy_balenie' => 0], $m[1]);
    return json_encode(['suroviny' => $riadky], JSON_UNESCAPED_UNICODE);
}

/** Prvý riadok výsledku nástroja do záverečnej odpovede. */
function hotovo(string $vysledok): string
{
    return 'Hotovo. ' . trim(strtok($vysledok, "\n") ?: $vysledok);
}

function over_nastroje(array $nazvy): void
{
    if (!in_array('pridaj_recept', $nazvy, true) || !in_array('najdi_recepty', $nazvy, true)) {
        chyba(400, 'Chýbajú nástroje appky: ' . implode(', ', $nazvy));
    }
}

/* OpenAI prepis zvuku (multipart, nie JSON) */
if ($cesta === '/openai/audio/transcriptions') {
    if (!preg_match('/^Bearer \S+/', $_SERVER['HTTP_AUTHORIZATION'] ?? '') || ($_POST['model'] ?? '') === '') {
        chyba(401, 'Chýba kľúč alebo model.');
    }
    $subor = $_FILES['file']['tmp_name'] ?? '';
    if ($subor === '' || !is_file($subor)) {
        chyba(400, 'Chýba súbor.');
    }
    odpovedz(200, ['text' => prepis_nahravky((string) file_get_contents($subor))]);
}

if (!is_array($telo)) {
    chyba(400, 'Telo nie je JSON.');
}

/* ---------------------------------- Gemini ---------------------------------- */
if (preg_match('~^/gemini/models/([^/:]+):generateContent$~', $cesta, $m)) {
    if (($_SERVER['HTTP_X_GOOG_API_KEY'] ?? '') === '') {
        chyba(403, 'Chýba kľúč.');
    }
    // Model, ktorý „nepozná" krátke premýšľanie: appka to má skúsiť znova bez neho.
    if ($m[1] === 'gemini-bez-premyslania' && isset($telo['generationConfig']['thinkingConfig'])) {
        chyba(400, 'thinkingLevel is not supported for this model.');
    }
    $casti = $telo['contents'][0]['parts'] ?? [];
    $vyziva = vyziva_odpoved((string) ($telo['systemInstruction']['parts'][0]['text'] ?? ''),
        (string) ($casti ? ($casti[count($casti) - 1]['text'] ?? '') : ''));
    if ($vyziva !== null) {
        odpovedz(200, ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => $vyziva]]], 'finishReason' => 'STOP']]]);
    }
    // Prepis hlasu: zvuk v inline_data, bez nástrojov.
    $zvuk = $telo['contents'][0]['parts'][0]['inline_data'] ?? null;
    if ($zvuk !== null) {
        if (!str_starts_with((string) ($zvuk['mime_type'] ?? ''), 'audio/')) {
            chyba(400, 'Unsupported MIME type: ' . ($zvuk['mime_type'] ?? ''));
        }
        $prepis = prepis_nahravky((string) base64_decode((string) $zvuk['data']));
        // 2.9.1: „PREPIS503" — prvý model je preťažený, appka má skúsiť ďalší model z AI_MODEL_GEMINI.
        if (str_contains($prepis, 'PREPIS503') && $m[1] === 'gemini-bez-premyslania') {
            chyba(503, 'The model is overloaded. Please try again later.');
        }
        // „VRAT_POKYN" — vráti názvy receptov z pokynu (2.9.2: AI pri prepise pozná názvy receptov).
        if (str_contains($prepis, 'VRAT_POKYN')) {
            $pokynPrepisu = (string) ($telo['contents'][0]['parts'][1]['text'] ?? '');
            odpovedz(200, ['candidates' => [['content' => ['role' => 'model', 'parts' => [
                ['text' => preg_match('/napíš ho správne: (.*)\.$/su', $pokynPrepisu, $mm) ? 'Recepty ' . $mm[1] : 'Bez receptov'],
            ]], 'finishReason' => 'STOP']]]);
        }
        // „BEZTEXTU" — odpoveď 200 bez textu (napr. zablokovaná), admin má vidieť dôvod.
        if (str_contains($prepis, 'BEZTEXTU')) {
            odpovedz(200, ['candidates' => [['finishReason' => 'OTHER']]]);
        }
        odpovedz(200, ['candidates' => [['content' => ['role' => 'model', 'parts' => [
            ['text' => '„' . $prepis . '"'],
        ]], 'finishReason' => 'STOP']]]);
    }
    over_nastroje(array_column($telo['tools'][0]['functionDeclarations'] ?? [], 'name'));
    foreach ($telo['tools'][0]['functionDeclarations'] as $d) {
        if (isset($d['parameters']) && str_contains(json_encode($d['parameters']), 'additionalProperties')) {
            chyba(400, 'Invalid JSON payload: Unknown name "additionalProperties" in ' . $d['name']);
        }
    }
    $obsah = $telo['contents'] ?? [];
    $posledny = end($obsah) ?: [];
    $otazka = '';
    $predosla = '';
    foreach ($obsah as $c) {
        foreach ($c['parts'] ?? [] as $p) {
            if (isset($p['text'])) {
                if (($c['role'] ?? '') === 'user') {
                    $otazka = $p['text'];
                } else {
                    $predosla = $p['text'];
                }
            }
        }
    }
    if (str_contains(bez_diakritiky($otazka), 'chyba503')) {
        chyba(503, 'The model is overloaded. Please try again later.');
    }
    $vysledky = array_values(array_filter($posledny['parts'] ?? [], static fn($p) => isset($p['functionResponse'])));
    if ($vysledky) {
        // Gemini 3: odpoveď modelu s volaním musí prísť späť aj s podpisom.
        $model = $obsah[count($obsah) - 2] ?? [];
        if (($model['role'] ?? '') !== 'model' || ($model['parts'][0]['thoughtSignature'] ?? '') !== 'podpis-123') {
            chyba(400, 'Function call is missing a thought_signature.');
        }
        $r = $vysledky[0]['functionResponse']['response'];
        if (str_contains(bez_diakritiky($otazka), 'zlyhaj')) {
            odpovedz(200, ['candidates' => [['finishReason' => 'PROHIBITED_CONTENT']]]);
        }
        odpovedz(200, ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => hotovo((string) ($r['result'] ?? $r['error'] ?? ''))]]], 'finishReason' => 'STOP']]]);
    }
    $rozhodnutie = rozhodni($otazka, $predosla);
    if (isset($rozhodnutie['text'])) {
        odpovedz(200, ['candidates' => [['content' => ['role' => 'model', 'parts' => [
            ['text' => 'myslím…', 'thought' => true], ['text' => $rozhodnutie['text']],
        ]], 'finishReason' => 'STOP']]]);
    }
    odpovedz(200, ['candidates' => [['content' => ['role' => 'model', 'parts' => [
        ['functionCall' => ['name' => $rozhodnutie['nastroj'], 'args' => (object) $rozhodnutie['argumenty']], 'thoughtSignature' => 'podpis-123'],
    ]], 'finishReason' => 'STOP']]]);
}

/* ---------------------------------- Claude ---------------------------------- */
if ($cesta === '/claude/messages') {
    if (($_SERVER['HTTP_X_API_KEY'] ?? '') === '' || ($_SERVER['HTTP_ANTHROPIC_VERSION'] ?? '') === '') {
        chyba(401, 'Chýba kľúč alebo anthropic-version.');
    }
    $casti = is_array($telo['messages'][0]['content'] ?? null) ? $telo['messages'][0]['content'] : [];
    $vyziva = vyziva_odpoved(is_string($telo['system'] ?? null) ? $telo['system'] : '',
        (string) ($casti ? ($casti[count($casti) - 1]['text'] ?? '') : ''));
    if ($vyziva !== null) {
        odpovedz(200, ['id' => 'msg_v', 'type' => 'message', 'role' => 'assistant', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => $vyziva]]]);
    }
    over_nastroje(array_column($telo['tools'] ?? [], 'name'));
    $spravy = $telo['messages'] ?? [];
    if (($spravy[0]['role'] ?? '') !== 'user') {
        chyba(400, 'messages: first message must use the "user" role');
    }
    $posledna = end($spravy) ?: [];
    $otazka = '';
    $predosla = '';
    foreach ($spravy as $s) {
        if (is_string($s['content'])) {
            if ($s['role'] === 'user') {
                $otazka = $s['content'];
            } else {
                $predosla = $s['content'];
            }
        }
    }
    if (is_array($posledna['content'] ?? null) && ($posledna['content'][0]['type'] ?? '') === 'tool_result') {
        if (($posledna['content'][0]['tool_use_id'] ?? '') !== 'toolu_1') {
            chyba(400, 'tool_result block(s) provided when previous message does not have tool_use blocks with matching ids');
        }
        odpovedz(200, ['id' => 'msg_2', 'type' => 'message', 'role' => 'assistant', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => hotovo((string) $posledna['content'][0]['content'])]]]);
    }
    $rozhodnutie = rozhodni($otazka, $predosla);
    if (isset($rozhodnutie['text'])) {
        odpovedz(200, ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => $rozhodnutie['text']]]]);
    }
    odpovedz(200, ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'stop_reason' => 'tool_use', 'content' => [
        ['type' => 'text', 'text' => 'Pozriem sa.'],
        ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => $rozhodnutie['nastroj'], 'input' => (object) $rozhodnutie['argumenty']],
    ]]);
}

/* ---------------------------------- OpenAI ---------------------------------- */
if ($cesta === '/openai/responses') {
    if (!preg_match('/^Bearer \S+/', $_SERVER['HTTP_AUTHORIZATION'] ?? '')) {
        chyba(401, 'Chýba kľúč.');
    }
    $vyziva = vyziva_odpoved((string) ($telo['instructions'] ?? ''), (string) ($telo['input'][0]['content'][0]['text'] ?? ''));
    if ($vyziva !== null) {
        odpovedz(200, ['id' => 'resp_v', 'output' => [['type' => 'message', 'role' => 'assistant',
            'content' => [['type' => 'output_text', 'text' => $vyziva]]]]]);
    }
    over_nastroje(array_column($telo['tools'] ?? [], 'name'));
    $vstup = $telo['input'] ?? [];
    if (isset($telo['previous_response_id'])) {
        $vystup = $vstup[0] ?? [];
        if ($telo['previous_response_id'] !== 'resp_1' || ($vystup['type'] ?? '') !== 'function_call_output' || ($vystup['call_id'] ?? '') !== 'call_1') {
            chyba(400, 'No tool output found for function call call_1.');
        }
        $text = hotovo((string) $vystup['output']);
        odpovedz(200, ['id' => 'resp_2', 'output' => [['type' => 'message', 'role' => 'assistant',
            'content' => [['type' => 'output_text', 'text' => $text]]]]]);
    }
    $otazka = '';
    $predosla = '';
    foreach ($vstup as $s) {
        if (($s['role'] ?? '') === 'user') {
            $otazka = (string) $s['content'];
        } elseif (($s['role'] ?? '') === 'assistant') {
            $predosla = (string) $s['content'];
        }
    }
    $rozhodnutie = rozhodni($otazka, $predosla);
    if (isset($rozhodnutie['text'])) {
        odpovedz(200, ['id' => 'resp_1', 'output' => [['type' => 'message', 'role' => 'assistant',
            'content' => [['type' => 'output_text', 'text' => $rozhodnutie['text']]]]]]);
    }
    odpovedz(200, ['id' => 'resp_1', 'output' => [
        ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => []],
        ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => $rozhodnutie['nastroj'],
            'arguments' => json_encode((object) $rozhodnutie['argumenty'], JSON_UNESCAPED_UNICODE)],
    ]]);
}

chyba(404, 'Neznáma cesta ' . $cesta);
