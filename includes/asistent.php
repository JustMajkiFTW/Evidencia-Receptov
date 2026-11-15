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
 *   Iný (rýchlejší) model len pre asistenta: AI_ASISTENT_MODEL_GEMINI / _CLAUDE / _OPENAI / _MISTRAL / _GROQ.
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
        . (shopping_available($pdo) && array_filter(ai_poradie(), 'ai_vie_obrazky') ? 'Keď chce pridať do nákupu niečo z fotky (recept z kuchárky alebo z obrazovky, nákupný zoznam na papieri, jedlo), zavolaj odfot_na_nakup — '
            . 'appka ukáže tlačidlá Odfotiť a Z galérie a suroviny z fotky do nákupu pridá sama; ty len krátko povedz, nech odfotí alebo vyberie fotku. ' : '')
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
    // Len v appke (3.1): fotka do nákupu — telefón ukáže tlačidlá Odfotiť / Z galérie, fotku spracuje asistent_fotka_do_nakupu().
    // Potrebuje službu, ktorá vie čítať fotky (Groq nie).
    if (shopping_available($pdo) && array_filter(ai_poradie(), 'ai_vie_obrazky')) {
        $nastroje[] = [
            'name' => 'odfot_na_nakup',
            'description' => 'Ukáže používateľovi tlačidlá Odfotiť a Z galérie. Z fotky (recept z kuchárky alebo obrazovky, nákupný zoznam, jedlo) '
                . 'appka sama vytiahne suroviny a pridá ich do nákupu. Použi, keď chce do nákupu pridať niečo „z fotky", „odfotím ti to", '
                . '„mám to v kuchárke"… Ty fotku nevidíš a nič nepridávaš — len povedz, nech odfotí alebo vyberie fotku.',
            'schema' => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
        ];
    }
    $pokyny = asistent_pokyny($pdo, $pripojenie);

    // Vykonanie nástroja: tie isté funkcie ako cez MCP. Zapisuje sa, čo asistent urobil.
    $stav = ['akcie' => [], 'zmeny' => false, 'konal' => false, 'zapisane' => [], 'nastroje_s' => 0.0, 'export' => null, 'fotka' => null];
    $vykonaj = static function (string $nazov, array $argumenty) use ($pdo, $pripojenie, $nastroje, &$stav): array {
        if (!in_array($nazov, array_column($nastroje, 'name'), true)) {
            return ['text' => 'Neznámy nástroj ' . $nazov . '.', 'chyba' => true];
        }
        if ($nazov === 'odfot_na_nakup') {
            $stav['fotka'] = 'nakup';
            $stav['akcie'][] = ['nastroj' => $nazov, 'ok' => true];
            return ['text' => 'Appka teraz pod odpoveďou ukáže tlačidlá Odfotiť a Z galérie. Povedz používateľovi jednou vetou, nech odfotí recept, '
                . 'kuchárku alebo nákupný zoznam, alebo vyberie fotku z galérie — suroviny potom pridá do nákupu appka sama.', 'chyba' => false];
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
            'mistral', 'groq' => asistent_kompatibilne($sluzba, $model, $pokyny, $spravy, $nastroje, $vykonaj, $timeout, $casy),
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
                'fotka'   => $stav['fotka'],  // 'nakup' = telefón ukáže tlačidlá Odfotiť / Z galérie (3.1)
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
            : (ai_limit_minuty($chyby) ? AI_HLASKA_LIMIT : 'Asistent teraz neodpovedá. Skús to o chvíľu znova.'),
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

/** Služby, ktoré vedia prepisovať zvuk (Claude nie). */
const ASISTENT_PREPIS_SLUZBY = ['groq', 'gemini', 'mistral', 'openai'];

/**
 * Poradie služieb pri prepise hlasu (3.2): AI_PREPIS_PORADIE=groq,gemini, inak Groq prvý
 * (Whisper má vlastný denný limit a nemíňa limit Gemini) a potom AI_PORADIE. Len služby s kľúčom.
 */
function asistent_prepis_poradie(): array
{
    $nastavene = strtolower(ai_env('AI_PREPIS_PORADIE'));
    $zoznam = $nastavene !== '' ? (preg_split('/[\s,;]+/', $nastavene, -1, PREG_SPLIT_NO_EMPTY) ?: [])
        : array_merge(['groq'], ai_poradie());
    $vysledok = [];
    foreach ($zoznam as $sluzba) {
        if (in_array($sluzba, ASISTENT_PREPIS_SLUZBY, true) && !in_array($sluzba, $vysledok, true)
                && ai_env(AI_KLUCE_ENV[$sluzba]) !== '') {
            $vysledok[] = $sluzba;
        }
    }
    return $vysledok;
}

/** Vie niektorá služba prepisovať zvuk (Groq, Gemini, Mistral alebo OpenAI)? */
function asistent_prepis_dostupny(): bool
{
    return asistent_prepis_poradie() !== [];
}

/**
 * Modely na prepis hlasu (3.2): AI_PREPIS_MODEL_GEMINI / _MISTRAL / _OPENAI=model1,model2.
 * Vlastný zoznam pre prepis, aby nemíňal denný limit modelu, ktorý odpovedá.
 * Bez nastavenia: Gemini tie isté ako asistent, Groq Whisper, Mistral Voxtral, OpenAI gpt-4o-mini-transcribe.
 */
function asistent_prepis_modely(string $sluzba): array
{
    $nastavene = ai_env('AI_PREPIS_MODEL_' . strtoupper($sluzba));
    $modely = $nastavene !== '' ? (preg_split('/[\s,;]+/', $nastavene, -1, PREG_SPLIT_NO_EMPTY) ?: []) : [];
    return $modely ?: match ($sluzba) {
        'gemini'  => asistent_modely('gemini'),
        'mistral' => ['voxtral-mini-latest'],
        'groq'    => ['whisper-large-v3', 'whisper-large-v3-turbo'], // každý má v bezplatnom pláne vlastný limit
        default   => ['gpt-4o-mini-transcribe'],
    };
}

/** Prepis cez /audio/transcriptions (OpenAI, Mistral) — multipart s nahrávkou. Návrat [kód, telo, chyba cURL]. */
function asistent_prepis_multipart(string $url, string $kluc, array $polia): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $kluc],
        CURLOPT_POSTFIELDS => array_filter($polia, static fn($v) => $v !== ''),
    ]);
    $odpoved = (string) curl_exec($ch);
    $kod = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $chybaCurl = curl_error($ch);
    curl_close($ch);
    return [$kod, $odpoved, $chybaCurl];
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
    foreach (asistent_prepis_poradie() as $sluzba) {
        $zaciatok = microtime(true);
        if ($sluzba === 'gemini') {
            // 2.9.1: skúsi sa každý model z AI_ASISTENT_MODEL_GEMINI (inak AI_MODEL_GEMINI), nielen prvý —
            // preťažený alebo minutý model inak znamenal „nepodarilo sa prepísať".
            $vysledok = null;
            foreach (asistent_prepis_modely('gemini') as $model) {
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
        } else {
            // OpenAI, Mistral (Voxtral) aj Groq (Whisper) majú rovnaké /audio/transcriptions; každý model z AI_PREPIS_MODEL_… po rade.
            $pripona = ['audio/mp4' => 'm4a', 'audio/webm' => 'webm', 'audio/ogg' => 'ogg', 'audio/wav' => 'wav', 'audio/mp3' => 'mp3', 'audio/aac' => 'aac'][$mime];
            $vysledok = null;
            foreach (asistent_prepis_modely($sluzba) as $model) {
                $zaciatok = microtime(true);
                $polia = ['model' => $model, 'language' => 'sk', 'file' => new CURLFile($subor['tmp_name'], $mime, 'nahravka.' . $pripona)];
                // Slovník názvov receptov: OpenAI a Groq ho berú ako prompt (Whisper najviac ~224 tokenov), Mistral ako context_bias.
                $polia += match ($sluzba) {
                    'mistral' => ['context_bias' => $slovnik],
                    'groq'    => ['response_format' => 'json', 'prompt' => mb_substr($slovnik, 0, 300, 'UTF-8')],
                    default   => ['response_format' => 'json', 'prompt' => $slovnik],
                };
                [$kod, $odpoved, $chybaCurl] = asistent_prepis_multipart(ai_adresa($sluzba) . '/audio/transcriptions', ai_env(AI_KLUCE_ENV[$sluzba]), $polia);
                if ($kod === 400 && $sluzba === 'mistral' && $slovnik !== '') {
                    unset($polia['context_bias']);
                    [$kod, $odpoved, $chybaCurl] = asistent_prepis_multipart(ai_adresa($sluzba) . '/audio/transcriptions', ai_env(AI_KLUCE_ENV[$sluzba]), $polia);
                }
                if ($kod === 200) {
                    $vysledok = (string) (json_decode($odpoved, true)['text'] ?? '');
                    break;
                }
                $chyby[] = AI_NAZVY[$sluzba] . ': ' . $model . ' — ' . ai_popis_chyby($kod, $odpoved, $chybaCurl);
            }
            if ($vysledok === null) {
                continue; // chyby sú už zapísané, ďalšia služba v poradí
            }
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
    return ['ok' => false, 'chyba' => $chyby ? (ai_limit_minuty($chyby) ? AI_HLASKA_LIMIT . ' Otázku môžeš zatiaľ napísať.'
            : 'Nahrávku sa teraz nepodarilo prepísať. Skús to znova alebo to napíš.')
        : 'Na prepis hlasu treba v nastaveniach kľúč Groq, Gemini, Mistral alebo OpenAI.', 'detail' => implode(' · ', $chyby)];
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

/* ----------------------------------------------------------------------
   Mistral a Groq (Chat Completions, 3.2): tool_calls → správy role „tool",
   kým nepríde text. Celý rozhovor sa posiela znova (nemajú previous_response_id).
   ---------------------------------------------------------------------- */
function asistent_kompatibilne(string $sluzba, string $model, string $pokyny, array $spravy, array $nastroje, callable $vykonaj, int $timeout, array &$casy): array
{
    $kratkoPremyslat = $sluzba === 'groq'; // gpt-oss: reasoning_effort „low", pri 400 bez neho
    $sprava = array_merge([['role' => 'system', 'content' => $pokyny]],
        array_map(static fn($s) => ['role' => $s['rola'] === 'asistent' ? 'assistant' : 'user', 'content' => $s['text']], $spravy));
    $tools = array_map(static fn($n) => ['type' => 'function', 'function' => [
        'name' => $n['name'], 'description' => $n['description'], 'parameters' => $n['schema'],
    ]], $nastroje);

    for ($kolo = 0; $kolo <= ASISTENT_MAX_KOL; $kolo++) {
        $telo = ['model' => $model, 'messages' => $sprava, 'tools' => $tools, 'max_tokens' => 4000,
            'tool_choice' => $kolo === ASISTENT_MAX_KOL ? 'none' : 'auto'];
        if ($kratkoPremyslat) {
            $telo['reasoning_effort'] = 'low';
        }
        [$kod, $odpoved, $chybaCurl] = asistent_post(ai_adresa($sluzba) . '/chat/completions',
            ['Authorization: Bearer ' . ai_env(AI_KLUCE_ENV[$sluzba])], $telo, $timeout, $casy);
        if ($kod === 400 && $kratkoPremyslat) {
            $kratkoPremyslat = false; // model nepozná reasoning_effort — to isté kolo znova bez neho
            $kolo--;
            continue;
        }
        if ($kod !== 200) {
            return asistent_chyba_sluzby($model, $kod, $odpoved, $chybaCurl, $timeout);
        }
        $data = json_decode($odpoved, true);
        $vyrok = $data['choices'][0]['message'] ?? null;
        $volania = is_array($vyrok['tool_calls'] ?? null) ? $vyrok['tool_calls'] : [];
        if (!$volania) {
            $text = ai_text_odpovede($sluzba, $data);
            return $text !== null && trim($text) !== '' ? ['ok' => true, 'text' => $text]
                : ['ok' => false, 'chyba' => $model . ' — prázdna odpoveď (' . ($data['choices'][0]['finish_reason'] ?? 'bez dôvodu') . ')'];
        }
        $sprava[] = ['role' => 'assistant', 'content' => is_string($vyrok['content'] ?? null) ? $vyrok['content'] : '', 'tool_calls' => $volania];
        foreach ($volania as $volanie) {
            $nazov = (string) ($volanie['function']['name'] ?? '');
            $argumenty = $volanie['function']['arguments'] ?? '';
            $argumenty = is_array($argumenty) ? $argumenty : json_decode((string) $argumenty, true);
            $r = $vykonaj($nazov, is_array($argumenty) ? $argumenty : []);
            $sprava[] = ['role' => 'tool', 'tool_call_id' => (string) ($volanie['id'] ?? ''), 'name' => $nazov,
                'content' => ($r['chyba'] ? 'CHYBA: ' : '') . $r['text']];
        }
    }
    return ['ok' => false, 'chyba' => $model . ' — priveľa krokov'];
}

/* ----------------------------------------------------------------------
   Fotka do nákupu (3.1): „z fotky mi pridaj do nákupu". Telefón po nástroji
   odfot_na_nakup ukáže tlačidlá Odfotiť / Z galérie a fotku pošle sem.
   AI z nej vytiahne suroviny (recept, nákupný zoznam aj jedlo, české texty
   preložené) a pridajú sa do nákupu cez mcp_pridaj_do_nakupu — recept sa
   neukladá, ostatným príde upozornenie na nákup. Počíta sa do denného limitu
   fotiek (ako recept z fotky).
   ---------------------------------------------------------------------- */

function asistent_fotka_schema(): array
{
    return [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['typ', 'jedlo', 'polozky'],
        'properties' => [
            'typ'     => ['type' => 'string', 'enum' => ['recept', 'zoznam', 'jedlo', 'ine'],
                'description' => 'recept = text receptu; zoznam = nákupný zoznam; jedlo = hotové jedlo bez textu; ine = nič z toho'],
            'jedlo'   => ['type' => 'string', 'description' => 'Názov jedla po slovensky, pri nákupnom zozname prázdny text'],
            'polozky' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['nazov', 'mnozstvo', 'jednotka'],
                    'properties' => [
                        'nazov'    => ['type' => 'string'],
                        'mnozstvo' => ['type' => ['number', 'null']],
                        'jednotka' => ['type' => 'string', 'enum' => array_merge(SUROVINA_JEDNOTKY, [''])],
                    ],
                ],
            ],
        ],
    ];
}

function asistent_fotka_pokyny(array $suroviny): string
{
    $zoznam = implode(', ', array_column($suroviny, 'nazov'));
    return <<<TXT
Si pomocník v slovenskej rodinnej appke na recepty. NÁKUP Z FOTKY: dostaneš fotku a vrátiš JSON so všetkým, čo treba kúpiť.

Najprv urči, čo je na fotke (pole typ):
- "recept": text receptu (kuchárka, lístok, obrazovka). Vypíš všetky suroviny s množstvami presne podľa textu.
- "zoznam": nákupný zoznam (napísaný alebo vytlačený). Vypíš každú položku, aj nepotravinové (napr. toaletný papier), množstvo len keď je napísané.
- "jedlo": hotové jedlo bez textu. Vypíš suroviny bežné v slovenskej domácej kuchyni na 4 porcie s rozumnými množstvami.
- "ine": nič z toho. Pole polozky je prázdne.

Pravidlá:
- Všetko po slovensky. Text po česky alebo v inom jazyku prelož (brambory → Zemiaky, hladká mouka → Múka hladká, máslo → Maslo).
- jedlo = názov jedla po slovensky (pri recepte a jedle), pri nákupnom zozname prázdny text.
- Názov položky ber prednostne zo zoznamu surovín nižšie presne tak, ako je napísaný. Inak krátky názov v prvom páde s podstatným menom na začiatku a veľkým začiatočným písmenom („Múka hladká", „Smotana kyslá").
- Množstvo je číslo (desatinná bodka) alebo null. Zlomky prepočítaj (1/2 = 0.5).
- Jednotka je jedna z: g, kg, ml, dl, l, ks, PL, ČL, šálka, štipka, balenie, plátok, strúčik, alebo prázdny text. Kusy (2 vajcia) majú "ks".
- Každú položku uveď len raz. Vodu z vodovodu neuvádzaj.

Zoznam surovín v appke: {$zoznam}
TXT;
}

/**
 * Fotka → suroviny → nákup. Návrat v tvare odpovede asistenta (success, odpoved, zmeny, akcie…).
 */
function asistent_fotka_do_nakupu(PDO $pdo, array $pripojenie, $file, bool $podrobnosti = false): array
{
    if (!ai_dostupne()) {
        return ['success' => false, 'error' => 'Asistent nie je zapnutý: v nastaveniach appky chýba kľúč k AI.'];
    }
    if (!shopping_available($pdo)) {
        return ['success' => false, 'error' => 'Nákupný zoznam ešte nie je v databáze pripravený.'];
    }
    $userId = (int) $pripojenie['user_id'];
    if (ai_zostava($pdo, $userId) === 0) {
        return ['success' => false, 'error' => 'Dnešný počet fotiek na rozpoznanie je minutý (' . ai_denny_limit() . ' za deň). Zajtra to pôjde znova.'];
    }
    $fotka = ai_priprav_fotku($file);
    if (isset($fotka['error'])) {
        return ['success' => false, 'error' => $fotka['error']];
    }
    $zaciatok = microtime(true);
    $pokyny = asistent_fotka_pokyny(get_all_suroviny($pdo));
    $schema = asistent_fotka_schema();
    $pokusy = [];
    foreach (array_filter(ai_poradie(), 'ai_vie_obrazky') as $sluzba) { // Groq fotky nevie (3.2)
        foreach (ai_modely($sluzba) as $model) {
            $pokusy[] = [$sluzba, $model];
        }
    }
    if (!$pokusy) {
        return ['success' => false, 'error' => 'Na nákup z fotky treba kľúč Gemini, Claude, OpenAI alebo Mistral (Groq fotky nevie).'];
    }
    @set_time_limit(AI_TIMEOUT_PRED_ZALOHOU * count($pokusy) + AI_TIMEOUT_SEKUND + 30);

    $chyby = [];
    $zlyKluc = [];
    $data = null;
    $sluzbaOk = null;
    foreach ($pokusy as $i => [$sluzba, $model]) {
        if (isset($zlyKluc[$sluzba])) {
            continue;
        }
        $vysledok = ai_zavolaj($sluzba, $model, $pokyny, $fotka, $schema,
            $i === count($pokusy) - 1 ? AI_TIMEOUT_SEKUND : AI_TIMEOUT_PRED_ZALOHOU, 'Vypíš z fotky, čo treba kúpiť, a vráť JSON.');
        if ($vysledok['ok'] && is_array($vysledok['data']['polozky'] ?? null)) {
            $data = $vysledok['data'];
            $sluzbaOk = $sluzba;
            break;
        }
        if (!empty($vysledok['zly_kluc'])) {
            $zlyKluc[$sluzba] = true;
        }
        $chyby[] = AI_NAZVY[$sluzba] . ': ' . ($vysledok['chyba'] ?? $model . ' — odpoveď bez zoznamu položiek');
    }
    if ($data === null) {
        ai_zapis_pouzitie($pdo, $userId, null, false, implode('; ', $chyby));
        debug_log('AI ASISTENT fotka do nákupu nedokončená: ' . implode('; ', $chyby));
        $odpoved = ['success' => false, 'error' => ai_limit_minuty($chyby) ? AI_HLASKA_LIMIT : 'Fotku sa teraz nepodarilo prečítať. Skús to o chvíľu znova.'];
        if ($podrobnosti) {
            $odpoved['meta'] = 'Fotka zlyhala: ' . implode(' · ', $chyby);
        }
        return $odpoved;
    }
    ai_zapis_pouzitie($pdo, $userId, $sluzbaOk, true, $chyby ? implode('; ', $chyby) : null);

    $polozky = [];
    foreach (array_slice($data['polozky'], 0, 60) as $p) {
        if (!is_array($p) || trim((string) ($p['nazov'] ?? '')) === '') {
            continue;
        }
        $polozky[] = array_filter([
            'nazov'    => trim((string) $p['nazov']),
            'mnozstvo' => is_numeric($p['mnozstvo'] ?? null) && (float) $p['mnozstvo'] > 0 ? (float) $p['mnozstvo'] : null,
            'jednotka' => in_array($p['jednotka'] ?? '', SUROVINA_JEDNOTKY, true) ? $p['jednotka'] : null,
        ], static fn($v) => $v !== null);
    }
    $meta = $podrobnosti ? AI_NAZVY[$sluzbaOk] . ' · ' . str_replace('.', ',', (string) round(microtime(true) - $zaciatok, 1)) . ' s (fotka: ' . ($data['typ'] ?? '?') . ')'
        . ($chyby ? '; predtým zlyhalo: ' . implode('; ', $chyby) : '') : null;
    if (!$polozky) {
        return ['success' => true, 'zmeny' => false, 'akcie' => [], 'meta' => $meta, 'poskytovatel' => AI_NAZVY[$sluzbaOk],
            'odpoved' => 'Na fotke som nenašiel recept ani nákupný zoznam. Skús to odfotiť bližšie a ostrejšie.'];
    }
    $jedlo = ($data['typ'] ?? '') !== 'zoznam' ? mb_substr(trim((string) ($data['jedlo'] ?? '')), 0, 150, 'UTF-8') : '';
    $vysledok = mcp_pridaj_do_nakupu($pdo, $pripojenie, ['suroviny' => $polozky, 'jedlo' => $jedlo]);
    if (isset($vysledok['chyba'])) {
        return ['success' => false, 'error' => $vysledok['chyba'], 'meta' => $meta];
    }
    $nazvy = $vysledok['data']['polozky'];
    $pocet = count($nazvy);
    $zoznam = $pocet > 5 ? implode(', ', array_slice($nazvy, 0, 5)) . ' a ' . mcp_sklonuj($pocet - 5, 'ďalšiu', 'ďalšie', 'ďalších')
        : (count($nazvy) > 1 ? implode(', ', array_slice($nazvy, 0, -1)) . ' a ' . end($nazvy) : $nazvy[0]);
    return [
        'success'      => true,
        'odpoved'      => 'Pridal som do nákupu ' . mcp_sklonuj($pocet, 'položku', 'položky', 'položiek') . ($jedlo !== '' ? ' na ' . $jedlo : '') . ': ' . $zoznam . '.',
        'zmeny'        => true,
        'akcie'        => [['nastroj' => 'pridaj_do_nakupu', 'ok' => true]],
        'polozky'      => $nazvy,
        'meta'         => $meta,
        'poskytovatel' => AI_NAZVY[$sluzbaOk],
    ];
}
