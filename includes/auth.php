<?php
/**
 * auth.php — vlastné prihlasovanie appky (nezávislé).
 *
 * Appka si teraz sama spravuje session aj heslá. Registrácia nových
 * účtov ide LEN cez admin panel (admin/index.php) — žiadna verejná
 * registrácia neexistuje.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ======================================================================
   Prihlásený používateľ — čítané z VLASTNEJ session appky
   ====================================================================== */

/** Vráti údaje o prihlásenom používateľovi, alebo null. */
function current_user(): ?array
{
    if (empty($_SESSION['auth_user_id'])) {
        return null;
    }
    return [
        'id'        => (int) $_SESSION['auth_user_id'],
        'username'  => $_SESSION['auth_username'] ?? null,
        'role'      => $_SESSION['auth_role'] ?? 'user',
        'full_name' => $_SESSION['auth_full_name'] ?? ($_SESSION['auth_username'] ?? 'Užívateľ'),
    ];
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function is_admin(): bool
{
    $u = current_user();
    return $u !== null && $u['role'] === 'admin';
}

/**
 * Cesta k login.php z práve bežiacej stránky: 'login.php' z koreňa appky,
 * '../login.php' z podpriečinka (admin/, webauthn/). Bez toho by presmerovanie
 * z admin/ mierilo na neexistujúci admin/login.php.
 */
function login_page_url(): string
{
    $root = realpath(dirname(__DIR__));
    $script = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $depth = 0;
    if ($root !== false && $script !== false && str_starts_with($script, $root . DIRECTORY_SEPARATOR)) {
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($script, strlen($root) + 1));
        $depth = substr_count($relative, '/');
    }
    return str_repeat('../', $depth) . 'login.php';
}

/**
 * $query = parametre, ktoré sa majú po prihlásení zachovať (napr. 'recept=12',
 * aby sa po kliknutí na upozornenie otvoril správny recept aj cez prihlásenie).
 */
function require_login(string $query = ''): void
{
    // Session vypršala, ale zariadenie má platné „Zostať prihlásený" → prihlásime ho potichu.
    if (!is_logged_in() && isset($GLOBALS['pdo'])) {
        remember_restore($GLOBALS['pdo']);
    }
    if (!is_logged_in()) {
        header('Location: ' . login_page_url() . ($query !== '' ? '?' . $query : ''));
        exit;
    }
}

function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        die('Táto stránka je len pre administrátora.');
    }
}

function get_current_user_id(PDO $pdo): ?int
{
    $user = current_user();
    return $user['id'] ?? null;
}

/* ======================================================================
   Session pri prihlásení / odhlásení
   ====================================================================== */

/** Zapíše prihláseného používateľa do session (spoločné pre heslo aj WebAuthn). */
function start_authenticated_session(array $user): void
{
    // Nový session ID pri každom prihlásení — ochrana proti session fixation.
    session_regenerate_id(true);

    $_SESSION['auth_user_id']   = (int) $user['id'];
    $_SESSION['auth_username']  = $user['username'];
    $_SESSION['auth_role']      = $user['role'] ?? 'user';
    $_SESSION['auth_full_name'] = $user['full_name'] ?: $user['username'];
    $_SESSION['last_activity']  = time();
}

function logout_user(): void
{
    // Odhlásenie zruší aj „Zostať prihlásený" na tomto zariadení.
    if (isset($GLOBALS['pdo'])) {
        remember_forget($GLOBALS['pdo']);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function enforce_session_idle_timeout(string $query = ''): void
{
    if (isset($_SESSION['last_activity']) &&
        (time() - $_SESSION['last_activity']) > SESSION_IDLE_SECONDS) {
        // Zariadenie so „Zostať prihlásený" sa po nečinnosti neodhlasuje.
        if (isset($GLOBALS['pdo']) && remember_validate($GLOBALS['pdo']) !== null) {
            $_SESSION['last_activity'] = time();
            return;
        }
        logout_user();
        header('Location: ' . login_page_url() . '?timeout=1' . ($query !== '' ? '&' . $query : ''));
        exit;
    }
    $_SESSION['last_activity'] = time();
}

/* ======================================================================
   „Zostať prihlásený" — trvalé prihlásenie zariadenia na 90 dní.

   Session v PHP vydrží len krátko (hosting ju po čase zmaže), preto si
   zariadenie pri prihlásení uloží dlhodobú cookie s náhodným kľúčom:
     cookie      = selector:validator
     v databáze  = selector + SHA-256 odtlačok validatora (tabuľka auth_tokens)
   V databáze teda nie je nič, čím by sa dalo prihlásiť, keby unikla.
   Keď session vyprší, appka podľa cookie používateľa potichu prihlási znova.
   ====================================================================== */

const REMEMBER_COOKIE = 'recepty_remember';
const REMEMBER_DAYS   = 90;

function remember_request_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function remember_set_cookie(string $value, int $expires): void
{
    setcookie(REMEMBER_COOKIE, $value, [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => remember_request_is_https(),
        'httponly' => true,     // JavaScript ju nevidí
        'samesite' => 'Lax',
    ]);
}

/**
 * CSRF token odvodený od trvalého prihlásenia. Vďaka tomu ostane rovnaký aj po
 * tichom obnovení session — otvorená stránka môže ďalej ukladať bez obnovenia.
 */
function remember_csrf_token(string $validatorHash): string
{
    return hash_hmac('sha256', 'csrf-token', $validatorHash);
}

/** Po úspešnom prihlásení: zapamätá toto zariadenie na REMEMBER_DAYS dní. */
function remember_create(PDO $pdo, int $userId): void
{
    try {
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $validatorHash = hash('sha256', $validator);
        $expires = time() + REMEMBER_DAYS * 86400;

        // Pri tej príležitosti upraceme tokeny, ktorým už vypršala platnosť.
        $pdo->exec('DELETE FROM auth_tokens WHERE expires_at < NOW()');
        $pdo->prepare(
            'INSERT INTO auth_tokens (user_id, selector, validator_hash, expires_at, user_agent) VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $userId,
            $selector,
            $validatorHash,
            date('Y-m-d H:i:s', $expires),
            mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);

        remember_set_cookie($selector . ':' . $validator, $expires);
        $_COOKIE[REMEMBER_COOKIE] = $selector . ':' . $validator;
        $_SESSION['csrf_token'] = remember_csrf_token($validatorHash);
    } catch (Throwable $e) {
        // Tabuľka auth_tokens ešte neexistuje (nespustená migrácia) → bežné prihlásenie bez pamätania.
        debug_log('ZOSTAT PRIHLASENY: token sa nepodarilo vytvoriť (' . $e->getMessage() . ')');
    }
}

/**
 * Overí cookie „Zostať prihlásený". Vráti údaje používateľa (+ validator_hash
 * a id tokenu), alebo null, ak cookie chýba, je neplatná alebo vypršala.
 */
function remember_validate(PDO $pdo): ?array
{
    static $cache = [];
    $cookie = (string) ($_COOKIE[REMEMBER_COOKIE] ?? '');
    if ($cookie === '' || !preg_match('/^([0-9a-f]{24}):([0-9a-f]{64})$/', $cookie, $m)) {
        return null;
    }
    if (array_key_exists($cookie, $cache)) {
        return $cache[$cookie];
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT t.id AS token_id, t.validator_hash, u.id, u.username, u.role, u.full_name
             FROM auth_tokens t JOIN users u ON u.id = t.user_id
             WHERE t.selector = ? AND t.expires_at > NOW()'
        );
        $stmt->execute([$m[1]]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $cache[$cookie] = null;
    }

    if (!$row || !hash_equals($row['validator_hash'], hash('sha256', $m[2]))) {
        return $cache[$cookie] = null;
    }
    return $cache[$cookie] = $row;
}

/**
 * Ak používateľ nie je prihlásený, ale má platnú cookie, prihlási ho a
 * predĺži platnosť o ďalších REMEMBER_DAYS dní. Vráti true, ak sa to podarilo.
 */
function remember_restore(PDO $pdo): bool
{
    if (is_logged_in()) {
        return true;
    }
    if (!isset($_COOKIE[REMEMBER_COOKIE])) {
        return false;
    }

    $row = remember_validate($pdo);
    if ($row === null) {
        // Neplatná alebo vypršaná cookie — zmažeme ju, nech sa neposiela donekonečna.
        remember_set_cookie('', time() - 42000);
        unset($_COOKIE[REMEMBER_COOKIE]);
        return false;
    }

    start_authenticated_session($row);
    $_SESSION['csrf_token'] = remember_csrf_token($row['validator_hash']);

    try {
        $expires = time() + REMEMBER_DAYS * 86400;
        $pdo->prepare('UPDATE auth_tokens SET expires_at = ?, last_used_at = NOW() WHERE id = ?')
            ->execute([date('Y-m-d H:i:s', $expires), $row['token_id']]);
        remember_set_cookie($_COOKIE[REMEMBER_COOKIE], $expires);
    } catch (Throwable $e) {
        debug_log('ZOSTAT PRIHLASENY: predĺženie zlyhalo (' . $e->getMessage() . ')');
    }
    return true;
}

/** Zruší trvalé prihlásenie tohto zariadenia (pri odhlásení). */
function remember_forget(PDO $pdo): void
{
    $cookie = (string) ($_COOKIE[REMEMBER_COOKIE] ?? '');
    if ($cookie === '') {
        return;
    }
    try {
        $pdo->prepare('DELETE FROM auth_tokens WHERE selector = ?')->execute([explode(':', $cookie)[0]]);
    } catch (Throwable $e) {
        // tabuľka ešte neexistuje — nie je čo mazať
    }
    remember_set_cookie('', time() - 42000);
    unset($_COOKIE[REMEMBER_COOKIE]);
}

/** Zruší trvalé prihlásenie používateľa na VŠETKÝCH zariadeniach (napr. po zmene hesla). */
function remember_forget_user(PDO $pdo, int $userId): void
{
    try {
        $pdo->prepare('DELETE FROM auth_tokens WHERE user_id = ?')->execute([$userId]);
    } catch (Throwable $e) {
        // tabuľka ešte neexistuje
    }
}

/* ======================================================================
   Heslá — overenie s jednoduchým brzdením opakovaných pokusov
   ====================================================================== */

const LOGIN_MAX_ATTEMPTS      = 5;
const LOGIN_ATTEMPT_WINDOW_S  = 15 * 60; // 15 minút

function login_throttle_identifier(string $username): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return mb_strtolower(trim($username)) . '|' . $ip;
}

function login_is_throttled(PDO $pdo, string $username): bool
{
    $identifier = login_throttle_identifier($username);
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND attempted_at > (NOW() - INTERVAL ? SECOND)'
    );
    $stmt->execute([$identifier, LOGIN_ATTEMPT_WINDOW_S]);
    return (int) $stmt->fetchColumn() >= LOGIN_MAX_ATTEMPTS;
}

function login_record_failed_attempt(PDO $pdo, string $username): void
{
    $identifier = login_throttle_identifier($username);
    $stmt = $pdo->prepare('INSERT INTO login_attempts (identifier) VALUES (?)');
    $stmt->execute([$identifier]);
}

function login_clear_attempts(PDO $pdo, string $username): void
{
    $identifier = login_throttle_identifier($username);
    $stmt = $pdo->prepare('DELETE FROM login_attempts WHERE identifier = ?');
    $stmt->execute([$identifier]);
}

/**
 * Overí meno + heslo. Vráti pole s údajmi používateľa pri úspechu,
 * alebo null pri zlyhaní (nesprávne meno/heslo, alebo príliš veľa pokusov).
 */
function attempt_password_login(PDO $pdo, string $username, string $password): ?array
{
    $username = trim($username);
    if ($username === '' || $password === '') {
        return null;
    }

    if (login_is_throttled($pdo, $username)) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id, username, password_hash, role, full_name FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $row = $stmt->fetch();

    if (!$row || empty($row['password_hash']) || !password_verify($password, $row['password_hash'])) {
        login_record_failed_attempt($pdo, $username);
        return null;
    }

    login_clear_attempts($pdo, $username);

    // Ak heslo bolo hashované starším algoritmom, prehashuj ho na aktuálny.
    if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $upd = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $upd->execute([$newHash, $row['id']]);
    }

    return [
        'id'        => (int) $row['id'],
        'username'  => $row['username'],
        'role'      => $row['role'],
        'full_name' => $row['full_name'],
    ];
}
