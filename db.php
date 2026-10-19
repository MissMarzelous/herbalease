<?php
// Shared code for the HerbalEASE login: database connection, session, and rules.
// Visitors can't open this file directly (blocked by .htaccess).

declare(strict_types=1);

const USERNAME_MIN = 3;
const USERNAME_MAX = 30;
const PASSWORD_MIN = 8;
const PASSWORD_MAX = 128;
const MAX_FAILS_PER_USER = 5;    // wrong passwords allowed per username...
const MAX_FAILS_PER_IP = 20;     // ...and per network address...
const LOCK_MINUTES = 15;         // ...within this many minutes

function send_json(int $status, array $data): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data);
    exit;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;

    // HERBALEASE_TEST_DSN is only used for local testing; on Hostinger config.php is used
    $testDsn = getenv('HERBALEASE_TEST_DSN');
    try {
        if ($testDsn) {
            $pdo = new PDO($testDsn);
        } else {
            $c = require __DIR__ . '/config.php';
            if (strpos($c['db_name'], 'PASTE_') === 0) {
                send_json(500, ['error' => 'The login database is not set up yet. Fill in api/config.php.']);
            }
            $dsn = 'mysql:host=' . $c['db_host'] . ';dbname=' . $c['db_name'] . ';charset=utf8mb4';
            $pdo = new PDO($dsn, $c['db_user'], $c['db_pass']);
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    } catch (PDOException $e) {
        error_log('HerbalEASE DB connect failed: ' . $e->getMessage());
        send_json(500, ['error' => 'Could not connect to the login database. Check api/config.php.']);
    }

    create_tables($pdo);
    return $pdo;
}

// Creates the tables the first time the login is used, so no SQL needs to be run by hand
function create_tables(PDO $pdo): void
{
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $id = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $engine = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

    $pdo->exec("CREATE TABLE IF NOT EXISTS herb_users (
        id $id,
        username VARCHAR(30) NOT NULL UNIQUE,
        display_name VARCHAR(30) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL,
        last_login DATETIME NULL
    )$engine");

    $pdo->exec("CREATE TABLE IF NOT EXISTS herb_login_attempts (
        id $id,
        username VARCHAR(30) NOT NULL,
        ip VARCHAR(45) NOT NULL,
        attempted_at DATETIME NOT NULL
    )$engine");
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('HERBALEASE');
    session_set_cookie_params([
        'lifetime' => 0,          // ends when the browser closes
        'path' => '/',
        'secure' => $https,       // only sent over HTTPS when the site uses it
        'httponly' => true,       // JavaScript can't read the session cookie
        'samesite' => 'Lax',
    ]);
    session_start();
}

function now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function client_ip(): string
{
    return substr($_SERVER['REMOTE_ADDR'] ?? 'unknown', 0, 45);
}

function normalize_username(string $u): string
{
    return strtolower(trim($u));
}

// Returns an error message, or null if the username is OK
function check_username(string $display): ?string
{
    $len = strlen($display);
    if ($len < USERNAME_MIN) return 'Username must be at least ' . USERNAME_MIN . ' characters.';
    if ($len > USERNAME_MAX) return 'Username can be at most ' . USERNAME_MAX . ' characters.';
    if (!preg_match('/^[A-Za-z0-9_.-]+$/', $display)) return 'Username can use only letters, numbers, dots, dashes, or underscores.';
    return null;
}

// Returns an error message, or null if the password meets the rules
function check_password(string $p, string $username): ?string
{
    if (strlen($p) < PASSWORD_MIN) return 'Password must be at least ' . PASSWORD_MIN . ' characters.';
    if (strlen($p) > PASSWORD_MAX) return 'Password can be at most ' . PASSWORD_MAX . ' characters.';
    if (!preg_match('/[A-Za-z]/', $p)) return 'Password must include at least one letter.';
    if (!preg_match('/[0-9]/', $p)) return 'Password must include at least one number.';
    if (stripos($p, $username) !== false) return 'Password can\'t contain your username.';
    return null;
}

function is_locked(PDO $pdo, string $username): bool
{
    $since = gmdate('Y-m-d H:i:s', time() - LOCK_MINUTES * 60);
    $q = $pdo->prepare('SELECT COUNT(*) FROM herb_login_attempts WHERE username = ? AND attempted_at > ?');
    $q->execute([$username, $since]);
    if ((int)$q->fetchColumn() >= MAX_FAILS_PER_USER) return true;
    $q = $pdo->prepare('SELECT COUNT(*) FROM herb_login_attempts WHERE ip = ? AND attempted_at > ?');
    $q->execute([client_ip(), $since]);
    return (int)$q->fetchColumn() >= MAX_FAILS_PER_IP;
}

function record_failure(PDO $pdo, string $username): void
{
    $pdo->prepare('INSERT INTO herb_login_attempts (username, ip, attempted_at) VALUES (?, ?, ?)')
        ->execute([$username, client_ip(), now()]);
    // Clean up old rows now and then
    if (random_int(1, 20) === 1) {
        $old = gmdate('Y-m-d H:i:s', time() - 86400);
        $pdo->prepare('DELETE FROM herb_login_attempts WHERE attempted_at < ?')->execute([$old]);
    }
}

function clear_failures(PDO $pdo, string $username): void
{
    $pdo->prepare('DELETE FROM herb_login_attempts WHERE username = ?')->execute([$username]);
}
