<?php
require_once __DIR__ . '/../../connector/auth_guard.php';
require_once __DIR__ . '/../../connector/activity_archive.php';
require_once __DIR__ . '/../../connector/inventory_tables.php';

header("Content-Type: application/json");

require_once __DIR__ . '/../../connector/config.php';

try {

    /*
    |--------------------------------------------------------------------------
    | DATABASE CONNECTIONS
    |--------------------------------------------------------------------------
    */

    $sidebarsConn = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_CONTROL};charset=utf8",
        $DB_USER,
        $DB_PASS
    );

    $inventoryConn = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_INVENTORY};charset=utf8",
        $DB_USER,
        $DB_PASS
    );

    $authConn = new PDO(
        "mysql:host={$DB_HOST};dbname=inventory;charset=utf8",
        $DB_USER,
        $DB_PASS
    );

    $sidebarsConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $inventoryConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $authConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    /*
    |--------------------------------------------------------------------------
    | SIDEBARS
    |--------------------------------------------------------------------------
    */

    $sidebarStmt = $sidebarsConn->query(
        "SELECT * FROM navigation_menu"
    );

    $sidebarRows = $sidebarStmt->fetchAll(PDO::FETCH_ASSOC);

    $sidebarCountStmt = $sidebarsConn->query(
        "SELECT COUNT(*) AS total FROM navigation_menu"
    );

    $sidebarCount = $sidebarCountStmt->fetch(PDO::FETCH_ASSOC)['total'];

    $sidebarData = [
        "count" => $sidebarCount,
        "rows" => $sidebarRows
    ];

    $activityHistory = load_user_activity_history($authConn, (int)$_SESSION['uid']);

    /*
|--------------------------------------------------------------------------
| INVENTORY
|--------------------------------------------------------------------------
*/

$tablesStmt = $inventoryConn->query("SHOW TABLES");
$tables = array_values(array_filter(
    $tablesStmt->fetchAll(PDO::FETCH_COLUMN),
    static function ($table): bool {
        return !inventory_is_system_table((string)$table);
    }
));

$inventoryData = [];
$stockSummary = [];

$requestRecords = 0;

foreach ($tables as $table) {

    $stmt = $inventoryConn->query(
        "SELECT * FROM `$table`"
    );

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $count = count($rows);

    $requestRecords += $count;

    $formattedRows = [];
    $availableStocks = 0;

    foreach ($rows as $row) {

        $status = strtolower(trim($row['status'] ?? ''));

        if (strpos($status, 'stock') !== false) {
            $availableStocks++;
        }

        $formattedRows[] = [

            "item" => $row['model'] ?? '',

            "asset_code" => $row['asset_code'] ?? '',

            "name" => $row['name'] ?? '',

            "job_trade" =>
                $row['jobtrade']
                ?? $row['job_trade']
                ?? '',

            "work_area" => $row['work_area'] ?? '',

            "date_issued" =>
                $row['date_issued']
                ?? $row['issued_date']
                ?? $row['issue_date']
                ?? $row['dateissued']
                ?? '',

            "remarks" => $row['remarks'] ?? '',

            "status" => $row['status'] ?? '',

            "department" => $row['department'] ?? '',

            "serial_number" => $row['serial_number'] ?? '',

            "table_name" => $table
        ];
    }

    $inventoryData[$table] = [

        "count" => $count,

        "available_stocks" => $availableStocks,

        "rows" => $formattedRows
    ];

    $stockSummary[$table] = $availableStocks;
}

    /*
    |--------------------------------------------------------------------------
    | KPI
    |--------------------------------------------------------------------------
    */

    $kpi = [

        "total_assets_profile" => $sidebarCount,

        "request_records" => $requestRecords,

        "threshold_triggers" => 0,

        "depleted_inventory" => 0
    ];

    /*
    |--------------------------------------------------------------------------
    | RESPONSE
    |--------------------------------------------------------------------------
    */

    $response = [

    "sidebars" => $sidebarData,

    "inventory" => $inventoryData,

    "stock_summary" => $stockSummary,

    "activity_history" => $activityHistory,

    "kpi" => $kpi
];

    echo json_encode(
        $response,
        JSON_PRETTY_PRINT
    );

} catch (Throwable $e) {
    error_log('Dashboard data request failed: ' . $e->getMessage());

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Dashboard data could not be loaded. Please try again later."
    ]);
}