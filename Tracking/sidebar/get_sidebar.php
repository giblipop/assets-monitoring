<?php
require_once __DIR__ . '/../connector/auth_guard.php';
// 1. Force error reporting off for output safety in production
error_reporting(0); 
ini_set('display_errors', 0);

// 2. Database connection configuration (use shared config)
require_once __DIR__ . '/../connector/config.php';
// 3. Establish the connection to control panel DB
$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_CONTROL);

// Check connection
if ($conn->connect_error) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(["error" => "Connection failed: " . $conn->connect_error]);
    exit;
}

$data = [];

// 4. Pure READ-ONLY Query
// Fetches all columns, ordered by your custom sequence so your frontend sorts it perfectly.
$displayNameColumn = $conn->query(
    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS "
    . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'navigation_menu' AND COLUMN_NAME = 'display_name'"
);
$hasDisplayName = $displayNameColumn && $displayNameColumn->num_rows > 0;
$displayNameSelect = $hasDisplayName ? 'display_name' : 'NULL AS display_name';
$query = "SELECT unique_id, table_name, {$displayNameSelect}, icon, sort_order, is_visible 
          FROM navigation_menu 
          WHERE is_visible = 1
          ORDER BY sort_order ASC";

$result = $conn->query($query);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $data[] = [
            "unique_id"  => $row['unique_id'],
            "table_name" => $row['table_name'],
            "display_name" => trim((string)($row['display_name'] ?? '')) ?: $row['table_name'],
            "icon"       => $row['icon'],
            "sort_order" => (int)$row['sort_order'],
            "is_visible" => (int)$row['is_visible'] // 1 for visible, 0 for hidden
        ];
    }
    $result->free();
}

$conn->close();

// Clear any accidental output buffers
if (ob_get_length()) ob_clean();

// 5. Output the data as JSON for your frontend application
header('Content-Type: application/json; charset=utf-8');
echo json_encode($data);
exit;
?>