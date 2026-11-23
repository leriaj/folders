<?php
declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
start_app_session();
$userId = current_user_id();
$fileId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$fileId) {
    http_response_code(400);
    exit('Invalid file.');
}

try {
    $pdo = database();
    $query = $pdo->prepare(
        'SELECT fi.stored_name, fi.original_name, fi.mime_type, f.id AS folder_id
         FROM files fi JOIN folders f ON f.id = fi.folder_id WHERE fi.id = ?'
    );
    $query->execute([$fileId]);
    $file = $query->fetch();
    if (!$file) {
        http_response_code(404);
        exit('File not found.');
    }
    folder_access($pdo, (int) $file['folder_id'], $userId);
    $path = UPLOAD_DIRECTORY . DIRECTORY_SEPARATOR . $file['stored_name'];
    if (!is_file($path)) {
        http_response_code(404);
        exit('File not found.');
    }
    $safeName = str_replace(["\r", "\n", '"'], '', (string) $file['original_name']);
    $preview = ($_GET['preview'] ?? '') === '1';
    $isPreviewableMedia = str_starts_with($file['mime_type'], 'image/')
        || str_starts_with($file['mime_type'], 'video/');
    if ($preview && !$isPreviewableMedia) {
        http_response_code(415);
        exit('Only images and videos can be previewed.');
    }
    header('Content-Type: ' . $file['mime_type']);
    header('Content-Length: ' . (string) filesize($path));
    header('Content-Disposition: ' . ($preview ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($safeName));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    if ($preview) {
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header('Cross-Origin-Resource-Policy: same-origin');
    }
    readfile($path);
} catch (Throwable $exception) {
    error_log('Storage download error: ' . $exception->getMessage());
    http_response_code(500);
    exit('The file could not be downloaded.');
}
