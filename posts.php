<?php
// HerbalEASE discussion board API (stored in MySQL)
//   GET  posts.php?action=list     -> all posts, newest first (anyone can read)
//   POST posts.php?action=add      -> create a post   {title, content}   (logged in; author = your username)
//   POST posts.php?action=delete   -> delete your own post   {id}

declare(strict_types=1);
require __DIR__ . '/db.php';

start_session();
$action = $_GET['action'] ?? '';
$pdo = db();
create_post_table($pdo);
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

const POST_TITLE_MAX = 120;
const POST_CONTENT_MAX = 4000;
const POSTS_PER_HOUR_MAX = 20;

// Creates the discussion_posts table the first time this is used (same approach as db.php)
function create_post_table(PDO $pdo): void
{
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $id = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $engine = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    $index = $sqlite ? '' : ',
        INDEX idx_discussion_posts_user (user_id)';
    $pdo->exec("CREATE TABLE IF NOT EXISTS discussion_posts (
        id $id,
        user_id INT UNSIGNED NOT NULL,
        title VARCHAR(120) NOT NULL,
        content TEXT NOT NULL,
        created_at DATETIME NOT NULL$index
    )$engine");
}

function post_len(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
}

if ($action === 'list') {
    $q = $pdo->query(
        'SELECT p.id, p.user_id, p.title, p.content, p.created_at, u.display_name
           FROM discussion_posts p
           LEFT JOIN herb_users u ON u.id = p.user_id
          ORDER BY p.id DESC
          LIMIT 500'
    );
    $posts = [];
    foreach ($q->fetchAll() as $row) {
        $posts[] = [
            'id'         => (int)$row['id'],
            'title'      => $row['title'],
            'content'    => $row['content'],
            'author'     => $row['display_name'] ?? 'former user',
            'created_at' => $row['created_at'],
            'mine'       => $userId > 0 && (int)$row['user_id'] === $userId,
        ];
    }
    send_json(200, ['posts' => $posts, 'user' => $_SESSION['display_name'] ?? null]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(405, ['error' => 'Use POST.']);
}

// Same protection as auth.php: only accept requests sent by the HerbalEASE pages
if (($_SERVER['HTTP_X_HERBALEASE'] ?? '') !== '1') {
    send_json(403, ['error' => 'Request not allowed.']);
}

if ($userId <= 0) {
    send_json(401, ['error' => 'Please log in to post.']);
}

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) send_json(400, ['error' => 'Invalid request.']);

if ($action === 'add') {
    $title = trim((string)($body['title'] ?? ''));
    $content = trim((string)($body['content'] ?? ''));
    if ($title === '' || $content === '') send_json(400, ['error' => 'Please enter a title and some content.']);
    if (post_len($title) > POST_TITLE_MAX) send_json(400, ['error' => 'Title is too long (max ' . POST_TITLE_MAX . ' characters).']);
    if (post_len($content) > POST_CONTENT_MAX) send_json(400, ['error' => 'Post is too long (max ' . POST_CONTENT_MAX . ' characters).']);

    // Simple spam guard: limit posts per hour per user
    $since = gmdate('Y-m-d H:i:s', time() - 3600);
    $c = $pdo->prepare('SELECT COUNT(*) FROM discussion_posts WHERE user_id = ? AND created_at > ?');
    $c->execute([$userId, $since]);
    if ((int)$c->fetchColumn() >= POSTS_PER_HOUR_MAX) {
        send_json(429, ['error' => 'You are posting too fast. Please try again later.']);
    }

    $pdo->prepare('INSERT INTO discussion_posts (user_id, title, content, created_at) VALUES (?, ?, ?, ?)')
        ->execute([$userId, $title, $content, now()]);
    send_json(201, ['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
}

if ($action === 'delete') {
    $id = (int)($body['id'] ?? 0);
    $q = $pdo->prepare('SELECT user_id FROM discussion_posts WHERE id = ?');
    $q->execute([$id]);
    $owner = $q->fetchColumn();
    if ($owner === false) send_json(404, ['error' => 'That post no longer exists.']);
    if ((int)$owner !== $userId) send_json(403, ['error' => 'You can only delete your own posts.']);
    $pdo->prepare('DELETE FROM discussion_posts WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
    send_json(200, ['ok' => true]);
}

send_json(400, ['error' => 'Unknown action.']);