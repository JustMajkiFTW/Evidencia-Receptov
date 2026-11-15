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
 * - Rýchlosť (2.7.3): premýšľanie modelu najkratšie, aké model pozná (minimal → low →
 *   bez nastavenia), pred záložným pokusom sa čaká najviac AI_TIMEOUT_PRED_ZALOHOU.
 *   Iný (rýchlejší) model len pre asistenta: AI_ASISTENT_MODEL_GEMINI / _CLAUDE / _OPENAI.
 *   Čas každého volania AI aj nástrojov sa zapíše do záznamu (AI ASISTENT … za … s).
 */

const ASISTENT_MAX_KOL = 6;               // koľkokrát smie AI v jednej odpovedi zavolať nástroje
const ASISTENT_MAX_HISTORIA = 12;         // posledné správy rozhovoru, ktoré sa posielajú AI
const ASISTENT_MAX_TEXT = 1000;           // znakov na jednu otázku
const ASISTENT_DENNY_LIMIT_PREDVOLENY = 100;
const ASISTENT_ZAPISUJUCE = ['pridaj_recept', 'zapis_varenie', 'pridaj_do_nakupu'];

/** Modely pre asistenta: AI_ASISTENT_MODEL_GEMINI=model1,model2 alebo tie isté ako pri fotke. */
function asistent_modely(string $sluzba): array
{
    $nastavene = ai_env('AI_ASISTENT_MODEL_' . strtoupper($sluzba));
    $modely = $nastavene !== '' ? (preg_split('/[\s,;]+/', $nastavene, -1, PREG_SPLIT_NO_EMPTY) ?: []) : [];
    return $modely ?: ai_modely($sluzba);
}

/** Jedno volanie AI s meraním času (do $casy pribudne „1,8 s"). */
function asistent_post(string $url, array $hlavicky, array $telo, int $timeout, array &$casy): array
{
    $zaciatok = microtime(true);
    $vysledok = ai_http_post($url, $hlavicky, $telo, $timeout);
    $casy[] = round(microtime(true) - $zaciatok, 1);
    return $vysledok;
}

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
function asistent_pokyny(PDO $pdo, array $pripojenie): string
{
    $dni = ['nedeľa', 'pondelok', 'utorok', 'streda', 'štvrtok', 'piatok', 'sobota'];
    $dnes = new DateTime(app_today());
    return mcp_pokyny($pripojenie) . "\n\n"
        . 'Používateľ s tebou hovorí cez hlasového asistenta priamo v appke a tvoja odpoveď sa mu prečíta nahlas slovenským hlasom. Preto: '
        . 'odpovedaj krátko, najviac dve-tri vety, celými vetami po slovensky. Nepoužívaj markdown, odrážky, emoji, odkazy ani čísla receptov (#12). '
        . 'Množstvá a jednotky píš tak, ako sa vyslovujú (napr. „dve polievkové lyžice", „pol kila"). '
        . 'Keď vymenúvaš suroviny alebo nákup, spoj ich do vety; pri dlhom zozname povedz najdôležitejšie a koľko je ďalších. '
        . (kucharky_pocet_stran($pdo) > 0
            ? 'Keď sa pýta, čo uvariť, alebo chce recept, ktorý v appke nie je, najprv pozri do rodinných kuchárok (hladaj_v_kucharkach) a odporuč recept odtiaľ aj s knihou a stranou; '
            : '')
        . 'Ak chce nový recept a v appke ani v kuchárkach nie je, navrhni bežný overený recept z vlastných vedomostí. Keď ho chce uložiť, ulož ho celý (všetky suroviny s množstvami aj postup), '
        . 'aj keď nahlas povieš len krátke zhrnutie. Ak nie je jasné, čo chce, krátko sa opýtaj. '
        // 2.9.2: otázka je prepis reči — misheard „robili kuraciu…" zapísal varenie
        . 'Otázka je prepis reči a môže byť skomolená (napr. „koracu cigánsko" znamená „kuraciu cigánsku"); hľadaj podľa toho, čo človek pravdepodobne myslel. '
        . 'Zapisovať (zapis_varenie, pridaj_do_nakupu, pridaj_recept) smieš len vtedy, keď človek jasne povie, že to chce zapísať, pridať alebo uložiť '
        . '(„dnes sme uvarili…, zapíš to", „pridaj do nákupu…"). Otázka „kedy sme robili…" alebo veta, ktorej nerozumieš, nie je pokyn na zápis — vtedy len hľadaj, '
        . 'alebo povedz, čo si počul, a opýtaj sa. '
        . 'Keď chce niečo uložiť do poznámok, Google Keep alebo poslať, zavolaj priprav_na_ulozenie a povedz mu, nech ťukne na tlačidlo pri odpovedi. '
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
    // Len v appke (2.8): text na uloženie do Poznámok / Google Keep — telefón ho otvorí v zdieľaní po ťuknutí.
    $nastroje[] = [
        'name' => 'priprav_na_ulozenie',
        'description' => 'Pripraví text, ktorý si používateľ uloží do Poznámok, Google Keep alebo pošle správou (pri odpovedi uvidí tlačidlo '
            . '„Uložiť do poznámok"). Použi, keď chce recept, nákupný zoznam alebo čokoľvek z rozhovoru uložiť, poslať alebo exportovať. '
            . 'Text napíš celý a čitateľne: nadpis, suroviny každú na samostatný riadok, postup v očíslovaných krokoch.',
        'schema' => [
            'type' => 'object',
            'properties' => [
                'nazov' => ['type' => 'string', 'maxLength' => 150, 'description' => 'Nadpis poznámky, napr. „Bábovka s kakaom" alebo „Nákup".'],
                'text'  => ['type' => 'string', 'maxLength' => 8000, 'description' => 'Celý text poznámky.'],
            ],
            'required' => ['nazov', 'text'],
            'additionalProperties' => false,
        ],
    ];
    $pokyny = asistent_pokyny($pdo, $pripojenie);

    // Vykonanie nástroja: tie isté funkcie ako cez MCP. Zapisuje sa, čo asistent urobil.
    $stav = ['akcie' => [], 'zmeny' => false, 'konal' => false, 'zapisane' => [], 'nastroje_s' => 0.0, 'export' => null];
    $vykonaj = static function (string $nazov, array $argumenty) use ($pdo, $pripojenie, $nastroje, &$stav): array {
        if (!in_array($nazov, array_column($nastroje, 'name'), true)) {
            return ['text' => 'Neznámy nástroj ' . $nazov . '.', 'chyba' => true];
        }
        if ($nazov === 'priprav_na_ulozenie') {
            $text = is_string($argumenty['text'] ?? null) ? mb_substr(trim($argumenty['text']), 0, 8000, 'UTF-8') : '';
            if ($text === '') {
                return ['text' => 'Chýba text poznámky.', 'chyba' => true];
            }
            $stav['export'] = ['nazov' => is_string($argumenty['nazov'] ?? null) ? mb_substr(trim($argumenty['nazov']), 0, 150, 'UTF-8') : 'Recept', 'text' => $text];
            $stav['akcie'][] = ['nastroj' => $nazov, 'ok' => true];
            return ['text' => 'Pripravené. Pri odpovedi je tlačidlo „Uložiť do poznámok" — povedz používateľovi, nech naň ťukne a vyberie Poznámky alebo Google Keep.', 'chyba' => false];
        }
        $zaciatok = microtime(true);
        $vysledok = mcp_vykonaj($pdo, $pripojenie, $nazov, $argumenty);
        $stav['nastroje_s'] += microtime(true) - $zaciatok;
        $chyba = !empty($vysledok['isError']);
        $zapisuje = in_array($nazov, ASISTENT_ZAPISUJUCE, true);
        if ($zapisuje) {
            $stav['konal'] = true;
            $stav['zmeny'] = $stav['zmeny'] || !$chyba;
            if (!$chyba) { // keď potom AI zlyhá, človek sa dozvie, čo presne sa zapísalo (2.9.2)
                $stav['zapisane'][] = (string) ($vysledok['content'][0]['text'] ?? '');
            }
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
        foreach (asistent_modely($sluzba) as $model) {
            $pokusy[] = [$sluzba, $model];
        }
    }
    @set_time_limit(AI_TIMEOUT_SEKUND * (ASISTENT_MAX_KOL + 1) + 60);

    $chyby = [];
    $zlyKluc = [];
    $zaciatokCelku = microtime(true);
    foreach ($pokusy as $i => [$sluzba, $model]) {
        if (isset($zlyKluc[$sluzba])) {
            continue;
        }
        // Keď je ešte záložný pokus, na pomalý (preťažený) model sa nečaká celú minútu.
        $timeout = $i === count($pokusy) - 1 ? AI_TIMEOUT_SEKUND : AI_TIMEOUT_PRED_ZALOHOU;
        $casy = [];
        $vysledok = match ($sluzba) {
            'gemini' => asistent_gemini($model, $pokyny, $spravy, $nastroje, $vykonaj, $timeout, $casy),
            'claude' => asistent_claude($model, $pokyny, $spravy, $nastroje, $vykonaj, $timeout, $casy),
            default  => asistent_openai($model, $pokyny, $spravy, $nastroje, $vykonaj, $timeout, $casy),
        };
        if ($vysledok['ok']) {
            asistent_zapis_pouzitie($pdo, $pripojenie['user_id'], $sluzba, true, $chyby ? implode('; ', $chyby) : null);
            $trvanie = round(microtime(true) - $zaciatokCelku, 1);
            $rozpis = 'volania AI ' . implode(' + ', array_map(static fn($c) => str_replace('.', ',', (string) $c) . ' s', $casy))
                . ', nástroje ' . str_replace('.', ',', (string) round($stav['nastroje_s'], 1)) . ' s';
            debug_log('AI ASISTENT ' . $sluzba . ' (' . $model . '): odpoveď za ' . sprintf('%.1f s', $trvanie) . ' (' . $rozpis . ')'
                . ($stav['akcie'] ? ', nástroje: ' . implode(', ', array_column($stav['akcie'], 'nastroj')) : '')
                . ($chyby ? ', predtým: ' . implode('; ', $chyby) : ''));
            $odpoved = trim($vysledok['text']);
            $meta = AI_NAZVY[$sluzba] . ' · ' . str_replace('.', ',', (string) $trvanie) . ' s';
            return [
                'trvanie' => $trvanie,
                // Pre admina rozpis času pod odpoveďou — podľa neho sa dá zistiť, čo asistenta brzdí.
                'meta'    => $podrobnosti ? $meta . ' (' . $model . ': ' . $rozpis . ($chyby ? '; predtým zlyhalo: ' . implode('; ', $chyby) : '') . ')' : null,
                'success' => true,
                'odpoved' => $odpoved !== '' ? $odpoved : 'Hotovo.',
                'zmeny'   => $stav['zmeny'],
                'akcie'   => $stav['akcie'],
                'poskytovatel' => AI_NAZVY[$sluzba],
                'export'  => $stav['export'], // text na uloženie do poznámok (tlačidlo pri odpovedi)
                'zostava' => $limit > 0 ? max(0, $limit - asistent_dnes_pouzite($pdo, $pripojenie['user_id'])) : null,
            ];
        }
        if (!empty($vysledok['zly_kluc'])) {
            $zlyKluc[$sluzba] = true;
        }
        $chyby[] = AI_NAZVY[$sluzba] . ': ' . $vysledok['chyba'] . ($casy ? ' (' . str_replace('.', ',', (string) array_sum($casy)) . ' s)' : '');
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
            ? ($stav['zapisane']
                ? 'Asistent nestihol odpovedať, ale už v appke urobil toto: ' . implode(' ', array_filter($stav['zapisane']))
                    . ' Ak to nemalo byť, oprav to v appke.'
                : 'Asistent nestihol odpovedať, ale niečo už v appke urobil. Skontroluj recepty alebo nákup.')
            : 'Asistent teraz neodpovedá. Skús to o chvíľu znova.',
        'zmeny'   => $stav['zmeny'],
    ];
    if ($podrobnosti) {
        $odpoved['detail'] = implode(' · ', $chyby);
    }
    return $odpoved;
}

/* ----------------------------------------------------------------------
   Prepis hlasu (2.8): na iPhone rozpoznávanie reči v Safari po prvom
   prečítaní odpovede nahlas prestane fungovať (chyba WebKitu), preto telefón
   zvuk nahrá a prepíše ho AI tu na serveri — Gemini (zvuk priamo v požiadavke)
   alebo OpenAI (prepis zvuku). Claude zvuk neprijíma, ten sa preskočí.
   ---------------------------------------------------------------------- */

const ASISTENT_ZVUK_MAX = 10 * 1024 * 1024;
const ASISTENT_ZVUK_TYPY = [
    'audio/mp4' => 'audio/mp4', 'audio/x-m4a' => 'audio/mp4', 'audio/m4a' => 'audio/mp4', 'video/mp4' => 'audio/mp4',
    'audio/aac' => 'audio/aac', 'audio/webm' => 'audio/webm', 'video/webm' => 'audio/webm',
    'audio/ogg' => 'audio/ogg', 'application/ogg' => 'audio/ogg', 'audio/wav' => 'audio/wav', 'audio/x-wav' => 'audio/wav',
    'audio/wave' => 'audio/wav', 'audio/mpeg' => 'audio/mp3',
];

/** Vie niektorá služba z AI_PORADIE prepisovať zvuk (Gemini alebo OpenAI)? */
function asistent_prepis_dostupny(): bool
{
    return (bool) array_intersect(ai_poradie(), ['gemini', 'openai']);
}

/** Prepis nahrávky. Návrat: ['ok' => true, 'text' => …] alebo ['ok' => false, 'chyba' => …]. */
function asistent_prepis(array $subor, ?PDO $pdo = null): array
{
    if (($subor['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($subor['tmp_name'] ?? ''))) {
        return ['ok' => false, 'chyba' => 'Nahrávka neprišla celá, skús to znova.'];
    }
    if ((int) $subor['size'] > ASISTENT_ZVUK_MAX) {
        return ['ok' => false, 'chyba' => 'Nahrávka je príliš dlhá. Povedz to stručnejšie.'];
    }
    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
    $zistene = $finfo ? (string) finfo_file($finfo, $subor['tmp_name']) : '';
    $udane = strtolower(trim(explode(';', (string) ($subor['type'] ?? ''))[0]));
    $mime = ASISTENT_ZVUK_TYPY[$zistene] ?? ASISTENT_ZVUK_TYPY[$udane] ?? null;
    if ($mime === null || (!isset(ASISTENT_ZVUK_TYPY[$zistene]) && !in_array($zistene, ['application/octet-stream', ''], true))) {
        return ['ok' => false, 'chyba' => 'Nahrávka má neznámy formát (' . ($zistene ?: $udane) . ').'];
    }
    $data = (string) file_get_contents($subor['tmp_name']);
    $pokyn = 'Prepíš presne, čo človek v nahrávke povedal (po slovensky, prípadne česky). Vráť len prepis bez úvodzoviek a bez komentárov. '
        . 'Ak v nahrávke nie je žiadna reč, vráť prázdny text.';
    // 2.9.2: názvy receptov z appky — AI ich potom napíše správne („kuraciu cigánsku", nie „koracu cigánsko").
    $slovnik = ''; // pre OpenAI (parameter prompt)
    if ($pdo !== null) {
        try {
            $nazvy = $pdo->query('SELECT name FROM recipes ORDER BY last_made_date IS NULL, last_made_date DESC, id DESC LIMIT 200')->fetchAll(PDO::FETCH_COLUMN);
            if ($nazvy) {
                $pokyn .= ' Človek sa zvyčajne pýta na recepty, varenie alebo nákup (napr. „Kedy sme naposledy robili…?", „Čo treba kúpiť?"). '
                    . 'Môžu zaznieť názvy receptov z rodinnej appky (v hocijakom páde) — keď zaznie niektorý z nich, napíš ho správne: '
                    . implode('; ', $nazvy) . '.';
                $slovnik = mb_substr('Kedy sme naposledy robili? Čo treba kúpiť? Recepty: ' . implode(', ', $nazvy) . '.', 0, 800, 'UTF-8');
            }
        } catch (Throwable $e) {
            // bez názvov receptov sa prepisuje ako doteraz
        }
    }

    $chyby = [];
    foreach (ai_poradie() as $sluzba) {
        $zaciatok = microtime(true);
        if ($sluzba === 'gemini') {
            // 2.9.1: skúsi sa každý model z AI_ASISTENT_MODEL_GEMINI (inak AI_MODEL_GEMINI), nielen prvý —
            // preťažený alebo minutý model inak znamenal „nepodarilo sa prepísať".
            $vysledok = null;
            foreach (asistent_modely('gemini') as $model) {
                $zaciatok = microtime(true);
                foreach (['minimal', 'low', null] as $uroven) {
                    $telo = ['contents' => [['role' => 'user', 'parts' => [
                        ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($data)]], ['text' => $pokyn],
                    ]]]];
                    if ($uroven !== null) {
                        $telo['generationConfig'] = ['thinkingConfig' => ['thinkingLevel' => $uroven]];
                    }
                    [$kod, $odpoved, $chybaCurl] = ai_http_post(ai_adresa('gemini') . '/models/' . rawurlencode($model) . ':generateContent',
                        ['x-goog-api-key: ' . ai_env(AI_KLUCE_ENV['gemini'])], $telo, 30);
                    if ($kod === 400 && $uroven !== null) {
                        continue; // model nepozná nastavenie premýšľania — skúsi sa bez neho
                    }
                    break;
                }
                if ($kod === 200) {
                    $json = json_decode($odpoved, true);
                    $vysledok = ai_text_odpovede('gemini', $json);
                    if ($vysledok === null) {
                        // 200 bez textu: zablokované alebo prázdne — povieme prečo
                        $dovod = $json['promptFeedback']['blockReason'] ?? $json['candidates'][0]['finishReason'] ?? 'bez kandidátov';
                        $chyby[] = 'Gemini: ' . $model . ' — odpoveď bez textu (' . $dovod . ')';
                    }
                } else {
                    $chyby[] = 'Gemini: ' . $model . ' — ' . ai_popis_chyby($kod, $odpoved, $chybaCurl);
                }
                if ($vysledok !== null) {
                    break;
                }
            }
            if ($vysledok === null) {
                continue; // chyby sú už zapísané, ďalšia služba v poradí
            }
        } elseif ($sluzba === 'openai') {
            $model = ai_env('AI_PREPIS_MODEL_OPENAI') ?: 'gpt-4o-mini-transcribe';
            $pripona = ['audio/mp4' => 'm4a', 'audio/webm' => 'webm', 'audio/ogg' => 'ogg', 'audio/wav' => 'wav', 'audio/mp3' => 'mp3', 'audio/aac' => 'aac'][$mime];
            $ch = curl_init(ai_adresa('openai') . '/audio/transcriptions');
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . ai_env(AI_KLUCE_ENV['openai'])],
                CURLOPT_POSTFIELDS => array_filter(['model' => $model, 'language' => 'sk', 'response_format' => 'json', 'prompt' => $slovnik,
                    'file' => new CURLFile($subor['tmp_name'], $mime, 'nahravka.' . $pripona)], static fn($v) => $v !== ''),
            ]);
            $odpoved = (string) curl_exec($ch);
            $kod = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $chybaCurl = curl_error($ch);
            curl_close($ch);
            $vysledok = $kod === 200 ? (string) (json_decode($odpoved, true)['text'] ?? '') : null;
        } else {
            continue; // Claude zvuk neprijíma
        }
        if ($vysledok !== null) {
            $text = trim(preg_replace('/^["„“”\'\s]+|["„“”\'\s]+$/u', '', $vysledok) ?? '');
            // Z ticha AI „prepíše" len bodky alebo poznámku v zátvorke („...", „[ticho]") — to nie je otázka (2.9).
            if (preg_match('/^[\p{P}\p{S}\s]*$/u', $text) || preg_match('/^[\[(][^\])]{0,40}[\])]$/u', $text)) {
                $text = '';
            }
            debug_log('AI ASISTENT prepis ' . $sluzba . ' (' . $model . '): ' . sprintf('%.1f s', microtime(true) - $zaciatok) . ', ' . mb_strlen($text) . ' znakov');
            return ['ok' => true, 'text' => $text];
        }
        $chyby[] = AI_NAZVY[$sluzba] . ': ' . $model . ' — ' . ai_popis_chyby($kod, $odpoved, $chybaCurl);
    }
    debug_log('AI ASISTENT prepis nedokončil: ' . ($chyby ? implode('; ', $chyby) : 'žiadna služba nevie prepisovať zvuk'));
    return ['ok' => false, 'chyba' => $chyby ? 'Nahrávku sa teraz nepodarilo prepísať. Skús to znova alebo to napíš.'
        : 'Na prepis hlasu treba v nastaveniach kľúč Gemini alebo OpenAI.', 'detail' => implode(' · ', $chyby)];
}

/** Je dnešný limit otázok minutý? (kontroluje sa pred prepisom, aby sa zbytočne neplatil) */
function asistent_limit_minuty(PDO $pdo, int $userId): bool
{
    $limit = asistent_denny_limit();
    return $limit > 0 && asistent_dnes_pouzite($pdo, $userId) >= $limit;
}

/** Chyba volania služby v jednotnom tvare (ako ai_zavolaj). */
function asistent_chyba_sluzby(string $model, int $kod, string $telo, string $chybaCurl, int $timeout = AI_TIMEOUT_SEKUND): array
{
    $chyba = $model . ' — ' . ai_popis_chyby($kod, $telo, $chybaCurl);
    if ($chybaCurl !== '' && str_contains(strtolower($chybaCurl), 'timed out')) {
        $chyba = $model . ' — neodpovedal do ' . $timeout . ' sekúnd';
    }
    return ['ok' => false, 'chyba' => $chyba, 'zly_kluc' => in_array($kod, [401, 403], true)];
}

/* ----------------------------------------------------------------------
   Claude (Messages API): tool_use → tool_result, kým nepríde text.
   ---------------------------------------------------------------------- */
function asistent_claude(string $model, string $pokyny, array $spravy, array $nastroje, callable $vykonaj, int $timeout, array &$casy): array
{
    $sprava = array_map(static fn($s) => ['role' => $s['rola'] === 'asistent' ? 'assistant' : 'user', 'content' => $s['text']], $spravy);
    $tools = array_map(static fn($n) => ['name' => $n['name'], 'description' => $n['description'], 'input_schema' => $n['schema']], $nastroje);

    for ($kolo = 0; $kolo <= ASISTENT_MAX_KOL; $kolo++) {
        [$kod, $telo, $chybaCurl] = asistent_post(
            ai_adresa('claude') . '/messages',
            ['x-api-key: ' . ai_env(AI_KLUCE_ENV['claude']), 'anthropic-version: 2023-06-01'],
            ['model' => $model, 'max_tokens' => 4000, 'system' => $pokyny, 'tools' => $tools, 'messages' => $sprava]
                + ($kolo === ASISTENT_MAX_KOL ? ['tool_choice' => ['type' => 'none']] : []),
            $timeout,
            $casy
        );
        if ($kod !== 200) {
            return asistent_chyba_sluzby($model, $kod, $telo, $chybaCurl, $timeout);
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
function asistent_gemini(string $model, string $pokyny, array $spravy, array $nastroje, callable $vykonaj, int $timeout, array &$casy): array
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
    // Čo najkratšie premýšľanie: „minimal" vedia len niektoré modely, pri 400 sa skúsi „low", potom bez nastavenia.
    $urovne = ['minimal', 'low', null];

    for ($kolo = 0; $kolo <= ASISTENT_MAX_KOL; $kolo++) {
        $telo = [
            'systemInstruction' => ['parts' => [['text' => $pokyny]]],
            'contents'          => $obsah,
            'tools'             => [['functionDeclarations' => $deklaracie]],
            'toolConfig'        => ['functionCallingConfig' => ['mode' => $kolo === ASISTENT_MAX_KOL ? 'NONE' : 'AUTO']],
        ];
        if ($urovne[0] !== null) {
            $telo['generationConfig'] = ['thinkingConfig' => ['thinkingLevel' => $urovne[0]]];
        }
        [$kod, $odpoved, $chybaCurl] = asistent_post(
            ai_adresa('gemini') . '/models/' . rawurlencode($model) . ':generateContent',
            ['x-goog-api-key: ' . ai_env(AI_KLUCE_ENV['gemini'])],
            $telo,
            $timeout,
            $casy
        );
        if ($kod === 400 && $urovne[0] !== null) {
            array_shift($urovne);
            $kolo--;
            continue;
        }
        if ($kod !== 200) {
            return asistent_chyba_sluzby($model, $kod, $odpoved, $chybaCurl, $timeout);
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
function asistent_openai(string $model, string $pokyny, array $spravy, array $nastroje, callable $vykonaj, int $timeout, array &$casy): array
{
    $vstup = array_map(static fn($s) => ['role' => $s['rola'] === 'asistent' ? 'assistant' : 'user', 'content' => $s['text']], $spravy);
    $tools = array_map(static fn($n) => ['type' => 'function', 'name' => $n['name'], 'description' => $n['description'],
        'parameters' => $n['schema'], 'strict' => false], $nastroje);
    $predosla = null;
    $urovne = ['minimal', 'low', null]; // ako pri Gemini: najkratšie premýšľanie, ktoré model pozná

    for ($kolo = 0; $kolo <= ASISTENT_MAX_KOL; $kolo++) {
        $telo = ['model' => $model, 'instructions' => $pokyny, 'input' => $vstup, 'tools' => $tools, 'max_output_tokens' => 4000,
            'tool_choice' => $kolo === ASISTENT_MAX_KOL ? 'none' : 'auto'];
        if ($predosla !== null) {
            $telo['previous_response_id'] = $predosla;
        }
        if ($urovne[0] !== null) {
            $telo['reasoning'] = ['effort' => $urovne[0]];
        }
        [$kod, $odpoved, $chybaCurl] = asistent_post(ai_adresa('openai') . '/responses',
            ['Authorization: Bearer ' . ai_env(AI_KLUCE_ENV['openai'])], $telo, $timeout, $casy);
        if ($kod === 400 && $urovne[0] !== null) {
            array_shift($urovne);
            $kolo--;
            continue;
        }
        if ($kod !== 200) {
            return asistent_chyba_sluzby($model, $kod, $odpoved, $chybaCurl, $timeout);
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
