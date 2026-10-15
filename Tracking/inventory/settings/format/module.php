<?php
require_once __DIR__ . '/../../../connector/auth_guard.php';
require_once __DIR__ . '/../../../connector/config.php';

$rawTable = $_GET['table'] ?? '';
if (!is_string($rawTable) || !preg_match('/^[a-zA-Z0-9_]+$/', $rawTable)) {
    http_response_code(400);
    exit('Invalid inventory table.');
}

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_INVENTORY};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $check = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table_name');
    $check->execute([':schema' => $DB_INVENTORY, ':table_name' => $rawTable]);
    if ((int)$check->fetchColumn() === 0) {
        http_response_code(404);
        exit('Inventory table not found.');
    }
} catch (PDOException $e) {
    http_response_code(500);
    exit('Could not load this inventory module.');
}

$workbookTabs = [];
$workbookName = ucwords(str_replace('_', ' ', $rawTable));
try {
    $control = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_CONTROL};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $columns = $control->query('SHOW COLUMNS FROM navigation_menu')->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('workbook_id', $columns, true)
        && in_array('sheet_name', $columns, true)
        && in_array('sheet_order', $columns, true)
        && in_array('display_name', $columns, true)) {
        $lookup = $control->prepare('SELECT workbook_id, display_name FROM navigation_menu WHERE table_name = ? LIMIT 1');
        $lookup->execute([$rawTable]);
        $group = $lookup->fetch();
        if ($group && $group['workbook_id']) {
            $tabs = $control->prepare(
                'SELECT table_name, sheet_name FROM navigation_menu WHERE workbook_id = ? ORDER BY sheet_order, sort_order'
            );
            $tabs->execute([$group['workbook_id']]);
            $workbookTabs = array_map(static fn(array $tab): array => [
                'table' => (string)$tab['table_name'],
                'name' => (string)($tab['sheet_name'] ?: $tab['table_name'])
            ], $tabs->fetchAll());
            $workbookName = trim((string)($group['display_name'] ?? '')) ?: $workbookName;
        }
    }
} catch (PDOException $e) {
    error_log('Workbook sheet navigation could not be loaded: ' . $e->getMessage());
}

$templatePath = __DIR__ . '/table.html';
$html = file_get_contents($templatePath);
if ($html === false) {
    http_response_code(500);
    exit('Inventory template is unavailable.');
}

$displayName = $workbookName;
$safeDisplayName = htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8');
$tableConfig = json_encode($rawTable, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
$workbookTabsConfig = json_encode($workbookTabs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
$workbookNameConfig = json_encode($workbookName, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

$html = str_replace('../settings/format/style.css', './style.css', $html);
$html = str_replace('<title>Asset Inventory</title>', '<title>' . $safeDisplayName . ' Inventory</title>', $html);
$html = str_replace('<h1 id="workspaceTitle">ASSET MONITORING</h1>', '<h1 id="workspaceTitle">' . htmlspecialchars(strtoupper($workbookName), ENT_QUOTES, 'UTF-8') . '</h1>', $html);
$html = str_replace('<tbody id="tableBody">', '<tbody id="tableBody" data-table="' . htmlspecialchars($rawTable, ENT_QUOTES, 'UTF-8') . '">', $html);
$inventoryConfigScript = '<script>window.INVENTORY_MODULE_TABLE = ' . $tableConfig
    . '; window.INVENTORY_WORKBOOK_TABS = ' . $workbookTabsConfig
    . '; window.INVENTORY_WORKBOOK_NAME = ' . $workbookNameConfig . ';</script>' . PHP_EOL;
$injectionCount = 0;
$html = preg_replace_callback(
    '~<script\s+src=["\']\./table\.js(?:\?[^"\']*)?["\'][^>]*>\s*</script>~i',
    static function (array $matches) use ($inventoryConfigScript): string {
        return $inventoryConfigScript . $matches[0];
    },
    $html,
    1,
    $injectionCount
);
if ($html === null || $injectionCount !== 1) {
    error_log('Workbook sheet navigation could not be initialized: table.js script tag was not found.');
    http_response_code(500);
    exit('Inventory page could not be initialized.');
}

header('Content-Type: text/html; charset=utf-8');
echo $html;