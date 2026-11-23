<?php
declare(strict_types=1);

const DB_HOST = '127.0.0.1';
const DB_NAME = 'storage_portal';
const DB_USER = 'root';
const DB_PASS = '';
const SESSION_IDLE_SECONDS = 1800;
const MAX_UPLOAD_BYTES = 104857600;
const PASSWORD_RESET_SECONDS = 1800;

const UPLOAD_DIRECTORY = __DIR__ . DIRECTORY_SEPARATOR . 'private_uploads';

function database(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    return $pdo;
}

function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    if (isset($_SESSION['user_id'])) {
        $lastActivity = (int) ($_SESSION['last_activity'] ?? 0);
        if ($lastActivity === 0 || time() - $lastActivity > SESSION_IDLE_SECONDS) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
    }

    if (isset($_SESSION['user_id'])) {
        $_SESSION['last_activity'] = time();
    }
}

function send_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function current_user_id(): int
{
    $id = (int) ($_SESSION['user_id'] ?? 0);
    if ($id < 1) {
        send_json(['error' => 'Please sign in to continue.'], 401);
    }

    $query = database()->prepare('SELECT auth_version FROM users WHERE id = ?');
    $query->execute([$id]);
    $authVersion = $query->fetchColumn();
    if ($authVersion === false || (int) ($_SESSION['auth_version'] ?? -1) !== (int) $authVersion) {
        $_SESSION = [];
        session_regenerate_id(true);
        send_json(['error' => 'Your session has ended. Please sign in again.'], 401);
    }

    $_SESSION['last_activity'] = time();
    return $id;
}

function require_admin(PDO $pdo, int $userId): void
{
    $query = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $query->execute([$userId]);
    if ($query->fetchColumn() !== 'admin') {
        send_json(['error' => 'Administrator access is required.'], 403);
    }
}

function send_password_reset_email(string $email, string $username, string $code): void
{
    $fromEmail = trim((string) ini_get('sendmail_from'));
    if (filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('Set a valid sendmail_from address in C:\\xampp\\php\\php.ini.');
    }

    $safeUsername = trim(preg_replace('/[\r\n]+/', ' ', $username) ?? '');
    $subject = '=?UTF-8?B?' . base64_encode('Your Storage password reset code') . '?=';
    $body = "Hello " . $safeUsername . ",\r\n\r\n"
        . "Your six-digit Storage password reset code is:\r\n\r\n"
        . $code . "\r\n\r\n"
        . "This code expires in 10 minutes and can be used once. You have up to five attempts.\r\n\r\n"
        . "If you did not request this, you can ignore this email.\r\n";
    $headers = [
        'From' => 'Storage <' . $fromEmail . '>',
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/plain; charset=UTF-8',
        'Content-Transfer-Encoding' => '8bit',
    ];

    if (!mail($email, $subject, $body, $headers)) {
        throw new RuntimeException('PHP mail() could not hand the reset email to XAMPP Sendmail. Check C:\\xampp\\sendmail\\error.log.');
    }
}

function request_data(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        send_json(['error' => 'The request body must be valid JSON.'], 400);
    }

    return $data;
}

function require_csrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        send_json(['error' => 'Your session token is invalid. Refresh the page and try again.'], 403);
    }
}

function require_method(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        header('Allow: ' . $method);
        send_json(['error' => 'This request method is not allowed.'], 405);
    }
}

function folder_access(PDO $pdo, int $folderId, int $userId): array
{
    $query = $pdo->prepare(
        'SELECT f.id, f.owner_id, f.name, fp.permission
         FROM folders f
         LEFT JOIN folder_permissions fp ON fp.folder_id = f.id AND fp.user_id = ?
         WHERE f.id = ? AND (f.owner_id = ? OR fp.user_id = ?)'
    );
    $query->execute([$userId, $folderId, $userId, $userId]);
    $folder = $query->fetch();
    if (!$folder) {
        send_json(['error' => 'Folder not found or access denied.'], 404);
    }

    return $folder;
}

function require_folder_editor(PDO $pdo, int $folderId, int $userId): array
{
    $folder = folder_access($pdo, $folderId, $userId);
    if ((int) $folder['owner_id'] !== $userId && $folder['permission'] !== 'editor') {
        send_json(['error' => 'You need editor access to change files in this folder.'], 403);
    }

    return $folder;
}

function record_activity(PDO $pdo, int $userId, ?int $folderId, string $folderName, string $action, string $detail = ''): void
{
    $query = $pdo->prepare(
        'INSERT INTO activity (user_id, folder_id, folder_name, action, detail) VALUES (?, ?, ?, ?, ?)'
    );
    $query->execute([$userId, $folderId, $folderName, $action, $detail]);
}

function snapshot(PDO $pdo, int $userId): array
{
    $userQuery = $pdo->prepare('SELECT id, name, username, email, role FROM users WHERE id = ?');
    $userQuery->execute([$userId]);
    $self = $userQuery->fetch();
    if (!$self) {
        send_json(['error' => 'This account no longer exists.'], 401);
    }

    $friendQuery = $pdo->prepare(
        'SELECT u.id, u.username AS name, u.username, u.email
         FROM friendships fr JOIN users u ON u.id = IF(fr.user_one_id = ?, fr.user_two_id, fr.user_one_id)
         WHERE fr.user_one_id = ? OR fr.user_two_id = ?
         ORDER BY u.username'
    );
    $friendQuery->execute([$userId, $userId, $userId]);
    $friends = $friendQuery->fetchAll();

    $requestQuery = $pdo->prepare(
        'SELECT id, sender_id, recipient_id, created_at FROM friend_requests
         WHERE sender_id = ? OR recipient_id = ? ORDER BY created_at DESC'
    );
    $requestQuery->execute([$userId, $userId]);
    $requests = $requestQuery->fetchAll();

    $folderQuery = $pdo->prepare(
        'SELECT f.id, f.owner_id, f.name, f.created_at, f.updated_at, owner.username AS owner_name,
                fp.user_id AS permission_user_id, fp.permission
         FROM folders f
         JOIN users owner ON owner.id = f.owner_id
         LEFT JOIN folder_permissions fp ON fp.folder_id = f.id
         WHERE f.owner_id = ? OR EXISTS (
             SELECT 1 FROM folder_permissions mine WHERE mine.folder_id = f.id AND mine.user_id = ?
         )
         ORDER BY f.updated_at DESC, f.id DESC'
    );
    $folderQuery->execute([$userId, $userId]);
    $foldersById = [];
    foreach ($folderQuery->fetchAll() as $row) {
        $id = (int) $row['id'];
        if (!isset($foldersById[$id])) {
            $foldersById[$id] = [
                'id' => (string) $id,
                'ownerId' => (string) $row['owner_id'],
                'ownerName' => $row['owner_name'],
                'name' => $row['name'],
                'createdAt' => strtotime($row['created_at']) * 1000,
                'updatedAt' => strtotime($row['updated_at']) * 1000,
                'permissions' => [],
                'files' => [],
            ];
        }
        if ($row['permission_user_id'] !== null) {
            $foldersById[$id]['permissions'][(string) $row['permission_user_id']] = $row['permission'];
        }
    }

    $folderIds = array_keys($foldersById);
    if ($folderIds !== []) {
        $placeholders = implode(',', array_fill(0, count($folderIds), '?'));
        $fileQuery = $pdo->prepare(
            "SELECT id, folder_id, original_name, mime_type, size_bytes, added_by, created_at
             FROM files WHERE folder_id IN ($placeholders) ORDER BY created_at DESC, id DESC"
        );
        $fileQuery->execute($folderIds);
        foreach ($fileQuery->fetchAll() as $file) {
            $folderId = (int) $file['folder_id'];
            $mime = (string) $file['mime_type'];
            $category = str_starts_with($mime, 'image/') ? 'image'
                : (str_starts_with($mime, 'video/') ? 'video'
                    : (in_array($mime, ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'text/plain', 'text/csv'], true) ? 'document' : 'other'));
            $foldersById[$folderId]['files'][] = [
                'id' => (string) $file['id'],
                'name' => $file['original_name'],
                'size' => (int) $file['size_bytes'],
                'type' => $mime,
                'category' => $category,
                'addedAt' => strtotime($file['created_at']) * 1000,
                'addedBy' => (string) $file['added_by'],
            ];
        }
    }

    $activityQuery = $pdo->prepare(
        'SELECT a.id, a.user_id, a.folder_id, a.folder_name, a.action, a.detail, a.created_at
         FROM activity a
         WHERE a.user_id = ? OR a.folder_id IN (
             SELECT f.id FROM folders f
             LEFT JOIN folder_permissions fp ON fp.folder_id = f.id
             WHERE f.owner_id = ? OR fp.user_id = ?
         )
         ORDER BY a.created_at DESC, a.id DESC LIMIT 500'
    );
    $activityQuery->execute([$userId, $userId, $userId]);
    $activities = array_map(static fn(array $row): array => [
        'id' => (string) $row['id'],
        'userId' => (string) $row['user_id'],
        'folderId' => $row['folder_id'] === null ? null : (string) $row['folder_id'],
        'folderName' => $row['folder_name'],
        'action' => $row['action'],
        'detail' => $row['detail'],
        'time' => strtotime($row['created_at']) * 1000,
    ], $activityQuery->fetchAll());

    $users = [(string) $self['id'] => $self];
    foreach ($friends as $friend) {
        $users[(string) $friend['id']] = $friend;
    }
    foreach ($requests as $request) {
        $otherId = (int) ($request['sender_id'] == $userId ? $request['recipient_id'] : $request['sender_id']);
        $otherQuery = $pdo->prepare('SELECT id, username AS name, username, email FROM users WHERE id = ?');
        $otherQuery->execute([$otherId]);
        $other = $otherQuery->fetch();
        if ($other) {
            $users[(string) $other['id']] = $other;
        }
    }
    foreach ($foldersById as $folder) {
        $ownerId = (int) $folder['ownerId'];
        if (!isset($users[(string) $ownerId])) {
            $users[(string) $ownerId] = ['id' => $ownerId, 'name' => $folder['ownerName'], 'email' => ''];
        }
    }
    foreach ($activities as $activity) {
        $actorId = (int) $activity['userId'];
        if (!isset($users[(string) $actorId])) {
            $actorQuery = $pdo->prepare('SELECT id, username AS name, username, email FROM users WHERE id = ?');
            $actorQuery->execute([$actorId]);
            $actor = $actorQuery->fetch();
            if ($actor) {
                $users[(string) $actor['id']] = $actor;
            }
        }
    }

    $friendships = [];
    foreach ($friends as $friend) {
        $friendships[] = ['userIds' => [(string) $userId, (string) $friend['id']]];
    }

    return [
        'me' => [
            'id' => (string) $self['id'],
            'name' => $self['username'],
            'username' => $self['username'],
            'email' => $self['email'],
            'role' => $self['role'],
        ],
        'csrf' => $_SESSION['csrf_token'],
        'users' => array_values(array_map(static fn(array $user): array => [
            'id' => (string) $user['id'],
            'name' => $user['username'] ?? $user['name'],
            'username' => $user['username'] ?? $user['name'],
            'email' => $user['email'],
        ], $users)),
        'folders' => array_values($foldersById),
        'friendships' => $friendships,
        'requests' => array_map(static fn(array $request): array => [
            'id' => (string) $request['id'],
            'fromId' => (string) $request['sender_id'],
            'toId' => (string) $request['recipient_id'],
            'createdAt' => strtotime($request['created_at']) * 1000,
        ], $requests),
        'activity' => $activities,
    ];
}
