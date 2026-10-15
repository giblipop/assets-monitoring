<?php
declare(strict_types=1);

require_once __DIR__ . '/../Tracking/connector/config.php';
require_once __DIR__ . '/../Tracking/connector/database_backup.php';

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function admin_respond(array $body, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function admin_body(): array
{
    $body = json_decode(file_get_contents('php://input'), true);
    return is_array($body) ? $body : [];
}

function admin_csrf_check(): void
{
    $token = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($token === '' || !hash_equals((string)($_SESSION['admin_csrf'] ?? ''), $token)) {
        admin_respond(['success' => false, 'message' => 'Your session expired. Refresh and try again.'], 403);
    }
}

function admin_pdo(bool $withDatabase = false): PDO
{
    global $DB_HOST, $DB_USER, $DB_PASS, $DB_INVENTORY;
    $database = $withDatabase ? ';dbname=' . $DB_INVENTORY : '';
    return new PDO(
        "mysql:host={$DB_HOST}{$database};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

function admin_require_session(): void
{
    $lastActivity = (int)($_SESSION['admin_last_activity'] ?? 0);
    if (empty($_SESSION['admin_authenticated']) || !$lastActivity || time() - $lastActivity > 1200) {
        unset(
            $_SESSION['admin_authenticated'],
            $_SESSION['admin_last_activity'],
            $_SESSION['admin_csrf'],
            $_SESSION['admin_archive_password']
        );
        admin_respond(['success' => false, 'message' => 'Your admin session has expired. Please sign in again.'], 401);
    }
    $_SESSION['admin_last_activity'] = time();
}

function admin_quote_identifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function admin_configured_password_hash(): string
{
    return (string)(getenv('ASSETS_ADMIN_PASSWORD_HASH') ?: ($_SERVER['ASSETS_ADMIN_PASSWORD_HASH'] ?? ''));
}

function admin_verify_password(string $password): bool
{
    $hash = admin_configured_password_hash();
    return $hash !== '' && strlen($password) <= 72 && password_verify($password, $hash);
}

function admin_archive_password(): string
{
    $password = (string)($_SESSION['admin_archive_password'] ?? '');
    if ($password === '') {
        admin_respond(['success' => false, 'message' => 'Your admin session needs refreshing. Please sign in again.'], 401);
    }
    return $password;
}

function admin_latest_full_backup(): array
{
    $backups = asset_backup_list();
    if (!$backups) {
        throw new RuntimeException('No full-system database backup is available.');
    }
    foreach ($backups as $backup) {
        $filename = (string)($backup['filename'] ?? '');
        if (!preg_match('/^assets-monitoring-[0-9]{8}-[0-9]{6}-[a-f0-9]{6}\.json$/', $filename)) {
            error_log('Skipping a system backup with an invalid filename.');
            continue;
        }
        $path = asset_backup_directory() . DIRECTORY_SEPARATOR . $filename;
        $contents = file_get_contents($path);
        if ($contents === false) {
            error_log('Skipping unreadable system backup ' . $filename . '.');
            continue;
        }
        try {
            $document = asset_backup_decrypt($contents, ASSET_BACKUP_PASSPHRASE);
            if ((int)($document['workspace_owner_id'] ?? 0) !== 0) {
                throw new RuntimeException('This is not a full-system backup.');
            }
            return [$backup, $document];
        } catch (Throwable $error) {
            error_log('Skipping invalid full-system backup ' . $filename . ': ' . $error->getMessage());
        }
    }
    throw new RuntimeException('No valid full-system database backup is available.');
}

function admin_workspace_backup(int $ownerId): ?array
{
    $path = asset_backup_workspace_file($ownerId);
    if (!is_file($path)) {
        return null;
    }
    $contents = file_get_contents($path);
    if ($contents === false) {
        error_log('Could not read the workspace recovery backup for owner ' . $ownerId . '.');
        return null;
    }
    try {
        $document = asset_backup_decrypt($contents, ASSET_BACKUP_PASSPHRASE);
        if ((int)($document['workspace_owner_id'] ?? 0) !== $ownerId) {
            throw new RuntimeException('The workspace backup owner does not match its file path.');
        }
        $server = admin_pdo();
        $document = admin_normalize_workspace_document($server, $ownerId, $document);
        return [[
            'filename' => basename($path),
            'created_at' => (string)($document['created_at'] ?? gmdate('c', filemtime($path)))
        ], $document];
    } catch (Throwable $error) {
        error_log('Skipping invalid workspace recovery backup for owner ' . $ownerId . ': ' . $error->getMessage());
        return null;
    }
}

function admin_normalize_workspace_document(PDO $server, int $ownerId, array $document): array
{
    if (!is_array($document['databases'] ?? null)) {
        return $document;
    }
    $named = asset_backup_workspace_database_names($server, $ownerId);
    $legacy = asset_backup_legacy_workspace_database_names($ownerId);
    foreach (['inventory', 'control'] as $type) {
        if (!isset($document['databases'][$named[$type]])
            && isset($document['databases'][$legacy[$type]])) {
            $document['databases'][$named[$type]] = $document['databases'][$legacy[$type]];
            unset($document['databases'][$legacy[$type]]);
        }
    }
    return $document;
}

function admin_recovery_plan(PDO $server, array $fullBackup, array $fullDocument): array
{
    $present = array_fill_keys(asset_backup_databases($server, false), true);
    $sources = [];
    $unrecoverable = [];
    $intentionallyArchived = [];
    $unpaired = [];
    foreach (['inventory', 'control_panel_db'] as $coreDatabase) {
        if (!isset($present[$coreDatabase])) {
            if (isset($fullDocument['databases'][$coreDatabase])) {
                $sources[$coreDatabase] = [$fullBackup, $fullDocument];
            } else {
                $unrecoverable[] = $coreDatabase;
            }
        }
    }

    if (isset($present['inventory'])) {
        $archivedWorkspaces = [];
        $archiveTable = $server->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES "
            . "WHERE TABLE_SCHEMA = 'inventory' AND TABLE_NAME = 'AdminRecycleBin'"
        );
        $archiveTable->execute();
        if ((int)$archiveTable->fetchColumn() > 0) {
            foreach ($server->query(
                "SELECT DatabaseName FROM inventory.AdminRecycleBin WHERE ItemType = 'database'"
            )->fetchAll(PDO::FETCH_COLUMN) as $databaseName) {
                $workspace = admin_workspace_database_owner($server, (string)$databaseName);
                if ($workspace && $workspace['type'] === 'inventory') {
                    $archivedWorkspaces[(int)$workspace['ownerId']] = true;
                }
            }
        }
        $userIds = $server->query('SELECT UserID FROM inventory.Users')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($userIds as $userId) {
            $id = (int)$userId;
            $legacyNames = asset_backup_legacy_workspace_database_names($id);
            $migratedLegacyPair = isset($present[$legacyNames['inventory']])
                || isset($present[$legacyNames['control']]);
            $workspaceNames = asset_backup_existing_workspace_database_names($server, $id);
            if ($migratedLegacyPair) {
                $present = array_fill_keys(asset_backup_databases($server, false), true);
            }
            $inventoryName = $workspaceNames['inventory'];
            $controlName = $workspaceNames['control'];
            $workspaceFullDocument = admin_normalize_workspace_document($server, $id, $fullDocument);
            $hasInventory = isset($present[$inventoryName]);
            $hasControl = isset($present[$controlName]);
            if ($hasInventory === $hasControl) {
                if (!$hasInventory && !$hasControl) {
                    if (isset($archivedWorkspaces[$id])) {
                        $intentionallyArchived[] = $inventoryName;
                        $intentionallyArchived[] = $controlName;
                    } elseif (isset($workspaceFullDocument['databases'][$inventoryName])
                        || isset($workspaceFullDocument['databases'][$controlName])
                        || admin_workspace_backup($id) !== null) {
                        $unpaired[] = $inventoryName;
                        $unpaired[] = $controlName;
                    }
                }
                continue;
            }
            $missingName = $hasInventory ? $controlName : $inventoryName;
            $workspaceBackup = admin_workspace_backup($id);
            $workspaceTimestamp = $workspaceBackup
                ? strtotime((string)($workspaceBackup[1]['created_at'] ?? '')) ?: 0
                : 0;
            $fullTimestamp = strtotime((string)($fullDocument['created_at'] ?? '')) ?: 0;
            $source = $workspaceTimestamp > $fullTimestamp
                ? $workspaceBackup
                : [$fullBackup, $workspaceFullDocument];
            if (isset($source[1]['databases'][$missingName])) {
                $sources[$missingName] = $source;
            } else {
                $unrecoverable[] = $missingName;
            }
        }
    }
    $summary = [];
    foreach ($sources as $databaseName => [$backup, $document]) {
        $summary[] = [
            'database' => $databaseName,
            'filename' => $backup['filename'],
            'created_at' => (string)($document['created_at'] ?? $backup['created_at'] ?? '')
        ];
    }
    return [
        'sources' => $sources,
        'recoverable' => array_keys($sources),
        'unrecoverable' => array_values(array_unique($unrecoverable)),
        'intentionallyArchived' => array_values(array_unique($intentionallyArchived)),
        'unpaired' => array_values(array_unique($unpaired)),
        'summary' => $summary
    ];
}

function admin_file_login_limit(string $username, string $operation): bool
{
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'assets-monitoring-admin-lockouts';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not initialize the admin sign-in safety limit.');
    }
    $address = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $filename = $directory . DIRECTORY_SEPARATOR
        . hash('sha256', $address . "\0" . $username) . '.json';
    $handle = fopen($filename, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Could not access the admin sign-in safety limit.');
    }
    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Could not lock the admin sign-in safety limit.');
        }
        $raw = stream_get_contents($handle);
        $state = is_string($raw) ? json_decode($raw, true) : null;
        $attempts = is_array($state) ? max(0, (int)($state['attempts'] ?? 0)) : 0;
        $lockedUntil = is_array($state) ? max(0, (int)($state['locked_until'] ?? 0)) : 0;
        if ($lockedUntil > time()) {
            return true;
        }
        if ($operation === 'failure') {
            $attempts = $lockedUntil > 0 ? 0 : $attempts;
            $attempts++;
            if ($attempts >= 5) {
                $lockedUntil = time() + 900;
                $attempts = 0;
            } else {
                $lockedUntil = 0;
            }
        } else {
            $attempts = 0;
            $lockedUntil = 0;
        }
        $json = json_encode(['attempts' => $attempts, 'locked_until' => $lockedUntil]);
        if ($json === false || !ftruncate($handle, 0) || !rewind($handle)
            || fwrite($handle, $json) === false || !fflush($handle)) {
            throw new RuntimeException('Could not update the admin sign-in safety limit.');
        }
        return false;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function admin_recycle_table(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS AdminRecycleBin (
        RecycleID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        ItemType ENUM('database','table') NOT NULL,
        DatabaseName VARCHAR(128) NOT NULL,
        TableName VARCHAR(128) NULL,
        ArchiveFilename VARCHAR(255) NOT NULL,
        ArchiveSize BIGINT UNSIGNED NOT NULL DEFAULT 0,
        ArchivedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_admin_recycle_archived (ArchivedAt)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function admin_audit_table(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS AdminAuditLog (
        AuditID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        AdminName VARCHAR(80) NOT NULL,
        Action VARCHAR(80) NOT NULL,
        Item VARCHAR(255) NOT NULL,
        CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_admin_audit_created (CreatedAt)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function admin_audit(PDO $pdo, string $action, string $item): void
{
    admin_audit_table($pdo);
    $statement = $pdo->prepare('INSERT INTO AdminAuditLog (AdminName, Action, Item) VALUES (?, ?, ?)');
    $statement->execute(['kradmin', substr($action, 0, 80), substr($item, 0, 255)]);
}

function admin_recycle_directory(): string
{
    $directory = asset_backup_directory() . DIRECTORY_SEPARATOR . 'admin-recycle-bin';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('The protected admin recycle-bin directory could not be created.');
    }
    return $directory;
}

function admin_workspace_database_map(PDO $server): array
{
    static $maps = [];
    $connectionId = spl_object_id($server);
    if (isset($maps[$connectionId])) {
        return $maps[$connectionId];
    }
    $hasStemColumn = $server->query(
        "SELECT 1 FROM information_schema.COLUMNS "
        . "WHERE TABLE_SCHEMA = 'inventory' AND TABLE_NAME = 'Users' "
        . "AND COLUMN_NAME = 'WorkspaceDatabaseStem' LIMIT 1"
    )->fetchColumn();
    $users = $server->query(
        'SELECT UserID, FirstName, LastName'
        . ($hasStemColumn ? ', WorkspaceDatabaseStem' : '')
        . ' FROM inventory.Users ORDER BY UserID'
    )->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    $usedStems = [];
    foreach ($users as $user) {
        $ownerId = (int)$user['UserID'];
        if ($ownerId < 1) {
            continue;
        }
        $stem = strtoupper(trim((string)($user['WorkspaceDatabaseStem'] ?? '')));
        if ($stem === '' || !preg_match('/^[A-Z0-9_]{1,51}$/', $stem)) {
            $stem = asset_backup_workspace_stem(
                (string)$user['FirstName'],
                (string)$user['LastName']
            );
        }
        if (isset($usedStems[$stem])) {
            $suffix = '_' . $ownerId;
            $stem = substr($stem, 0, 51 - strlen($suffix)) . $suffix;
        }
        $usedStems[$stem] = true;
        $named = [
            'inventory' => substr($stem, 0, 51) . '_INVENTORY',
            'control' => substr($stem, 0, 50) . '_USER_CONTROL'
        ];
        $legacy = asset_backup_legacy_workspace_database_names($ownerId);
        foreach (['inventory', 'control'] as $type) {
            $map[strtolower($named[$type])] = ['ownerId' => $ownerId, 'type' => $type];
            $map[strtolower($legacy[$type])] = ['ownerId' => $ownerId, 'type' => $type];
        }
    }
    $maps[$connectionId] = $map;
    return $map;
}

function admin_workspace_database_owner(PDO $server, string $databaseName): ?array
{
    $map = admin_workspace_database_map($server);
    return $map[strtolower($databaseName)] ?? null;
}

function admin_validate_tenant_database(PDO $server, string $databaseName): int
{
    $workspace = admin_workspace_database_owner($server, $databaseName);
    if (!$workspace || $workspace['type'] !== 'inventory') {
        throw new InvalidArgumentException('Only tenant inventory workspaces can be archived from this screen.');
    }
    return (int)$workspace['ownerId'];
}

function admin_assert_archive_size(PDO $pdo, array $databaseNames): void
{
    $placeholders = implode(',', array_fill(0, count($databaseNames), '?'));
    $statement = $pdo->prepare(
        "SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) FROM information_schema.TABLES "
        . "WHERE TABLE_SCHEMA IN ({$placeholders})"
    );
    $statement->execute($databaseNames);
    $estimatedBytes = (int)$statement->fetchColumn();
    $memoryLimit = trim((string)ini_get('memory_limit'));
    $memoryBytes = 256 * 1024 * 1024;
    if ($memoryLimit !== '' && $memoryLimit !== '-1') {
        $suffix = strtolower(substr($memoryLimit, -1));
        $numeric = (float)$memoryLimit;
        $memoryBytes = (int)($numeric * match ($suffix) {
            'g' => 1024 * 1024 * 1024,
            'm' => 1024 * 1024,
            'k' => 1024,
            default => 1
        });
    }
    $maximumBytes = min(16 * 1024 * 1024, max(2 * 1024 * 1024, (int)($memoryBytes / 16)));
    if ($estimatedBytes > $maximumBytes) {
        throw new RuntimeException(
            'This data set is too large for a safe browser-based archive. Create a full system backup before removing it.'
        );
    }
}

function admin_archive_document(PDO $pdo, string $itemType, string $databaseName, ?string $tableName, array $document, string $password): int
{
    admin_recycle_table($pdo);
    $encrypted = asset_backup_encrypt($document, $password);
    $filename = 'admin-recycle-' . bin2hex(random_bytes(16)) . '.json';
    $target = admin_recycle_directory() . DIRECTORY_SEPARATOR . $filename;
    $temporary = $target . '.tmp';
    if (file_put_contents($temporary, $encrypted, LOCK_EX) === false || !rename($temporary, $target)) {
        if (is_file($temporary)) {
            unlink($temporary);
        }
        throw new RuntimeException('The encrypted recovery archive could not be written. Nothing was removed.');
    }

    try {
        $statement = $pdo->prepare(
            'INSERT INTO AdminRecycleBin (ItemType, DatabaseName, TableName, ArchiveFilename, ArchiveSize) VALUES (?, ?, ?, ?, ?)'
        );
        $statement->execute([$itemType, $databaseName, $tableName, $filename, filesize($target) ?: 0]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $error) {
        if (is_file($target)) {
            unlink($target);
        }
        throw $error;
    }
}

function admin_capture_table(PDO $server, string $databaseName, string $tableName): array
{
    $database = admin_quote_identifier($databaseName);
    $table = admin_quote_identifier($tableName);
    $create = $server->query("SHOW CREATE TABLE {$database}.{$table}")->fetch(PDO::FETCH_NUM);
    if (!isset($create[1]) || !str_starts_with(strtoupper(ltrim((string)$create[1])), 'CREATE TABLE')) {
        throw new RuntimeException('The table definition could not be read. Nothing was removed.');
    }

    $info = $server->prepare(
        'SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME '
        . 'FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?'
    );
    $info->execute([$databaseName]);
    $settings = $info->fetch();
    if (!$settings) {
        throw new RuntimeException('The selected database is no longer available.');
    }

    $columns = $server->query("SHOW COLUMNS FROM {$database}.{$table}")->fetchAll();
    $selectColumns = [];
    $binaryColumns = [];
    foreach ($columns as $column) {
        $columnName = (string)$column['Field'];
        if (preg_match('/\b(?:virtual|stored)\s+generated\b/i', (string)($column['Extra'] ?? ''))) {
            continue;
        }
        $selectColumns[] = admin_quote_identifier($columnName);
        if (preg_match('/\b(binary|varbinary|tinyblob|blob|mediumblob|longblob|bit)\b/i', (string)$column['Type'])) {
            $binaryColumns[$columnName] = true;
        }
    }
    $rows = [];
    if ($selectColumns) {
        $statement = $server->query('SELECT ' . implode(', ', $selectColumns) . " FROM {$database}.{$table}");
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            foreach ($binaryColumns as $columnName => $_) {
                if (array_key_exists($columnName, $row) && $row[$columnName] !== null) {
                    $row[$columnName] = ['__asset_backup_binary_base64' => base64_encode((string)$row[$columnName])];
                }
            }
            $rows[] = $row;
        }
        $statement->closeCursor();
    }
    return [
        'format' => 'assets-monitoring-admin-recycle-v1',
        'item_type' => 'table',
        'database' => $databaseName,
        'charset' => (string)$settings['DEFAULT_CHARACTER_SET_NAME'],
        'collation' => (string)$settings['DEFAULT_COLLATION_NAME'],
        'table_name' => $tableName,
        'table' => ['create_sql' => (string)$create[1], 'rows' => $rows]
    ];
}

function admin_restore_archived_database(PDO $server, string $databaseName, array $databaseData): void
{
    if (!asset_backup_project_database($databaseName)
        || !preg_match('/^(?:assets_user_(?:inventory|control)_[1-9][0-9]*|[A-Z0-9_]+_(?:INVENTORY|USER_CONTROL))$/i', $databaseName)) {
        throw new RuntimeException('The archive contains a database that is not eligible for restore.');
    }
    $charset = (string)($databaseData['charset'] ?? '');
    $collation = (string)($databaseData['collation'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_]+$/', $charset) || !preg_match('/^[A-Za-z0-9_]+$/', $collation)) {
        throw new RuntimeException('The archive contains invalid database settings.');
    }
    $database = admin_quote_identifier($databaseName);
    $server->exec("CREATE DATABASE {$database} CHARACTER SET {$charset} COLLATE {$collation}");
    try {
        $target = admin_pdo_for_database($databaseName);
        $target->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach (($databaseData['tables'] ?? []) as $tableName => $tableData) {
                if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$tableName) || !is_array($tableData)
                    || !is_string($tableData['create_sql'] ?? null) || !is_array($tableData['rows'] ?? null)) {
                    throw new RuntimeException('The database archive contains an invalid table entry.');
                }
                $target->exec($tableData['create_sql']);
                foreach ($tableData['rows'] as $row) {
                    if (!is_array($row) || !$row) {
                        continue;
                    }
                    foreach ($row as &$value) {
                        if (is_array($value) && isset($value['__asset_backup_binary_base64'])) {
                            $decoded = base64_decode((string)$value['__asset_backup_binary_base64'], true);
                            if ($decoded === false) {
                                throw new RuntimeException('The database archive contains invalid binary data.');
                            }
                            $value = $decoded;
                        }
                    }
                    unset($value);
                    $columns = array_map('admin_quote_identifier', array_keys($row));
                    $marks = array_fill(0, count($columns), '?');
                    $insert = $target->prepare(
                        'INSERT INTO ' . admin_quote_identifier((string)$tableName)
                        . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $marks) . ')'
                    );
                    $insert->execute(array_values($row));
                }
            }
        } catch (Throwable $error) {
            try {
                $target->exec('DROP TABLE IF EXISTS ' . admin_quote_identifier($tableName));
            } catch (Throwable $cleanupError) {
                error_log('Admin table restore cleanup failed: ' . $cleanupError->getMessage());
            }
            throw $error;
        } finally {
            $target->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    } catch (Throwable $error) {
        try {
            $server->exec('DROP DATABASE IF EXISTS ' . $database);
        } catch (Throwable $cleanupError) {
            error_log('Admin database restore cleanup failed: ' . $cleanupError->getMessage());
        }
        throw $error;
    }
}

function admin_pdo_for_database(string $databaseName): PDO
{
    global $DB_HOST, $DB_USER, $DB_PASS;
    return new PDO(
        "mysql:host={$DB_HOST};dbname={$databaseName};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = (string)($_GET['action'] ?? '');

    if ($method === 'GET' && $action === 'csrf') {
        if (empty($_SESSION['admin_csrf'])) {
            $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
        }
        admin_respond(['success' => true, 'csrf' => $_SESSION['admin_csrf']]);
    }

    if ($method === 'POST' && $action === 'login') {
        admin_csrf_check();
        $data = admin_body();
        $username = trim((string)($data['username'] ?? ''));
        $password = (string)($data['password'] ?? '');
        $configuredUsername = (string)(getenv('ASSETS_ADMIN_USERNAME') ?: ($_SERVER['ASSETS_ADMIN_USERNAME'] ?? ''));
        if ($configuredUsername !== 'kradmin' || admin_configured_password_hash() === '') {
            admin_respond(['success' => false, 'message' => 'Admin sign-in is not configured on this server.'], 503);
        }

        $lockoutKey = '__admin__kradmin';
        $pdo = null;
        try {
            $pdo = admin_pdo(true);
        } catch (PDOException $error) {
            $server = admin_pdo();
            $schemaCheck = $server->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
            $schemaCheck->execute([$DB_INVENTORY]);
            if ((int)$schemaCheck->fetchColumn() !== 0) {
                throw $error;
            }
            error_log('Admin sign-in is using its file-based rate limit because the inventory database is missing.');
        }
        if ($pdo instanceof PDO) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS AccountLockouts (
                Username VARCHAR(80) NOT NULL PRIMARY KEY,
                FailedAttempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                LockedUntil DATETIME NULL,
                UpdatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $lock = $pdo->prepare('SELECT LockedUntil FROM AccountLockouts WHERE Username = ?');
            $lock->execute([$lockoutKey]);
            $lockedUntil = $lock->fetchColumn();
            if ($lockedUntil && strtotime((string)$lockedUntil) > time()) {
                admin_respond(['success' => false, 'message' => 'Admin sign-in is temporarily locked after repeated failed attempts. Try again in 15 minutes.'], 429);
            }
        } elseif (admin_file_login_limit($lockoutKey, 'status')) {
            admin_respond(['success' => false, 'message' => 'Admin sign-in is temporarily locked after repeated failed attempts. Try again in 15 minutes.'], 429);
        }

        if (!hash_equals($configuredUsername, $username) || !admin_verify_password($password)) {
            if ($pdo instanceof PDO) {
                $failed = $pdo->prepare(
                    'INSERT INTO AccountLockouts (Username, FailedAttempts, LockedUntil) VALUES (?, 1, NULL) '
                    . 'ON DUPLICATE KEY UPDATE FailedAttempts = IF(LockedUntil IS NOT NULL AND LockedUntil <= NOW(), 1, FailedAttempts + 1), '
                    . 'LockedUntil = IF(FailedAttempts >= 4, DATE_ADD(NOW(), INTERVAL 15 MINUTE), NULL)'
                );
                $failed->execute([$lockoutKey]);
            } else {
                admin_file_login_limit($lockoutKey, 'failure');
            }
            admin_respond(['success' => false, 'message' => 'Invalid username or password.'], 401);
        }

        if ($pdo instanceof PDO) {
            $pdo->prepare('DELETE FROM AccountLockouts WHERE Username = ?')->execute([$lockoutKey]);
        } else {
            admin_file_login_limit($lockoutKey, 'success');
        }
        session_regenerate_id(true);
        $_SESSION = [];
        $_SESSION['admin_authenticated'] = true;
        $_SESSION['admin_last_activity'] = time();
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
        $_SESSION['admin_archive_password'] = $password;
        admin_respond(['success' => true]);
    }

    if ($method === 'POST' && $action === 'logout') {
        admin_csrf_check();
        admin_require_session();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 3600,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => 'Lax'
            ]);
        }
        session_destroy();
        admin_respond(['success' => true]);
    }

    if ($method === 'GET' && $action === 'recycle_bin') {
        admin_require_session();
        $pdo = admin_pdo(true);
        admin_recycle_table($pdo);
        $items = $pdo->query(
            'SELECT RecycleID AS id, ItemType AS itemType, DatabaseName AS databaseName, '
            . 'TableName AS tableName, ArchiveFilename AS archiveFilename, ArchiveSize AS sizeBytes, ArchivedAt AS archivedAt '
            . 'FROM AdminRecycleBin ORDER BY ArchivedAt DESC LIMIT 200'
        )->fetchAll();
        foreach ($items as &$item) {
            $item['id'] = (int)$item['id'];
            $item['sizeBytes'] = (int)$item['sizeBytes'];
            $item['archiveAvailable'] = preg_match('/^admin-recycle-[a-f0-9]{32}\.json$/', (string)$item['archiveFilename']) === 1
                && is_file(admin_recycle_directory() . DIRECTORY_SEPARATOR . $item['archiveFilename']);
            unset($item['archiveFilename']);
        }
        unset($item);
        admin_respond(['success' => true, 'items' => $items]);
    }

    if ($method === 'GET' && $action === 'recovery_status') {
        admin_require_session();
        $server = asset_backup_server();
        $presentDatabases = asset_backup_databases($server, false);
        try {
            [$backup, $document] = admin_latest_full_backup();
        } catch (Throwable $error) {
            error_log('Admin recovery status could not read a full-system backup: ' . $error->getMessage());
            admin_respond([
                'success' => true,
                'available' => false,
                'message' => $error->getMessage(),
                'presentDatabases' => $presentDatabases,
                'missingDatabases' => []
            ]);
        }
        $plan = admin_recovery_plan($server, $backup, $document);
        admin_respond([
            'success' => true,
            'available' => true,
            'backupCreatedAt' => (string)($document['created_at'] ?? $backup['created_at'] ?? ''),
            'backupFilename' => $backup['filename'],
            'presentDatabases' => $presentDatabases,
            'missingDatabases' => $plan['recoverable'],
            'recoverySources' => $plan['summary'],
            'unrecoverableDatabases' => $plan['unrecoverable'],
            'intentionallyArchivedDatabases' => $plan['intentionallyArchived'],
            'unpairedDatabases' => $plan['unpaired']
        ]);
    }

    if ($method === 'POST' && $action === 'recover_missing_databases') {
        admin_require_session();
        admin_csrf_check();
        $server = asset_backup_server();
        $lockAcquired = (int)$server->query(
            "SELECT GET_LOCK('assets_monitoring_admin_database_recovery', 10)"
        )->fetchColumn() === 1;
        if (!$lockAcquired) {
            admin_respond(['success' => false, 'message' => 'Another database recovery is already running. Try again shortly.'], 409);
        }
        $restored = [];
        $unrecoverable = [];
        try {
            [$fullBackup, $document] = admin_latest_full_backup();
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $plan = admin_recovery_plan($server, $fullBackup, $document);
                $unrecoverable = $plan['unrecoverable'];
                if ($unrecoverable || !$plan['sources']) {
                    break;
                }
                $passRestored = [];
                foreach ($plan['sources'] as $databaseName => [$sourceBackup, $sourceDocument]) {
                    $newlyRestored = asset_backup_restore_missing_databases(
                        $server,
                        $sourceDocument,
                        [$databaseName]
                    );
                    $passRestored = array_merge($passRestored, $newlyRestored);
                }
                if (!$passRestored) break;
                $restored = array_merge($restored, $passRestored);
            }
        } finally {
            $server->query("SELECT RELEASE_LOCK('assets_monitoring_admin_database_recovery')");
        }
        if ($restored) {
            error_log('Admin restored missing databases from the latest full-system backup: ' . implode(', ', $restored));
            admin_respond([
                'success' => true,
                'message' => 'Missing databases were restored from the latest full-system backup. Existing databases were left unchanged.'
                    . ($unrecoverable ? ' No matching backup was found for: ' . implode(', ', $unrecoverable) . '.' : ''),
                'restoredDatabases' => $restored
            ]);
        }
        if ($unrecoverable) {
            admin_respond([
                'success' => false,
                'message' => 'No matching database exists in the newest full-system backup for: '
                    . implode(', ', $unrecoverable) . '. No database was changed.'
            ], 409);
        }
        admin_respond([
            'success' => true,
            'message' => 'Every database in the latest full-system backup already exists. No changes were made.',
            'restoredDatabases' => []
        ]);
    }

    if ($method === 'POST' && $action === 'user_reset') {
        admin_require_session();
        admin_csrf_check();
        $data = admin_body();
        $userId = filter_var($data['userId'] ?? null, FILTER_VALIDATE_INT);
        $username = trim((string)($data['username'] ?? ''));
        if ($userId === false || $userId < 1 || $username === '' || strlen($username) > 80) {
            admin_respond(['success' => false, 'message' => 'Choose a valid account.'], 422);
        }

        $pdo = admin_pdo(true);
        $columns = $pdo->query("SHOW COLUMNS FROM `Users` LIKE 'MustChangePassword'")->fetch();
        if (!$columns) {
            $pdo->exec('ALTER TABLE `Users` ADD COLUMN `MustChangePassword` TINYINT(1) NOT NULL DEFAULT 0 AFTER `PasswordHash`');
        }
        $lookup = $pdo->prepare('SELECT Username, Status FROM `Users` WHERE UserID = ? LIMIT 1');
        $lookup->execute([$userId]);
        $user = $lookup->fetch();
        if (!$user || !hash_equals((string)$user['Username'], $username) || $user['Status'] !== 'active') {
            admin_respond(['success' => false, 'message' => 'Only active accounts can receive a temporary password.'], 404);
        }

        $server = admin_pdo();
        $workspaceDatabases = asset_backup_existing_workspace_database_names($server, (int)$userId);
        $inventoryName = $workspaceDatabases['inventory'];
        $controlName = $workspaceDatabases['control'];
        $workspaceCheck = $server->prepare(
            'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME IN (?, ?)'
        );
        $workspaceCheck->execute([$inventoryName, $controlName]);
        $workspaceCount = (int)$workspaceCheck->fetchColumn();
        if ($workspaceCount === 1) {
            admin_respond(['success' => false, 'message' => 'This account workspace is incomplete. No password was changed.'], 503);
        }

        $temporaryPassword = bin2hex(random_bytes(8));
        $temporaryHash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
        admin_audit_table($pdo);
        $pdo->beginTransaction();
        try {
            if ($workspaceCount === 2) {
                $tenant = admin_quote_identifier($inventoryName);
                $tenantUpdate = $pdo->prepare(
                    "UPDATE {$tenant}.`Users` SET PasswordHash = ? WHERE UserID = ?"
                );
                $tenantUpdate->execute([$temporaryHash, $userId]);
                if ($tenantUpdate->rowCount() !== 1) {
                    throw new RuntimeException('The workspace account credentials could not be updated.');
                }
            }
            $update = $pdo->prepare(
                'UPDATE `Users` SET PasswordHash = ?, MustChangePassword = 1 WHERE UserID = ? AND Status = ?'
            );
            $update->execute([$temporaryHash, $userId, 'active']);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('The account credentials could not be updated.');
            }
            $audit = $pdo->prepare('INSERT INTO AdminAuditLog (AdminName, Action, Item) VALUES (?, ?, ?)');
            $audit->execute(['kradmin', 'Temporary password issued', substr((string)$user['Username'], 0, 255)]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }

        admin_respond([
            'success' => true,
            'username' => (string)$user['Username'],
            'temporaryPassword' => $temporaryPassword,
            'message' => 'Temporary password created. It is shown once; the user must change it at next sign-in.'
        ]);
    }

    if ($method === 'POST' && $action === 'recycle_action') {
        admin_require_session();
        admin_csrf_check();
        $data = admin_body();
        $operation = (string)($data['operation'] ?? '');
        $archivePassword = admin_archive_password();

        $pdo = admin_pdo(true);
        $server = admin_pdo();
        admin_recycle_table($pdo);
        if ($operation === 'archive_workspace') {
            $databaseName = (string)($data['databaseName'] ?? '');
            $ownerId = admin_validate_tenant_database($server, $databaseName);
            $workspaceDatabases = asset_backup_existing_workspace_database_names($server, $ownerId);
            $inventoryName = $workspaceDatabases['inventory'];
            $controlName = $workspaceDatabases['control'];
            $available = asset_backup_databases($server, false);
            if (!in_array($inventoryName, $available, true) || !in_array($controlName, $available, true)) {
                admin_respond(['success' => false, 'message' => 'Both workspace databases must exist. An incomplete workspace cannot be removed as a pair.'], 409);
            }
            $databaseNames = [$inventoryName, $controlName];
            admin_assert_archive_size($server, $databaseNames);
            $placeholders = implode(',', array_fill(0, count($databaseNames), '?'));
            $unsupported = $server->prepare(
                "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA IN ({$placeholders}) AND TABLE_TYPE <> 'BASE TABLE'"
            );
            $unsupported->execute($databaseNames);
            $triggers = $server->prepare(
                "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA IN ({$placeholders})"
            );
            $triggers->execute($databaseNames);
            $routines = $server->prepare(
                "SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA IN ({$placeholders})"
            );
            $routines->execute($databaseNames);
            if ((int)$unsupported->fetchColumn() > 0 || (int)$triggers->fetchColumn() > 0
                || (int)$routines->fetchColumn() > 0) {
                admin_respond(['success' => false, 'message' => 'This workspace has views, triggers, or routines that the recoverable archive cannot safely preserve. Nothing was removed.'], 409);
            }
            $document = asset_backup_capture($server, $databaseNames);
            foreach ($document['databases'] as $databaseData) {
                if (!empty($databaseData['events'])) {
                    admin_respond(['success' => false, 'message' => 'This workspace has scheduled database events that the recycle bin cannot safely preserve. Nothing was removed.'], 409);
                }
            }
            $archiveId = admin_archive_document($pdo, 'database', $inventoryName, null, $document, $archivePassword);
            foreach ($databaseNames as $name) {
                $server->exec('DROP DATABASE ' . admin_quote_identifier($name));
            }
            admin_audit($pdo, 'Workspace archived', implode(' + ', $databaseNames));
            admin_respond(['success' => true, 'message' => 'Both workspace databases were archived and removed.', 'archiveId' => $archiveId]);
        }

        if ($operation === 'archive_table') {
            $databaseName = (string)($data['databaseName'] ?? '');
            $tableName = (string)($data['tableName'] ?? '');
            $workspace = admin_workspace_database_owner($server, $databaseName);
            $ownerId = (int)($workspace['ownerId'] ?? 0);
            if ($ownerId < 1 || !preg_match('/^[A-Za-z0-9_]+$/', $tableName)) {
                admin_respond(['success' => false, 'message' => 'Only tables in tenant workspace databases can be archived here.'], 422);
            }
            if ($workspace['type'] === 'inventory'
                && in_array(strtolower($tableName), [
                    'users', 'loginhistory', 'activitylogs', 'remembereddevices', 'accountlockouts',
                    'passwordresetrequests', 'accountrecoveryattempts', 'workspacemembers',
                    'workspaceinvitations', 'workspacepreferences', 'workspaceinvitationattempts',
                    'adminrecyclebin', 'adminauditlog'
                ], true)) {
                admin_respond(['success' => false, 'message' => 'Authentication, audit, and recovery tables are protected.'], 403);
            }
            if ($workspace['type'] === 'control'
                && in_array(strtolower($tableName), ['navigation_menu', 'system_recycle_bin'], true)) {
                admin_respond(['success' => false, 'message' => 'Workspace navigation and recycle-bin tables are protected.'], 403);
            }
            $quotedDatabase = admin_quote_identifier($databaseName);
            $exists = $server->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND TABLE_TYPE = ?'
            );
            $exists->execute([$databaseName, $tableName, 'BASE TABLE']);
            if ((int)$exists->fetchColumn() !== 1) {
                admin_respond(['success' => false, 'message' => 'The selected base table does not exist. Views cannot be archived by this action.'], 404);
            }
            admin_assert_archive_size($server, [$databaseName]);
            $tableIdentifier = admin_quote_identifier($tableName);
            $triggerCheck = $server->prepare(
                'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA = ? AND EVENT_OBJECT_TABLE = ?'
            );
            $triggerCheck->execute([$databaseName, $tableName]);
            $viewCheck = $server->prepare(
                'SELECT COUNT(*) FROM information_schema.VIEW_TABLE_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
            );
            $viewCheck->execute([$databaseName, $tableName]);
            if ((int)$triggerCheck->fetchColumn() > 0 || (int)$viewCheck->fetchColumn() > 0) {
                admin_respond(['success' => false, 'message' => 'This table has dependent triggers or views and cannot be safely archived alone. Nothing was removed.'], 409);
            }
            $archiveId = admin_archive_document(
                $pdo,
                'table',
                $databaseName,
                $tableName,
                admin_capture_table($server, $databaseName, $tableName),
                $archivePassword
            );
            $server->exec("DROP TABLE {$quotedDatabase}.{$tableIdentifier}");
            admin_audit($pdo, 'Table archived', $databaseName . '.' . $tableName);
            admin_respond(['success' => true, 'message' => 'The table was archived and removed.', 'archiveId' => $archiveId]);
        }

        $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            admin_respond(['success' => false, 'message' => 'Choose a valid recycle-bin item.'], 422);
        }
        $lookup = $pdo->prepare('SELECT * FROM AdminRecycleBin WHERE RecycleID = ?');
        $lookup->execute([$id]);
        $item = $lookup->fetch();
        if (!$item) {
            admin_respond(['success' => false, 'message' => 'That recycle-bin item no longer exists.'], 404);
        }
        $filename = (string)$item['ArchiveFilename'];
        if (!preg_match('/^admin-recycle-[a-f0-9]{32}\.json$/', $filename)) {
            throw new RuntimeException('The recycle-bin archive filename is invalid.');
        }
        $archivePath = admin_recycle_directory() . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($archivePath)) {
            admin_respond(['success' => false, 'message' => 'The recovery archive file is missing. No changes were made.'], 410);
        }

        if ($operation === 'restore') {
            $document = asset_backup_decrypt((string)file_get_contents($archivePath), $archivePassword);
            if ($item['ItemType'] === 'table') {
                if (($document['format'] ?? '') !== 'assets-monitoring-admin-recycle-v1'
                    || ($document['item_type'] ?? '') !== 'table'
                    || ($document['database'] ?? '') !== $item['DatabaseName']
                    || ($document['table_name'] ?? '') !== $item['TableName']) {
                    throw new RuntimeException('The table recovery archive does not match its recycle-bin entry.');
                }
                $databaseName = (string)$item['DatabaseName'];
                if (!admin_workspace_database_owner($server, $databaseName)
                    || !in_array($databaseName, asset_backup_databases($server, false), true)) {
                    admin_respond(['success' => false, 'message' => 'The original workspace database must exist before restoring this table.'], 409);
                }
                $tableName = (string)$item['TableName'];
                $exists = $server->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
                $exists->execute([$databaseName, $tableName]);
                if ((int)$exists->fetchColumn() > 0) {
                    admin_respond(['success' => false, 'message' => 'A table with that name already exists. Rename or remove it before restoring.'], 409);
                }
                $target = admin_pdo_for_database($databaseName);
                $target->exec('SET FOREIGN_KEY_CHECKS=0');
                try {
                    $target->exec($document['table']['create_sql']);
                    foreach ($document['table']['rows'] as $row) {
                        if (!is_array($row) || !$row) {
                            continue;
                        }
                        foreach ($row as &$value) {
                            if (is_array($value) && isset($value['__asset_backup_binary_base64'])) {
                                $decoded = base64_decode((string)$value['__asset_backup_binary_base64'], true);
                                if ($decoded === false) {
                                    throw new RuntimeException('The table archive contains invalid binary data.');
                                }
                                $value = $decoded;
                            }
                        }
                        unset($value);
                        $columns = array_map('admin_quote_identifier', array_keys($row));
                        $marks = array_fill(0, count($columns), '?');
                        $insert = $target->prepare(
                            'INSERT INTO ' . admin_quote_identifier($tableName)
                            . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $marks) . ')'
                        );
                        $insert->execute(array_values($row));
                    }
                } finally {
                    $target->exec('SET FOREIGN_KEY_CHECKS=1');
                }
            } elseif ($item['ItemType'] === 'database') {
                $inventoryName = (string)$item['DatabaseName'];
                $ownerId = admin_validate_tenant_database($server, $inventoryName);
                $workspaceDatabases = asset_backup_workspace_database_names($server, $ownerId);
                $legacyDatabases = asset_backup_legacy_workspace_database_names($ownerId);
                $expectedNames = array_values($workspaceDatabases);
                $sourceInventory = $document['databases'][$workspaceDatabases['inventory']]
                    ?? $document['databases'][$legacyDatabases['inventory']]
                    ?? null;
                $sourceControl = $document['databases'][$workspaceDatabases['control']]
                    ?? $document['databases'][$legacyDatabases['control']]
                    ?? null;
                if (($document['format'] ?? '') !== 'assets-monitoring-encrypted-json'
                    || !isset($document['databases']) || !is_array($sourceInventory)
                    || !is_array($sourceControl) || count($document['databases']) !== 2) {
                    throw new RuntimeException('The workspace recovery archive does not match its recycle-bin entry.');
                }
                $document['databases'] = [
                    $workspaceDatabases['inventory'] => $sourceInventory,
                    $workspaceDatabases['control'] => $sourceControl
                ];
                $available = asset_backup_databases($server, false);
                if (array_intersect($expectedNames, $available)) {
                    admin_respond(['success' => false, 'message' => 'One or more workspace databases already exist. Remove or rename them before restoring.'], 409);
                }
                $created = [];
                try {
                    foreach ($expectedNames as $databaseName) {
                        admin_restore_archived_database($server, $databaseName, $document['databases'][$databaseName]);
                        $created[] = $databaseName;
                    }
                } catch (Throwable $error) {
                    foreach (array_reverse($created) as $databaseName) {
                        try {
                            $server->exec('DROP DATABASE ' . admin_quote_identifier($databaseName));
                        } catch (Throwable $cleanupError) {
                            error_log('Admin recycle restore cleanup failed: ' . $cleanupError->getMessage());
                        }
                    }
                    throw $error;
                }
            } else {
                throw new RuntimeException('This recycle-bin item type is not supported.');
            }
            $pdo->prepare('DELETE FROM AdminRecycleBin WHERE RecycleID = ?')->execute([$id]);
            if (!unlink($archivePath)) {
                error_log('Restored admin archive could not be removed: ' . $archivePath);
            }
            admin_audit($pdo, 'Recycle item restored', (string)$item['DatabaseName'] . (empty($item['TableName']) ? '' : '.' . $item['TableName']));
            admin_respond(['success' => true, 'message' => 'The archived item was restored.']);
        }

        if ($operation === 'purge') {
            if (!unlink($archivePath)) {
                throw new RuntimeException('The archive file could not be permanently removed.');
            }
            $pdo->prepare('DELETE FROM AdminRecycleBin WHERE RecycleID = ?')->execute([$id]);
            admin_audit($pdo, 'Recycle item purged', (string)$item['DatabaseName'] . (empty($item['TableName']) ? '' : '.' . $item['TableName']));
            admin_respond(['success' => true, 'message' => 'The recovery archive was permanently removed.']);
        }

        admin_respond(['success' => false, 'message' => 'Unknown recycle-bin operation.'], 422);
    }

    if ($method !== 'GET' || $action !== 'overview') {
        admin_respond(['success' => false, 'message' => 'Unknown request.'], 404);
    }
    admin_require_session();

    $pdo = admin_pdo(true);
    $schemas = $pdo->query(
        "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA "
        . "WHERE SCHEMA_NAME NOT IN ('information_schema', 'mysql', 'performance_schema', 'sys') "
        . 'ORDER BY SCHEMA_NAME'
    )->fetchAll(PDO::FETCH_COLUMN);
    $tableQuery = $pdo->query(
        "SELECT TABLE_SCHEMA, TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, "
        . 'CREATE_TIME, UPDATE_TIME '
        . "FROM information_schema.TABLES "
        . "WHERE TABLE_SCHEMA NOT IN ('information_schema', 'mysql', 'performance_schema', 'sys') "
        . 'ORDER BY TABLE_SCHEMA, TABLE_NAME'
    );
    $tablesBySchema = [];
    foreach ($tableQuery as $table) {
        $schemaName = (string)$table['TABLE_SCHEMA'];
        $tableName = (string)$table['TABLE_NAME'];
        $tablesBySchema[$schemaName][] = [
            'name' => $tableName,
            'type' => $table['TABLE_TYPE'],
            'engine' => $table['ENGINE'],
            'rows' => $table['TABLE_ROWS'] === null ? null : (int)$table['TABLE_ROWS'],
            'sizeBytes' => (int)$table['DATA_LENGTH'] + (int)$table['INDEX_LENGTH'],
            'lastChangedAt' => $table['UPDATE_TIME'] ?: $table['CREATE_TIME']
        ];
    }

    $users = [];
    $truncatedUsers = false;
    $columnQuery = $pdo->query(
        "SELECT TABLE_SCHEMA, COLUMN_NAME FROM information_schema.COLUMNS "
        . "WHERE TABLE_NAME = 'Users' AND TABLE_SCHEMA NOT IN ('information_schema', 'mysql', 'performance_schema', 'sys') "
        . 'ORDER BY TABLE_SCHEMA, ORDINAL_POSITION'
    );
    $userColumns = [];
    foreach ($columnQuery as $column) {
        $userColumns[(string)$column['TABLE_SCHEMA']][] = (string)$column['COLUMN_NAME'];
    }
    foreach ($userColumns as $schemaName => $columns) {
        if (count($users) >= 500) {
            $truncatedUsers = true;
            break;
        }
        $available = array_fill_keys($columns, true);
        if (!isset($available['Username'])) {
            continue;
        }
        $safeColumns = array_values(array_filter(
            ['UserID', 'Username', 'FirstName', 'LastName', 'Department', 'WorkArea', 'Status', 'DateCreated'],
            static fn(string $column): bool => isset($available[$column])
        ));
        $selectColumns = implode(', ', array_map('admin_quote_identifier', $safeColumns));
        $remaining = 501 - count($users);
        $schema = admin_quote_identifier((string)$schemaName);
        $rows = $pdo->query("SELECT {$selectColumns} FROM {$schema}.`Users` LIMIT {$remaining}")->fetchAll();
        foreach ($rows as $row) {
            $row['database'] = $schemaName;
            $row['isPrimaryAccount'] = strcasecmp((string)$schemaName, (string)$DB_INVENTORY) === 0;
            $users[] = $row;
        }
        if (count($users) > 500) {
            $users = array_slice($users, 0, 500);
            $truncatedUsers = true;
            break;
        }
    }

    $activitySchema = null;
    foreach ($schemas as $schemaName) {
        if (strcasecmp((string)$schemaName, (string)$DB_INVENTORY) === 0) {
            $activitySchema = (string)$schemaName;
            break;
        }
    }
    $workspaceLastUse = [];
    if ($activitySchema !== null) {
        $quotedActivitySchema = admin_quote_identifier($activitySchema);
        $activityTables = array_column($tablesBySchema[$activitySchema] ?? [], 'name');
        if (in_array('Users', $activityTables, true) && in_array('LoginHistory', $activityTables, true)) {
            $lastLogin = $pdo->query(
                "SELECT u.UserID, u.Username, "
                . 'MAX(CASE WHEN l.Success = 1 THEN l.LoginDateTime END) AS LastLoginAt, '
                . 'MAX(CASE WHEN l.Success = 1 THEN l.LastActivity END) AS LastLoginActivity '
                . "FROM {$quotedActivitySchema}.`Users` u "
                . "LEFT JOIN {$quotedActivitySchema}.`LoginHistory` l ON l.UserID = u.UserID "
                . 'GROUP BY u.UserID, u.Username'
            )->fetchAll();
            $lastLoginsByUser = [];
            foreach ($users as &$user) {
                $user['LastLoginAt'] = null;
            }
            foreach ($lastLogin as $login) {
                $lastLoginsByUser[strtolower((string)$login['Username'])] = $login;
                $workspaceLastUse[(int)$login['UserID']]['login'] = $login['LastLoginActivity'] ?: $login['LastLoginAt'];
            }
            foreach ($users as &$user) {
                $login = $lastLoginsByUser[strtolower((string)$user['Username'])] ?? null;
                $user['LastLoginAt'] = $login['LastLoginAt'] ?? null;
            }
            unset($user);
        }
        $combinedActivityAvailable = in_array('ActivityLogs', $activityTables, true)
            && in_array('Users', $activityTables, true);
        if ($combinedActivityAvailable) {
            $centralActivity = $pdo->query(
                "SELECT u.UserID, u.Username, MAX(a.DateTime) AS LastUsedAt "
                . "FROM {$quotedActivitySchema}.`Users` u "
                . "LEFT JOIN {$quotedActivitySchema}.`ActivityLogs` a ON a.UserID = u.UserID "
                . 'GROUP BY u.UserID, u.Username'
            )->fetchAll();
            $activityByUser = [];
            foreach ($centralActivity as $activity) {
                $activityByUser[strtolower((string)$activity['Username'])] = $activity['LastUsedAt'];
                $workspaceLastUse[(int)$activity['UserID']]['activity'] = $activity['LastUsedAt'];
            }
            foreach ($users as &$user) {
                $user['LastUsedAt'] = $activityByUser[strtolower((string)$user['Username'])] ?? null;
            }
            unset($user);
        }
    }

    $databases = [];
    foreach ($schemas as $schemaName) {
        $workspaceLastUsed = null;
        $workspace = admin_workspace_database_owner($pdo, (string)$schemaName);
        if ($workspace) {
            $ownerUse = $workspaceLastUse[(int)$workspace['ownerId']] ?? [];
            $useTimes = array_values(array_filter([$ownerUse['login'] ?? null, $ownerUse['activity'] ?? null]));
            if ($useTimes) {
                rsort($useTimes, SORT_STRING);
                $workspaceLastUsed = $useTimes[0];
            }
        }
        $displayName = preg_match('/^[A-Z0-9_]+_(?:INVENTORY|USER_CONTROL)$/i', (string)$schemaName)
            ? strtoupper((string)$schemaName)
            : (string)$schemaName;
        $databases[] = [
            'name' => $displayName,
            'tables' => $tablesBySchema[$schemaName] ?? [],
            'lastUsedAt' => $workspaceLastUsed
        ];
    }

    $serverInfo = $pdo->query('SELECT VERSION() AS mysqlVersion, NOW() AS serverTime')->fetch();
    $databaseStats = $pdo->query(
        "SELECT COUNT(DISTINCT TABLE_SCHEMA) AS databaseCount, COUNT(*) AS tableCount, "
        . 'COALESCE(SUM(TABLE_ROWS), 0) AS estimatedRows, '
        . 'COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) AS sizeBytes '
        . "FROM information_schema.TABLES "
        . "WHERE TABLE_SCHEMA NOT IN ('information_schema', 'mysql', 'performance_schema', 'sys')"
    )->fetch();

    $centralSchema = null;
    foreach ($schemas as $schemaName) {
        if (strcasecmp((string)$schemaName, (string)$DB_INVENTORY) === 0) {
            $centralSchema = (string)$schemaName;
            break;
        }
    }

    $accountSummary = ['total' => count($users), 'active' => 0, 'disabled' => 0];
    $recentLogins = [];
    $recentActivity = [];
    $recentAdminEvents = [];
    $activeSessions = 0;
    $successfulLogins24h = 0;
    if ($centralSchema !== null) {
        $centralTables = array_column($tablesBySchema[$centralSchema] ?? [], 'name');
        $quotedCentralSchema = admin_quote_identifier($centralSchema);
        if (in_array('Users', $centralTables, true)) {
            $summary = $pdo->query(
                "SELECT COUNT(*) AS total, "
                . "SUM(CASE WHEN Status = 'active' THEN 1 ELSE 0 END) AS active, "
                . "SUM(CASE WHEN Status = 'disabled' THEN 1 ELSE 0 END) AS disabled "
                . "FROM {$quotedCentralSchema}.`Users`"
            )->fetch();
            $accountSummary = [
                'total' => (int)$summary['total'],
                'active' => (int)$summary['active'],
                'disabled' => (int)$summary['disabled']
            ];
        }
        if (in_array('LoginHistory', $centralTables, true) && in_array('Users', $centralTables, true)) {
            $loginStats = $pdo->query(
                "SELECT "
                . "SUM(CASE WHEN l.IsCurrentSession = 1 AND l.LastActivity >= DATE_SUB(NOW(), INTERVAL 20 MINUTE) THEN 1 ELSE 0 END) AS activeSessions, "
                . "SUM(CASE WHEN l.Success = 1 AND l.LoginDateTime >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) AS successfulLogins24h "
                . "FROM {$quotedCentralSchema}.`LoginHistory` l"
            )->fetch();
            $activeSessions = (int)$loginStats['activeSessions'];
            $successfulLogins24h = (int)$loginStats['successfulLogins24h'];
            $recentLogins = $pdo->query(
                "SELECT u.Username, u.FirstName, u.LastName, l.LoginDateTime, l.LastActivity, "
                . 'l.DeviceType, l.Browser, l.OperatingSystem, l.Success, l.IsCurrentSession '
                . "FROM {$quotedCentralSchema}.`LoginHistory` l "
                . "LEFT JOIN {$quotedCentralSchema}.`Users` u ON u.UserID = l.UserID "
                . 'ORDER BY l.LoginID DESC LIMIT 20'
            )->fetchAll();
        }
        if (in_array('ActivityLogs', $centralTables, true) && in_array('Users', $centralTables, true)) {
            $recentActivity = $pdo->query(
                "SELECT u.Username, a.Action, a.Module, a.DateTime "
                . "FROM {$quotedCentralSchema}.`ActivityLogs` a "
                . "LEFT JOIN {$quotedCentralSchema}.`Users` u ON u.UserID = a.UserID "
                . 'ORDER BY a.LogID DESC LIMIT 20'
            )->fetchAll();
        }
        if (in_array('AdminAuditLog', $centralTables, true)) {
            $recentAdminEvents = $pdo->query(
                "SELECT AdminName, Action, Item, CreatedAt FROM {$quotedCentralSchema}.`AdminAuditLog` "
                . 'ORDER BY AuditID DESC LIMIT 20'
            )->fetchAll();
        }
    }

    admin_respond([
        'success' => true,
        'databases' => $databases,
        'users' => $users,
        'usersTruncated' => $truncatedUsers,
        'system' => [
            'mysqlVersion' => (string)($serverInfo['mysqlVersion'] ?? 'Unknown'),
            'phpVersion' => PHP_VERSION,
            'serverTime' => (string)($serverInfo['serverTime'] ?? ''),
            'databaseCount' => (int)$databaseStats['databaseCount'],
            'tableCount' => (int)$databaseStats['tableCount'],
            'estimatedRows' => (int)$databaseStats['estimatedRows'],
            'sizeBytes' => (int)$databaseStats['sizeBytes'],
            'accountSummary' => $accountSummary,
            'activeSessions' => $activeSessions,
            'successfulLogins24h' => $successfulLogins24h
        ],
        'recentLogins' => $recentLogins,
        'recentActivity' => $recentActivity,
        'recentAdminEvents' => $recentAdminEvents,
        'recycleBin' => (function () use ($pdo): array {
            admin_recycle_table($pdo);
            return $pdo->query(
                'SELECT RecycleID AS id, ItemType AS itemType, DatabaseName AS databaseName, '
                . 'TableName AS tableName, ArchiveSize AS sizeBytes, ArchivedAt AS archivedAt '
                . 'FROM AdminRecycleBin ORDER BY ArchivedAt DESC LIMIT 100'
            )->fetchAll();
        })()
    ]);
} catch (Throwable $error) {
    error_log('Admin API error: ' . $error->getMessage());
    admin_respond(['success' => false, 'message' => 'Admin service is unavailable. Please try again later.'], 500);
}
