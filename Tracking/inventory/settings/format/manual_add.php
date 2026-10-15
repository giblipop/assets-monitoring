<?php
require_once __DIR__ . '/../../../connector/auth_guard.php';
require_once __DIR__ . '/../../../connector/activity_logger.php';
require_once __DIR__ . '/../../../connector/undo_support.php';
// Set headers for JSON response and CORS if your frontend lives on a different port/domain
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET"); // Allowed GET for diagnostic preview handling
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// 1. Database Configuration Integration
ob_start();
// This automatically extracts and makes $MODULE_TABLE and $MODULE_HEADERS available!
require_once "./db.php"; 
ob_clean();

// Check if PDO connection exists (Fallback to native configuration if needed)
if (!isset($conn_pdo)) { // Name altered slightly to prevent overwriting your mysqli $conn inside db.php
    $host     = $DB_HOST ?? 'localhost';
    $username = $DB_USER ?? 'root';
    $password = $DB_PASS ?? '';
    $dbname   = $DB_INVENTORY ?? 'inventory';

    try {
        $conn_pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
        $conn_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        echo json_encode([
            "status" => "error",
            "message" => "PDO Database connection failed: " . $e->getMessage()
        ]);
        exit();
    }
}

// 2. Only process POST requests for data insertion
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        "status" => "error",
        "message" => "Invalid request method. Only POST is accepted for submitting record updates."
    ]);
    exit();
}

// ========================================================
// 3. CONSUME FETCHED DATA FROM DB.PHP
// ========================================================
// Prioritize the table name derived dynamically from your HTML calling page
$tableName = isset($MODULE_TABLE) ? $MODULE_TABLE : '';
$allowedColumns = isset($MODULE_HEADERS) ? $MODULE_HEADERS : [];

if (empty($tableName)) {
    echo json_encode([
        "status" => "error",
        "message" => "Could not resolve a valid database table name target."
    ]);
    exit();
}

// 4. Extract incoming data safely (Handles raw JSON payloads or standard FormData structures)
$inputData = json_decode(file_get_contents("php://input"), true);
if (json_last_error() !== JSON_ERROR_NONE || empty($inputData)) {
    $inputData = $_POST;
}

if (empty($inputData)) {
    echo json_encode([
        "status" => "error",
        "message" => "No asset parameter dataset payload received."
    ]);
    exit();
}
$undoBatchToken = isset($inputData['undo_group']) ? (string)$inputData['undo_group'] : null;
if ($undoBatchToken !== null && !preg_match('/^[a-f0-9]{64}$/', $undoBatchToken)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "The Undo batch identifier is invalid."]);
    exit();
}

// 5. DYNAMIC FIELD & QUERY BUILDING (SECURED VIA FETCHED HEADERS)
$columns = [];
$placeholders = [];
$bindValues = [];
$fieldIndex = 0;

// Dictionary map protecting structural differences between JavaScript properties and DB schemas
$explicitDbMap = [
    'refNo'       => 'ref_no',        
    'serialNo'    => 'serial_number',  
    'jobNumber'   => 'job_number',
    'assetCode'   => 'asset_code',
    'jobTrade'    => 'job_trade',
    'workArea'    => 'work_area',
    'issuedDate'  => 'issued_date'
];

foreach ($inputData as $key => $value) {
    if ($key === 'undo_group') {
        continue;
    }
    // Sanitize column names to safeguard against malicious structural injection parameters
    $rawKey = (string)$key;
    $cleanKey = preg_replace('/[^a-zA-Z0-9_-]/', '', $rawKey);
    if (empty($cleanKey)) continue;

    // Accept an exact database column name first; otherwise translate known camelCase keys.
    if (!empty($allowedColumns) && in_array($rawKey, $allowedColumns, true)) {
        $dbColumn = $rawKey;
    } elseif (isset($explicitDbMap[$cleanKey])) {
        $dbColumn = $explicitDbMap[$cleanKey];
    } else {
        $dbColumn = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $cleanKey));
    }

    // --- CRITICAL DATA USE STEP ---
    // If we have a healthy header array from db.php, verify that the column actually exists in your MySQL table.
    // This stops query crashes caused by unexpected fields or spelling mismatches.
    if (!empty($allowedColumns) && !in_array($dbColumn, $allowedColumns)) {
        continue; // Skip this field entirely if it doesn't match a fetched database column
    }

    // Skip primary key auto-increment variables - ref_no and id should always be auto-generated
    // Last User is maintained by the issue/return workflow, never by a manual insert or import.
    if (in_array(strtolower($dbColumn), ['id', 'ref_no', 'last_user'], true)) {
        continue;
    }

    $placeholder = ':field_' . $fieldIndex++;
    $columns[] = "`$dbColumn`";
    $placeholders[] = $placeholder;
    
    // Normalize empty strings to null values, otherwise apply string trim
    $bindValues[$placeholder] = ($value === '') ? null : trim($value);
}

// Global safety check 
if (empty($columns)) {
    echo json_encode([
        "status" => "error",
        "message" => "Data processing failed: No fields matched your actual database table layout.",
        "debug_expected_headers" => $allowedColumns
    ]);
    exit();
}

// Assemble full dynamic SQL insert string sequence target
$sql = "INSERT INTO `$tableName` (" . implode(", ", $columns) . ") VALUES (" . implode(", ", $placeholders) . ")";

// 6. Execution Layer using PDO prepared statements
try {
    $stmt = $conn_pdo->prepare($sql);
    
    // Execute query passing bound parameters array mapped inside natively
    if ($stmt->execute($bindValues)) {
        $recordId = $conn_pdo->lastInsertId();
        $undoToken = null;
        $undoWarning = '';
        try {
            $quotedTable = '`' . str_replace('`', '``', $tableName) . '`';
            $primary = $conn_pdo->query("SHOW KEYS FROM {$quotedTable} WHERE Key_name='PRIMARY'")->fetch(PDO::FETCH_ASSOC);
            $primaryColumn = (string)($primary['Column_name'] ?? '');
            $primaryValue = null;
            if ($primaryColumn !== '') {
                foreach ($conn_pdo->query("SHOW COLUMNS FROM {$quotedTable}")->fetchAll(PDO::FETCH_ASSOC) as $columnInfo) {
                    if ((string)$columnInfo['Field'] === $primaryColumn
                        && stripos((string)($columnInfo['Extra'] ?? ''), 'auto_increment') !== false) {
                        $primaryValue = $recordId;
                        break;
                    }
                }
            }
            if ($primaryColumn !== '' && $primaryValue === null) {
                foreach ($inputData as $inputKey => $inputValue) {
                    $cleanInputKey = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$inputKey);
                    $candidate = isset($allowedColumns) && in_array((string)$inputKey, $allowedColumns, true)
                        ? (string)$inputKey
                        : ($explicitDbMap[$cleanInputKey] ?? strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $cleanInputKey)));
                    if ($candidate === $primaryColumn) {
                        $primaryValue = $inputValue;
                        break;
                    }
                }
            }
            if ($primaryColumn === '' || $primaryValue === null || (string)$primaryValue === '') {
                throw new RuntimeException('This table has no recoverable primary key.');
            }
            $fetch = $conn_pdo->prepare(
                'SELECT * FROM ' . $quotedTable . ' WHERE `' . str_replace('`', '``', $primaryColumn) . '` = ? LIMIT 1'
            );
            $fetch->execute([$primaryValue]);
            $insertedRecord = $fetch->fetch(PDO::FETCH_ASSOC);
            if (!$insertedRecord) {
                throw new RuntimeException('The new record could not be re-read for Undo.');
            }
            $control = new PDO(
                "mysql:host={$DB_HOST};dbname={$DB_CONTROL};charset=utf8mb4",
                $DB_USER,
                $DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
            $undoToken = asset_undo_store($control, (int)$_SESSION['uid'], [[
                'table' => $tableName,
                'key' => $primaryColumn,
                'before' => null,
                'after' => $insertedRecord
            ]], [], $undoBatchToken);
        } catch (Throwable $undoError) {
            error_log('Inventory record was added but Undo could not be saved: ' . $undoError->getMessage());
            $undoWarning = ' Undo could not be saved for this record.';
        }
        record_user_activity('Added asset record', 'inventory', [
            'table' => $tableName,
            'record_id' => $recordId
        ]);
        echo json_encode([
            "status" => "success",
            "message" => "Record saved successfully to the " . ucfirst($tableName) . " system tracking registry!" . $undoWarning,
            "id" => $recordId,
            "undo_token" => $undoToken
        ]);
    } else {
        echo json_encode([
            "status" => "error",
            "message" => "Failed to write the asset record entry."
        ]);
    }

} catch (PDOException $e) {
    echo json_encode([
        "status" => "error",
        "message" => "Database write validation exception error: " . $e->getMessage(),
        "debug_sql_ran" => $sql
    ]);
}
?>
