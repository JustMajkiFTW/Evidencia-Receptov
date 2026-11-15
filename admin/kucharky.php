<?php
/**
 * Správa — Kuchárky v PDF (len admin, verzia 2.8).
 * Nahratie PDF do priečinka kucharky/ (alebo cez FTP), spracovanie textu po
 * jednom súbore (aby to hosting stihol), zoznam kníh, zmazanie a skúšobné hľadanie.
 * Logika je v includes/kucharky.php, hľadá v nich hlasový asistent aj Claude.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/kucharky.php';
require_once __DIR__ . '/../includes/sprava.php';
require_admin();

$dostupne = kucharky_available($pdo);
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $ajax = $action === 'kucharka_spracuj';
    $vysledok = null;
    if (!$dostupne) {
        $vysledok = ['success' => false, 'error' => 'Najprv treba v phpMyAdmin naimportovať sql/migracia-2.8.sql.'];
    } elseif (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $vysledok = ['success' => false, 'error' => 'Vypršala platnosť formulára, obnov stránku a skús to znova.'];
    } elseif ($action === 'kucharka_spracuj') {
        session_write_close(); // čítanie veľkej knihy trvá — ostatné stránky nemajú čakať
        $vysledok = kucharky_spracuj($pdo, (string) ($_POST['subor'] ?? ''));
    } elseif ($action === 'kucharka_zmaz') {
        $vysledok = kucharky_zmaz($pdo, (string) ($_POST['subor'] ?? ''));
        if ($vysledok['success']) {
            $message = 'Kuchárka je zmazaná.';
        }
    } elseif ($action === 'kucharky_nahraj') {
        $subory = $_FILES['pdf'] ?? null;
        $nahrate = [];
        $chyby = [];
        if (is_array($subory) && is_array($subory['name'] ?? null)) {
            foreach (array_keys($subory['name']) as $i) {
                $r = kucharky_nahraj(['name' => $subory['name'][$i], 'tmp_name' => $subory['tmp_name'][$i], 'error' => $subory['error'][$i]]);
                $r['success'] ? $nahrate[] = $r['subor'] : $chyby[] = $r['error'];
            }
        } elseif (empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            $chyby[] = 'Súbory sú väčšie, ako server dovolí nahrať naraz (' . ini_get('post_max_size') . '). Nahraj ich po jednom alebo cez FTP.';
        }
        if ($nahrate) {
            $message = 'Nahrané: ' . implode(', ', $nahrate) . '. Spracúvam ich…';
        }
        if ($chyby) {
            $error = implode(' ', $chyby);
        } elseif (!$nahrate) {
            $error = 'Vyber aspoň jedno PDF.';
        }
    }
    if ($ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($vysledok ?? ['success' => false, 'error' => 'Neznáma akcia.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($vysledok !== null && !$vysledok['success']) {
        $error = $vysledok['error'];
    }
}

if ($error === null && isset($_GET['chyby'])) {
    $error = 'Niektoré kuchárky sa nepodarilo spracovať, dôvod je pri nich v zozname.';
} elseif ($message === null && isset($_GET['spracovane'])) {
    $message = 'Hotovo, kuchárky sú spracované.';
}
$zoznam = $dostupne ? kucharky_zoznam($pdo) : [];
$naSpracovanie = array_values(array_column(array_filter($zoznam, static fn($k) => in_array($k['stav'], ['nove', 'zmenene'], true)), 'subor'));
$hladaj = trim((string) ($_GET['hladaj'] ?? ''));
$najdene = ($dostupne && $hladaj !== '') ? kucharky_hladaj($pdo, $hladaj, 5) : [];
$priecinok = kucharky_priecinok();
$csrfToken = csrf_token();
$stavy = [
    'ok'        => ['text-bg-success', 'Spracovaná'],
    'nove'      => ['text-bg-warning', 'Nová, treba spracovať'],
    'zmenene'   => ['text-bg-warning', 'Zmenená, treba spracovať'],
    'bez_textu' => ['text-bg-secondary', 'Bez textu (sken)'],
    'chyba'     => ['text-bg-danger', 'Chyba'],
    'zmazane'   => ['text-bg-secondary', 'Súbor chýba'],
];
$strany = static fn(int $n): string => $n . ($n === 1 ? ' strana' : ($n >= 2 && $n <= 4 ? ' strany' : ' strán'));
$velkost = static fn(int $b): string => $b >= 1048576 ? str_replace('.', ',', (string) round($b / 1048576, 1)) . ' MB' : max(1, (int) round($b / 1024)) . ' kB';

sprava_zaciatok($pdo, 'kucharky', 'Kuchárky v PDF');
?>
    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if (!$dostupne): ?>
        <div class="sprava-upozornenie">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
            <div>Na kuchárky treba v phpMyAdmin naimportovať <code>sql/migracia-2.8.sql</code>.</div>
        </div>
    <?php else: ?>
        <p class="sprava-popis">
            Kuchárky v PDF (s textom, nie skeny) appka prečíta a hlasový asistent v nich potom hľadá, čo uvariť, aj s knihou a stranou.
            PDF sa dajú nahrať tu, alebo cez FTP do priečinka <code><?= htmlspecialchars(basename($priecinok)) ?>/</code>
            — nové sa spracujú samy (cez cron), alebo tlačidlom nižšie. Z internetu sa k nim nikto nedostane.
        </p>
        <form method="post" enctype="multipart/form-data" class="sprava-karta sprava-pridat" aria-label="Nahrať kuchárky">
            <input type="hidden" name="action" value="kucharky_nahraj">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <label class="form-label" for="kuchardkyPdf">Nahrať PDF (najviac <?= htmlspecialchars((string) ini_get('upload_max_filesize')) ?> na súbor)</label>
            <div class="sprava-riadok-pola">
                <input type="file" name="pdf[]" id="kuchardkyPdf" class="form-control" accept="application/pdf,.pdf" multiple required>
                <button type="submit" class="btn sprava-tlacidlo sprava-hlavne"><i class="bi bi-upload" aria-hidden="true"></i>Nahrať</button>
            </div>
        </form>

        <h2 class="sprava-nadpis">Kuchárky <span class="sprava-pocet"><?= count($zoznam) ?></span></h2>
        <?php if ($naSpracovanie): ?>
            <div class="sprava-karta kucharky-spracovanie">
                <div id="kucharkyStav"><?= count($naSpracovanie) === 1 ? '1 kuchárku' : count($naSpracovanie) . ' kuchárky' ?> treba spracovať.</div>
                <button type="button" class="btn sprava-tlacidlo sprava-hlavne" id="kucharkySpracuj"
                        data-subory="<?= htmlspecialchars(json_encode($naSpracovanie, JSON_UNESCAPED_UNICODE)) ?>"
                        data-hned="<?= $message ? '1' : '0' ?>">
                    <i class="bi bi-gear" aria-hidden="true"></i>Spracovať
                </button>
            </div>
        <?php endif; ?>
        <?php if (!$zoznam): ?>
            <p class="sprava-popis">Zatiaľ tu nie je žiadna kuchárka.</p>
        <?php endif; ?>
        <ul class="sprava-zoznam kucharky-zoznam">
            <?php foreach ($zoznam as $k): [$farba, $popis] = $stavy[$k['stav']] ?? ['text-bg-secondary', $k['stav']]; ?>
                <li class="sprava-polozka kucharka-riadok">
                    <div class="kucharka-info">
                        <strong><?= htmlspecialchars($k['nazov']) ?></strong>
                        <small><?= htmlspecialchars($k['subor']) ?> · <?= $velkost($k['velkost']) ?>
                            <?= $k['pocet_stran'] ? ' · ' . $strany((int) $k['pocet_stran']) : '' ?></small>
                        <span class="badge <?= $farba ?>" data-stav="<?= htmlspecialchars($k['subor']) ?>"><?= htmlspecialchars($popis) ?></span>
                        <?php if ($k['chyba']): ?><small class="text-danger"><?= htmlspecialchars($k['chyba']) ?></small><?php endif; ?>
                        <?php if ($k['stav'] === 'bez_textu'): ?><small>Vyzerá ako sken bez textu — z takého PDF text prečítať nevieme.</small><?php endif; ?>
                    </div>
                    <div class="kucharka-akcie">
                        <?php if ($k['stav'] !== 'zmazane'): ?>
                            <button type="button" class="btn sprava-tlacidlo sprava-vedlajsie kucharka-znova" data-subor="<?= htmlspecialchars($k['subor']) ?>"
                                    title="Prečítať znova" aria-label="Prečítať znova: <?= htmlspecialchars($k['nazov']) ?>">
                                <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
                            </button>
                        <?php endif; ?>
                        <form method="post" onsubmit="return confirm('Naozaj zmazať kuchárku <?= htmlspecialchars(addslashes($k['nazov']), ENT_QUOTES) ?> aj so súborom?');">
                            <input type="hidden" name="action" value="kucharka_zmaz">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="subor" value="<?= htmlspecialchars($k['subor']) ?>">
                            <button type="submit" class="btn sprava-tlacidlo sprava-zmazat sprava-ikona" aria-label="Zmazať kuchárku <?= htmlspecialchars($k['nazov']) ?>">
                                <i class="bi bi-trash" aria-hidden="true"></i>
                            </button>
                        </form>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>

        <h2 class="sprava-nadpis">Vyskúšať hľadanie</h2>
        <form method="get" class="sprava-karta" aria-label="Hľadať v kuchárkach">
            <div class="sprava-riadok-pola">
                <input type="search" name="hladaj" class="form-control" value="<?= htmlspecialchars($hladaj) ?>" placeholder="napr. kuracie mäso ryža">
                <button type="submit" class="btn sprava-tlacidlo sprava-vedlajsie"><i class="bi bi-search" aria-hidden="true"></i>Hľadať</button>
            </div>
        </form>
        <?php if ($hladaj !== ''): ?>
            <?php if (!$najdene): ?><p class="sprava-popis">Nič sa nenašlo.</p><?php endif; ?>
            <?php foreach ($najdene as $n): ?>
                <div class="sprava-karta kucharka-najdene">
                    <strong><?= htmlspecialchars($n['kniha']) ?>, strana <?= (int) $n['strana'] ?></strong>
                    <pre><?= htmlspecialchars(mb_substr($n['text'], 0, 600, 'UTF-8')) ?></pre>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>
<?php sprava_koniec($pdo); ?>
<script>
// Spracovanie po jednom súbore (každý v samostatnej požiadavke, aby to hosting stihol).
(function () {
    const csrf = <?= json_encode($csrfToken) ?>;
    const stav = document.getElementById('kucharkyStav');
    async function spracuj(subory, tlacidlo) {
        if (tlacidlo) tlacidlo.disabled = true;
        let chyby = 0;
        for (let i = 0; i < subory.length; i++) {
            if (stav) stav.textContent = 'Spracúvam ' + (i + 1) + ' z ' + subory.length + ': ' + subory[i] + '…';
            const odznak = document.querySelector('[data-stav="' + CSS.escape(subory[i]) + '"]');
            if (odznak) { odznak.className = 'badge text-bg-info'; odznak.textContent = 'Spracúvam…'; }
            const telo = new FormData();
            telo.append('action', 'kucharka_spracuj');
            telo.append('csrf_token', csrf);
            telo.append('subor', subory[i]);
            try {
                const data = await (await fetch('kucharky.php', { method: 'POST', body: telo })).json();
                if (!data.success) chyby++;
            } catch (err) {
                chyby++;
            }
        }
        location.href = 'kucharky.php' + (chyby ? '?chyby=' + chyby : '?spracovane=1');
    }
    const tlacidlo = document.getElementById('kucharkySpracuj');
    if (tlacidlo) {
        const subory = JSON.parse(tlacidlo.dataset.subory || '[]');
        tlacidlo.addEventListener('click', () => spracuj(subory, tlacidlo));
        if (tlacidlo.dataset.hned === '1') spracuj(subory, tlacidlo); // hneď po nahratí
    }
    document.querySelectorAll('.kucharka-znova').forEach((btn) => btn.addEventListener('click', () => spracuj([btn.dataset.subor], btn)));
})();
</script>
</body>
