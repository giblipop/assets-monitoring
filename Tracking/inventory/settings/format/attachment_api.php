<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../connector/auth_guard.php';
require_once __DIR__ . '/../../../connector/config.php';

function attachment_respond(array $body, int $status): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function attachment_storage_directory(string $controlDatabase): string
{
    $documentRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $storageRoot = $documentRoot !== false && $documentRoot !== ''
        ? dirname($documentRoot)
        : sys_get_temp_dir();
    return rtrim($storageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        . 'asset-monitoring-private' . DIRECTORY_SEPARATOR . hash('sha256', $controlDatabase);
}

$method = (string)($_SERVER['REQUEST_METHOD'] ?? '');
if ($method === 'GET') {
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    if (($_GET['action'] ?? '') === 'csrf') {
        if (!in_array((string)($_SESSION['workspace_role'] ?? ''), ['owner', 'editor'], true)) {
            attachment_respond(['success' => false, 'message' => 'You do not have permission to edit this workspace.'], 403);
        }
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        attachment_respond(['success' => true, 'csrf' => $_SESSION['csrf']], 200);
    }
    $id = (string)($_GET['id'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
        http_response_code(404);
        exit;
    }
    try {
        $control = new PDO(
            "mysql:host={$DB_HOST};dbname={$DB_CONTROL};charset=utf8mb4",
            $DB_USER,
            $DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $lookup = $control->prepare(
            'SELECT storage_name, original_name, mime_type, file_size FROM inventory_attachments WHERE attachment_id = ?'
        );
        $lookup->execute([$id]);
        $attachment = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!$attachment) {
            http_response_code(404);
            exit;
        }
        $path = attachment_storage_directory($DB_CONTROL) . DIRECTORY_SEPARATOR . $attachment['storage_name'];
        if (!is_file($path) || filesize($path) !== (int)$attachment['file_size']) {
            error_log('Stored inventory attachment is missing or has an unexpected size: ' . $id);
            http_response_code(404);
            exit;
        }
        $inline = isset($_GET['inline']) && $_GET['inline'] === 'image'
            && strpos((string)$attachment['mime_type'], 'image/') === 0;
        header('Content-Type: ' . $attachment['mime_type']);
        header('Content-Length: ' . (int)$attachment['file_size']);
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
            . '; filename*=UTF-8\'\'' . rawurlencode((string)$attachment['original_name']));
        readfile($path);
        exit;
    } catch (Throwable $error) {
        error_log('Inventory attachment download failed: ' . $error->getMessage());
        http_response_code(500);
        exit;
    }
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    attachment_respond(['success' => false, 'message' => 'Use POST to upload an attachment.'], 405);
}

$token = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$sessionToken = (string)($_SESSION['csrf'] ?? '');
if ($token === '' || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
    attachment_respond(['success' => false, 'message' => 'Refresh the page and try again.'], 403);
}
if (!in_array((string)($_SESSION['workspace_role'] ?? ''), ['owner', 'editor'], true)) {
    attachment_respond(['success' => false, 'message' => 'You do not have permission to upload attachments.'], 403);
}

$mimeExtensions = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
    'application/pdf' => 'pdf',
    'text/plain' => 'txt',
    'text/csv' => 'csv',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.ms-excel' => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'application/vnd.ms-powerpoint' => 'ppt',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx'
];
$uploads = [];
$filesField = $_FILES['files'] ?? null;
if (is_array($filesField) && is_array($filesField['name'] ?? null)) {
    $count = count($filesField['name']);
    if ($count < 1 || $count > 10) {
        attachment_respond(['success' => false, 'message' => 'Upload between 1 and 10 files per batch.'], 400);
    }
    for ($index = 0; $index < $count; $index++) {
        $uploads[] = [
            'name' => $filesField['name'][$index] ?? '',
            'tmp_name' => $filesField['tmp_name'][$index] ?? '',
            'size' => $filesField['size'][$index] ?? 0,
            'error' => $filesField['error'][$index] ?? UPLOAD_ERR_NO_FILE
        ];
    }
} elseif (isset($_FILES['file'])) {
    $uploads[] = $_FILES['file'];
}

if (!$uploads) {
    attachment_respond(['success' => false, 'message' => 'Choose one or more files to upload.'], 400);
}

$fileInfo = new finfo(FILEINFO_MIME_TYPE);
$totalBytes = 0;
foreach ($uploads as &$upload) {
    if ((int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || !is_uploaded_file((string)($upload['tmp_name'] ?? ''))
        || (int)($upload['size'] ?? 0) < 1 || (int)$upload['size'] > 20 * 1024 * 1024) {
        attachment_respond(['success' => false, 'message' => 'Choose files smaller than 20 MB each and try again.'], 400);
    }
    $upload['mime'] = (string)$fileInfo->file((string)$upload['tmp_name']);
    if (!isset($mimeExtensions[$upload['mime']])) {
        attachment_respond(['success' => false, 'message' => 'Supported files: images, PDF, Word, Excel, PowerPoint, TXT, and CSV.'], 415);
    }
    $totalBytes += (int)$upload['size'];
    if ($totalBytes > 24 * 1024 * 1024) {
        attachment_respond(['success' => false, 'message' => 'The combined upload batch must be smaller than 24 MB.'], 413);
    }
    $originalName = basename(str_replace('\\', '/', (string)($upload['name'] ?? 'attachment')));
    $originalName = preg_replace('/[\x00-\x1F\x7F]/u', '', $originalName) ?: 'attachment';
    $upload['original_name'] = mb_substr($originalName, 0, 180, 'UTF-8');
    $upload['attachment_id'] = bin2hex(random_bytes(16));
    $upload['storage_name'] = $upload['attachment_id'] . '.' . $mimeExtensions[$upload['mime']];
}
unset($upload);

$directory = attachment_storage_directory($DB_CONTROL);
$createdDirectory = false;
if (!is_dir($directory)) {
    if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
        attachment_respond(['success' => false, 'message' => 'Private attachment storage is unavailable.'], 500);
    }
    $createdDirectory = true;
}
$storedFiles = [];
try {
    foreach ($uploads as $upload) {
        $target = $directory . DIRECTORY_SEPARATOR . $upload['storage_name'];
        if (!move_uploaded_file((string)$upload['tmp_name'], $target)) {
            throw new RuntimeException('An uploaded file could not be stored.');
        }
        $storedFiles[] = $target;
    }
    global $DB_HOST, $DB_USER, $DB_PASS, $DB_CONTROL;
    $control = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_CONTROL};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $control->exec(
        'CREATE TABLE IF NOT EXISTS inventory_attachments ('
        . 'attachment_id CHAR(32) NOT NULL PRIMARY KEY,'
        . 'storage_name VARCHAR(80) NOT NULL,'
        . 'original_name VARCHAR(180) NOT NULL,'
        . 'mime_type VARCHAR(100) NOT NULL,'
        . 'file_size BIGINT UNSIGNED NOT NULL,'
        . 'uploaded_by BIGINT UNSIGNED NOT NULL,'
        . 'uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $control->beginTransaction();
    $insert = $control->prepare(
        'INSERT INTO inventory_attachments (attachment_id, storage_name, original_name, mime_type, file_size, uploaded_by) '
        . 'VALUES (?, ?, ?, ?, ?, ?)'
    );
    foreach ($uploads as $upload) {
        $insert->execute([
            $upload['attachment_id'],
            $upload['storage_name'],
            $upload['original_name'],
            $upload['mime'],
            (int)$upload['size'],
            (int)($_SESSION['uid'] ?? 0)
        ]);
    }
    $control->commit();
} catch (Throwable $error) {
    if (isset($control) && $control instanceof PDO && $control->inTransaction()) {
        $control->rollBack();
    }
    foreach ($storedFiles as $path) {
        if (is_file($path) && !unlink($path)) {
            error_log('Could not remove a failed inventory attachment upload.');
        }
    }
    if ($createdDirectory && is_dir($directory) && count(scandir($directory) ?: []) <= 2) rmdir($directory);
    error_log('Inventory attachment metadata could not be saved: ' . $error->getMessage());
    attachment_respond(['success' => false, 'message' => 'The file could not be attached to this workspace.'], 500);
}

$attachments = array_map(static function (array $upload): array {
    $isImage = strpos($upload['mime'], 'image/') === 0;
    return [
        'url' => 'attachment_api.php?id=' . $upload['attachment_id'] . ($isImage ? '&inline=image' : ''),
        'name' => $upload['original_name'],
        'mime_type' => $upload['mime']
    ];
}, $uploads);
$response = ['success' => true, 'attachments' => $attachments];
if (count($attachments) === 1) {
    $response += $attachments[0];
}
attachment_respond($response, 200);
