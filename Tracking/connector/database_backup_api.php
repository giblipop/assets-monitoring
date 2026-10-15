<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database_backup.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function asset_backup_api_respond(array $body, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function asset_backup_api_require_owner(): void
{
    $userId = (int)($_SESSION['uid'] ?? 0);
    $lastActivity = (int)($_SESSION['last_activity'] ?? 0);
    if ($userId < 1 || !$lastActivity || time() - $lastActivity > 1200) {
        asset_backup_api_respond(['success' => false, 'message' => 'Your session has expired. Sign in again.'], 401);
    }
    if ((int)($_SESSION['workspace_owner_id'] ?? 0) !== $userId ||
        ($_SESSION['workspace_role'] ?? '') !== 'owner') {
        asset_backup_api_respond([
            'success' => false,
            'message' => 'Only the owner of the selected workspace can manage its database backup.'
        ], 403);
    }
    $_SESSION['last_activity'] = time();
}

function asset_backup_api_csrf(): void
{
    $token = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $sessionToken = (string)($_SESSION['csrf'] ?? '');
    if ($token === '' || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
        asset_backup_api_respond(['success' => false, 'message' => 'Refresh this page and try again.'], 403);
    }
}

$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = (string)($_GET['action'] ?? '');
if ($method === 'GET' && $action === 'health') {
    $userId = (int)($_SESSION['uid'] ?? 0);
    $lastActivity = (int)($_SESSION['last_activity'] ?? 0);
    $workspaceOwnerId = (int)($_SESSION['workspace_owner_id'] ?? 0);
    if ($userId > 0 && $lastActivity > 0 && time() - $lastActivity <= 1200) {
        $_SESSION['last_activity'] = time();
    }
    if ($workspaceOwnerId > 0 && $userId > 0) {
        $workspaceBackup = asset_backup_workspace_file($workspaceOwnerId);
        $latest = is_file($workspaceBackup) ? [
            'filename' => 'workspace-backup.json',
            'created_at' => gmdate('c', filemtime($workspaceBackup)),
            'size_bytes' => filesize($workspaceBackup) ?: 0
        ] : null;
        $backupFailure = asset_backup_failure_marker($workspaceOwnerId);
    } else {
        $latest = asset_backup_list()[0] ?? null;
        $backupFailure = asset_backup_failure_marker();
    }
    $backupHealthy = is_array($latest) && $backupFailure === null;
    $backupMessage = $backupFailure !== null
        ? 'The most recent automatic backup failed. Create a backup now and check the server error log.'
        : (!$latest
            ? 'No encrypted database backup exists yet.'
            : ($backupHealthy ? '' : 'The latest automatic JSON backup needs attention.'));
    try {
        $server = asset_backup_server();
        $server->query('SELECT 1')->fetchColumn();
        $databases = asset_backup_databases($server, false);
        $healthy = in_array('inventory', $databases, true) && in_array('control_panel_db', $databases, true);
        $healthMessage = $healthy ? '' : 'One or more required system databases are missing.';
        if ($healthy) {
            $present = array_fill_keys($databases, true);
            $server->query('SELECT 1 FROM inventory.Users LIMIT 0');
            $server->query('SELECT 1 FROM control_panel_db.navigation_menu LIMIT 0');
            foreach ($server->query('SELECT UserID FROM inventory.Users')->fetchAll(PDO::FETCH_COLUMN) as $userId) {
                $id = (int)$userId;
                $namedDatabases = asset_backup_workspace_database_names($server, $id);
                $legacyDatabases = asset_backup_legacy_workspace_database_names($id);
                $inventoryName = $namedDatabases['inventory'];
                $controlName = $namedDatabases['control'];
                $hasInventory = isset($present[$inventoryName]);
                $hasControl = isset($present[$controlName]);
                if (!$hasInventory && !$hasControl) {
                    $inventoryName = $legacyDatabases['inventory'];
                    $controlName = $legacyDatabases['control'];
                    $hasInventory = isset($present[$inventoryName]);
                    $hasControl = isset($present[$controlName]);
                }
                if (!$hasInventory && !$hasControl) {
                    // Older accounts use the shared inventory database when no tenant pair exists.
                    continue;
                }
                if ($hasInventory !== $hasControl) {
                    $healthy = false;
                    $healthMessage = 'A workspace has only one of its required databases. Contact the administrator to repair the workspace setup.';
                    break;
                }
                $server->query(
                    'SELECT 1 FROM ' . asset_backup_quote($inventoryName) . '.`Users` LIMIT 0'
                );
                $server->query(
                    'SELECT 1 FROM ' . asset_backup_quote($controlName) . '.`navigation_menu` LIMIT 0'
                );
            }
        }
        asset_backup_api_respond([
            'success' => true,
            'database_healthy' => $healthy,
            'message' => $healthMessage,
            'backup_healthy' => $backupHealthy,
            'backup_message' => $backupMessage,
            'latest_backup' => $latest
        ]);
    } catch (Throwable $error) {
        error_log('Database health check failed: ' . $error->getMessage());
        asset_backup_api_respond([
            'success' => true,
            'database_healthy' => false,
            'message' => 'The MySQL service or system databases could not be reached.',
            'backup_healthy' => $backupHealthy,
            'backup_message' => $backupMessage,
            'latest_backup' => $latest
        ]);
    }
}

asset_backup_api_require_owner();

if ($method === 'GET' && $action === 'csrf') {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    asset_backup_api_respond(['success' => true, 'csrf' => $_SESSION['csrf']]);
}
if ($method === 'GET' && $action === 'list') {
    $ownerId = (int)$_SESSION['workspace_owner_id'];
    $path = asset_backup_workspace_file($ownerId);
    $backups = is_file($path) ? [[
        'filename' => 'workspace-backup.json',
        'created_at' => gmdate('c', filemtime($path)),
        'size_bytes' => filesize($path) ?: 0
    ]] : [];
    asset_backup_api_respond(['success' => true, 'backups' => $backups]);
}
if ($method === 'GET' && $action === 'modules') {
    $ownerId = (int)$_SESSION['workspace_owner_id'];
    $directory = asset_backup_module_directory($ownerId);
    $files = glob($directory . DIRECTORY_SEPARATOR . '*.json') ?: [];
    if (!$files) {
        try {
            asset_backup_create_workspace(asset_backup_server(), $ownerId);
            $files = glob($directory . DIRECTORY_SEPARATOR . '*.json') ?: [];
        } catch (Throwable $error) {
            error_log('Initial module JSON creation failed: ' . $error->getMessage());
            asset_backup_api_respond(['success' => false, 'message' => 'Could not create this workspace module backup. Verify that MySQL is running.'], 503);
        }
    }
    $modules = [];
    foreach ($files as $file) {
        $name = basename($file, '.json');
        if (!asset_backup_is_module_table($name)) continue;
        $contents = file_get_contents($file);
        $module = is_string($contents) ? json_decode($contents, true) : null;
        if (!is_array($module) || (int)($module['workspace_owner_id'] ?? 0) !== $ownerId
            || ($module['module'] ?? '') !== $name || !is_array($module['table']['rows'] ?? null)) continue;
        $modules[] = ['name' => $name, 'filename' => basename($file),
            'created_at' => (string)($module['created_at'] ?? ''),
            'row_count' => count($module['table']['rows'])];
    }
    usort($modules, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
    asset_backup_api_respond(['success' => true, 'modules' => $modules]);
}
if ($method === 'GET' && $action === 'module') {
    $moduleName = (string)($_GET['name'] ?? '');
    if (!asset_backup_is_module_table($moduleName)) {
        asset_backup_api_respond(['success' => false, 'message' => 'Choose a valid inventory module.'], 400);
    }
    $path = asset_backup_module_directory((int)$_SESSION['workspace_owner_id'])
        . DIRECTORY_SEPARATOR . $moduleName . '.json';
    $contents = is_file($path) ? file_get_contents($path) : false;
    $module = is_string($contents) ? json_decode($contents, true) : null;
    if (!is_array($module) || (int)($module['workspace_owner_id'] ?? 0) !== (int)$_SESSION['workspace_owner_id']
        || ($module['module'] ?? '') !== $moduleName) {
        asset_backup_api_respond(['success' => false, 'message' => 'The selected module JSON file is unavailable.'], 404);
    }
    asset_backup_api_respond(['success' => true, 'module' => $module]);
}
if ($method === 'GET' && $action === 'secondary_database') {
    $ownerId = (int)$_SESSION['workspace_owner_id'];
    $path = asset_backup_workspace_directory($ownerId) . DIRECTORY_SEPARATOR . 'secondary-database.json';
    if (!is_file($path)) {
        try {
            asset_backup_create_workspace(asset_backup_server(), $ownerId);
        } catch (Throwable $error) {
            error_log('Initial workspace JSON mirror creation failed: ' . $error->getMessage());
            asset_backup_api_respond(['success' => false, 'message' => 'The automatic JSON mirror is not available yet. Verify that MySQL is running.'], 503);
        }
    }
    $contents = file_get_contents($path);
    $document = is_string($contents) ? json_decode($contents, true) : null;
    if (!is_array($document) || (int)($document['workspace_owner_id'] ?? 0) !== $ownerId) {
        asset_backup_api_respond(['success' => false, 'message' => 'The workspace JSON mirror is unreadable or invalid.'], 500);
    }
    asset_backup_api_respond([
        'success' => true,
        'filename' => basename($path),
        'created_at' => (string)($document['created_at'] ?? ''),
        'document' => $document
    ]);
}
if ($method === 'GET' && $action === 'download') {
    $filename = 'workspace-backup.json';
    $path = asset_backup_workspace_file((int)$_SESSION['workspace_owner_id']);
    if (!is_file($path)) {
        asset_backup_api_respond(['success' => false, 'message' => 'The selected backup file is unavailable.'], 404);
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . (string)filesize($path));
    readfile($path);
    exit;
}
if ($method !== 'POST') {
    header('Allow: GET, POST');
    asset_backup_api_respond(['success' => false, 'message' => 'Use GET or POST for backup actions.'], 405);
}
$isMultipart = str_starts_with(
    strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')),
    'multipart/form-data'
);
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > ($isMultipart ? 33554432 : 4096)) {
    asset_backup_api_respond(['success' => false, 'message' => 'The backup request is too large.'], 413);
}
asset_backup_api_csrf();
$request = $isMultipart
    ? $_POST
    : json_decode((string)file_get_contents('php://input'), true);
if (!is_array($request)) {
    asset_backup_api_respond(['success' => false, 'message' => 'The backup request is invalid.'], 400);
}

try {
    if (($request['action'] ?? '') === 'restore_module') {
        $moduleName = (string)($request['module'] ?? '');
        asset_backup_restore_module(asset_backup_server(), (int)$_SESSION['workspace_owner_id'], $moduleName);
        asset_backup_create_workspace(asset_backup_server(), (int)$_SESSION['workspace_owner_id']);
        asset_backup_api_respond([
            'success' => true,
            'message' => 'The ' . $moduleName . ' module was recreated from its JSON file.'
        ]);
    }
    if (($request['action'] ?? '') === 'create') {
        try {
            $backup = asset_backup_create_workspace(
                asset_backup_server(),
                (int)$_SESSION['workspace_owner_id']
            );
        } catch (Throwable $error) {
            asset_backup_mark_failure($error, (int)$_SESSION['workspace_owner_id']);
            throw $error;
        }
        asset_backup_api_respond([
            'success' => true,
            'message' => 'Encrypted workspace JSON backup created.',
            'backup' => $backup
        ]);
    }
    if (($request['action'] ?? '') === 'restore') {
        $filename = (string)($request['filename'] ?? '');
        $password = (string)($request['password'] ?? '');
        if ($password === '' || strlen($password) > 100) {
            asset_backup_api_respond(['success' => false, 'message' => 'Enter the backup encryption password.'], 400);
        }
        if ($filename !== 'workspace-backup.json') {
            asset_backup_api_respond(['success' => false, 'message' => 'Choose this workspace backup.'], 400);
        }
        asset_backup_restore_workspace(
            asset_backup_server(),
            (int)$_SESSION['workspace_owner_id'],
            $password
        );
        asset_backup_api_respond([
            'success' => true,
            'message' => 'This workspace was restored from its JSON backup.'
        ]);
    }
    if (($request['action'] ?? '') === 'restore_upload') {
        $upload = $_FILES['backup'] ?? null;
        $password = (string)($request['password'] ?? '');
        if (!is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK ||
            !is_uploaded_file((string)($upload['tmp_name'] ?? '')) ||
            (int)($upload['size'] ?? 0) < 1 || (int)$upload['size'] > 33554432) {
            asset_backup_api_respond([
                'success' => false,
                'message' => 'Choose a valid encrypted JSON backup file under 32 MB.'
            ], 400);
        }
        if ($password === '' || strlen($password) > 100) {
            asset_backup_api_respond(['success' => false, 'message' => 'Enter the backup encryption password.'], 400);
        }
        $contents = file_get_contents((string)$upload['tmp_name']);
        if ($contents === false) {
            asset_backup_api_respond(['success' => false, 'message' => 'The uploaded backup could not be read.'], 400);
        }
        $document = asset_backup_decrypt($contents, $password);
        if ((int)($document['workspace_owner_id'] ?? 0) !== (int)$_SESSION['workspace_owner_id']) {
            asset_backup_api_respond(['success' => false, 'message' => 'This JSON file is not a backup of the selected workspace.'], 403);
        }
        asset_backup_restore_document(asset_backup_server(), $document);
        asset_backup_api_respond([
            'success' => true,
            'message' => 'This workspace was restored from the uploaded JSON backup.'
        ]);
    }
    asset_backup_api_respond(['success' => false, 'message' => 'Choose a supported backup action.'], 400);
} catch (Throwable $error) {
    error_log('System JSON backup action failed: ' . $error->getMessage());
    asset_backup_api_respond([
        'success' => false,
        'message' => $error instanceof InvalidArgumentException
            ? $error->getMessage()
            : 'The backup action failed. Check the PHP error log and verify that MySQL is running.'
    ], 500);
}
