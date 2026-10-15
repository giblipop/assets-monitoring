<?php
require_once __DIR__ . '/../../connector/auth_guard.php';
require_once __DIR__ . '/../../connector/activity_logger.php';
require_once __DIR__ . '/../../connector/undo_support.php';
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../connector/config.php';

function rb_out($payload, $status = 200) { http_response_code($status); echo json_encode($payload, JSON_UNESCAPED_UNICODE); exit; }
function rb_ident($name) { if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$name)) throw new RuntimeException('Invalid database or table name.'); return '`' . $name . '`'; }
function rb_workspace_dir() {
  $dir = __DIR__ . '/../.recycle-bin';
  if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Could not create the workspace recycle-bin folder.');
  return $dir;
}
function rb_write_workspace_item(array $row, $payload) {
  $file = rb_workspace_dir() . DIRECTORY_SEPARATOR . (int)$row['id'] . '.json';
  $document = ['id'=>(int)$row['id'],'item_type'=>$row['item_type'],'source_table'=>$row['source_table'],'item_name'=>$row['item_name'],'deleted_at'=>$row['deleted_at'],'expires_at'=>$row['expires_at'],'payload'=>$payload];
  $json = json_encode($document, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
  if ($json === false || file_put_contents($file, $json, LOCK_EX) === false) throw new RuntimeException('Could not write the workspace recycle-bin backup.');
}
function rb_remove_workspace_item($id) {
  $directory = __DIR__ . '/../.recycle-bin';
  foreach ([(int)$id . '.json', 'payload_' . (int)$id . '.json'] as $name) {
    $file = $directory . DIRECTORY_SEPARATOR . $name;
    if (is_file($file)) @unlink($file);
  }
}
function rb_decode_payload(array $item): ?array {
  $payload = json_decode((string)($item['payload'] ?? ''), true);
  if (!is_array($payload)) return null;
  if (empty($payload['_payload_file'])) return $payload;
  $id = filter_var($item['id'] ?? null, FILTER_VALIDATE_INT);
  if ($id === false || $id < 1) throw new RuntimeException('The saved recycle-bin backup reference is invalid.');
  $file = __DIR__ . '/../.recycle-bin/payload_' . $id . '.json';
  $contents = is_file($file) ? file_get_contents($file) : false;
  if ($contents === false) throw new RuntimeException('The saved table backup file is missing.');
  $payload = json_decode($contents, true);
  if (!is_array($payload)) throw new RuntimeException('The saved table backup file is invalid.');
  return $payload;
}
function rb_restore_inventory_folder($tableName, array $files) {
  if (!$files) return;
  $root = realpath(__DIR__ . '/..');
  if (!$root || !preg_match('/^[A-Za-z0-9_]+$/', $tableName)) throw new RuntimeException('Invalid archived folder name.');
  $folder = $root . DIRECTORY_SEPARATOR . $tableName;
  if (file_exists($folder)) throw new RuntimeException('The matching inventory folder already exists. Move or rename it before restoring.');
  if (!mkdir($folder, 0775, true) && !is_dir($folder)) throw new RuntimeException('Could not recreate the inventory folder.');
  foreach ($files as $file) {
    $relative = str_replace('\\', '/', (string)($file['path'] ?? ''));
    $parts = explode('/', $relative);
    if ($relative === '' || str_starts_with($relative, '/') || in_array('..', $parts, true) || in_array('.', $parts, true)) throw new RuntimeException('The archived folder contains an invalid path.');
    $target = $folder . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);
    $parent = dirname($target);
    if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) throw new RuntimeException('Could not recreate an archived subfolder.');
    $contents = base64_decode((string)($file['content'] ?? ''), true);
    if ($contents === false || file_put_contents($target, $contents) === false) throw new RuntimeException('Could not restore an archived folder file.');
  }
  function rb_decode_backup_value($value) {
    if (is_array($value) && array_key_exists('__asset_backup_binary_base64', $value)) {
      $decoded = base64_decode((string)$value['__asset_backup_binary_base64'], true);
      if ($decoded === false) throw new RuntimeException('Saved column data contains invalid binary data.');
      return $decoded;
    }
    if (is_array($value)) throw new RuntimeException('Saved column data contains an unsupported field value.');
    return $value;
  }
}
function rb_table(PDO $db) {
    $db->exec("CREATE TABLE IF NOT EXISTS system_recycle_bin (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      item_type VARCHAR(32) NOT NULL,
      source_table VARCHAR(255) NULL,
      item_name VARCHAR(255) NOT NULL DEFAULT '',
      payload LONGTEXT NOT NULL,
      deleted_at DATETIME NOT NULL,
      expires_at DATETIME NOT NULL,
      INDEX idx_recycle_expires (expires_at), INDEX idx_recycle_type (item_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Keep older navigation trash recoverable and migrate it once.
    $legacy = $db->query("SHOW TABLES LIKE 'navigation_trash'")->fetchColumn();
    if ($legacy) {
      $columns = $db->query("SHOW COLUMNS FROM navigation_trash")->fetchAll(PDO::FETCH_COLUMN);
      if (in_array('unique_id', $columns, true) && in_array('table_name', $columns, true)) {
        $rows = $db->query('SELECT * FROM navigation_trash')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
          $check = $db->prepare("SELECT id FROM system_recycle_bin WHERE item_type='navigation' AND JSON_EXTRACT(payload, '$.unique_id') = :uid LIMIT 1");
          $check->execute([':uid' => (string)($row['unique_id'] ?? '')]);
          if ($check->fetchColumn()) continue;
          $deleted = !empty($row['deleted_at']) ? $row['deleted_at'] : date('Y-m-d H:i:s');
          $ins = $db->prepare("INSERT INTO system_recycle_bin (item_type,source_table,item_name,payload,deleted_at,expires_at) VALUES ('navigation',:table,:name,:payload,:deleted,DATE_ADD(:deleted, INTERVAL 30 DAY))");
          $ins->execute([':table' => null, ':name' => (string)($row['table_name'] ?? 'Navigation'), ':payload' => json_encode($row, JSON_UNESCAPED_UNICODE), ':deleted' => $deleted]);
        }
        $db->exec('DELETE FROM navigation_trash');
      }
    }
}
function rb_insert_inventory_row(PDO $inventory, string $tableName, array $row): void {
  static $statements = [];
  if (!$row) return;
  $fields = array_map('rb_ident', array_keys($row));
  $sql = 'INSERT INTO ' . rb_ident($tableName) . ' (' . implode(',', $fields) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')';
  if (!isset($statements[$sql])) $statements[$sql] = $inventory->prepare($sql);
  $statements[$sql]->execute(array_values($row));
}
function rb_restored_record(PDO $inventory, string $tableName, array $row): ?array {
  $primary = $inventory->query('SHOW KEYS FROM ' . rb_ident($tableName) . " WHERE Key_name='PRIMARY'")->fetch(PDO::FETCH_ASSOC);
  $key = (string)($primary['Column_name'] ?? '');
  if ($key === '' || !array_key_exists($key, $row)) return null;
  $statement = $inventory->prepare(
    'SELECT * FROM ' . rb_ident($tableName) . ' WHERE ' . rb_ident($key) . ' = ? LIMIT 2'
  );
  $statement->execute([$row[$key]]);
  $records = $statement->fetchAll(PDO::FETCH_ASSOC);
  return count($records) === 1 ? ['key' => $key, 'row' => $records[0]] : null;
}
function rb_navigation_columns(PDO $control): array {
  $statement = $control->prepare(
    'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
  );
  $statement->execute(['navigation_menu']);
  return array_fill_keys($statement->fetchAll(PDO::FETCH_COLUMN), true);
}
function rb_insert_navigation(PDO $control, array $entry, string $tableName, int $sortOrder): void {
  $columns = rb_navigation_columns($control);
  $uniqueId = (string)($entry['unique_id'] ?? '');
  $check = $control->prepare('SELECT 1 FROM navigation_menu WHERE unique_id = ? LIMIT 1');
  if ($uniqueId === '') $uniqueId = uniqid('nm_', true);
  $check->execute([$uniqueId]);
  if ($check->fetchColumn()) $uniqueId = uniqid('nm_', true);

  $values = [
    'unique_id' => $uniqueId,
    'table_name' => $tableName,
    'display_name' => $entry['display_name'] ?? null,
    'workbook_id' => $entry['workbook_id'] ?? null,
    'sheet_name' => $entry['sheet_name'] ?? null,
    'sheet_order' => $entry['sheet_order'] ?? null,
    'icon' => $entry['icon'] ?? 'grid',
    'sort_order' => $sortOrder,
    'is_visible' => (int)($entry['is_visible'] ?? 1)
  ];
  $values = array_intersect_key($values, $columns);
  if (!$values) throw new RuntimeException('The navigation table has no compatible columns.');
  $fieldList = implode(',', array_map('rb_ident', array_keys($values)));
  $placeholders = implode(',', array_fill(0, count($values), '?'));
  $insert = $control->prepare('INSERT INTO navigation_menu (' . $fieldList . ') VALUES (' . $placeholders . ')');
  $insert->execute(array_values($values));
}
function rb_restore_item(PDO $control, PDO $inventory, array $item): void {
  static $tableColumns = [];
  $payload = rb_decode_payload($item);
  if (!is_array($payload)) throw new RuntimeException('The saved item data is damaged.');
  if ($item['item_type'] === 'inventory_record') {
    $tableName = (string)$item['source_table'];
    if (!array_key_exists($tableName, $tableColumns)) {
      $cols = $inventory->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t');
      $cols->execute([':t'=>$tableName]);
      $tableColumns[$tableName] = $cols->fetchAll(PDO::FETCH_COLUMN);
    }
    if (!$tableColumns[$tableName]) throw new RuntimeException('The original inventory table no longer exists. Restore its table first.');
    $row = array_intersect_key($payload, array_flip($tableColumns[$tableName]));
    if (!$row) throw new RuntimeException('No compatible fields were found to restore.');
    rb_insert_inventory_row($inventory, $tableName, $row);
  } elseif ($item['item_type'] === 'navigation') {
    $tableName = preg_replace('/[^A-Za-z0-9_]/','', (string)($payload['table_name'] ?? ''));
    if ($tableName === '') throw new RuntimeException('Saved navigation entry has no valid table name.');
    $tableExists=$inventory->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t'); $tableExists->execute([':t'=>$tableName]);
    if (!$tableExists->fetchColumn()) throw new RuntimeException('The inventory table was permanently removed. Restore its table entry first.');
    $conflict=$control->prepare('SELECT COUNT(*) FROM navigation_menu WHERE table_name=:t'); $conflict->execute([':t'=>$tableName]);
    if ($conflict->fetchColumn()) throw new RuntimeException('A navigation entry for this table already exists.');
    $sort=(int)$control->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM navigation_menu')->fetchColumn();
    rb_insert_navigation($control, $payload, $tableName, $sort);
  } elseif ($item['item_type'] === 'inventory_column') {
    $tableName = (string)($payload['table_name'] ?? '');
    $columnName = (string)($payload['column_name'] ?? '');
    $primaryKey = (string)($payload['primary_key'] ?? '');
    $columnType = (string)($payload['column_type'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $tableName)
      || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $columnName)
      || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $primaryKey)
      || !preg_match('/^(?:tinyint|smallint|mediumint|int|bigint|decimal|float|double|bit|char|varchar|tinytext|text|mediumtext|longtext|date|datetime|timestamp|time|year|json)(?:\([0-9]+(?:,[0-9]+)?\))?(?: unsigned)?(?: zerofill)?$/i', $columnType)
      || !is_array($payload['rows'] ?? null)) {
      throw new RuntimeException('Saved column backup is incomplete or invalid.');
    }
    $tableExists = $inventory->prepare(
      'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $tableExists->execute([$tableName]);
    if (!(int)$tableExists->fetchColumn()) throw new RuntimeException('The original table no longer exists.');
    $existingColumns = $inventory->query('SHOW COLUMNS FROM ' . rb_ident($tableName))->fetchAll(PDO::FETCH_COLUMN);
    if (in_array($columnName, $existingColumns, true)) {
      throw new RuntimeException('A column with this name already exists. Rename or remove it before restoring.');
    }
    if (!in_array($primaryKey, $existingColumns, true)) {
      throw new RuntimeException('The original primary-key column no longer exists, so the column data cannot be restored safely.');
    }

    $definition = rb_ident($columnName) . ' ' . $columnType;
    if (!empty($payload['collation'])) {
      $collation = (string)$payload['collation'];
      if (!preg_match('/^[A-Za-z0-9_]+$/', $collation)) throw new RuntimeException('Saved column collation is invalid.');
      $definition .= ' COLLATE ' . $collation;
    }
    $nullable = !empty($payload['nullable']);
    $definition .= $nullable ? ' NULL' : ' NOT NULL';
    $default = $payload['default'] ?? null;
    if ($default !== null) {
      $defaultText = (string)$default;
      $definition .= ' DEFAULT ' . (preg_match('/^CURRENT_TIMESTAMP(?:\([0-6]\))?$/i', $defaultText)
        ? strtoupper($defaultText)
        : $inventory->quote($defaultText));
    } elseif ($nullable) {
      $definition .= ' DEFAULT NULL';
    }
    $extra = trim(str_ireplace('DEFAULT_GENERATED', '', (string)($payload['extra'] ?? '')));
    if ($extra !== '' && !preg_match('/^on update CURRENT_TIMESTAMP(?:\([0-6]\))?$/i', $extra)) {
      throw new RuntimeException('Saved column options are not supported for restore.');
    }
    if ($extra !== '') $definition .= ' ' . $extra;
    $comment = (string)($payload['comment'] ?? '');
    if ($comment !== '') $definition .= ' COMMENT ' . $inventory->quote($comment);
    $previousColumn = (string)($payload['previous_column'] ?? '');
    $positionSql = $previousColumn !== '' && in_array($previousColumn, $existingColumns, true)
      ? ' AFTER ' . rb_ident($previousColumn)
      : '';

    $inventory->exec(
      'ALTER TABLE ' . rb_ident($tableName) . ' ADD COLUMN ' . $definition . $positionSql
    );
    try {
      $update = $inventory->prepare(
        'UPDATE ' . rb_ident($tableName) . ' SET ' . rb_ident($columnName) . ' = ? WHERE '
        . rb_ident($primaryKey) . ' = ?'
      );
      foreach ($payload['rows'] as $savedRow) {
        if (!is_array($savedRow) || !array_key_exists('key', $savedRow)) {
          throw new RuntimeException('Saved column data contains an invalid record.');
        }
        $update->execute([
          rb_decode_backup_value($savedRow['value'] ?? null),
          rb_decode_backup_value($savedRow['key'])
        ]);
      }
    } catch (Throwable $error) {
      try {
        $inventory->exec('ALTER TABLE ' . rb_ident($tableName) . ' DROP COLUMN ' . rb_ident($columnName));
      } catch (Throwable $cleanupError) {
        error_log('Could not remove a partially restored inventory column: ' . $cleanupError->getMessage());
      }
      throw $error;
    }
  } elseif ($item['item_type'] === 'inventory_table') {
    $tableName = preg_replace('/[^A-Za-z0-9_]/','', (string)($payload['table_name'] ?? ''));
    if (!$tableName || empty($payload['create_sql']) || !is_array($payload['rows'] ?? null)) throw new RuntimeException('Saved table backup is incomplete.');
    $exists=$inventory->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t'); $exists->execute([':t'=>$tableName]);
    if ($exists->fetchColumn()) throw new RuntimeException('A table with this name already exists. Rename or remove it before restoring.');
    if (!empty($payload['folder_files'])) {
      $root = realpath(__DIR__ . '/..');
      if (!$root || file_exists($root . DIRECTORY_SEPARATOR . $tableName)) throw new RuntimeException('The matching inventory folder already exists. Move or rename it before restoring.');
    }
    $inventory->exec($payload['create_sql']);
    foreach ($payload['rows'] as $row) {
      if (!is_array($row) || !$row) continue;
      foreach ($row as &$value) {
        if (is_array($value) && array_key_exists('__asset_backup_binary_base64', $value)) {
          $decoded = base64_decode((string)$value['__asset_backup_binary_base64'], true);
          if ($decoded === false) throw new RuntimeException('Saved table data contains invalid binary data.');
          $value = $decoded;
        } elseif (is_array($value)) {
          throw new RuntimeException('Saved table data contains an unsupported field value.');
        }
      }
      unset($value);
      rb_insert_inventory_row($inventory, $tableName, $row);
    }
    rb_restore_inventory_folder($tableName, $payload['folder_files'] ?? []);
    $navEntries=$payload['navigation_entries'] ?? [];
    if (!$navEntries && is_array($payload['navigation'] ?? null)) $navEntries=[$payload['navigation']];
    if (is_array($navEntries) && $navEntries) {
      $existingNavigation=$control->prepare('SELECT 1 FROM navigation_menu WHERE table_name=:t LIMIT 1');
      $existingNavigation->execute([':t'=>$tableName]);
      if (!$existingNavigation->fetchColumn()) {
        $sort=(int)$control->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM navigation_menu')->fetchColumn();
        foreach ($navEntries as $nav) {
          if (!is_array($nav)) continue;
          rb_insert_navigation($control, $nav, $tableName, $sort++);
        }
      }
    }
  } else {
    throw new RuntimeException('This item type cannot be restored.');
  }
  $del=$control->prepare('DELETE FROM system_recycle_bin WHERE id=:id');
  $del->execute([':id'=>(int)$item['id']]);
  rb_remove_workspace_item($item['id']);
}
try {
  $controlName = rb_ident($DB_CONTROL);
  $inventoryName = rb_ident($DB_INVENTORY);
  $control = new PDO("mysql:host={$DB_HOST};dbname={$DB_CONTROL};charset=utf8mb4", $DB_USER, $DB_PASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
  $inventory = new PDO("mysql:host={$DB_HOST};dbname={$DB_INVENTORY};charset=utf8mb4", $DB_USER, $DB_PASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
  rb_table($control);  // MySQL removes expired entries in the background when its event scheduler is enabled.
  try { $control->exec("CREATE EVENT IF NOT EXISTS system_recycle_bin_cleanup ON SCHEDULE EVERY 1 HOUR DO DELETE FROM system_recycle_bin WHERE expires_at <= NOW()"); } catch (Throwable $ignored) {}
  // Expired payloads are removed on every recycle-bin request.
  $control->exec('DELETE FROM system_recycle_bin WHERE expires_at <= NOW()');
  $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
  if ($method === 'GET' && !empty($_GET['download_sql'])) {
    $downloadId = filter_var($_GET['download_sql'], FILTER_VALIDATE_INT);
    $download = $control->prepare("SELECT id,item_name,payload FROM system_recycle_bin WHERE id=:id AND item_type='inventory_table' AND expires_at>NOW()");
    $download->execute([':id'=>$downloadId]); $saved=$download->fetch();
    $backup=is_array($saved) ? rb_decode_payload($saved) : null;
    if (!is_array($backup) || empty($backup['sql_dump'])) rb_out(['success'=>false,'message'=>'SQL backup is unavailable.'],404);
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.preg_replace('/[^A-Za-z0-9_-]/','_',$saved['item_name']).'.sql"');
    echo $backup['sql_dump']; exit;
  }
  if ($method === 'GET') {
    $rows = $control->query('SELECT id,item_type,source_table,item_name,deleted_at,expires_at,payload FROM system_recycle_bin ORDER BY deleted_at DESC')->fetchAll();
    $activeWorkspaceItems = [];
    foreach ($rows as &$row) {
      $payload = rb_decode_payload($row);
      if (is_array($payload)) { rb_write_workspace_item($row, $payload); $activeWorkspaceItems[(string)$row['id']] = true; }
      $row['details'] = '';
      $row['has_sql'] = false; $row['folder_count'] = 0;
      if (is_array($payload)) {
        $row['has_sql'] = !empty($payload['sql_dump']);
        $row['folder_count'] = is_array($payload['folder_files'] ?? null) ? count($payload['folder_files']) : 0;
        if ($row['item_type'] === 'inventory_record') {
          $bits = [];
          foreach (['ref_no','asset_code','name','model','serial_number','job_number'] as $key) if (!empty($payload[$key])) $bits[] = $key . ': ' . $payload[$key];
          $row['details'] = implode(' · ', array_slice($bits, 0, 3));
        } elseif ($row['item_type'] === 'navigation' || $row['item_type'] === 'inventory_table') {
          $row['details'] = !empty($payload['table_name']) ? 'Table: ' . $payload['table_name'] : '';
        } elseif ($row['item_type'] === 'inventory_column') {
          $row['details'] = !empty($payload['column_name'])
            ? 'Column: ' . (string)($payload['table_name'] ?? $row['source_table']) . '.' . $payload['column_name']
            : '';
        }
      }
      unset($row['payload']);
    }
    foreach (glob(__DIR__ . '/../.recycle-bin/*.json') ?: [] as $file) {
      $fileId = basename($file, '.json');
      if (ctype_digit($fileId) && !isset($activeWorkspaceItems[$fileId])) {
        @unlink($file);
      } elseif (preg_match('/^payload_([1-9][0-9]*)$/', $fileId, $match)
        && !isset($activeWorkspaceItems[$match[1]])) {
        @unlink($file);
      }
    }
    rb_out(['success'=>true,'items'=>$rows]);
  }
  if ($method !== 'POST') rb_out(['success'=>false,'message'=>'Method not allowed'],405);
  $data = json_decode(file_get_contents('php://input'), true) ?: [];
  $action = $data['action'] ?? '';
  $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
  if ($action === 'empty') {
    $itemCount = (int)$control->query('SELECT COUNT(*) FROM system_recycle_bin')->fetchColumn();
    $control->exec('DELETE FROM system_recycle_bin');
    foreach (glob(__DIR__ . '/../.recycle-bin/*.json') ?: [] as $file) @unlink($file);
    record_user_activity('Emptied recycle bin', 'recycle bin', ['deleted_items' => $itemCount]);
    rb_out(['success'=>true,'message'=>'Recycle bin emptied.']);
  }
  if ($action === 'restore_batch') {
    $restoreAll = ($data['all'] ?? false) === true;
    $ids = $restoreAll ? null : ($data['ids'] ?? null);
    if (!$restoreAll && (!is_array($ids) || !$ids)) rb_out(['success'=>false,'message'=>'Select at least one item to restore.'],400);
    if (function_exists('set_time_limit')) set_time_limit(0);
    $requestedCount = null;
    if ($ids === null) {
      $items = $control->query("SELECT id,item_type FROM system_recycle_bin WHERE expires_at>NOW() ORDER BY CASE item_type WHEN 'inventory_table' THEN 0 WHEN 'inventory_column' THEN 1 WHEN 'navigation' THEN 2 WHEN 'inventory_record' THEN 3 ELSE 4 END,id")->fetchAll();
    } else {
      $validIds = [];
      foreach ($ids as $value) {
        $value = filter_var($value, FILTER_VALIDATE_INT);
        if ($value === false || $value < 1) rb_out(['success'=>false,'message'=>'The selected item list is invalid.'],400);
        $validIds[$value] = $value;
      }
      if (count($validIds) > 10000) rb_out(['success'=>false,'message'=>'Select no more than 10,000 items at a time.'],400);
      $requestedCount = count($validIds);
      $marks = implode(',', array_fill(0, count($validIds), '?'));
      $stmt = $control->prepare("SELECT id,item_type FROM system_recycle_bin WHERE expires_at>NOW() AND id IN ({$marks}) ORDER BY CASE item_type WHEN 'inventory_table' THEN 0 WHEN 'inventory_column' THEN 1 WHEN 'navigation' THEN 2 WHEN 'inventory_record' THEN 3 ELSE 4 END,id");
      $stmt->execute(array_values($validIds));
      $items = $stmt->fetchAll();
    }
    $fetchItem = $control->prepare('SELECT * FROM system_recycle_bin WHERE id=:id AND expires_at>NOW()');
    $results = ['restored'=>0,'failed'=>[],'missing'=>$requestedCount === null ? 0 : max(0, $requestedCount - count($items)),'types'=>[]];
    $undoChanges = [];
    $undoItems = [];
    $undoSupported = true;
    foreach ($items as $candidate) {
      $fetchItem->execute([':id'=>(int)$candidate['id']]);
      $item = $fetchItem->fetch();
      if (!$item) {
        $results['missing']++;
        continue;
      }
      try {
        $savedRow = $item['item_type'] === 'inventory_record'
          ? json_decode((string)$item['payload'], true)
          : null;
        rb_restore_item($control, $inventory, $item);
        $results['restored']++;
        $results['types'][$item['item_type']] = ($results['types'][$item['item_type']] ?? 0) + 1;
        if ($item['item_type'] !== 'inventory_record' || !is_array($savedRow)) {
          $undoSupported = false;
        } else {
          $restoredRecord = rb_restored_record($inventory, (string)$item['source_table'], $savedRow);
          if (!$restoredRecord) {
            $undoSupported = false;
          } else {
            $undoChanges[] = [
              'table' => (string)$item['source_table'],
              'key' => $restoredRecord['key'],
              'before' => null,
              'after' => $restoredRecord['row']
            ];
            $undoItems[] = [
              'id' => (int)$item['id'],
              'item_type' => (string)$item['item_type'],
              'source_table' => $item['source_table'],
              'item_name' => (string)$item['item_name'],
              'payload' => (string)$item['payload'],
              'deleted_at' => (string)$item['deleted_at'],
              'expires_at' => (string)$item['expires_at']
            ];
          }
        }
      } catch (Throwable $error) {
        $results['failed'][] = [
          'id'=>(int)$item['id'],
          'item'=>(string)$item['item_name'],
          'message'=>$error->getMessage()
        ];
      }
    }
    if ($results['restored'] > 0) {
      try {
        record_user_activity('Restored recycle-bin items', 'recycle bin', [
          'restored_items'=>$results['restored'],
          'failed_items'=>count($results['failed']),
          'item_types'=>$results['types']
        ]);
      } catch (Throwable $error) {
        error_log('Recycle-bin batch restore activity logging failed: ' . $error->getMessage());
        $results['warning'] = 'Items were restored, but the activity log could not be saved.';
      }
    }
    $undoToken = null;
    if ($results['restored'] > 0 && $undoSupported && count($undoChanges) === $results['restored']) {
      try {
        $undoToken = asset_undo_store($control, (int)$_SESSION['uid'], $undoChanges, [
          'restore_items' => $undoItems
        ]);
      } catch (Throwable $error) {
        error_log('Recycle-bin restore completed but Undo could not be recorded: ' . $error->getMessage());
        $results['warning'] = 'Items were restored, but the Undo action could not be saved.';
      }
    } elseif ($results['restored'] > 0 && !$undoSupported) {
      $results['warning'] = 'Undo is available only when every restored item is an inventory record.';
    }
    rb_out(['success'=>true,'message'=>'Batch restore finished.','undo_token'=>$undoToken] + $results);
  }
  if (!in_array($action, ['restore','delete'], true) || !$id) rb_out(['success'=>false,'message'=>'Invalid recycle-bin request.'],400);
  $stmt = $control->prepare('SELECT * FROM system_recycle_bin WHERE id=:id AND expires_at>NOW()'); $stmt->execute([':id'=>$id]); $item = $stmt->fetch();
  if (!$item) rb_out(['success'=>false,'message'=>'This item has expired or is no longer in the recycle bin.'],404);
  if ($action === 'delete') {
    $del=$control->prepare('DELETE FROM system_recycle_bin WHERE id=:id'); $del->execute([':id'=>$id]);
    rb_remove_workspace_item($id);
    record_user_activity('Permanently deleted recycle-bin item', 'recycle bin', [
      'item' => $item['item_name'],
      'type' => $item['item_type'],
      'table' => $item['source_table']
    ]);
    rb_out(['success'=>true,'message'=>'Item permanently deleted.']);
  }
  rb_restore_item($control, $inventory, $item);
  record_user_activity('Restored recycle-bin item', 'recycle bin', [
    'item' => $item['item_name'],
    'type' => $item['item_type'],
    'table' => $item['source_table']
  ]);
  $undoToken = null;
  $undoWarning = '';
  if ($item['item_type'] === 'inventory_record') {
    try {
      $savedRow = json_decode((string)$item['payload'], true);
      if (is_array($savedRow)) {
        $restoredRecord = rb_restored_record($inventory, (string)$item['source_table'], $savedRow);
        if ($restoredRecord) {
          $undoToken = asset_undo_store($control, (int)$_SESSION['uid'], [[
            'table' => (string)$item['source_table'],
            'key' => $restoredRecord['key'],
            'before' => null,
            'after' => $restoredRecord['row']
          ]], [
            'restore_items' => [[
              'id' => (int)$item['id'],
              'item_type' => (string)$item['item_type'],
              'source_table' => $item['source_table'],
              'item_name' => (string)$item['item_name'],
              'payload' => (string)$item['payload'],
              'deleted_at' => (string)$item['deleted_at'],
              'expires_at' => (string)$item['expires_at']
            ]]
          ]);
        }
      }
    } catch (Throwable $undoError) {
      error_log('Recycle-bin record was restored but Undo could not be saved: ' . $undoError->getMessage());
      $undoWarning = ' Undo could not be saved for this restore.';
    }
  }
  rb_out(['success'=>true,'message'=>'Item restored.' . $undoWarning,'undo_token'=>$undoToken]);
} catch (Throwable $e) { rb_out(['success'=>false,'message'=>$e->getMessage()],500); }
