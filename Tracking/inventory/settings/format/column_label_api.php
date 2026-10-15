<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../connector/auth_guard.php';
require_once __DIR__ . '/../../../connector/activity_logger.php';
require_once __DIR__ . '/../../../connector/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

function column_label_respond(array $body, int $status): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    column_label_respond(['success' => false, 'message' => 'Use POST to rename a column heading.'], 405);
}

$token = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$sessionToken = (string)($_SESSION['csrf'] ?? '');
if ($token === '' || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
    column_label_respond(['success' => false, 'message' => 'Refresh the page and try again.'], 403);
}
if (!in_array((string)($_SESSION['workspace_role'] ?? ''), ['owner', 'editor'], true)) {
    column_label_respond(['success' => false, 'message' => 'You do not have permission to rename table headings.'], 403);
}

$request = json_decode(file_get_contents('php://input') ?: '', true);
$table = is_array($request) ? trim((string)($request['table'] ?? '')) : '';
$column = is_array($request) ? trim((string)($request['column'] ?? '')) : '';
$label = is_array($request) ? trim((string)($request['label'] ?? '')) : '';
if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $table)
    || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $column)
    || $label === '' || mb_strlen($label, 'UTF-8') > 100
    || in_array(strtolower($column), ['id', 'last_user'], true)) {
    column_label_respond(['success' => false, 'message' => 'The table, column, or heading name is invalid.'], 400);
}

try {
    $inventory = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_INVENTORY};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $exists = $inventory->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $exists->execute([$DB_INVENTORY, $table, $column]);
    if ((int)$exists->fetchColumn() !== 1) {
        column_label_respond(['success' => false, 'message' => 'That table column no longer exists. Refresh the table and try again.'], 404);
    }

    $control = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_CONTROL};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $control->exec(
        'CREATE TABLE IF NOT EXISTS inventory_column_labels ('
        . 'table_name VARCHAR(64) NOT NULL,'
        . 'column_name VARCHAR(64) NOT NULL,'
        . 'display_name VARCHAR(100) NOT NULL,'
        . 'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
        . 'PRIMARY KEY (table_name, column_name)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $save = $control->prepare(
        'INSERT INTO inventory_column_labels (table_name, column_name, display_name) VALUES (?, ?, ?) '
        . 'ON DUPLICATE KEY UPDATE display_name = VALUES(display_name)'
    );
    $previousLabelQuery = $control->prepare(
        'SELECT display_name FROM inventory_column_labels WHERE table_name = ? AND column_name = ?'
    );
    $previousLabelQuery->execute([$table, $column]);
    $previousLabel = $previousLabelQuery->fetchColumn();
    $save->execute([$table, $column, $label]);

    $warning = null;
    try {
        record_user_activity('Renamed table column heading', 'inventory', [
            'table' => $table,
            'column' => $column,
            'previous_label' => $previousLabel === false ? null : $previousLabel,
            'new_label' => $label
        ]);
    } catch (Throwable $loggingError) {
        error_log('Column heading was renamed but activity logging failed: ' . $loggingError->getMessage());
        $warning = 'The heading was renamed, but the activity history could not be updated.';
    }

    column_label_respond(['success' => true, 'label' => $label, 'warning' => $warning], 200);
} catch (Throwable $error) {
    error_log('Inventory column heading update failed: ' . $error->getMessage());
    column_label_respond(['success' => false, 'message' => 'The heading could not be saved. Please try again.'], 500);
}
