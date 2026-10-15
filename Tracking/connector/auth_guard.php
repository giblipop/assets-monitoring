<?php
// Include before processing protected inventory pages or API requests.
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$https,'httponly'=>true,'samesite'=>'Lax']);
    session_start();
}
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database_backup.php';
$sessionUserId = (int)($_SESSION['uid'] ?? 0);
$workspaceOwnerId = (int)($_SESSION['workspace_owner_id'] ?? $sessionUserId);
$tenantInventoryDatabase = '';
$tenantControlDatabase = '';
if ($sessionUserId > 0) {
    try {
        $server = new PDO(
            "mysql:host={$DB_HOST};dbname={$DB_INVENTORY};charset=utf8mb4",
            $DB_USER,
            $DB_PASS,
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
        );
        $workspaceDatabases = asset_backup_existing_workspace_database_names($server, $workspaceOwnerId);
        $tenantInventoryDatabase = $workspaceDatabases['inventory'];
        $tenantControlDatabase = $workspaceDatabases['control'];
        $account = $server->prepare('SELECT Status, MustChangePassword FROM Users WHERE UserID = ?');
        $account->execute([$sessionUserId]);
        $accountDetails = $account->fetch(PDO::FETCH_ASSOC);
        if (($accountDetails['Status'] ?? '') !== 'active') {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, private');
            echo json_encode(['success'=>false,'message'=>'This account is unavailable. Please sign in again.']);
            exit;
        }
        if ((int)$accountDetails['MustChangePassword'] === 1) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, private');
            echo json_encode(['success'=>false,'message'=>'Change your temporary password before accessing the workspace.']);
            exit;
        }
        if ($workspaceOwnerId === $sessionUserId) {
            $workspaceRole = 'owner';
        } else {
            $membership = $server->prepare(
                'SELECT wm.Role FROM WorkspaceMembers wm '
                . 'JOIN Users owner ON owner.UserID = wm.OwnerUserID AND owner.Status = "active" '
                . 'WHERE wm.OwnerUserID = ? AND wm.MemberUserID = ? AND wm.Status = "active"'
            );
            $membership->execute([$workspaceOwnerId, $sessionUserId]);
            $workspaceRole = $membership->fetchColumn();
            if (!in_array($workspaceRole, ['viewer', 'editor'], true)) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                header('Cache-Control: no-store, private');
                echo json_encode(['success'=>false,'message'=>'You no longer have access to this workspace. Switch to your own workspace and try again.']);
                exit;
            }
        }
        $check = $server->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME IN (?,?)');
        $check->execute([$tenantInventoryDatabase, $tenantControlDatabase]);
        $tenantDatabaseCount = (int)$check->fetchColumn();
    } catch (Throwable $error) {
        error_log('Workspace access verification failed: ' . $error->getMessage());
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        echo json_encode(['success'=>false,'message'=>'Unable to verify workspace access. Please try again later.']);
        exit;
    }
    if ($tenantDatabaseCount === 0 && $workspaceOwnerId === $sessionUserId
        && hash_equals($DB_INVENTORY, (string)($_SESSION['tenant_db'] ?? ''))) {
        $_SESSION['workspace_role'] = 'owner';
    } elseif ($tenantDatabaseCount !== 2) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        echo json_encode(['success'=>false,'message'=>'The selected workspace database setup is incomplete. Please contact the administrator.']);
        exit;
    } elseif (!hash_equals($tenantInventoryDatabase, (string)($_SESSION['tenant_db'] ?? ''))) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        echo json_encode(['success'=>false,'message'=>'The selected workspace is invalid. Please switch workspaces and try again.']);
        exit;
    } else {
        $DB_INVENTORY = $tenantInventoryDatabase;
        $DB_CONTROL = $tenantControlDatabase;
        $_SESSION['workspace_role'] = $workspaceRole;
    }
}
$lastActivity = (int)($_SESSION['last_activity'] ?? 0);
if (empty($_SESSION['uid']) || !$lastActivity || time() - $lastActivity > 1200) {
    if (!empty($_SESSION['login_id']) && ($_SESSION['login_db'] ?? '') === 'inventory') {
        try {
            $pdo = new PDO("mysql:host={$DB_HOST};dbname=inventory;charset=utf8mb4", $DB_USER, $DB_PASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $stmt = $pdo->prepare('UPDATE LoginHistory SET LogoutDateTime=NOW(), IsCurrentSession=0 WHERE LoginID=?');
            $stmt->execute([$_SESSION['login_id']]);
            require_once __DIR__ . '/activity_logger.php';
            record_user_activity('Session expired', 'authentication');
        } catch (Throwable $error) {
            error_log('Session-expiry activity logging failed: ' . $error->getMessage());
        }
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', ['expires'=>time()-3600,'path'=>$params['path'],'domain'=>$params['domain'],'secure'=>$params['secure'],'httponly'=>$params['httponly'],'samesite'=>'Lax']);
    }
    session_destroy();
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode(['success'=>false,'message'=>'Your session has expired. Please sign in again.']);
    exit;
}
$_SESSION['last_activity'] = time();
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestPath = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
$hasRequestBody = (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 || !empty($_SERVER['HTTP_TRANSFER_ENCODING']);
if (($_SESSION['workspace_role'] ?? '') === 'viewer'
    && ($requestMethod !== 'GET' || $hasRequestBody
        || preg_match('#/(delete|update(?:_[a-z0-9_-]+)?|manual_add)\.php$#i', $requestPath))) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode(['success'=>false,'message'=>'You have view-only access to this workspace. Ask its owner for edit access.']);
    exit;
}
if (($_SESSION['workspace_role'] ?? '') !== 'owner'
    && !in_array($requestMethod, ['GET', 'HEAD', 'OPTIONS'], true)
    && preg_match('#/inventory/settings/(control_panel_api|link_button)\.php$#', $requestPath)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode(['success'=>false,'message'=>'Only the workspace owner can change system navigation and settings.']);
    exit;
}
if ($requestMethod === 'POST' && strcasecmp(basename($requestPath), 'database_backup_api.php') !== 0) {
    register_shutdown_function('asset_backup_after_write');
}
