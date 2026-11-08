<?php
/**
 * ai.php — rozpoznanie receptu z fotky cez AI (verzia 1.8).
 *
 * Appka pošle zmenšenú fotku jednej z AI služieb (Gemini, Claude, OpenAI) spolu
 * so zoznamom surovín z číselníka, jednotkami a kategóriami. AI vráti JSON podľa
 * pevnej schémy (názov, kategória, porcie, čas, postup, suroviny). Server výsledok
 * skontroluje, suroviny napáruje na číselník a pošle formuláru na kontrolu —
 * nič sa neuloží, kým to používateľ neodklikne.
 *
 * Nastavenie v includes/.env (všetko nepovinné; bez kľúča je funkcia vypnutá a
 * appka beží ako doteraz):
 *   GEMINI_API_KEY, ANTHROPIC_API_KEY, OPENAI_API_KEY  — kľúče k službám
 *   AI_PORADIE=gemini,claude,openai  — v akom poradí ich skúšať (bez kľúča sa preskočí)
 *   AI_DENNY_LIMIT=20                — koľko fotiek môže jeden používateľ poslať za deň (0 = bez limitu)
 *   AI_MODEL_GEMINI / AI_MODEL_CLAUDE / AI_MODEL_OPENAI — iný model než predvolený
 *   AI_ADRESA_GEMINI / AI_ADRESA_CLAUDE / AI_ADRESA_OPENAI — iná adresa API (len na testovanie)
 *
 * Keď prvá služba zlyhá (zlý kľúč, minutý limit, výpadok, nečitateľná odpoveď),
 * skúsi sa ďalšia v poradí.
 */

const AI_POSKYTOVATELIA = ['gemini', 'claude', 'openai'];

const AI_NAZVY = ['gemini' => 'Gemini', 'claude' => 'Claude', 'openai' => 'OpenAI'];

const AI_KLUCE_ENV = [
    'gemini' => 'GEMINI_API_KEY',
    'claude' => 'ANTHROPIC_API_KEY',
    'openai' => 'OPENAI_API_KEY',
];

// Lacné a rýchle modely, ktoré vedia čítať fotky a vracať JSON podľa schémy (november 2026).
// Pri Gemini sú dva: keď je prvý preťažený (Google vracia 503 „high demand"), neodpovedá
// včas alebo ho kľúč nemá, skúsi sa ľahší Flash-Lite, ktorý býva voľný.
const AI_PREDVOLENE_MODELY = [
    'gemini' => ['gemini-3.8-flash', 'gemini-flash-lite-latest'],
    'claude' => ['claude-haiku-5-5'],
    'openai' => ['gpt-6-luna'],
];

const AI_PREDVOLENE_ADRESY = [
    'gemini' => 'https://generativelanguage.googleapis.com/v1beta',
    'claude' => 'https://api.anthropic.com/v1',
    'openai' => 'https://api.openai.com/v1',
];

const AI_DENNY_LIMIT_PREDVOLENY = 20;
const AI_TIMEOUT_SEKUND = 45;          // na poslednú skúšanú požiadavku
const AI_TIMEOUT_PRED_ZALOHOU = 25;    // keď po nej ešte príde na rad ďalší model alebo služba
const AI_FOTKA_MAX_STRANA = 1568;      // väčšie fotky AI aj tak zmenšuje, len by to stálo viac
const AI_MAX_SUROVIN = 40;

function ai_env(string $nazov): string
{
    $hodnota = getenv($nazov);
    return $hodnota === false ? '' : trim($hodnota);
}

/** Služby v poradí z AI_PORADIE — len tie, ktoré majú v .env kľúč. */
function ai_poradie(): array
{
    $nastavene = strtolower(ai_env('AI_PORADIE'));
    $zoznam = $nastavene !== '' ? (preg_split('/[\s,;]+/', $nastavene) ?: []) : AI_POSKYTOVATELIA;
    $vysledok = [];
    foreach ($zoznam as $sluzba) {
        if (in_array($sluzba, AI_POSKYTOVATELIA, true) && !in_array($sluzba, $vysledok, true)
                && ai_env(AI_KLUCE_ENV[$sluzba]) !== '') {
            $vysledok[] = $sluzba;
        }
    }
    return $vysledok;
}

/** Je rozpoznávanie z fotky zapnuté (aspoň jeden kľúč a cURL)? */
function ai_dostupne(): bool
{
    return function_exists('curl_init') && ai_poradie() !== [];
}

function ai_denny_limit(): int
{
    $hodnota = ai_env('AI_DENNY_LIMIT');
    return ctype_digit($hodnota) ? (int) $hodnota : AI_DENNY_LIMIT_PREDVOLENY;
}

/** Modely služby v poradí skúšania: AI_MODEL_GEMINI=model1,model2 alebo predvolené. */
function ai_modely(string $sluzba): array
{
    $nastavene = ai_env('AI_MODEL_' . strtoupper($sluzba));
    $modely = $nastavene !== '' ? (preg_split('/[\s,;]+/', $nastavene, -1, PREG_SPLIT_NO_EMPTY) ?: []) : [];
    return $modely ?: AI_PREDVOLENE_MODELY[$sluzba];
}

function ai_model(string $sluzba): string
{
    return ai_modely($sluzba)[0];
}

function ai_adresa(string $sluzba): string
{
    return rtrim(ai_env('AI_ADRESA_' . strtoupper($sluzba)) ?: AI_PREDVOLENE_ADRESY[$sluzba], '/');
}

/* ----------------------------------------------------------------------
   Denný limit. Počítajú sa len úspešné rozpoznania (výpadok služby nikoho
   neobmedzí), záznam o neúspechu ostane pre prehľad chýb. Údaje sú v tabuľke
   ai_rozpoznania (migrácia 1.8); kým nie je naimportovaná, počíta sa aspoň
   v session prihláseného používateľa.
   ---------------------------------------------------------------------- */

function ai_log_available(PDO $pdo): bool
{
    static $available = null;
    if ($available === null) {
        try {
            $pdo->query('SELECT 1 FROM ai_rozpoznania LIMIT 1');
            $available = true;
        } catch (Throwable $e) {
            debug_log('AI: chýba tabuľka ai_rozpoznania, spusti sql/migracia-1.8.sql — limit sa zatiaľ počíta v session (' . $e->getMessage() . ')');
            $available = false;
        }
    }
    return $available;
}

function ai_dnes_pouzite(PDO $pdo, int $userId): int
{
    if (ai_log_available($pdo)) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM ai_rozpoznania WHERE user_id = ? AND uspech = 1 AND created_at >= CURDATE()');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }
    $session = $_SESSION['ai_rozpoznania'] ?? null;
    return (is_array($session) && ($session['den'] ?? '') === date('Y-m-d')) ? (int) $session['pocet'] : 0;
}

function ai_zapis_pouzitie(PDO $pdo, int $userId, ?string $sluzba, bool $uspech, ?string $chyba): void
{
    if (ai_log_available($pdo)) {
        $pdo->prepare('INSERT INTO ai_rozpoznania (user_id, poskytovatel, uspech, chyba) VALUES (?, ?, ?, ?)')
            ->execute([$userId, $sluzba, $uspech ? 1 : 0, $chyba !== null ? mb_substr($chyba, 0, 255, 'UTF-8') : null]);
        return;
    }
    if ($uspech) {
        $_SESSION['ai_rozpoznania'] = ['den' => date('Y-m-d'), 'pocet' => ai_dnes_pouzite($pdo, $userId) + 1];
    }
}

/** Koľko fotiek môže používateľ dnes ešte poslať (null = bez limitu). */
function ai_zostava(PDO $pdo, int $userId): ?int
{
    $limit = ai_denny_limit();
    return $limit === 0 ? null : max(0, $limit - ai_dnes_pouzite($pdo, $userId));
}

/* ----------------------------------------------------------------------
   Fotka: overenie, zmenšenie a prevod na JPEG v base64.
   ---------------------------------------------------------------------- */

function ai_priprav_fotku($file): array
{
    if (!is_array($file) || !isset($file['error']) || is_array($file['error'])) {
        return ['error' => 'Chýba fotka.'];
    }
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        return ['error' => 'Fotka je príliš veľká.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['error' => $file['error'] === UPLOAD_ERR_NO_FILE ? 'Chýba fotka.' : 'Nahrávanie fotky zlyhalo, skús to znova.'];
    }
    if ($file['size'] > 12 * 1024 * 1024) {
        return ['error' => 'Fotka je príliš veľká (max 12 MB).'];
    }

    ini_set('memory_limit', '256M');
    $info = @getimagesize($file['tmp_name']);
    $zdroj = null;
    switch ($info['mime'] ?? '') {
        case 'image/jpeg':
            $zdroj = @imagecreatefromjpeg($file['tmp_name']);
            break;
        case 'image/png':
            $zdroj = @imagecreatefrompng($file['tmp_name']);
            break;
        case 'image/webp':
            $zdroj = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file['tmp_name']) : null;
            break;
    }
    if (!$zdroj) {
        return ['error' => 'Súbor nie je fotka (JPG, PNG alebo WEBP).'];
    }

    $sirka = imagesx($zdroj);
    $vyska = imagesy($zdroj);
    $pomer = min(1, AI_FOTKA_MAX_STRANA / max($sirka, $vyska));
    $novaSirka = max(1, (int) round($sirka * $pomer));
    $novaVyska = max(1, (int) round($vyska * $pomer));

    $ciel = imagecreatetruecolor($novaSirka, $novaVyska);
    imagefill($ciel, 0, 0, imagecolorallocate($ciel, 255, 255, 255)); // priehľadné PNG na bielom
    imagecopyresampled($ciel, $zdroj, 0, 0, 0, 0, $novaSirka, $novaVyska, $sirka, $vyska);
    imagedestroy($zdroj);

    ob_start();
    imagejpeg($ciel, null, 85);
    $jpeg = (string) ob_get_clean();
    imagedestroy($ciel);

    return ['data' => base64_encode($jpeg), 'mime' => 'image/jpeg'];
}

/* ----------------------------------------------------------------------
   Pokyny pre AI a schéma odpovede. Schéma je spoločná pre všetky tri služby:
   všetky polia sú povinné, nič navyše, „nevyplnené" je null alebo prázdny text.
   ---------------------------------------------------------------------- */

function ai_schema(array $kategorie): array
{
    $kluceKategorii = array_values(array_unique(array_map(static fn($k) => (string) $k['kluc'], $kategorie)));
    $kluceKategorii[] = '';

    return [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['typ', 'nazov', 'kategoria', 'porcie', 'cas_pripravy', 'postup', 'suroviny'],
        'properties' => [
            'typ' => [
                'type' => 'string',
                'enum' => ['recept', 'jedlo', 'ine'],
                'description' => 'recept = na fotke je text receptu; jedlo = hotové jedlo bez textu; ine = nič z toho',
            ],
            'nazov' => ['type' => 'string', 'description' => 'Názov jedla po slovensky'],
            'kategoria' => ['type' => 'string', 'enum' => $kluceKategorii],
            'porcie' => ['type' => ['integer', 'null']],
            'cas_pripravy' => ['type' => ['integer', 'null'], 'description' => 'Čas prípravy v minútach'],
            'postup' => ['type' => 'string'],
            'suroviny' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['nazov', 'mnozstvo', 'jednotka', 'poznamka'],
                    'properties' => [
                        'nazov' => ['type' => 'string'],
                        'mnozstvo' => ['type' => ['number', 'null']],
                        'jednotka' => ['type' => 'string', 'enum' => array_merge(SUROVINA_JEDNOTKY, [''])],
                        'poznamka' => ['type' => 'string'],
                    ],
                ],
            ],
        ],
    ];
}

function ai_pokyny(array $suroviny, array $kategorie): string
{
    $zoznamKategorii = implode("\n", array_map(
        static fn($k) => '- ' . $k['kluc'] . ($k['nazov'] !== $k['kluc'] ? ' (' . $k['nazov'] . ')' : ''),
        $kategorie
    ));
    $zoznamSurovin = implode(', ', array_map(static fn($s) => $s['nazov'], $suroviny));

    return <<<TXT
Si pomocník v slovenskej rodinnej appke na recepty. Dostaneš jednu fotku a vrátiš recept ako JSON podľa schémy.

Najprv urči, čo je na fotke (pole typ):
- "recept": text receptu (strana z kuchárky, ručne písaný lístok, snímka obrazovky). Prepíš názov, suroviny s množstvami a postup presne podľa textu, nič nevymýšľaj.
- "jedlo": hotové jedlo bez textu receptu. Odhadni názov jedla a suroviny bežné v slovenskej domácej kuchyni s rozumnými množstvami. Ak počet porcií nevidno, počítaj so 4 porciami. Postup napíš stručne (3 až 6 viet).
- "ine": na fotke nie je jedlo ani recept. Ostatné polia nechaj prázdne (prázdny text, null, prázdny zoznam).

Pravidlá:
- Všetko píš po slovensky.
- Názov suroviny ber prednostne zo zoznamu surovín nižšie, presne tak, ako je napísaný. Ak tam nie je, napíš krátky názov v prvom páde s veľkým začiatočným písmenom (napríklad "Údený syr").
- Množstvo je číslo (desatinná bodka) alebo null, keď nie je uvedené (napríklad "podľa chuti"). Zlomky prepočítaj (1/2 = 0.5).
- Jednotka je jedna z: g, kg, ml, dl, l, ks, PL (polievková lyžica), ČL (čajová lyžička), šálka, štipka, balenie, plátok, strúčik. Kusy bez jednotky (2 vajcia) majú jednotku "ks". Keď jednotka nie je, prázdny text.
- Spresnenie suroviny (nastrúhaná, na posypanie, izbová teplota) patrí do poznámky, nie do názvu. Inak je poznámka prázdna.
- Každú surovinu uveď len raz. Vodu na varenie a soľ do vody neuvádzaj.
- kategoria je jeden z týchto kľúčov, alebo prázdny text, keď žiadny nesedí:
{$zoznamKategorii}
- porcie a cas_pripravy (v minútach) vyplň, keď sú na fotke alebo sa dajú rozumne odhadnúť, inak null.

Zoznam surovín v appke: {$zoznamSurovin}
TXT;
}

/* ----------------------------------------------------------------------
   Volanie služieb
   ---------------------------------------------------------------------- */

/** POST s JSON telom. Vráti [stavový kód, telo odpovede, chyba cURL]. */
function ai_http_post(string $url, array $hlavicky, array $telo, int $timeout = AI_TIMEOUT_SEKUND): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($telo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $hlavicky),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => $timeout,
    ]);
    $odpoved = curl_exec($ch);
    $kod = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $chyba = curl_error($ch);
    curl_close($ch);
    return [$kod, is_string($odpoved) ? $odpoved : '', $chyba];
}

/** JSON z textu odpovede — aj keď ho AI obalí do ```json … ``` alebo pridá vetu navyše. */
function ai_json_z_textu(string $text): ?array
{
    $text = trim($text);
    $data = json_decode($text, true);
    if (is_array($data)) {
        return $data;
    }
    $zaciatok = strpos($text, '{');
    $koniec = strrpos($text, '}');
    if ($zaciatok === false || $koniec === false || $koniec <= $zaciatok) {
        return null;
    }
    $data = json_decode(substr($text, $zaciatok, $koniec - $zaciatok + 1), true);
    return is_array($data) ? $data : null;
}

/** Zrozumiteľný popis chyby služby pre záznam chýb a pre používateľa. */
function ai_popis_chyby(int $kod, string $telo, string $chybaCurl): string
{
    if ($chybaCurl !== '') {
        return 'nedá sa spojiť (' . $chybaCurl . ')';
    }
    $sprava = '';
    $data = json_decode($telo, true);
    if (is_array($data)) {
        $sprava = (string) ($data['error']['message'] ?? $data['error']['type'] ?? $data['message'] ?? '');
    }
    $sprava = $sprava !== '' ? ': ' . mb_substr($sprava, 0, 150, 'UTF-8') : '';
    return match (true) {
        $kod === 401, $kod === 403 => 'neplatný kľúč alebo chýbajú práva (' . $kod . ')' . $sprava,
        $kod === 429 => 'minutý limit alebo kredit (429)' . $sprava,
        $kod === 503 => 'model je preťažený (503)' . $sprava,
        $kod >= 500 => 'služba má výpadok (' . $kod . ')' . $sprava,
        default => 'chyba ' . $kod . $sprava,
    };
}

/**
 * Jedna služba. Prvý pokus žiada štruktúrovaný výstup a krátke premýšľanie modelu
 * (rozpoznanie fotky hlboké premýšľanie nepotrebuje a s ním odpoveď trvala aj
 * viac než 40 sekúnd). Keď API niečo z toho odmietne (400 — iný alebo starší
 * model), skúsi sa bez nastavenia rýchlosti a nakoniec aj bez schémy (JSON sa
 * potom vyberie z textu).
 * Návrat: ['ok' => true, 'data' => array] alebo ['ok' => false, 'chyba' => string].
 */
function ai_zavolaj(string $sluzba, string $model, string $pokyny, array $fotka, array $schema, int $timeout = AI_TIMEOUT_SEKUND): array
{
    $kluc = ai_env(AI_KLUCE_ENV[$sluzba]);
    $adresa = ai_adresa($sluzba);
    $uloha = 'Rozpoznaj túto fotku podľa pokynov a vráť JSON.';

    // 2 = schéma + krátke premýšľanie, 1 = len schéma, 0 = nič navyše
    foreach ([2, 1, 0] as $uroven) {
        $soSchemou = $uroven >= 1;
        $rychlo = $uroven === 2;
        $zaciatok = microtime(true);
        switch ($sluzba) {
            case 'gemini':
                $config = ['responseMimeType' => 'application/json'];
                if ($soSchemou) {
                    $config['responseJsonSchema'] = $schema;
                }
                if ($rychlo) {
                    $config['thinkingConfig'] = ['thinkingLevel' => 'low'];
                }
                [$kod, $telo, $chybaCurl] = ai_http_post(
                    $adresa . '/models/' . rawurlencode($model) . ':generateContent',
                    ['x-goog-api-key: ' . $kluc],
                    [
                        'systemInstruction' => ['parts' => [['text' => $pokyny]]],
                        'contents' => [[
                            'role' => 'user',
                            'parts' => [
                                ['inline_data' => ['mime_type' => $fotka['mime'], 'data' => $fotka['data']]],
                                ['text' => $uloha],
                            ],
                        ]],
                        'generationConfig' => $config,
                    ],
                    $timeout
                );
                break;

            case 'claude':
                $telo = [
                    'model' => $model,
                    'max_tokens' => 8000,
                    'system' => $pokyny,
                    'messages' => [[
                        'role' => 'user',
                        'content' => [
                            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $fotka['mime'], 'data' => $fotka['data']]],
                            ['type' => 'text', 'text' => $uloha],
                        ],
                    ]],
                ];
                if ($soSchemou) {
                    $telo['output_config'] = ['format' => ['type' => 'json_schema', 'schema' => $schema]];
                    if ($rychlo) {
                        $telo['output_config']['effort'] = 'low';
                    }
                }
                [$kod, $telo, $chybaCurl] = ai_http_post(
                    $adresa . '/messages',
                    ['x-api-key: ' . $kluc, 'anthropic-version: 2023-06-01'],
                    $telo,
                    $timeout
                );
                break;

            default: // openai — Responses API
                $telo = [
                    'model' => $model,
                    'instructions' => $pokyny,
                    'max_output_tokens' => 8000,
                    'input' => [[
                        'role' => 'user',
                        'content' => [
                            ['type' => 'input_text', 'text' => $uloha],
                            ['type' => 'input_image', 'image_url' => 'data:' . $fotka['mime'] . ';base64,' . $fotka['data']],
                        ],
                    ]],
                ];
                if ($soSchemou) {
                    $telo['text'] = ['format' => ['type' => 'json_schema', 'name' => 'recept', 'schema' => $schema, 'strict' => true]];
                }
                if ($rychlo) {
                    $telo['reasoning'] = ['effort' => 'low'];
                }
                [$kod, $telo, $chybaCurl] = ai_http_post(
                    $adresa . '/responses',
                    ['Authorization: Bearer ' . $kluc],
                    $telo,
                    $timeout
                );
                break;
        }

        $trvanie = sprintf('%.1f s', microtime(true) - $zaciatok);
        if ($kod === 400 && $uroven > 0) {
            debug_log('AI ' . $sluzba . ' (' . $model . '): nastavenie odmietnuté, skúšam jednoduchšiu požiadavku — ' . ai_popis_chyby($kod, $telo, ''));
            continue;
        }
        if ($kod !== 200) {
            $chyba = $model . ' — ' . ai_popis_chyby($kod, $telo, $chybaCurl);
            if ($chybaCurl !== '' && str_contains(strtolower($chybaCurl), 'timed out')) {
                $chyba = $model . ' — neodpovedal do ' . $timeout . ' sekúnd';
            }
            // Neplatný kľúč platí pre všetky modely služby, ďalšie netreba skúšať.
            return ['ok' => false, 'chyba' => $chyba, 'zly_kluc' => in_array($kod, [401, 403], true)];
        }

        $text = ai_text_odpovede($sluzba, json_decode($telo, true));
        $data = $text !== null ? ai_json_z_textu($text) : null;
        if ($data === null) {
            return ['ok' => false, 'chyba' => $model . ' — nečitateľná odpoveď'];
        }
        // Čas odpovede v zázname chýb pomôže, keď bude rozpoznanie pomalé.
        debug_log('AI ' . $sluzba . ' (' . $model . '): rozpoznané za ' . $trvanie);
        return ['ok' => true, 'data' => $data];
    }
    return ['ok' => false, 'chyba' => $model . ' — požiadavku odmietol aj v najjednoduchšej podobe'];
}

/** Text odpovede z JSON-u danej služby (bez „premýšľania" modelu). */
function ai_text_odpovede(string $sluzba, $odpoved): ?string
{
    if (!is_array($odpoved)) {
        return null;
    }
    $casti = [];
    switch ($sluzba) {
        case 'gemini':
            foreach ($odpoved['candidates'][0]['content']['parts'] ?? [] as $cast) {
                if (isset($cast['text']) && empty($cast['thought'])) {
                    $casti[] = $cast['text'];
                }
            }
            break;
        case 'claude':
            foreach ($odpoved['content'] ?? [] as $blok) {
                if (($blok['type'] ?? '') === 'text') {
                    $casti[] = $blok['text'] ?? '';
                }
            }
            break;
        default:
            if (isset($odpoved['output_text']) && is_string($odpoved['output_text'])) {
                return $odpoved['output_text'];
            }
            foreach ($odpoved['output'] ?? [] as $polozka) {
                if (($polozka['type'] ?? '') !== 'message') {
                    continue;
                }
                foreach ($polozka['content'] ?? [] as $obsah) {
                    if (($obsah['type'] ?? '') === 'output_text') {
                        $casti[] = $obsah['text'] ?? '';
                    }
                }
            }
    }
    return $casti ? implode('', $casti) : null;
}

/* ----------------------------------------------------------------------
   Kontrola výsledku a párovanie surovín na číselník
   ---------------------------------------------------------------------- */

/**
 * Podobná surovina z číselníka pre názov, ktorý tam presne nie je:
 *  - preklep (o 1–2 písmená iný názov, ako „Nemysleli ste…" vo formulári),
 *  - názov z číselníka je celým slovom v názve od AI („Anglická slanina" → „Slanina").
 */
function ai_podobna_surovina(string $kluc, array $katalog): ?array
{
    $tolerancia = mb_strlen($kluc) >= 9 ? 2 : 1;
    $najlepsia = null;
    $najmensia = PHP_INT_MAX;
    if (mb_strlen($kluc) >= 3) {
        foreach ($katalog as $k => $surovina) {
            if (abs(strlen((string) $k) - strlen($kluc)) > $tolerancia) {
                continue;
            }
            $vzdialenost = levenshtein($kluc, (string) $k);
            if ($vzdialenost <= $tolerancia && $vzdialenost < $najmensia) {
                $najmensia = $vzdialenost;
                $najlepsia = $surovina + ['druh' => 'preklep'];
            }
        }
    }
    if ($najlepsia) {
        return $najlepsia;
    }

    $slova = ' ' . $kluc . ' ';
    $najdlhsi = 0;
    foreach ($katalog as $k => $surovina) {
        $k = (string) $k;
        if (strlen($k) >= 4 && strlen($k) > $najdlhsi && $k !== $kluc && str_contains($slova, ' ' . $k . ' ')) {
            $najdlhsi = strlen($k);
            $najlepsia = $surovina + ['druh' => 'cast'];
        }
    }
    return $najlepsia;
}

/** Vyčistí odpoveď AI na tvar, ktorý pozná formulár receptu. */
function ai_spracuj_vysledok(array $data, array $suroviny, array $kategorie): array
{
    $katalog = [];
    foreach ($suroviny as $s) {
        $katalog[surovina_key($s['nazov'])] = ['id' => (int) $s['id'], 'nazov' => $s['nazov']];
    }
    $kluceKategorii = array_map(static fn($k) => (string) $k['kluc'], $kategorie);

    $text = static fn($hodnota, int $max): string => is_scalar($hodnota)
        ? mb_substr(trim(preg_replace('/[ \t]+/u', ' ', (string) $hodnota) ?? ''), 0, $max, 'UTF-8') : '';
    $cislo = static function ($hodnota, int $max): ?int {
        if (!is_numeric($hodnota)) {
            return null;
        }
        $n = (int) round((float) $hodnota);
        return ($n >= 1 && $n <= $max) ? $n : null;
    };

    $typ = in_array($data['typ'] ?? '', ['recept', 'jedlo', 'ine'], true) ? $data['typ'] : 'jedlo';
    $kategoria = (string) ($data['kategoria'] ?? '');

    $riadky = [];
    $videne = [];
    foreach (is_array($data['suroviny'] ?? null) ? $data['suroviny'] : [] as $polozka) {
        if (!is_array($polozka) || count($riadky) >= AI_MAX_SUROVIN) {
            continue;
        }
        $nazov = surovina_display_name($text($polozka['nazov'] ?? '', 100));
        $kluc = surovina_key($nazov);
        if ($kluc === '') {
            continue;
        }
        $zhoda = $katalog[$kluc] ?? null;
        $kluc = $zhoda ? surovina_key($zhoda['nazov']) : $kluc;
        if (isset($videne[$kluc])) {
            continue;
        }
        $videne[$kluc] = true;

        $jednotka = $text($polozka['jednotka'] ?? '', 20);
        $poznamka = $text($polozka['poznamka'] ?? '', 100);
        $riadky[] = [
            'id'       => $zhoda['id'] ?? null,
            'nazov'    => $zhoda['nazov'] ?? $nazov,
            'mnozstvo' => parse_surovina_mnozstvo($polozka['mnozstvo'] ?? null),
            'jednotka' => in_array($jednotka, SUROVINA_JEDNOTKY, true) ? $jednotka : null,
            'poznamka' => $poznamka !== '' ? $poznamka : null,
            'navrh'    => $zhoda ? null : ai_podobna_surovina($kluc, $katalog),
        ];
    }

    return [
        'typ'          => $typ,
        'nazov'        => $text($data['nazov'] ?? '', 150),
        'kategoria'    => in_array($kategoria, $kluceKategorii, true) ? $kategoria : '',
        'porcie'       => $cislo($data['porcie'] ?? null, 99),
        'cas_pripravy' => $cislo($data['cas_pripravy'] ?? null, 999),
        'postup'       => mb_substr(trim((string) (is_scalar($data['postup'] ?? null) ? $data['postup'] : '')), 0, 5000, 'UTF-8'),
        'suroviny'     => $riadky,
    ];
}

/* ----------------------------------------------------------------------
   Celé rozpoznanie (akcia ai_rozpoznaj v index.php)
   ---------------------------------------------------------------------- */

/** $podrobnosti = do odpovede pridať, ktorá služba prečo zlyhala (len pre admina). */
function ai_rozpoznaj(PDO $pdo, int $userId, $file, bool $podrobnosti = false): array
{
    if (!ai_dostupne()) {
        return ['success' => false, 'error' => 'Rozpoznávanie z fotky nie je zapnuté (v includes/.env chýba kľúč k AI).'];
    }
    $zostava = ai_zostava($pdo, $userId);
    if ($zostava === 0) {
        return ['success' => false, 'zostava' => 0,
            'error' => 'Dnešný počet fotiek na rozpoznanie je minutý (' . ai_denny_limit() . ' za deň). Zajtra to pôjde znova, dovtedy vyplň recept ručne.'];
    }

    $fotka = ai_priprav_fotku($file);
    if (isset($fotka['error'])) {
        return ['success' => false, 'error' => $fotka['error']];
    }

    // Všetky pokusy (služba + model) v poradí. Pred záložným pokusom sa čaká kratšie,
    // aby sa naň ušiel čas a človek s telefónom nečakal zbytočne dlho.
    $pokusy = [];
    foreach (ai_poradie() as $sluzba) {
        foreach (ai_modely($sluzba) as $model) {
            $pokusy[] = [$sluzba, $model];
        }
    }
    @set_time_limit(AI_TIMEOUT_PRED_ZALOHOU * count($pokusy) + AI_TIMEOUT_SEKUND + 30);

    $suroviny = get_all_suroviny($pdo);
    $kategorie = array_values(array_filter(get_recipe_categories($pdo), static fn($k) => empty($k['mimo_zoznamu'])));
    $pokyny = ai_pokyny($suroviny, $kategorie);
    $schema = ai_schema($kategorie);

    $chyby = [];
    $zlyKluc = [];
    foreach ($pokusy as $i => [$sluzba, $model]) {
        if (isset($zlyKluc[$sluzba])) {
            continue;
        }
        $poslednyPokus = $i === count($pokusy) - 1;
        $vysledok = ai_zavolaj($sluzba, $model, $pokyny, $fotka, $schema,
            $poslednyPokus ? AI_TIMEOUT_SEKUND : AI_TIMEOUT_PRED_ZALOHOU);
        if ($vysledok['ok']) {
            ai_zapis_pouzitie($pdo, $userId, $sluzba, true, $chyby ? implode('; ', $chyby) : null);
            return [
                'success'     => true,
                'vysledok'    => ai_spracuj_vysledok($vysledok['data'], $suroviny, $kategorie),
                'poskytovatel' => AI_NAZVY[$sluzba],
                'zostava'     => ai_zostava($pdo, $userId),
            ];
        }
        if (!empty($vysledok['zly_kluc'])) {
            $zlyKluc[$sluzba] = true;
        }
        $chyby[] = AI_NAZVY[$sluzba] . ': ' . $vysledok['chyba'];
        debug_log('AI ' . $sluzba . ' zlyhalo: ' . $vysledok['chyba']);
    }

    ai_zapis_pouzitie($pdo, $userId, null, false, implode('; ', $chyby));
    $odpoved = [
        'success' => false,
        'error'   => 'Fotku sa teraz nepodarilo rozpoznať. Skús to o chvíľu znova, alebo vyplň recept ručne.',
    ];
    if ($podrobnosti) {
        $odpoved['detail'] = implode(' · ', $chyby);
    }
    return $odpoved;
}
