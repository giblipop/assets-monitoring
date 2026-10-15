<?php
require_once __DIR__ . '/../../../connector/auth_guard.php';
require_once __DIR__ . '/../../../connector/activity_logger.php';
require_once __DIR__ . '/../../../connector/undo_support.php';
// update.php — secure dynamic updater
header('Content-Type: application/json; charset=UTF-8');
ob_start();

// Hide raw PHP errors from output; log them if you want
error_reporting(E_ALL);
ini_set('display_errors', '0');

try {
    // Load DB connection (try parent then local)
    if (file_exists(__DIR__ . '/../../db.php')) {
        require_once __DIR__ . '/../../db.php';
    } else {
        require_once __DIR__ . '/db.php';
    }

    if (!isset($conn) || !$conn) {
        throw new Exception('Database connection is missing or failed.');
    }

    // Only accept POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method. POST required.');
    }

    // Resolve table name from current folder and sanitize
    $currentFolder = $_GET['table'] ?? basename(__DIR__);
    $tableName = preg_replace('/[^a-zA-Z0-9_]/', '', $currentFolder);
    if (empty($tableName)) {
        throw new Exception('Could not resolve target table name.');
    }

    $escapedTable = mysqli_real_escape_string($conn, $tableName);

    // Ensure table exists
    $checkRes = mysqli_query($conn, "SHOW TABLES LIKE '{$escapedTable}'");
    if (!$checkRes || mysqli_num_rows($checkRes) === 0) {
        throw new Exception("Target table '{$tableName}' does not exist.");
    }
    mysqli_free_result($checkRes);

    // Fetch table columns and types to build a safe allowlist
    $columns = []; // ['col_name' => 'type']
    $colRes = mysqli_query($conn, "SHOW COLUMNS FROM `{$escapedTable}`");
    if (!$colRes) {
        throw new Exception('Failed to fetch table schema: ' . mysqli_error($conn));
    }
    while ($col = mysqli_fetch_assoc($colRes)) {
        $columns[$col['Field']] = $col['Type']; // raw type string (e.g., int(11), varchar(255))
    }
    mysqli_free_result($colRes);

    if (empty($columns)) {
        throw new Exception('No columns found for target table.');
    }

    // Determine primary identifier: prefer 'id' if present, otherwise 'asset_code' if present
    $primaryKey = null;
    if (array_key_exists('id', $columns)) {
        $primaryKey = 'id';
    } elseif (array_key_exists('asset_code', $columns)) {
        $primaryKey = 'asset_code';
    } else {
        // fallback to first column if neither exists (rare)
        $primaryKey = array_keys($columns)[0];
    }

    // Accept either id or asset_code from POST (id preferred)
    $providedId = isset($_POST['id']) ? trim((string)$_POST['id']) : '';
    $providedAssetCode = isset($_POST['asset_code']) ? trim((string)$_POST['asset_code']) : '';

    if ($providedId === '' && $providedAssetCode === '') {
        throw new Exception('Missing identifier: provide id or asset_code.');
    }

    // Build update list from POST but only include allowed columns and skip protected ones
    $protected = ['id', 'last_user']; // system-maintained identifiers and assignment history
    if ($primaryKey === 'asset_code') {
        $protected[] = 'asset_code';
    }

    $updatePairs = [];
    $bindValues = [];
    $bindTypes = '';

    // Helper: determine mysqli bind type from column type
    $determineType = function($columnType) {
        $t = strtolower($columnType);
        // integer-like
        if (preg_match('/^(tinyint|smallint|mediumint|int|bigint)/', $t)) return 'i';
        // float/decimal/double
        if (preg_match('/^(float|double|decimal)/', $t)) return 'd';
        // otherwise treat as string
        return 's';
    };

    foreach ($_POST as $rawKey => $rawValue) {
        $key = preg_replace('/[^a-zA-Z0-9_-]/', '', $rawKey);
        if ($key === '') continue;
        if (!array_key_exists($key, $columns)) continue; // not a real column
        if (in_array(strtolower($key), $protected, true)) continue; // skip protected columns

        // Skip empty values if you prefer not to update them; currently we allow empty strings
        $updatePairs[] = "`{$key}` = ?";
        $bindValues[] = (string)$rawValue;
        $bindTypes .= $determineType($columns[$key]);
    }

    if (empty($updatePairs)) {
        throw new Exception('No valid updatable fields were provided.');
    }

    // Determine WHERE clause and bind the identifier
    if ($providedId !== '' && $primaryKey === 'id') {
        $whereClause = "`id` = ?";
        $bindValues[] = $providedId;
        $bindTypes .= $determineType($columns['id']);
    } elseif ($providedAssetCode !== '' && array_key_exists('asset_code', $columns)) {
        $whereClause = "`asset_code` = ?";
        $bindValues[] = $providedAssetCode;
        $bindTypes .= $determineType($columns['asset_code']);
    } else {
        // If primaryKey is something else and user provided a matching key, use it
        if ($providedId !== '' && array_key_exists('id', $columns)) {
            $whereClause = "`id` = ?";
            $bindValues[] = $providedId;
            $bindTypes .= $determineType($columns['id']);
        } elseif ($providedAssetCode !== '' && array_key_exists('asset_code', $columns)) {
            $whereClause = "`asset_code` = ?";
            $bindValues[] = $providedAssetCode;
            $bindTypes .= $determineType($columns['asset_code']);
        } else {
            throw new Exception('Could not determine a valid WHERE identifier for update.');
        }
    }

    $undoKeyValue = $providedId !== '' && $primaryKey === 'id' ? $providedId : $providedAssetCode;
    $undoPdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_INVENTORY};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $undoKey = $primaryKey;
    $undoSelect = $undoPdo->prepare(
        'SELECT * FROM `' . str_replace('`', '``', $tableName) . '` WHERE `' .
        str_replace('`', '``', $undoKey) . '` = ? LIMIT 2'
    );
    $undoSelect->execute([$undoKeyValue]);
    $undoBeforeRows = $undoSelect->fetchAll(PDO::FETCH_ASSOC);
    if (count($undoBeforeRows) !== 1) {
        throw new Exception('The record could not be uniquely identified for a safe update.');
    }
    $undoBefore = $undoBeforeRows[0];

    // Final SQL
    $sql = "UPDATE `{$escapedTable}` SET " . implode(', ', $updatePairs) . " WHERE {$whereClause} LIMIT 1";

    // Prepare statement
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . mysqli_error($conn));
    }

    // Bind parameters dynamically (bind_param requires references)
    // Build array: first element is types string, followed by values by reference
    $params = [];
    $params[] = $bindTypes;
    foreach ($bindValues as $k => $v) {
        // ensure each value is a variable so we can pass by reference
        $params[] = &$bindValues[$k];
    }

    // Use call_user_func_array to bind params
    if (!call_user_func_array([$stmt, 'bind_param'], $params)) {
        mysqli_stmt_close($stmt);
        throw new Exception('Binding parameters failed: ' . mysqli_stmt_error($stmt));
    }

    // Execute inside transaction for safety
    mysqli_begin_transaction($conn);

    if (!mysqli_stmt_execute($stmt)) {
        mysqli_rollback($conn);
        $err = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        throw new Exception('Execution failed: ' . $err);
    }

    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_commit($conn);
    mysqli_stmt_close($stmt);

    $undoToken = null;
    $undoWarning = '';
    try {
        $undoAfterSelect = $undoPdo->prepare(
            'SELECT * FROM `' . str_replace('`', '``', $tableName) . '` WHERE `' .
            str_replace('`', '``', $undoKey) . '` = ? LIMIT 2'
        );
        $undoAfterSelect->execute([$undoKeyValue]);
        $undoAfterRows = $undoAfterSelect->fetchAll(PDO::FETCH_ASSOC);
        if (count($undoAfterRows) !== 1) {
            throw new RuntimeException('The updated record could not be uniquely re-read.');
        }
        $controlPdo = new PDO(
            "mysql:host={$DB_HOST};dbname={$DB_CONTROL};charset=utf8mb4",
            $DB_USER,
            $DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $undoToken = asset_undo_store($controlPdo, (int)$_SESSION['uid'], [[
            'table' => $tableName,
            'key' => $undoKey,
            'before' => $undoBefore,
            'after' => $undoAfterRows[0]
        ]]);
    } catch (Throwable $undoError) {
        error_log('Asset update succeeded but Undo could not be saved: ' . $undoError->getMessage());
        $undoWarning = ' Undo could not be saved for this change.';
    }

    record_user_activity('Modified asset record', 'inventory', [
        'table' => $tableName,
        'record_id' => $providedId !== '' ? $providedId : $providedAssetCode,
        'fields' => array_values(array_filter(array_keys($_POST), static function ($field) use ($columns, $protected) {
            return array_key_exists($field, $columns) && !in_array(strtolower($field), $protected, true);
        })),
        'changed_rows' => $affected
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Record updated successfully.' . $undoWarning,
        'affected_rows' => $affected,
        'undo_token' => $undoToken
    ]);
    exit;

} catch (Exception $e) {
    if (ob_get_length()) ob_end_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit;
}
?>
