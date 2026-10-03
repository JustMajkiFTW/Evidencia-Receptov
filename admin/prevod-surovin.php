<?php
/**
 * prevod-surovin.php — jednorazový prevod starých receptov.
 *
 * Staré recepty majú suroviny ako voľný text v recipes.ingredients (jedna
 * surovina na riadok). Táto stránka každý riadok navrhne priradiť k surovine
 * z číselníka; admin návrh skontroluje a uloží. Pôvodný text sa pred zmenou
 * odloží do recept_suroviny_zaloha, takže prevod receptu sa dá vrátiť späť.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

/* ------------------------------------------------------------------ */
/* Návrh priradenia pre jeden riadok starého textu                      */
/* ------------------------------------------------------------------ */

/** Slová, ktoré znamenajú jednotku („2 lyžice masla"), podľa normalizovaného kľúča. */
const PREVOD_JEDNOTKY = [
    'g' => 'g', 'kg' => 'kg', 'ml' => 'ml', 'dl' => 'dl', 'l' => 'l', 'ks' => 'ks', 'x' => 'ks',
    'pl' => 'PL', 'lyzica' => 'PL', 'lyzice' => 'PL', 'lyzic' => 'PL',
    'cl' => 'ČL', 'lyzicka' => 'ČL', 'lyzicky' => 'ČL', 'lyziciek' => 'ČL',
    'salka' => 'šálka', 'salky' => 'šálka', 'stipka' => 'štipka', 'stipku' => 'štipka',
    'balenie' => 'balenie', 'balenia' => 'balenie', 'bal' => 'balenie',
    'platok' => 'plátok', 'platky' => 'plátok', 'platkov' => 'plátok',
    'strucik' => 'strúčik', 'struciky' => 'strúčik', 'strucky' => 'strúčik',
];

/** Bežné tvary, ktoré by podobnosť názvov sama netrafila (kľúč → kľúč v číselníku). */
const PREVOD_SYNONYMA = [
    'vajicka' => 'vajcia', 'vajicko' => 'vajcia', 'vajce' => 'vajcia', 'vajec' => 'vajcia',
    'zemiak' => 'zemiaky', 'paradajka' => 'paradajky', 'jablko' => 'jablka',
    'kridla' => 'kuracie kridla', 'kridielka' => 'kuracie kridla',
    'cukor' => 'cukor krystalovy', 'korenie' => 'cierne korenie', 'kura' => 'kurca cele', 'kurca' => 'kurca cele',
];

/** Pomôcky a iné nepotraviny — také riadky necháme predvolene v poznámkach. */
const PREVOD_NIE_SUROVINA = [
    'forma', 'alobal', 'gril', 'papier', 'pekac', 'plech', 'friteza', 'fryer', 'hrniec', 'panvica',
    'mixer', 'spajdle', 'spilky', 'folia', 'rura', 'truba', 'odkaz', 'recept', 'recepte', 'postup',
];

function prevod_navrh(string $line, array $byKey, array $all): array
{
    $original = trim($line);
    $text = trim(preg_replace('/^[\-–—•*·]+\s*/u', '', $original) ?? $original);

    // 1) množstvo a jednotka na začiatku riadka („200 g múka", „2 vajcia", „1/2 ČL soli")
    $mnozstvo = null;
    $jednotka = '';
    $rozsah = '';
    if (preg_match('/^(\d+\s*[-–]\s*\d+)\s*(.+)$/u', $text, $m)) {
        // „2-3 vajcia": rozsah sa nedá uložiť ako jedno číslo, pôjde do poznámky
        $rozsah = preg_replace('/\s+/u', '', $m[1]);
        $text = trim($m[2]);
    } elseif (preg_match('~^(\d+\s*/\s*\d+|\d+(?:[.,]\d+)?)\s*(.*)$~u', $text, $m) && trim($m[2]) !== '') {
        $mnozstvo = parse_surovina_mnozstvo($m[1]);
        $text = trim($m[2]);
    }
    if (preg_match('/^(\S+?)\.?\s+(.+)$/u', $text, $m) && isset(PREVOD_JEDNOTKY[surovina_key($m[1])])
        && ($mnozstvo !== null || $rozsah !== '' || mb_strlen($m[1]) > 2)) {
        $jednotka = PREVOD_JEDNOTKY[surovina_key($m[1])];
        $text = trim($m[2]);
    }

    // 2) poznámka za šípkou, pomlčkou alebo v zátvorke („Pizza cesto XXL -> LIDL")
    $poznamka = '';
    $parts = preg_split('/\s*(?:->|=>|–|—|\s-\s|\()\s*/u', $text, 2);
    if (is_array($parts) && count($parts) === 2 && trim($parts[0]) !== '') {
        $text = trim($parts[0]);
        $poznamka = trim(rtrim(trim($parts[1]), ')'));
    }
    if ($rozsah !== '') {
        $poznamka = trim($rozsah . ($jednotka !== '' ? ' ' . $jednotka : '') . ($poznamka !== '' ? ', ' . $poznamka : ''));
        $jednotka = '';
    }
    $text = trim($text, " \t,;:");

    $key = surovina_key($text);
    $words = $key === '' ? [] : explode(' ', $key);

    $navrh = [
        'original' => $original,
        'zapnute'  => true,
        'zhoda'    => 'nova',           // presna | navrh | nova
        'nazov'    => surovina_display_name($text),
        'mnozstvo' => $mnozstvo,
        'jednotka' => $jednotka,
        'poznamka' => $poznamka,
    ];

    // 3) presná zhoda podľa kľúča
    if (isset($byKey[$key])) {
        $navrh['zhoda'] = 'presna';
        $navrh['nazov'] = $byKey[$key]['nazov'];
        return $navrh;
    }

    // Vety, odkazy a pomôcky nie sú suroviny — predvolene ostanú v poznámkach.
    $vyzeraAkoPoznamka = $key === ''
        || mb_strlen($original) > 60
        || count($words) > 6
        || preg_match('/[.!?]$/u', $original) === 1
        || stripos($original, 'http') !== false
        || count(array_intersect($words, PREVOD_NIE_SUROVINA)) > 0;
    if ($vyzeraAkoPoznamka) {
        $navrh['zapnute'] = false;
        return $navrh;
    }

    // 4) synonymá a podobnosť názvov
    if (isset(PREVOD_SYNONYMA[$key], $byKey[PREVOD_SYNONYMA[$key]])) {
        $navrh['zhoda'] = 'navrh';
        $navrh['nazov'] = $byKey[PREVOD_SYNONYMA[$key]]['nazov'];
        return $navrh;
    }

    $best = null;
    $bestScore = 0.0;
    foreach ($all as $item) {
        $score = 0.0;
        if (!array_diff($words, $item['words'])) {
            // všetky slová riadka sú v názve suroviny („niva" → „Syr niva")
            $score = 0.9 - 0.1 * (count($item['words']) - count($words));
        } elseif (!array_diff($item['words'], $words)) {
            // názov suroviny je celý v riadku („kuracie horné stehná" → „Kuracie stehná")
            $score = 0.6 + 0.3 * count($item['words']) / count($words);
        } elseif (count($item['words']) === count($words)) {
            // preklep alebo iný pád („mlieka" → „Mlieko"); podobné musí byť každé slovo zvlášť,
            // inak by „paradajkový základ" sadol na „Paradajkový pretlak"
            $similarity = 1.0;
            foreach ($words as $i => $word) {
                $other = $item['words'][$i];
                $similarity = min($similarity, 1 - levenshtein($word, $other) / max(strlen($word), strlen($other)));
            }
            $score = $similarity >= 0.72 ? $similarity * 0.85 : 0.0;
        }
        if ($score > $bestScore || ($score > 0 && $score === $bestScore && strlen($item['key']) < strlen($best['key']))) {
            $best = $item;
            $bestScore = $score;
        }
    }

    if ($best !== null && $bestScore >= 0.6) {
        $navrh['zhoda'] = 'navrh';
        $navrh['nazov'] = $best['nazov'];
        // Slová navyše („horné", „mix") nezahodíme, pôjdu do poznámky.
        $extra = array_filter(
            preg_split('/\s+/u', $text) ?: [],
            static fn(string $word): bool => !in_array(surovina_key($word), $best['words'], true)
        );
        if ($extra && !array_diff($best['words'], $words)) {
            $navrh['poznamka'] = trim(mb_strtolower(implode(' ', $extra), 'UTF-8') . ($poznamka !== '' ? ', ' . $poznamka : ''));
        }
    }

    return $navrh;
}

/* ------------------------------------------------------------------ */
/* Akcie                                                                */
/* ------------------------------------------------------------------ */

$ready = suroviny_available($pdo);
if ($ready) {
    try {
        $pdo->query('SELECT 1 FROM recept_suroviny_zaloha LIMIT 1');
    } catch (Throwable $e) {
        $ready = false;
    }
}

$currentUser = current_user();
$flash = null;

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'convert') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            if (!csrf_verify($_POST['csrf_token'] ?? null)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Vypršala platnosť formulára. Obnov stránku a skús znova.']);
                exit;
            }

            $recipeId = (int) ($_POST['recipe_id'] ?? 0);
            $recipe = get_recipe($pdo, $recipeId);
            if (!$recipe) {
                echo json_encode(['success' => false, 'error' => 'Recept neexistuje.']);
                exit;
            }

            $done = $pdo->prepare('SELECT 1 FROM recept_suroviny_zaloha WHERE recept_id = ?');
            $done->execute([$recipeId]);
            if ($done->fetchColumn()) {
                echo json_encode(['success' => false, 'error' => 'Tento recept je už prevedený. Obnov stránku.']);
                exit;
            }

            $rows = parse_suroviny_rows($_POST['suroviny_json'] ?? '[]');
            $notes = trim((string) ($_POST['notes'] ?? ''));

            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO recept_suroviny_zaloha (recept_id, povodny_text) VALUES (?, ?)')
                ->execute([$recipeId, $recipe['ingredients']]);
            save_recipe_suroviny($pdo, $recipeId, $rows);
            $pdo->prepare('UPDATE recipes SET ingredients = ? WHERE id = ?')
                ->execute([$notes !== '' ? $notes : null, $recipeId]);
            log_recipe_action($pdo, $recipeId, (int) $currentUser['id'], 'updated', 'Prevod surovín na výber zo zoznamu');
            $pdo->commit();

            $saved = get_all_recipe_suroviny($pdo)[$recipeId] ?? [];
            echo json_encode(['success' => true, 'suroviny' => $saved], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            debug_log('PREVOD SUROVIN zlyhal: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Prevod sa nepodarilo uložiť: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'undo') {
        if (!csrf_verify($_POST['csrf_token'] ?? null)) {
            $_SESSION['prevod_flash'] = ['danger', 'Vypršala platnosť formulára, skús to znova.'];
        } else {
            $recipeId = (int) ($_POST['recipe_id'] ?? 0);
            $backup = $pdo->prepare('SELECT povodny_text FROM recept_suroviny_zaloha WHERE recept_id = ?');
            $backup->execute([$recipeId]);
            $row = $backup->fetch();
            if ($row === false) {
                $_SESSION['prevod_flash'] = ['danger', 'K tomuto receptu nie je záloha.'];
            } else {
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare('UPDATE recipes SET ingredients = ? WHERE id = ?')->execute([$row['povodny_text'], $recipeId]);
                    $pdo->prepare('DELETE FROM recept_suroviny WHERE recept_id = ?')->execute([$recipeId]);
                    $pdo->prepare('DELETE FROM recept_suroviny_zaloha WHERE recept_id = ?')->execute([$recipeId]);
                    $pdo->commit();
                    $_SESSION['prevod_flash'] = ['success', 'Prevod bol vrátený, recept má znova pôvodný text.'];
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    debug_log('PREVOD SUROVIN undo zlyhal: ' . $e->getMessage());
                    $_SESSION['prevod_flash'] = ['danger', 'Vrátenie sa nepodarilo.'];
                }
            }
        }
        header('Location: prevod-surovin.php');
        exit;
    }
}

if (!empty($_SESSION['prevod_flash'])) {
    $flash = $_SESSION['prevod_flash'];
    unset($_SESSION['prevod_flash']);
}

/* ------------------------------------------------------------------ */
/* Dáta pre stránku                                                     */
/* ------------------------------------------------------------------ */

$allSuroviny = [];
$pending = [];
$converted = [];

if ($ready) {
    $allSuroviny = get_all_suroviny($pdo);

    $byKey = [];
    $index = [];
    foreach ($allSuroviny as $s) {
        $key = surovina_key($s['nazov']);
        $entry = ['id' => $s['id'], 'nazov' => $s['nazov'], 'key' => $key, 'words' => explode(' ', $key)];
        $byKey[$key] = $entry;
        $index[] = $entry;
    }

    // Na prevod čakajú recepty, ktoré majú starý text a ešte žiadne suroviny zo zoznamu.
    $recipes = $pdo->query(
        "SELECT r.id, r.name, r.ingredients
         FROM recipes r
         WHERE r.ingredients IS NOT NULL AND TRIM(r.ingredients) <> ''
           AND NOT EXISTS (SELECT 1 FROM recept_suroviny_zaloha z WHERE z.recept_id = r.id)
           AND NOT EXISTS (SELECT 1 FROM recept_suroviny rs WHERE rs.recept_id = r.id)
         ORDER BY r.name"
    )->fetchAll();

    foreach ($recipes as $r) {
        $lines = [];
        foreach (preg_split('/\R/u', $r['ingredients']) ?: [] as $line) {
            if (trim($line) !== '') {
                $lines[] = prevod_navrh($line, $byKey, $index);
            }
        }
        $pending[] = ['id' => (int) $r['id'], 'name' => $r['name'], 'lines' => $lines];
    }

    $converted = $pdo->query(
        'SELECT r.id, r.name, (SELECT COUNT(*) FROM recept_suroviny rs WHERE rs.recept_id = r.id) AS pocet
         FROM recept_suroviny_zaloha z
         JOIN recipes r ON r.id = z.recept_id
         ORDER BY z.prevedene_at DESC, r.name'
    )->fetchAll();
}

$csrfToken = csrf_token();
?>
<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="UTF-8">
    <script src="../theme.js"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prevod surovín — Evidencia receptov</title>
    <link rel="manifest" href="../manifest.json?v=4">
    <meta name="theme-color" content="#f59e0b">
    <link rel="icon" type="image/png" sizes="192x192" href="../app-icons/icon-192.png?v=4">
    <link rel="apple-touch-icon" sizes="180x180" href="../app-icons/apple-touch-icon.png?v=4">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../style.css?v=9">
</head>
<body class="p-3 p-md-4">
<div class="container" style="max-width:760px;">
    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0"><i class="bi bi-arrow-left-right me-2"></i>Prevod surovín</h1>
        <div class="d-flex gap-2">
            <button id="themeToggle" type="button" class="btn btn-outline-secondary btn-sm" title="Zmeniť motív" aria-label="Zmeniť motív">
                <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
            </button>
            <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-people-fill me-1"></i>Používatelia</a>
            <a href="../index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Späť</a>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= htmlspecialchars($flash[0]) ?>"><?= htmlspecialchars($flash[1]) ?></div>
    <?php endif; ?>

    <?php if (!$ready): ?>
        <div class="alert alert-warning">
            V databáze chýbajú tabuľky pre suroviny. Najprv v phpMyAdmine spusti súbor
            <code>migracia_suroviny.sql</code> a potom túto stránku obnov.
        </div>
    <?php else: ?>

    <p class="text-secondary">
        Každý riadok starého textu sa stane surovinou zo zoznamu, alebo ostane v poznámkach receptu.
        Skontroluj návrhy, uprav čo nesedí a recept ulož. Pôvodný text sa zálohuje, prevod sa dá vrátiť späť.
    </p>
    <p class="fw-semibold" id="pendingCount" data-count="<?= count($pending) ?>">
        <?= $pending ? 'Zostáva previesť: ' . count($pending) : 'Všetky recepty sú prevedené.' ?>
    </p>
    <p class="text-success small" id="prevodStatus" role="status"></p>

    <?php foreach ($pending as $recipe): ?>
        <div class="card mb-3 prevod-card" data-recipe-id="<?= $recipe['id'] ?>">
            <div class="card-header fw-semibold"><?= htmlspecialchars($recipe['name']) ?></div>
            <div class="card-body py-2">
                <?php foreach ($recipe['lines'] as $i => $line):
                    $switchId = 'on-' . $recipe['id'] . '-' . $i; ?>
                    <div class="prevod-line<?= $line['zapnute'] ? '' : ' is-off' ?>"
                         data-line='<?= htmlspecialchars(json_encode($line, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>'>
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input prevod-on" type="checkbox" role="switch"
                                   id="<?= $switchId ?>" <?= $line['zapnute'] ? 'checked' : '' ?>
                                   title="Zapnuté = surovina, vypnuté = ostane v poznámkach">
                            <label class="visually-hidden" for="<?= $switchId ?>">Použiť ako surovinu: <?= htmlspecialchars($line['original']) ?></label>
                        </div>
                        <div class="prevod-original"><?= htmlspecialchars($line['original']) ?></div>
                        <div class="prevod-edit">
                            <div class="prevod-name">
                                <div class="surovina-combo">
                                    <input type="text" class="form-control form-control-sm prevod-nazov" autocomplete="off"
                                           maxlength="100" value="<?= htmlspecialchars($line['nazov']) ?>"
                                           aria-label="Surovina pre riadok: <?= htmlspecialchars($line['original']) ?>">
                                </div>
                                <span class="badge prevod-badge"></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="card-footer d-flex flex-wrap align-items-center gap-2">
                <button type="button" class="btn btn-primary btn-sm fw-semibold prevod-save">
                    <i class="bi bi-check2 me-1"></i>Uložiť recept
                </button>
                <span class="small text-danger prevod-error" role="alert"></span>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($converted): ?>
        <?php /* Hotové recepty sú schované — rozbalia sa len vtedy, keď treba prevod vrátiť späť. */ ?>
        <details class="mt-4 mb-4">
        <summary class="text-secondary small">Už prevedené recepty (<?= count($converted) ?>) — rozbaľ, ak chceš niektorý prevod vrátiť späť</summary>
        <ul class="list-group mt-2">
            <?php foreach ($converted as $c): ?>
                <li class="list-group-item d-flex align-items-center justify-content-between gap-2">
                    <span><?= htmlspecialchars($c['name']) ?>
                        <span class="text-secondary small">· surovín: <?= (int) $c['pocet'] ?></span></span>
                    <form method="post" onsubmit="return confirm('Vrátiť prevod tohto receptu? Suroviny zo zoznamu sa odstránia a vráti sa pôvodný text.');">
                        <input type="hidden" name="action" value="undo">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="recipe_id" value="<?= (int) $c['id'] ?>">
                        <button type="submit" class="btn btn-outline-secondary btn-sm text-nowrap">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Vrátiť späť
                        </button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
        </details>
    <?php endif; ?>

    <?php endif; ?>
</div>

<?php if ($ready): ?>
<script src="../suroviny.js?v=9"></script>
<script>
(function () {
    const S = window.Suroviny;
    const suroviny = <?= json_encode($allSuroviny, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const jednotky = <?= json_encode(SUROVINA_JEDNOTKY, JSON_UNESCAPED_UNICODE) ?>;
    const csrfToken = <?= json_encode($csrfToken) ?>;
    const countEl = document.getElementById('pendingCount');
    const statusEl = document.getElementById('prevodStatus');

    document.querySelectorAll('.prevod-line').forEach((lineEl) => {
        const line = JSON.parse(lineEl.dataset.line);
        const input = lineEl.querySelector('.prevod-nazov');
        const badge = lineEl.querySelector('.prevod-badge');
        const toggle = lineEl.querySelector('.prevod-on');
        let untouched = true; // kým názov nikto nezmenil, platí pôvodný návrh

        lineEl.querySelector('.prevod-edit').appendChild(S.createDetailFields(line, jednotky, line.original));

        const combo = S.attachCombobox(input, {
            items: suroviny,
            onPick: (item) => {
                input.value = item.nazov;
                untouched = false;
                updateBadge();
            },
        });

        function updateBadge() {
            const found = combo.find(input.value);
            let text = 'nová';
            let cls = 'text-bg-warning';
            if (found && untouched && line.zhoda === 'navrh') {
                text = 'návrh';
                cls = 'text-bg-info';
            } else if (found) {
                text = 'zo zoznamu';
                cls = 'text-bg-success';
            }
            badge.textContent = text;
            badge.className = 'badge prevod-badge ' + cls;
        }

        input.addEventListener('input', () => { untouched = false; updateBadge(); });
        toggle.addEventListener('change', () => lineEl.classList.toggle('is-off', !toggle.checked));
        lineEl._updateBadge = updateBadge;
        updateBadge();
    });

    document.querySelectorAll('.prevod-card').forEach((card) => {
        const saveBtn = card.querySelector('.prevod-save');
        const errorEl = card.querySelector('.prevod-error');

        saveBtn.addEventListener('click', async () => {
            errorEl.textContent = '';
            const rows = [];
            const notes = [];
            let invalid = null;

            card.querySelectorAll('.prevod-line').forEach((lineEl) => {
                const line = JSON.parse(lineEl.dataset.line);
                if (!lineEl.querySelector('.prevod-on').checked) {
                    notes.push(line.original);
                    return;
                }
                const input = lineEl.querySelector('.prevod-nazov');
                const nazov = S.displayName(input.value);
                if (!nazov) { invalid = invalid || input; return; }
                const key = S.normKey(nazov);
                const found = suroviny.find((s) => S.normKey(s.nazov) === key);
                rows.push(Object.assign({ id: found ? found.id : null, nazov }, S.readDetailFields(lineEl)));
            });

            if (invalid) {
                errorEl.textContent = 'Doplň názov suroviny, alebo riadok vypni, aby ostal v poznámkach.';
                invalid.focus();
                return;
            }

            const formData = new FormData();
            formData.append('action', 'convert');
            formData.append('csrf_token', csrfToken);
            formData.append('recipe_id', card.dataset.recipeId);
            formData.append('suroviny_json', JSON.stringify(rows));
            formData.append('notes', notes.join('\n'));

            saveBtn.disabled = true;
            try {
                const res = await fetch('prevod-surovin.php', { method: 'POST', body: formData });
                const data = await res.json();
                if (!data.success) {
                    errorEl.textContent = data.error || 'Uloženie zlyhalo.';
                    saveBtn.disabled = false;
                    return;
                }

                // Nové suroviny z tohto receptu hneď ponúkneme aj v ďalších kartách.
                (data.suroviny || []).forEach((row) => {
                    if (!suroviny.some((s) => s.id === row.id)) {
                        suroviny.push({ id: row.id, nazov: row.nazov, kategoria: '' });
                    }
                });
                document.querySelectorAll('.prevod-line').forEach((el) => el._updateBadge && el._updateBadge());

                // Hotový recept zo stránky zmizne, ostane len krátke potvrdenie hore.
                const name = card.querySelector('.card-header').textContent.trim();
                card.remove();
                statusEl.textContent = 'Uložené: ' + name + ' · surovín: ' + (data.suroviny || []).length
                    + (notes.length ? ' · riadkov v poznámkach: ' + notes.length : '');

                const left = Math.max(0, Number(countEl.dataset.count) - 1);
                countEl.dataset.count = left;
                countEl.textContent = left ? 'Zostáva previesť: ' + left : 'Všetky recepty sú prevedené.';
            } catch (err) {
                errorEl.textContent = 'Chyba pripojenia k serveru. Skús to znova.';
                saveBtn.disabled = false;
            }
        });
    });
})();
</script>
<?php endif; ?>
</body>
</html>
