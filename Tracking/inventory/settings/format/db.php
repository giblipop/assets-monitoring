<?php
require_once __DIR__ . '/../../../connector/auth_guard.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$configCandidates = [
    __DIR__ . '/../../connector/config.php',
    __DIR__ . '/../../../connector/config.php'
];
foreach ($configCandidates as $configPath) {
    if (is_file($configPath)) {
        require_once $configPath;
        break;
    }
}

$host = $DB_HOST ?? 'localhost';
$user = $DB_USER ?? 'root';
$password = $DB_PASS ?? '';
$database = $DB_INVENTORY ?? 'inventory';

$conn = mysqli_connect($host, $user, $password, $database);

// ========================================================
// 1. DYNAMIC TABLE NAME EXTRACTION BASED ON FOLDER NAME
// ========================================================

// Use the folder where this script resides
$scriptPath = $_SERVER['SCRIPT_FILENAME']; // full filesystem path
$folderName = basename(dirname($scriptPath)); // parent folder name

// Sanitize folder name for SQL safety
$requestedTable = $_GET['table'] ?? $folderName;
$tableName = preg_replace('/[^a-zA-Z0-9_]/', '', $requestedTable);

if (empty($tableName)) {
    die(json_encode([
        "success" => false,
        "message" => "No valid table name could be resolved from script folder."
    ]));
}

$MODULE_TABLE = $tableName;

// ========================================================
// 2. FETCH TABLE HEADERS (COLUMNS) FROM MYSQL DYNAMICALLY
// ========================================================
$MODULE_HEADERS = [];
$dbError = null;

if ($conn) {
    $safeTable = mysqli_real_escape_string($conn, $MODULE_TABLE);
    $result = mysqli_query($conn, "SHOW COLUMNS FROM `$safeTable`");

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $MODULE_HEADERS[] = $row['Field'];
        }
        mysqli_free_result($result);
    } else {
        $dbError = mysqli_error($conn);
    }
} else {
    $dbError = mysqli_connect_error();
}

// ========================================================
// 3. DEBUG OUTPUT IF RUN DIRECTLY
// ========================================================
if (basename($_SERVER['SCRIPT_NAME']) === 'db.php') {
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode([
        "connection_status" => ($conn) ? "Connected successfully" : "Connection failed",
        "detected_table"    => $MODULE_TABLE,
        "columns_fetched"   => $MODULE_HEADERS,
        "total_columns"     => count($MODULE_HEADERS),
        "debug_notes"       => [
            "script_folder" => $folderName,
            "mysql_error"   => $dbError
        ]
    ], JSON_PRETTY_PRINT);
    exit();
}
?>
