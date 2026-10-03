<?php
/**
 * install.php — sprievodca prvou inštaláciou appky.
 *
 * Prevedie nového správcu tromi krokmi:
 *   1. kontrola servera (verzia PHP, rozšírenia, knižnice, práva na zápis),
 *   2. súbor includes/.env (pripraví jeho obsah vrátane vygenerovaných kľúčov),
 *   3. databáza a prvý administrátorský účet.
 *
 * BEZPEČNOSŤ: administrátora sa dá vytvoriť len vtedy, keď je tabuľka users
 * prázdna. Akonáhle existuje aspoň jeden účet, táto stránka už nič nerobí.
 * Aj tak ju po inštalácii zo servera ZMAŽ.
 */

require_once __DIR__ . '/includes/env.php';
require_once __DIR__ . '/includes/webpush.php';   // len kvôli base64url pomocníkom
load_env(__DIR__ . '/includes/.env');

function h(?string $text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

/** Nový pár VAPID kľúčov pre upozornenia (krivka P-256), v tvare, aký čaká .env. */
function install_generate_vapid(): ?array
{
    $key = @openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $details = $key ? openssl_pkey_get_details($key) : false;
    if (!$details || empty($details['ec']['d'])) {
        return null;
    }
    $pad = static fn(string $bytes): string => str_pad($bytes, 32, "\x00", STR_PAD_LEFT);
    return [
        'public'  => webpush_b64url_encode("\x04" . $pad($details['ec']['x']) . $pad($details['ec']['y'])),
        'private' => webpush_b64url_encode($pad($details['ec']['d'])),
    ];
}

/* ------------------------------------------------------------------ */
/* Krok 1: kontrola servera                                             */
/* ------------------------------------------------------------------ */

$checks = [];
$checks[] = ['PHP 8.4.1 alebo novšie', PHP_VERSION_ID >= 80401, 'Na serveri je PHP ' . PHP_VERSION . '. Knižnica na biometriu vyžaduje 8.4.1+.'];
foreach (['pdo_mysql' => 'databáza', 'openssl' => 'upozornenia a biometria', 'curl' => 'odosielanie upozornení',
          'mbstring' => 'práca s textom', 'gd' => 'zmenšovanie fotiek', 'json' => 'dáta pre prehliadač'] as $ext => $why) {
    $checks[] = ["Rozšírenie PHP „{$ext}“ ({$why})", extension_loaded($ext), "Zapni rozšírenie {$ext} v nastavení PHP."];
}
$checks[] = ['Knižnice vo vendor/ (composer install)', is_file(__DIR__ . '/vendor/autoload.php'),
    'V priečinku appky spusti „composer install“ a nahraj vzniknutý priečinok vendor/.'];
$uploadsDir = __DIR__ . '/uploads/recipes';
$checks[] = ['Zápis do uploads/recipes/ (fotky receptov)', is_dir($uploadsDir) && is_writable($uploadsDir),
    'Vytvor priečinok uploads/recipes a povoľ doň zápis (práva 755 alebo 775).'];
$checks[] = ['Zápis do includes/ (súbor s chybami debug-log.txt)', is_writable(__DIR__ . '/includes'),
    'Nie je to povinné — bez zápisu pôjdu chyby do štandardného logu PHP.', true];

$serverOk = true;
foreach ($checks as $check) {
    if (!$check[1] && empty($check[3])) {
        $serverOk = false;
    }
}

/* ------------------------------------------------------------------ */
/* Krok 2: includes/.env                                                */
/* ------------------------------------------------------------------ */

$envKeys = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'VAPID_PUBLIC_KEY', 'VAPID_PRIVATE_KEY', 'VAPID_SUBJECT', 'APP_HOST'];
$envMissing = array_values(array_filter($envKeys, static fn(string $k): bool => getenv($k) === false || getenv($k) === ''));
$envOk = !$envMissing;

$envTemplate = '';
if (!$envOk) {
    $host = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'example.com'));
    $vapid = install_generate_vapid();
    // Už vyplnené hodnoty neukazujeme (stránka je verejná) — ponúkame len nové/prázdne.
    $envTemplate = implode("\n", [
        '# Databáza',
        'DB_HOST=localhost',
        'DB_NAME=',
        'DB_USER=',
        'DB_PASS=',
        'DB_CHARSET=utf8mb4',
        '',
        '# Doména appky bez https:// a bez lomky na konci',
        'APP_HOST=' . $host,
        '',
        '# Kľúče pre upozornenia (vygenerované práve teraz, len pre teba)',
        'VAPID_PUBLIC_KEY=' . ($vapid['public'] ?? 'SEM_VLOZ_VEREJNY_KLUC'),
        'VAPID_PRIVATE_KEY=' . ($vapid['private'] ?? 'SEM_VLOZ_SUKROMNY_KLUC'),
        '# Kontakt na správcu appky (vyžadujú ho služby, ktoré upozornenia doručujú)',
        'VAPID_SUBJECT=mailto:tvoj@email.sk',
        '',
        '# Tajný kľúč pre cron.php (pripomienky „dlho ste nevarili“)',
        'CRON_KEY=' . bin2hex(random_bytes(16)),
        '',
        '# 1 = chyby sa zobrazia priamo na obrazovke (len pri hľadaní problému)',
        'APP_DEBUG=0',
    ]) . "\n";
}

/* ------------------------------------------------------------------ */
/* Krok 3: databáza a prvý administrátor                                */
/* ------------------------------------------------------------------ */

$dbError = null;       // text chyby pripojenia alebo chýbajúcich tabuliek
$usersCount = null;    // počet účtov (null = nevieme zistiť)
$formError = null;
$created = false;
$pdo = null;

if ($serverOk && $envOk) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    try {
        $pdo = new PDO(
            'mysql:host=' . getenv('DB_HOST') . ';dbname=' . getenv('DB_NAME') . ';charset=' . (getenv('DB_CHARSET') ?: 'utf8mb4'),
            getenv('DB_USER'),
            getenv('DB_PASS'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    } catch (Throwable $e) {
        $dbError = 'K databáze sa nepodarilo pripojiť. Skontroluj DB_HOST, DB_NAME, DB_USER a DB_PASS v includes/.env.';
    }

    if ($pdo) {
        try {
            $usersCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            // Overíme aj tabuľku z konca inštalačného súboru — či import prebehol celý.
            $pdo->query('SELECT 1 FROM nakupny_zoznam LIMIT 1');
            $pdo->query('SELECT 1 FROM suroviny LIMIT 1');
        } catch (Throwable $e) {
            $usersCount = null;
            $dbError = 'Pripojenie funguje, ale v databáze chýbajú tabuľky. Importuj súbor sql/instalacia.sql (napr. cez phpMyAdmin) a obnov túto stránku.';
        }
    }

    if ($usersCount === 0) {
        if (empty($_SESSION['install_csrf'])) {
            $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $username = trim((string) ($_POST['username'] ?? ''));
            $fullName = trim((string) ($_POST['full_name'] ?? '')) ?: $username;
            $password = (string) ($_POST['password'] ?? '');

            if (!hash_equals($_SESSION['install_csrf'], (string) ($_POST['csrf_token'] ?? ''))) {
                $formError = 'Vypršala platnosť formulára, skús to znova.';
            } elseif (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) {
                $formError = 'Používateľské meno môže mať 3 až 64 znakov: písmená bez diakritiky, číslice, bodka, pomlčka, podčiarkovník.';
            } elseif (mb_strlen($password) < 8) {
                $formError = 'Heslo musí mať aspoň 8 znakov.';
            } elseif ($password !== (string) ($_POST['password2'] ?? '')) {
                $formError = 'Heslá sa nezhodujú.';
            } else {
                // Podmienka v SQL: účet vznikne len vtedy, keď je tabuľka naozaj stále prázdna.
                $stmt = $pdo->prepare(
                    "INSERT INTO users (username, password_hash, role, full_name)
                     SELECT ?, ?, 'admin', ? FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users)"
                );
                $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), mb_substr($fullName, 0, 128)]);
                $created = $stmt->rowCount() === 1;
                $usersCount = 1;
                unset($_SESSION['install_csrf']);
            }
        }
    }
}

$done = $usersCount !== null && $usersCount > 0;
?>
<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="UTF-8">
    <script src="theme.js"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Inštalácia — Evidencia receptov</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body class="p-3 p-md-4">
<div class="container" style="max-width:720px;">
    <h1 class="h4 mb-4"><i class="bi bi-egg-fried text-warning me-2"></i>Inštalácia appky Evidencia receptov</h1>

<?php if ($done): ?>

    <div class="alert alert-success">
        <strong><?= $created ? 'Administrátorský účet je vytvorený.' : 'Inštalácia je dokončená.' ?></strong>
        Appka má aspoň jeden účet, takže táto stránka už nič nerobí.
    </div>
    <p><strong>Teraz zmaž súbor <code>install.php</code> zo servera</strong> a prihlás sa.</p>
    <a href="login.php" class="btn btn-primary fw-semibold"><i class="bi bi-box-arrow-in-right me-1"></i>Prejsť na prihlásenie</a>

<?php else: ?>

    <div class="card mb-3">
        <div class="card-header fw-semibold">1. Kontrola servera</div>
        <ul class="list-group list-group-flush">
            <?php foreach ($checks as $check): ?>
                <li class="list-group-item">
                    <?php if ($check[1]): ?>
                        <i class="bi bi-check-circle-fill text-success me-2"></i><?= h($check[0]) ?>
                    <?php else: ?>
                        <i class="bi <?= empty($check[3]) ? 'bi-x-circle-fill text-danger' : 'bi-exclamation-triangle-fill text-warning' ?> me-2"></i><?= h($check[0]) ?>
                        <div class="small text-secondary ms-4"><?= h($check[2]) ?></div>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <div class="card mb-3">
        <div class="card-header fw-semibold">2. Nastavenie v súbore includes/.env</div>
        <div class="card-body">
            <?php if (!$serverOk): ?>
                <p class="text-secondary mb-0">Najprv oprav položky označené červenou v kroku 1.</p>
            <?php elseif ($envOk): ?>
                <p class="mb-0"><i class="bi bi-check-circle-fill text-success me-2"></i>Súbor <code>includes/.env</code> obsahuje všetky povinné hodnoty.</p>
            <?php else: ?>
                <p>V <code>includes/.env</code> chýba: <strong><?= h(implode(', ', $envMissing)) ?></strong>.</p>
                <p>Vytvor súbor <code>includes/.env</code> s týmto obsahom, doplň údaje k databáze a svoj e-mail,
                   nahraj ho na server a obnov túto stránku. Ak už súbor máš, doplň doň len chýbajúce riadky.</p>
                <textarea class="form-control font-monospace small" rows="20" readonly
                          onclick="this.select()" aria-label="Obsah súboru .env"><?= h($envTemplate) ?></textarea>
                <div class="form-text">Kľúče VAPID a CRON_KEY sa pri každom obnovení stránky vygenerujú nové. Použi tie, ktoré si skopíroval, a už ich nemeň —
                    po zmene VAPID kľúčov by si všetci museli upozornenia povoliť znova.</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header fw-semibold">3. Databáza a prvý administrátor</div>
        <div class="card-body">
            <?php if (!$serverOk || !$envOk): ?>
                <p class="text-secondary mb-0">Tento krok sa sprístupní po dokončení krokov 1 a 2.</p>
            <?php elseif ($dbError): ?>
                <div class="alert alert-danger mb-0"><?= h($dbError) ?></div>
            <?php else: ?>
                <p><i class="bi bi-check-circle-fill text-success me-2"></i>Databáza je pripravená a zatiaľ v nej nie je žiadny účet. Vytvor prvého administrátora:</p>
                <?php if ($formError): ?><div class="alert alert-danger py-2"><?= h($formError) ?></div><?php endif; ?>
                <form method="post" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['install_csrf'] ?? '') ?>">
                    <div class="mb-3">
                        <label class="form-label" for="instUser">Používateľské meno</label>
                        <input type="text" class="form-control" id="instUser" name="username" required minlength="3" maxlength="64"
                               value="<?= h($_POST['username'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="instName">Celé meno <span class="text-secondary">(zobrazuje sa pri receptoch)</span></label>
                        <input type="text" class="form-control" id="instName" name="full_name" maxlength="128"
                               value="<?= h($_POST['full_name'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="instPass">Heslo <span class="text-secondary">(aspoň 8 znakov)</span></label>
                        <input type="password" class="form-control" id="instPass" name="password" required minlength="8" autocomplete="new-password">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="instPass2">Heslo ešte raz</label>
                        <input type="password" class="form-control" id="instPass2" name="password2" required minlength="8" autocomplete="new-password">
                    </div>
                    <button type="submit" class="btn btn-primary fw-semibold"><i class="bi bi-person-plus me-1"></i>Vytvoriť administrátora</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

<?php endif; ?>
</div>
</body>
</html>
