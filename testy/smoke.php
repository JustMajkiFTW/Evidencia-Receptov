<?php
/**
 * smoke.php — prejde appku tak, ako by ju používal človek: prihlási sa, pridá
 * recept s fotkou, upraví ho, zapíše varenie, ohodnotí, použije nákupný zoznam,
 * zmaže recept a odhlási sa. Každý krok overí podľa odpovede servera.
 *
 * Spúšťa ho testy/spusti-testy.sh nad bežiacou appkou (vstavaný server PHP):
 *   php testy/smoke.php ADRESA MENO HESLO REZIM CRON_KEY
 *
 * REZIM:
 *   plny          databáza je aktuálna (nová inštalácia alebo po migrácii), overí sa všetko
 *   bez-migracie  databáza je z predchádzajúcej verzie; základné veci (recepty, varenie,
 *                 hodnotenie, história) musia fungovať, nové funkcie len nesmú spadnúť
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
$csrf = csrf_zo_stranky($o->telo);
over($csrf !== null, 'Hlavná stránka obsahuje bezpečnostný token (CSRF)');
$csrf = (string) $csrf;

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
/* 7. Ďalšie stránky                                                       */
/* ---------------------------------------------------------------------- */
echo "\nĎalšie stránky\n";
$o = $p->get("index.php?recept=$receptId");
over($o->kod === 200 && bez_chyby($o), 'Odkaz z upozornenia (index.php?recept=…) sa otvorí', $o->popis());

$o = $p->get('admin/index.php');
over($o->kod === 200 && bez_chyby($o) && str_contains($o->telo, 'Používatelia'), 'Správa používateľov sa otvorí', $o->popis());

$o = $p->postJson('webauthn/register_options.php', []);
over($o->kod === 200 && $o->json() !== null && !isset($o->json()['error']), 'Biometria: príprava registrácie (knižnice vo vendor/ fungujú)', $o->popis());

$o = (new Prehliadac($adresa))->postJson('webauthn/login_options.php', []);
over($o->kod === 200 && isset($o->json()['challenge']), 'Biometria: príprava prihlásenia', $o->popis());

$o = $p->get('cron.php?key=zly-kluc');
over($o->kod === 403, 'cron.php s nesprávnym kľúčom odmietne', $o->popis());
$o = $p->get('cron.php?key=' . urlencode($cronKey));
over($o->kod === 200 && bez_chyby($o), 'cron.php so správnym kľúčom prebehne', $o->popis());

/* ---------------------------------------------------------------------- */
/* 8. Zostať prihlásený a odhlásenie                                       */
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
