<?php
// Session štartuje includes/config.php — až po nastavení parametrov cookie.
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

// Číslo receptu z upozornenia (login.php?recept=12) — po prihlásení sa otvorí práve ten recept.
$openRecipeId = (isset($_REQUEST['recept']) && ctype_digit((string) $_REQUEST['recept'])) ? (int) $_REQUEST['recept'] : 0;
$afterLoginUrl = 'index.php' . ($openRecipeId > 0 ? '?recept=' . $openRecipeId : '');

// Zariadenie so „Zostať prihlásený" sem nemá prečo chodiť — rovno ho pustíme do appky.
remember_restore($pdo);

if (is_logged_in()) {
    header('Location: ' . $afterLoginUrl);
    exit;
}

$error = null;
$timeout = isset($_GET['timeout']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'password_login') {

    // 1. Kontrola CSRF tokenu
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Vypršala platnosť formulára. Obnov stránku a skús to znova.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');

        // 2. Pokus o prihlásenie
        $user = attempt_password_login($pdo, $username, $password);

        if (!$user) {
            $error = 'Nesprávne používateľské meno alebo heslo.';
        } else {
            // 3. Úspešné prihlásenie -> presmerovanie
            start_authenticated_session($user);
            if (!empty($_POST['remember'])) {
                remember_create($pdo, (int) $user['id']);
            }
            header('Location: ' . $afterLoginUrl);
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="UTF-8">
    <script src="theme.js"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prihlásenie — Evidencia receptov</title>
    <link rel="manifest" href="manifest.json?v=4">
    <meta name="theme-color" content="#f59e0b">
    <link rel="icon" type="image/png" sizes="192x192" href="app-icons/icon-192.png?v=4">
    <link rel="apple-touch-icon" sizes="180x180" href="app-icons/apple-touch-icon.png?v=4">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Recepty">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body class="d-flex align-items-center justify-content-center" style="min-height:100vh;">
<button id="themeToggle" type="button" class="btn btn-outline-secondary btn-sm theme-toggle-floating"
        title="Zmeniť motív" aria-label="Zmeniť motív">
    <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
</button>
<div class="card app-card shadow-sm mx-3" style="max-width:380px; width:100%;">
    <div class="card-body p-4">
        <div class="text-center mb-3">
            <i class="bi bi-egg-fried fs-1 text-warning"></i>
            <h1 class="h4 mt-2 mb-0">Evidencia receptov</h1>
        </div>

        <?php if ($timeout): ?>
            <div class="alert alert-warning py-2">Boli ste odhlásený po dlhšej nečinnosti.</div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <div id="webauthnError" class="alert alert-danger py-2 d-none"></div>

        <form method="post" autocomplete="on">
            <input type="hidden" name="action" value="password_login">
            <?php if ($openRecipeId > 0): ?><input type="hidden" name="recept" value="<?= $openRecipeId ?>"><?php endif; ?>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <div class="mb-3">
                <label class="form-label">Používateľské meno</label>
                <input type="text" name="username" id="loginUsername" class="form-control" required autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label">Heslo</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="remember" value="1" id="loginRemember" checked>
                <label class="form-check-label" for="loginRemember">Zostať prihlásený na tomto zariadení</label>
            </div>
            <button type="submit" class="btn btn-primary w-100 fw-semibold">
                <i class="bi bi-box-arrow-in-right me-1"></i>Prihlásiť sa
            </button>
        </form>

        <div class="d-flex align-items-center my-3 text-secondary small">
            <hr class="flex-grow-1"><span class="px-2">alebo</span><hr class="flex-grow-1">
        </div>

        <button type="button" id="passkeyBtn" class="btn btn-outline-primary w-100 fw-semibold">
            <i class="bi bi-fingerprint me-1"></i>Prihlásiť sa odtlačkom / Face ID
        </button>
    </div>
</div>

<script src="webauthn-client.js?v=19"></script>
<script>
(function () {
    const errBox = document.getElementById('webauthnError');
    const usernameInput = document.getElementById('loginUsername');
    const passkeyBtn = document.getElementById('passkeyBtn');
    const afterLoginUrl = <?= json_encode($afterLoginUrl) ?>;
    let running = false;

    /**
     * silent = automatický pokus pri načítaní stránky: keď ho používateľ zruší
     * alebo ho prehliadač nepovolí, nič nevypisujeme — stránka ostane ako bežné prihlásenie.
     */
    async function passkeyLogin(username, silent) {
        if (running) return;
        running = true;
        errBox.classList.add('d-none');
        passkeyBtn.disabled = true;
        try {
            // „Zostať prihlásený" platí aj pre prihlásenie odtlačkom / Face ID.
            await loginWithPasskey(username, document.getElementById('loginRemember').checked);
            window.location.href = afterLoginUrl;
        } catch (e) {
            if (!silent) {
                errBox.textContent = e.message || 'Prihlásenie zlyhalo.';
                errBox.classList.remove('d-none');
            }
            passkeyBtn.disabled = false;
            running = false;
        }
    }

    passkeyBtn.addEventListener('click', () => passkeyLogin(usernameInput.value.trim(), false));

    // Zariadenie, na ktorom je uložená biometria: meno predvyplníme a odtlačok /
    // Face ID vyvoláme hneď, bez klikania. Po nesprávnom hesle (odoslaný formulár)
    // to nerobíme, aby sa výzva neplietla do písania hesla.
    const savedUser = getPasskeyUser();
    const formWasSubmitted = <?= json_encode($_SERVER['REQUEST_METHOD'] === 'POST') ?>;
    if (savedUser && window.PublicKeyCredential) {
        if (!usernameInput.value) usernameInput.value = savedUser;
        if (!formWasSubmitted) passkeyLogin(savedUser, true);
    }
})();
</script>
</body>
</html>
