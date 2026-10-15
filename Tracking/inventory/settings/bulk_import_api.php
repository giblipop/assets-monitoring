<?php
declare(strict_types=1);

require_once __DIR__ . '/../../connector/auth_guard.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function bulk_import_respond(array $body, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function bulk_import_identifier(string $name): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new InvalidArgumentException('A table or column name contains unsupported characters.');
    }
    return '`' . str_replace('`', '``', $name) . '`';
}

function bulk_import_columns(PDO $inventory, string $table): array
{
    $statement = $inventory->query('SHOW COLUMNS FROM ' . bulk_import_identifier($table));
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function bulk_import_table_exists(PDO $inventory, string $table): bool
{
    $statement = $inventory->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $statement->execute([$table]);
    return (int)$statement->fetchColumn() > 0;
}

function bulk_import_archive_table(PDO $control, PDO $inventory, string $table): void
{
    $createRow = $inventory->query(
        'SHOW CREATE TABLE ' . bulk_import_identifier($table)
    )->fetch(PDO::FETCH_NUM);
    if (!is_array($createRow) || empty($createRow[1])) {
        throw new RuntimeException('The existing table schema could not be backed up; it was not changed.');
    }
    $columns = bulk_import_columns($inventory, $table);
    if (count($columns) > 250) {
        throw new RuntimeException('The existing table is too large to back up safely; it was not changed.');
    }
    $rowCount = (int)$inventory->query(
        'SELECT COUNT(*) FROM ' . bulk_import_identifier($table)
    )->fetchColumn();
    if ($rowCount > 50000) {
        throw new RuntimeException('The existing table has more than 50,000 rows and cannot be safely overwritten; it was not changed.');
    }
    $sizeExpressions = array_map(
        static fn(array $column): string => 'COALESCE(OCTET_LENGTH('
            . bulk_import_identifier((string)$column['Field']) . '),0)',
        $columns
    );
    $estimatedBytes = $sizeExpressions
        ? (int)$inventory->query(
            'SELECT COALESCE(SUM(' . implode(' + ', $sizeExpressions) . '),0) FROM '
            . bulk_import_identifier($table)
        )->fetchColumn()
        : 0;
    if ($estimatedBytes > 24 * 1024 * 1024) {
        throw new RuntimeException('The existing table is larger than the safe Recycle Bin backup limit; it was not changed.');
    }
    $rows = $inventory->query(
        'SELECT * FROM ' . bulk_import_identifier($table)
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        foreach ($row as &$value) {
            if (is_resource($value)) {
                $value = stream_get_contents($value);
            }
            if (is_string($value) && preg_match('//u', $value) !== 1) {
                $value = ['__asset_backup_binary_base64' => base64_encode($value)];
            }
        }
        unset($value);
    }
    unset($row);
    $navigation = $control->prepare('SELECT * FROM navigation_menu WHERE table_name = ?');
    $navigation->execute([$table]);
    $payload = [
        'table_name' => $table,
        'create_sql' => (string)$createRow[1],
        'rows' => $rows,
        'navigation_entries' => $navigation->fetchAll(PDO::FETCH_ASSOC)
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false || strlen($json) > 32 * 1024 * 1024) {
        throw new RuntimeException('The existing table backup exceeds the 32 MB safe recycle-bin limit; it was not changed.');
    }
    $control->exec(
        "CREATE TABLE IF NOT EXISTS system_recycle_bin ("
        . "id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,"
        . "item_type VARCHAR(32) NOT NULL,source_table VARCHAR(255) NULL,"
        . "item_name VARCHAR(255) NOT NULL DEFAULT '',payload LONGTEXT NOT NULL,"
        . "deleted_at DATETIME NOT NULL,expires_at DATETIME NOT NULL,"
        . "INDEX idx_recycle_expires(expires_at),INDEX idx_recycle_type(item_type)"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pointer = json_encode(['_payload_file' => true, 'table_name' => $table], JSON_UNESCAPED_UNICODE);
    if ($pointer === false) {
        throw new RuntimeException('The existing table backup reference could not be created; it was not changed.');
    }
    $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.recycle-bin';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Private recycle-bin storage is unavailable; the existing table was not changed.');
    }
    $insert = $control->prepare(
        "INSERT INTO system_recycle_bin "
        . "(item_type,source_table,item_name,payload,deleted_at,expires_at) "
        . "VALUES ('inventory_table',?,?,?,NOW(),DATE_ADD(NOW(), INTERVAL 30 DAY))"
    );
    $temporaryFile = '';
    $backupFile = '';
    $backupFileCreated = false;
    $control->beginTransaction();
    try {
        $insert->execute([$table, $table, $pointer]);
        $backupId = (int)$control->lastInsertId();
        if ($backupId < 1) {
            throw new RuntimeException('The existing table backup could not be registered; it was not changed.');
        }
        $temporaryFile = $directory . DIRECTORY_SEPARATOR . 'payload_' . $backupId . '.tmp';
        $backupFile = $directory . DIRECTORY_SEPARATOR . 'payload_' . $backupId . '.json';
        if (file_put_contents($temporaryFile, $json, LOCK_EX) !== strlen($json) || !rename($temporaryFile, $backupFile)) {
            throw new RuntimeException('The existing table backup could not be saved; the existing table was not changed.');
        }
        $backupFileCreated = true;
        $control->commit();
    } catch (Throwable $error) {
        if ($control->inTransaction()) {
            $control->rollBack();
        }
        if ($temporaryFile !== '' && is_file($temporaryFile)) {
            unlink($temporaryFile);
        }
        if ($backupFileCreated && is_file($backupFile)) {
            unlink($backupFile);
        }
        throw $error;
    }
}

function bulk_import_ensure_sidebar_name_column(PDO $control): void
{
    $columns = [
        'display_name' => 'VARCHAR(255) NULL AFTER table_name',
        'workbook_id' => 'VARCHAR(64) NULL',
        'sheet_name' => 'VARCHAR(255) NULL',
        'sheet_order' => 'INT NULL'
    ];
    foreach ($columns as $name => $definition) {
        $statement = $control->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $statement->execute(['navigation_menu', $name]);
        if ((int)$statement->fetchColumn() === 0) {
            $control->exec('ALTER TABLE navigation_menu ADD COLUMN `' . $name . '` ' . $definition);
        }
    }
}

function bulk_import_module_list(PDO $inventory, PDO $control): array
{
    $navigationColumns = array_fill_keys(
        $control->query('SHOW COLUMNS FROM navigation_menu')->fetchAll(PDO::FETCH_COLUMN),
        true
    );
    $displayNameSelect = isset($navigationColumns['display_name']) ? 'display_name' : 'NULL AS display_name';
    $workbookSelect = isset($navigationColumns['workbook_id']) ? 'workbook_id' : 'NULL AS workbook_id';
    $tables = [];
    foreach ($inventory->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $tables[strtolower((string)$table)] = (string)$table;
    }
    $navigation = $control->query(
        'SELECT unique_id, table_name, ' . $displayNameSelect . ', ' . $workbookSelect
        . ' FROM navigation_menu WHERE is_visible = 1 ORDER BY sort_order, table_name'
    )->fetchAll(PDO::FETCH_ASSOC);
    $modules = [];
    foreach ($navigation as $entry) {
        $table = (string)$entry['table_name'];
        $lower = strtolower($table);
        if (!isset($tables[$lower]) || in_array($lower, [
            'users', 'loginhistory', 'activitylogs', 'remembereddevices', 'accountlockouts',
            'passwordresetrequests', 'accountrecoveryattempts', 'workspacemembers',
            'workspaceinvitations', 'workspacepreferences', 'workspaceinvitationattempts',
            'adminrecyclebin', 'adminauditlog', 'system_recycle_bin', 'navigation_menu',
            'navigation_trash'
        ], true)) {
            continue;
        }
        $columns = bulk_import_columns($inventory, $tables[$lower]);
        $modules[] = [
            'table' => $tables[$lower],
            'label' => trim((string)($entry['display_name'] ?? '')) ?: ucwords(str_replace('_', ' ', $tables[$lower])),
            'columns' => array_values(array_map(static fn(array $column): array => [
                'name' => (string)$column['Field'],
                'type' => (string)$column['Type'],
                'auto_increment' => stripos((string)($column['Extra'] ?? ''), 'auto_increment') !== false,
                'generated' => preg_match('/\b(?:virtual|stored)\s+generated\b/i', (string)($column['Extra'] ?? '')) === 1
            ], $columns)),
        ];
    }
    return $modules;
}

try {
    global $DB_HOST, $DB_USER, $DB_PASS, $DB_INVENTORY, $DB_CONTROL;
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ];
    $inventory = new PDO("mysql:host={$DB_HOST};dbname={$DB_INVENTORY};charset=utf8mb4", $DB_USER, $DB_PASS, $options);
    $control = new PDO("mysql:host={$DB_HOST};dbname={$DB_CONTROL};charset=utf8mb4", $DB_USER, $DB_PASS, $options);
    $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'GET') {
        bulk_import_respond([
            'success' => true,
            'can_create_module' => (string)($_SESSION['workspace_role'] ?? '') === 'owner',
            'modules' => bulk_import_module_list($inventory, $control)
        ]);
    }
    if ($method !== 'POST') {
        header('Allow: GET, POST');
        bulk_import_respond(['success' => false, 'message' => 'Use GET or POST for module imports.'], 405);
    }

    $csrf = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($csrf === '' || !hash_equals((string)($_SESSION['csrf'] ?? ''), $csrf)) {
        bulk_import_respond(['success' => false, 'message' => 'Your session expired. Refresh the page and try again.'], 403);
    }
    if (!in_array((string)($_SESSION['workspace_role'] ?? ''), ['owner', 'editor'], true)) {
        bulk_import_respond(['success' => false, 'message' => 'You do not have permission to import inventory modules.'], 403);
    }
    $maxRequestBytes = 12 * 1024 * 1024;
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxRequestBytes) {
        bulk_import_respond(['success' => false, 'message' => 'This import batch exceeds the 12 MB server request limit. Select fewer sheets or reduce the batch size.'], 413);
    }
    $requestBody = file_get_contents('php://input', false, null, 0, $maxRequestBytes + 1);
    if ($requestBody === false || strlen($requestBody) > $maxRequestBytes) {
        bulk_import_respond(['success' => false, 'message' => 'This import batch exceeds the 12 MB server request limit. Select fewer sheets or reduce the batch size.'], 413);
    }
    $request = json_decode($requestBody, true);
    if (!is_array($request) || !is_array($request['modules'] ?? null) || !$request['modules']) {
        bulk_import_respond(['success' => false, 'message' => 'No module worksheets were selected.'], 400);
    }
    if (count($request['modules']) > 50) {
        bulk_import_respond(['success' => false, 'message' => 'Import up to 50 worksheets at a time.'], 400);
    }
    $groupName = trim((string)($request['group_name'] ?? ''));
    if ($groupName === '' || mb_strlen($groupName, 'UTF-8') > 100) {
        bulk_import_respond(['success' => false, 'message' => 'Choose one sidebar name for this workbook (up to 100 characters).'], 400);
    }
    $workbookId = strtolower(trim((string)($request['workbook_id'] ?? '')));
    if (!preg_match('/^[a-f0-9]{32}$/', $workbookId)) {
        bulk_import_respond(['success' => false, 'message' => 'The workbook import session is invalid. Select the workbook again and retry.'], 400);
    }
    if ((string)($_SESSION['workspace_role'] ?? '') !== 'owner') {
        bulk_import_respond(['success' => false, 'message' => 'Importing a workbook as sheet tabs and creating its tables requires workspace owner access.'], 403);
    }
    set_time_limit(300);
    bulk_import_ensure_sidebar_name_column($control);

    $availableModules = bulk_import_module_list($inventory, $control);
    $byTable = [];
    foreach ($availableModules as $module) {
        $byTable[strtolower($module['table'])] = $module;
    }
    $protectedNames = [
        'users', 'loginhistory', 'activitylogs', 'remembereddevices', 'accountlockouts',
        'passwordresetrequests', 'accountrecoveryattempts', 'workspacemembers',
        'workspaceinvitations', 'workspacepreferences', 'workspaceinvitationattempts',
        'adminrecyclebin', 'adminauditlog', 'system_recycle_bin', 'navigation_trash',
        'navigation_menu'
    ];
    $seenModules = [];
    $results = [];
    $totalRows = 0;
    $visibleWorkbook = $control->prepare('SELECT COUNT(*) FROM navigation_menu WHERE workbook_id = ? AND is_visible = 1');
    $visibleWorkbook->execute([$workbookId]);
    $hasVisibleWorkbookTab = (int)$visibleWorkbook->fetchColumn() > 0;

    foreach ($request['modules'] as $index => $item) {
        if (!is_array($item)) {
            $results[] = ['worksheet' => 'Worksheet ' . ($index + 1), 'success' => false, 'message' => 'Invalid worksheet mapping.'];
            continue;
        }
        $label = trim((string)($item['label'] ?? $item['worksheet'] ?? ''));
        $sidebarName = $groupName;
        $table = strtolower(trim((string)($item['table'] ?? '')));
        $mode = (string)($item['mode'] ?? '');
        $headers = $item['headers'] ?? null;
        $rows = $item['rows'] ?? null;
        if ($label === '' || mb_strlen($label, 'UTF-8') > 100 || $sidebarName === ''
            || mb_strlen($sidebarName, 'UTF-8') > 100 || !preg_match('/^[a-z][a-z0-9_]{0,63}$/', $table)
            || !in_array($mode, ['existing', 'new', 'append', 'overwrite'], true) || !is_array($headers) || !is_array($rows)
            || !$headers || count($headers) > 250 || count($rows) > 50000) {
            $results[] = ['worksheet' => $label ?: 'Worksheet ' . ($index + 1), 'success' => false, 'message' => 'Worksheet mapping or data limits are invalid.'];
            continue;
        }
        $key = strtolower($table);
        if (isset($seenModules[$key])) {
            $results[] = ['worksheet' => $label, 'success' => false, 'message' => 'Each worksheet must target a different module.'];
            continue;
        }
        $seenModules[$key] = true;

        $newTable = false;
        $navigationId = null;
        $createdNavigation = false;
        $previousNavigation = null;
        try {
            $targetColumns = [];
            if ($mode === 'existing') {
                if (in_array($key, $protectedNames, true) || !bulk_import_table_exists($inventory, $table)) {
                    throw new InvalidArgumentException('Choose an existing inventory table that can be imported into.');
                }
                foreach (bulk_import_columns($inventory, $table) as $column) {
                    if (stripos((string)($column['Extra'] ?? ''), 'auto_increment') === false
                        && preg_match('/\b(?:virtual|stored)\s+generated\b/i', (string)($column['Extra'] ?? '')) !== 1) {
                        $targetColumns[(string)$column['Field']] = [
                            'auto_increment' => false,
                            'generated' => false
                        ];
                    }
                }
            } elseif ($mode === 'append') {
                $groupTable = $control->prepare(
                    'SELECT 1 FROM navigation_menu WHERE workbook_id = ? AND table_name = ? LIMIT 1'
                );
                $groupTable->execute([$workbookId, $table]);
                if (!$groupTable->fetchColumn() || !bulk_import_table_exists($inventory, $table)) {
                    throw new InvalidArgumentException('The worksheet table for this workbook import could not be found.');
                }
                foreach (bulk_import_columns($inventory, $table) as $column) {
                    if (stripos((string)($column['Extra'] ?? ''), 'auto_increment') === false
                        && preg_match('/\b(?:virtual|stored)\s+generated\b/i', (string)($column['Extra'] ?? '')) !== 1) {
                        $targetColumns[(string)$column['Field']] = [
                            'auto_increment' => false,
                            'generated' => false
                        ];
                    }
                }
            } else {
                if (($_SESSION['workspace_role'] ?? '') !== 'owner') {
                    throw new InvalidArgumentException('Only the workspace owner can create new modules. Editors can import into existing modules.');
                }
                if (in_array($key, $protectedNames, true)) {
                    throw new InvalidArgumentException('That module name is protected. Choose a different table name.');
                }
                if ($mode === 'new' && bulk_import_table_exists($inventory, $table)) {
                    throw new InvalidArgumentException('That module name already exists.', 0, null);
                }
                if ($mode === 'overwrite' && !bulk_import_table_exists($inventory, $table)) {
                    throw new InvalidArgumentException('The table to overwrite no longer exists. Refresh the workbook and retry.');
                }
            }

            $mapping = [];
            $sourceIndexes = [];
            $columnsToAdd = [];
            $mappedSources = [];
            $mappedTargets = [];
            foreach ($headers as $headerIndex => $header) {
                if (!is_array($header)) {
                    throw new InvalidArgumentException('A worksheet column mapping is invalid.');
                }
                $source = trim((string)($header['source'] ?? ''));
                $target = trim((string)($header['target'] ?? ''));
                if ($source === '' || isset($mappedSources[$source])) {
                    throw new InvalidArgumentException('Worksheet headings must be non-empty and unique.');
                }
                $mappedSources[$source] = true;
                $sourceIndex = filter_var($header['source_index'] ?? $headerIndex, FILTER_VALIDATE_INT);
                if ($sourceIndex === false || $sourceIndex < 0 || $sourceIndex >= 250) {
                    throw new InvalidArgumentException('A worksheet column position is invalid.');
                }
                if ($target === '') {
                    continue;
                }
                if ($mode === 'existing') {
                    foreach ($targetColumns as $existingName => $_column) {
                        if (strtolower($existingName) === strtolower($target)) {
                            $target = $existingName;
                            break;
                        }
                    }
                }
                if ($mode === 'new' || $mode === 'overwrite') {
                    if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $target)
                        || in_array(strtolower($target), ['id', 'created_at'], true)
                        || isset($targetColumns[$target])) {
                        throw new InvalidArgumentException('The new module has an invalid or duplicate column name.');
                    }
                    $targetColumns[$target] = ['auto_increment' => false, 'generated' => false];
                } elseif ($mode === 'existing' && !isset($targetColumns[$target])) {
                    if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $target)
                        || in_array(strtolower($target), ['id', 'created_at', 'last_user'], true)) {
                        throw new InvalidArgumentException('A new column for the existing table has an invalid name.');
                    }
                    $targetColumns[$target] = ['auto_increment' => false, 'generated' => false];
                    $columnsToAdd[] = $target;
                }
                if (!isset($targetColumns[$target]) || $targetColumns[$target]['auto_increment'] || $targetColumns[$target]['generated']) {
                    throw new InvalidArgumentException('Every worksheet column must map to a valid module column.');
                }
                if (in_array(strtolower($target), ['id', 'last_user'], true)) {
                    throw new InvalidArgumentException('System-managed columns cannot be imported.');
                }
                if (isset($mappedTargets[strtolower($target)])) {
                    throw new InvalidArgumentException('Each worksheet column must map to a different module column.');
                }
                $mappedTargets[strtolower($target)] = true;
                $mapping[$source] = $target;
                $sourceIndexes[$source] = $sourceIndex;
            }
            if (!$mapping) {
                throw new InvalidArgumentException('No worksheet columns were mapped.');
            }
            if (count($rows) > 50000 || $totalRows + count($rows) > 100000) {
                throw new InvalidArgumentException('Import up to 50,000 rows per worksheet and 100,000 rows per workbook.');
            }
            $totalRows += count($rows);

            if ($mode === 'overwrite') {
                bulk_import_archive_table($control, $inventory, $table);
                $inventory->exec('DROP TABLE ' . bulk_import_identifier($table));
                $control->prepare('DELETE FROM navigation_menu WHERE table_name = ?')->execute([$table]);
                $mode = 'new';
            }
            if ($mode === 'new') {
                $definitions = array_map(
                    static fn(string $column): string => bulk_import_identifier($column) . ' TEXT NULL',
                    array_values($mapping)
                );
                $createSql = 'CREATE TABLE ' . bulk_import_identifier($table)
                    . ' (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
                    . implode(', ', $definitions)
                    . ', `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
                $inventory->exec($createSql);
                $newTable = true;
                $sortOrder = (int)$control->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM navigation_menu')->fetchColumn();
                $navigationId = uniqid('nm_', true);
                $createdNavigation = true;
                $isFirstTab = !$hasVisibleWorkbookTab;
                $insertNavigation = $control->prepare(
                    'INSERT INTO navigation_menu '
                    . '(unique_id,table_name,display_name,workbook_id,sheet_name,sheet_order,icon,sort_order,is_visible) '
                    . 'VALUES (?,?,?,?,?,?,?,?,?)'
                );
                $insertNavigation->execute([
                    $navigationId,
                    $table,
                    $isFirstTab ? $sidebarName : null,
                    $workbookId,
                    $label,
                    (int)($item['sheet_order'] ?? $index),
                    'grid',
                    $sortOrder,
                    $isFirstTab ? 1 : 0
                ]);
            } elseif ($mode === 'existing') {
                foreach ($columnsToAdd as $column) {
                    $inventory->exec(
                        'ALTER TABLE ' . bulk_import_identifier($table) . ' ADD COLUMN '
                        . bulk_import_identifier($column) . ' TEXT NULL'
                    );
                }
                $existingNavigation = $control->prepare(
                    'SELECT unique_id FROM navigation_menu WHERE table_name = ? ORDER BY is_visible DESC, sort_order LIMIT 1'
                );
                $existingNavigation->execute([$table]);
                $navigationId = $existingNavigation->fetchColumn() ?: null;
                if ($navigationId !== null) {
                    $previousNavigation = $control->prepare(
                        'SELECT display_name, workbook_id, sheet_name, sheet_order, is_visible '
                        . 'FROM navigation_menu WHERE unique_id = ?'
                    );
                    $previousNavigation->execute([$navigationId]);
                    $previousNavigation = $previousNavigation->fetch(PDO::FETCH_ASSOC);
                    $control->prepare(
                        'UPDATE navigation_menu SET display_name = ?, workbook_id = ?, sheet_name = ?, '
                        . 'sheet_order = ?, is_visible = ? WHERE unique_id = ?'
                    )->execute([
                        $isFirstTab ? $sidebarName : null,
                        $workbookId,
                        $label,
                        (int)($item['sheet_order'] ?? $index),
                        $isFirstTab ? 1 : 0,
                        $navigationId
                    ]);
                } else {
                    $sortOrder = (int)$control->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM navigation_menu')->fetchColumn();
                    $navigationId = uniqid('nm_', true);
                    $createdNavigation = true;
                    $control->prepare(
                        'INSERT INTO navigation_menu '
                        . '(unique_id,table_name,display_name,workbook_id,sheet_name,sheet_order,icon,sort_order,is_visible) '
                        . 'VALUES (?,?,?,?,?,?,?,?,?)'
                    )->execute([
                        $navigationId,
                        $table,
                        $isFirstTab ? $sidebarName : null,
                        $workbookId,
                        $label,
                        (int)($item['sheet_order'] ?? $index),
                        'grid',
                        $sortOrder,
                        $isFirstTab ? 1 : 0
                    ]);
                }
            }

            $inventory->beginTransaction();
            $insertColumns = array_values($mapping);
            $inserted = 0;
            $columnCount = count($insertColumns);
            $maxAllowedPacket = (int)$inventory->query('SELECT @@max_allowed_packet')->fetchColumn();
            $safeBatchBytes = min(4 * 1024 * 1024, max(1024, intdiv($maxAllowedPacket * 3, 4)));
            $insertPrefix = 'INSERT INTO ' . bulk_import_identifier($table) . ' ('
                . implode(', ', array_map('bulk_import_identifier', $insertColumns)) . ') VALUES ';
            $tupleTemplate = '(' . implode(', ', array_fill(0, $columnCount, '?')) . ')';
            $tuples = [];
            $batchValues = [];
            $batchBytes = strlen($insertPrefix);
            $flushInsertBatch = static function () use (
                $inventory,
                $insertPrefix,
                &$tuples,
                &$batchValues,
                &$batchBytes
            ): void {
                if (!$tuples) {
                    return;
                }
                $statement = $inventory->prepare($insertPrefix . implode(', ', $tuples));
                $statement->execute($batchValues);
                $tuples = [];
                $batchValues = [];
                $batchBytes = strlen($insertPrefix);
            };
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    throw new InvalidArgumentException('An imported data row is invalid.');
                }
                $values = [];
                $rowBytes = strlen($tupleTemplate) + 2;
                foreach ($mapping as $source => $_target) {
                    $value = $row[$source] ?? $row[$sourceIndexes[$source]] ?? null;
                    if ($value !== null && !is_scalar($value)) {
                        throw new InvalidArgumentException('Cell data must be plain text or numbers.');
                    }
                    $value = $value === null ? null : (string)$value;
                    if ($value !== null && strlen($value) > 65000) {
                        throw new InvalidArgumentException('A cell exceeds the database text-column limit of 65,000 bytes.');
                    }
                    $value = $value === '' ? null : $value;
                    $values[] = $value;
                    $rowBytes += $value === null ? 8 : strlen($value) + 8;
                }
                if (count(array_filter($values, static fn($value): bool => $value !== null)) === 0) {
                    continue;
                }
                if ($rowBytes + strlen($insertPrefix) > $safeBatchBytes) {
                    throw new InvalidArgumentException(
                        'A row in this worksheet is too large for the database import limit. Reduce its cell content or imported columns and retry.'
                    );
                }
                $separatorBytes = $tuples ? 2 : 0;
                if ($tuples && $batchBytes + $separatorBytes + $rowBytes > $safeBatchBytes) {
                    $flushInsertBatch();
                    $separatorBytes = 0;
                }
                $tuples[] = $tupleTemplate;
                array_push($batchValues, ...$values);
                $batchBytes += $separatorBytes + $rowBytes;
                $inserted++;
            }
            $flushInsertBatch();
            $inventory->commit();
            $hasVisibleWorkbookTab = true;
            $results[] = [
                'worksheet' => $label,
                'table' => $table,
                'sidebar_name' => $sidebarName,
                'workbook_id' => $workbookId,
                'success' => true,
                'created_module' => $newTable,
                'inserted' => $inserted
            ];
        } catch (Throwable $error) {
            if ($inventory->inTransaction()) {
                $inventory->rollBack();
            }
            if ($newTable) {
                try {
                    $inventory->exec('DROP TABLE IF EXISTS ' . bulk_import_identifier($table));
                } catch (Throwable $cleanupError) {
                    error_log('Could not remove a failed bulk-import table: ' . $cleanupError->getMessage());
                }
            }
            if ($createdNavigation && $navigationId !== null) {
                try {
                    $control->prepare('DELETE FROM navigation_menu WHERE unique_id = ?')->execute([$navigationId]);
                } catch (Throwable $cleanupError) {
                    error_log('Could not remove navigation for a failed bulk-import module: ' . $cleanupError->getMessage());
                }
            } elseif ($previousNavigation && $navigationId !== null) {
                try {
                    $control->prepare(
                        'UPDATE navigation_menu SET display_name = ?, workbook_id = ?, sheet_name = ?, '
                        . 'sheet_order = ?, is_visible = ? WHERE unique_id = ?'
                    )->execute([
                        $previousNavigation['display_name'],
                        $previousNavigation['workbook_id'],
                        $previousNavigation['sheet_name'],
                        $previousNavigation['sheet_order'],
                        $previousNavigation['is_visible'],
                        $navigationId
                    ]);
                } catch (Throwable $cleanupError) {
                    error_log('Could not restore navigation after a failed bulk-import module: ' . $cleanupError->getMessage());
                }
            }
            $userMessage = $error instanceof InvalidArgumentException
                ? $error->getMessage()
                : 'A server/database error occurred while importing this worksheet. Please check the workspace and try again.';
            if (!($error instanceof InvalidArgumentException)) {
                error_log('Worksheet import failed: ' . $error->getMessage());
            }
            $results[] = [
                'worksheet' => $label,
                'table' => $table,
                'success' => false,
                'code' => $mode === 'new' && !in_array($key, $protectedNames, true)
                    && bulk_import_table_exists($inventory, $table) ? 'table_exists' : null,
                'message' => $userMessage
            ];
        }
    }

    $successes = array_values(array_filter($results, static fn(array $result): bool => $result['success']));
    $failures = array_values(array_filter($results, static fn(array $result): bool => !$result['success']));
    bulk_import_respond([
        'success' => count($successes) > 0 && count($failures) === 0,
        'message' => count($successes) . ' worksheet table(s) imported; ' . count($failures) . ' failed.',
        'modules' => $results
    ], count($successes) > 0 ? 200 : 400);
} catch (Throwable $error) {
    error_log('Bulk module import failed: ' . $error->getMessage());
    bulk_import_respond(['success' => false, 'message' => 'The import could not be completed because of a server or database error. Please try again or contact the workspace owner.'], 500);
}
