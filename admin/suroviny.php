<?php
/**
 * Správa surovín (len admin): zaradenie do kategórie, oprava názvu,
 * zlúčenie dvoch surovín (preklep, iný názov tej istej veci) a zmazanie
 * nepoužívanej. Predvolene ukazuje suroviny bez kategórie — to sú tie,
 * ktoré pribudli pri písaní receptov. Databázu netreba meniť.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

$userId = (int) get_current_user_id($pdo);
$hladaj = trim((string) ($_POST['hladaj'] ?? $_GET['hladaj'] ?? ''));
$message = null;
$error = null;
$konflikt = null; // ponuka zlúčenia, keď nový názov už má iná surovina

/** „1 recept", „3 recepty", „7 receptov" */
function pocet_receptov_s(int $pocet): string
{
    return $pocet . ' ' . ($pocet === 1 ? 'recept' : ($pocet >= 2 && $pocet <= 4 ? 'recepty' : 'receptov'));
}

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
                $message = "Surovina „{$vysledok['z']}“ je zlúčená do „{$vysledok['do']}“ (" . pocet_receptov_s($vysledok['receptov']) . ').';
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
?>
<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="UTF-8">
    <script src="../theme.js"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suroviny — Evidencia receptov</title>
    <link rel="manifest" href="../manifest.json?v=4">
    <meta name="theme-color" content="#f59e0b">
    <link rel="icon" type="image/png" sizes="192x192" href="../app-icons/icon-192.png?v=4">
    <link rel="apple-touch-icon" sizes="180x180" href="../app-icons/apple-touch-icon.png?v=4">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../style.css?v=26">
</head>
<body class="p-3 p-md-4">
<div class="container" style="max-width:900px;">
    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-basket-fill me-2"></i>Suroviny</h1>
        <div class="d-flex gap-2">
            <button id="themeToggle" type="button" class="btn btn-outline-secondary btn-sm" title="Zmeniť motív" aria-label="Zmeniť motív">
                <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
            </button>
            <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Späť do Správy</a>
        </div>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if (!$dostupne): ?>
        <div class="alert alert-warning">Číselník surovín v databáze chýba. Naimportuj <code>sql/instalacia.sql</code>.</div>
    <?php else: ?>

    <?php if ($konflikt && $konflikt['z']): ?>
        <div class="alert alert-warning surovina-konflikt" role="alert">
            <p class="mb-2">
                Surovina <strong><?= htmlspecialchars($konflikt['do']['nazov']) ?></strong> už existuje.
                Chceš <strong><?= htmlspecialchars($konflikt['z']['nazov']) ?></strong> zlúčiť do nej?
                <?= pocet_receptov_s($konflikt['receptov']) ?> prejde na „<?= htmlspecialchars($konflikt['do']['nazov']) ?>“
                a „<?= htmlspecialchars($konflikt['z']['nazov']) ?>“ sa zmaže.
            </p>
            <div class="d-flex flex-wrap gap-2">
                <form method="post" action="<?= htmlspecialchars($adresa) ?>">
                    <input type="hidden" name="action" value="surovina_zlucit">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="hladaj" value="<?= htmlspecialchars($hladaj) ?>">
                    <input type="hidden" name="z_id" value="<?= (int) $konflikt['z']['id'] ?>">
                    <input type="hidden" name="do_id" value="<?= (int) $konflikt['do']['id'] ?>">
                    <button type="submit" class="btn btn-warning surovina-tlacidlo fw-semibold">
                        <i class="bi bi-intersect me-1"></i>Zlúčiť do „<?= htmlspecialchars($konflikt['do']['nazov']) ?>“
                    </button>
                </form>
                <a href="<?= htmlspecialchars($adresa) ?>" class="btn btn-outline-secondary surovina-tlacidlo">Nechať tak</a>
            </div>
        </div>
    <?php endif; ?>

    <p class="text-secondary">
        Suroviny pridané pri písaní receptov nemajú kategóriu, preto sú v nákupnom zozname v skupine „Ostatné“.
        Zaraď ich, oprav preklep v názve, alebo zlúč dve suroviny, ktoré sú to isté. Zmeny sa prejavia vo všetkých receptoch aj v nákupnom zozname.
    </p>

    <form method="get" action="suroviny.php" class="d-flex flex-wrap gap-2 mb-3" role="search">
        <input type="search" name="hladaj" class="form-control surovina-pole" value="<?= htmlspecialchars($hladaj) ?>"
               placeholder="Hľadať vo všetkých surovinách…" aria-label="Hľadať surovinu" style="flex: 1 1 220px;">
        <button type="submit" class="btn btn-primary surovina-tlacidlo"><i class="bi bi-search me-1"></i>Hľadať</button>
        <?php if ($hladaj !== ''): ?>
            <a href="suroviny.php" class="btn btn-outline-secondary surovina-tlacidlo">Bez kategórie (<?= $bezKategorie ?>)</a>
        <?php endif; ?>
    </form>

    <h2 class="h6 text-secondary mb-3">
        <?php if ($hladaj === ''): ?>
            Suroviny bez kategórie (<?= $bezKategorie ?>)
        <?php else: ?>
            Výsledky pre „<?= htmlspecialchars($hladaj) ?>“ (<?= count($suroviny) ?><?= count($suroviny) >= 100 ? ', zobrazených prvých 100' : '' ?>)
        <?php endif; ?>
    </h2>

    <?php if (!$suroviny): ?>
        <p class="text-secondary"><?= $hladaj === '' ? 'Všetky suroviny majú kategóriu.' : 'Nič sa nenašlo.' ?></p>
    <?php endif; ?>

    <ul class="list-unstyled suroviny-zoznam mb-0">
        <?php foreach ($suroviny as $s): ?>
            <li class="surovina-riadok" data-surovina-id="<?= $s['id'] ?>">
                <form method="post" action="<?= htmlspecialchars($adresa) ?>" class="surovina-uprava">
                    <input type="hidden" name="action" value="surovina_ulozit">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="hladaj" value="<?= htmlspecialchars($hladaj) ?>">
                    <input type="hidden" name="surovina_id" value="<?= $s['id'] ?>">
                    <input type="text" name="nazov" class="form-control surovina-pole" maxlength="100" required
                           value="<?= htmlspecialchars($s['nazov']) ?>" aria-label="Názov suroviny">
                    <select name="kategoria" class="form-select surovina-pole" aria-label="Kategória suroviny <?= htmlspecialchars($s['nazov']) ?>">
                        <option value="">— bez kategórie —</option>
                        <?php foreach ($kategorie as $k): ?>
                            <option value="<?= htmlspecialchars($k) ?>"<?= $k === $s['kategoria'] ? ' selected' : '' ?>><?= htmlspecialchars($k) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-primary surovina-tlacidlo"><i class="bi bi-check-lg me-1"></i>Uložiť</button>
                </form>

                <div class="surovina-info">
                    <span class="text-secondary">
                        <?= pocet_receptov_s($s['receptov']) ?><?= $s['v_nakupe'] ? ' · v nákupe' : '' ?>
                    </span>
                    <div class="surovina-akcie">
                        <details class="surovina-zlucit">
                            <summary class="btn btn-outline-secondary surovina-tlacidlo"><i class="bi bi-intersect me-1"></i>Zlúčiť s inou…</summary>
                            <form method="post" action="<?= htmlspecialchars($adresa) ?>" class="surovina-zlucit-form"
                                  data-z-nazov="<?= htmlspecialchars($s['nazov']) ?>" data-receptov="<?= $s['receptov'] ?>">
                                <input type="hidden" name="action" value="surovina_zlucit">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="hladaj" value="<?= htmlspecialchars($hladaj) ?>">
                                <input type="hidden" name="z_id" value="<?= $s['id'] ?>">
                                <input type="hidden" name="do_id" value="">
                                <label class="form-label small text-secondary mb-1">
                                    Ktorá surovina ostane? „<?= htmlspecialchars($s['nazov']) ?>“ sa do nej zlúči a zmaže.
                                </label>
                                <div class="surovina-combo">
                                    <input type="text" class="form-control surovina-pole surovina-ciel" autocomplete="off" maxlength="100"
                                           placeholder="Napíš názov, napr. Paradajky" aria-label="Surovina, ktorá ostane">
                                </div>
                                <button type="submit" class="btn btn-warning surovina-tlacidlo fw-semibold mt-2" disabled>
                                    <i class="bi bi-intersect me-1"></i>Zlúčiť
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
                                <button type="submit" class="btn btn-outline-danger surovina-tlacidlo" title="Zmazať nepoužívanú surovinu"
                                        aria-label="Zmazať surovinu <?= htmlspecialchars($s['nazov']) ?>">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>
<script>
    window.APP_SUROVINY = <?= json_encode($vsetkySuroviny, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="../suroviny.js?v=26"></script>
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
