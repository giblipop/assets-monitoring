<?php
require_once __DIR__ . '/../../../connector/auth_guard.php';
require_once __DIR__ . '/../../../connector/activity_logger.php';
require_once __DIR__ . '/../../../connector/undo_support.php';
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../connector/config.php';

function delete_out(array $body, int $status = 200): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function delete_ident(string $name): string {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) throw new RuntimeException('Invalid table or column name.');
    return '`' . $name . '`';
}
function delete_recycle_dir(): string {
    $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.recycle-bin';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Could not create the workspace recycle-bin folder.');
    return $dir;
}

try {
    $control = new PDO("mysql:host={$DB_HOST};dbname={$DB_CONTROL};charset=utf8mb4", $DB_USER, $DB_PASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $inventory = new PDO("mysql:host={$DB_HOST};dbname={$DB_INVENTORY};charset=utf8mb4", $DB_USER, $DB_PASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $control->exec("CREATE TABLE IF NOT EXISTS system_recycle_bin (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,item_type VARCHAR(32) NOT NULL,source_table VARCHAR(255) NULL,item_name VARCHAR(255) NOT NULL DEFAULT '',payload LONGTEXT NOT NULL,deleted_at DATETIME NOT NULL,expires_at DATETIME NOT NULL,INDEX idx_recycle_expires(expires_at),INDEX idx_recycle_type(item_type)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $control->exec(
        'CREATE TABLE IF NOT EXISTS inventory_hidden_rows ('
        . 'table_name VARCHAR(64) NOT NULL,'
        . 'row_id VARCHAR(255) NOT NULL,'
        . 'created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
        . 'PRIMARY KEY (table_name, row_id)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') delete_out(['status'=>'error','message'=>'Method not allowed.'], 405);
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $data['action'] ?? '';
    $table = (string)($data['tableContext'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) delete_out(['status'=>'error','message'=>'Invalid inventory table.'], 400);
    $quotedTable = delete_ident($table);
    $exists = $inventory->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t');
    $exists->execute([':t'=>$table]);
    if (!$exists->fetchColumn()) delete_out(['status'=>'error','message'=>'Inventory table not found.'], 404);

    $primary = $inventory->query("SHOW KEYS FROM {$quotedTable} WHERE Key_name='PRIMARY'")->fetchAll();
    $key = $primary[0]['Column_name'] ?? null;
    if (!$key) delete_out(['status'=>'error','message'=>'This table has no primary key, so its rows cannot be safely restored individually.'], 400);
    $selectedIds = [];
    if ($action === 'delete_selected') {
        $selectedIds = array_values(array_unique(array_map('strval', is_array($data['refNos'] ?? null) ? $data['refNos'] : [])));
        if (!$selectedIds || count(array_filter($selectedIds, static fn($v) => $v !== '' && strlen($v) <= 255)) !== count($selectedIds)) delete_out(['status'=>'error','message'=>'No valid row IDs were provided.'], 400);
        $marks = implode(',', array_fill(0, count($selectedIds), '?'));
        $select = $inventory->prepare("SELECT * FROM {$quotedTable} WHERE " . delete_ident($key) . " IN ({$marks})");
        $select->execute($selectedIds);
    } elseif ($action === 'wipe_all') {
        $select = $inventory->query("SELECT * FROM {$quotedTable}");
    } else {
        delete_out(['status'=>'error','message'=>'Unknown delete action.'], 400);
    }
    $rows = $select->fetchAll();
    if (!$rows) delete_out(['status'=>'success','message'=>'No matching inventory rows were found.']);

    $insert = $control->prepare("INSERT INTO system_recycle_bin (item_type,source_table,item_name,payload,deleted_at,expires_at) VALUES ('inventory_record',:table,:name,:payload,NOW(),DATE_ADD(NOW(), INTERVAL 30 DAY))");
    $deleteSql = "DELETE FROM {$quotedTable} WHERE " . delete_ident($key) . ' = ?';
    $deleteRow = $inventory->prepare($deleteSql);
    $unhideRow = $control->prepare('DELETE FROM inventory_hidden_rows WHERE table_name = ? AND row_id = ?');
    $control->beginTransaction();
    $inventory->beginTransaction();
    $createdFiles = [];
    $recycleBinIds = [];
    try {
        $dir = delete_recycle_dir();
        foreach ($rows as $row) {
            $payload = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $label = (string)($row['asset_code'] ?? $row['ref_no'] ?? $row['name'] ?? ($table . ' record'));
            $insert->execute([':table'=>$table, ':name'=>$label, ':payload'=>$payload]);
            $id = (string)$control->lastInsertId();
            $recycleBinIds[] = (int)$id;
            $artifact = $dir . DIRECTORY_SEPARATOR . $id . '.json';
            if (file_put_contents($artifact, json_encode(['id'=>(int)$id,'item_type'=>'inventory_record','source_table'=>$table,'item_name'=>$label,'deleted_at'=>date('c'),'expires_in_days'=>30,'payload'=>$row], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX) === false) throw new RuntimeException('Could not write the VS Code recycle-bin backup.');
            $createdFiles[] = $artifact;
            $deleteRow->execute([$row[$key]]);
            $unhideRow->execute([$table, (string)$row[$key]]);
        }
        $inventory->commit();
        $control->commit();
    } catch (Throwable $e) {
        if ($inventory->inTransaction()) $inventory->rollBack();
        if ($control->inTransaction()) $control->rollBack();
        foreach ($createdFiles as $file) @unlink($file);
        throw $e;
    }
    $undoChanges = array_map(
        static fn(array $row): array => [
            'table' => $table,
            'key' => $key,
            'before' => $row,
            'after' => null
        ],
        $rows
    );
    $undoToken = null;
    $undoWarning = '';
    try {
        $undoToken = asset_undo_store($control, (int)$_SESSION['uid'], $undoChanges, [
            'recycle_bin_ids' => $recycleBinIds
        ]);
    } catch (Throwable $undoError) {
        error_log('Inventory records were deleted but Undo could not be saved: ' . $undoError->getMessage());
        $undoWarning = ' Undo could not be saved for this deletion.';
    }
    record_user_activity($action === 'wipe_all' ? 'Moved all asset records to recycle bin' : 'Moved asset records to recycle bin', 'inventory', [
        'table' => $table,
        'count' => count($rows),
        'items' => array_slice(array_map(
            static fn(array $row): string => (string)($row['asset_code'] ?? $row['ref_no'] ?? $row['name'] ?? $row[$key] ?? ''),
            $rows
        ), 0, 25)
    ]);
    delete_out([
        'status'=>'success',
        'message'=>'Moved ' . count($rows) . ' item(s) to the recycle bin.' . $undoWarning,
        'undo_token'=>$undoToken
    ]);
} catch (Throwable $e) {
    delete_out(['status'=>'error','message'=>$e->getMessage()], 500);
}
