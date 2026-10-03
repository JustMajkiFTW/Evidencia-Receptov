<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

$currentUser = current_user();
$message = null;
$error = null;

function generate_temp_password(): string
{
    return bin2hex(random_bytes(5)); // 10-znakové čitateľné heslo
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Vypršala platnosť formulára, skús to znova.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create_user') {
            $username = trim($_POST['username'] ?? '');
            $fullName = trim($_POST['full_name'] ?? '') ?: $username;
            $role = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
            $password = trim($_POST['password'] ?? '') ?: generate_temp_password();

            if ($username === '') {
                $error = 'Meno je povinné.';
            } else {
                $check = $pdo->prepare('SELECT id FROM users WHERE username = ?');
                $check->execute([$username]);
                if ($check->fetch()) {
                    $error = 'Toto používateľské meno už existuje.';
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO users (username, password_hash, role, full_name) VALUES (?, ?, ?, ?)'
                    );
                    $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role, $fullName]);
                    $message = "Používateľ „{$username}“ vytvorený. Heslo: {$password} (pošli mu ho, appka ho už nikde nezobrazí).";
                }
            }
        } elseif ($action === 'reset_password') {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $newPassword = trim($_POST['new_password'] ?? '') ?: generate_temp_password();
            $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
            // Nové heslo odhlási používateľa zo všetkých zapamätaných zariadení.
            remember_forget_user($pdo, $userId);
            $message = "Nové heslo nastavené: {$newPassword}";
        } elseif ($action === 'delete_user') {
            $userId = (int) ($_POST['user_id'] ?? 0);
            if ($userId === $currentUser['id']) {
                $error = 'Nemôžeš zmazať sám seba.';
            } else {
                $countStmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'");
                $adminCount = (int) $countStmt->fetchColumn();
                $roleStmt = $pdo->prepare('SELECT role FROM users WHERE id = ?');
                $roleStmt->execute([$userId]);
                $targetRole = $roleStmt->fetchColumn();
                if ($targetRole === 'admin' && $adminCount <= 1) {
                    $error = 'Nemôžeš zmazať posledného administrátora.';
                } else {
                    $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
                    $stmt->execute([$userId]);
                    $message = 'Používateľ zmazaný.';
                }
            }
        }
    }
}

$users = $pdo->query('SELECT id, username, full_name, role, created_at FROM users ORDER BY id')->fetchAll();

$credCounts = [];
foreach ($pdo->query('SELECT user_id, COUNT(*) c FROM webauthn_credentials GROUP BY user_id') as $row) {
    $credCounts[$row['user_id']] = (int) $row['c'];
}

$csrfToken = csrf_token();
?>
<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="UTF-8">
    <script src="../theme.js"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Používatelia — Evidencia receptov</title>
    <link rel="manifest" href="../manifest.json?v=4">
    <meta name="theme-color" content="#f59e0b">
    <link rel="icon" type="image/png" sizes="192x192" href="../app-icons/icon-192.png?v=4">
    <link rel="apple-touch-icon" sizes="180x180" href="../app-icons/apple-touch-icon.png?v=4">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../style.css">
</head>
<body class="p-3 p-md-4">
<div class="container" style="max-width:900px;">
    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-people-fill me-2"></i>Používatelia</h1>
        <div class="d-flex gap-2">
            <button id="themeToggle" type="button" class="btn btn-outline-secondary btn-sm" title="Zmeniť motív" aria-label="Zmeniť motív">
                <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
            </button>
            <a href="prevod-surovin.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left-right me-1"></i>Prevod surovín</a>
            <a href="../index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Späť</a>
        </div>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="card mb-4">
        <div class="card-header fw-semibold">Pridať nového používateľa</div>
        <div class="card-body">
            <form method="post" class="row g-2">
                <input type="hidden" name="action" value="create_user">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <div class="col-md-3">
                    <input type="text" name="username" class="form-control" placeholder="Používateľské meno" required>
                </div>
                <div class="col-md-3">
                    <input type="text" name="full_name" class="form-control" placeholder="Celé meno (nepovinné)">
                </div>
                <div class="col-md-2">
                    <select name="role" class="form-select">
                        <option value="user">user</option>
                        <option value="admin">admin</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <input type="text" name="password" class="form-control" placeholder="Heslo (prázdne = vygeneruje sa)">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg me-1"></i>Vytvoriť</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header fw-semibold">Existujúci používatelia</div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="text-nowrap">
                    <tr>
                        <th>Meno</th><th>Celé meno</th><th>Rola</th><th>Biometria</th><th>Vytvorený</th><th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td><?= htmlspecialchars($u['username']) ?></td>
                        <td><?= htmlspecialchars($u['full_name']) ?></td>
                        <td><span class="badge <?= $u['role'] === 'admin' ? 'text-bg-warning' : 'text-bg-primary' ?>"><?= htmlspecialchars($u['role']) ?></span></td>
                        <td><?= (int) ($credCounts[$u['id']] ?? 0) ?>×</td>
                        <td class="small text-secondary"><?= htmlspecialchars($u['created_at']) ?></td>
                        <td class="text-end">
                            <div class="d-flex gap-1 justify-content-end">
                                <form method="post" class="d-flex gap-1" onsubmit="return promptNewPassword(this);">
                                    <input type="hidden" name="action" value="reset_password">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                    <input type="hidden" name="new_password" class="new-password-input">
                                    <button type="submit" class="btn btn-outline-secondary btn-sm" title="Nastaviť nové heslo">
                                        <i class="bi bi-key"></i>
                                    </button>
                                </form>
                                <?php if ((int) $u['id'] !== $currentUser['id']): ?>
                                <form method="post" onsubmit="return confirm('Naozaj zmazať tohto používateľa?');">
                                    <input type="hidden" name="action" value="delete_user">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm" title="Zmazať">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
function promptNewPassword(form) {
    const pw = prompt('Nové heslo (prázdne = appka vygeneruje náhodné):', '');
    if (pw === null) return false; // zrušené
    form.querySelector('.new-password-input').value = pw;
    return true;
}
</script>
</body>
</html>
