<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../connector/auth_guard.php';
require_once __DIR__ . '/../../../connector/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

function table_editor_respond(array $body, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function table_editor_ident(string $name): string
{
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
        throw new InvalidArgumentException('The table or column name is invalid.');
    }
    return '`' . $name . '`';
}

function table_editor_ensure_settings(PDO $control): void
{
    $control->exec(
        'CREATE TABLE IF NOT EXISTS inventory_table_preferences ('
        . 'table_name VARCHAR(64) NOT NULL PRIMARY KEY,'
        . 'hidden_columns LONGTEXT NOT NULL,'
        . 'attachment_columns LONGTEXT NOT NULL,'
        . 'filter_columns LONGTEXT NOT NULL,'
        . 'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $filterColumnsExists = $control->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS "
        . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventory_table_preferences' AND COLUMN_NAME = 'filter_columns'"
    );
    if ((int)$filterColumnsExists->fetchColumn() === 0) {
        $control->exec(
            "ALTER TABLE inventory_table_preferences ADD COLUMN filter_columns LONGTEXT NOT NULL DEFAULT '[]' AFTER attachment_columns"
        );
    }
    $control->exec(
        'CREATE TABLE IF NOT EXISTS inventory_hidden_rows ('
        . 'table_name VARCHAR(64) NOT NULL,'
        . 'row_id VARCHAR(255) NOT NULL,'
        . 'created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
        . 'PRIMARY KEY (table_name, row_id)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function table_editor_table_columns(PDO $inventory, string $table): array
{
    return $inventory->query('SHOW FULL COLUMNS FROM ' . table_editor_ident($table))->fetchAll(PDO::FETCH_ASSOC);
}

function table_editor_archive_column(PDO $control, PDO $inventory, string $table, string $column, string $primaryKey, array $metadata): int
{
    $rowCount = (int)$inventory->query('SELECT COUNT(*) FROM ' . table_editor_ident($table))->fetchColumn();
    if ($rowCount > 50000) {
        throw new InvalidArgumentException('This column has more than 50,000 records and cannot be safely moved to the Recycle Bin.');
    }
    $type = (string)($metadata['Type'] ?? '');
    if (!preg_match(
        '/^(?:tinyint|smallint|mediumint|int|bigint|decimal|float|double|bit|char|varchar|tinytext|text|mediumtext|longtext|date|datetime|timestamp|time|year|json)(?:\([0-9]+(?:,[0-9]+)?\))?(?: unsigned)?(?: zerofill)?$/i',
        $type
    )) {
        throw new InvalidArgumentException('This column type cannot yet be safely backed up for Recycle Bin restore.');
    }
    $select = $inventory->query(
        'SELECT ' . table_editor_ident($primaryKey) . ', ' . table_editor_ident($column)
        . ' FROM ' . table_editor_ident($table)
    );
    $rows = [];
    $estimatedBytes = 0;
    while ($row = $select->fetch(PDO::FETCH_NUM)) {
        $key = $row[0];
        $value = $row[1];
        if (is_resource($key)) $key = stream_get_contents($key);
        if (is_resource($value)) $value = stream_get_contents($value);
        foreach (['key', 'value'] as $index => $field) {
            $candidate = $index === 0 ? $key : $value;
            if (is_string($candidate) && preg_match('//u', $candidate) !== 1) {
                $candidate = ['__asset_backup_binary_base64' => base64_encode($candidate)];
                if ($index === 0) $key = $candidate;
                else $value = $candidate;
            }
        }
        $rows[] = ['key' => $key, 'value' => $value];
        $estimatedBytes += (is_string($key) ? strlen($key) : 32) + (is_string($value) ? strlen($value) : 32);
        if ($estimatedBytes > 24 * 1024 * 1024) {
            throw new InvalidArgumentException('This column exceeds the safe Recycle Bin backup limit.');
        }
    }
    $columnNames = array_column(table_editor_table_columns($inventory, $table), 'Field');
    $position = array_search($column, $columnNames, true);
    $previousColumn = is_int($position) && $position > 0
        ? (string)$columnNames[$position - 1]
        : null;
    $payload = [
        'table_name' => $table,
        'column_name' => $column,
        'primary_key' => $primaryKey,
        'column_type' => $type,
        'nullable' => strtoupper((string)($metadata['Null'] ?? 'YES')) === 'YES',
        'default' => $metadata['Default'] ?? null,
        'collation' => (string)($metadata['Collation'] ?? ''),
        'extra' => (string)($metadata['Extra'] ?? ''),
        'comment' => (string)($metadata['Comment'] ?? ''),
        'previous_column' => $previousColumn,
        'rows' => $rows
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false || strlen($json) > 32 * 1024 * 1024) {
        throw new InvalidArgumentException('This column exceeds the safe Recycle Bin backup limit.');
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
    $pointer = json_encode(['_payload_file' => true, 'table_name' => $table, 'column_name' => $column]);
    $directory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.recycle-bin';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Private Recycle Bin storage is unavailable; the column was not changed.');
    }
    $control->beginTransaction();
    $temporaryFile = '';
    $backupFile = '';
    $backupFileCreated = false;
    $backupId = 0;
    try {
        $insert = $control->prepare(
            "INSERT INTO system_recycle_bin (item_type,source_table,item_name,payload,deleted_at,expires_at) "
            . "VALUES ('inventory_column',?,?,?,NOW(),DATE_ADD(NOW(), INTERVAL 30 DAY))"
        );
        $insert->execute([$table, $table . '.' . $column, $pointer]);
        $backupId = (int)$control->lastInsertId();
        if ($backupId < 1) throw new RuntimeException('The column backup could not be registered; it was not changed.');
        $temporaryFile = $directory . DIRECTORY_SEPARATOR . 'payload_' . $backupId . '.tmp';
        $backupFile = $directory . DIRECTORY_SEPARATOR . 'payload_' . $backupId . '.json';
        if (file_put_contents($temporaryFile, $json, LOCK_EX) !== strlen($json) || !rename($temporaryFile, $backupFile)) {
            throw new RuntimeException('The column backup could not be saved; it was not changed.');
        }
        $backupFileCreated = true;
        $control->commit();
    } catch (Throwable $error) {
        if ($control->inTransaction()) $control->rollBack();
        if ($temporaryFile !== '' && is_file($temporaryFile)) unlink($temporaryFile);
        if ($backupFileCreated && is_file($backupFile)) unlink($backupFile);
        throw $error;
    }
    return $backupId;
}

try {
    $table = trim((string)($_GET['table'] ?? $_POST['table'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $table)) {
        table_editor_respond(['success' => false, 'message' => 'Choose a valid inventory module.'], 400);
    }

    $inventory = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_INVENTORY};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $control = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_CONTROL};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $exists = $inventory->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $exists->execute([$table]);
    if ((int)$exists->fetchColumn() !== 1) {
        table_editor_respond(['success' => false, 'message' => 'That inventory module no longer exists. Refresh and try again.'], 404);
    }
    table_editor_ensure_settings($control);

    $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $canEdit = in_array((string)($_SESSION['workspace_role'] ?? ''), ['owner', 'editor'], true);
    if ($method === 'GET') {
        $preference = $control->prepare(
            'SELECT hidden_columns, attachment_columns, filter_columns FROM inventory_table_preferences WHERE table_name = ?'
        );
        $preference->execute([$table]);
        $saved = $preference->fetch() ?: [];
        $hiddenRows = $control->prepare('SELECT row_id FROM inventory_hidden_rows WHERE table_name = ?');
        $hiddenRows->execute([$table]);
        $primaryRows = $inventory->query('SHOW KEYS FROM ' . table_editor_ident($table) . " WHERE Key_name = 'PRIMARY'")
            ->fetchAll(PDO::FETCH_ASSOC);
        $primaryKey = count($primaryRows) === 1 ? (string)$primaryRows[0]['Column_name'] : '';
        table_editor_respond([
            'success' => true,
            'can_edit' => $canEdit,
            'primary_key' => $primaryKey,
            'columns' => table_editor_table_columns($inventory, $table),
            'hidden_columns' => json_decode((string)($saved['hidden_columns'] ?? '[]'), true) ?: [],
            'attachment_columns' => json_decode((string)($saved['attachment_columns'] ?? '[]'), true) ?: [],
            'filter_columns' => json_decode((string)($saved['filter_columns'] ?? '[]'), true) ?: [],
            'hidden_rows' => array_map('strval', $hiddenRows->fetchAll(PDO::FETCH_COLUMN))
        ]);
    }

    if ($method !== 'POST') {
        header('Allow: GET, POST');
        table_editor_respond(['success' => false, 'message' => 'Use GET or POST for table settings.'], 405);
    }
    if (!$canEdit) {
        table_editor_respond(['success' => false, 'message' => 'You do not have permission to edit this module.'], 403);
    }
    $token = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $sessionToken = (string)($_SESSION['csrf'] ?? '');
    if ($token === '' || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
        table_editor_respond(['success' => false, 'message' => 'Refresh the page and try again.'], 403);
    }

    $request = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($request)) {
        table_editor_respond(['success' => false, 'message' => 'The table editor request is invalid.'], 400);
    }
    $action = (string)($request['action'] ?? '');
    $columns = table_editor_table_columns($inventory, $table);
    $columnByName = [];
    foreach ($columns as $column) {
        $columnByName[(string)$column['Field']] = $column;
    }

    if ($action === 'save_preferences') {
        $hiddenColumns = $request['hidden_columns'] ?? null;
        $attachmentColumns = $request['attachment_columns'] ?? null;
        $filterColumns = $request['filter_columns'] ?? null;
        if (!is_array($hiddenColumns) || !is_array($attachmentColumns) || !is_array($filterColumns)) {
            table_editor_respond(['success' => false, 'message' => 'Column settings are invalid.'], 400);
        }
        $allowed = static function (array $names) use ($columnByName): array {
            $result = [];
            foreach ($names as $name) {
                if (!is_string($name) || !isset($columnByName[$name])) {
                    throw new InvalidArgumentException('A selected column no longer exists. Refresh and try again.');
                }
                if (!in_array($name, $result, true)) {
                    $result[] = $name;
                }
            }
            return $result;
        };
        $hiddenColumns = $allowed($hiddenColumns);
        $attachmentColumns = $allowed($attachmentColumns);
        $filterColumns = $allowed($filterColumns);
        foreach ($attachmentColumns as $name) {
            if (in_array(strtolower($name), ['id', 'asset_code', 'ref_no', 'refno', 'last_user'], true)) {
                table_editor_respond(['success' => false, 'message' => 'System-managed fields cannot have attachment controls.'], 400);
            }
        }
        $save = $control->prepare(
            'INSERT INTO inventory_table_preferences (table_name, hidden_columns, attachment_columns, filter_columns) VALUES (?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE hidden_columns = VALUES(hidden_columns), attachment_columns = VALUES(attachment_columns), '
            . 'filter_columns = VALUES(filter_columns)'
        );
        $save->execute([
            $table,
            json_encode($hiddenColumns, JSON_UNESCAPED_UNICODE),
            json_encode($attachmentColumns, JSON_UNESCAPED_UNICODE),
            json_encode($filterColumns, JSON_UNESCAPED_UNICODE)
        ]);
        table_editor_respond(['success' => true, 'message' => 'Table settings saved.']);
    }

    if ($action === 'toggle_row_visibility') {
        $rowId = trim((string)($request['row_id'] ?? ''));
        $hidden = ($request['hidden'] ?? false) === true;
        $primaryRows = $inventory->query('SHOW KEYS FROM ' . table_editor_ident($table) . " WHERE Key_name = 'PRIMARY'")
            ->fetchAll(PDO::FETCH_ASSOC);
        $primaryKey = count($primaryRows) === 1 ? (string)$primaryRows[0]['Column_name'] : '';
        if ($primaryKey === '' || $rowId === '' || strlen($rowId) > 255) {
            table_editor_respond(['success' => false, 'message' => 'This record cannot be hidden safely because its primary key is unavailable.'], 400);
        }
        $rowExists = $inventory->prepare(
            'SELECT 1 FROM ' . table_editor_ident($table) . ' WHERE ' . table_editor_ident($primaryKey) . ' = ? LIMIT 1'
        );
        $rowExists->execute([$rowId]);
        if (!$rowExists->fetchColumn()) {
            table_editor_respond(['success' => false, 'message' => 'That record no longer exists. Refresh and try again.'], 404);
        }
        if ($hidden) {
            $control->prepare('INSERT IGNORE INTO inventory_hidden_rows (table_name, row_id) VALUES (?, ?)')->execute([$table, $rowId]);
        } else {
            $control->prepare('DELETE FROM inventory_hidden_rows WHERE table_name = ? AND row_id = ?')->execute([$table, $rowId]);
        }
        table_editor_respond(['success' => true, 'message' => $hidden ? 'Record hidden from the table.' : 'Record is visible in the table again.']);
    }

    if ($action === 'delete_column') {
        $columnName = trim((string)($request['column'] ?? ''));
        if (!isset($columnByName[$columnName])) {
            table_editor_respond(['success' => false, 'message' => 'That column no longer exists. Refresh and try again.'], 404);
        }
        $column = $columnByName[$columnName];
        $key = strtolower($columnName);
        $primaryRows = $inventory->query('SHOW KEYS FROM ' . table_editor_ident($table) . " WHERE Column_name = " . $inventory->quote($columnName))
            ->fetchAll(PDO::FETCH_ASSOC);
        if ($primaryRows || stripos((string)($column['Extra'] ?? ''), 'auto_increment') !== false
            || preg_match('/\b(?:virtual|stored)\s+generated\b/i', (string)($column['Extra'] ?? '')) === 1
            || in_array($key, ['id', 'last_user'], true)) {
            table_editor_respond(['success' => false, 'message' => 'Primary-key and system-managed columns cannot be deleted.'], 400);
        }
        $primaryRows = $inventory->query('SHOW KEYS FROM ' . table_editor_ident($table) . " WHERE Key_name = 'PRIMARY'")
            ->fetchAll(PDO::FETCH_ASSOC);
        $primaryKey = count($primaryRows) === 1 ? (string)$primaryRows[0]['Column_name'] : '';
        if ($primaryKey === '') {
            table_editor_respond(['success' => false, 'message' => 'This table has no primary key, so its column data cannot be safely backed up.'], 400);
        }
        $backupId = table_editor_archive_column($control, $inventory, $table, $columnName, $primaryKey, $column);
        try {
            $inventory->exec('ALTER TABLE ' . table_editor_ident($table) . ' DROP COLUMN ' . table_editor_ident($columnName));
        } catch (Throwable $error) {
            if ($backupId > 0) {
                $control->prepare('DELETE FROM system_recycle_bin WHERE id = ?')->execute([$backupId]);
                $backupFile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.recycle-bin'
                    . DIRECTORY_SEPARATOR . 'payload_' . $backupId . '.json';
                if (is_file($backupFile)) unlink($backupFile);
            }
            throw $error;
        }
        $preference = $control->prepare('SELECT hidden_columns, attachment_columns, filter_columns FROM inventory_table_preferences WHERE table_name = ?');
        $preference->execute([$table]);
        $saved = $preference->fetch() ?: [];
        $hiddenColumns = array_values(array_filter(
            json_decode((string)($saved['hidden_columns'] ?? '[]'), true) ?: [],
            static fn($name): bool => $name !== $columnName
        ));
        $attachmentColumns = array_values(array_filter(
            json_decode((string)($saved['attachment_columns'] ?? '[]'), true) ?: [],
            static fn($name): bool => $name !== $columnName
        ));
        $filterColumns = array_values(array_filter(
            json_decode((string)($saved['filter_columns'] ?? '[]'), true) ?: [],
            static fn($name): bool => $name !== $columnName
        ));
        $control->prepare(
            'INSERT INTO inventory_table_preferences (table_name, hidden_columns, attachment_columns, filter_columns) VALUES (?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE hidden_columns = VALUES(hidden_columns), attachment_columns = VALUES(attachment_columns), '
            . 'filter_columns = VALUES(filter_columns)'
        )->execute([
            $table,
            json_encode($hiddenColumns, JSON_UNESCAPED_UNICODE),
            json_encode($attachmentColumns, JSON_UNESCAPED_UNICODE),
            json_encode($filterColumns, JSON_UNESCAPED_UNICODE)
        ]);
        $labelTable = $control->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $labelTable->execute(['inventory_column_labels']);
        if ((int)$labelTable->fetchColumn() > 0) {
            $control->prepare('DELETE FROM inventory_column_labels WHERE table_name = ? AND column_name = ?')
                ->execute([$table, $columnName]);
        }
        table_editor_respond(['success' => true, 'message' => 'Column moved to the Recycle Bin.']);
    }

    table_editor_respond(['success' => false, 'message' => 'Unknown table editor action.'], 400);
} catch (InvalidArgumentException $error) {
    table_editor_respond(['success' => false, 'message' => $error->getMessage()], 400);
} catch (Throwable $error) {
    error_log('Inventory table editor failed: ' . $error->getMessage());
    table_editor_respond(['success' => false, 'message' => 'The table change could not be saved. Please try again.'], 500);
}
