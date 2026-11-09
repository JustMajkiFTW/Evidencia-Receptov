<?php
/**
 * Správa — Používatelia (len admin): nový účet, nové heslo a zmazanie.
 * Kategórie receptov sú od verzie 2.1 na vlastnej stránke kategorie.php.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/sprava.php';
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

/** „bez odtlačku", „odtlačok na 1 zariadení", „odtlačok na 3 zariadeniach" */
function odtlacok_text(int $pocet): string
{
    return $pocet === 0 ? 'bez odtlačku' : 'odtlačok na ' . $pocet . ' ' . ($pocet === 1 ? 'zariadení' : 'zariadeniach');
}

/** Iniciály do krúžku: „Eva Čambáliková" → „EČ", „dedo" → „D". */
function inicialy(string $meno): string
{
    $vysledok = '';
    foreach (preg_split('/\s+/u', trim($meno)) ?: [] as $slovo) {
        if ($slovo !== '' && mb_strlen($vysledok) < 2) {
            $vysledok .= mb_strtoupper(mb_substr($slovo, 0, 1));
        }
    }
    return $vysledok;
}

$csrfToken = csrf_token();
sprava_zaciatok($pdo, 'pouzivatelia', 'Používatelia');
?>
    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="sprava-mriezka">
        <section aria-labelledby="pouzivateliaNadpis">
            <h2 class="sprava-nadpis" id="pouzivateliaNadpis">Účty v appke <span class="sprava-pocet"><?= count($users) ?></span></h2>
            <ul class="sprava-zoznam">
                <?php foreach ($users as $u):
                    $meno = trim((string) $u['full_name']) ?: $u['username'];
                    $jaSam = (int) $u['id'] === $currentUser['id'];
                    $vytvoreny = strtotime((string) $u['created_at']); ?>
                    <li class="sprava-polozka sprava-pouzivatel">
                        <div class="sprava-pouzivatel-hlava">
                            <span class="sprava-inicialy" aria-hidden="true"><?= htmlspecialchars(inicialy($meno)) ?></span>
                            <div class="sprava-pouzivatel-info">
                                <div class="sprava-pouzivatel-meno">
                                    <?= htmlspecialchars($meno) ?>
                                    <span class="sprava-odznak<?= $u['role'] === 'admin' ? ' je-admin' : '' ?>"><?= $u['role'] === 'admin' ? 'Admin' : 'Používateľ' ?></span>
                                    <?php if ($jaSam): ?><span class="sprava-odznak je-ty">Ty</span><?php endif; ?>
                                </div>
                                <div class="sprava-pouzivatel-detail">
                                    Prihlasovacie meno <strong><?= htmlspecialchars($u['username']) ?></strong>
                                    · <?= odtlacok_text((int) ($credCounts[$u['id']] ?? 0)) ?><?= $vytvoreny ? ' · od ' . str_replace(' ', "\u{00A0}", date('j. n. Y', $vytvoreny)) : '' ?>
                                </div>
                            </div>
                        </div>
                        <div class="sprava-akcie">
                            <details class="sprava-rozbal">
                                <summary class="btn sprava-tlacidlo sprava-vedlajsie"><i class="bi bi-key" aria-hidden="true"></i>Nové heslo</summary>
                                <form method="post" class="sprava-rozbal-form">
                                    <input type="hidden" name="action" value="reset_password">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                    <label class="form-label" for="heslo-<?= (int) $u['id'] ?>">Nové heslo pre <?= htmlspecialchars($meno) ?></label>
                                    <input type="text" name="new_password" id="heslo-<?= (int) $u['id'] ?>" class="form-control"
                                           autocomplete="new-password" autocapitalize="none" spellcheck="false"
                                           placeholder="Prázdne = appka vygeneruje">
                                    <div class="sprava-pomoc">Používateľ sa odhlási zo všetkých zariadení a prihlási sa novým heslom.</div>
                                    <button type="submit" class="btn sprava-tlacidlo sprava-hlavne"><i class="bi bi-check-lg" aria-hidden="true"></i>Nastaviť heslo</button>
                                </form>
                            </details>
                            <?php if (!$jaSam): ?>
                            <form method="post" onsubmit="return confirm('Naozaj zmazať používateľa <?= htmlspecialchars(addslashes($meno), ENT_QUOTES) ?>?');">
                                <input type="hidden" name="action" value="delete_user">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                <button type="submit" class="btn sprava-tlacidlo sprava-zmazat" title="Zmazať používateľa">
                                    <i class="bi bi-trash" aria-hidden="true"></i><span class="sprava-skryt-pc">Zmazať</span>
                                    <span class="visually-hidden"> používateľa <?= htmlspecialchars($meno) ?></span>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <section class="sprava-karta" aria-labelledby="novyNadpis">
            <h2 class="sprava-karta-nadpis" id="novyNadpis"><i class="bi bi-person-plus" aria-hidden="true"></i>Pridať používateľa</h2>
            <p class="sprava-popis">Verejná registrácia nie je, účty vytváraš tu. Heslo mu potom pošli, appka ho ukáže len raz.</p>
            <form method="post" class="sprava-formular">
                <input type="hidden" name="action" value="create_user">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <div>
                    <label class="form-label" for="novyMeno">Prihlasovacie meno</label>
                    <input type="text" name="username" id="novyMeno" class="form-control" required
                           autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="napr. mama">
                </div>
                <div>
                    <label class="form-label" for="novyCele">Celé meno <span class="sprava-nepovinne">(nepovinné)</span></label>
                    <input type="text" name="full_name" id="novyCele" class="form-control" autocomplete="off" placeholder="napr. Eva Nováková">
                </div>
                <div>
                    <label class="form-label" for="novyRola">Rola</label>
                    <select name="role" id="novyRola" class="form-select">
                        <option value="user">Používateľ</option>
                        <option value="admin">Admin (aj Správa)</option>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="novyHeslo">Heslo <span class="sprava-nepovinne">(nepovinné)</span></label>
                    <input type="text" name="password" id="novyHeslo" class="form-control" autocomplete="new-password"
                           autocapitalize="none" spellcheck="false" placeholder="Prázdne = appka vygeneruje">
                </div>
                <button type="submit" class="btn sprava-tlacidlo sprava-hlavne sprava-formular-odoslat">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i>Vytvoriť používateľa
                </button>
            </form>
        </section>
    </div>
<?php sprava_koniec($pdo); ?>
</body>
</html>
