<?php
require_once __DIR__ . '/../../../connector/auth_guard.php';
// fetch.php — return database columns and rows for the selected inventory table

// Buffer output so nothing leaks
ob_start();

header('Content-Type: application/json; charset=utf-8');

// Hide raw PHP errors from output; we still log/throw them
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

    // Resolve table name from current folder and sanitize
    $currentFolder = $_GET['table'] ?? basename(__DIR__);
    $tableName = preg_replace('/[^a-zA-Z0-9_]/', '', $currentFolder);

    if (empty($tableName)) {
        throw new Exception('Could not resolve a valid database table name target.');
    }

    // Extra safety: ensure the table actually exists in the database
    $escapedTableCheck = mysqli_real_escape_string($conn, $tableName);
    $checkRes = mysqli_query(
        $conn,
        "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES "
        . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$escapedTableCheck}'"
    );
    if (!$checkRes || mysqli_num_rows($checkRes) === 0) {
        throw new Exception("Target table '{$tableName}' does not exist.");
    }
    mysqli_free_result($checkRes);

    // Prefer ordering by id when present for stable results
    $hasId = false;
    $tableHeaders = [];
    $colRes = mysqli_query($conn, "SHOW COLUMNS FROM `{$escapedTableCheck}`");
    if ($colRes) {
        while ($col = mysqli_fetch_assoc($colRes)) {
            $tableHeaders[] = $col['Field'];
            if (strtolower($col['Field']) === 'id') {
                $hasId = true;
            }
        }
        mysqli_free_result($colRes);
    }

    $orderClause = $hasId ? "ORDER BY `id` ASC" : "";

    // Build and run query
    $query = "SELECT * FROM `{$escapedTableCheck}` {$orderClause}";
    $result = mysqli_query($conn, $query);

    if (!$result) {
        throw new Exception(mysqli_error($conn));
    }

    $data = [];
    while ($row = mysqli_fetch_assoc($result)) {
        // Normalize primary id into a consistent 'id' key (handles different DB column casings)
        if (!isset($row['id'])) {
            if (isset($row['ID'])) $row['id'] = $row['ID'];
            elseif (isset($row['Id'])) $row['id'] = $row['Id'];
        }

        // Ensure id is string for consistent JSON typing
        if (isset($row['id'])) {
            $row['id'] = (string)$row['id'];
        } else {
            // If no id exists, generate an empty string to avoid undefined index errors
            $row['id'] = '';
        }


        $data[] = $row;
    }
    mysqli_free_result($result);

    $headerLabels = [];
    $control = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_CONTROL};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $labelsTable = $control->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
    );
    $labelsTable->execute([$DB_CONTROL, 'inventory_column_labels']);
    if ((int)$labelsTable->fetchColumn() > 0) {
        $labels = $control->prepare('SELECT column_name, display_name FROM inventory_column_labels WHERE table_name = ?');
        $labels->execute([$tableName]);
        foreach ($labels->fetchAll() as $label) {
            $headerLabels[(string)$label['column_name']] = (string)$label['display_name'];
        }
    }

    // Clear buffer and output JSON
    ob_end_clean();
    echo json_encode([
        'success' => true,
        'headers' => $tableHeaders,
        'header_labels' => $headerLabels,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Exception $e) {
    if (ob_get_length()) ob_end_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit;
}
?>
