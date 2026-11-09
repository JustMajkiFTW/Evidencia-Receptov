<?php
/**
 * Správa — Kategórie receptov (len admin, od verzie 2.1 na vlastnej stránke).
 * Kľúč kategórie pri recepte sa nemení, premenovanie mení len zobrazený názov.
 * Bez tabuľky z migrácie 1.5 stránka ukáže základný zoznam a vysvetlí, čo treba urobiť.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/sprava.php';
require_admin();

$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $kategoriaId = (int) ($_POST['kategoria_id'] ?? 0);
    // Bez tabuľky kategórií sa nič nemení, preto stačí vysvetlenie (aj bez platného formulára).
    if (!recipe_categories_available($pdo)) {
        $error = 'Na úpravu kategórií treba najprv naimportovať sql/migracia-1.5.sql.';
    } elseif (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Vypršala platnosť formulára, skús to znova.';
    } elseif ($action === 'kategoria_pridat') {
        $vysledok = add_recipe_category($pdo, (string) ($_POST['nazov'] ?? ''));
        if ($vysledok['success']) { $message = 'Kategória pridaná.'; } else { $error = $vysledok['error']; }
    } elseif ($action === 'kategoria_premenovat') {
        $vysledok = rename_recipe_category($pdo, $kategoriaId, (string) ($_POST['nazov'] ?? ''));
        if ($vysledok['success']) { $message = 'Kategória premenovaná.'; } else { $error = $vysledok['error']; }
    } elseif ($action === 'kategoria_hore' || $action === 'kategoria_dole') {
        move_recipe_category($pdo, $kategoriaId, $action === 'kategoria_hore' ? -1 : 1);
    } elseif ($action === 'kategoria_zmazat') {
        $vysledok = delete_recipe_category($pdo, $kategoriaId);
        if ($vysledok['success']) { $message = 'Kategória zmazaná.'; } else { $error = $vysledok['error']; }
    }
}

$kategorieDostupne = recipe_categories_available($pdo);
$kategorie = get_recipe_categories($pdo);
$kategorieZoZoznamu = array_values(array_filter($kategorie, fn($k) => !$k['mimo_zoznamu']));
$kategorieMimo = array_values(array_filter($kategorie, fn($k) => $k['mimo_zoznamu']));
$csrfToken = csrf_token();

sprava_zaciatok($pdo, 'kategorie', 'Kategórie receptov');
?>
    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if (!$kategorieDostupne): ?>
        <div class="sprava-upozornenie">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
            <div>Na úpravu kategórií treba v phpMyAdmin naimportovať <code>sql/migracia-1.5.sql</code>.
                Dovtedy appka používa základný zoznam nižšie.</div>
        </div>
    <?php else: ?>
        <p class="sprava-popis">
            Kategórie sú tlačidlá nad receptami a výber vo formulári, v tomto poradí. Premenovanie zmení názov
            pri všetkých receptoch tejto kategórie. Zmazať sa dá len kategória, ktorú nepoužíva žiadny recept.
        </p>
        <form method="post" class="sprava-karta sprava-pridat" aria-label="Pridať kategóriu">
            <input type="hidden" name="action" value="kategoria_pridat">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <label class="form-label" for="novaKategoria">Nová kategória</label>
            <div class="sprava-riadok-pola">
                <input type="text" name="nazov" id="novaKategoria" class="form-control" maxlength="<?= KATEGORIA_MAX_DLZKA ?>"
                       placeholder="napr. Šaláty" required>
                <button type="submit" class="btn sprava-tlacidlo sprava-hlavne"><i class="bi bi-plus-lg" aria-hidden="true"></i>Pridať</button>
            </div>
        </form>
    <?php endif; ?>

    <h2 class="sprava-nadpis">Poradie kategórií <span class="sprava-pocet"><?= count($kategorieZoZoznamu) ?></span></h2>
    <ol class="sprava-zoznam kategorie-zoznam">
        <?php foreach ($kategorieZoZoznamu as $i => $k): ?>
            <li class="sprava-polozka kategoria-riadok">
                <span class="kategoria-cislo" aria-hidden="true"><?= $i + 1 ?></span>
                <?php if ($kategorieDostupne): ?>
                    <form method="post" class="kategoria-premenovat">
                        <input type="hidden" name="action" value="kategoria_premenovat">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="kategoria_id" value="<?= (int) $k['id'] ?>">
                        <input type="text" name="nazov" class="form-control kategoria-nazov" maxlength="<?= KATEGORIA_MAX_DLZKA ?>"
                               value="<?= htmlspecialchars($k['nazov']) ?>" aria-label="Názov kategórie <?= htmlspecialchars($k['nazov']) ?>" required>
                        <button type="submit" class="btn sprava-tlacidlo sprava-vedlajsie kategoria-ulozit" title="Uložiť nový názov">
                            <i class="bi bi-check-lg" aria-hidden="true"></i>Uložiť
                        </button>
                    </form>
                <?php else: ?>
                    <span class="kategoria-nazov-text"><?= htmlspecialchars($k['nazov']) ?></span>
                <?php endif; ?>
                <div class="kategoria-spodok">
                    <span class="kategoria-pocet"><?= sprava_pocet_receptov($k['pocet']) ?></span>
                    <?php if ($kategorieDostupne): ?>
                        <div class="kategoria-akcie">
                            <?php foreach ([['kategoria_hore', 'bi-arrow-up', 'Posunúť vyššie', $i === 0],
                                            ['kategoria_dole', 'bi-arrow-down', 'Posunúť nižšie', $i === count($kategorieZoZoznamu) - 1]] as [$akcia, $ikona, $popis, $vypnute]): ?>
                                <form method="post">
                                    <input type="hidden" name="action" value="<?= $akcia ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="kategoria_id" value="<?= (int) $k['id'] ?>">
                                    <button type="submit" class="btn sprava-tlacidlo sprava-vedlajsie sprava-ikona" title="<?= $popis ?>"
                                            aria-label="<?= $popis ?>: <?= htmlspecialchars($k['nazov']) ?>"<?= $vypnute ? ' disabled' : '' ?>>
                                        <i class="bi <?= $ikona ?>" aria-hidden="true"></i>
                                    </button>
                                </form>
                            <?php endforeach; ?>
                            <form method="post"
                                  onsubmit="return confirm('Naozaj zmazať kategóriu <?= htmlspecialchars(addslashes($k['nazov']), ENT_QUOTES) ?>?');">
                                <input type="hidden" name="action" value="kategoria_zmazat">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="kategoria_id" value="<?= (int) $k['id'] ?>">
                                <button type="submit" class="btn sprava-tlacidlo sprava-zmazat sprava-ikona"
                                        title="<?= $k['pocet'] > 0 ? 'Používa ju ' . sprava_pocet_receptov($k['pocet']) . ', najprv im zmeň kategóriu' : 'Zmazať kategóriu' ?>"
                                        aria-label="Zmazať kategóriu <?= htmlspecialchars($k['nazov']) ?>"<?= $k['pocet'] > 0 ? ' disabled' : '' ?>>
                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ol>

    <?php if ($kategorieMimo): ?>
        <div class="sprava-upozornenie mt-3">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            <div>Niektoré recepty majú kategóriu, ktorá v zozname nie je:
                <?= htmlspecialchars(implode(', ', array_map(fn($k) => $k['nazov'] . ' (' . sprava_pocet_receptov($k['pocet']) . ')', $kategorieMimo))) ?>.
                Pridaj ju pod rovnakým názvom, alebo receptom zmeň kategóriu.</div>
        </div>
    <?php endif; ?>
<?php sprava_koniec($pdo); ?>
<script>
// Uložiť sa zvýrazní, až keď sa názov kategórie zmení.
document.querySelectorAll('.kategoria-premenovat').forEach((form) => {
    const pole = form.querySelector('.kategoria-nazov');
    pole.addEventListener('input', () => form.classList.toggle('je-zmenena', pole.value !== pole.defaultValue));
});
</script>
</body>
</html>
