<?php
declare(strict_types=1);

function tenant_quote_identifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function tenant_create_database(PDO $server, string $sourceDatabase, string $targetDatabase, array $tablesToCopy): void
{
    $sourceName = tenant_quote_identifier($sourceDatabase);
    $targetName = tenant_quote_identifier($targetDatabase);
    $server->exec("CREATE DATABASE {$targetName} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    try {
        $target = new PDO(
            'mysql:host=' . $GLOBALS['DB_HOST'] . ';dbname=' . $targetDatabase . ';charset=utf8mb4',
            $GLOBALS['DB_USER'],
            $GLOBALS['DB_PASS'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $availableTables = $server->query("SHOW FULL TABLES FROM {$sourceName}")->fetchAll(PDO::FETCH_NUM);
        $availableTables = array_change_key_case(array_column($availableTables, 1, 0), CASE_LOWER);
        $target->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($tablesToCopy as $tableName) {
                if (($availableTables[strtolower($tableName)] ?? '') !== 'BASE TABLE') {
                    continue;
                }

                $quotedTableName = tenant_quote_identifier($tableName);
                $create = $server->query("SHOW CREATE TABLE {$sourceName}.{$quotedTableName}")->fetch(PDO::FETCH_NUM);
                if (!isset($create[1])) {
                    throw new RuntimeException('Unable to read a source table definition.');
                }
                $target->exec($create[1]);

            }
        } finally {
            $target->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    } catch (Throwable $error) {
        try {
            $server->exec("DROP DATABASE {$targetName}");
        } catch (Throwable $cleanupError) {
            error_log('Tenant database cleanup failed: ' . $cleanupError->getMessage());
        }
        throw $error;
    }
}

function provision_user_databases(int $userId, array $user, string $passwordHash): void
{
    global $DB_HOST, $DB_USER, $DB_PASS, $DB_INVENTORY, $DB_CONTROL;

    if ($userId < 1 || !preg_match('/^[A-Za-z0-9_]+$/', $DB_INVENTORY) || !preg_match('/^[A-Za-z0-9_]+$/', $DB_CONTROL)) {
        throw new RuntimeException('Invalid tenant provisioning configuration.');
    }

    $databaseStem = strtoupper(trim((string)($user['WorkspaceDatabaseStem'] ?? '')));
    if (!preg_match('/^[A-Z0-9_]{1,51}$/', $databaseStem)) {
        throw new RuntimeException('Invalid workspace database name.');
    }
    $inventoryDatabase = substr($databaseStem, 0, 51) . '_INVENTORY';
    $controlDatabase = substr($databaseStem, 0, 50) . '_USER_CONTROL';
    $createdDatabases = [];
    $server = null;

    try {
        $server = new PDO(
            'mysql:host=' . $DB_HOST . ';charset=utf8mb4',
            $DB_USER,
            $DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        tenant_create_database($server, $DB_INVENTORY, $inventoryDatabase, [
            'Users',
            'LoginHistory',
            'ActivityLogs',
            'RememberedDevices',
            'AccountLockouts',
            'PasswordResetRequests',
        ]);
        $createdDatabases[] = $inventoryDatabase;
        tenant_create_database($server, $DB_CONTROL, $controlDatabase, ['navigation_menu']);
        $createdDatabases[] = $controlDatabase;

        $control = new PDO(
            'mysql:host=' . $DB_HOST . ';dbname=' . $controlDatabase . ';charset=utf8mb4',
            $DB_USER,
            $DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $control->exec("CREATE TABLE system_recycle_bin (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            item_type VARCHAR(32) NOT NULL,
            source_table VARCHAR(255) NULL,
            item_name VARCHAR(255) NOT NULL DEFAULT '',
            payload LONGTEXT NOT NULL,
            deleted_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            INDEX idx_recycle_expires (expires_at),
            INDEX idx_recycle_type (item_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $tenant = new PDO(
            'mysql:host=' . $DB_HOST . ';dbname=' . $inventoryDatabase . ';charset=utf8mb4',
            $DB_USER,
            $DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $insert = $tenant->prepare(
            'INSERT INTO Users (UserID, EmployeeID, AccountID, FirstName, LastName, WorkspaceDatabaseStem, Department, WorkArea, Username, PasswordHash, Status) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $userId,
            $user['EmployeeID'],
            $user['AccountID'],
            $user['FirstName'],
            $user['LastName'],
            $databaseStem,
            $user['Department'],
            $user['WorkArea'],
            $user['Username'],
            $passwordHash,
            $user['Status'],
        ]);
    } catch (Throwable $error) {
        foreach ($createdDatabases as $database) {
            try {
                if ($server instanceof PDO) {
                    $server->exec('DROP DATABASE ' . tenant_quote_identifier($database));
                }
            } catch (Throwable $cleanupError) {
                error_log('Tenant database cleanup failed: ' . $cleanupError->getMessage());
            }
        }
        error_log('Tenant provisioning failed: ' . $error->getMessage());
        throw $error;
    }
}
