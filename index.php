<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ai.php';
// ?recept=ID (odkaz z upozornenia) otvorí náhľad daného receptu. Ak treba najprv
// prihlásenie, číslo receptu sa prenesie cez login.php a vráti späť sem.
$openRecipeId = (isset($_GET['recept']) && ctype_digit((string) $_GET['recept'])) ? (int) $_GET['recept'] : 0;
$keepQuery = $openRecipeId > 0 ? 'recept=' . $openRecipeId : '';
require_login($keepQuery); // presmeruje na prihlásenie, ak nie si prihlásený
enforce_session_idle_timeout($keepQuery);

// Ak PHP dostane POST request väčší než 'post_max_size' z php.ini, celé
// $_POST aj $_FILES sú tíchо prázdne (aj keď Content-Length > 0). Bez tejto
// kontroly by appka v takom prípade len vyrenderovala normálnu stránku
// (status 200, plný HTML) namiesto JSON-u, čo v JS vyzerá ako "chyba pripojenia".
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES)) {
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > 0) {
        debug_log('POST_MAX_SIZE PRESIAHNUTÝ: Content-Length=' . $contentLength . ' (php.ini post_max_size=' . ini_get('post_max_size') . ')');
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(413);
        echo json_encode(['success' => false, 'error' => 'Fotka je príliš veľká pre limit servera. Skús menšiu fotku (appka ju teraz pred odoslaním sama zmenší, skús to prosím znova).']);
        exit;
    }
}

$action = $_POST['action'] ?? $_GET['action'] ?? null;

/* ------------------------------------------------------------------ */
/* AJAX akcie nad receptami — všetky vyžadujú platný CSRF token         */
/* ------------------------------------------------------------------ */
if (in_array($action, ['add_recipe', 'update_recipe', 'mark_made', 'delete_recipe', 'delete_photo', 'get_logs', 'rate_recipe', 'save_push_subscription',
        'shopping_get', 'shopping_add_recipe', 'shopping_add_item', 'shopping_toggle', 'shopping_delete', 'shopping_clear',
        'ai_rozpoznaj'], true)
        && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    try {

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Neplatný bezpečnostný token. Obnov stránku a skús znova.']);
        exit;
    }

    $user = current_user();
    $userId = get_current_user_id($pdo);

    if ($userId === null) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Tvoj účet sa nepodarilo nájsť v databáze.']);
        exit;
    }

    // Spoločné načítanie nových polí pre oba prípady
    $ingredients = !empty($_POST['ingredients']) ? $_POST['ingredients'] : null;
    $url = !empty($_POST['url']) ? $_POST['url'] : null;
    $prep_time = !empty($_POST['prep_time']) ? (int)$_POST['prep_time'] : null;
    $category = !empty($_POST['category']) ? $_POST['category'] : null;
    $last_made = !empty($_POST['last_made_date']) ? $_POST['last_made_date'] : null;
    // Suroviny vybrané z číselníka (JSON z formulára). null = formulár ich neposlal, nič sa nemení.
    $servings = parse_servings($_POST['servings'] ?? null);
    $surovinyRows = isset($_POST['suroviny_json']) ? parse_suroviny_rows($_POST['suroviny_json']) : null;
    // Dátum pod novou fotkou v galérii (null = predvolený, pozri add_recipe/update_recipe).
    $photoDate = parse_app_date($_POST['photo_date'] ?? null);

    // Spracovanie prípadnej nahranej fotky (pridanie a úprava receptu, „Uvarené")
    $newImagePath = null;
    if (in_array($action, ['add_recipe', 'update_recipe', 'mark_made'], true) && !empty($_FILES['recipe_image']['name'])) {
        $uploadResult = process_recipe_image_upload($_FILES['recipe_image']);
        if (isset($uploadResult['error'])) {
            echo json_encode(['success' => false, 'error' => $uploadResult['error']]);
            exit;
        }
        $newImagePath = $uploadResult['path'] ?? null;
    }
    $removeImage = !empty($_POST['remove_image']);

    switch ($action) {
        case 'add_recipe':
    $result = add_recipe($pdo, $userId, $_POST['name'] ?? '', $ingredients, $url, $prep_time, $category, $last_made, $newImagePath, $surovinyRows, $servings, $photoDate);

    if ($result['success']) {
        $addedByName = $user['full_name'] ?? $user['username'] ?? 'Niekto';
        $recipeName = trim($_POST['name'] ?? '');
        send_push_notification(
            $pdo,
            null,
            'Nový recept 🍽️',
            $addedByName . ' pridal(a) recept „' . $recipeName . '"',
            './?recept=' . (int) ($result['id'] ?? 0),
            $userId
        );
    }

    echo json_encode($result);
    exit;

        case 'update_recipe':
            $recipeId = (int)($_POST['recipe_id'] ?? 0);
            $result = update_recipe($pdo, $recipeId, $userId, $_POST['name'] ?? '', $ingredients, $url, $prep_time, $category, $last_made, $newImagePath, $removeImage, $surovinyRows, $servings, $photoDate);
            echo json_encode($result);
            exit;

        case 'get_logs':
            $recipeId = (int)($_POST['recipe_id'] ?? 0);
            // Vyberieme históriu a pripojíme meno používateľa
            $stmt = $pdo->prepare('
            SELECT l.*, COALESCE(NULLIF(TRIM(u.full_name), \'\'), u.username) AS username
            FROM recipe_logs l 
            LEFT JOIN users u ON l.user_id = u.id 
            WHERE l.recipe_id = ? 
            ORDER BY l.created_at DESC, l.id DESC
        ');
            $stmt->execute([$recipeId]);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'logs' => $logs]);
            exit;
			
		case 'save_push_subscription':
    $sub = json_decode($_POST['subscription'] ?? '', true);
    if (!$sub || empty($sub['endpoint']) || empty($sub['keys']['p256dh']) || empty($sub['keys']['auth'])) {
        echo json_encode(['success' => false, 'error' => 'Neplatné dáta subscription.']);
        exit;
    }

    $stmt = $pdo->prepare('
        INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh), auth = VALUES(auth)
    ');
    $stmt->execute([$userId, $sub['endpoint'], $sub['keys']['p256dh'], $sub['keys']['auth']]);

    echo json_encode(['success' => true]);
    exit;

        // ... ostatné prípady ako mark_made, delete_recipe ...

        case 'mark_made':
            // made_date = deň varenia z okna „Uvarené" (prázdne alebo neplatné = dnes)
            $madeDate = parse_app_date($_POST['made_date'] ?? null);
            $result = mark_recipe_made($pdo, (int)($_POST['recipe_id'] ?? 0), $userId, $madeDate, $newImagePath);
            break;

        case 'delete_photo':
            $result = delete_recipe_photo($pdo, (int)($_POST['photo_id'] ?? 0), $userId, $user['role']);
            break;

        case 'rate_recipe':
            $recipeId = (int)($_POST['recipe_id'] ?? 0);
            $result = rate_recipe($pdo, $recipeId, $userId, (int)($_POST['rating'] ?? 0));

            if ($result['success']) {
                $ratedRecipe = get_recipe($pdo, $recipeId);
                // Autora receptu nezaťažujeme notifikáciou, ak si ohodnotil vlastný recept
                if ($ratedRecipe && (int) $ratedRecipe['created_by'] !== $userId) {
                    $raterName = $user['full_name'] ?? $user['username'] ?? 'Niekto';
                    send_push_notification(
                        $pdo,
                        (int) $ratedRecipe['created_by'],
                        'Nové hodnotenie ⭐',
                        $raterName . ' dal(a) tvojmu receptu „' . $ratedRecipe['name'] . '" ' . $result['yourRating'] . ' ★',
                        './?recept=' . $recipeId
                    );
                }
            }
            break;

        case 'delete_recipe':
            $result = delete_recipe($pdo, (int)($_POST['recipe_id'] ?? 0), $userId, $user['role']);
            break;

        /* ---------------- Recept z fotky cez AI (includes/ai.php) ---------------- */
        case 'ai_rozpoznaj':
            $result = ai_rozpoznaj($pdo, $userId, $_FILES['fotka'] ?? null, is_admin());
            break;

        /* ---------------- Nákupný zoznam ---------------- */
        case 'shopping_get':
        case 'shopping_add_recipe':
        case 'shopping_add_item':
        case 'shopping_toggle':
        case 'shopping_delete':
        case 'shopping_clear':
            if (!shopping_available($pdo)) {
                $result = ['success' => false, 'error' => 'Nákupný zoznam ešte nie je v databáze pripravený.'];
                break;
            }
            $result = ['success' => true];
            $itemId = (int) ($_POST['item_id'] ?? 0);

            if ($action === 'shopping_add_recipe') {
                $target = parse_servings($_POST['servings'] ?? null);
                $result = shopping_add_recipe($pdo, (int) ($_POST['recipe_id'] ?? 0), $userId, $target);
            } elseif ($action === 'shopping_add_item') {
                $surovinaId = (int) ($_POST['surovina_id'] ?? 0);
                $nazov = surovina_display_name((string) ($_POST['nazov'] ?? ''));
                if ($surovinaId > 0) {
                    $find = $pdo->prepare('SELECT id, nazov FROM suroviny WHERE id = ?');
                    $find->execute([$surovinaId]);
                    $surovina = $find->fetch();
                } else {
                    // vlastná položka („toaletný papier") sa do číselníka surovín nepridáva,
                    // ale ak sa volá rovnako ako surovina, naviaže sa na ňu
                    $find = $pdo->prepare('SELECT id, nazov FROM suroviny WHERE kluc = ?');
                    $find->execute([surovina_key($nazov)]);
                    $surovina = $find->fetch();
                }
                if ($surovina) {
                    shopping_add_item($pdo, $userId, (int) $surovina['id'], $surovina['nazov'], null, null, null);
                } elseif ($nazov !== '') {
                    shopping_add_item($pdo, $userId, null, $nazov, null, null, null);
                } else {
                    $result = ['success' => false, 'error' => 'Napíš, čo treba kúpiť.'];
                }
            } elseif ($action === 'shopping_toggle') {
                $pdo->prepare('UPDATE nakupny_zoznam SET kupene = ? WHERE id = ?')
                    ->execute([!empty($_POST['kupene']) ? 1 : 0, $itemId]);
            } elseif ($action === 'shopping_delete') {
                $pdo->prepare('DELETE FROM nakupny_zoznam WHERE id = ?')->execute([$itemId]);
            } elseif ($action === 'shopping_clear') {
                $pdo->exec(($_POST['mode'] ?? '') === 'all'
                    ? 'DELETE FROM nakupny_zoznam'
                    : 'DELETE FROM nakupny_zoznam WHERE kupene = 1');
            }

            // Každá akcia vráti aj čerstvý zoznam — klient ho len prekreslí.
            $result['items'] = get_shopping_list($pdo);
            break;
    }

    echo json_encode($result);
    exit;

    } catch (Throwable $e) {
        debug_log('AJAX EXCEPTION (' . $action . '): ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Nastala neočakávaná chyba: ' . $e->getMessage()]);
        exit;
    }
}

/* ------------------------------------------------------------------ */
/* Bežné vykreslenie stránky                                            */
/* ------------------------------------------------------------------ */
$recipes = get_all_recipes($pdo);
$user = current_user();
$csrfToken = csrf_token();
$currentUserId = get_current_user_id($pdo);
$ratingsSummary = get_recipe_ratings_summary($pdo);
$userRatings = $currentUserId ? get_user_recipe_ratings($pdo, $currentUserId) : [];
$surovinyEnabled = suroviny_available($pdo);
$servingsEnabled = recipes_have_servings($pdo);
$shoppingEnabled = shopping_available($pdo);
$shoppingCount = shopping_open_count($pdo);
$allSuroviny = get_all_suroviny($pdo);
$recipeSuroviny = get_all_recipe_suroviny($pdo);
$photosEnabled = recipes_have_photos($pdo);
$recipePhotos = get_recipe_photos($pdo);
$recipeCategories = get_recipe_categories($pdo); // z tabuľky kategorie_receptov, bez migrácie základný zoznam
// Recept z fotky: zapnuté, keď je v .env kľúč aspoň k jednej AI službe (includes/ai.php).
$aiEnabled = ai_dostupne() && $currentUserId !== null;
$aiZostava = $aiEnabled ? ai_zostava($pdo, $currentUserId) : null;
// Oslovenie v mobilnej hlavičke: krstné meno (prvé slovo celého mena), inak prihlasovacie meno.
$menoOslovenie = trim((string) ($user['full_name'] ?? ''));
$menoOslovenie = $menoOslovenie !== '' ? explode(' ', $menoOslovenie)[0] : (string) ($user['username'] ?? '');
// Iniciály do okna Viac: „Marián Čambálik" → „MČ".
$menoInicialy = '';
foreach (preg_split('/\s+/u', trim((string) ($user['full_name'] ?: $user['username']))) as $slovo) {
    if ($slovo !== '' && mb_strlen($menoInicialy) < 2) {
        $menoInicialy .= mb_strtoupper(mb_substr($slovo, 0, 1));
    }
}

// Značka pre sw.js: túto stránku si môže uložiť a ukázať ju bez internetu (len na čítanie).
header('X-Recepty-Offline: 1');
?>
<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="UTF-8">
    <script src="theme.js"></script>
    <script>
        // Offline režim: fotky, ktoré sa nenačítali skôr, než sa načíta script.js
        // (ten ich potom nahradí obrázkom „Fotka nie je uložená v zariadení").
        window.APP_CHYBNE_FOTKY = [];
        document.addEventListener('error', function (e) {
            if (e.target && e.target.tagName === 'IMG') window.APP_CHYBNE_FOTKY.push(e.target);
        }, true);
    </script>
    <!-- viewport-fit=cover: spodná lišta na telefóne si cez env(safe-area-inset-bottom) nechá miesto nad pásikom iPhonu -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Evidencia receptov</title>

    <!-- PWA -->
    <link rel="manifest" href="manifest.json?v=4">
    <meta name="theme-color" content="#f59e0b">
    <link rel="icon" type="image/png" sizes="192x192" href="app-icons/icon-192.png?v=4">
    <link rel="apple-touch-icon" sizes="180x180" href="app-icons/apple-touch-icon.png?v=4">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Recepty">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=28">
</head>
<body class="ma-spodnu-listu">

<!-- Toast kontajner pre notifikácie -->
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:1080;">
    <div id="appToast" class="toast align-items-center border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body" id="appToastBody"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<!-- Pásik offline režimu: zobrazí ho style.css/script.js, keď je <html data-offline="…">
     (uložená kópia stránky zo sw.js alebo stratené pripojenie počas používania). -->
<div id="offlinePasik" class="offline-pasik mx-3 mt-3" role="status" aria-live="polite">
    <i class="bi bi-wifi-off offline-pasik-ikona" aria-hidden="true"></i>
    <div class="offline-pasik-text">
        <strong id="offlinePasikNadpis">Bez pripojenia</strong>
        <span id="offlinePasikPopis">Recepty si môžeš pozerať, ale meniť ich teraz nemôžeš.</span>
    </div>
    <button type="button" class="btn btn-warning fw-semibold offline-pasik-tlacidlo" id="offlinePasikTlacidlo">
        <i class="bi bi-arrow-clockwise me-1"></i><span id="offlinePasikTlacidloText">Skúsiť znova</span>
    </button>
</div>

<header class="app-navbar mx-3 mt-3 px-3 px-md-4 py-3">
    <div class="app-navbar-brand d-flex align-items-center gap-2">
        <i class="bi bi-egg-fried fs-4 text-warning"></i>
        <div class="min-w-0">
            <div class="fw-bold text-truncate">Evidencia receptov</div>
            <div class="text-secondary small text-truncate">
                <?= htmlspecialchars($user['full_name']) ?>
                <span class="badge <?= $user['role'] === 'admin' ? 'text-bg-warning' : 'text-bg-primary' ?> ms-1"><?= htmlspecialchars($user['role']) ?></span>
            </div>
        </div>
    </div>
    <div class="app-navbar-actions">
        <button type="button" class="btn btn-primary btn-sm fw-semibold app-nav-add" onclick="openRecipeModal()" data-vyzaduje-siet>
            <i class="bi bi-plus-lg"></i><span class="btn-label btn-label-long">Pridať recept</span><span class="btn-label btn-label-short">Pridať</span>
        </button>
        <?php if ($shoppingEnabled): ?>
        <button type="button" class="btn btn-outline-primary btn-sm position-relative" onclick="openShoppingModal()" data-vyzaduje-siet
                title="Nákupný zoznam" aria-label="Nákupný zoznam">
            <i class="bi bi-cart3"></i><span class="btn-label">Nákup</span>
            <span class="badge rounded-pill text-bg-danger shopping-count<?= $shoppingCount ? '' : ' d-none' ?>"
                  id="shoppingCount"><?= $shoppingCount ?></span>
        </button>
        <?php endif; ?>
        <button id="themeToggle" type="button" class="btn btn-outline-secondary btn-sm" title="Zmeniť motív" aria-label="Zmeniť motív">
            <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
        </button>
        <?php if (is_admin()): ?>
        <a href="admin/index.php" class="btn btn-outline-secondary btn-sm" title="Správa: používatelia, kategórie receptov a suroviny" aria-label="Správa: používatelia, kategórie receptov a suroviny" data-vyzaduje-siet>
            <i class="bi bi-gear-fill"></i><span class="btn-label">Správa</span>
        </a>
        <?php endif; ?>
        <button type="button" class="btn btn-outline-primary btn-sm" onclick="setupPasskeyFromApp()" title="Zapnúť biometriu" aria-label="Zapnúť biometriu" data-vyzaduje-siet>
            <i class="bi bi-fingerprint"></i><span class="btn-label">Biometria</span>
        </button>
        <button type="button" class="btn btn-outline-primary btn-sm" onclick="enablePushNotifications()" title="Povoliť notifikácie" aria-label="Povoliť notifikácie" data-vyzaduje-siet>
            <i class="bi bi-bell-fill"></i><span class="btn-label">Notifikácie</span>
        </button>
        <a href="logout.php" class="btn btn-outline-danger btn-sm" title="Odhlásiť" aria-label="Odhlásiť" data-vyzaduje-siet>
            <i class="bi bi-box-arrow-right"></i><span class="btn-label">Odhlásiť</span>
        </a>
    </div>
</header>

<!-- Hlavička na telefóne (verzia 1.7). Horná lišta s tlačidlami je len na PC a tablete,
     na telefóne sú tie isté akcie v spodnej lište a v okne Viac. -->
<div class="mobil-hlavicka d-md-none">
    <div class="min-w-0">
        <div class="mobil-pozdrav text-truncate">Ahoj, <?= htmlspecialchars($menoOslovenie) ?></div>
        <h1 class="mobil-nadpis">Recepty</h1>
    </div>
    <select id="recipeSortMobil" class="form-select mobil-zoradenie" aria-label="Zoradiť recepty">
        <option value="default">Naposledy varené</option>
        <option value="last_made_asc">Najdlhšie nevarené</option>
        <option value="added_desc">Najnovšie pridané</option>
        <option value="prep_time_asc">Najrýchlejšie</option>
    </select>
</div>

<div class="container-fluid mt-3" style="max-width:1100px;">
    <div class="mx-3 mx-md-0 mb-2 text-secondary small d-none d-md-block">
        <i class="bi bi-info-circle me-1"></i>Kliknutím kdekoľvek na kartu receptu zobrazíš jeho náhľad.
    </div>
    <div class="mx-3 mx-md-0 d-flex flex-wrap gap-2 align-items-center recepty-filtre">
        <div class="input-group shadow-sm recepty-hladanie" style="flex: 1 1 240px; max-width: 500px;">
            <span class="input-group-text bg-body-tertiary border-secondary-subtle rounded-start-pill ps-3">
                <i class="bi bi-search"></i>
            </span>
            <input type="text" id="recipeSearch" class="form-control border-secondary-subtle py-2 rounded-end-pill pe-3"
                   placeholder="Hľadaj podľa názvu alebo surovín..." aria-label="Hľadať recept" enterkeyhint="search">
        </div>

        <select id="recipeCategoryFilter" class="form-select form-select-sm shadow-sm d-none d-md-block"
                style="width:auto; min-width:170px;" aria-label="Kategória receptov">
            <option value="">Všetky kategórie</option>
            <?php foreach ($recipeCategories as $kategoria): ?>
                <option value="<?= htmlspecialchars($kategoria['kluc']) ?>"><?= htmlspecialchars($kategoria['nazov']) ?></option>
            <?php endforeach; ?>
        </select>

        <select id="recipeSortSelect" class="form-select form-select-sm shadow-sm d-none d-md-block" style="width:auto; min-width:220px;" aria-label="Zoradenie receptov">
            <option value="default">Naposledy varené — najnovšie</option>
            <option value="last_made_asc">Naposledy varené — najstaršie</option>
            <option value="added_desc">Najnovšie pridané prvé</option>
            <option value="prep_time_asc">Čas prípravy (najkratšie prvé)</option>
        </select>
    </div>
    <!-- Kategórie ako veľké tlačidlá (len telefón) — nastavujú ten istý filter ako výber kategórie vyššie. -->
    <div class="kategoria-cipy d-md-none" role="group" aria-label="Kategória receptov">
        <button type="button" class="kategoria-cip is-active" data-kategoria="" aria-pressed="true">Všetko</button>
        <?php foreach ($recipeCategories as $kategoria): ?>
            <button type="button" class="kategoria-cip" data-kategoria="<?= htmlspecialchars($kategoria['kluc']) ?>" aria-pressed="false"><?= htmlspecialchars($kategoria['nazov']) ?></button>
        <?php endforeach; ?>
    </div>
</div>

<div class="container-fluid recipes-container">
    <div class="app-card mt-4 mx-3 mx-md-0" id="recipesTable">
        <div class="recipes-grid" id="recipeTableBody">
            <?php if (empty($recipes)): ?>
                <div class="recipes-grid-empty text-center text-secondary py-5">
                    <i class="bi bi-clipboard-x fs-2 d-block mb-2"></i>
                    Zatiaľ tu nie sú žiadne recepty. Pridaj prvý pomocou tlačidla „Pridať recept“.
                </div>
            <?php endif; ?>

            <?php foreach ($recipes as $r):
                $days = days_since($r['last_made_date']);

                if ($days === null) {
                    $badgeClass = 'text-bg-success';
                    $badgeText = 'Ešte nikdy — skús to!';
                } elseif ($days > 90) {
                    $badgeClass = 'text-bg-danger';
                    $badgeText = "Pred $days dňami — čas na opakovanie!";
                } elseif ($days > 10) {
                    $badgeClass = 'text-bg-warning';
                    $badgeText = "Pred $days dňami";
                } else {
                    $badgeClass = 'text-bg-secondary';
                    $badgeText = "Pred $days dňami";
                }

                $suroviny = $recipeSuroviny[$r['id']] ?? [];
                // Galéria od najnovšej fotky; na karte je najnovšia (bez galérie titulná z receptu).
                $photos = $recipePhotos[$r['id']] ?? [];
                $coverPath = $photos[0]['path'] ?? ($r['image_path'] ?? '');
                // Text na hľadanie: názvy surovín + poznámky, bez diakritiky a veľkých písmen,
                // aby „muka" našlo „Múka" (JS si rovnako upraví hľadaný výraz).
                $searchIngredients = surovina_key(implode(' ', array_column($suroviny, 'nazov')) . ' ' . ($r['ingredients'] ?? ''));

                // Dáta pre JS na predvyplnenie modalu pri úprave (bezpečne
                // zakódované ako JSON, escapované pre vloženie do onclick='...').
                $editPayload = json_encode([
                        'id' => $r['id'],
                        'name' => $r['name'],
                        'lastMadeDate' => $r['last_made_date'],
                        'ingredients' => $r['ingredients'] ?? '',
                        'suroviny' => $suroviny,
                        'url' => $r['url'] ?? '',
                        'prep_time' => $r['prep_time'] ?? '',
                        'servings' => !empty($r['servings']) ? (int) $r['servings'] : null,
                        'category' => $r['category'] ?? '',
                        'categoryLabel' => recipe_category_label($recipeCategories, $r['category'] ?? null),
                        'imagePath' => $coverPath,
                        'photos' => $photos,
                        'badgeText' => $badgeText,
                        'badgeClass' => $badgeClass,
                        'createdBy' => $r['created_by_username'] ?? ''
                ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);

                $ratingInfo = $ratingsSummary[$r['id']] ?? ['avg' => 0, 'count' => 0];
                $myRating = $userRatings[$r['id']] ?? 0;
                ?>
                <div class="recipe-card recipe-row"
                     data-recipe-id="<?= $r['id'] ?>"
                     data-payload='<?= $editPayload ?>'
                     data-search-name="<?= htmlspecialchars(surovina_key($r['name'])) ?>"
                     data-search-ingredients="<?= htmlspecialchars($searchIngredients) ?>"
                     data-category="<?= htmlspecialchars($r['category'] ?? '') ?>"
                     data-prep-time="<?= htmlspecialchars($r['prep_time'] ?? '') ?>"
                     data-last-made="<?= htmlspecialchars($r['last_made_date'] ?? '') ?>">

                    <div class="recipe-card-media">
                        <?php if ($coverPath !== ''): ?>
                            <img src="<?= htmlspecialchars($coverPath) ?>" alt=""
                                 class="recipe-card-img" loading="lazy">
                            <?php if (count($photos) > 1): ?>
                                <span class="recipe-card-photo-count" title="Fotiek v galérii: <?= count($photos) ?>">
                                    <i class="bi bi-images"></i><?= count($photos) ?>
                                </span>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="recipe-card-img-placeholder"><i class="bi bi-egg-fried"></i></div>
                        <?php endif; ?>
                    </div>

                    <div class="recipe-card-body">
                        <div class="recipe-card-title-row">
                            <span class="recipe-card-title" title="Zobraziť náhľad receptu"><?= htmlspecialchars($r['name']) ?></span>
                            <?php if (!empty($r['url'])): ?>
                                <a href="<?= htmlspecialchars($r['url']) ?>" target="_blank"
                                   class="btn btn-link btn-sm p-0 flex-shrink-0 text-primary"
                                   title="Otvoriť pôvodný web receptu">
                                    <i class="bi bi-box-arrow-up-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>

                        <div class="mb-2">
                            <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($badgeText) ?></span>
                        </div>

                        <div class="d-flex flex-wrap gap-1 mb-2 recipe-card-meta">
                            <?php if (!empty($r['category'])): ?>
                                <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle small px-2 py-1">
                                    <?= htmlspecialchars(recipe_category_label($recipeCategories, $r['category'])) ?>
                                </span>
                            <?php endif; ?>

                            <?php if (!empty($r['prep_time'])): ?>
                                <span class="badge bg-body-secondary text-secondary-emphasis border small px-2 py-1">
                                    <i class="bi bi-clock me-1"></i><?= htmlspecialchars($r['prep_time']) ?> min
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="text-secondary small mb-2 recipe-card-autor">
                            <?= htmlspecialchars($r['created_by_username']) ?>
                        </div>

                        <div class="recipe-rating d-flex align-items-center flex-wrap gap-2 mb-1"
                             data-recipe-id="<?= $r['id'] ?>" data-user-rating="<?= $myRating ?>">
                            <span class="rating-stars" data-vyzaduje-siet>
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <i class="bi <?= $i <= $myRating ? 'bi-star-fill' : 'bi-star' ?> rating-star" data-value="<?= $i ?>"
                                       role="button" title="Hodnotiť: <?= $i ?>/5"></i>
                                <?php endfor; ?>
                            </span>
                            <div class="small text-secondary rating-summary">
                                <?php if ($ratingInfo['count'] > 0): ?>
                                    <?= number_format($ratingInfo['avg'], 1, ',', '') ?> ★ (<?= $ratingInfo['count'] ?>)
                                <?php else: ?>
                                    Zatiaľ bez hodnotenia
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="recipe-card-actions">
                        <button class="btn btn-outline-success btn-sm btn-mark-made" data-id="<?= $r['id'] ?>" data-vyzaduje-siet
                                data-name="<?= htmlspecialchars($r['name'], ENT_QUOTES) ?>"
                                title="Uvarené — zapísať dátum a pridať fotku" aria-label="Uvarené">
                            <i class="bi bi-check2-circle"></i>
                        </button>
                        <?php if ($shoppingEnabled && $suroviny): ?>
                        <button type="button" class="btn btn-outline-secondary btn-sm btn-add-shopping" data-id="<?= $r['id'] ?>" data-vyzaduje-siet
                                title="Pridať suroviny do nákupu" aria-label="Pridať suroviny do nákupu">
                            <i class="bi bi-cart-plus"></i>
                        </button>
                        <?php endif; ?>
                        <button type="button" class="btn btn-outline-info btn-sm" data-vyzaduje-siet
                                onclick="openHistoryModal(<?= $r['id'] ?>, '<?= htmlspecialchars($r['name'], ENT_QUOTES) ?>')"
                                title="História receptu">
                            <i class="bi bi-clock-history"></i>
                        </button>
                        <button type="button" class="btn btn-outline-primary btn-sm" data-vyzaduje-siet
                                onclick='openRecipeModal(<?= $editPayload ?>)' title="Upraviť recept">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <?php if (is_admin()): ?>
                            <button class="btn btn-outline-danger btn-sm btn-delete-recipe" data-vyzaduje-siet
                                    data-id="<?= $r['id'] ?>"
                                    data-name="<?= htmlspecialchars($r['name'], ENT_QUOTES) ?>" title="Zmazať recept">
                                <i class="bi bi-trash"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ================= MODAL: Pridať / upraviť recept ================= -->
<div class="modal fade" id="recipeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen-md-down">
        <div class="modal-content">
            <form id="recipeForm" novalidate enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title" id="recipeModalTitle"><i class="bi bi-journal-plus me-2"></i>Pridať recept
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="recipe_id" value="">

                    <?php if ($aiEnabled): ?>
                    <!-- Recept z fotky (verzia 1.8) — len pri novom recepte, skryje ho script.js pri úprave. -->
                    <div class="ai-vyplnit mb-3" id="aiVyplnitWrap">
                        <label class="btn btn-outline-primary fw-semibold ai-vyplnit-tlacidlo" for="aiFotkaFormular" data-vyzaduje-siet>
                            <i class="bi bi-camera me-2" aria-hidden="true"></i>Vyplniť z fotky
                        </label>
                        <input type="file" id="aiFotkaFormular" class="visually-hidden" accept="image/jpeg,image/png,image/webp">
                        <div class="form-text">Fotka jedla alebo receptu z kuchárky — AI navrhne názov a suroviny. Nič sa neuloží, kým nestlačíš Uložiť.</div>
                    </div>
                    <div class="ai-stav mb-3 d-none" id="aiStavFormular" role="status" aria-live="polite">
                        <span class="spinner-border spinner-border-sm text-warning" aria-hidden="true"></span>
                        <span>Pozerám sa na fotku… zvyčajne to trvá 5 až 20 sekúnd.</span>
                    </div>
                    <?php endif; ?>
                    <!-- Vyplnené z fotky: náhľad a voľba, či fotku uložiť do galérie receptu -->
                    <div class="ai-banner mb-3 d-none" id="aiBanner">
                        <img id="aiBannerFoto" src="" alt="Fotka, z ktorej sa recept vyplnil">
                        <div class="min-w-0">
                            <div class="fw-semibold" id="aiBannerNadpis">Vyplnené z fotky</div>
                            <div class="small" id="aiBannerPopis"></div>
                            <div class="form-check mt-2 mb-0">
                                <input class="form-check-input" type="checkbox" id="aiBannerUlozitFotku">
                                <label class="form-check-label" for="aiBannerUlozitFotku">Pridať fotku k receptu</label>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small text-secondary">Názov receptu *</label>
                        <input type="text" name="name" class="form-control" maxlength="150" required>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="<?= $servingsEnabled ? 'col-12' : 'col-md-6' ?>">
                            <label class="form-label small text-secondary">Kategória</label>
                            <select name="category" class="form-select">
                                <option value="">-- Nevybrané --</option>
                                <?php foreach ($recipeCategories as $kategoria): ?>
                                    <option value="<?= htmlspecialchars($kategoria['kluc']) ?>"><?= htmlspecialchars($kategoria['nazov']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="<?= $servingsEnabled ? 'col-6' : 'col-md-6' ?>">
                            <label class="form-label small text-secondary" for="recipePrepTime">Čas prípravy (min)</label>
                            <input type="number" name="prep_time" id="recipePrepTime" class="form-control" min="1" max="999"
                                   placeholder="napr. 45">
                        </div>
                        <?php if ($servingsEnabled): ?>
                        <div class="col-6">
                            <label class="form-label small text-secondary" for="recipeServings">Počet porcií</label>
                            <input type="number" name="servings" id="recipeServings" class="form-control" min="1" max="99"
                                   step="1" inputmode="numeric" placeholder="napr. 4">
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small text-secondary">Odkaz na recept (URL)</label>
                        <input type="url" name="url" class="form-control" placeholder="https://example.com/recept">
                    </div>

                    <?php if ($surovinyEnabled): ?>
                    <div class="mb-3" id="surovinyField">
                        <label class="form-label small text-secondary" for="surovinaSearch">Suroviny</label>
                        <div class="surovina-combo">
                            <input type="text" id="surovinaSearch" class="form-control" autocomplete="off"
                                   placeholder="Začni písať, napr. múka…" maxlength="100">
                        </div>
                        <div class="form-text">Vyber zo zoznamu. Ak tam surovina chýba, dá sa pridať ako nová.</div>
                        <div id="surovinyList" class="suroviny-list"></div>
                        <input type="hidden" name="suroviny_json" value="[]">
                    </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label small text-secondary" for="recipeNotes"><?= $surovinyEnabled ? 'Poznámky / postup' : 'Suroviny / Poznámky' ?></label>
                        <textarea name="ingredients" id="recipeNotes" class="form-control" rows="3"
                                  placeholder="<?= $surovinyEnabled ? 'Stručný postup, pomôcky, kde čo kúpiť…' : 'Napíš sem suroviny alebo stručný postup...' ?>"></textarea>
                    </div>

                    <?php if ($photosEnabled): ?>
                    <div class="mb-3" id="recipePhotosField">
                        <label class="form-label small text-secondary" for="recipeImageInput">Fotky receptu</label>
                        <div id="recipePhotosList" class="photo-thumbs"></div>
                        <div class="photo-pick">
                            <div id="recipeImagePickWrap" class="photo-pick-preview d-none">
                                <img id="recipeImagePick" src="" alt="Náhľad novej fotky">
                                <button type="button" class="btn btn-sm photo-pick-clear" id="recipeImagePickClear"
                                        aria-label="Zrušiť výber fotky" title="Zrušiť výber"><i class="bi bi-x-lg"></i></button>
                            </div>
                            <input type="file" name="recipe_image" id="recipeImageInput" class="form-control"
                                   accept="image/jpeg,image/png,image/webp" aria-describedby="recipeImageHelp">
                        </div>
                        <div id="recipePhotoDateWrap" class="mt-2 d-none">
                            <label class="form-label small text-secondary" for="recipePhotoDate">Dátum novej fotky</label>
                            <input type="date" name="photo_date" id="recipePhotoDate" class="form-control">
                        </div>
                        <div class="form-text" id="recipeImageHelp">Nová fotka sa pridá do galérie, staršie ostanú. JPG, PNG alebo WEBP, max 8 MB.</div>
                    </div>
                    <?php else: ?>
                    <div class="mb-3">
                        <label class="form-label small text-secondary">Fotka receptu</label>
                        <div id="recipeImagePreviewWrap" class="mb-2 d-none">
                            <img id="recipeImagePreview" src="" alt="Náhľad fotky" class="rounded d-block mb-1"
                                 style="max-width:160px;max-height:120px;object-fit:cover;">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="remove_image"
                                       id="recipeImageRemove" value="1">
                                <label class="form-check-label small text-secondary" for="recipeImageRemove">Odstrániť
                                    fotku</label>
                            </div>
                        </div>
                        <input type="file" name="recipe_image" id="recipeImageInput" class="form-control"
                               accept="image/jpeg,image/png,image/webp">
                        <div class="form-text">JPG, PNG alebo WEBP, max 8 MB — automaticky sa zmenší.</div>
                    </div>
                    <?php endif; ?>

                    <div class="mb-1">
                        <label class="form-label small text-secondary">Naposledy varené <span class="opacity-50">(nepovinné)</span></label>
                        <input type="date" name="last_made_date" class="form-control" max="<?= app_today() ?>">
                    </div>

                    <div class="alert alert-danger py-2 small d-none" id="recipeFormError"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Zrušiť
                    </button>
                    <button type="submit" class="btn btn-primary btn-sm fw-semibold" id="recipeFormSubmit">
                        <i class="bi bi-check2 me-1"></i>Uložiť recept
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($aiEnabled): ?>
<!-- ================= MODAL: Nový recept z fotky (verzia 1.8) =================
     Sprievodca na telefóne: výber fotky → rozpoznanie → kontrola surovín → formulár
     receptu (ten istý ako pri ručnom pridaní). Otvára ho Pridať v spodnej lište. -->
<div class="modal fade" id="aiModal" tabindex="-1" aria-hidden="true" aria-labelledby="aiModalTitle">
    <div class="modal-dialog modal-fullscreen-md-down">
        <div class="modal-content">
            <section class="ai-krok" data-krok="vyber">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title ai-nadpis" id="aiModalTitle">Nový recept</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zavrieť"></button>
                </div>
                <div class="modal-body">
                    <p class="ai-podnadpis">Odfoť a suroviny ti navrhneme</p>
                    <label class="ai-odfotit" for="aiFotkaKamera">
                        <span class="ai-odfotit-ikona" aria-hidden="true"><i class="bi bi-camera"></i></span>
                        <span class="ai-odfotit-nadpis">Odfotiť</span>
                        <span class="ai-odfotit-popis">jedlo, stranu z kuchárky<br>alebo lístok s receptom</span>
                    </label>
                    <input type="file" id="aiFotkaKamera" class="visually-hidden" accept="image/*" capture="environment">
                    <label class="btn ai-galeria" for="aiFotkaGaleria">
                        <i class="bi bi-image me-2" aria-hidden="true"></i>Vybrať fotku z telefónu
                    </label>
                    <input type="file" id="aiFotkaGaleria" class="visually-hidden" accept="image/jpeg,image/png,image/webp">
                    <button type="button" class="btn ai-rucne" id="aiRucne">
                        <i class="bi bi-pencil me-2" aria-hidden="true"></i>Vyplniť ručne, bez fotky
                    </button>
                    <div class="ai-info">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                        <span>Fotka jedla sa pridá aj do galérie receptu. Nič sa nezapíše, kým to neskontroluješ.</span>
                    </div>
                    <div class="ai-zostava" id="aiZostava" data-zostava="<?= $aiZostava === null ? '' : (int) $aiZostava ?>"></div>
                </div>
            </section>

            <section class="ai-krok d-none" data-krok="rozpoznavam">
                <div class="modal-header border-0">
                    <button type="button" class="btn ai-spat" id="aiZrusit"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>Zrušiť</button>
                    <span class="ai-krok-cislo">Krok 1 z 3</span>
                </div>
                <div class="modal-body">
                    <img class="ai-nahlad" id="aiNahlad" src="" alt="Fotka na rozpoznanie">
                    <div class="ai-cakanie" role="status" aria-live="polite">
                        <span class="spinner-border ai-cakanie-kruh" aria-hidden="true"></span>
                        <div>
                            <div class="ai-cakanie-nadpis">Pozerám sa na fotku…</div>
                            <div class="ai-cakanie-popis">Zvyčajne to trvá 5 až 20 sekúnd</div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="ai-krok d-none" data-krok="kontrola">
                <div class="modal-header border-0 pb-0">
                    <button type="button" class="btn ai-spat" id="aiZnova"><i class="bi bi-camera me-1" aria-hidden="true"></i>Odfotiť znova</button>
                    <span class="ai-krok-cislo">Krok 2 z 3</span>
                </div>
                <div class="modal-body">
                    <div class="ai-vysledok-hlava">
                        <img class="ai-nahlad-maly" id="aiNahladMaly" src="" alt="">
                        <div class="min-w-0">
                            <h2 class="ai-vysledok-nazov" id="aiVysledokNazov"></h2>
                            <div class="ai-vysledok-pocet" id="aiVysledokPocet"></div>
                        </div>
                    </div>
                    <div class="ai-upozornenie d-none" id="aiOdhad">
                        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                        <span>Odhad z fotky jedla. Skontroluj, či suroviny sedia, a uprav množstvá.</span>
                    </div>
                    <div class="ai-suroviny" id="aiSurovinyZoznam"></div>
                    <div class="surovina-combo ai-pridat-combo d-none" id="aiPridatWrap">
                        <input type="text" id="aiPridatInput" class="form-control" autocomplete="off" maxlength="100"
                               placeholder="Začni písať, napr. múka…" aria-label="Pridať surovinu">
                    </div>
                </div>
                <div class="modal-footer ai-paticka">
                    <button type="button" class="btn btn-outline-secondary" id="aiPridatSurovinu"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Surovina</button>
                    <button type="button" class="btn btn-primary fw-bold" id="aiDalej">Ďalej<i class="bi bi-arrow-right ms-2" aria-hidden="true"></i></button>
                </div>
            </section>

            <section class="ai-krok d-none" data-krok="chyba">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title ai-nadpis">Nový recept</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zavrieť"></button>
                </div>
                <div class="modal-body">
                    <div class="ai-chyba" role="alert">
                        <i class="bi bi-emoji-frown" aria-hidden="true"></i>
                        <p class="ai-chyba-text" id="aiChybaText"></p>
                        <p class="ai-chyba-detail d-none" id="aiChybaDetail"></p>
                    </div>
                    <button type="button" class="btn btn-primary ai-chyba-tlacidlo" id="aiChybaZnova"><i class="bi bi-camera me-2" aria-hidden="true"></i>Skúsiť inú fotku</button>
                    <button type="button" class="btn btn-outline-secondary ai-chyba-tlacidlo" id="aiChybaRucne"><i class="bi bi-pencil me-2" aria-hidden="true"></i>Vyplniť ručne</button>
                </div>
            </section>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ================= MODAL: Náhľad receptu ================= -->
<div class="modal fade" id="recipeViewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-md-down recipe-view-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title d-none d-md-block" id="viewRecipeName"></h5>
                <button type="button" class="btn-close d-none d-md-inline-block" data-bs-dismiss="modal" aria-label="Zavrieť"></button>
                <!-- Telefón: späť vľavo, menej časté akcie vpravo ako ikony (verzia 1.7) -->
                <div class="view-mobil-lista d-md-none">
                    <button type="button" class="btn view-mobil-tlacidlo" data-bs-dismiss="modal" aria-label="Späť na recepty">
                        <i class="bi bi-arrow-left"></i>
                    </button>
                    <div class="view-mobil-akcie">
                        <button type="button" class="btn view-mobil-tlacidlo" id="viewMobilZdielat" aria-label="Zdieľať recept">
                            <i class="bi bi-share"></i>
                        </button>
                        <button type="button" class="btn view-mobil-tlacidlo" id="viewMobilUpravit" aria-label="Upraviť recept" data-vyzaduje-siet>
                            <i class="bi bi-pencil"></i>
                        </button>
                        <div class="dropdown">
                            <button type="button" class="btn view-mobil-tlacidlo" data-bs-toggle="dropdown" aria-expanded="false"
                                    aria-label="Ďalšie možnosti">
                                <i class="bi bi-three-dots"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end view-mobil-menu">
                                <li><button type="button" class="dropdown-item" id="viewMobilHistoria" data-vyzaduje-siet>
                                    <i class="bi bi-clock-history me-2"></i>História receptu</button></li>
                                <?php if (is_admin()): ?>
                                <li><button type="button" class="dropdown-item text-danger" id="viewMobilZmazat" data-vyzaduje-siet>
                                    <i class="bi bi-trash me-2"></i>Zmazať recept</button></li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-body">
                <div id="viewRecipeGallery" class="recipe-gallery mb-3 d-none">
                    <div id="viewRecipeCarousel" class="carousel slide recipe-gallery-carousel">
                        <div class="carousel-inner" id="viewRecipeSlides"></div>
                        <button class="carousel-control-prev" type="button" data-bs-target="#viewRecipeCarousel"
                                data-bs-slide="prev" aria-label="Novšia fotka">
                            <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                        </button>
                        <button class="carousel-control-next" type="button" data-bs-target="#viewRecipeCarousel"
                                data-bs-slide="next" aria-label="Staršia fotka">
                            <span class="carousel-control-next-icon" aria-hidden="true"></span>
                        </button>
                    </div>
                    <div class="recipe-gallery-caption" id="viewRecipePhotoCaption" aria-live="polite"></div>
                    <div class="recipe-gallery-thumbs" id="viewRecipeThumbs" role="group" aria-label="Fotky receptu"></div>
                </div>

                <h2 class="view-mobil-nazov d-md-none" id="viewRecipeNameMobil"></h2>

                <div class="d-flex flex-wrap gap-2 mb-3">
                    <span id="viewRecipeCategory"
                          class="badge bg-info-subtle text-info-emphasis border border-info-subtle d-none"></span>
                    <span id="viewRecipePrepTime"
                          class="badge bg-body-secondary text-secondary-emphasis border d-none"></span>
                    <span id="viewRecipeServings"
                          class="badge bg-body-secondary text-secondary-emphasis border d-none"></span>
                    <span id="viewRecipeLastMade" class="badge"></span>
                </div>

                <!-- Hodnotenie v náhľade (telefón): na karte receptu sa na telefóne hviezdičky neukazujú. -->
                <div class="recipe-rating view-mobil-hodnotenie d-md-none d-none" id="viewRecipeRating" data-recipe-id="" data-user-rating="0">
                    <span class="rating-stars" data-vyzaduje-siet role="group" aria-label="Tvoje hodnotenie">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="bi bi-star rating-star" data-value="<?= $i ?>" role="button" tabindex="0"
                               aria-label="Hodnotiť: <?= $i ?> z 5"></i>
                        <?php endfor; ?>
                    </span>
                    <span class="rating-summary"></span>
                </div>

                <div class="mb-3">
                    <a id="viewRecipeUrl" href="#" target="_blank" class="btn btn-outline-primary btn-sm d-none">
                        <i class="bi bi-box-arrow-up-right me-1"></i>Otvoriť pôvodný recept
                    </a>
                </div>

                <div id="viewRecipeSurovinyWrap" class="mb-3 d-none">
                    <div class="suroviny-view-head">
                        <div class="form-label small text-secondary mb-0">Suroviny</div>
                        <div id="viewServings" class="servings-stepper d-none" role="group" aria-label="Prepočet porcií">
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="viewServingsMinus"
                                    aria-label="Menej porcií"><i class="bi bi-dash-lg"></i></button>
                            <output id="viewServingsValue" class="servings-value" aria-live="polite"></output>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="viewServingsPlus"
                                    aria-label="Viac porcií"><i class="bi bi-plus-lg"></i></button>
                        </div>
                    </div>
                    <div id="viewServingsNote" class="small text-secondary mb-1 d-none"></div>
                    <ul id="viewRecipeSuroviny" class="suroviny-view-list"></ul>
                </div>

                <div id="viewRecipeIngredientsWrap" class="mb-2 d-none">
                    <label class="form-label small text-secondary d-block" id="viewRecipeIngredientsLabel">Suroviny / Poznámky</label>
                    <div id="viewRecipeIngredients" class="small" style="white-space: pre-wrap;"></div>
                </div>

                <div class="text-secondary small" id="viewRecipeCreatedBy"></div>
            </div>
            <div class="modal-footer view-paticka">
                <button type="button" class="btn btn-outline-secondary btn-sm d-none d-md-inline-block" data-bs-dismiss="modal">Zavrieť</button>
                <?php if ($shoppingEnabled): ?>
                <button type="button" class="btn btn-outline-primary btn-sm fw-semibold d-none" id="viewRecipeShoppingBtn" data-vyzaduje-siet>
                    <i class="bi bi-cart-plus me-1"></i>Do nákupu
                </button>
                <?php endif; ?>
                <button type="button" class="btn btn-sm fw-semibold text-white d-none d-md-inline-block" id="viewRecipeShareBtn"
                        style="background-color:#0d6efd; border-color:#0d6efd;">
                    <i class="bi bi-share-fill me-1"></i>Zdieľať
                </button>
                <button type="button" class="btn btn-primary btn-sm fw-semibold d-none d-md-inline-block" id="viewRecipeEditBtn" data-vyzaduje-siet>
                    <i class="bi bi-pencil me-1"></i>Upraviť recept
                </button>
                <!-- Telefón: hlavná akcia veľkým tlačidlom dole -->
                <button type="button" class="btn btn-success fw-bold d-md-none view-mobil-uvarene" id="viewMobilUvarene" data-vyzaduje-siet>
                    <i class="bi bi-check2-circle me-1"></i>Uvarené
                </button>
            </div>
        </div>
    </div>
</div>
<?php if ($shoppingEnabled): ?>
<!-- Plávajúce tlačidlo košíka — nákupný zoznam je po ruke aj po odscrollovaní dole -->
<button type="button" class="shopping-fab<?= $shoppingCount ? '' : ' d-none' ?>" id="shoppingFab" data-vyzaduje-siet
        onclick="openShoppingModal()" aria-label="Otvoriť nákupný zoznam" title="Nákupný zoznam">
    <i class="bi bi-cart3"></i>
    <span class="badge rounded-pill text-bg-danger shopping-fab-count"><?= $shoppingCount ?></span>
</button>
<!-- ================= MODAL: Nákupný zoznam ================= -->
<div class="modal fade modal-s-listou" id="shoppingModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen-md-down">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cart3 me-2"></i>Nákupný zoznam</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zavrieť"></button>
            </div>
            <div class="modal-body">
                <div class="surovina-combo mb-3">
                    <input type="text" id="shoppingAddInput" class="form-control" autocomplete="off" maxlength="100"
                           placeholder="Pridať položku, napr. mlieko…" aria-label="Pridať položku do nákupu">
                </div>
                <div id="shoppingList" aria-live="polite"></div>
            </div>
            <div class="modal-footer justify-content-between">
                <div class="dropdown">
                    <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        Vymazať
                    </button>
                    <ul class="dropdown-menu">
                        <li><button class="dropdown-item" type="button" id="shoppingClearBought">Len kúpené</button></li>
                        <li><button class="dropdown-item text-danger" type="button" id="shoppingClearAll">Celý zoznam</button></li>
                    </ul>
                </div>
                <button type="button" class="btn btn-primary btn-sm fw-semibold" id="shoppingShareBtn">
                    <i class="bi bi-share-fill me-1"></i>Odoslať (Keep…)
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<!-- ================= Spodná lišta (len telefón, verzia 1.7) =================
     Tie isté akcie ako horná lišta na PC. Nákup a Viac sa otvárajú nad receptami
     a lišta ostáva viditeľná, ostatné okná (recept, formulár) ju zakryjú. -->
<nav class="spodna-lista d-md-none" id="spodnaLista" aria-label="Hlavné menu">
    <button type="button" class="spodna-lista-polozka is-active" data-lista="recepty" aria-current="page">
        <i class="bi bi-journal-text" aria-hidden="true"></i><span>Recepty</span>
    </button>
    <button type="button" class="spodna-lista-polozka" data-lista="pridat" data-vyzaduje-siet>
        <span class="spodna-lista-pridat" aria-hidden="true"><i class="bi <?= $aiEnabled ? 'bi-camera' : 'bi-plus-lg' ?>"></i></span><span>Pridať</span>
    </button>
    <?php if ($shoppingEnabled): ?>
    <button type="button" class="spodna-lista-polozka" data-lista="nakup" data-vyzaduje-siet>
        <span class="spodna-lista-ikona" aria-hidden="true"><i class="bi bi-cart3"></i>
            <span class="spodna-lista-pocet<?= $shoppingCount ? '' : ' d-none' ?>" id="spodnaListaPocet"><?= $shoppingCount ?></span></span><span>Nákup</span>
    </button>
    <?php endif; ?>
    <button type="button" class="spodna-lista-polozka" data-lista="viac">
        <i class="bi bi-list" aria-hidden="true"></i><span>Viac</span>
    </button>
</nav>
<!-- ================= MODAL: Viac (len telefón) — motív, biometria, upozornenia, správa ================= -->
<div class="modal fade modal-s-listou" id="viacModal" tabindex="-1" aria-hidden="true" aria-labelledby="viacModalTitle">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title viac-nadpis" id="viacModalTitle">Viac</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zavrieť"></button>
            </div>
            <div class="modal-body">
                <div class="viac-pouzivatel">
                    <div class="viac-inicialy" aria-hidden="true"><?= htmlspecialchars($menoInicialy) ?></div>
                    <div class="min-w-0">
                        <div class="viac-meno text-truncate"><?= htmlspecialchars($user['full_name'] ?: $user['username']) ?></div>
                        <span class="badge <?= $user['role'] === 'admin' ? 'text-bg-warning' : 'text-bg-primary' ?>"><?= htmlspecialchars($user['role']) ?></span>
                    </div>
                </div>
                <div class="viac-zoznam">
                    <label class="viac-riadok" for="viacTemaPrepinac">
                        <i class="bi bi-moon-stars viac-ikona" aria-hidden="true"></i>
                        <span class="viac-text">Tmavý motív</span>
                        <span class="form-check form-switch m-0">
                            <input class="form-check-input viac-prepinac" type="checkbox" role="switch" id="viacTemaPrepinac">
                        </span>
                    </label>
                    <button type="button" class="viac-riadok" onclick="setupPasskeyFromApp()" data-vyzaduje-siet>
                        <i class="bi bi-fingerprint viac-ikona" aria-hidden="true"></i>
                        <span class="viac-text">Prihlásenie odtlačkom / Face ID</span>
                        <i class="bi bi-chevron-right viac-sipka" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="viac-riadok" onclick="enablePushNotifications()" data-vyzaduje-siet>
                        <i class="bi bi-bell viac-ikona" aria-hidden="true"></i>
                        <span class="viac-text">Upozornenia</span>
                        <i class="bi bi-chevron-right viac-sipka" aria-hidden="true"></i>
                    </button>
                    <?php if (is_admin()): ?>
                    <a href="admin/index.php" class="viac-riadok" data-vyzaduje-siet>
                        <i class="bi bi-gear viac-ikona" aria-hidden="true"></i>
                        <span class="viac-text">Správa<small>Používatelia, kategórie, suroviny</small></span>
                        <i class="bi bi-chevron-right viac-sipka" aria-hidden="true"></i>
                    </a>
                    <?php endif; ?>
                </div>
                <a href="logout.php" class="btn viac-odhlasit" data-vyzaduje-siet>
                    <i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>Odhlásiť sa
                </a>
            </div>
        </div>
    </div>
</div>
<!-- ================= MODAL: Uvarené (dátum + voliteľná fotka) ================= -->
<div class="modal fade" id="madeModal" tabindex="-1" aria-hidden="true" aria-labelledby="madeModalTitle">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="madeForm" novalidate>
                <div class="modal-header">
                    <h5 class="modal-title" id="madeModalTitle"><i class="bi bi-check2-circle me-2 text-success"></i>Uvarené</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zavrieť"></button>
                </div>
                <div class="modal-body">
                    <p class="fw-semibold mb-3" id="madeRecipeName"></p>
                    <input type="hidden" name="recipe_id" value="">
                    <div class="mb-3">
                        <label class="form-label small text-secondary" for="madeDate">Kedy sa varilo</label>
                        <input type="date" name="made_date" id="madeDate" class="form-control" required>
                    </div>
                    <?php if ($photosEnabled): ?>
                    <div class="mb-1">
                        <label class="form-label small text-secondary" for="madeImageInput">Fotka jedla <span class="opacity-50">(nepovinné)</span></label>
                        <div class="photo-pick">
                            <div id="madeImagePickWrap" class="photo-pick-preview d-none">
                                <img id="madeImagePick" src="" alt="Náhľad fotky">
                                <button type="button" class="btn btn-sm photo-pick-clear" id="madeImagePickClear"
                                        aria-label="Zrušiť výber fotky" title="Zrušiť výber"><i class="bi bi-x-lg"></i></button>
                            </div>
                            <input type="file" name="recipe_image" id="madeImageInput" class="form-control"
                                   accept="image/jpeg,image/png,image/webp" aria-describedby="madeImageHelp">
                        </div>
                        <div class="form-text" id="madeImageHelp">Pridá sa do galérie receptu s týmto dátumom, staršie fotky ostanú.</div>
                    </div>
                    <?php endif; ?>
                    <div class="alert alert-danger py-2 small d-none mt-3 mb-0" id="madeFormError"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Zrušiť</button>
                    <button type="submit" class="btn btn-success btn-sm fw-semibold" id="madeFormSubmit">
                        <i class="bi bi-check2 me-1"></i>Uložiť
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<div class="modal fade" id="historyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-md-down">
        <div class="modal-content">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title"><i class="bi bi-clock-history me-2 text-info"></i>História: <span
                            id="historyRecipeName" class="text-body fw-bold"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="historyModalBody">
                <div class="text-center text-secondary py-3">Načítavam históriu...</div>
            </div>
            <div class="modal-footer border-top-0 pt-0">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Zavrieť</button>
            </div>
        </div>
    </div>
</div>

<!-- ================= MODAL: Pomenovanie nového passkey ================= -->
<div class="modal fade" id="passkeyLabelModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-fingerprint me-2 text-primary"></i>Nastaviť biometriu</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label small text-secondary" for="passkeyLabelInput">Ako chceš tento kľúč
                    pomenovať?</label>
                <input type="text" id="passkeyLabelInput" class="form-control" value="Telefón"
                       placeholder="napr. Telefón — Face ID">
                <div class="alert alert-danger py-2 small mt-2 d-none" id="passkeyLabelError"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Zrušiť
                </button>
                <button type="button" class="btn btn-primary btn-sm fw-semibold" id="passkeyLabelConfirmBtn">
                    <i class="bi bi-fingerprint me-1"></i>Pokračovať
                </button>
            </div>
        </div>
    </div>
</div>
<!-- ================= MODAL: Pomoc s notifikáciami ================= -->
<div class="modal fade" id="pushHelpModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-bell-fill me-2 text-primary"></i><span id="pushHelpTitle"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="pushHelpBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary btn-sm" data-bs-dismiss="modal">Rozumiem</button>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<style>
    /* Vynútené štýly priamo v HTML, aby sme obišli cache prehliadača */
    html[data-bs-theme="dark"] .form-select,
    html[data-bs-theme="dark"] .form-select option {
        background-color: #2b3035 !important;
        color: #ffffff !important;
    }

    .recipe-name-link {
        cursor: pointer;
    }

    .recipe-name-link:hover {
        text-decoration: underline;
    }

    .rating-stars .rating-star {
        cursor: pointer;
        font-size: 1.05rem;
        color: #adb5bd;
        margin-right: 1px;
        transition: color .1s ease;
    }

    .rating-stars .rating-star.bi-star-fill {
        color: #ffc107;
    }

    #viewRecipeShareBtn:hover {
        background-color: #0b5ed7 !important;
        border-color: #0a58ca !important;
    }
</style>
<style>
    .recipe-row {
        cursor: pointer;
    }

    .recipe-row:hover {
        background-color: rgba(var(--bs-primary-rgb), 0.05);
    }
</style>
<script src="webauthn-client.js?v=21"></script>
<script>
function setupPasskeyFromApp() {
    // POZOR: WebAuthn (najmä na iPhone/Safari) vyžaduje, aby dokument mal
    // fokus PRESNE v momente volania navigator.credentials.create() a aby
    // išlo o priamu reakciu na klik. Natívny prompt()/alert() tesne pred tým
    // vie fokus "rozhodiť" a Safari potom hlási "The document is not focused" —
    // preto sa meno kľúča pýtame cez bežný Bootstrap modal, nie prompt().
    const input = document.getElementById('passkeyLabelInput');
    const errBox = document.getElementById('passkeyLabelError');
    errBox.classList.add('d-none');
    input.value = 'Telefón';
    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('passkeyLabelModal'));
    modal.show();
}

document.getElementById('passkeyLabelConfirmBtn').addEventListener('click', async () => {
    const errBox = document.getElementById('passkeyLabelError');
    const label = document.getElementById('passkeyLabelInput').value.trim() || 'Telefón';
    errBox.classList.add('d-none');
    try {
        // Volanie WebAuthn priamo v tomto click handleri = čerstvé
        // používateľské gesto, dokument je fokusovaný.
        await registerPasskey(label);
        bootstrap.Modal.getOrCreateInstance(document.getElementById('passkeyLabelModal')).hide();
        showToast('Biometria bola úspešne pridaná. Nabudúce sa môžeš prihlásiť odtlačkom / Face ID priamo z prihlasovacej stránky.');
    } catch (e) {
        errBox.textContent = 'Nepodarilo sa nastaviť biometriu: ' + (e.message || e);
        errBox.classList.remove('d-none');
    }
});
</script>
<script>
    window.APP_VAPID_PUBLIC_KEY = <?= json_encode(VAPID_PUBLIC_KEY) ?>;
    window.APP_OPEN_RECIPE = <?= (int) $openRecipeId ?>;
    window.APP_SUROVINY = <?= json_encode($allSuroviny, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    window.APP_JEDNOTKY = <?= json_encode(SUROVINA_JEDNOTKY, JSON_UNESCAPED_UNICODE) ?>;
    window.APP_IS_ADMIN = <?= is_admin() ? 'true' : 'false' ?>;
    window.APP_AI = <?= $aiEnabled ? 'true' : 'false' ?>; // recept z fotky (includes/ai.php)
</script>
<script src="suroviny.js?v=28"></script>
<script src="script.js?v=28"></script>
</body>
</html>
<?php
// Pripomienka „dlho ste nevarili" — až po odoslaní stránky, aby návštevník nečakal
// na push službu. Väčšinu dní je to len jeden rýchly dotaz do databázy.
session_write_close();
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
send_due_recipe_reminder($pdo);
