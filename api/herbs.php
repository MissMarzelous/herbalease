<?php
// HerbalEASE user-added herbs API (stored in MySQL)
//   GET  herbs.php?action=list     -> every user-added herb (anyone can view)
//   POST herbs.php?action=add      -> add an herb            {name, parts, remedies, uses}   (logged in)
//   POST herbs.php?action=update   -> edit your own herb     {id, name, parts, remedies, uses}
//   POST herbs.php?action=delete   -> delete your own herb   {id}

declare(strict_types=1);
require __DIR__ . '/db.php';

start_session();
$action = $_GET['action'] ?? '';
$pdo = db();
create_herb_table($pdo);
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

const HERB_NAME_MAX = 100;
const HERB_PARTS_MAX = 255;
const HERB_TEXT_MAX = 2000;
const HERBS_PER_USER_MAX = 200;

// Creates the user_herbs table the first time this is used (same approach as db.php)
function create_herb_table(PDO $pdo): void
{
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $id = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $engine = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    $index = $sqlite ? '' : ',
        INDEX idx_user_herbs_user (user_id)';
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_herbs (
        id $id,
        user_id INT UNSIGNED NOT NULL,

        name VARCHAR(100) NOT NULL,
        parts VARCHAR(255) NOT NULL,
        remedies TEXT NOT NULL,
        uses TEXT NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NULL$index
    )$engine");
}

function text_len(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
}

if ($action === 'list') {
    $q = $pdo->query(
        'SELECT h.id, h.user_id, h.name, h.parts, h.remedies, h.uses, h.created_at, u.display_name
           FROM user_herbs h
           LEFT JOIN herb_users u ON u.id = h.user_id
          ORDER BY h.id ASC
          LIMIT 1000'
    );
    $herbs = [];
    foreach ($q->fetchAll() as $row) {
        $herbs[] = [
            'id'       => (int)$row['id'],
            'name'     => $row['name'],
            'parts'    => $row['parts'],
            'remedies' => $row['remedies'],
            'uses'     => $row['uses'],
            'added_by' => $row['display_name'] ?? 'former user',
            'mine'     => $userId > 0 && (int)$row['user_id'] === $userId,

        ];
    }
    send_json(200, ['herbs' => $herbs, 'user' => $_SESSION['display_name'] ?? null]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(405, ['error' => 'Use POST.']);
}

// Same protection as auth.php: only accept requests sent by the HerbalEASE pages
if (($_SERVER['HTTP_X_HERBALEASE'] ?? '') !== '1') {
    send_json(403, ['error' => 'Request not allowed.']);
}

if ($userId <= 0) {
    send_json(401, ['error' => 'Please log in to add or change herbs.']);
}

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) send_json(400, ['error' => 'Invalid request.']);

function clean_herb(array $body): array {
    $h = [
        'name'     => trim((string)($body['name'] ?? '')),
        'parts'    => trim((string)($body['parts'] ?? '')),
        'remedies' => trim((string)($body['remedies'] ?? '')),
        'uses'     => trim((string)($body['uses'] ?? '')),
    ];
    if ($h['name'] === '' || $h['parts'] === '' || $h['remedies'] === '' || $h['uses'] === '') {
        send_json(400, ['error' => 'Please fill in every field.']);
    }
    if (text_len($h['name']) > HERB_NAME_MAX)    send_json(400, ['error' => 'Herb name is too long (max ' . HERB_NAME_MAX . ' characters).']);

    if (text_len($h['parts']) > HERB_PARTS_MAX)  send_json(400, ['error' => 'Parts used is too long (max ' . HERB_PARTS_MAX . ' characters).']);
    if (text_len($h['remedies']) > HERB_TEXT_MAX || text_len($h['uses']) > HERB_TEXT_MAX) {
        send_json(400, ['error' => 'Remedies and uses must be under ' . HERB_TEXT_MAX . ' characters.']);
    }
    return $h;
}

function owned_herb_id(PDO $pdo, array $body, int $userId): int {
    $id = (int)($body['id'] ?? 0);
    $q = $pdo->prepare('SELECT user_id FROM user_herbs WHERE id = ?');
    $q->execute([$id]);
    $owner = $q->fetchColumn();
    if ($owner === false) send_json(404, ['error' => 'That herb no longer exists.']);
    if ((int)$owner !== $userId) send_json(403, ['error' => 'You can only change herbs you added.']);
    return $id;
}

if ($action === 'add') {
    $h = clean_herb($body);
    $c = $pdo->prepare('SELECT COUNT(*) FROM user_herbs WHERE user_id = ?');
    $c->execute([$userId]);
    if ((int)$c->fetchColumn() >= HERBS_PER_USER_MAX) {
        send_json(429, ['error' => 'You have reached the limit of ' . HERBS_PER_USER_MAX . ' herbs.']);
    }
    $pdo->prepare('INSERT INTO user_herbs (user_id, name, parts, remedies, uses, created_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$userId, $h['name'], $h['parts'], $h['remedies'], $h['uses'], now()]);
    send_json(201, ['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
}

if ($action === 'update') {
    $id = owned_herb_id($pdo, $body, $userId);
    $h = clean_herb($body);

    $pdo->prepare('UPDATE user_herbs SET name = ?, parts = ?, remedies = ?, uses = ?, updated_at = ? WHERE id = ? AND user_id = ?')
        ->execute([$h['name'], $h['parts'], $h['remedies'], $h['uses'], now(), $id, $userId]);
    send_json(200, ['ok' => true]);
}

if ($action === 'delete') {
    $id = owned_herb_id($pdo, $body, $userId);
    $pdo->prepare('DELETE FROM user_herbs WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
    send_json(200, ['ok' => true]);
}

send_json(400, ['error' => 'Unknown action.']);
