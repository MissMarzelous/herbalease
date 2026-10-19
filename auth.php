<?php
// HerbalEASE login API
//   GET  auth.php?action=me       -> who is logged in
//   POST auth.php?action=signup   -> create an account   {username, password}
//   POST auth.php?action=login    -> log in              {username, password}
//   POST auth.php?action=logout   -> log out

declare(strict_types=1);
require __DIR__ . '/db.php';

start_session();
$action = $_GET['action'] ?? '';

if ($action === 'me') {
    send_json(200, ['user' => $_SESSION['display_name'] ?? null]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(405, ['error' => 'Use POST.']);
}

// Only accept requests sent by the HerbalEASE pages themselves.
// Browsers won't let another website add this header, which blocks cross-site form tricks.
if (($_SERVER['HTTP_X_HERBALEASE'] ?? '') !== '1') {
    send_json(403, ['error' => 'Request not allowed.']);
}

if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'],
            'httponly' => true, 'samesite' => 'Lax',
        ]);
    }
    session_destroy();
    send_json(200, ['ok' => true]);
}

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) send_json(400, ['error' => 'Invalid request.']);
$display = trim((string)($body['username'] ?? ''));
$password = (string)($body['password'] ?? '');
$username = normalize_username($display);

$pdo = db();

if ($action === 'signup') {
    if ($err = check_username($display)) send_json(400, ['error' => $err]);
    if ($err = check_password($password, $username)) send_json(400, ['error' => $err]);

    $q = $pdo->prepare('SELECT 1 FROM herb_users WHERE username = ?');
    $q->execute([$username]);
    if ($q->fetchColumn()) send_json(409, ['error' => 'That username is already taken.']);

    $hash = password_hash($password, PASSWORD_DEFAULT);
    try {
        $pdo->prepare('INSERT INTO herb_users (username, display_name, password_hash, created_at, last_login) VALUES (?, ?, ?, ?, ?)')
            ->execute([$username, $display, $hash, now(), now()]);
    } catch (PDOException $e) {
        send_json(409, ['error' => 'That username is already taken.']);
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$pdo->lastInsertId();
    $_SESSION['display_name'] = $display;
    send_json(201, ['user' => $display]);
}

if ($action === 'login') {
    if ($username === '' || $password === '') send_json(400, ['error' => 'Enter your username and password.']);
    if (strlen($username) > USERNAME_MAX || strlen($password) > PASSWORD_MAX) {
        send_json(401, ['error' => 'Username or password is incorrect.']);
    }
    if (is_locked($pdo, $username)) {
        send_json(429, ['error' => 'Too many wrong attempts. Try again in ' . LOCK_MINUTES . ' minutes.']);
    }

    $q = $pdo->prepare('SELECT id, display_name, password_hash FROM herb_users WHERE username = ?');
    $q->execute([$username]);
    $user = $q->fetch();

    // Check a dummy hash when the user doesn't exist, so response time doesn't reveal valid usernames
    $hash = $user['password_hash'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
    $ok = password_verify($password, $hash) && $user;

    if (!$ok) {
        record_failure($pdo, $username);
        send_json(401, ['error' => 'Username or password is incorrect.']);
    }

    clear_failures($pdo, $username);
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        $pdo->prepare('UPDATE herb_users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }
    $pdo->prepare('UPDATE herb_users SET last_login = ? WHERE id = ?')->execute([now(), $user['id']]);

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['display_name'] = $user['display_name'];
    send_json(200, ['user' => $user['display_name']]);
}

send_json(400, ['error' => 'Unknown action.']);
