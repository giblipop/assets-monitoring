<?php
declare(strict_types=1);

const ASSET_BACKUP_PASSPHRASE = '123';
const ASSET_BACKUP_DEFAULT_DIRECTORY = 'C:\\assets-monitoring-backups';
const ASSET_BACKUP_FORMAT_VERSION = 1;

function asset_backup_directory(): string
{
    $configured = getenv('ASSETS_MONITORING_BACKUP_DIR');
    return $configured !== false && trim($configured) !== ''
        ? rtrim($configured, '\\/')
        : ASSET_BACKUP_DEFAULT_DIRECTORY;
}

function asset_backup_quote(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function asset_backup_server(): PDO
{
    global $DB_HOST, $DB_USER, $DB_PASS;
    return new PDO(
        "mysql:host={$DB_HOST};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
}

function asset_backup_project_database(string $name): bool
{
    return in_array($name, ['inventory', 'control_panel_db'], true)
        || preg_match('/^assets_user_(?:inventory|control)_[0-9]+$/i', $name) === 1
        || preg_match('/^[A-Z0-9_]+_(?:INVENTORY|USER_CONTROL)$/i', $name) === 1;
}

function asset_backup_workspace_stem(string $firstName, string $lastName): string
{
    $name = trim($firstName . ' ' . $lastName);
    $asciiName = function_exists('iconv')
        ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name)
        : false;
    $source = is_string($asciiName) ? $asciiName : $name;
    $stem = strtoupper(trim((string)preg_replace('/[^A-Za-z0-9]+/', '_', $source), '_'));
    return substr($stem, 0, 51) ?: 'USER';
}

function asset_backup_workspace_database_names(PDO $server, int $ownerId): array
{
    if ($ownerId < 1) {
        throw new InvalidArgumentException('A valid workspace owner is required.');
    }
    $hasStemColumn = $server->query(
        "SELECT 1 FROM information_schema.COLUMNS "
        . "WHERE TABLE_SCHEMA = 'inventory' AND TABLE_NAME = 'Users' "
        . "AND COLUMN_NAME = 'WorkspaceDatabaseStem' LIMIT 1"
    )->fetchColumn();
    $lookup = $server->prepare(
        'SELECT FirstName, LastName'
        . ($hasStemColumn ? ', WorkspaceDatabaseStem' : '')
        . ' FROM inventory.Users WHERE UserID = ?'
    );
    $lookup->execute([$ownerId]);
    $user = $lookup->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        throw new RuntimeException('The workspace owner account could not be found.');
    }

    $stem = strtoupper(trim((string)($user['WorkspaceDatabaseStem'] ?? '')));
    if ($stem === '' || !preg_match('/^[A-Z0-9_]{1,51}$/', $stem)) {
        $stem = asset_backup_workspace_stem((string)$user['FirstName'], (string)$user['LastName']);
    }
    return [
        'inventory' => substr($stem, 0, 51) . '_INVENTORY',
        'control' => substr($stem, 0, 50) . '_USER_CONTROL'
    ];
}

function asset_backup_legacy_workspace_database_names(int $ownerId): array
{
    return [
        'inventory' => 'assets_user_inventory_' . $ownerId,
        'control' => 'assets_user_control_' . $ownerId
    ];
}

function asset_backup_normalize_full_backup_names(array $document): array
{
    $usersTableName = null;
    foreach (array_keys($document['databases']['inventory']['tables'] ?? []) as $tableName) {
        if (strcasecmp((string)$tableName, 'Users') === 0) {
            $usersTableName = (string)$tableName;
            break;
        }
    }
    $userRows = $usersTableName !== null
        ? ($document['databases']['inventory']['tables'][$usersTableName]['rows'] ?? null)
        : null;
    if (!is_array($userRows)) {
        return $document;
    }
    foreach ($userRows as $userRow) {
        if (!is_array($userRow)) {
            throw new RuntimeException('The full-system backup contains an invalid account row.');
        }
    }
    usort($userRows, static fn(array $left, array $right): int =>
        (int)($left['UserID'] ?? 0) <=> (int)($right['UserID'] ?? 0)
    );
    $usedStems = [];
    foreach ($userRows as $user) {
        $ownerId = (int)($user['UserID'] ?? 0);
        if ($ownerId < 1) {
            continue;
        }
        $stem = strtoupper(trim((string)($user['WorkspaceDatabaseStem'] ?? '')));
        if ($stem === '' || !preg_match('/^[A-Z0-9_]{1,51}$/', $stem)) {
            $stem = asset_backup_workspace_stem(
                (string)($user['FirstName'] ?? ''),
                (string)($user['LastName'] ?? '')
            );
        }
        if (isset($usedStems[$stem])) {
            $suffix = '_' . $ownerId;
            $stem = substr($stem, 0, 51 - strlen($suffix)) . $suffix;
        }
        $usedStems[$stem] = true;
        $legacy = asset_backup_legacy_workspace_database_names($ownerId);
        $named = [
            'inventory' => substr($stem, 0, 51) . '_INVENTORY',
            'control' => substr($stem, 0, 50) . '_USER_CONTROL'
        ];
        foreach (['inventory', 'control'] as $type) {
            if (!isset($document['databases'][$named[$type]])
                && isset($document['databases'][$legacy[$type]])) {
                $document['databases'][$named[$type]] = $document['databases'][$legacy[$type]];
            }
            unset($document['databases'][$legacy[$type]]);
        }
    }
    return $document;
}

function asset_backup_existing_workspace_database_names(PDO $server, int $ownerId): array
{
    $named = asset_backup_workspace_database_names($server, $ownerId);
    $legacy = asset_backup_legacy_workspace_database_names($ownerId);
    $exists = $server->prepare(
        'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME IN (?, ?)'
    );
    $exists->execute(array_values($named));
    $namedCount = (int)$exists->fetchColumn();
    if ($namedCount === 2) {
        return $named;
    }
    if ($namedCount !== 0) {
        throw new RuntimeException('The named workspace database pair is incomplete.');
    }
    $exists->execute(array_values($legacy));
    $legacyCount = (int)$exists->fetchColumn();
    if ($legacyCount === 0) {
        return $named;
    }
    return asset_backup_migrate_workspace_databases($server, $ownerId);
}

function asset_backup_allocate_workspace_stem(PDO $server, string $firstName, string $lastName, int $ownerId): string
{
    $base = asset_backup_workspace_stem($firstName, $lastName);
    $users = $server->query(
        'SELECT UserID, FirstName, LastName, WorkspaceDatabaseStem FROM inventory.Users'
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($users as $user) {
        $otherId = (int)$user['UserID'];
        if ($otherId === $ownerId) {
            continue;
        }
        $existingStem = strtoupper(trim((string)($user['WorkspaceDatabaseStem'] ?? '')));
        if ($existingStem === '') {
            if ($otherId > $ownerId) {
                continue;
            }
            $existingStem = asset_backup_workspace_stem(
                (string)$user['FirstName'],
                (string)$user['LastName']
            );
        }
        if ($existingStem === $base) {
            $suffix = '_' . $ownerId;
            return substr($base, 0, 51 - strlen($suffix)) . $suffix;
        }
    }

    $candidateDatabases = [
        substr($base, 0, 51) . '_INVENTORY',
        substr($base, 0, 50) . '_USER_CONTROL'
    ];
    $databaseCheck = $server->prepare(
        'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME IN (?, ?)'
    );
    $databaseCheck->execute($candidateDatabases);
    $matchingDatabaseCount = (int)$databaseCheck->fetchColumn();
    if ($matchingDatabaseCount === 2) {
        return $base;
    }
    if ($matchingDatabaseCount > 0) {
        $suffix = '_' . $ownerId;
        return substr($base, 0, 51 - strlen($suffix)) . $suffix;
    }
    return $base;
}

function asset_backup_migrate_workspace_databases(PDO $server, int $ownerId): array
{
    $named = asset_backup_workspace_database_names($server, $ownerId);
    $legacy = asset_backup_legacy_workspace_database_names($ownerId);
    $exists = $server->prepare(
        'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME IN (?, ?)'
    );
    $exists->execute(array_values($named));
    $namedCount = (int)$exists->fetchColumn();
    $exists->execute(array_values($legacy));
    $legacyCount = (int)$exists->fetchColumn();

    if ($namedCount === 2) {
        return $named;
    }
    if ($namedCount !== 0 || $legacyCount !== 2) {
        throw new RuntimeException('The workspace database pair is incomplete or conflicts with the requested names.');
    }

    foreach ($legacy as $legacyName) {
        $sourceName = asset_backup_quote($legacyName);
        $objects = $server->query("SHOW FULL TABLES FROM {$sourceName}")->fetchAll(PDO::FETCH_NUM);
        foreach ($objects as $object) {
            if (($object[1] ?? '') !== 'BASE TABLE') {
                throw new RuntimeException('Workspace migration cannot safely move a database containing views.');
            }
        }
        $schemaCheck = $server->prepare(
            'SELECT (SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?) '
            . '+ (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ?)'
        );
        $schemaCheck->execute([$legacyName, $legacyName]);
        if ((int)$schemaCheck->fetchColumn() > 0) {
            throw new RuntimeException(
                'Workspace migration cannot safely move a database containing triggers or routines.'
            );
        }
    }

    $created = [];
    $validated = false;
    try {
        foreach (['inventory', 'control'] as $type) {
            $sourceName = asset_backup_quote($legacy[$type]);
            $targetName = asset_backup_quote($named[$type]);
            $server->exec(
                'CREATE DATABASE ' . $targetName . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            );
            $created[] = $named[$type];
            $server->exec('SET FOREIGN_KEY_CHECKS=0');
            try {
                $objects = $server->query("SHOW FULL TABLES FROM {$sourceName}")->fetchAll(PDO::FETCH_NUM);
                foreach ($objects as $object) {
                    if (($object[1] ?? '') !== 'BASE TABLE') {
                        continue;
                    }
                    $tableName = (string)$object[0];
                    $quotedTable = asset_backup_quote($tableName);
                    $definition = $server->query(
                        "SHOW CREATE TABLE {$sourceName}.{$quotedTable}"
                    )->fetch(PDO::FETCH_NUM);
                    if (!isset($definition[1])) {
                        throw new RuntimeException('Could not read a workspace table definition.');
                    }
                    $createSql = str_replace($sourceName . '.', $targetName . '.', (string)$definition[1]);
                    $createSql = preg_replace(
                        '/^CREATE TABLE\s+/i',
                        'CREATE TABLE ' . $targetName . '.',
                        $createSql,
                        1
                    ) ?? $createSql;
                    $server->exec($createSql);
                    $columns = $server->query(
                        "SHOW COLUMNS FROM {$sourceName}.{$quotedTable}"
                    )->fetchAll(PDO::FETCH_ASSOC);
                    $copyColumns = [];
                    foreach ($columns as $column) {
                        if (preg_match('/\b(?:virtual|stored)\s+generated\b/i', (string)($column['Extra'] ?? ''))) {
                            continue;
                        }
                        $copyColumns[] = asset_backup_quote((string)$column['Field']);
                    }
                    if ($copyColumns) {
                        $columnList = implode(', ', $copyColumns);
                        $server->exec(
                            "INSERT INTO {$targetName}.{$quotedTable} ({$columnList}) "
                            . "SELECT {$columnList} FROM {$sourceName}.{$quotedTable}"
                        );
                    }
                }
                foreach ($server->query("SHOW EVENTS FROM {$sourceName}")->fetchAll(PDO::FETCH_ASSOC) as $event) {
                    $eventName = (string)$event['Name'];
                    $eventDefinition = $server->query(
                        'SHOW CREATE EVENT ' . $sourceName . '.' . asset_backup_quote($eventName)
                    )->fetch(PDO::FETCH_NUM);
                    if (!isset($eventDefinition[3]) || !is_string($eventDefinition[3])) {
                        throw new RuntimeException('Could not read a workspace scheduled event definition.');
                    }
                    $eventSql = preg_replace(
                        '/\bEVENT\s+`' . preg_quote($eventName, '/') . '`/i',
                        'EVENT ' . $targetName . '.' . asset_backup_quote($eventName),
                        $eventDefinition[3],
                        1
                    );
                    if (!is_string($eventSql)) {
                        throw new RuntimeException('Could not prepare a workspace scheduled event.');
                    }
                    $server->exec($eventSql);
                }
            } finally {
                $server->exec('SET FOREIGN_KEY_CHECKS=1');
            }
        }

        foreach (['inventory', 'control'] as $type) {
            $sourceName = asset_backup_quote($legacy[$type]);
            $targetName = asset_backup_quote($named[$type]);
            $sourceTables = $server->query("SHOW FULL TABLES FROM {$sourceName}")
                ->fetchAll(PDO::FETCH_NUM);
            $targetTables = $server->query("SHOW FULL TABLES FROM {$targetName}")
                ->fetchAll(PDO::FETCH_NUM);
            $sourceTables = array_map(
                static fn(array $table): string => (string)$table[0],
                array_filter($sourceTables, static fn(array $table): bool => ($table[1] ?? '') === 'BASE TABLE')
            );
            $targetTables = array_map(
                static fn(array $table): string => (string)$table[0],
                array_filter($targetTables, static fn(array $table): bool => ($table[1] ?? '') === 'BASE TABLE')
            );
            sort($sourceTables, SORT_STRING);
            sort($targetTables, SORT_STRING);
            if ($sourceTables !== $targetTables) {
                throw new RuntimeException('Workspace migration validation failed: table lists differ.');
            }
            $sourceEvents = array_map(
                static fn(array $event): string => (string)$event['Name'],
                $server->query("SHOW EVENTS FROM {$sourceName}")->fetchAll(PDO::FETCH_ASSOC)
            );
            $targetEvents = array_map(
                static fn(array $event): string => (string)$event['Name'],
                $server->query("SHOW EVENTS FROM {$targetName}")->fetchAll(PDO::FETCH_ASSOC)
            );
            sort($sourceEvents, SORT_STRING);
            sort($targetEvents, SORT_STRING);
            if ($sourceEvents !== $targetEvents) {
                throw new RuntimeException('Workspace migration validation failed: scheduled events differ.');
            }
            foreach ($sourceTables as $tableName) {
                $quotedTable = asset_backup_quote($tableName);
                $sourceRows = (int)$server->query(
                    "SELECT COUNT(*) FROM {$sourceName}.{$quotedTable}"
                )->fetchColumn();
                $targetRows = (int)$server->query(
                    "SELECT COUNT(*) FROM {$targetName}.{$quotedTable}"
                )->fetchColumn();
                if ($sourceRows !== $targetRows) {
                    throw new RuntimeException('Workspace migration validation failed: row counts differ.');
                }
            }
        }

        $validated = true;
        foreach ($legacy as $legacyName) {
            $server->exec('DROP DATABASE ' . asset_backup_quote($legacyName));
        }
        return $named;
    } catch (Throwable $error) {
        if (!$validated) {
            foreach (array_reverse($created) as $databaseName) {
                try {
                    $server->exec('DROP DATABASE ' . asset_backup_quote($databaseName));
                } catch (Throwable $cleanupError) {
                    error_log('Incomplete workspace migration cleanup failed: ' . $cleanupError->getMessage());
                }
            }
        }
        throw $error;
    }
}

function asset_backup_databases(PDO $server, bool $requireCore = true): array
{
    $names = [];
    foreach ($server->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN) as $name) {
        $name = (string)$name;
        if (asset_backup_project_database($name)) {
            $names[] = preg_match('/^[A-Z0-9_]+_(?:INVENTORY|USER_CONTROL)$/i', $name)
                ? strtoupper($name)
                : $name;
        }
    }
    sort($names, SORT_STRING);
    if ($requireCore && (!in_array('inventory', $names, true) || !in_array('control_panel_db', $names, true))) {
        throw new RuntimeException('The system or control database is missing; no complete backup can be made.');
    }
    return $names;
}

function asset_backup_capture(PDO $server, ?array $onlyDatabases = null): array
{
    $databases = [];
    $availableDatabases = asset_backup_databases($server, $onlyDatabases === null);
    $databaseNames = $onlyDatabases === null
        ? $availableDatabases
        : array_values(array_intersect($availableDatabases, $onlyDatabases));
    foreach ($databaseNames as $databaseName) {
        $database = asset_backup_quote($databaseName);
        $databaseInfo = $server->prepare(
            'SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME '
            . 'FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?'
        );
        $databaseInfo->execute([$databaseName]);
        $settings = $databaseInfo->fetch();
        if (!$settings) {
            throw new RuntimeException('Could not read database settings for ' . $databaseName . '.');
        }

        $tables = [];
        $events = [];
        $objects = $server->query("SHOW FULL TABLES FROM {$database}")->fetchAll(PDO::FETCH_NUM);
        foreach ($objects as $object) {
            $tableName = (string)$object[0];
            if (($object[1] ?? '') !== 'BASE TABLE') {
                continue;
            }
            $table = asset_backup_quote($tableName);
            $create = $server->query("SHOW CREATE TABLE {$database}.{$table}")->fetch(PDO::FETCH_NUM);
            if (!isset($create[1]) || !str_starts_with(strtoupper(ltrim((string)$create[1])), 'CREATE TABLE')) {
                throw new RuntimeException('Could not read the definition for ' . $databaseName . '.' . $tableName . '.');
            }

            $columns = $server->query("SHOW COLUMNS FROM {$database}.{$table}")->fetchAll();
            $selectColumns = [];
            $binaryColumns = [];
            foreach ($columns as $column) {
                $columnName = (string)$column['Field'];
                if (preg_match('/\b(?:virtual|stored)\s+generated\b/i', (string)($column['Extra'] ?? ''))) {
                    continue;
                }
                $selectColumns[] = asset_backup_quote($columnName);
                if (preg_match('/\b(binary|varbinary|tinyblob|blob|mediumblob|longblob|bit)\b/i', (string)$column['Type'])) {
                    $binaryColumns[$columnName] = true;
                }
            }

            $rows = [];
            if ($selectColumns) {
                $statement = $server->query(
                    'SELECT ' . implode(', ', $selectColumns) . " FROM {$database}.{$table}"
                );
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
            $tables[$tableName] = [
                'create_sql' => (string)$create[1],
                'rows' => $rows
            ];
        }
        foreach ($server->query("SHOW EVENTS FROM {$database}")->fetchAll(PDO::FETCH_ASSOC) as $event) {
            $eventName = (string)$event['Name'];
            if (!preg_match('/^[A-Za-z0-9_]+$/', $eventName)) {
                throw new RuntimeException('A scheduled database event has an unsupported name.');
            }
            $eventSql = $server->query(
                'SHOW CREATE EVENT ' . $database . '.' . asset_backup_quote($eventName)
            )->fetch(PDO::FETCH_NUM);
            if (!isset($eventSql[3]) || !is_string($eventSql[3])) {
                throw new RuntimeException('Could not read a scheduled database event definition.');
            }
            $events[$eventName] = $eventSql[3];
        }
        $databases[$databaseName] = [
            'charset' => (string)$settings['DEFAULT_CHARACTER_SET_NAME'],
            'collation' => (string)$settings['DEFAULT_COLLATION_NAME'],
            'tables' => $tables,
            'events' => $events
        ];
    }

    return [
        'format' => 'assets-monitoring-encrypted-json',
        'version' => ASSET_BACKUP_FORMAT_VERSION,
        'created_at' => gmdate('c'),
        'databases' => $databases
    ];
}

function asset_backup_derive_key(string $password, string $salt): string
{
    return hash_pbkdf2('sha256', $password, $salt, 250000, 32, true);
}

function asset_backup_encrypt(array $document, string $password): string
{
    $json = json_encode(
        $document,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
    );
    $compressed = gzencode($json, 6);
    if ($compressed === false) {
        throw new RuntimeException('Could not compress the database snapshot.');
    }
    $salt = random_bytes(16);
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt(
        $compressed,
        'aes-256-gcm',
        asset_backup_derive_key($password, $salt),
        OPENSSL_RAW_DATA,
        $nonce,
        $tag,
        'assets-monitoring-json-backup-v1'
    );
    if ($ciphertext === false) {
        throw new RuntimeException('Could not encrypt the database snapshot.');
    }

    return json_encode([
        'format' => 'assets-monitoring-encrypted-json',
        'version' => ASSET_BACKUP_FORMAT_VERSION,
        'cipher' => 'AES-256-GCM',
        'kdf' => 'PBKDF2-HMAC-SHA256',
        'iterations' => 250000,
        'salt' => base64_encode($salt),
        'nonce' => base64_encode($nonce),
        'tag' => base64_encode($tag),
        'ciphertext' => base64_encode($ciphertext)
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function asset_backup_decrypt(string $contents, string $password): array
{
    $envelope = json_decode($contents, true);
    if (!is_array($envelope) ||
        ($envelope['format'] ?? '') !== 'assets-monitoring-encrypted-json' ||
        (int)($envelope['version'] ?? 0) !== ASSET_BACKUP_FORMAT_VERSION ||
        ($envelope['cipher'] ?? '') !== 'AES-256-GCM' ||
        (int)($envelope['iterations'] ?? 0) !== 250000) {
        throw new RuntimeException('This is not a supported encrypted JSON database backup.');
    }

    $salt = base64_decode((string)($envelope['salt'] ?? ''), true);
    $nonce = base64_decode((string)($envelope['nonce'] ?? ''), true);
    $tag = base64_decode((string)($envelope['tag'] ?? ''), true);
    $ciphertext = base64_decode((string)($envelope['ciphertext'] ?? ''), true);
    if ($salt === false || strlen($salt) !== 16 || $nonce === false || strlen($nonce) !== 12 ||
        $tag === false || strlen($tag) !== 16 || $ciphertext === false) {
        throw new RuntimeException('The encrypted backup file is incomplete or damaged.');
    }
    $compressed = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        asset_backup_derive_key($password, $salt),
        OPENSSL_RAW_DATA,
        $nonce,
        $tag,
        'assets-monitoring-json-backup-v1'
    );
    if ($compressed === false) {
        throw new InvalidArgumentException('The encryption password is incorrect or the backup was altered.');
    }
    $json = gzdecode($compressed);
    if ($json === false) {
        throw new RuntimeException('The decrypted backup data is damaged.');
    }
    $document = json_decode($json, true);
    if (!is_array($document) || ($document['format'] ?? '') !== 'assets-monitoring-encrypted-json' ||
        (int)($document['version'] ?? 0) !== ASSET_BACKUP_FORMAT_VERSION ||
        !is_array($document['databases'] ?? null)) {
        throw new RuntimeException('The decrypted file is not a supported database backup.');
    }
    $workspaceOwnerId = (int)($document['workspace_owner_id'] ?? 0);
    if ($workspaceOwnerId > 0) {
        $databaseNames = array_keys($document['databases']);
        $legacyNames = array_values(asset_backup_legacy_workspace_database_names($workspaceOwnerId));
        $inventoryStem = null;
        $controlStem = null;
        foreach ($databaseNames as $databaseName) {
            if (preg_match('/^([A-Z0-9_]+)_INVENTORY$/i', (string)$databaseName, $match)) {
                $inventoryStem = strtoupper($match[1]);
            } elseif (preg_match('/^([A-Z0-9_]+)_USER_CONTROL$/i', (string)$databaseName, $match)) {
                $controlStem = strtoupper($match[1]);
            }
        }
        $namedPair = count($databaseNames) === 2
            && $inventoryStem !== null
            && $inventoryStem === $controlStem;
        $legacyPair = count($databaseNames) === 2
            && !array_diff($legacyNames, $databaseNames)
            && !array_diff($databaseNames, $legacyNames);
        if (!$namedPair && !$legacyPair) {
            throw new RuntimeException('The backup does not contain exactly this workspace.');
        }
    } elseif (!isset($document['databases']['inventory'], $document['databases']['control_panel_db'])) {
        throw new RuntimeException('The decrypted file is not a complete system backup.');
    }
    foreach ($document['databases'] as $databaseName => $database) {
        if (!asset_backup_project_database((string)$databaseName) ||
            !is_array($database) || !is_array($database['tables'] ?? null)) {
            throw new RuntimeException('The backup contains an invalid database entry.');
        }
        foreach ($database['tables'] as $tableName => $table) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$tableName) ||
                !is_array($table) || !is_string($table['create_sql'] ?? null) ||
                !str_starts_with(strtoupper(ltrim($table['create_sql'])), 'CREATE TABLE') ||
                !preg_match('/^CREATE TABLE\s+`' . preg_quote((string)$tableName, '/') . '`/i', ltrim($table['create_sql'])) ||
                str_contains($table['create_sql'], ';') ||
                !is_array($table['rows'] ?? null)) {
                throw new RuntimeException('The backup contains an invalid table entry.');
            }
            foreach ($table['rows'] as $row) {
                if (!is_array($row)) {
                    throw new RuntimeException('The backup contains an invalid table row.');
                }
                foreach ($row as $columnName => $value) {
                    if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$columnName)) {
                        throw new RuntimeException('The backup contains an invalid column name.');
                    }
                    if (is_array($value) && array_key_exists('__asset_backup_binary_base64', $value)) {
                        $binary = base64_decode((string)$value['__asset_backup_binary_base64'], true);
                        if ($binary === false) {
                            throw new RuntimeException('The backup contains invalid binary data.');
                        }
                    } elseif (is_array($value)) {
                        throw new RuntimeException('The backup contains an unsupported field value.');
                    }
                }
                foreach (($database['events'] ?? []) as $eventName => $eventSql) {
                    if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$eventName) ||
                        !is_string($eventSql) ||
                        !preg_match('/^CREATE\s+(?:DEFINER\s*=\s*[^ ]+\s+)?EVENT\s+`' . preg_quote((string)$eventName, '/') . '`/i', ltrim($eventSql))) {
                        throw new RuntimeException('The backup contains an invalid scheduled event definition.');
                    }
                }
            }
        }
    }
    return $document;
}

function asset_backup_create(PDO $server, string $password = ASSET_BACKUP_PASSPHRASE): array
{
    $directory = asset_backup_directory();
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create the backup folder outside the web directory.');
    }
    $document = asset_backup_capture($server);
    $encrypted = asset_backup_encrypt($document, $password);
    $filename = 'assets-monitoring-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.json';
    $target = $directory . DIRECTORY_SEPARATOR . $filename;
    $temporary = $target . '.tmp';
    if (file_put_contents($temporary, $encrypted, LOCK_EX) === false || !rename($temporary, $target)) {
        if (is_file($temporary)) {
            unlink($temporary);
        }
        throw new RuntimeException('Could not write the encrypted database backup.');
    }
    $failureMarker = $directory . DIRECTORY_SEPARATOR . 'assets-monitoring-backup-error.json';
    if (is_file($failureMarker) && !unlink($failureMarker)) {
        error_log('Could not clear the system backup failure marker after a successful backup.');
    }
    asset_backup_prune($directory, 30);
    return [
        'filename' => $filename,
        'created_at' => $document['created_at'],
        'size_bytes' => filesize($target) ?: 0
    ];
}

function asset_backup_workspace_slug(string $firstName, string $lastName): string
{
    $name = trim($firstName . ' ' . $lastName);
    $asciiName = function_exists('iconv')
        ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name)
        : false;
    $slugSource = is_string($asciiName) ? $asciiName : $name;
    $slug = strtolower(trim((string)preg_replace('/[^A-Za-z0-9]+/', '-', $slugSource), '-'));
    return substr($slug, 0, 100) ?: 'user';
}

function asset_backup_workspace_directory(int $ownerId): string
{
    if ($ownerId < 1) {
        throw new InvalidArgumentException('A valid workspace owner is required.');
    }
    static $workspaceNames = [];
    if (!array_key_exists($ownerId, $workspaceNames)) {
        $server = asset_backup_server();
        $lookup = $server->prepare('SELECT FirstName, LastName FROM inventory.Users WHERE UserID = ?');
        $lookup->execute([$ownerId]);
        $user = $lookup->fetch();
        if (!$user) {
            $workspaceNames[$ownerId] = null;
        } else {
            $slug = asset_backup_workspace_slug((string)$user['FirstName'], (string)$user['LastName']);
            $duplicates = $server->query('SELECT UserID, FirstName, LastName FROM inventory.Users')
                ->fetchAll(PDO::FETCH_ASSOC);
            foreach ($duplicates as $otherUser) {
                if ((int)$otherUser['UserID'] !== $ownerId
                    && asset_backup_workspace_slug(
                        (string)$otherUser['FirstName'],
                        (string)$otherUser['LastName']
                    ) === $slug) {
                    $slug .= '-' . $ownerId;
                    break;
                }
            }
            $workspaceNames[$ownerId] = $slug;
        }
    }

    $usersDirectory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'users';
    $legacyDirectory = $usersDirectory . DIRECTORY_SEPARATOR . $ownerId
        . DIRECTORY_SEPARATOR . 'assets-monitoring' . DIRECTORY_SEPARATOR . 'backups';
    if ($workspaceNames[$ownerId] === null) {
        $directory = $legacyDirectory;
    } else {
        $directory = $usersDirectory . DIRECTORY_SEPARATOR . $workspaceNames[$ownerId]
            . DIRECTORY_SEPARATOR . 'assets-monitoring' . DIRECTORY_SEPARATOR . 'backups';
        if (!is_dir($directory) && is_dir($legacyDirectory)) {
            $directory = $legacyDirectory;
        }
    }
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create the workspace backup folder.');
    }
    $denyFile = $directory . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($denyFile)) {
        $rules = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\nOrder Allow,Deny\nDeny from all\n</IfModule>\n";
        if (file_put_contents($denyFile, $rules, LOCK_EX) === false) {
            throw new RuntimeException('Could not protect the workspace backup folder from web access.');
        }
    }
    return $directory;
}

function asset_backup_module_directory(int $ownerId): string
{
    $directory = asset_backup_workspace_directory($ownerId) . DIRECTORY_SEPARATOR . 'modules';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create the workspace module JSON folder.');
    }
    $denyFile = $directory . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($denyFile) && file_put_contents(
        $denyFile,
        "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\nOrder Allow,Deny\nDeny from all\n</IfModule>\n",
        LOCK_EX
    ) === false) {
        throw new RuntimeException('Could not protect the module JSON folder from web access.');
    }
    return $directory;
}

function asset_backup_is_module_table(string $tableName): bool
{
    static $systemTables = [
        'users', 'loginhistory', 'activitylogs', 'remembereddevices',
        'accountlockouts', 'passwordresetrequests', 'system_recycle_bin', 'navigation_trash'
    ];
    return preg_match('/^[A-Za-z0-9_]+$/', $tableName) === 1
        && !in_array(strtolower($tableName), $systemTables, true);
}

function asset_backup_write_module_files(int $ownerId, array $document): void
{
    $databaseName = null;
    foreach (array_keys($document['databases'] ?? []) as $candidate) {
        if (preg_match('/_INVENTORY$/i', (string)$candidate)) {
            $databaseName = (string)$candidate;
            break;
        }
    }
    if ($databaseName === null) {
        throw new RuntimeException('The workspace inventory database is missing from the JSON snapshot.');
    }
    $database = $document['databases'][$databaseName] ?? null;
    if (!is_array($database) || !is_array($database['tables'] ?? null)) {
        throw new RuntimeException('The workspace inventory database is missing from the JSON snapshot.');
    }
    $directory = asset_backup_module_directory($ownerId);
    foreach ($database['tables'] as $tableName => $table) {
        $tableName = (string)$tableName;
        if (!asset_backup_is_module_table($tableName) || !is_array($table)) {
            continue;
        }
        $module = [
            'format' => 'assets-monitoring-module-json',
            'version' => 1,
            'workspace_owner_id' => $ownerId,
            'module' => $tableName,
            'database_name' => $databaseName,
            'created_at' => (string)$document['created_at'],
            'charset' => (string)$database['charset'],
            'collation' => (string)$database['collation'],
            'table' => $table
        ];
        $json = json_encode(
            $module,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
        $target = $directory . DIRECTORY_SEPARATOR . $tableName . '.json';
        $temporary = $target . '.' . bin2hex(random_bytes(8)) . '.tmp';
        if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $target)) {
            if (is_file($temporary)) unlink($temporary);
            throw new RuntimeException('Could not update the JSON snapshot for module ' . $tableName . '.');
        }
    }
}

function asset_backup_restore_missing_databases(PDO $server, array $document, array $requestedDatabases): array
{
    $workspaceOwnerId = (int)($document['workspace_owner_id'] ?? 0);
    $targetDatabases = $document['databases'] ?? null;
    if (!is_array($targetDatabases)) {
        throw new RuntimeException('The selected JSON file does not contain database data.');
    }
    if ($workspaceOwnerId > 0) {
        $workspaceNames = asset_backup_workspace_database_names($server, $workspaceOwnerId);
        $legacyNames = asset_backup_legacy_workspace_database_names($workspaceOwnerId);
        $sourceInventory = $targetDatabases[$workspaceNames['inventory']]
            ?? $targetDatabases[$legacyNames['inventory']]
            ?? null;
        $sourceControl = $targetDatabases[$workspaceNames['control']]
            ?? $targetDatabases[$legacyNames['control']]
            ?? null;
        if (!is_array($sourceInventory) || !is_array($sourceControl)
            || array_diff($requestedDatabases, array_values($workspaceNames))) {
            throw new RuntimeException('The selected JSON file does not contain the complete requested workspace.');
        }
        $targetDatabases = [
            $workspaceNames['inventory'] => $sourceInventory,
            $workspaceNames['control'] => $sourceControl
        ];
    } elseif (!isset($targetDatabases['inventory'], $targetDatabases['control_panel_db'])) {
        throw new RuntimeException('The selected JSON file does not contain a complete system backup.');
    } else {
        $document = asset_backup_normalize_full_backup_names($document);
        $targetDatabases = $document['databases'];
    }
    $existing = array_fill_keys(asset_backup_databases($server, false), true);
    $requested = array_fill_keys($requestedDatabases, true);
    $restored = [];
    foreach ($targetDatabases as $databaseName => $databaseData) {
        if (!asset_backup_project_database((string)$databaseName) || isset($existing[$databaseName])
            || !isset($requested[$databaseName])) {
            continue;
        }
        if (!is_array($databaseData) || !is_array($databaseData['tables'] ?? null)) {
            throw new RuntimeException('The backup contains an invalid database entry.');
        }
        $charset = (string)($databaseData['charset'] ?? '');
        $collation = (string)($databaseData['collation'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $charset) ||
            !preg_match('/^[A-Za-z0-9_]+$/', $collation)) {
            throw new RuntimeException('The backup has invalid database character settings.');
        }

        $quotedDatabase = asset_backup_quote((string)$databaseName);
        $server->exec(
            'CREATE DATABASE ' . $quotedDatabase . ' CHARACTER SET ' . $charset . ' COLLATE ' . $collation
        );
        try {
            $database = new PDO(
                'mysql:host=' . $GLOBALS['DB_HOST'] . ';dbname=' . $databaseName . ';charset=utf8mb4',
                $GLOBALS['DB_USER'],
                $GLOBALS['DB_PASS'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
            $database->exec('SET FOREIGN_KEY_CHECKS=0');
            try {
                foreach ($databaseData['tables'] as $tableName => $tableData) {
                    if (!is_array($tableData) || !is_string($tableData['create_sql'] ?? null)
                        || !is_array($tableData['rows'] ?? null)) {
                        throw new RuntimeException('The backup contains an invalid table entry.');
                    }
                    $database->exec($tableData['create_sql']);
                    if (!$tableData['rows']) {
                        continue;
                    }
                    foreach ($tableData['rows'] as $row) {
                        if (!is_array($row) || !$row) {
                            continue;
                        }
                        foreach ($row as &$value) {
                            if (is_array($value) && array_key_exists('__asset_backup_binary_base64', $value)) {
                                $value = base64_decode((string)$value['__asset_backup_binary_base64'], true);
                                if ($value === false) {
                                    throw new RuntimeException('The backup contains invalid binary data.');
                                }
                            }
                        }
                        unset($value);
                        $columns = array_map('asset_backup_quote', array_keys($row));
                        $marks = array_fill(0, count($columns), '?');
                        $insert = $database->prepare(
                            'INSERT INTO ' . asset_backup_quote((string)$tableName)
                            . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $marks) . ')'
                        );
                        $insert->execute(array_values($row));
                    }
                }
                foreach (($databaseData['events'] ?? []) as $eventSql) {
                    if (!is_string($eventSql)) {
                        throw new RuntimeException('The backup contains an invalid scheduled event.');
                    }
                    $database->exec($eventSql);
                }
            } finally {
                $database->exec('SET FOREIGN_KEY_CHECKS=1');
            }
        } catch (Throwable $error) {
            try {
                $server->exec('DROP DATABASE ' . $quotedDatabase);
            } catch (Throwable $cleanupError) {
                error_log('Failed to remove an incomplete restored database ' . $databaseName . ': ' . $cleanupError->getMessage());
            }
            throw new RuntimeException(
                'Could not restore database ' . $databaseName . ' from the selected backup. Any partial copy was removed.',
                0,
                $error
            );
        }
        $existing[$databaseName] = true;
        $restored[] = (string)$databaseName;
    }
    return $restored;
}

function asset_backup_create_workspace(PDO $server, int $ownerId, string $password = ASSET_BACKUP_PASSPHRASE): array
{
    $directory = asset_backup_workspace_directory($ownerId);
    $path = $directory . DIRECTORY_SEPARATOR . 'workspace-backup.json';
    $lock = fopen($path . '.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) fclose($lock);
        throw new RuntimeException('Could not lock the workspace JSON backup for an update.');
    }
    try {
        $databaseNames = array_values(asset_backup_existing_workspace_database_names($server, $ownerId));
        $document = asset_backup_capture($server, $databaseNames);
        if (count($document['databases']) !== 2) {
            throw new RuntimeException('The workspace databases are unavailable; no backup was written.');
        }
        $document['workspace_owner_id'] = $ownerId;
        asset_backup_write_module_files($ownerId, $document);
        // Keep a readable, per-workspace JSON mirror alongside the encrypted
        // restore point. The API only serves this file to the workspace owner.
        $mirrorPath = $directory . DIRECTORY_SEPARATOR . 'secondary-database.json';
        $mirrorTemporary = $mirrorPath . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $mirrorJson = json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
        if (file_put_contents($mirrorTemporary, $mirrorJson, LOCK_EX) === false
            || !rename($mirrorTemporary, $mirrorPath)) {
            if (is_file($mirrorTemporary)) unlink($mirrorTemporary);
            throw new RuntimeException('Could not update the workspace secondary JSON database.');
        }
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $previous = $path . '.previous';
        if (!is_file($path) && is_file($previous)) {
            @rename($previous, $path);
        } elseif (is_file($path) && is_file($previous)) {
            @unlink($previous);
        }
        $encrypted = asset_backup_encrypt($document, $password);
        if (file_put_contents($temporary, $encrypted, LOCK_EX) === false) {
            if (is_file($temporary)) unlink($temporary);
            throw new RuntimeException('Could not update the workspace JSON backup.');
        }
        $hadBackup = is_file($path);
        if ($hadBackup && !rename($path, $previous)) {
            unlink($temporary);
            throw new RuntimeException('Could not safely replace the existing workspace JSON backup.');
        }
        if (!rename($temporary, $path)) {
            if ($hadBackup && is_file($previous)) @rename($previous, $path);
            if (is_file($temporary)) unlink($temporary);
            throw new RuntimeException('Could not finalize the workspace JSON backup.');
        }
        if (is_file($previous)) @unlink($previous);
        $failureMarker = $directory . DIRECTORY_SEPARATOR . 'workspace-backup-error.json';
        if (is_file($failureMarker)) @unlink($failureMarker);
        return ['filename' => basename($path), 'created_at' => $document['created_at'], 'size_bytes' => filesize($path) ?: 0];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function asset_backup_workspace_file(int $ownerId): string
{
    return asset_backup_workspace_directory($ownerId) . DIRECTORY_SEPARATOR . 'workspace-backup.json';
}

function asset_backup_restore_module(PDO $server, int $ownerId, string $moduleName): void
{
    if ($ownerId < 1 || !asset_backup_is_module_table($moduleName)) {
        throw new InvalidArgumentException('Choose a valid inventory module.');
    }
    $path = asset_backup_module_directory($ownerId) . DIRECTORY_SEPARATOR . $moduleName . '.json';
    $contents = is_file($path) ? file_get_contents($path) : false;
    $module = is_string($contents) ? json_decode($contents, true) : null;
    $databaseName = asset_backup_existing_workspace_database_names($server, $ownerId)['inventory'];
    $legacyDatabaseName = asset_backup_legacy_workspace_database_names($ownerId)['inventory'];
    $table = is_array($module) ? ($module['table'] ?? null) : null;
    if (!is_array($module) || ($module['format'] ?? '') !== 'assets-monitoring-module-json'
        || (int)($module['version'] ?? 0) !== 1
        || (int)($module['workspace_owner_id'] ?? 0) !== $ownerId
        || ($module['module'] ?? '') !== $moduleName
        || !in_array((string)($module['database_name'] ?? ''), [$databaseName, $legacyDatabaseName], true)
        || !is_array($table) || !is_string($table['create_sql'] ?? null)
        || !str_starts_with(strtoupper(ltrim($table['create_sql'])), 'CREATE TABLE')
        || !preg_match('/^CREATE TABLE\s+`' . preg_quote($moduleName, '/') . '`/i', ltrim($table['create_sql']))
        || str_contains($table['create_sql'], ';') || !is_array($table['rows'] ?? null)) {
        throw new RuntimeException('The selected module JSON file is invalid or belongs to another workspace.');
    }
    foreach ($table['rows'] as $row) {
        if (!is_array($row)) {
            throw new RuntimeException('The selected module JSON contains an invalid row.');
        }
        foreach ($row as $columnName => $value) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$columnName)) {
                throw new RuntimeException('The selected module JSON contains an invalid column name.');
            }
            if (is_array($value) && array_key_exists('__asset_backup_binary_base64', $value)) {
                if (base64_decode((string)$value['__asset_backup_binary_base64'], true) === false) {
                    throw new RuntimeException('The selected module JSON contains invalid binary data.');
                }
            } elseif (is_array($value)) {
                throw new RuntimeException('The selected module JSON contains an unsupported field value.');
            }
        }
    }
    $charset = (string)($module['charset'] ?? 'utf8mb4');
    $collation = (string)($module['collation'] ?? 'utf8mb4_unicode_ci');
    if (!preg_match('/^[A-Za-z0-9_]+$/', $charset) || !preg_match('/^[A-Za-z0-9_]+$/', $collation)) {
        throw new RuntimeException('The selected module JSON has invalid database settings.');
    }
    $server->exec('CREATE DATABASE IF NOT EXISTS ' . asset_backup_quote($databaseName)
        . ' CHARACTER SET ' . $charset . ' COLLATE ' . $collation);
    $database = new PDO(
        'mysql:host=' . $GLOBALS['DB_HOST'] . ';dbname=' . $databaseName . ';charset=utf8mb4',
        $GLOBALS['DB_USER'],
        $GLOBALS['DB_PASS'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false]
    );
    $database->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        $quotedTable = asset_backup_quote($moduleName);
        $database->exec('DROP TABLE IF EXISTS ' . $quotedTable);
        $database->exec($table['create_sql']);
        if ($table['rows']) {
            $firstRow = reset($table['rows']);
            if ($firstRow) {
                $columns = array_map('asset_backup_quote', array_keys($firstRow));
                $marks = array_fill(0, count($columns), '?');
                $insert = $database->prepare('INSERT INTO ' . $quotedTable . ' ('
                    . implode(', ', $columns) . ') VALUES (' . implode(', ', $marks) . ')');
                foreach ($table['rows'] as $row) {
                    if (array_keys($row) !== array_keys($firstRow)) {
                        throw new RuntimeException('The selected module JSON rows have inconsistent columns.');
                    }
                    $values = [];
                    foreach ($row as $value) {
                        if (is_array($value)) {
                            $value = base64_decode((string)$value['__asset_backup_binary_base64'], true);
                        }
                        $values[] = $value;
                    }
                    $insert->execute($values);
                }
            }
        }
    } finally {
        $database->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}

function asset_backup_restore_workspace(PDO $server, int $ownerId, string $password): void
{
    $path = asset_backup_workspace_file($ownerId);
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('No JSON backup exists for this workspace yet.');
    }
    $document = asset_backup_decrypt($contents, $password);
    if ((int)($document['workspace_owner_id'] ?? 0) !== $ownerId) {
        throw new InvalidArgumentException('This JSON backup belongs to a different workspace.');
    }
    asset_backup_restore_document($server, $document);
}

function asset_backup_failure_marker(?int $workspaceOwnerId = null): ?array
{
    $path = $workspaceOwnerId !== null && $workspaceOwnerId > 0
        ? asset_backup_workspace_directory($workspaceOwnerId) . DIRECTORY_SEPARATOR . 'workspace-backup-error.json'
        : asset_backup_directory() . DIRECTORY_SEPARATOR . 'assets-monitoring-backup-error.json';
    if (!is_file($path)) {
        return null;
    }
    $contents = file_get_contents($path);
    $marker = is_string($contents) ? json_decode($contents, true) : null;
    return is_array($marker) ? $marker : ['failed_at' => null];
}

function asset_backup_mark_failure(Throwable $error, ?int $workspaceOwnerId = null): void
{
    try {
        $directory = $workspaceOwnerId !== null && $workspaceOwnerId > 0
            ? asset_backup_workspace_directory($workspaceOwnerId)
            : asset_backup_directory();
    } catch (Throwable $markerError) {
        error_log('Automatic JSON backup failed and its failure marker could not be saved.');
        return;
    }
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        error_log('Automatic system JSON backup failed and its failure marker could not be saved.');
        return;
    }
    $path = $directory . DIRECTORY_SEPARATOR . ($workspaceOwnerId !== null && $workspaceOwnerId > 0
        ? 'workspace-backup-error.json'
        : 'assets-monitoring-backup-error.json');
    $marker = json_encode([
        'failed_at' => gmdate('c'),
        'message' => substr($error->getMessage(), 0, 300)
    ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($marker === false || file_put_contents($path, $marker, LOCK_EX) === false) {
        error_log('Automatic system JSON backup failed and its failure marker could not be saved.');
    }
}

function asset_backup_prune(string $directory, int $keep): void
{
    $files = glob($directory . DIRECTORY_SEPARATOR . 'assets-monitoring-*.json') ?: [];
    usort($files, static fn(string $left, string $right): int => filemtime($right) <=> filemtime($left));
    foreach (array_slice($files, $keep) as $file) {
        if (is_file($file) && !unlink($file)) {
            error_log('Could not remove an expired encrypted system backup: ' . basename($file));
        }
    }
}

function asset_backup_list(): array
{
    $files = glob(asset_backup_directory() . DIRECTORY_SEPARATOR . 'assets-monitoring-*.json') ?: [];
    usort($files, static fn(string $left, string $right): int => filemtime($right) <=> filemtime($left));
    return array_map(static function (string $file): array {
        return [
            'filename' => basename($file),
            'created_at' => gmdate('c', filemtime($file)),
            'size_bytes' => filesize($file) ?: 0
        ];
    }, $files);
}

function asset_backup_restore(PDO $server, string $filename, string $password): void
{
    if (!preg_match('/^assets-monitoring-[0-9]{8}-[0-9]{6}-[a-f0-9]{6}\.json$/', $filename)) {
        throw new InvalidArgumentException('Choose a valid backup file.');
    }
    $path = asset_backup_directory() . DIRECTORY_SEPARATOR . $filename;
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('The selected backup file could not be read.');
    }
    $document = asset_backup_decrypt($contents, $password);
    asset_backup_restore_document($server, $document);
}

function asset_backup_restore_document(PDO $server, array $document): void
{
    $workspaceOwnerId = (int)($document['workspace_owner_id'] ?? 0);
    if ($workspaceOwnerId < 1) {
        try {
            asset_backup_create($server);
        } catch (Throwable $error) {
            error_log('Pre-restore backup could not be created: ' . $error->getMessage());
            asset_backup_mark_failure($error);
        }
        $document = asset_backup_normalize_full_backup_names($document);
    }

    $current = asset_backup_databases($server, false);
    $targetNames = array_keys($document['databases']);
    if ($workspaceOwnerId > 0) {
        $expectedNames = asset_backup_workspace_database_names($server, $workspaceOwnerId);
        $legacyNames = asset_backup_legacy_workspace_database_names($workspaceOwnerId);
        $sourceInventory = $document['databases'][$expectedNames['inventory']]
            ?? $document['databases'][$legacyNames['inventory']]
            ?? null;
        $sourceControl = $document['databases'][$expectedNames['control']]
            ?? $document['databases'][$legacyNames['control']]
            ?? null;
        if (!is_array($sourceInventory) || !is_array($sourceControl) || count($targetNames) !== 2) {
            throw new RuntimeException('The workspace backup does not match the active workspace.');
        }
        $document['databases'] = [
            $expectedNames['inventory'] => $sourceInventory,
            $expectedNames['control'] => $sourceControl
        ];
        $targetNames = array_keys($document['databases']);
        $current = array_values(array_intersect(
            $current,
            array_merge(array_values($expectedNames), array_values($legacyNames))
        ));
    }
    $projectNames = array_values(array_filter($current, static fn(string $name): bool =>
        $name !== 'inventory' && $name !== 'control_panel_db'
    ));
    foreach (array_unique(array_merge($projectNames, array_filter($targetNames, static fn(string $name): bool =>
        $name !== 'inventory' && $name !== 'control_panel_db'
    ))) as $databaseName) {
        if (!in_array($databaseName, $targetNames, true)) {
            $server->exec('DROP DATABASE ' . asset_backup_quote($databaseName));
        }
    }

    foreach ($document['databases'] as $databaseName => $databaseData) {
        $charset = (string)($databaseData['charset'] ?? 'utf8mb4');
        $collation = (string)($databaseData['collation'] ?? 'utf8mb4_unicode_ci');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $charset) ||
            !preg_match('/^[A-Za-z0-9_]+$/', $collation)) {
            throw new RuntimeException('The backup has invalid database character settings.');
        }
        $quotedDatabase = asset_backup_quote((string)$databaseName);
        $server->exec('DROP DATABASE IF EXISTS ' . $quotedDatabase);
        $server->exec(
            'CREATE DATABASE ' . $quotedDatabase . ' CHARACTER SET ' . $charset . ' COLLATE ' . $collation
        );
        $database = new PDO(
            'mysql:host=' . $GLOBALS['DB_HOST'] . ';dbname=' . $databaseName . ';charset=utf8mb4',
            $GLOBALS['DB_USER'],
            $GLOBALS['DB_PASS'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );
        $database->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($databaseData['tables'] as $tableName => $tableData) {
                $database->exec($tableData['create_sql']);
                if (!$tableData['rows']) {
                    continue;
                }
                $firstRow = reset($tableData['rows']);
                $columns = array_map('asset_backup_quote', array_keys($firstRow));
                $marks = array_fill(0, count($columns), '?');
                $insert = $database->prepare(
                    'INSERT INTO ' . asset_backup_quote((string)$tableName) .
                    ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $marks) . ')'
                );
                foreach ($tableData['rows'] as $row) {
                    if (!$row) {
                        continue;
                    }
                    $values = [];
                    foreach ($row as $columnName => $value) {
                        if (is_array($value) && array_key_exists('__asset_backup_binary_base64', $value)) {
                            $value = base64_decode((string)$value['__asset_backup_binary_base64'], true);
                        }
                        $values[$columnName] = $value;
                    }
                    $insert->execute(array_values($values));
                }
            }
            foreach (($databaseData['events'] ?? []) as $eventSql) {
                $database->exec($eventSql);
            }
        } finally {
            $database->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    }
    if ($workspaceOwnerId > 0) {
        asset_backup_create_workspace($server, $workspaceOwnerId);
    } else {
        try {
            asset_backup_create($server);
        } catch (Throwable $error) {
            error_log('Restored databases, but could not create a fresh post-restore backup: ' . $error->getMessage());
            asset_backup_mark_failure($error);
        }
    }
}

function asset_backup_after_write(): void
{
    if (http_response_code() >= 400) {
        return;
    }
    $ownerId = (int)($_SESSION['workspace_owner_id'] ?? $_SESSION['uid'] ?? 0);
    if ($ownerId < 1) {
        return;
    }
    try {
        $server = asset_backup_server();
        asset_backup_create_workspace($server, $ownerId);
    } catch (Throwable $error) {
        error_log('Automatic workspace JSON backup failed: ' . $error->getMessage());
        asset_backup_mark_failure($error, $ownerId > 0 ? $ownerId : null);
    }
}
