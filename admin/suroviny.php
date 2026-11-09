<?php
/**
 * Správa surovín (len admin): zaradenie do kategórie, oprava názvu,
 * zlúčenie dvoch surovín (preklep, iný názov tej istej veci) a zmazanie
 * nepoužívanej. Predvolene ukazuje suroviny bez kategórie — to sú tie,
 * ktoré pribudli pri písaní receptov. Databázu netreba meniť.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/sprava.php';
require_admin();

$userId = (int) get_current_user_id($pdo);
$hladaj = trim((string) ($_POST['hladaj'] ?? $_GET['hladaj'] ?? ''));
$message = null;
$error = null;
$konflikt = null; // ponuka zlúčenia, keď nový názov už má iná surovina

$dostupne = suroviny_available($pdo);

if ($dostupne && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Vypršala platnosť formulára, skús to znova.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'surovina_ulozit') {
            $id = (int) ($_POST['surovina_id'] ?? 0);
            $vysledok = update_surovina($pdo, $id, (string) ($_POST['nazov'] ?? ''), (string) ($_POST['kategoria'] ?? ''), $userId);
            if ($vysledok['success']) {
                $message = "Surovina „{$vysledok['nazov']}“ uložená.";
            } elseif (!empty($vysledok['konflikt'])) {
                $povodna = get_surovina($pdo, $id);
                $konflikt = ['z' => $povodna, 'do' => $vysledok['konflikt'],
                    'receptov' => $povodna ? count(surovina_recipes($pdo, $id)) : 0];
            } else {
                $error = $vysledok['error'];
            }
        } elseif ($action === 'surovina_zlucit') {
            $vysledok = merge_suroviny($pdo, (int) ($_POST['z_id'] ?? 0), (int) ($_POST['do_id'] ?? 0), $userId);
            if ($vysledok['success']) {
                $message = "Surovina „{$vysledok['z']}“ je zlúčená do „{$vysledok['do']}“ (" . sprava_pocet_receptov($vysledok['receptov']) . ').';
                if ($vysledok['obe']) {
                    $message .= ' Pozor, obe suroviny mali tieto recepty, „' . $vysledok['do'] . '“ v nich teraz je dvakrát: '
                        . implode(', ', $vysledok['obe']) . '. Uprav ich a nechaj len jeden riadok.';
                }
            } else {
                $error = $vysledok['error'];
            }
        } elseif ($action === 'surovina_zmazat') {
            $vysledok = delete_surovina($pdo, (int) ($_POST['surovina_id'] ?? 0));
            if ($vysledok['success']) {
                $message = "Surovina „{$vysledok['nazov']}“ zmazaná.";
            } else {
                $error = $vysledok['error'];
            }
        }
    }
}

$suroviny = $dostupne ? get_suroviny_for_admin($pdo, $hladaj) : [];
$bezKategorie = $dostupne ? count(get_suroviny_for_admin($pdo, '', 1000)) : 0;
$kategorie = $dostupne ? get_surovina_categories($pdo) : [];
$vsetkySuroviny = $dostupne ? get_all_suroviny($pdo) : [];
$csrfToken = csrf_token();
$adresa = 'suroviny.php' . ($hladaj !== '' ? '?hladaj=' . rawurlencode($hladaj) : '');

sprava_zaciatok($pdo, 'suroviny', 'Suroviny');
?>
    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if (!$dostupne): ?>
        <div class="sprava-upozornenie">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
            <div>Číselník surovín v databáze chýba. Naimportuj <code>sql/instalacia.sql</code>.</div>
        </div>
    <?php else: ?>

    <?php if ($konflikt && $konflikt['z']): ?>
        <div class="alert alert-warning surovina-konflikt" role="alert">
            <p class="mb-2">
                Surovina <strong><?= htmlspecialchars($konflikt['do']['nazov']) ?></strong> už existuje.
                Chceš <strong><?= htmlspecialchars($konflikt['z']['nazov']) ?></strong> zlúčiť do nej?
                <?= sprava_pocet_receptov($konflikt['receptov']) ?> prejde na „<?= htmlspecialchars($konflikt['do']['nazov']) ?>“
                a „<?= htmlspecialchars($konflikt['z']['nazov']) ?>“ sa zmaže.
            </p>
            <div class="sprava-akcie">
                <form method="post" action="<?= htmlspecialchars($adresa) ?>">
                    <input type="hidden" name="action" value="surovina_zlucit">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="hladaj" value="<?= htmlspecialchars($hladaj) ?>">
                    <input type="hidden" name="z_id" value="<?= (int) $konflikt['z']['id'] ?>">
                    <input type="hidden" name="do_id" value="<?= (int) $konflikt['do']['id'] ?>">
                    <button type="submit" class="btn sprava-tlacidlo sprava-hlavne">
                        <i class="bi bi-intersect" aria-hidden="true"></i>Zlúčiť do „<?= htmlspecialchars($konflikt['do']['nazov']) ?>“
                    </button>
                </form>
                <a href="<?= htmlspecialchars($adresa) ?>" class="btn sprava-tlacidlo sprava-vedlajsie">Nechať tak</a>
            </div>
        </div>
    <?php endif; ?>

    <p class="sprava-popis">
        Suroviny pridané pri písaní receptov nemajú kategóriu, preto sú v nákupnom zozname v skupine „Ostatné“.
        Zaraď ich, oprav preklep v názve, alebo zlúč dve suroviny, ktoré sú to isté. Zmeny sa prejavia vo všetkých receptoch aj v nákupnom zozname.
    </p>

    <form method="get" action="suroviny.php" class="sprava-hladanie" role="search">
        <div class="sprava-hladanie-pole">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="search" name="hladaj" class="form-control" value="<?= htmlspecialchars($hladaj) ?>"
                   placeholder="Hľadať surovinu…" aria-label="Hľadať surovinu" enterkeyhint="search">
        </div>
        <button type="submit" class="btn sprava-tlacidlo sprava-hlavne">Hľadať</button>
    </form>
    <div class="sprava-filtre">
        <a href="suroviny.php" class="sprava-filter<?= $hladaj === '' ? ' is-active' : '' ?>"<?= $hladaj === '' ? ' aria-current="page"' : '' ?>>Bez kategórie (<?= $bezKategorie ?>)</a>
        <?php if ($hladaj !== ''): ?>
            <span class="sprava-filter is-active" aria-current="page"><i class="bi bi-search" aria-hidden="true"></i><?= htmlspecialchars($hladaj) ?></span>
        <?php endif; ?>
    </div>

    <h2 class="sprava-nadpis">
        <?php if ($hladaj === ''): ?>
            Suroviny bez kategórie <span class="sprava-pocet"><?= $bezKategorie ?></span>
        <?php else: ?>
            Výsledky pre „<?= htmlspecialchars($hladaj) ?>“ <span class="sprava-pocet"><?= count($suroviny) ?><?= count($suroviny) >= 100 ? ', zobrazených prvých 100' : '' ?></span>
        <?php endif; ?>
    </h2>

    <?php if (!$suroviny): ?>
        <div class="sprava-prazdne">
            <i class="bi <?= $hladaj === '' ? 'bi-check2-circle' : 'bi-search' ?>" aria-hidden="true"></i>
            <?= $hladaj === '' ? 'Všetky suroviny majú kategóriu.' : 'Nič sa nenašlo.' ?>
        </div>
    <?php endif; ?>

    <ul class="sprava-zoznam suroviny-zoznam">
        <?php foreach ($suroviny as $s): ?>
            <li class="sprava-polozka surovina-riadok" data-surovina-id="<?= $s['id'] ?>">
                <form method="post" action="<?= htmlspecialchars($adresa) ?>" class="surovina-uprava">
                    <input type="hidden" name="action" value="surovina_ulozit">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="hladaj" value="<?= htmlspecialchars($hladaj) ?>">
                    <input type="hidden" name="surovina_id" value="<?= $s['id'] ?>">
                    <input type="text" name="nazov" class="form-control" maxlength="100" required
                           value="<?= htmlspecialchars($s['nazov']) ?>" aria-label="Názov suroviny">
                    <div class="surovina-uprava-kategoria">
                        <select name="kategoria" class="form-select" aria-label="Kategória suroviny <?= htmlspecialchars($s['nazov']) ?>">
                            <option value="">— bez kategórie —</option>
                            <?php foreach ($kategorie as $k): ?>
                                <option value="<?= htmlspecialchars($k) ?>"<?= $k === $s['kategoria'] ? ' selected' : '' ?>><?= htmlspecialchars($k) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn sprava-tlacidlo sprava-hlavne"><i class="bi bi-check-lg" aria-hidden="true"></i>Uložiť</button>
                    </div>
                </form>

                <div class="surovina-info">
                    <span class="surovina-pouzitie">
                        <?= sprava_pocet_receptov($s['receptov']) ?><?= $s['v_nakupe'] ? ' · v nákupe' : '' ?>
                    </span>
                    <div class="sprava-akcie">
                        <details class="sprava-rozbal surovina-zlucit">
                            <summary class="btn sprava-tlacidlo sprava-vedlajsie"><i class="bi bi-intersect" aria-hidden="true"></i>Zlúčiť s inou…</summary>
                            <form method="post" action="<?= htmlspecialchars($adresa) ?>" class="sprava-rozbal-form surovina-zlucit-form"
                                  data-z-nazov="<?= htmlspecialchars($s['nazov']) ?>" data-receptov="<?= $s['receptov'] ?>">
                                <input type="hidden" name="action" value="surovina_zlucit">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="hladaj" value="<?= htmlspecialchars($hladaj) ?>">
                                <input type="hidden" name="z_id" value="<?= $s['id'] ?>">
                                <input type="hidden" name="do_id" value="">
                                <label class="form-label" for="ciel-<?= $s['id'] ?>">
                                    Ktorá surovina ostane? „<?= htmlspecialchars($s['nazov']) ?>“ sa do nej zlúči a zmaže.
                                </label>
                                <div class="surovina-combo">
                                    <input type="text" id="ciel-<?= $s['id'] ?>" class="form-control surovina-ciel" autocomplete="off" maxlength="100"
                                           placeholder="Napíš názov, napr. Paradajky">
                                </div>
                                <button type="submit" class="btn sprava-tlacidlo sprava-hlavne" disabled>
                                    <i class="bi bi-intersect" aria-hidden="true"></i>Zlúčiť
                                </button>
                            </form>
                        </details>
                        <?php if ($s['receptov'] === 0): ?>
                            <form method="post" action="<?= htmlspecialchars($adresa) ?>"
                                  onsubmit="return confirm('Naozaj zmazať surovinu <?= htmlspecialchars(addslashes($s['nazov']), ENT_QUOTES) ?>?');">
                                <input type="hidden" name="action" value="surovina_zmazat">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="hladaj" value="<?= htmlspecialchars($hladaj) ?>">
                                <input type="hidden" name="surovina_id" value="<?= $s['id'] ?>">
                                <button type="submit" class="btn sprava-tlacidlo sprava-zmazat sprava-ikona" title="Zmazať nepoužívanú surovinu"
                                        aria-label="Zmazať surovinu <?= htmlspecialchars($s['nazov']) ?>">
                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
<?php sprava_koniec($pdo); ?>
<script>
    window.APP_SUROVINY = <?= json_encode($vsetkySuroviny, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="../suroviny.js?v=31"></script>
<script>
// Zlúčenie: cieľová surovina sa vyberá rovnakým vyhľadávaním ako v recepte (len existujúce).
document.querySelectorAll('.surovina-zlucit-form').forEach((form) => {
    const input = form.querySelector('.surovina-ciel');
    const ciel = form.querySelector('[name="do_id"]');
    const tlacidlo = form.querySelector('button[type="submit"]');
    const zId = Number(form.querySelector('[name="z_id"]').value);
    let vybrana = null;
    window.Suroviny.attachCombobox(input, {
        items: window.APP_SUROVINY || [],
        allowNew: false,
        podobne: false,
        isTaken: (item) => item.id === zId,
        onPick: (item) => {
            vybrana = item;
            input.value = item.nazov;
            ciel.value = item.id;
            tlacidlo.disabled = false;
        },
    });
    input.addEventListener('input', () => {
        if (vybrana && input.value !== vybrana.nazov) {
            vybrana = null;
            ciel.value = '';
            tlacidlo.disabled = true;
        }
    });
    form.addEventListener('submit', (e) => {
        if (!vybrana) { e.preventDefault(); return; }
        const pocet = Number(form.dataset.receptov);
        const recepty = pocet === 1 ? '1 recept' : (pocet >= 2 && pocet <= 4 ? pocet + ' recepty' : pocet + ' receptov');
        if (!confirm('Zlúčiť „' + form.dataset.zNazov + '“ do „' + vybrana.nazov + '“?\n\n'
            + recepty + ' prejde na „' + vybrana.nazov + '“ a „' + form.dataset.zNazov + '“ sa zmaže. Vrátiť sa to nedá.')) {
            e.preventDefault();
        }
    });
});
</script>
</body>
</html>
