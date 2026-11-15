<?php
/**
 * smoke.php — prejde appku tak, ako by ju používal človek: prihlási sa, pridá
 * recept s fotkou, upraví ho, zapíše varenie, ohodnotí, použije nákupný zoznam,
 * zapíše objednané jedlo, zmaže recept a odhlási sa. Každý krok overí podľa odpovede servera.
 *
 * Spúšťa ho testy/spusti-testy.sh nad bežiacou appkou (vstavaný server PHP):
 *   php testy/smoke.php ADRESA MENO HESLO REZIM CRON_KEY
 *
 * REZIM:
 *   plny          databáza je aktuálna (nová inštalácia alebo po migrácii), overí sa všetko
 *   bez-migracie  databáza je z predchádzajúcej verzie; základné veci (recepty, varenie,
 *                 hodnotenie, história) musia fungovať, nové funkcie len nesmú spadnúť
 *   asistent      appka beží s kľúčmi k falošnej AI (testy/falosna-ai.php); po prihlásení
 *                 sa overí len hlasový asistent (verzia 2.7). Premenná TEST_ASISTENT_SLUZBA
 *                 = ktorá služba má odpovedať (Gemini, Claude, OpenAI), TEST_ASISTENT_ZALOHA=1
 *                 overí prepnutie na ďalšiu službu, keď prvá zlyhá.
 *
 * Návratový kód: 0 = všetko v poriadku, 1 = aspoň jedna kontrola zlyhala.
 */

if ($argc < 6) {
    fwrite(STDERR, "Použitie: php testy/smoke.php ADRESA MENO HESLO plny|bez-migracie CRON_KEY\n");
    exit(2);
}
[, $adresa, $meno, $heslo, $rezim, $cronKey] = $argv;
$plny = $rezim === 'plny';

/* ---------------------------------------------------------------------- */
/* Pomôcky                                                                 */
/* ---------------------------------------------------------------------- */

$chyby = 0;

// Varovanie PHP priamo v teste je tiež chyba (inak by test mohol „prejsť" omylom).
set_error_handler(function (int $cislo, string $sprava, string $subor, int $riadok): bool {
    global $chyby;
    $chyby++;
    echo "  ✘ Chyba v samotnom teste: $sprava ($subor:$riadok)\n";
    return true;
});

function over(bool $podmienka, string $popis, string $detail = ''): bool
{
    global $chyby;
    if ($podmienka) {
        echo "  ✔ $popis\n";
    } else {
        $chyby++;
        echo "  ✘ $popis" . ($detail !== '' ? "\n      " . str_replace("\n", "\n      ", mb_substr($detail, 0, 1500)) : '') . "\n";
        if (getenv('GITHUB_ACTIONS') === 'true') {
            echo "::error::Test zlyhal: $popis\n";
        }
    }
    return $podmienka;
}

function info(string $text): void
{
    echo "  · $text\n";
}

final class Odpoved
{
    public function __construct(
        public int $kod,
        public array $hlavicky,
        public string $telo,
    ) {}

    public function json(): ?array
    {
        $data = json_decode($this->telo, true);
        return is_array($data) ? $data : null;
    }

    public function presmerovanie(): string
    {
        return $this->hlavicky['location'] ?? '';
    }

    /** Krátky popis pre výpis pri chybe. */
    public function popis(): string
    {
        return "HTTP {$this->kod}: " . trim(preg_replace('/\s+/', ' ', strip_tags($this->telo)));
    }
}

/** Jednoduchý prehliadač: pamätá si cookies, presmerovania nesleduje (aby sa dali overiť). */
final class Prehliadac
{
    public array $cookies = [];

    public function __construct(private string $adresa) {}

    public function get(string $cesta): Odpoved
    {
        return $this->poziadavka('GET', $cesta);
    }

    public function post(string $cesta, array $polia): Odpoved
    {
        return $this->poziadavka('POST', $cesta, $polia);
    }

    public function postJson(string $cesta, array $data): Odpoved
    {
        return $this->poziadavka('POST', $cesta, json_encode($data), ['Content-Type: application/json']);
    }

    private function poziadavka(string $metoda, string $cesta, array|string|null $telo = null, array $hlavicky = []): Odpoved
    {
        $ch = curl_init(rtrim($this->adresa, '/') . '/' . ltrim($cesta, '/'));
        $prijateHlavicky = [];
        $cookieHlavicka = [];
        foreach ($this->cookies as $nazov => $hodnota) {
            $cookieHlavicka[] = $nazov . '=' . $hodnota;
        }
        if ($cookieHlavicka) {
            $hlavicky[] = 'Cookie: ' . implode('; ', $cookieHlavicka);
        }
        $hlavicky[] = 'Accept: text/html,application/json';
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $metoda,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => $hlavicky,
            CURLOPT_HEADERFUNCTION => function ($ch, string $riadok) use (&$prijateHlavicky) {
                if (str_contains($riadok, ':')) {
                    [$nazov, $hodnota] = explode(':', $riadok, 2);
                    $nazov = strtolower(trim($nazov));
                    $hodnota = trim($hodnota);
                    if ($nazov === 'set-cookie') {
                        $this->ulozCookie($hodnota);
                    }
                    $prijateHlavicky[$nazov] = $hodnota;
                }
                return strlen($riadok);
            },
        ]);
        if ($telo !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $telo);
        }
        $vystup = curl_exec($ch);
        if ($vystup === false) {
            $chyba = curl_error($ch);
            return new Odpoved(0, [], "Spojenie zlyhalo: $chyba");
        }
        return new Odpoved((int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $prijateHlavicky, (string) $vystup);
    }

    private function ulozCookie(string $hlavicka): void
    {
        $prva = explode(';', $hlavicka, 2)[0];
        if (!str_contains($prva, '=')) {
            return;
        }
        [$nazov, $hodnota] = explode('=', $prva, 2);
        $nazov = trim($nazov);
        $hodnota = trim($hodnota);
        // Zmazanie cookie: prázdna hodnota alebo dátum v minulosti.
        if ($hodnota === '' || $hodnota === 'deleted' || preg_match('/expires=[^;]*1970/i', $hlavicka)
            || preg_match('/max-age=0\b/i', $hlavicka)) {
            unset($this->cookies[$nazov]);
            return;
        }
        $this->cookies[$nazov] = $hodnota;
    }
}

/** Stránka nesmie obsahovať chybovú obrazovku appky ani výpis chyby PHP. */
function bez_chyby(Odpoved $o): bool
{
    return !str_contains($o->telo, 'Nastala chyba na serveri')
        && !preg_match('/(Fatal error|Parse error|Warning|Notice|Deprecated)<\/b>:/', $o->telo)
        && !preg_match('/^(PHP )?(Fatal error|Parse error|Warning|Notice):/m', $o->telo);
}

function csrf_zo_stranky(string $html): ?string
{
    return preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m) ? html_entity_decode($m[1]) : null;
}

/** Údaje receptov z hlavnej stránky (atribút data-payload na každej karte). */
function recepty_zo_stranky(string $html): array
{
    $recepty = [];
    preg_match_all('/data-recipe-id="(\d+)"\s+data-payload=\'([^\']*)\'/', $html, $zhody, PREG_SET_ORDER);
    foreach ($zhody as $z) {
        $data = json_decode(html_entity_decode($z[2], ENT_QUOTES), true);
        if (is_array($data)) {
            $recepty[(int) $z[1]] = $data;
        }
    }
    return $recepty;
}

/** Malá skutočná fotka JPEG, akú by poslal telefón (appka ju prevedie a zmenší). */
function testovacia_fotka(string $farba): CURLFile
{
    $obrazok = imagecreatetruecolor(800, 600);
    [$r, $g, $b] = sscanf($farba, '#%02x%02x%02x');
    imagefill($obrazok, 0, 0, imagecolorallocate($obrazok, $r, $g, $b));
    imagestring($obrazok, 5, 20, 20, 'Test ' . $farba, imagecolorallocate($obrazok, 255, 255, 255));
    $subor = tempnam(sys_get_temp_dir(), 'recepty-foto-') . '.jpg';
    imagejpeg($obrazok, $subor, 85);
    imagedestroy($obrazok);
    return new CURLFile($subor, 'image/jpeg', 'fotka.jpg');
}

/** Kategórie zo správy (admin/kategorie.php): [id => názov] v poradí na stránke. */
function kategorie_zo_spravy(string $html): array
{
    preg_match_all('/name="kategoria_id" value="(\d+)">\s*<input type="text" name="nazov"[^>]*value="([^"]*)"/', $html, $zhody, PREG_SET_ORDER);
    $kategorie = [];
    foreach ($zhody as $z) {
        $kategorie[(int) $z[1]] = html_entity_decode($z[2], ENT_QUOTES);
    }
    return $kategorie;
}

/** Hláška správy (zelená alebo červená) zo stránok v admin/. */
function hlaska_spravy(string $html): string
{
    return preg_match('/class="alert alert-(?:success|danger)">([^<]*)</', $html, $m) ? html_entity_decode($m[1], ENT_QUOTES) : '';
}

/** Suroviny na stránke správy surovín: [id => názov]. */
function suroviny_zo_spravy(string $html): array
{
    preg_match_all('/name="surovina_id" value="(\d+)">\s*<input type="text" name="nazov"[^>]*value="([^"]*)"/', $html, $zhody, PREG_SET_ORDER);
    $suroviny = [];
    foreach ($zhody as $z) {
        $suroviny[(int) $z[1]] = html_entity_decode($z[2], ENT_QUOTES);
    }
    return $suroviny;
}

function akcia(Prehliadac $p, string $csrf, string $akcia, array $polia = []): Odpoved
{
    return $p->post('index.php', ['action' => $akcia, 'csrf_token' => $csrf] + $polia);
}

/** Akcia vrátila HTTP 200 a JSON so success = true. */
function uspech(Odpoved $o): bool
{
    $data = $o->json();
    return $o->kod === 200 && $data !== null && ($data['success'] ?? false) === true;
}

/** Akcia nespadla: HTTP 200 a platný JSON (success môže byť false, napr. funkcia bez migrácie). */
function nespadla(Odpoved $o): bool
{
    return $o->kod === 200 && $o->json() !== null;
}

$tz = new DateTimeZone('Europe/Bratislava');
$dnes = (new DateTime('now', $tz))->format('Y-m-d');
$vcera = (new DateTime('-1 day', $tz))->format('Y-m-d');
$znacka = substr(bin2hex(random_bytes(3)), 0, 6);

echo "Režim: " . ($plny ? 'aktuálna databáza (overí sa všetko)' : 'databáza z predchádzajúcej verzie bez migrácie') . "\n";

/* ---------------------------------------------------------------------- */
/* 1. Prihlásenie                                                          */
/* ---------------------------------------------------------------------- */
echo "\nPrihlásenie\n";

$p = new Prehliadac($adresa);

$o = $p->get('index.php');
over(in_array($o->kod, [301, 302, 303], true) && str_contains($o->presmerovanie(), 'login.php'),
    'Hlavná stránka bez prihlásenia presmeruje na prihlásenie', $o->popis());
over(!isset($o->hlavicky['x-recepty-offline']), 'Presmerovanie na prihlásenie si zariadenie neuloží na offline');

$o = $p->get('login.php');
$csrf = csrf_zo_stranky($o->telo);
over($o->kod === 200 && bez_chyby($o) && str_contains($o->telo, 'Evidencia receptov'),
    'Prihlasovacia stránka sa načíta', $o->popis());
over($csrf !== null, 'Prihlasovacia stránka obsahuje bezpečnostný token (CSRF)');

$o = $p->post('login.php', ['action' => 'password_login', 'csrf_token' => (string) $csrf, 'username' => $meno, 'password' => 'zle-heslo']);
over($o->kod === 200 && str_contains($o->telo, 'Nesprávne používateľské meno alebo heslo'),
    'Nesprávne heslo sa odmietne', $o->popis());

$o = $p->get('login.php');
$csrf = csrf_zo_stranky($o->telo);
$o = $p->post('login.php', ['action' => 'password_login', 'csrf_token' => (string) $csrf,
    'username' => $meno, 'password' => $heslo, 'remember' => '1']);
over(in_array($o->kod, [301, 302, 303], true) && str_contains($o->presmerovanie(), 'index.php'),
    'Správne heslo prihlási a presmeruje do appky', $o->popis());

$o = $p->get('index.php');
over($o->kod === 200 && bez_chyby($o), 'Hlavná stránka sa po prihlásení načíta', $o->popis());
over(($o->hlavicky['x-recepty-offline'] ?? '') === '1',
    'Hlavnú stránku si zariadenie môže uložiť na čítanie bez internetu (hlavička X-Recepty-Offline)');
over(str_contains($o->telo, 'id="offlinePasik"') && substr_count($o->telo, 'data-vyzaduje-siet') >= 5,
    'Stránka má pásik offline režimu a označené tlačidlá, ktoré potrebujú server');
$csrf = csrf_zo_stranky($o->telo);
over($csrf !== null, 'Hlavná stránka obsahuje bezpečnostný token (CSRF)');
$csrf = (string) $csrf;

/* ---------------------------------------------------------------------- */
/* 1a. Hlasový asistent (verzia 2.7) — len v režime „asistent", s falošnou   */
/*     AI službou. Overí celý rozhovor aj s nástrojmi appky a končí.        */
/* ---------------------------------------------------------------------- */
if ($rezim === 'asistent') {
    $sluzba = getenv('TEST_ASISTENT_SLUZBA') ?: 'Gemini';
    echo "\nHlasový asistent ($sluzba)\n";
    $stranka = $p->get('index.php')->telo;
    over(str_contains($stranka, 'id="asistentModal"') && substr_count($stranka, 'onclick="openAsistent()"') >= 3,
        'S kľúčom k AI je asistent v appke (okno, bočná ponuka, hlavička, Viac)');
    $zostavaFotiek = preg_match('/id="aiZostava" data-zostava="(\d*)"/', $stranka, $m) ? $m[1] : null;

    $historia = [];
    $spytaj = static function (string $text) use ($p, $csrf, &$historia): Odpoved {
        $o = akcia($p, $csrf, 'asistent', ['text' => $text, 'historia' => json_encode($historia, JSON_UNESCAPED_UNICODE)]);
        if (($o->json()['success'] ?? false) === true) {
            $historia[] = ['rola' => 'pouzivatel', 'text' => $text];
            $historia[] = ['rola' => 'asistent', 'text' => $o->json()['odpoved']];
        }
        return $o;
    };

    $o = $spytaj('Ahoj');
    over(uspech($o) && str_contains((string) ($o->json()['odpoved'] ?? ''), 'Ahoj') && ($o->json()['zmeny'] ?? null) === false,
        'Asistent odpovie bez nástroja', $o->popis());
    over(($o->json()['poskytovatel'] ?? '') === $sluzba, "Odpovedala služba $sluzba", $o->popis());

    $nazov = "Bábovka od hlasu $znacka";
    $o = $spytaj("Ulož recept $nazov");
    $data = $o->json() ?? [];
    over(uspech($o) && ($data['zmeny'] ?? false) === true && ($data['akcie'][0]['nastroj'] ?? '') === 'pridaj_recept'
        && ($data['akcie'][0]['ok'] ?? false) === true, 'Asistent uloží recept (nástroj pridaj_recept)', $o->popis());
    over(str_starts_with((string) ($data['odpoved'] ?? ''), 'Hotovo. Recept'), 'Odpoveď po nástroji obsahuje jeho výsledok', $o->popis());
    $receptId = (int) ($data['akcie'][0]['recept_id'] ?? 0);
    $recept = recepty_zo_stranky($p->get('index.php')->telo)[$receptId] ?? null;
    over(($recept['name'] ?? '') === $nazov && count($recept['suroviny'] ?? []) === 3, 'Recept od asistenta je v appke aj so surovinami',
        json_encode($recept, JSON_UNESCAPED_UNICODE));
    $o = akcia($p, $csrf, 'get_logs', ['recipe_id' => $receptId]);
    over(in_array('Pridané cez hlasového asistenta', array_column($o->json()['logs'] ?? [], 'note'), true),
        'V histórii je, že recept pridal hlasový asistent', $o->popis());

    $o = $spytaj('Čo treba kúpiť?');
    over(uspech($o) && str_starts_with((string) ($o->json()['odpoved'] ?? ''), 'Hotovo.') && ($o->json()['zmeny'] ?? null) === false,
        'Asistent pozrie nákup (čítanie nič nemení)', $o->popis());
    $o = $spytaj("Nájdi $znacka");
    over(str_contains((string) ($o->json()['odpoved'] ?? ''), 'Našiel som 1 recept'), 'Asistent nájde recept', $o->popis());
    $o = $spytaj('Pamätáš si, čo si povedal?');
    over(str_contains((string) ($o->json()['odpoved'] ?? ''), 'Našiel som 1 recept'), 'Asistent dostane históriu rozhovoru', $o->popis());

    if (getenv('TEST_ASISTENT_ZALOHA') === '1') {
        $o = $spytaj('chyba503 a nájdi ' . $znacka);
        over(uspech($o) && ($o->json()['poskytovatel'] ?? '') !== 'Gemini',
            'Keď Gemini zlyhá (503), odpovie ďalšia služba v poradí', $o->popis());
    }

    $o = $spytaj('   ');
    over(nespadla($o) && ($o->json()['success'] ?? null) === false, 'Prázdna otázka sa odmietne', $o->popis());
    $o = akcia($p, 'zly-token', 'asistent', ['text' => 'Ahoj']);
    over($o->kod === 403, 'Asistent s neplatným tokenom odmietne', $o->popis());
    if ($zostavaFotiek !== null && $zostavaFotiek !== '') {
        over(preg_match('/id="aiZostava" data-zostava="' . $zostavaFotiek . '"/', $p->get('index.php')->telo) === 1,
            'Otázky asistenta sa nerátajú do limitu fotiek');
    }

    // Denný limit (AI_ASISTENT_DENNY_LIMIT v teste je malý): skôr či neskôr asistent odmietne.
    $odmietol = false;
    for ($i = 0; $i < 12 && !$odmietol; $i++) {
        $o = akcia($p, $csrf, 'asistent', ['text' => 'Ahoj', 'historia' => '[]']);
        $odmietol = ($o->json()['success'] ?? null) === false && str_contains((string) ($o->json()['error'] ?? ''), 'minutý');
    }
    over($odmietol, 'Po minutí denného limitu asistent odmietne', $o->popis());

    akcia($p, $csrf, 'delete_recipe', ['recipe_id' => $receptId]);
    echo "\n" . ($chyby === 0 ? "Všetky kontroly prešli.\n" : "Počet zlyhaných kontrol: $chyby\n");
    exit($chyby === 0 ? 0 : 1);
}

/* ---------------------------------------------------------------------- */
/* 2. Statické súbory                                                      */
/* ---------------------------------------------------------------------- */
echo "\nStatické súbory\n";
foreach (['style.css', 'script.js', 'suroviny.js', 'theme.js', 'webauthn-client.js', 'sw.js', 'offline.html', 'manifest.json'] as $subor) {
    $o = $p->get($subor);
    over($o->kod === 200 && strlen($o->telo) > 0, "$subor sa dá stiahnuť", $o->popis());
}
over(json_decode($p->get('manifest.json')->telo, true) !== null, 'manifest.json je platný JSON');

/* ---------------------------------------------------------------------- */
/* 3. Recepty                                                              */
/* ---------------------------------------------------------------------- */
echo "\nRecepty\n";

$o = akcia($p, 'zly-token', 'get_logs', ['recipe_id' => 1]);
over($o->kod === 403 && ($o->json()['success'] ?? null) === false, 'Akcia s neplatným tokenom sa odmietne', $o->popis());
over(!isset($o->hlavicky['x-recepty-offline']), 'Odpoveď akcie (JSON) nemá značku pre offline');

$nazov = "Kurací vývar test $znacka";
$suroviny = json_encode([
    ['nazov' => 'Múka hladká', 'mnozstvo' => '250', 'jednotka' => 'g', 'poznamka' => ''],
    ['nazov' => "Testovacia surovina $znacka", 'mnozstvo' => '2', 'jednotka' => 'ks', 'poznamka' => 'čerstvá'],
], JSON_UNESCAPED_UNICODE);

$o = akcia($p, $csrf, 'add_recipe', [
    'name' => $nazov, 'category' => 'Polievky', 'prep_time' => '45', 'servings' => '4',
    'ingredients' => "Postup: všetko uvariť.\nŠpeciálne znaky: <b>&\"'", 'url' => 'https://example.com/recept',
    'suroviny_json' => $suroviny, 'photo_date' => $vcera, 'recipe_image' => testovacia_fotka('#c0392b'),
]);
over(uspech($o), 'Pridanie receptu s fotkou a surovinami', $o->popis());
$receptId = (int) ($o->json()['id'] ?? 0);
over($receptId > 0, 'Nový recept dostal číslo', $o->popis());

$o = $p->get('index.php');
$recepty = recepty_zo_stranky($o->telo);
$recept = $recepty[$receptId] ?? null;
over($recept !== null && ($recept['name'] ?? '') === $nazov, 'Recept je v zozname s presným názvom (aj s diakritikou)');
over(!str_contains($o->telo, "<b>&\"'"), 'Špeciálne znaky z poznámok sa na stránke nevypíšu ako HTML');
over(!empty($recept['imagePath']), 'Recept má titulnú fotku', json_encode($recept, JSON_UNESCAPED_UNICODE));
if (!empty($recept['imagePath'])) {
    $foto = $p->get((string) $recept['imagePath']);
    over($foto->kod === 200 && str_starts_with($foto->telo, "\xFF\xD8"), 'Fotka sa uložila na disk a dá sa zobraziť ako JPEG', $foto->popis());
}
if ($plny) {
    over(count($recept['suroviny'] ?? []) === 2, 'Recept má 2 suroviny z číselníka', json_encode($recept['suroviny'] ?? null, JSON_UNESCAPED_UNICODE));
    over((int) ($recept['servings'] ?? 0) === 4, 'Recept má uložený počet porcií');
    over(count($recept['photos'] ?? []) === 1 && ($recept['photos'][0]['date'] ?? '') === $vcera,
        'Fotka je v galérii so zvoleným dátumom', json_encode($recept['photos'] ?? null));
}

$o = akcia($p, $csrf, 'update_recipe', [
    'recipe_id' => $receptId, 'name' => "$nazov upravený", 'category' => 'Polievky', 'prep_time' => '50', 'servings' => '6',
    'ingredients' => 'Upravený postup.', 'suroviny_json' => $suroviny, 'recipe_image' => testovacia_fotka('#27ae60'),
]);
over(uspech($o), 'Úprava receptu s novou fotkou', $o->popis());

$o = akcia($p, $csrf, 'mark_made', ['recipe_id' => $receptId, 'made_date' => $dnes, 'recipe_image' => testovacia_fotka('#2980b9')]);
over(uspech($o), 'Zápis varenia (Uvarené) s fotkou', $o->popis());

$o = akcia($p, $csrf, 'mark_made', ['recipe_id' => $receptId, 'made_date' => '2099-01-01']);
over(uspech($o), 'Zápis varenia s dátumom v budúcnosti sa nezrúti (použije sa dnešok)', $o->popis());

$o = akcia($p, $csrf, 'rate_recipe', ['recipe_id' => $receptId, 'rating' => 5]);
over(uspech($o), 'Hodnotenie receptu 5 hviezdičkami', $o->popis());

$o = akcia($p, $csrf, 'rate_recipe', ['recipe_id' => $receptId, 'rating' => 9]);
over($o->kod === 200 && ($o->json()['success'] ?? null) === false, 'Neplatné hodnotenie sa odmietne', $o->popis());

$o = $p->get('index.php');
$recept = recepty_zo_stranky($o->telo)[$receptId] ?? null;
over(($recept['name'] ?? '') === "$nazov upravený", 'Úprava sa prejavila v zozname');
over(($recept['lastMadeDate'] ?? '') === $dnes, 'Dátum posledného varenia je dnes', json_encode($recept['lastMadeDate'] ?? null));
if ($plny) {
    $fotky = $recept['photos'] ?? [];
    over(count($fotky) === 3, 'Galéria má 3 fotky (pridanie, úprava, varenie)', json_encode($fotky));
    over(($recept['imagePath'] ?? '') === ($fotky[0]['path'] ?? '-'), 'Titulná fotka je najnovšia z galérie');
    over((int) ($recept['servings'] ?? 0) === 6, 'Úprava zmenila počet porcií');
}

$o = akcia($p, $csrf, 'get_logs', ['recipe_id' => $receptId]);
$zaznamy = array_column($o->json()['logs'] ?? [], 'action');
over(uspech($o), 'História receptu sa načíta', $o->popis());
foreach (['created', 'updated', 'made', 'rated'] as $druh) {
    over(in_array($druh, $zaznamy, true), "História obsahuje záznam „{$druh}“", implode(', ', $zaznamy));
}

/* ---------------------------------------------------------------------- */
/* 4. Fotky v galérii                                                      */
/* ---------------------------------------------------------------------- */
echo "\nGaléria fotiek\n";
$fotky = $recept['photos'] ?? [];
if ($plny && count($fotky) >= 2) {
    $najstarsia = end($fotky);
    $o = akcia($p, $csrf, 'delete_photo', ['photo_id' => $najstarsia['id']]);
    over(uspech($o) && count($o->json()['photos'] ?? []) === count($fotky) - 1, 'Admin zmaže jednu fotku z galérie', $o->popis());
    $foto = $p->get((string) $najstarsia['path']);
    over($foto->kod === 404, 'Zmazaná fotka zmizla aj z disku', $foto->popis());
} else {
    $o = akcia($p, $csrf, 'delete_photo', ['photo_id' => 999999]);
    over(nespadla($o), 'Mazanie fotky bez galérie v databáze nespadne', $o->popis());
}

/* ---------------------------------------------------------------------- */
/* 5. Nákupný zoznam                                                       */
/* ---------------------------------------------------------------------- */
echo "\nNákupný zoznam\n";
$o = akcia($p, $csrf, 'shopping_get');
if ($plny) {
    over(uspech($o), 'Nákupný zoznam sa načíta', $o->popis());
    $o = akcia($p, $csrf, 'shopping_add_recipe', ['recipe_id' => $receptId, 'servings' => '8']);
    over(uspech($o) && count($o->json()['items'] ?? []) >= 2, 'Suroviny receptu sa pridajú do nákupu', $o->popis());
    $o = akcia($p, $csrf, 'shopping_add_item', ['nazov' => "Toaletný papier $znacka"]);
    $polozky = $o->json()['items'] ?? [];
    over(uspech($o), 'Vlastná položka sa pridá do nákupu', $o->popis());
    $polozka = null;
    foreach ($polozky as $kandidat) {
        if (str_contains((string) ($kandidat['nazov'] ?? ''), $znacka) && str_contains((string) ($kandidat['nazov'] ?? ''), 'Toaletný')) {
            $polozka = $kandidat;
        }
    }
    over($polozka !== null, 'Vlastná položka je v zozname', json_encode($polozky, JSON_UNESCAPED_UNICODE));
    if ($polozka) {
        $o = akcia($p, $csrf, 'shopping_toggle', ['item_id' => $polozka['id'], 'kupene' => '1']);
        over(uspech($o), 'Položka sa dá odškrtnúť', $o->popis());
        $o = akcia($p, $csrf, 'shopping_delete', ['item_id' => $polozka['id']]);
        over(uspech($o), 'Položka sa dá zmazať', $o->popis());
    }
    $o = akcia($p, $csrf, 'shopping_clear', ['mode' => 'all']);
    over(uspech($o) && count($o->json()['items'] ?? [1]) === 0, 'Celý zoznam sa dá vymazať', $o->popis());
} else {
    over(nespadla($o), 'Nákupný zoznam nespadne', $o->popis());
}

/* ---------------------------------------------------------------------- */
/* 5a. Objednané jedlo (verzia 2.2, tabuľka z migracia-2.2.sql)            */
/* ---------------------------------------------------------------------- */
echo "\nObjednané jedlo\n";
/** Objednávky z odpovede akcie alebo zo stránky (window.APP_OBJEDNAVKY). */
$objednavka = function (array $polozky, int $id): ?array {
    foreach ($polozky as $polozka) {
        if ((int) ($polozka['id'] ?? 0) === $id) {
            return $polozka;
        }
    }
    return null;
};
$stranka = $p->get('index.php')->telo;
$o = akcia($p, $csrf, 'objednavky_get');
// Databáza predchádzajúcej verzie môže tabuľku objednane_jedla už mať (od 2.2) — vtedy sa overí celé.
$objednavkyVDb = $plny || str_contains($stranka, 'id="strankaObjednane"');
if ($objednavkyVDb) {
    over(str_contains($stranka, 'id="strankaObjednane"') && str_contains($stranka, 'id="objednavkaForm"')
        && str_contains($stranka, 'data-stranka="objednane"'),
        'Objednané jedlo je samostatná stránka s formulárom a je v bočnej ponuke');
    over((bool) preg_match('~<nav class="spodna-lista[^"]*ma-5-poloziek[^>]*>.*?data-lista="recepty".*?data-lista="objednane".*?data-lista="pridat".*?data-lista="nakup".*?data-lista="viac"~s', $stranka),
        'Spodná lišta: Recepty, Objednané, Pridať, Nákup, Viac');
    over(uspech($o) && is_array($o->json()['polozky'] ?? null), 'Objednávky sa načítajú', $o->popis());

    $o = akcia($p, $csrf, 'objednavka_ulozit', ['sluzba' => 'wolt', 'restauracia' => "  KFC $znacka  ", 'jedlo' => "Bucket\nhranolky",
        'datum' => $vcera, 'cena' => '24,90 €']);
    $kfcId = (int) ($o->json()['id'] ?? 0);
    $kfc = $objednavka($o->json()['polozky'] ?? [], $kfcId);
    over(uspech($o) && $kfc !== null, 'Objednávka sa zapíše', $o->popis());
    over(($kfc['restauracia'] ?? '') === "KFC $znacka" && ($kfc['cena'] ?? null) === 24.9 && ($kfc['datum'] ?? '') === $vcera
        && ($kfc['sluzba'] ?? '') === 'wolt' && ($kfc['jedlo'] ?? '') === "Bucket\nhranolky" && ($kfc['mozeZmazat'] ?? false) === true
        && ($kfc['pridal'] ?? '') === 'Testovací Admin',
        'Uloží sa reštaurácia (orezaná), jedlo, dátum, cena „24,90 €" aj kto ju zapísal', json_encode($kfc, JSON_UNESCAPED_UNICODE));

    $o = akcia($p, $csrf, 'objednavka_ulozit', ['sluzba' => 'neznama', 'jedlo' => "Pizza $znacka", 'datum' => '']);
    $pizzaId = (int) ($o->json()['id'] ?? 0);
    $polozky = $o->json()['polozky'] ?? [];
    $pizza = $objednavka($polozky, $pizzaId);
    over(uspech($o) && ($pizza['datum'] ?? '') === $dnes && ($pizza['sluzba'] ?? '') === 'ine'
        && array_key_exists('cena', $pizza ?? []) && $pizza['cena'] === null,
        'Bez reštaurácie a dátumu sa uloží na dnes, neznáma služba ako Iné', json_encode($pizza, JSON_UNESCAPED_UNICODE));
    over((int) ($polozky[0]['id'] ?? 0) === $pizzaId, 'Najnovšia objednávka je v zozname prvá');

    $o = akcia($p, $csrf, 'objednavka_ulozit', ['sluzba' => 'bolt', 'restauracia' => ' ', 'jedlo' => '']);
    over(nespadla($o) && str_contains((string) ($o->json()['error'] ?? ''), 'reštauráciu'), 'Prázdna objednávka sa odmietne', $o->popis());
    $zajtra = (new DateTime('+1 day', $tz))->format('Y-m-d');
    $o = akcia($p, $csrf, 'objednavka_ulozit', ['restauracia' => 'Budúcnosť', 'datum' => $zajtra]);
    over(nespadla($o) && str_contains((string) ($o->json()['error'] ?? ''), 'Dátum'), 'Dátum v budúcnosti sa odmietne', $o->popis());
    $o = akcia($p, $csrf, 'objednavka_ulozit', ['restauracia' => 'Drahá', 'cena' => 'veľa']);
    over(nespadla($o) && str_contains((string) ($o->json()['error'] ?? ''), 'Cena'), 'Cena, ktorá nie je číslo, sa odmietne', $o->popis());

    $o = akcia($p, $csrf, 'objednavka_ulozit', ['objednavka_id' => $kfcId, 'sluzba' => 'bistro', 'restauracia' => "KFC $znacka",
        'jedlo' => 'Bucket', 'datum' => $vcera, 'cena' => '']);
    $kfc = $objednavka($o->json()['polozky'] ?? [], $kfcId);
    over(uspech($o) && ($kfc['sluzba'] ?? '') === 'bistro' && ($kfc['jedlo'] ?? '') === 'Bucket' && array_key_exists('cena', $kfc ?? [])
        && $kfc['cena'] === null, 'Objednávka sa dá upraviť (aj zmazať cenu)', json_encode($kfc, JSON_UNESCAPED_UNICODE));
    $o = akcia($p, $csrf, 'objednavka_ulozit', ['objednavka_id' => 999999, 'restauracia' => 'Nikde']);
    over(nespadla($o) && str_contains((string) ($o->json()['error'] ?? ''), 'neexistuje'), 'Úprava neexistujúcej objednávky sa odmietne', $o->popis());

    // Fotka (verzia 2.4, stĺpec foto z migracia-2.4.sql): spracuje sa ako fotka receptu, uloží do uploads/objfood/.
    $fotkyVDb = $plny || str_contains($stranka, 'id="objednavkaFotoPole"');
    $upravKfc = fn(array $polia) => akcia($p, $csrf, 'objednavka_ulozit', $polia + ['objednavka_id' => $kfcId, 'sluzba' => 'bistro',
        'restauracia' => "KFC $znacka", 'jedlo' => 'Bucket', 'datum' => $vcera]);
    $fotkaKfc = fn(Odpoved $o) => (string) ($objednavka($o->json()['polozky'] ?? [], $kfcId)['foto'] ?? '');
    $o = $upravKfc(['foto' => testovacia_fotka('#d04020')]);
    $prvaFotka = $fotkaKfc($o);
    $poslednaFotka = '';
    if ($fotkyVDb) {
        over(str_contains($stranka, 'id="objednavkaFotoPole"') && str_contains($stranka, 'id="objednavkaFotoKamera"'),
            'Formulár objednávky ponúka fotku (odfotiť aj z galérie)');
        over(uspech($o) && str_starts_with($prvaFotka, 'uploads/objfood/objfood_') && str_ends_with($prvaFotka, '.jpg'),
            'Fotka objednávky sa uloží do uploads/objfood/ (priečinok si appka vytvorí sama)', $o->popis() . ' foto=' . $prvaFotka);
        $f = $p->get($prvaFotka);
        over($f->kod === 200 && str_starts_with($f->telo, "\xFF\xD8"), 'Fotka objednávky sa dá otvoriť a je to JPEG', "HTTP {$f->kod}");

        $o = $upravKfc(['foto' => testovacia_fotka('#2040d0')]);
        $druhaFotka = $fotkaKfc($o);
        over(uspech($o) && str_starts_with($druhaFotka, 'uploads/objfood/') && $druhaFotka !== $prvaFotka && $p->get($prvaFotka)->kod === 404,
            'Nová fotka nahradí starú a stará sa zmaže z disku', $o->popis());
        $o = $upravKfc(['cena' => '9,90']);
        over(uspech($o) && $fotkaKfc($o) === $druhaFotka, 'Úprava bez novej fotky fotku ponechá', $o->popis());
        $o = $upravKfc(['odstranit_foto' => '1']);
        over(uspech($o) && $fotkaKfc($o) === '' && $p->get($druhaFotka)->kod === 404, 'Fotka sa dá odstrániť (zmaže sa aj z disku)', $o->popis());

        $text = tempnam(sys_get_temp_dir(), 'recepty-text-');
        file_put_contents($text, 'toto nie je obrázok');
        $o = $upravKfc(['foto' => new CURLFile($text, 'image/jpeg', 'fotka.jpg')]);
        over(nespadla($o) && str_contains((string) ($o->json()['error'] ?? ''), 'obrázok'), 'Súbor, ktorý nie je obrázok, sa odmietne', $o->popis());

        $o = $upravKfc(['foto' => testovacia_fotka('#20a040')]);
        $poslednaFotka = $fotkaKfc($o);
    } else {
        over(uspech($o) && $prvaFotka === '', 'Bez migrácie 2.4 sa objednávka uloží, fotka sa len nezapíše', $o->popis());
        over(!str_contains($stranka, 'id="objednavkaFotoPole"'), 'Bez migrácie 2.4 formulár fotku neponúka');
    }

    // Náhľad a hodnotenie (verzia 2.5, tabuľka objednavky_hodnotenia z migracia-2.5.sql).
    over(str_contains($stranka, 'data-cast="nahlad"') && str_contains($stranka, 'id="nahladUpravit"') && str_contains($stranka, 'id="nahladZmazat"'),
        'Objednávka má náhľad ako recept (s tlačidlami Upraviť a Zmazať)');
    $hodnoteniaVDb = $plny || str_contains($stranka, 'id="nahladHodnotenie"');
    $o = akcia($p, $csrf, 'objednavka_hodnotit', ['objednavka_id' => $kfcId, 'hodnotenie' => '4']);
    if ($hodnoteniaVDb) {
        $kfc = $objednavka($o->json()['polozky'] ?? [], $kfcId);
        over(uspech($o) && ($kfc['mojeHodnotenie'] ?? 0) === 4 && ($kfc['hodnotenie']['pocet'] ?? 0) === 1
            && ($kfc['hodnotenie']['priemer'] ?? 0) == 4, 'Objednávka sa dá ohodnotiť hviezdičkami', $o->popis());
        $o = akcia($p, $csrf, 'objednavka_hodnotit', ['objednavka_id' => $kfcId, 'hodnotenie' => '5']);
        $kfc = $objednavka($o->json()['polozky'] ?? [], $kfcId);
        over(uspech($o) && ($kfc['mojeHodnotenie'] ?? 0) === 5 && ($kfc['hodnotenie']['pocet'] ?? 0) === 1,
            'Nové hodnotenie toho istého človeka prepíše staré (jeden hlas)', json_encode($kfc['hodnotenie'] ?? null));
        $o = akcia($p, $csrf, 'objednavka_hodnotit', ['objednavka_id' => $kfcId, 'hodnotenie' => '9']);
        over(nespadla($o) && str_contains((string) ($o->json()['error'] ?? ''), 'Neplatné'), 'Hodnotenie mimo 1 až 5 sa odmietne', $o->popis());
        $o = akcia($p, $csrf, 'objednavka_hodnotit', ['objednavka_id' => 999999, 'hodnotenie' => '3']);
        over(nespadla($o) && str_contains((string) ($o->json()['error'] ?? ''), 'neexistuje'), 'Hodnotenie neexistujúcej objednávky sa odmietne', $o->popis());
        over(str_contains($stranka, 'id="nahladHodnotenie"'), 'Náhľad objednávky má hviezdičky');
    } else {
        over(nespadla($o) && ($o->json()['success'] ?? null) === false, 'Bez migrácie 2.5 sa hodnotenie odmietne, appka nespadne', $o->popis());
        over(!str_contains($stranka, 'id="nahladHodnotenie"'), 'Bez migrácie 2.5 náhľad hviezdičky neukazuje');
    }

    $stranka = $p->get('index.php')->telo;
    $zoStranky = preg_match('/window\.APP_OBJEDNAVKY = (.*?);\n/', $stranka, $m) ? json_decode($m[1], true) : null;
    over(is_array($zoStranky) && $objednavka($zoStranky, $kfcId) !== null, 'Objednávky sú priamo v stránke (pre pozretie bez internetu)');

    $o = akcia($p, $csrf, 'objednavka_zmazat', ['objednavka_id' => $kfcId]);
    over(uspech($o) && $objednavka($o->json()['polozky'] ?? [], $kfcId) === null, 'Objednávka sa dá zmazať', $o->popis());
    if ($fotkyVDb) {
        over($poslednaFotka !== '' && $p->get($poslednaFotka)->kod === 404, 'So zmazanou objednávkou sa zmaže aj jej fotka', $poslednaFotka);
    }
    akcia($p, $csrf, 'objednavka_zmazat', ['objednavka_id' => $pizzaId]);
    $o = akcia($p, $csrf, 'objednavka_zmazat', ['objednavka_id' => $kfcId]);
    over(nespadla($o) && ($o->json()['success'] ?? null) === false, 'Druhé zmazanie tej istej objednávky sa odmietne a nespadne', $o->popis());
    $o = $p->post('index.php', ['action' => 'objednavka_ulozit', 'csrf_token' => 'zly', 'restauracia' => 'Bez tokenu']);
    over($o->kod === 403, 'Zápis objednávky s neplatným tokenom sa odmietne', $o->popis());
} else {
    over(!str_contains($stranka, 'id="strankaObjednane"') && !str_contains($stranka, 'data-lista="objednane"')
        && !str_contains($stranka, 'data-stranka="objednane"'),
        'Bez migrácie sa Objednané jedlo v ponuke ani v lište neukáže');
    over(nespadla($o) && ($o->json()['success'] ?? null) === false, 'Bez migrácie načítanie objednávok nespadne', $o->popis());
    $o = akcia($p, $csrf, 'objednavka_ulozit', ['restauracia' => "KFC $znacka"]);
    over(nespadla($o) && ($o->json()['success'] ?? null) === false, 'Bez migrácie sa objednávka nezapíše, appka nespadne', $o->popis());
}

/* ---------------------------------------------------------------------- */
/* 5b. Recept z fotky (AI, verzia 1.8) — test nemá kľúč k AI, funkcia musí  */
/*     byť vypnutá, nič neukázať a pri pokuse nespadnúť.                     */
/* ---------------------------------------------------------------------- */
echo "\nRecept z fotky (AI)\n";
$stranka = $p->get('index.php')->telo;
over(!str_contains($stranka, 'id="aiModal"') && !str_contains($stranka, 'id="aiVyplnitWrap"'),
    'Bez kľúča k AI nie je sprievodca ani tlačidlo Vyplniť z fotky');
over(str_contains($stranka, 'window.APP_AI = false'), 'Stránka vie, že AI je vypnutá');
$o = akcia($p, $csrf, 'ai_rozpoznaj', ['fotka' => testovacia_fotka('#c08040')]);
over(nespadla($o) && ($o->json()['success'] ?? null) === false && str_contains((string) ($o->json()['error'] ?? ''), 'kľúč'),
    'Rozpoznanie bez kľúča sa odmietne a nespadne', $o->popis());

/* ---------------------------------------------------------------------- */
/* 5c. Formulár receptu (verzia 1.9) — na telefóne sprievodca v troch       */
/*     krokoch, ale stále jeden formulár so všetkými poliami (PC ho má celý). */
/* ---------------------------------------------------------------------- */
echo "\nFormulár receptu\n";
$formular = '';
if (preg_match('~<form id="recipeForm".*?</form>~s', $stranka, $m)) {
    $formular = $m[0];
}
over($formular !== '', 'Stránka obsahuje formulár receptu');
$chybajuce = [];
foreach (['name="name"', 'name="category"', 'name="prep_time"', 'name="url"', 'name="ingredients"', 'name="recipe_image"', 'name="last_made_date"', 'id="recipeFormSubmit"'] as $pole) {
    if (!str_contains($formular, $pole)) {
        $chybajuce[] = $pole;
    }
}
if ($plny) {
    foreach (['name="suroviny_json"', 'name="servings"', 'id="recipeImageKamera"'] as $pole) {
        if (!str_contains($formular, $pole)) {
            $chybajuce[] = $pole;
        }
    }
}
over(!$chybajuce, 'Formulár má všetky polia', implode(', ', $chybajuce));
preg_match_all('~class="formular-krok" data-krok="(\d)" data-nadpis="([^"]+)"~', $formular, $kroky);
over($kroky[1] === ['1', '2', '3'], 'Sprievodca má tri kroky: ' . implode(' → ', $kroky[2]), implode(',', $kroky[1]));
over(str_contains($formular, 'id="recipeKrokDalej"') && str_contains($formular, 'class="formular-cip"'),
    'Sprievodca má tlačidlo Ďalej a kategórie ako tlačidlá');

/* 5d. Rozloženie na PC (verzia 2.0): bočná ponuka so všetkými akciami, náhľad receptu s akciami. */
echo "\nRozloženie na PC\n";
$ponuka = '';
if (preg_match('~<nav class="app-bocna.*?</nav>~s', $stranka, $m)) {
    $ponuka = $m[0];
}
$chybajuce = [];
foreach (['openRecipeModal()', 'id="themeToggle"', 'enablePushNotifications()', 'setupPasskeyFromApp()', 'href="logout.php"', 'href="admin/index.php"'] as $akcia) {
    if (!str_contains($ponuka, $akcia)) {
        $chybajuce[] = $akcia;
    }
}
if ($plny && !str_contains($ponuka, 'openShoppingModal()')) {
    $chybajuce[] = 'openShoppingModal()';
}
over($ponuka !== '' && !$chybajuce, 'Bočná ponuka má všetky akcie (pridať, nákup, motív, upozornenia, odtlačok, správa, odhlásiť)', implode(', ', $chybajuce));
$chybajuce = [];
foreach (['id="viewPcUpravit"', 'id="viewPcHistoria"', 'id="viewPcZdielat"', 'id="viewPcZmazat"', 'id="viewPcUvarene"', 'id="recipeSortSelect"', 'id="receptyPocet"'] as $prvok) {
    if (!str_contains($stranka, $prvok)) {
        $chybajuce[] = $prvok;
    }
}
over(!$chybajuce, 'Náhľad receptu má akcie s popisom, zoradenie a počet receptov', implode(', ', $chybajuce));

/* ---------------------------------------------------------------------- */
/* 5d. AI asistent cez MCP (verzia 2.6, tabuľka z migracia-2.6.sql) —       */
/*     adresa z appky, asistent sa pripojí, hľadá, pridá recept, zapíše     */
/*     varenie; zrušená adresa už nefunguje. Bez migrácie nič nespadne.    */
/* ---------------------------------------------------------------------- */
echo "\nAI asistent (MCP)\n";
$asistent = new Prehliadac($adresa); // ako Claude: bez cookies appky, len s adresou
/** Jedna správa JSON-RPC na mcp.php. */
$mcp = static function (string $kluc, string $metoda, array $parametre = [], ?int $id = 1) use ($asistent): Odpoved {
    $sprava = ['jsonrpc' => '2.0', 'method' => $metoda] + ($parametre ? ['params' => $parametre] : []);
    if ($id !== null) {
        $sprava['id'] = $id;
    }
    return $asistent->postJson('mcp.php?kluc=' . $kluc, $sprava);
};
$stranka = $p->get('index.php')->telo;
$o = $asistent->get('mcp.php');
over($o->kod === 405 && isset($o->json()['error']), 'mcp.php otvorený v prehliadači (GET) odpovie 405 a JSON', $o->popis());

// Tabuľka ai_pripojenia je od 2.6. Databáza „predchádzajúcej verzie" ju môže už mať (keď je vydaná 2.6
// a testuje sa novšia verzia) — vtedy sa overí celé pripojenie, inak len to, že bez nej nič nespadne.
$mcpVDatabaze = str_contains($stranka, 'id="aiAsistentModal"');
if ($plny) {
    over($mcpVDatabaze && str_contains($stranka, 'onclick="openAiAsistent()"'), 'Nastavenia ponúkajú pripojenie k Claude (okno aj tlačidlo)');
}
if ($mcpVDatabaze) {
    $o = akcia($p, $csrf, 'ai_pripojenie_vytvor', ['nazov' => "Claude test $znacka"]);
    $adresaMcp = (string) ($o->json()['adresa'] ?? '');
    over(uspech($o) && preg_match('~/mcp\.php\?kluc=([a-f0-9]{48})$~', $adresaMcp, $m) === 1, 'Vytvorenie adresy pre asistenta', $o->popis());
    $kluc = $m[1] ?? '';
    $pripojenia = $o->json()['pripojenia'] ?? [];
    over(in_array("Claude test $znacka", array_column($pripojenia, 'nazov'), true) && !str_contains(json_encode($pripojenia), $kluc),
        'Pripojenie je v zozname a kľúč sa v zozname neukazuje');
    $o = akcia($p, 'zly-token', 'ai_pripojenie_vytvor', ['nazov' => 'Bez tokenu']);
    over($o->kod === 403, 'Vytvorenie adresy s neplatným tokenom sa odmietne', $o->popis());

    $o = $mcp($kluc, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(),
        'clientInfo' => ['name' => 'smoke-test', 'version' => '1']]);
    $init = $o->json()['result'] ?? [];
    over($o->kod === 200 && ($init['protocolVersion'] ?? '') === '2025-06-18' && isset($init['capabilities']['tools']),
        'Asistent sa pripojí (initialize, verzia protokolu podľa klienta)', $o->popis());
    over(str_contains((string) ($init['instructions'] ?? ''), 'Testovací') || str_contains((string) ($init['instructions'] ?? ''), $meno),
        'Pokyny pre asistenta obsahujú meno používateľa');
    $o = $mcp($kluc, 'notifications/initialized', [], null);
    over($o->kod === 202 && trim($o->telo) === '', 'Oznámenie (bez id) dostane 202 bez odpovede', $o->popis());
    $o = $mcp($kluc, 'tools/list');
    $nastroje = array_column($o->json()['result']['tools'] ?? [], 'name');
    over(!array_diff(['najdi_recepty', 'zobraz_recept', 'pridaj_recept', 'zapis_varenie', 'pridaj_do_nakupu', 'zobraz_nakup'], $nastroje),
        'Asistent vidí všetky nástroje', implode(', ', $nastroje));

    $volaj = static fn(string $nastroj, array $argumenty) => $mcp($kluc, 'tools/call', ['name' => $nastroj, 'arguments' => $argumenty]);
    $vysledok = static fn(Odpoved $o): array => $o->json()['result'] ?? [];

    $nazovMcp = "Bábovka od asistenta $znacka";
    $o = $volaj('pridaj_recept', [
        'nazov' => $nazovMcp, 'kategoria' => 'dezerty a pecenie', 'porcie' => 8, 'cas_pripravy' => 60,
        'postup' => "1. Vymiešaj.\n2. Upeč.",
        'suroviny' => [
            ['nazov' => 'hladká múka', 'mnozstvo' => 300, 'jednotka' => 'g'],   // poradie slov → Múka hladká
            ['nazov' => 'Kakao', 'mnozstvo' => 2, 'jednotka' => 'pl'],         // malé písmená v jednotke → PL
            ['nazov' => 'Mlieko', 'mnozstvo' => 1, 'jednotka' => 'hrnček'],    // neznáma jednotka → poznámka
            ['nazov' => "Asistentova surovina $znacka", 'mnozstvo' => 1, 'jednotka' => 'ks'],
        ],
    ]);
    $r = $vysledok($o);
    $mcpId = (int) ($r['structuredContent']['id'] ?? 0);
    over($o->kod === 200 && ($r['isError'] ?? true) === false && $mcpId > 0, 'Asistent pridá recept', $o->popis());
    over(in_array('Hladká múka → Múka hladká', $r['structuredContent']['naparovane_suroviny'] ?? [], true),
        'Surovina s iným poradím slov sa napáruje na číselník', json_encode($r['structuredContent'] ?? null, JSON_UNESCAPED_UNICODE));
    $recept = recepty_zo_stranky($p->get('index.php')->telo)[$mcpId] ?? null;
    over(($recept['name'] ?? '') === $nazovMcp && ($recept['category'] ?? '') === 'Dezerty' && (int) ($recept['servings'] ?? 0) === 8,
        'Recept od asistenta je v appke s kategóriou a porciami', json_encode($recept, JSON_UNESCAPED_UNICODE));
    $surovinyMcp = array_column($recept['suroviny'] ?? [], null, 'nazov');
    over(($surovinyMcp['Kakao']['jednotka'] ?? '') === 'PL' && ($surovinyMcp['Mlieko']['poznamka'] ?? '') === 'hrnček' && isset($surovinyMcp['Múka hladká']),
        'Jednotky sa opravia (pl → PL, hrnček do poznámky)', json_encode($recept['suroviny'] ?? null, JSON_UNESCAPED_UNICODE));
    $o = akcia($p, $csrf, 'get_logs', ['recipe_id' => $mcpId]);
    over(str_contains($o->telo, "Claude test $znacka"), 'V histórii receptu je, že ho pridal asistent', $o->popis());

    $o = $volaj('pridaj_recept', ['nazov' => mb_strtoupper($nazovMcp)]);
    over(($vysledok($o)['isError'] ?? false) === true && !isset(recepty_zo_stranky($p->get('index.php')->telo)[$mcpId + 1]),
        'Rovnaký recept druhýkrát sa neuloží (ochrana pred zdvojením)', $o->popis());
    $o = $volaj('najdi_recepty', ['dotaz' => "babovka asistenta $znacka"]);
    over(($vysledok($o)['structuredContent']['recepty'][0]['id'] ?? 0) === $mcpId, 'Asistent nájde recept bez diakritiky', $o->popis());
    $o = $volaj('zobraz_recept', ['id' => $mcpId]);
    over(str_contains((string) ($vysledok($o)['content'][0]['text'] ?? ''), '300 g Múka hladká'), 'Asistent zobrazí recept so surovinami', $o->popis());
    $o = $volaj('zapis_varenie', ['id' => $mcpId, 'datum' => $vcera]);
    over(($vysledok($o)['isError'] ?? true) === false
        && (recepty_zo_stranky($p->get('index.php')->telo)[$mcpId]['lastMadeDate'] ?? '') === $vcera, 'Asistent zapíše varenie', $o->popis());
    $o = $volaj('zapis_varenie', ['id' => $mcpId, 'datum' => '2099-01-01']);
    over(($vysledok($o)['isError'] ?? false) === true, 'Varenie v budúcnosti asistent nezapíše', $o->popis());
    $o = $volaj('pridaj_do_nakupu', ['id' => $mcpId, 'porcie' => 4]);
    over(($vysledok($o)['structuredContent']['pridane'] ?? 0) === 4, 'Asistent pridá suroviny do nákupu', $o->popis());
    $o = $volaj('zobraz_nakup', []);
    over(str_contains((string) ($vysledok($o)['content'][0]['text'] ?? ''), '150 g Múka hladká'), 'Asistent vidí nákup s prepočítaným množstvom', $o->popis());
    $o = $mcp($kluc, 'tools/call', ['name' => 'zmaz_vsetko', 'arguments' => []]);
    over(($o->json()['error']['code'] ?? 0) === -32602, 'Neznámy nástroj sa odmietne', $o->popis());
    akcia($p, $csrf, 'shopping_clear', ['mode' => 'all']);

    $o = $mcp(str_repeat('0', 48), 'tools/list');
    over($o->kod === 403 && isset($o->json()['error']), 'Neplatná adresa sa odmietne (403)', $o->popis());
    $idPripojenia = 0;
    foreach (akcia($p, $csrf, 'ai_pripojenia_get')->json()['pripojenia'] ?? [] as $pr) {
        if ($pr['nazov'] === "Claude test $znacka") {
            $idPripojenia = (int) $pr['id'];
            over(!empty($pr['pouzite']), 'Pri pripojení sa ukazuje, kedy bolo naposledy použité');
        }
    }
    $o = akcia($p, $csrf, 'ai_pripojenie_zrus', ['pripojenie_id' => $idPripojenia]);
    over(uspech($o) && !in_array($idPripojenia, array_column($o->json()['pripojenia'] ?? [], 'id'), true), 'Zrušenie pripojenia', $o->popis());
    $o = $mcp($kluc, 'tools/list');
    over($o->kod === 403, 'Zrušená adresa už nefunguje', $o->popis());
    foreach (akcia($p, $csrf, 'ai_pripojenia_get')->json()['pripojenia'] ?? [] as $pr) {
        akcia($p, $csrf, 'ai_pripojenie_zrus', ['pripojenie_id' => $pr['id']]);
    }
    akcia($p, $csrf, 'delete_recipe', ['recipe_id' => $mcpId]);
} else {
    info('Databáza nemá tabuľku ai_pripojenia (migrácia 2.6), overí sa, že bez nej nič nespadne');
    over(!str_contains($stranka, 'onclick="openAiAsistent()"'), 'Bez migrácie 2.6 sa pripojenie k Claude v nastaveniach neukáže');
    $o = akcia($p, $csrf, 'ai_pripojenie_vytvor', ['nazov' => 'Claude']);
    over(nespadla($o) && ($o->json()['success'] ?? null) === false, 'Vytvorenie adresy bez migrácie sa odmietne a nespadne', $o->popis());
    $o = $mcp(str_repeat('0', 48), 'tools/list');
    over($o->kod === 503 && isset($o->json()['error']), 'mcp.php bez migrácie odpovie 503 a JSON', $o->popis());
}

/* ---------------------------------------------------------------------- */
/* 6. Mazanie receptu                                                      */
/* ---------------------------------------------------------------------- */
echo "\nMazanie\n";
$o = akcia($p, $csrf, 'add_recipe', ['name' => "Recept na zmazanie $znacka", 'category' => 'Dezerty']);
$zmazatId = (int) ($o->json()['id'] ?? 0);
over(uspech($o) && $zmazatId > 0, 'Pridanie receptu bez fotky', $o->popis());
$o = akcia($p, $csrf, 'delete_recipe', ['recipe_id' => $zmazatId]);
over(uspech($o), 'Admin zmaže recept', $o->popis());
$o = $p->get('index.php');
over(!isset(recepty_zo_stranky($o->telo)[$zmazatId]), 'Zmazaný recept už nie je v zozname');

/* ---------------------------------------------------------------------- */
/* 7. Kategórie receptov                                                   */
/* ---------------------------------------------------------------------- */
echo "\nKategórie receptov\n";
$o = $p->get('index.php');
over(str_contains($o->telo, '<option value="Fit">Nízko kal. / Fit</option>'), 'Filter aj formulár ponúkajú kategórie s názvom (Fit → Nízko kal. / Fit)');
$fitId = 0;
$o = akcia($p, $csrf, 'add_recipe', ['name' => "Fit recept $znacka", 'category' => 'Fit']);
$fitId = (int) ($o->json()['id'] ?? 0);
$o = $p->get('index.php');
$fit = recepty_zo_stranky($o->telo)[$fitId] ?? null;
over(($fit['category'] ?? '') === 'Fit' && ($fit['categoryLabel'] ?? '') === 'Nízko kal. / Fit',
    'Recept má uložený kľúč kategórie a ukazuje jej názov', json_encode($fit, JSON_UNESCAPED_UNICODE));

// Od verzie 2.1 majú kategórie vlastnú stránku Správy (predtým boli v admin/index.php).
$o = $p->get('admin/index.php');
over(str_contains($o->telo, 'href="kategorie.php"'), 'Zo Správy vedie odkaz na Kategórie receptov');
$o = $p->get('admin/kategorie.php');
over($o->kod === 200 && bez_chyby($o) && str_contains($o->telo, 'Kategórie receptov'), 'Správa kategórií receptov sa otvorí', $o->popis());
$adminCsrf = (string) csrf_zo_stranky($o->telo);
$sprava = fn(array $polia) => $p->post('admin/kategorie.php', $polia + ['csrf_token' => $adminCsrf]);

// Tabuľka kategórií je od verzie 1.5 — staršia databáza bez migrácie ju nemá.
if ($plny || str_contains($o->telo, 'value="kategoria_pridat"')) {
    $kategorie = kategorie_zo_spravy($o->telo);
    over(count($kategorie) >= 7 && in_array('Nízko kal. / Fit', $kategorie, true), 'Správa ukazuje kategórie z databázy', json_encode($kategorie, JSON_UNESCAPED_UNICODE));

    $nova = "Šaláty $znacka";
    $o = $sprava(['action' => 'kategoria_pridat', 'nazov' => "  $nova "]);
    $kategorie = kategorie_zo_spravy($o->telo);
    $novaId = (int) array_search($nova, $kategorie, true);
    over($novaId > 0 && array_key_last($kategorie) === $novaId, 'Nová kategória sa pridá na koniec zoznamu', hlaska_spravy($o->telo));

    $o = $sprava(['action' => 'kategoria_pridat', 'nazov' => mb_strtoupper($nova)]);
    over(str_contains(hlaska_spravy($o->telo), 'už existuje'), 'Rovnaká kategória sa druhýkrát nepridá (ani veľkými písmenami)', hlaska_spravy($o->telo));
    $o = $sprava(['action' => 'kategoria_pridat', 'nazov' => 'nizko kal. / fit']);
    over(str_contains(hlaska_spravy($o->telo), 'už existuje'), 'Kategória s názvom existujúcej (bez diakritiky) sa nepridá', hlaska_spravy($o->telo));

    $o = $sprava(['action' => 'kategoria_hore', 'kategoria_id' => $novaId]);
    $poradie = array_keys(kategorie_zo_spravy($o->telo));
    over(array_search($novaId, $poradie, true) === count($poradie) - 2, 'Posun vyššie zmení poradie', json_encode($poradie));

    $o = akcia($p, $csrf, 'add_recipe', ['name' => "Šalát $znacka", 'category' => $nova]);
    $salatId = (int) ($o->json()['id'] ?? 0);
    $o = $sprava(['action' => 'kategoria_premenovat', 'kategoria_id' => $novaId, 'nazov' => "Šaláty a prílohy $znacka"]);
    over((kategorie_zo_spravy($o->telo)[$novaId] ?? '') === "Šaláty a prílohy $znacka", 'Kategória sa dá premenovať', hlaska_spravy($o->telo));
    $o = $p->get('index.php');
    $salat = recepty_zo_stranky($o->telo)[$salatId] ?? null;
    over(($salat['category'] ?? '') === $nova && ($salat['categoryLabel'] ?? '') === "Šaláty a prílohy $znacka",
        'Recept po premenovaní ukazuje nový názov, kľúč ostal', json_encode($salat, JSON_UNESCAPED_UNICODE));
    over(str_contains($o->telo, '<option value="' . htmlspecialchars($nova) . '">Šaláty a prílohy'), 'Filter ukazuje nový názov');

    $o = $sprava(['action' => 'kategoria_zmazat', 'kategoria_id' => $novaId]);
    over(str_contains(hlaska_spravy($o->telo), 'používa 1 recept') && isset(kategorie_zo_spravy($o->telo)[$novaId]),
        'Používaná kategória sa zmazať nedá', hlaska_spravy($o->telo));
    akcia($p, $csrf, 'delete_recipe', ['recipe_id' => $salatId]);
    $o = $sprava(['action' => 'kategoria_zmazat', 'kategoria_id' => $novaId]);
    over(!isset(kategorie_zo_spravy($o->telo)[$novaId]), 'Nepoužívaná kategória sa zmaže', hlaska_spravy($o->telo));

    $o = $sprava(['action' => 'kategoria_pridat', 'nazov' => '   ']);
    over(str_contains(hlaska_spravy($o->telo), 'povinný'), 'Prázdny názov sa odmietne', hlaska_spravy($o->telo));
    $o = $sprava(['action' => 'kategoria_pridat', 'nazov' => 'Nová', 'csrf_token' => 'zly-token']);
    over(str_contains(hlaska_spravy($o->telo), 'Vypršala platnosť formulára'), 'Úprava kategórií s neplatným tokenom sa odmietne', hlaska_spravy($o->telo));
} else {
    over(str_contains($o->telo, 'sql/migracia-1.5.sql'), 'Bez migrácie správa vysvetlí, že treba naimportovať migráciu');
    $o = $sprava(['action' => 'kategoria_pridat', 'nazov' => "Šaláty $znacka"]);
    over($o->kod === 200 && bez_chyby($o) && str_contains(hlaska_spravy($o->telo), 'migracia-1.5.sql'),
        'Bez migrácie sa kategória nepridá, appka nespadne', hlaska_spravy($o->telo));
}
akcia($p, $csrf, 'delete_recipe', ['recipe_id' => $fitId]);

/* ---------------------------------------------------------------------- */
/* 8. Suroviny (správa, nepotrebuje migráciu)                              */
/* ---------------------------------------------------------------------- */
echo "\nSuroviny\n";
// Recept s preklepom v novej surovine a s ďalšou novou surovinou, ktorú potom nič nepoužíva.
$preklep = "Paradjky $znacka";
$o = akcia($p, $csrf, 'add_recipe', ['name' => "Šalát s preklepom $znacka", 'servings' => '2', 'suroviny_json' => json_encode([
    ['nazov' => $preklep, 'mnozstvo' => '300', 'jednotka' => 'g', 'poznamka' => ''],
    ['nazov' => 'Paradajky', 'mnozstvo' => '1', 'jednotka' => 'ks', 'poznamka' => ''],
], JSON_UNESCAPED_UNICODE)]);
$preklepRecept = (int) ($o->json()['id'] ?? 0);
over(uspech($o) && $preklepRecept > 0, 'Recept s novou surovinou (preklep) sa uloží', $o->popis());
$o = akcia($p, $csrf, 'add_recipe', ['name' => "Na zmazanie $znacka", 'suroviny_json' => json_encode([
    ['nazov' => "Nepoužitá $znacka", 'mnozstvo' => '', 'jednotka' => '', 'poznamka' => ''],
], JSON_UNESCAPED_UNICODE)]);
akcia($p, $csrf, 'delete_recipe', ['recipe_id' => (int) ($o->json()['id'] ?? 0)]);
akcia($p, $csrf, 'shopping_clear', ['mode' => 'all']);
akcia($p, $csrf, 'shopping_add_recipe', ['recipe_id' => $preklepRecept]);

$o = $p->get('admin/suroviny.php');
$sCsrf = (string) csrf_zo_stranky($o->telo);
$bez = suroviny_zo_spravy($o->telo);
$preklepId = (int) array_search($preklep, $bez, true);
$nepouzitaId = (int) array_search("Nepoužitá $znacka", $bez, true);
over($o->kod === 200 && bez_chyby($o) && $preklepId > 0 && $nepouzitaId > 0, 'Správa surovín ukáže nové suroviny bez kategórie', $o->popis());
$o = $p->get('admin/index.php');
over(str_contains($o->telo, 'href="suroviny.php"'), 'Zo Správy vedie odkaz na Suroviny');
$suroviny = fn(array $polia) => $p->post('admin/suroviny.php', $polia + ['csrf_token' => $sCsrf, 'hladaj' => '']);

$o = $p->get('admin/suroviny.php?hladaj=' . rawurlencode('paradajky cherry'));
over(in_array('Paradajky cherry', suroviny_zo_spravy($o->telo), true), 'Hľadanie nájde surovinu aj bez diakritiky');

$o = $suroviny(['action' => 'surovina_ulozit', 'surovina_id' => $nepouzitaId, 'nazov' => "Nepoužitá $znacka", 'kategoria' => 'Zelenina']);
over(!isset(suroviny_zo_spravy($o->telo)[$nepouzitaId]), 'Zaradená surovina zmizne zo zoznamu bez kategórie', hlaska_spravy($o->telo));
$o = $suroviny(['action' => 'surovina_ulozit', 'surovina_id' => $nepouzitaId, 'nazov' => 'X', 'kategoria' => 'Neexistujúca']);
over(str_contains(hlaska_spravy($o->telo), 'Neznáma kategória'), 'Neznáma kategória sa odmietne', hlaska_spravy($o->telo));

$o = $suroviny(['action' => 'surovina_ulozit', 'surovina_id' => $preklepId, 'nazov' => "Paradajky opravené $znacka", 'kategoria' => '']);
over(isset(suroviny_zo_spravy($o->telo)[$preklepId]) && suroviny_zo_spravy($o->telo)[$preklepId] === "Paradajky opravené $znacka", 'Premenovanie suroviny', hlaska_spravy($o->telo));
$o = $p->get('index.php');
$nazvy = array_column(recepty_zo_stranky($o->telo)[$preklepRecept]['suroviny'] ?? [], 'nazov');
over(in_array("Paradajky opravené $znacka", $nazvy, true), 'Recept ukazuje nový názov suroviny', json_encode($nazvy, JSON_UNESCAPED_UNICODE));

$o = $suroviny(['action' => 'surovina_ulozit', 'surovina_id' => $preklepId, 'nazov' => 'paradajky', 'kategoria' => '']);
over(str_contains($o->telo, 'už existuje') && str_contains($o->telo, 'name="action" value="surovina_zlucit"'),
    'Premenovanie na existujúci názov ponúkne zlúčenie');
preg_match('/name="z_id" value="' . $preklepId . '">\s*<input type="hidden" name="do_id" value="(\d+)"/', $o->telo, $m);
$paradajkyId = (int) ($m[1] ?? 0);
over($paradajkyId > 0, 'Ponuka zlúčenia mieri na existujúcu surovinu Paradajky');

$o = $suroviny(['action' => 'surovina_zmazat', 'surovina_id' => $preklepId]);
over(str_contains(hlaska_spravy($o->telo), 'používa 1 recept'), 'Používanú surovinu zmazať nejde', hlaska_spravy($o->telo));

$o = $suroviny(['action' => 'surovina_zlucit', 'z_id' => $preklepId, 'do_id' => $paradajkyId]);
$hlaska = hlaska_spravy($o->telo);
over(str_contains($hlaska, 'zlúčená do „Paradajky“') && str_contains($hlaska, 'je dvakrát'),
    'Zlúčenie do Paradajky upozorní na recept, ktorý mal obe suroviny', $hlaska);
$o = $p->get('index.php');
$nazvy = array_column(recepty_zo_stranky($o->telo)[$preklepRecept]['suroviny'] ?? [], 'nazov');
over($nazvy === ['Paradajky', 'Paradajky'], 'Recept má po zlúčení len Paradajky', json_encode($nazvy, JSON_UNESCAPED_UNICODE));
$o = $p->get('admin/suroviny.php?hladaj=' . rawurlencode("opravené $znacka"));
over(suroviny_zo_spravy($o->telo) === [], 'Zlúčená surovina zmizla z číselníka');
$o = akcia($p, $csrf, 'shopping_get');
$polozky = array_values(array_filter($o->json()['items'] ?? [], fn($i) => str_starts_with((string) $i['nazov'], 'Paradajky')));
if ($plny) {
    over(count($polozky) === 2 && $polozky[0]['kategoria'] === 'Zelenina',
        'V nákupe sú obe položky pod Paradajkami v kategórii Zelenina (g a ks ostávajú zvlášť)', json_encode($polozky, JSON_UNESCAPED_UNICODE));
}
$o = akcia($p, $csrf, 'get_logs', ['recipe_id' => $preklepRecept]);
$poznamky = array_column($o->json()['logs'] ?? [], 'note');
over((bool) array_filter($poznamky, fn($n) => str_contains((string) $n, 'zlúčená do „Paradajky“')), 'Zlúčenie je v histórii receptu');

$o = $suroviny(['action' => 'surovina_zmazat', 'surovina_id' => $nepouzitaId]);
over(str_contains(hlaska_spravy($o->telo), 'zmazaná'), 'Nepoužívaná surovina sa zmaže', hlaska_spravy($o->telo));
$o = $suroviny(['action' => 'surovina_zlucit', 'z_id' => $paradajkyId, 'do_id' => $paradajkyId]);
over(str_contains(hlaska_spravy($o->telo), 'samú so sebou'), 'Surovinu nejde zlúčiť samú so sebou', hlaska_spravy($o->telo));
$o = $p->post('admin/suroviny.php', ['action' => 'surovina_zmazat', 'surovina_id' => $paradajkyId, 'csrf_token' => 'zly']);
over(str_contains(hlaska_spravy($o->telo), 'Vypršala platnosť formulára'), 'Úprava surovín s neplatným tokenom sa odmietne');
akcia($p, $csrf, 'shopping_clear', ['mode' => 'all']);
akcia($p, $csrf, 'delete_recipe', ['recipe_id' => $preklepRecept]);
$o = (new Prehliadac($adresa))->get('admin/suroviny.php');
over(in_array($o->kod, [301, 302, 303], true), 'Správa surovín bez prihlásenia presmeruje na prihlásenie', $o->popis());

/* ---------------------------------------------------------------------- */
/* 9. Ďalšie stránky                                                       */
/* ---------------------------------------------------------------------- */
echo "\nĎalšie stránky\n";
$o = $p->get("index.php?recept=$receptId");
over($o->kod === 200 && bez_chyby($o), 'Odkaz z upozornenia (index.php?recept=…) sa otvorí', $o->popis());
// Upozornenie na objednané jedlo (2.5): ?objednavka=ID sa prenesie aj cez prihlásenie.
$o = $p->get('index.php?objednavka=7');
over($o->kod === 200 && bez_chyby($o) && str_contains($o->telo, 'window.APP_OPEN_OBJEDNAVKA = 7;'),
    'Odkaz z upozornenia na objednávku (index.php?objednavka=…) sa otvorí', $o->popis());
$o = (new Prehliadac($adresa))->get('index.php?objednavka=7');
over(in_array($o->kod, [301, 302, 303], true) && str_contains($o->presmerovanie(), 'login.php?objednavka=7'),
    'Bez prihlásenia sa číslo objednávky prenesie na prihlásenie', $o->presmerovanie());
$o = (new Prehliadac($adresa))->get('login.php?objednavka=7');
over(str_contains($o->telo, 'name="objednavka" value="7"'), 'Prihlásenie si číslo objednávky zapamätá do formulára');

$o = $p->get('admin/index.php');
over($o->kod === 200 && bez_chyby($o) && str_contains($o->telo, 'Používatelia'), 'Správa používateľov sa otvorí', $o->popis());
over(str_contains($o->telo, 'index.php?otvor=objednavky') === $objednavkyVDb, $objednavkyVDb
    ? 'Bočná ponuka Správy vedie aj na Objednané jedlo' : 'Bez migrácie Správa Objednané jedlo neponúka');
$o = $p->get('admin/prevod-surovin.php');
over($o->kod === 200 && bez_chyby($o) && str_contains($o->telo, 'Prevod surovín'), 'Prevod surovín sa otvorí', $o->popis());
$o = (new Prehliadac($adresa))->get('admin/kategorie.php');
over(in_array($o->kod, [301, 302, 303], true), 'Správa kategórií bez prihlásenia presmeruje na prihlásenie', $o->popis());

$o = $p->postJson('webauthn/register_options.php', []);
over($o->kod === 200 && $o->json() !== null && !isset($o->json()['error']), 'Biometria: príprava registrácie (knižnice vo vendor/ fungujú)', $o->popis());

$o = (new Prehliadac($adresa))->postJson('webauthn/login_options.php', []);
over($o->kod === 200 && isset($o->json()['challenge']), 'Biometria: príprava prihlásenia', $o->popis());

$o = $p->get('cron.php?key=zly-kluc');
over($o->kod === 403, 'cron.php s nesprávnym kľúčom odmietne', $o->popis());
$o = $p->get('cron.php?key=' . urlencode($cronKey));
over($o->kod === 200 && bez_chyby($o), 'cron.php so správnym kľúčom prebehne', $o->popis());

/* ---------------------------------------------------------------------- */
/* 10. Zostať prihlásený a odhlásenie                                      */
/* ---------------------------------------------------------------------- */
echo "\nZostať prihlásený a odhlásenie\n";
$zapamatane = new Prehliadac($adresa);
$zapamatane->cookies = array_intersect_key($p->cookies, ['recepty_remember' => true]);
over(isset($zapamatane->cookies['recepty_remember']), 'Prihlásenie so „Zostať prihlásený" uložilo cookie zariadenia');
$o = $zapamatane->get('index.php');
over($o->kod === 200 && bez_chyby($o), 'Zapamätané zariadenie sa dostane do appky bez hesla', $o->popis());

$o = $p->get('logout.php');
over(in_array($o->kod, [301, 302, 303], true) && str_contains($o->presmerovanie(), 'login.php'), 'Odhlásenie presmeruje na prihlásenie', $o->popis());
$o = $p->get('index.php');
over(in_array($o->kod, [301, 302, 303], true), 'Po odhlásení sa do appky nedá dostať', $o->popis());

echo "\n" . ($chyby === 0 ? "Všetky kontroly prešli.\n" : "Počet zlyhaných kontrol: $chyby\n");
exit($chyby === 0 ? 0 : 1);
