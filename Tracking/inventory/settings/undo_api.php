<?php
declare(strict_types=1);

require_once __DIR__ . '/../../connector/auth_guard.php';
require_once __DIR__ . '/../../connector/undo_support.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function undo_respond(array $body, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    undo_respond(['success' => false, 'message' => 'Use POST to undo a change.'], 405);
}

$csrfToken = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$sessionToken = (string)($_SESSION['csrf'] ?? '');
if ($csrfToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $csrfToken)) {
    undo_respond(['success' => false, 'message' => 'Your session token expired. Refresh the page and try again.'], 403);
}
$request = json_decode(file_get_contents('php://input'), true);
$token = is_array($request) ? (string)($request['token'] ?? '') : '';
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    undo_respond(['success' => false, 'message' => 'This Undo action is invalid or has expired.'], 400);
}

try {
    $recycleBinIds = [];
    global $DB_HOST, $DB_USER, $DB_PASS, $DB_INVENTORY, $DB_CONTROL;
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ];
    $inventory = new PDO("mysql:host={$DB_HOST};dbname={$DB_INVENTORY};charset=utf8mb4", $DB_USER, $DB_PASS, $options);
    $control = new PDO("mysql:host={$DB_HOST};dbname={$DB_CONTROL};charset=utf8mb4", $DB_USER, $DB_PASS, $options);
    asset_undo_ensure_table($control);
    $control->beginTransaction();
    $statement = $control->prepare(
        'SELECT id,payload FROM app_undo_journal '
        . 'WHERE token_hash = ? AND user_id = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1 FOR UPDATE'
    );
    $statement->execute([hash('sha256', $token), (int)$_SESSION['uid']]);
    $entry = $statement->fetch();
    if (!$entry) {
        $control->rollBack();
        undo_respond([
            'success' => false,
            'message' => 'Undo is no longer available. It may have expired or a newer change replaced it.'
        ], 409);
    }
    $payload = json_decode((string)$entry['payload'], true);
    if (!is_array($payload)) {
        throw new RuntimeException('Undo data is damaged.');
    }
    $changes = is_array($payload['changes'] ?? null) ? $payload['changes'] : [];
    if (!$changes || count($changes) > 10000) {
        throw new RuntimeException('Undo data is invalid or exceeds the safe item limit.');
    }

    $inventory->beginTransaction();
    try {
        foreach (array_reverse($changes) as $change) {
            if (!is_array($change)) {
                throw new RuntimeException('Undo data is invalid.');
            }
            asset_undo_apply_change($inventory, $change);
        }
        $recycleBinIds = array_values(array_unique(array_filter(
            array_map('intval', is_array($payload['recycle_bin_ids'] ?? null) ? $payload['recycle_bin_ids'] : []),
            static fn(int $id): bool => $id > 0
        )));
        if ($recycleBinIds) {
            $marks = implode(',', array_fill(0, count($recycleBinIds), '?'));
            $remove = $control->prepare("DELETE FROM system_recycle_bin WHERE id IN ({$marks})");
            $remove->execute($recycleBinIds);
        }
        $restoreItems = is_array($payload['restore_items'] ?? null) ? $payload['restore_items'] : [];
        foreach ($restoreItems as $item) {
            if (!is_array($item) || (int)($item['id'] ?? 0) < 1
                || ($item['item_type'] ?? '') !== 'inventory_record'
                || !preg_match('/^[A-Za-z0-9_]+$/', (string)($item['source_table'] ?? ''))) {
                throw new RuntimeException('Undo contains invalid recycle-bin restore data.');
            }
            $restore = $control->prepare(
                'INSERT INTO system_recycle_bin (id,item_type,source_table,item_name,payload,deleted_at,expires_at) '
                . 'VALUES (?,?,?,?,?,?,?)'
            );
            $restore->execute([
                (int)$item['id'],
                (string)$item['item_type'],
                (string)$item['source_table'],
                (string)$item['item_name'],
                (string)$item['payload'],
                (string)$item['deleted_at'],
                (string)$item['expires_at']
            ]);
        }
        $inventory->commit();
    } catch (Throwable $error) {
        if ($inventory->inTransaction()) {
            $inventory->rollBack();
        }
        throw $error;
    }
    $markUsed = $control->prepare('UPDATE app_undo_journal SET used_at = NOW() WHERE id = ? AND used_at IS NULL');
    $markUsed->execute([(int)$entry['id']]);
    $control->commit();
    $recycleDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.recycle-bin';
    foreach ($recycleBinIds as $recycleId) {
        $artifact = $recycleDirectory . DIRECTORY_SEPARATOR . $recycleId . '.json';
        if (is_file($artifact) && !unlink($artifact)) {
            error_log('Could not remove a stale recycle-bin artifact after inventory Undo.');
        }
    }
    $restoreItems = is_array($payload['restore_items'] ?? null) ? $payload['restore_items'] : [];
    if ($restoreItems) {
        $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.recycle-bin';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            error_log('Could not recreate recycle-bin artifacts after Undo.');
            $restoreItems = [];
        }
        foreach ($restoreItems as $item) {
            $document = [
                'id' => (int)$item['id'],
                'item_type' => (string)$item['item_type'],
                'source_table' => (string)$item['source_table'],
                'item_name' => (string)$item['item_name'],
                'deleted_at' => (string)$item['deleted_at'],
                'expires_at' => (string)$item['expires_at'],
                'payload' => json_decode((string)$item['payload'], true)
            ];
            $artifact = $directory . DIRECTORY_SEPARATOR . (int)$item['id'] . '.json';
            $contents = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($contents === false || file_put_contents($artifact, $contents, LOCK_EX) === false) {
                error_log('Could not recreate a recycle-bin artifact after Undo.');
            }
        }
    }
    undo_respond(['success' => true, 'message' => 'The most recent inventory change was undone.']);
} catch (Throwable $error) {
    if (isset($inventory) && $inventory->inTransaction()) {
        $inventory->rollBack();
    }
    if (isset($control) && $control->inTransaction()) {
        $control->rollBack();
    }
    error_log('Inventory undo failed: ' . $error->getMessage());
    undo_respond(['success' => false, 'message' => $error->getMessage()], 409);
}
