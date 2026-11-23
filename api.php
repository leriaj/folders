<?php
declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
start_app_session();

try {
    $pdo = database();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = (string) ($_GET['action'] ?? '');

    if ($method === 'GET' && $action === 'csrf') {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        send_json(['csrf' => $_SESSION['csrf_token']]);
    }

    if ($method === 'GET' && $action === 'state') {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        if (!isset($_SESSION['user_id'])) {
            send_json(['authenticated' => false, 'csrf' => $_SESSION['csrf_token']]);
        }
        send_json(['authenticated' => true, 'data' => snapshot($pdo, current_user_id())]);
    }

    if ($method === 'POST' && $action === 'forgot-password') {
        require_csrf();
        $input = request_data();
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            send_json(['error' => 'Enter a valid registered email address.'], 422);
        }

        $find = $pdo->prepare('SELECT id, username FROM users WHERE email = ?');
        $find->execute([$email]);
        $user = $find->fetch();
        if ($user) {
            $rateLimit = $pdo->prepare(
                'SELECT COUNT(*) FROM password_resets WHERE user_id = ?
                 AND created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 HOUR)'
            );
            $rateLimit->execute([$user['id']]);
            if ((int) $rateLimit->fetchColumn() < 3) {
                $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $codeHash = password_hash($code, PASSWORD_DEFAULT);
                $pdo->prepare('UPDATE password_resets SET expires_at = CURRENT_TIMESTAMP WHERE user_id = ?')
                    ->execute([$user['id']]);
                $insert = $pdo->prepare(
                    'INSERT INTO password_resets (user_id, token_hash, expires_at, attempts)
                     VALUES (?, ?, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 10 MINUTE), 0)'
                );
                $insert->execute([$user['id'], $codeHash]);
                $resetId = (int) $pdo->lastInsertId();
                try {
                    send_password_reset_email($email, $user['username'], $code);
                } catch (Throwable $mailException) {
                    $pdo->prepare('DELETE FROM password_resets WHERE id = ?')->execute([$resetId]);
                    error_log('Storage password reset email failed: ' . $mailException->getMessage());
                    send_json(['error' => 'Could not send the reset email through XAMPP Sendmail. Check C:\\xampp\\sendmail\\error.log.'], 503);
                }
            }
        }
        send_json(['message' => 'If that email belongs to an account, a six-digit reset code has been sent.']);
    }

    if ($method === 'POST' && $action === 'verify-reset-code') {
        require_csrf();
        $input = request_data();
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $code = trim((string) ($input['code'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            send_json(['error' => 'Enter the email address for your account.'], 422);
        }
        if (!preg_match('/^[0-9]{6}$/', $code)) {
            send_json(['error' => 'Enter the six-digit code from your email.'], 422);
        }

        $pdo->beginTransaction();
        try {
            $find = $pdo->prepare(
                'SELECT pr.id, pr.user_id, pr.token_hash, pr.attempts
                 FROM password_resets pr
                 JOIN users u ON u.id = pr.user_id
                 WHERE u.email = ? AND pr.expires_at > CURRENT_TIMESTAMP
                 ORDER BY pr.created_at DESC, pr.id DESC
                 LIMIT 1 FOR UPDATE'
            );
            $find->execute([$email]);
            $reset = $find->fetch();
            if (!$reset || (int) $reset['attempts'] >= 5) {
                $pdo->commit();
                send_json(['error' => 'The code is invalid, expired, or locked. Request a new code.'], 422);
            }
            if (!password_verify($code, $reset['token_hash'])) {
                $pdo->prepare('UPDATE password_resets SET attempts = attempts + 1 WHERE id = ?')
                    ->execute([$reset['id']]);
                $pdo->commit();
                send_json(['error' => 'The code is incorrect. Check it and try again.'], 422);
            }

            $pdo->commit();
            $_SESSION['password_reset_id'] = (int) $reset['id'];
            $_SESSION['password_reset_user_id'] = (int) $reset['user_id'];
            $_SESSION['password_reset_verified_at'] = time();
            send_json(['verified' => true]);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    if ($method === 'POST' && $action === 'reset-password') {
        require_csrf();
        $input = request_data();
        $password = (string) ($input['password'] ?? '');
        if (strlen($password) < 8 || strlen($password) > 4096) {
            send_json(['error' => 'Password must be at least 8 characters long.'], 422);
        }
        $resetId = (int) ($_SESSION['password_reset_id'] ?? 0);
        $resetUserId = (int) ($_SESSION['password_reset_user_id'] ?? 0);
        $verifiedAt = (int) ($_SESSION['password_reset_verified_at'] ?? 0);
        if ($resetId < 1 || $resetUserId < 1 || $verifiedAt < time() - 600) {
            unset($_SESSION['password_reset_id'], $_SESSION['password_reset_user_id'], $_SESSION['password_reset_verified_at']);
            send_json(['error' => 'Verify your email code before choosing a new password. Request a new code if needed.'], 403);
        }

        $pdo->beginTransaction();
        try {
            $find = $pdo->prepare(
                'SELECT id, user_id FROM password_resets
                 WHERE id = ? AND user_id = ? AND expires_at > CURRENT_TIMESTAMP AND attempts < 5
                 LIMIT 1 FOR UPDATE'
            );
            $find->execute([$resetId, $resetUserId]);
            $reset = $find->fetch();
            if (!$reset) {
                $pdo->rollBack();
                unset($_SESSION['password_reset_id'], $_SESSION['password_reset_user_id'], $_SESSION['password_reset_verified_at']);
                send_json(['error' => 'This verification has expired. Request a new code.'], 422);
            }

            $pdo->prepare('UPDATE users SET password_hash = ?, auth_version = auth_version + 1 WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $reset['user_id']]);
            $pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$reset['user_id']]);
            $pdo->commit();
            unset($_SESSION['password_reset_id'], $_SESSION['password_reset_user_id'], $_SESSION['password_reset_verified_at']);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        send_json(['message' => 'Your password has been changed. You can now sign in.']);
    }

    if ($method === 'POST' && in_array($action, ['signup', 'login'], true)) {
        require_csrf();
        $input = request_data();
        $identifier = trim((string) ($input['identifier'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        if (strlen($password) < 8 || strlen($password) > 4096) {
            send_json(['error' => 'Password must be at least 8 characters long.'], 422);
        }

        if ($action === 'signup') {
            $username = trim((string) ($input['username'] ?? ''));
            $email = strtolower(trim((string) ($input['email'] ?? '')));
            if (!preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $username)) {
                send_json(['error' => 'Username must be 3–30 characters using letters, numbers, dots, underscores, or hyphens.'], 422);
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
                send_json(['error' => 'Enter a valid email address.'], 422);
            }
            $insert = $pdo->prepare('INSERT INTO users (name, username, email, password_hash) VALUES (?, ?, ?, ?)');
            try {
                $insert->execute([$username, $username, $email, password_hash($password, PASSWORD_DEFAULT)]);
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    send_json(['error' => 'That username or email address is already registered.'], 409);
                }
                throw $exception;
            }
            $userId = (int) $pdo->lastInsertId();
            $authVersion = 0;
        } else {
            if ($identifier === '' || strlen($identifier) > 190) {
                send_json(['error' => 'Enter your username or email address.'], 422);
            }
            $find = $pdo->prepare('SELECT id, password_hash, auth_version FROM users WHERE email = ? OR username = ? LIMIT 1');
            $find->execute([strtolower($identifier), $identifier]);
            $user = $find->fetch();
            if (!$user || !password_verify($password, $user['password_hash'])) {
                send_json(['error' => 'Username/email or password is incorrect.'], 401);
            }
            $userId = (int) $user['id'];
            $authVersion = (int) $user['auth_version'];
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['auth_version'] = $authVersion;
        $_SESSION['last_activity'] = time();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        send_json(['authenticated' => true, 'data' => snapshot($pdo, $userId)]);
    }

    if ($method === 'POST' && $action === 'logout') {
        require_csrf();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $cookie = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $cookie['path'],
                'domain' => $cookie['domain'],
                'secure' => $cookie['secure'],
                'httponly' => $cookie['httponly'],
                'samesite' => $cookie['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
        send_json(['authenticated' => false]);
    }

    $userId = current_user_id();
    if ($method !== 'GET') {
        require_csrf();
    }

    if ($method === 'GET' && $action === 'admin-data') {
        require_admin($pdo, $userId);
        $summary = $pdo->query(
            'SELECT
                (SELECT COUNT(*) FROM users) AS total_users,
                (SELECT COUNT(*) FROM folders) AS total_folders,
                (SELECT COUNT(*) FROM files) AS total_files,
                (SELECT COALESCE(SUM(size_bytes), 0) FROM files) AS total_bytes'
        )->fetch();
        $accounts = $pdo->query(
            'SELECT u.id, u.username, u.email, u.role, u.created_at,
                    COUNT(DISTINCT f.id) AS folder_count,
                    COUNT(DISTINCT fi.id) AS file_count,
                    COALESCE((
                        SELECT SUM(upload.size_bytes)
                        FROM files upload
                        JOIN folders owned ON owned.id = upload.folder_id
                        WHERE owned.owner_id = u.id
                    ), 0) AS storage_bytes
             FROM users u
             LEFT JOIN folders f ON f.owner_id = u.id
             LEFT JOIN files fi ON fi.folder_id = f.id
             GROUP BY u.id, u.username, u.email, u.role, u.created_at
             ORDER BY u.created_at DESC, u.id DESC'
        )->fetchAll();
        send_json(['admin' => ['summary' => $summary, 'users' => $accounts]]);
    }

    if ($method === 'POST' && $action === 'admin-delete-user') {
        require_admin($pdo, $userId);
        $input = request_data();
        $targetId = (int) ($input['userId'] ?? 0);
        if ($targetId < 1 || $targetId === $userId) {
            send_json(['error' => 'Choose another account to remove. You cannot delete your own account here.'], 422);
        }

        $pdo->beginTransaction();
        $pdo->query("SELECT id FROM users WHERE role = 'admin' FOR UPDATE")->fetchAll();
        $targetQuery = $pdo->prepare('SELECT id, username, role FROM users WHERE id = ? FOR UPDATE');
        $targetQuery->execute([$targetId]);
        $target = $targetQuery->fetch();
        if (!$target) {
            $pdo->rollBack();
            send_json(['error' => 'The selected account no longer exists.'], 404);
        }
        if ($target['role'] === 'admin') {
            $adminsLeft = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
            if ($adminsLeft <= 1) {
                $pdo->rollBack();
                send_json(['error' => 'Promote another administrator before deleting the last admin account.'], 409);
            }
        }

        $storedQuery = $pdo->prepare(
            'SELECT DISTINCT fi.stored_name FROM files fi
             LEFT JOIN folders f ON f.id = fi.folder_id
             WHERE fi.added_by = ? OR f.owner_id = ?'
        );
        $storedQuery->execute([$targetId, $targetId]);
        $storedNames = $storedQuery->fetchAll(PDO::FETCH_COLUMN);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$targetId]);
        $pdo->commit();

        foreach ($storedNames as $storedName) {
            $path = UPLOAD_DIRECTORY . DIRECTORY_SEPARATOR . basename((string) $storedName);
            if (is_file($path) && !unlink($path)) {
                error_log('Storage admin could not remove uploaded file: ' . basename((string) $storedName));
            }
        }
    } elseif ($method === 'POST' && $action === 'create-folder') {
        $input = request_data();
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 80) {
            send_json(['error' => 'Folder names must be between 1 and 80 characters.'], 422);
        }
        $pdo->beginTransaction();
        $insert = $pdo->prepare('INSERT INTO folders (owner_id, name) VALUES (?, ?)');
        $insert->execute([$userId, $name]);
        $folderId = (int) $pdo->lastInsertId();
        record_activity($pdo, $userId, $folderId, $name, 'created');
        $pdo->commit();
    } elseif ($method === 'POST' && $action === 'delete-folder') {
        $input = request_data();
        $folder = folder_access($pdo, (int) ($input['folderId'] ?? 0), $userId);
        if ((int) $folder['owner_id'] !== $userId) {
            send_json(['error' => 'Only the folder owner can delete it.'], 403);
        }
        $filesQuery = $pdo->prepare('SELECT stored_name FROM files WHERE folder_id = ?');
        $filesQuery->execute([$folder['id']]);
        $storedFiles = $filesQuery->fetchAll(PDO::FETCH_COLUMN);
        $pdo->beginTransaction();
        record_activity($pdo, $userId, null, $folder['name'], 'deleted folder');
        $pdo->prepare('DELETE FROM folders WHERE id = ?')->execute([$folder['id']]);
        $pdo->commit();
        foreach ($storedFiles as $storedName) {
            $path = UPLOAD_DIRECTORY . DIRECTORY_SEPARATOR . $storedName;
            if (is_file($path) && !unlink($path)) {
                error_log('Storage app could not remove uploaded file: ' . $storedName);
            }
        }
    } elseif ($method === 'POST' && $action === 'leave-folder') {
        $input = request_data();
        $folder = folder_access($pdo, (int) ($input['folderId'] ?? 0), $userId);
        if ((int) $folder['owner_id'] === $userId) {
            send_json(['error' => 'Folder owners cannot leave their own folder.'], 422);
        }
        $pdo->beginTransaction();
        record_activity($pdo, $userId, (int) $folder['id'], $folder['name'], 'left');
        $pdo->prepare('DELETE FROM folder_permissions WHERE folder_id = ? AND user_id = ?')->execute([$folder['id'], $userId]);
        $pdo->commit();
    } elseif ($method === 'POST' && $action === 'permissions') {
        $input = request_data();
        $folderId = (int) ($input['folderId'] ?? 0);
        $folder = folder_access($pdo, $folderId, $userId);
        if ((int) $folder['owner_id'] !== $userId) {
            send_json(['error' => 'Only the folder owner can manage permissions.'], 403);
        }
        $permissions = $input['permissions'] ?? null;
        if (!is_array($permissions) || count($permissions) > 500) {
            send_json(['error' => 'Invalid folder permissions.'], 422);
        }
        $friendQuery = $pdo->prepare(
            'SELECT IF(user_one_id = ?, user_two_id, user_one_id) AS friend_id
             FROM friendships WHERE user_one_id = ? OR user_two_id = ?'
        );
        $friendQuery->execute([$userId, $userId, $userId]);
        $friendIds = array_map('intval', array_column($friendQuery->fetchAll(), 'friend_id'));
        $normalized = [];
        foreach ($permissions as $friendId => $permission) {
            $friendId = filter_var($friendId, FILTER_VALIDATE_INT);
            if (!$friendId || !in_array((int) $friendId, $friendIds, true)) {
                send_json(['error' => 'Folders can only be shared with your friends.'], 422);
            }
            if (!in_array($permission, ['viewer', 'editor'], true)) {
                send_json(['error' => 'Choose viewer or editor access.'], 422);
            }
            $normalized[(int) $friendId] = $permission;
        }

        $oldQuery = $pdo->prepare('SELECT user_id, permission FROM folder_permissions WHERE folder_id = ?');
        $oldQuery->execute([$folderId]);
        $oldPermissions = [];
        foreach ($oldQuery->fetchAll() as $old) {
            $oldPermissions[(int) $old['user_id']] = $old['permission'];
        }
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM folder_permissions WHERE folder_id = ?')->execute([$folderId]);
        $insertPermission = $pdo->prepare('INSERT INTO folder_permissions (folder_id, user_id, permission) VALUES (?, ?, ?)');
        foreach ($normalized as $friendId => $permission) {
            $insertPermission->execute([$folderId, $friendId, $permission]);
        }
        foreach (array_unique(array_merge(array_keys($oldPermissions), array_keys($normalized))) as $friendId) {
            if (($oldPermissions[$friendId] ?? null) !== ($normalized[$friendId] ?? null)) {
                $friend = $pdo->prepare('SELECT name FROM users WHERE id = ?');
                $friend->execute([$friendId]);
                $friendName = (string) ($friend->fetchColumn() ?: 'Friend');
                record_activity(
                    $pdo,
                    $userId,
                    $folderId,
                    $folder['name'],
                    isset($normalized[$friendId]) ? 'permission changed' : 'permission removed',
                    $friendName . ' · ' . ($normalized[$friendId] ?? 'no access')
                );
            }
        }
        $pdo->prepare('UPDATE folders SET updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$folderId]);
        $pdo->commit();
    } elseif ($method === 'POST' && $action === 'send-request') {
        $input = request_data();
        $identifier = trim((string) ($input['identifier'] ?? ''));
        if ($identifier === '' || strlen($identifier) > 190) {
            send_json(['error' => 'Enter a username or email address.'], 422);
        }
        $find = $pdo->prepare('SELECT id FROM users WHERE email = ? OR username = ? LIMIT 1');
        $find->execute([strtolower($identifier), $identifier]);
        $recipientId = (int) $find->fetchColumn();
        if ($recipientId < 1) {
            send_json(['error' => 'No account found for that username or email.'], 404);
        }
        if ($recipientId === $userId) {
            send_json(['error' => 'You cannot send a request to yourself.'], 422);
        }
        $first = min($userId, $recipientId);
        $second = max($userId, $recipientId);
        $friendCheck = $pdo->prepare('SELECT 1 FROM friendships WHERE user_one_id = ? AND user_two_id = ?');
        $friendCheck->execute([$first, $second]);
        if ($friendCheck->fetchColumn()) {
            send_json(['error' => 'You are already friends.'], 409);
        }
        $reverse = $pdo->prepare('SELECT id FROM friend_requests WHERE sender_id = ? AND recipient_id = ?');
        $reverse->execute([$recipientId, $userId]);
        $reverseId = (int) $reverse->fetchColumn();
        if ($reverseId > 0) {
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM friend_requests WHERE id = ?')->execute([$reverseId]);
            $pdo->prepare('INSERT INTO friendships (user_one_id, user_two_id) VALUES (?, ?)')->execute([$first, $second]);
            $pdo->commit();
        } else {
            $pending = $pdo->prepare('SELECT 1 FROM friend_requests WHERE sender_id = ? AND recipient_id = ?');
            $pending->execute([$userId, $recipientId]);
            if ($pending->fetchColumn()) {
                send_json(['error' => 'You have already sent this person a request.'], 409);
            }
            $pdo->prepare('INSERT INTO friend_requests (sender_id, recipient_id) VALUES (?, ?)')->execute([$userId, $recipientId]);
        }
    } elseif ($method === 'POST' && $action === 'respond-request') {
        $input = request_data();
        $requestId = (int) ($input['requestId'] ?? 0);
        $accept = ($input['accept'] ?? false) === true;
        $find = $pdo->prepare('SELECT sender_id, recipient_id FROM friend_requests WHERE id = ? AND recipient_id = ?');
        $find->execute([$requestId, $userId]);
        $request = $find->fetch();
        if (!$request) {
            send_json(['error' => 'Friend request not found.'], 404);
        }
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM friend_requests WHERE id = ?')->execute([$requestId]);
        if ($accept) {
            $first = min($userId, (int) $request['sender_id']);
            $second = max($userId, (int) $request['sender_id']);
            $pdo->prepare('INSERT IGNORE INTO friendships (user_one_id, user_two_id) VALUES (?, ?)')->execute([$first, $second]);
        }
        $pdo->commit();
    } elseif ($method === 'POST' && $action === 'cancel-request') {
        $input = request_data();
        $delete = $pdo->prepare('DELETE FROM friend_requests WHERE id = ? AND sender_id = ?');
        $delete->execute([(int) ($input['requestId'] ?? 0), $userId]);
        if ($delete->rowCount() < 1) {
            send_json(['error' => 'Friend request not found.'], 404);
        }
    } elseif ($method === 'POST' && $action === 'remove-friend') {
        $input = request_data();
        $friendId = (int) ($input['friendId'] ?? 0);
        $first = min($userId, $friendId);
        $second = max($userId, $friendId);
        $sharedByMe = $pdo->prepare(
            'SELECT f.id, f.name FROM folders f
             JOIN folder_permissions fp ON fp.folder_id = f.id
             WHERE f.owner_id = ? AND fp.user_id = ?'
        );
        $sharedByMe->execute([$userId, $friendId]);
        $sharedWithMe = $pdo->prepare(
            'SELECT f.id, f.name FROM folders f
             JOIN folder_permissions fp ON fp.folder_id = f.id
             WHERE f.owner_id = ? AND fp.user_id = ?'
        );
        $sharedWithMe->execute([$friendId, $userId]);
        $pdo->beginTransaction();
        foreach ($sharedByMe->fetchAll() as $folder) {
            record_activity($pdo, $userId, (int) $folder['id'], $folder['name'], 'permission removed', 'Friendship ended');
        }
        foreach ($sharedWithMe->fetchAll() as $folder) {
            record_activity($pdo, $userId, (int) $folder['id'], $folder['name'], 'permission removed', 'Friendship ended');
        }
        $pdo->prepare('DELETE FROM folder_permissions WHERE user_id = ? AND folder_id IN (SELECT id FROM folders WHERE owner_id = ?)')->execute([$friendId, $userId]);
        $pdo->prepare('DELETE FROM folder_permissions WHERE folder_id IN (SELECT id FROM folders WHERE owner_id = ?) AND user_id = ?')->execute([$friendId, $userId]);
        $pdo->prepare('DELETE FROM friendships WHERE user_one_id = ? AND user_two_id = ?')->execute([$first, $second]);
        $pdo->commit();
    } elseif ($method === 'POST' && $action === 'upload') {
        $folderId = (int) ($_POST['folderId'] ?? 0);
        $folder = require_folder_editor($pdo, $folderId, $userId);
        if (!isset($_FILES['files']) || !is_array($_FILES['files']['name'] ?? null)) {
            send_json(['error' => 'Choose at least one file to upload.'], 422);
        }
        if (!is_dir(UPLOAD_DIRECTORY) && !mkdir(UPLOAD_DIRECTORY, 0750, true) && !is_dir(UPLOAD_DIRECTORY)) {
            throw new RuntimeException('Could not create the private upload directory.');
        }
        $allowed = [
            'jpg' => ['image/jpeg', 'image/jpeg'],
            'jpeg' => ['image/jpeg', 'image/jpeg'],
            'png' => ['image/png', 'image/png'],
            'gif' => ['image/gif', 'image/gif'],
            'webp' => ['image/webp', 'image/webp'],
            'mp4' => ['video/mp4', 'video/mp4'],
            'webm' => ['video/webm', 'video/webm'],
            'mov' => ['video/quicktime', 'video/quicktime'],
            'pdf' => ['application/pdf', 'application/pdf'],
            'doc' => ['application/msword', 'application/msword'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
            'xls' => ['application/vnd.ms-excel', 'application/vnd.ms-excel'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
            'ppt' => ['application/vnd.ms-powerpoint', 'application/vnd.ms-powerpoint'],
            'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
            'txt' => ['text/plain', 'text/plain'],
            'csv' => ['text/csv', 'text/plain', 'application/vnd.ms-excel'],
            'zip' => ['application/zip', 'application/x-zip', 'application/octet-stream'],
        ];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $stored = [];
        $total = count($_FILES['files']['name']);
        if ($total > 20) {
            send_json(['error' => 'Upload no more than 20 files at a time.'], 422);
        }
        foreach ($_FILES['files']['name'] as $index => $originalName) {
            $error = (int) $_FILES['files']['error'][$index];
            if ($error !== UPLOAD_ERR_OK) {
                send_json(['error' => 'A file could not be uploaded. Check your PHP upload limits and try again.'], 422);
            }
            $size = (int) $_FILES['files']['size'][$index];
            $extension = strtolower(pathinfo((string) $originalName, PATHINFO_EXTENSION));
            $temporary = (string) $_FILES['files']['tmp_name'][$index];
            $mime = $finfo->file($temporary);
            if ($size < 1 || $size > MAX_UPLOAD_BYTES || !isset($allowed[$extension]) || !in_array($mime, $allowed[$extension], true)) {
                send_json(['error' => 'Unsupported file type or size. Use an allowed image, video, or document up to 100 MB.'], 422);
            }
            $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
            if (!move_uploaded_file($temporary, UPLOAD_DIRECTORY . DIRECTORY_SEPARATOR . $storedName)) {
                throw new RuntimeException('Could not save an uploaded file.');
            }
            $stored[] = ['name' => basename((string) $originalName), 'stored' => $storedName, 'mime' => $mime, 'size' => $size];
        }
        try {
            $pdo->beginTransaction();
            $insert = $pdo->prepare('INSERT INTO files (folder_id, added_by, original_name, stored_name, mime_type, size_bytes) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($stored as $file) {
                $insert->execute([$folderId, $userId, mb_substr($file['name'], 0, 255), $file['stored'], $file['mime'], $file['size']]);
                record_activity($pdo, $userId, $folderId, $folder['name'], 'uploaded', $file['name']);
            }
            $pdo->prepare('UPDATE folders SET updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$folderId]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            foreach ($stored as $file) {
                $path = UPLOAD_DIRECTORY . DIRECTORY_SEPARATOR . $file['stored'];
                if (is_file($path)) {
                    unlink($path);
                }
            }
            throw $exception;
        }
    } elseif ($method === 'POST' && $action === 'delete-file') {
        $input = request_data();
        $fileId = (int) ($input['fileId'] ?? 0);
        $query = $pdo->prepare(
            'SELECT fi.id, fi.stored_name, fi.folder_id, f.name AS folder_name
             FROM files fi JOIN folders f ON f.id = fi.folder_id WHERE fi.id = ?'
        );
        $query->execute([$fileId]);
        $file = $query->fetch();
        if (!$file) {
            send_json(['error' => 'File not found.'], 404);
        }
        require_folder_editor($pdo, (int) $file['folder_id'], $userId);
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM files WHERE id = ?')->execute([$fileId]);
        record_activity($pdo, $userId, (int) $file['folder_id'], $file['folder_name'], 'deleted', (string) ($input['fileName'] ?? 'file'));
        $pdo->commit();
        $path = UPLOAD_DIRECTORY . DIRECTORY_SEPARATOR . $file['stored_name'];
        if (is_file($path) && !unlink($path)) {
            error_log('Storage app could not remove uploaded file: ' . $file['stored_name']);
        }
    } else {
        send_json(['error' => 'Unknown API action.'], 404);
    }

    send_json(['authenticated' => true, 'data' => snapshot($pdo, $userId)]);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Storage API error: ' . $exception->getMessage());
    send_json(['error' => 'The server could not complete that request. Check the PHP error log for details.'], 500);
}
