<?php
// HerbalEASE boards API (saved herb lists, like Pinterest boards)
// Everything here requires a logged-in user; each user only sees their own boards.
//   GET  boards.php?action=list                         -> all boards with saved herbs
//   POST boards.php?action=create   {name}              -> new board
//   POST boards.php?action=rename   {board_id, name}
//   POST boards.php?action=delete   {board_id}
//   POST boards.php?action=save     {board_id, herb}    -> save herb to board
//   POST boards.php?action=unsave   {board_id, herb}    -> remove herb from board

declare(strict_types=1);
require __DIR__ . '/db.php';

const BOARD_NAME_MAX = 40;
const MAX_BOARDS = 50;

start_session();
$userId = (int)($_SESSION['user_id'] ?? 0);
if (!$userId) send_json(401, ['error' => 'Log in to save herbs.']);

$pdo = db();
create_board_tables($pdo);
$action = $_GET['action'] ?? '';

function create_board_tables(PDO $pdo): void
{
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $id = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $ref = $sqlite ? 'INTEGER' : 'INT UNSIGNED';
    $engine = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

    $pdo->exec("CREATE TABLE IF NOT EXISTS herb_boards (
        id $id,
        user_id $ref NOT NULL,
        name VARCHAR(40) NOT NULL,
        created_at DATETIME NOT NULL
    )$engine");

    $pdo->exec("CREATE TABLE IF NOT EXISTS herb_pins (
        id $id,
        board_id $ref NOT NULL,
        herb_name VARCHAR(60) NOT NULL,
        saved_at DATETIME NOT NULL,
        UNIQUE (board_id, herb_name)
    )$engine");
}

// Board names: trimmed, single spaces, 1-40 characters
function clean_board_name($name): string
{
    $name = trim(preg_replace('/\s+/u', ' ', (string)$name));
    if ($name === '') send_json(400, ['error' => 'Give the board a name.']);
    if (mb_strlen($name) > BOARD_NAME_MAX) send_json(400, ['error' => 'Board names can be at most ' . BOARD_NAME_MAX . ' characters.']);
    return $name;
}

// Makes sure the board exists and belongs to the logged-in user
function own_board(PDO $pdo, int $userId, $boardId): int
{
    $q = $pdo->prepare('SELECT id FROM herb_boards WHERE id = ? AND user_id = ?');
    $q->execute([(int)$boardId, $userId]);
    $id = $q->fetchColumn();
    if (!$id) send_json(404, ['error' => 'Board not found.']);
    return (int)$id;
}

// Only herbs that exist in herbs.json can be saved
function valid_herb($name): string
{
    $name = (string)$name;
    $herbs = json_decode((string)file_get_contents(__DIR__ . '/../herbs.json'), true) ?: [];
    foreach ($herbs as $h) {
        if (($h['Common Name'] ?? null) === $name) return $name;
    }
    send_json(400, ['error' => 'That herb is not in the database.']);
}

function board_list(PDO $pdo, int $userId): array
{
    $q = $pdo->prepare('SELECT id, name, created_at FROM herb_boards WHERE user_id = ? ORDER BY created_at, id');
    $q->execute([$userId]);
    $boards = $q->fetchAll();
    $pins = $pdo->prepare('SELECT herb_name FROM herb_pins WHERE board_id = ? ORDER BY saved_at DESC, id DESC');
    foreach ($boards as &$b) {
        $pins->execute([$b['id']]);
        $b['id'] = (int)$b['id'];
        $b['herbs'] = $pins->fetchAll(PDO::FETCH_COLUMN);
    }
    return $boards;
}

if ($action === 'list') {
    send_json(200, ['boards' => board_list($pdo, $userId)]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') send_json(405, ['error' => 'Use POST.']);
if (($_SERVER['HTTP_X_HERBALEASE'] ?? '') !== '1') send_json(403, ['error' => 'Request not allowed.']);
$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) send_json(400, ['error' => 'Invalid request.']);

switch ($action) {
    case 'create':
        $name = clean_board_name($body['name'] ?? '');
        $q = $pdo->prepare('SELECT COUNT(*) FROM herb_boards WHERE user_id = ?');
        $q->execute([$userId]);
        if ((int)$q->fetchColumn() >= MAX_BOARDS) send_json(400, ['error' => 'You can have up to ' . MAX_BOARDS . ' boards.']);
        $q = $pdo->prepare('SELECT 1 FROM herb_boards WHERE user_id = ? AND LOWER(name) = LOWER(?)');
        $q->execute([$userId, $name]);
        if ($q->fetchColumn()) send_json(409, ['error' => 'You already have a board with that name.']);
        $pdo->prepare('INSERT INTO herb_boards (user_id, name, created_at) VALUES (?, ?, ?)')->execute([$userId, $name, now()]);
        $newId = (int)$pdo->lastInsertId();
        if (isset($body['herb'])) {
            $herb = valid_herb($body['herb']);
            $pdo->prepare('INSERT INTO herb_pins (board_id, herb_name, saved_at) VALUES (?, ?, ?)')->execute([$newId, $herb, now()]);
        }
        send_json(201, ['board_id' => $newId, 'boards' => board_list($pdo, $userId)]);

    case 'rename':
        $id = own_board($pdo, $userId, $body['board_id'] ?? 0);
        $name = clean_board_name($body['name'] ?? '');
        $q = $pdo->prepare('SELECT 1 FROM herb_boards WHERE user_id = ? AND LOWER(name) = LOWER(?) AND id <> ?');
        $q->execute([$userId, $name, $id]);
        if ($q->fetchColumn()) send_json(409, ['error' => 'You already have a board with that name.']);
        $pdo->prepare('UPDATE herb_boards SET name = ? WHERE id = ?')->execute([$name, $id]);
        send_json(200, ['boards' => board_list($pdo, $userId)]);

    case 'delete':
        $id = own_board($pdo, $userId, $body['board_id'] ?? 0);
        $pdo->prepare('DELETE FROM herb_pins WHERE board_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM herb_boards WHERE id = ?')->execute([$id]);
        send_json(200, ['boards' => board_list($pdo, $userId)]);

    case 'save':
        $id = own_board($pdo, $userId, $body['board_id'] ?? 0);
        $herb = valid_herb($body['herb'] ?? '');
        $q = $pdo->prepare('SELECT 1 FROM herb_pins WHERE board_id = ? AND herb_name = ?');
        $q->execute([$id, $herb]);
        if (!$q->fetchColumn()) {
            $pdo->prepare('INSERT INTO herb_pins (board_id, herb_name, saved_at) VALUES (?, ?, ?)')->execute([$id, $herb, now()]);
        }
        send_json(200, ['boards' => board_list($pdo, $userId)]);

    case 'unsave':
        $id = own_board($pdo, $userId, $body['board_id'] ?? 0);
        $pdo->prepare('DELETE FROM herb_pins WHERE board_id = ? AND herb_name = ?')->execute([$id, (string)($body['herb'] ?? '')]);
        send_json(200, ['boards' => board_list($pdo, $userId)]);
}

send_json(400, ['error' => 'Unknown action.']);
