<?php
require_once __DIR__ . '/../../connector/auth_guard.php';
require_once __DIR__ . '/../../connector/activity_logger.php';
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../connector/config.php';

// Safe name sanitizers
function sanitize_identifier($v) {
    // allow letters, numbers and underscore only
    return preg_replace('/[^a-zA-Z0-9_]/', '', $v);
}

try {
    // PDO connections (use control_panel_db for sidebar navigation storage)
    $sidebars = new PDO("mysql:host={$DB_HOST};dbname={$DB_CONTROL};charset=utf8", $DB_USER, $DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $inventory = new PDO("mysql:host={$DB_HOST};dbname={$DB_INVENTORY};charset=utf8", $DB_USER, $DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "DB connect error: " . $e->getMessage()]);
    exit;
}

// Helper to output and exit
function out($data) {
    echo json_encode($data);
    exit;
}

function scaffold_inventory_module($table) {
    $templateDir = __DIR__ . '/format';
    if (!is_dir($templateDir)) {
        throw new RuntimeException('The inventory module template folder is missing.');
    }

    // All inventory navigation is routed through the shared format module in
    // sidebar.html. Avoid copying UI, JavaScript, CSS, and endpoint files into
    // per-table folders, where they can drift from the shared implementation.
    return [
        'shared_module' => '../settings/format/module.php?table=' . rawurlencode($table),
        'shared_files' => array_values(array_filter(scandir($templateDir), static function ($file) use ($templateDir) {
            return $file !== '.' && $file !== '..' && is_file($templateDir . '/' . $file);
        }))
    ];
}
function inventory_table_exists(PDO $inventory, $table) {
    $stmt = $inventory->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name');
    $stmt->execute([':table_name' => $table]);
    return intval($stmt->fetchColumn()) > 0;
}

function archive_inventory_folder($table) {
    $root = realpath(__DIR__ . '/..');
    $candidate = $root ? realpath($root . DIRECTORY_SEPARATOR . $table) : false;
    if (!$root || !$candidate || dirname($candidate) !== $root || !is_dir($candidate)) return [];
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($candidate, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->isLink()) continue;
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($candidate) + 1));
        $contents = file_get_contents($file->getPathname());
        if ($contents === false) throw new RuntimeException('Could not archive module folder file: ' . $relative);
        $files[] = ['path'=>$relative,'content'=>base64_encode($contents)];
    }
    return $files;
}
function remove_inventory_folder($table) {
    $root = realpath(__DIR__ . '/..');
    $candidate = $root ? realpath($root . DIRECTORY_SEPARATOR . $table) : false;
    if (!$root || !$candidate || dirname($candidate) !== $root || !is_dir($candidate)) return;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($candidate, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        if ($entry->isLink()) { @unlink($entry->getPathname()); continue; }
        if ($entry->isDir()) { @rmdir($entry->getPathname()); } else { @unlink($entry->getPathname()); }
    }
    @rmdir($candidate);
}
function build_sql_dump(PDO $db, $table, $createSql, array $rows) {
    $safe = '`' . sanitize_identifier($table) . '`';
    $sql = "SET FOREIGN_KEY_CHECKS=0;\nDROP TABLE IF EXISTS {$safe};\n{$createSql};\n";
    if ($rows) {
        $columns = array_keys($rows[0]);
        $quotedColumns = implode(', ', array_map(static function($column) { return '`' . str_replace('`','``',$column) . '`'; }, $columns));
        foreach ($rows as $row) {
            $values = [];
            foreach ($columns as $column) $values[] = $row[$column] === null ? 'NULL' : $db->quote((string)$row[$column]);
            $sql .= "INSERT INTO {$safe} ({$quotedColumns}) VALUES (" . implode(', ', $values) . ");\n";
        }
    }
    return $sql . "SET FOREIGN_KEY_CHECKS=1;\n";
}
function navigation_entry_exists(PDO $sidebars, $table) {
    $stmt = $sidebars->prepare('SELECT COUNT(*) FROM navigation_menu WHERE table_name = :table_name');
    $stmt->execute([':table_name' => $table]);
    return intval($stmt->fetchColumn()) > 0;
}
function ensure_system_ui_settings(PDO $sidebars) {
    $sidebars->exec(
        'CREATE TABLE IF NOT EXISTS system_ui_settings ('
        . 'setting_key VARCHAR(100) NOT NULL PRIMARY KEY, '
        . 'setting_value VARCHAR(255) NOT NULL'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if (isset($_GET['get_assistant_visibility'])) {
        try {
            ensure_system_ui_settings($sidebars);
            $stmt = $sidebars->prepare('SELECT setting_value FROM system_ui_settings WHERE setting_key = ? LIMIT 1');
            $stmt->execute(['hdjv_assistant_visible']);
            $value = $stmt->fetchColumn();
            out(['success' => true, 'visible' => $value === false ? true : $value === '1']);
        } catch (Throwable $error) {
            error_log('Could not load HDJV Assistant visibility: ' . $error->getMessage());
            http_response_code(500);
            out(['success' => false, 'message' => 'Could not load the HDJV Assistant setting.']);
        }
    }

    // GET ?get_config=unique_id
    if (!empty($_GET['get_config'])) {
        $uid = $_GET['get_config'];
        $stmt = $sidebars->prepare('SELECT unique_id, table_name, icon, sort_order, is_visible FROM navigation_menu WHERE unique_id = :uid LIMIT 1');
        $stmt->execute([':uid' => $uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) out(["success" => false, "message" => "Not found"]);
        out($row);
    }

    // GET ?get_headers=table_name
    if (!empty($_GET['get_headers'])) {
        $table = sanitize_identifier($_GET['get_headers']);
        if (empty($table)) out([]);
        try {
            // verify table exists
            $res = $inventory->query("SHOW TABLES LIKE '" . $table . "'");
            $exists = $res->fetchColumn();
            if (!$exists) {
                out([]);
            }
            $cols = $inventory->query("DESCRIBE `" . $table . "`")->fetchAll(PDO::FETCH_ASSOC);
            $out = [];
            foreach ($cols as $c) {
                // simplify the type
                $type = strtolower($c['Type']);
                if (strpos($type, '(') !== false) $type = substr($type, 0, strpos($type, '('));
                $out[] = ["field" => $c['Field'], "type" => strtoupper($type)];
            }
            out($out);
        } catch (PDOException $e) {
            out([]);
        }
    }

    // Default: return navigation menu rows
    $stmt = $sidebars->query('SELECT unique_id, table_name, icon, sort_order, is_visible FROM navigation_menu ORDER BY sort_order ASC');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    out($rows);
}

// Handle POST actions
$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?? [];

$action = $data['action'] ?? '';

try {
    if ($action === 'update_assistant_visibility') {
        $visible = filter_var($data['visible'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($visible === null) {
            out(['success' => false, 'message' => 'Choose whether the HDJV Assistant should be visible.']);
        }
        ensure_system_ui_settings($sidebars);
        $stmt = $sidebars->prepare(
            'INSERT INTO system_ui_settings (setting_key, setting_value) VALUES (?, ?) '
            . 'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $stmt->execute(['hdjv_assistant_visible', $visible ? '1' : '0']);
        record_user_activity(
            $visible ? 'Enabled HDJV Assistant' : 'Disabled HDJV Assistant',
            'settings'
        );
        out([
            'success' => true,
            'visible' => $visible,
            'message' => $visible ? 'HDJV Assistant is visible.' : 'HDJV Assistant is hidden.'
        ]);
    }

    if ($action === 'delete') {
        $uid = $data['unique_id'] ?? '';
        if (!$uid) out(['success'=>false,'message'=>'Missing unique_id']);
        $stmt=$sidebars->prepare('SELECT * FROM navigation_menu WHERE unique_id=:uid LIMIT 1'); $stmt->execute([':uid'=>$uid]); $nav=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$nav) out(['success'=>false,'message'=>'Not found']);
        $drop=(int)($data['drop_table'] ?? 0)===1;
        $table=sanitize_identifier($nav['table_name'] ?? '');
        $payload=$nav; $type='navigation';
        $folderFiles = [];
        if ($drop && $table && inventory_table_exists($inventory,$table)) {
            $create=$inventory->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM);
            $rows=$inventory->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);
            $folderFiles=archive_inventory_folder($table);
            $payload=['table_name'=>$table,'navigation'=>$nav,'create_sql'=>$create[1] ?? null,'rows'=>$rows,'sql_dump'=>build_sql_dump($inventory,$table,$create[1] ?? '',$rows),'folder_files'=>$folderFiles]; $type='inventory_table';
        }
        $sidebars->exec("CREATE TABLE IF NOT EXISTS system_recycle_bin (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,item_type VARCHAR(32) NOT NULL,source_table VARCHAR(255) NULL,item_name VARCHAR(255) NOT NULL DEFAULT '',payload LONGTEXT NOT NULL,deleted_at DATETIME NOT NULL,expires_at DATETIME NOT NULL,INDEX idx_recycle_expires(expires_at),INDEX idx_recycle_type(item_type)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $sidebars->beginTransaction();
        $mirrorFile = null;
        try {
            $ins=$sidebars->prepare('INSERT INTO system_recycle_bin (item_type,source_table,item_name,payload,deleted_at,expires_at) VALUES (:type,:table,:name,:payload,NOW(),DATE_ADD(NOW(), INTERVAL 30 DAY))');
            $ins->execute([':type'=>$type,':table'=>$table ?: null,':name'=>$table ?: 'Navigation entry',':payload'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)]);
            $entryId=(int)$sidebars->lastInsertId();
            $recycleDir=__DIR__ . '/../.recycle-bin';
            if (!is_dir($recycleDir) && !mkdir($recycleDir,0775,true) && !is_dir($recycleDir)) throw new RuntimeException('Could not create the workspace recycle-bin folder.');
            $mirrorFile=$recycleDir . DIRECTORY_SEPARATOR . $entryId . '.json';
            $mirror=['id'=>$entryId,'item_type'=>$type,'source_table'=>$table ?: null,'item_name'=>$table ?: 'Navigation entry','deleted_at'=>date('Y-m-d H:i:s'),'expires_at'=>date('Y-m-d H:i:s',time()+30*86400),'payload'=>$payload];
            if (file_put_contents($mirrorFile,json_encode($mirror,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE),LOCK_EX)===false) throw new RuntimeException('Could not write the workspace recycle-bin backup.');
            $del=$sidebars->prepare('DELETE FROM navigation_menu WHERE unique_id=:uid'); $del->execute([':uid'=>$uid]);
            if ($drop && $table) $inventory->exec('DROP TABLE IF EXISTS `'.$table.'`');
            $sidebars->commit();
            if ($drop && $folderFiles) remove_inventory_folder($table);
        } catch (Throwable $e) { if($sidebars->inTransaction())$sidebars->rollBack(); if($mirrorFile && is_file($mirrorFile)) @unlink($mirrorFile); throw $e; }
        record_user_activity($drop ? 'Deleted inventory table and navigation button' : 'Deleted navigation button', 'settings', [
            'table' => $table,
            'removed_table' => $drop
        ]);
        out(['success'=>true,'message'=>$drop?'Component, table SQL/data, and matching folder archived in the recycle bin.':'Component moved to the recycle bin.']);
    }
    if ($action === 'sync') {
        // Ensure every table in `inventory` has a corresponding navigation_menu entry
        $tablesStmt = $inventory->query("SHOW TABLES");
        $tables = array_values(array_filter(
            $tablesStmt->fetchAll(PDO::FETCH_COLUMN),
            static function ($table): bool {
                return !inventory_is_system_table((string)$table);
            }
        ));
        $added = 0;

        // compute starting sort order
        $row = $sidebars->query('SELECT COALESCE(MAX(sort_order),0) AS max_order FROM navigation_menu')->fetch(PDO::FETCH_ASSOC);
        $nextOrder = intval($row['max_order']) + 1;

        $checkStmt = $sidebars->prepare('SELECT COUNT(*) FROM navigation_menu WHERE table_name = :t');
        $insertStmt = $sidebars->prepare('INSERT INTO navigation_menu (unique_id, table_name, icon, sort_order, is_visible) VALUES (:u, :t, :i, :s, 1)');

        foreach ($tables as $t) {
            $table = sanitize_identifier($t);
            if (empty($table)) continue;
            $checkStmt->execute([':t' => $table]);
            $exists = $checkStmt->fetchColumn();
            if (intval($exists) === 0) {
                $uid = uniqid('nm_', true);
                $insertStmt->execute([':u' => $uid, ':t' => $table, ':i' => 'grid', ':s' => $nextOrder]);
                $nextOrder++;
                $added++;
            }
        }

        // Optional pruning: remove sidebar entries not present in inventory (if requested)
        if (!empty($data['prune'])) {
            $validTables = array_values(array_filter(array_map('sanitize_identifier', $tables)));
            $keep = array_fill_keys($validTables, true);
            $orphans = array_filter($sidebars->query('SELECT * FROM navigation_menu')->fetchAll(PDO::FETCH_ASSOC), static function($nav) use ($keep) { return !isset($keep[$nav['table_name']]); });
            if ($orphans) {
                $sidebars->exec("CREATE TABLE IF NOT EXISTS system_recycle_bin (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,item_type VARCHAR(32) NOT NULL,source_table VARCHAR(255) NULL,item_name VARCHAR(255) NOT NULL DEFAULT '',payload LONGTEXT NOT NULL,deleted_at DATETIME NOT NULL,expires_at DATETIME NOT NULL,INDEX idx_recycle_expires(expires_at),INDEX idx_recycle_type(item_type)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $save = $sidebars->prepare("INSERT INTO system_recycle_bin (item_type,source_table,item_name,payload,deleted_at,expires_at) VALUES ('navigation',NULL,:name,:payload,NOW(),DATE_ADD(NOW(), INTERVAL 30 DAY))");
                $remove = $sidebars->prepare('DELETE FROM navigation_menu WHERE unique_id=:uid');
                foreach ($orphans as $nav) { $save->execute([':name'=>$nav['table_name'],':payload'=>json_encode($nav,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)]); $remove->execute([':uid'=>$nav['unique_id']]); }
            }
        }

        if ($added > 0 || !empty($orphans)) {
            record_user_activity('Synchronized navigation buttons', 'settings', [
                'added' => $added,
                'removed' => count($orphans ?? [])
            ]);
        }
        out(["success" => true, "message" => "Sync complete", "added" => $added, "total_inventory_tables" => count($tables)]);
    }

    if ($action === 'update_configuration') {
        $uid = $data['unique_id'] ?? '';
        if (empty($uid)) out(["success" => false, "message" => "Missing unique_id"]);

        // fetch existing
        $stmt = $sidebars->prepare('SELECT * FROM navigation_menu WHERE unique_id = :uid LIMIT 1');
        $stmt->execute([':uid' => $uid]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) out(["success" => false, "message" => "Not found"]);

        $fields = [];
        $params = [':u' => $uid];

        if (isset($data['table_name'])) {
            $fields[] = 'table_name = :t';
            $params[':t'] = sanitize_identifier($data['table_name']);
        }
        if (isset($data['icon'])) {
            $fields[] = 'icon = :i';
            $params[':i'] = preg_replace('/[^a-zA-Z0-9_\-]/','', $data['icon']);
        }
        if (isset($data['is_visible'])) {
            $fields[] = 'is_visible = :v';
            $params[':v'] = intval($data['is_visible']);
        }

        if (count($fields) === 0) {
            out(["success" => false, "message" => "No fields to update"]);
        }

        $sql = 'UPDATE navigation_menu SET ' . implode(', ', $fields) . ' WHERE unique_id = :u';
        $upd = $sidebars->prepare($sql);
        $upd->execute($params);
        record_user_activity('Modified navigation button', 'settings', [
            'table' => $existing['table_name'],
            'changes' => array_values(array_filter(array_keys($data), static fn($key) => in_array($key, ['table_name', 'icon', 'is_visible'], true)))
        ]);
        out(["success" => true, "message" => "Configuration updated"]);
    }

    if ($action === 'add_header_row') {
        $table_name = sanitize_identifier($data['table_name'] ?? '');
        $field = sanitize_identifier($data['field_name'] ?? '');
        $ftype = strtoupper($data['field_type'] ?? 'VARCHAR');
        if (!$table_name || !$field) out(["success" => false, "message" => "Invalid table or field name"]);

        $typeMap = [
            'VARCHAR' => 'VARCHAR(255)',
            'TEXT' => 'TEXT',
            'INT' => 'INT',
            'DATE' => 'DATE',
            'TIMESTAMP' => 'TIMESTAMP'
        ];
        $sqlType = $typeMap[$ftype] ?? 'VARCHAR(255)';

        // check if column exists
        $colCheck = $inventory->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c");
        $colCheck->execute([':t' => $table_name, ':c' => $field]);
        $exists = intval($colCheck->fetchColumn()) > 0;
        if ($exists) {
            out(["success" => false, "message" => "Column already exists"]);
        }

        // perform alter
        $inventory->exec("ALTER TABLE `" . $table_name . "` ADD COLUMN `" . $field . "` " . $sqlType . " NULL");
        record_user_activity('Added inventory table column', 'settings', [
            'table' => $table_name,
            'column' => $field,
            'type' => $ftype
        ]);
        out(["success" => true, "message" => "Header added"]);
    }

    if ($action === 'create_new_table') {
        $table = sanitize_identifier($data['table_name'] ?? '');
        $headers = $data['headers'] ?? [];
        if (!$table || !is_array($headers) || count($headers) === 0) out(["success" => false, "message" => "Invalid payload"]);
        if (inventory_table_exists($inventory, $table)) out(["success" => false, "message" => "An inventory table with this name already exists"]);
        if (navigation_entry_exists($sidebars, $table)) out(["success" => false, "message" => "A navigation button with this name already exists"]);

        // Build CREATE TABLE
        $colsSql = [];
        foreach ($headers as $h) {
            $field = sanitize_identifier($h['field'] ?? '');
            $type = strtoupper($h['type'] ?? 'VARCHAR');
            $map = ['VARCHAR' => 'VARCHAR(255)', 'TEXT' => 'TEXT', 'INT' => 'INT', 'DATE' => 'DATE', 'TIMESTAMP' => 'TIMESTAMP'];
            $sqlType = $map[$type] ?? 'VARCHAR(255)';
            if (!$field) continue;
            $colsSql[] = "`$field` $sqlType NULL";
        }
        if (count($colsSql) === 0) out(["success" => false, "message" => "No valid columns"]);

        $createSql = "CREATE TABLE IF NOT EXISTS `" . $table . "` ( `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY, " . implode(', ', $colsSql) . ", `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ) ENGINE=InnoDB DEFAULT CHARSET=utf8;";
        $inventory->exec($createSql);

        try {
            $scaffoldedFiles = scaffold_inventory_module($table);
        } catch (Throwable $e) {
            out(["success" => false, "message" => "The table was created, but the shared module could not be prepared: " . $e->getMessage(), "table_created" => true]);
        }

        // Insert into navigation_menu
        $uid = uniqid('nm_', true);
        // compute next sort_order
        $s = $sidebars->query('SELECT COALESCE(MAX(sort_order),0)+1 AS next_order FROM navigation_menu')->fetch(PDO::FETCH_ASSOC)['next_order'];
        $stmt = $sidebars->prepare('INSERT INTO navigation_menu (unique_id, table_name, icon, sort_order, is_visible) VALUES (:u, :t, :i, :s, 1)');
        $stmt->execute([':u' => $uid, ':t' => $table, ':i' => 'grid', ':s' => $s]);

        record_user_activity('Created inventory table and navigation button', 'settings', [
            'table' => $table,
            'columns' => array_values(array_map(static fn($header) => sanitize_identifier($header['field'] ?? ''), $headers))
        ]);
        out(["success" => true, "message" => "Inventory table and navigation button created using the shared format module", "module_url" => $scaffoldedFiles["shared_module"], "shared_files" => $scaffoldedFiles["shared_files"]]);
    }

    if ($action === 'add_nav_only') {
        $table = sanitize_identifier($data['table_name'] ?? '');
        if (!$table) out(["success" => false, "message" => "Invalid table name"]);
        if (!inventory_table_exists($inventory, $table)) out(["success" => false, "message" => "The inventory table does not exist. Create it first or enter an existing table name."]);
        if (navigation_entry_exists($sidebars, $table)) out(["success" => false, "message" => "A navigation button with this name already exists"]);

        try {
            $scaffoldedFiles = scaffold_inventory_module($table);
        } catch (Throwable $e) {
            out(["success" => false, "message" => "Could not prepare the shared module: " . $e->getMessage()]);
        }

        $uid = uniqid('nm_', true);
        $s = $sidebars->query('SELECT COALESCE(MAX(sort_order),0)+1 AS next_order FROM navigation_menu')->fetch(PDO::FETCH_ASSOC)['next_order'];
        $stmt = $sidebars->prepare('INSERT INTO navigation_menu (unique_id, table_name, icon, sort_order, is_visible) VALUES (:u, :t, :i, :s, 1)');
        $stmt->execute([':u' => $uid, ':t' => $table, ':i' => 'grid', ':s' => $s]);
        record_user_activity('Added navigation button', 'settings', ['table' => $table]);
        out(["success" => true, "message" => "Navigation button created using the shared format module", "module_url" => $scaffoldedFiles["shared_module"], "shared_files" => $scaffoldedFiles["shared_files"]]);
    }

    if ($action === 'restore') {
        $uid = $data['unique_id'] ?? '';
        if (empty($uid)) out(["success" => false, "message" => "Missing unique_id"]);
        // fetch from trash
        $stmt = $sidebars->prepare('SELECT unique_id, table_name, icon, sort_order, is_visible FROM navigation_trash WHERE unique_id = :u LIMIT 1');
        $stmt->execute([':u' => $uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) out(["success" => false, "message" => "Not found in trash"]);

        // insert back to navigation_menu (avoid duplicates)
        $check = $sidebars->prepare('SELECT COUNT(*) FROM navigation_menu WHERE unique_id = :u');
        $check->execute([':u' => $uid]);
        if (intval($check->fetchColumn()) === 0) {
            $s = $sidebars->query('SELECT COALESCE(MAX(sort_order),0)+1 AS next_order FROM navigation_menu')->fetch(PDO::FETCH_ASSOC)['next_order'];
            $ins = $sidebars->prepare('INSERT INTO navigation_menu (unique_id, table_name, icon, sort_order, is_visible) VALUES (:u, :t, :i, :s, :v)');
            $ins->execute([':u' => $row['unique_id'], ':t' => $row['table_name'], ':i' => $row['icon'], ':s' => $s, ':v' => $row['is_visible']]);
        }

        // remove from trash
        $del = $sidebars->prepare('DELETE FROM navigation_trash WHERE unique_id = :u');
        $del->execute([':u' => $uid]);
        record_user_activity('Restored navigation button', 'settings', ['table' => $row['table_name']]);
        out(["success" => true, "message" => "Restored"]);
    }

    if ($action === 'purge_trash') {
        $days = intval($data['days'] ?? 30);
        if ($days <= 0) $days = 30;
        // remove older entries from trash
        try {
            $sidebars->exec("DELETE FROM navigation_trash WHERE deleted_at < DATE_SUB(NOW(), INTERVAL $days DAY)");
            out(["success" => true, "message" => "Purged entries older than $days days"]);
        } catch (PDOException $e) {
            out(["success" => false, "message" => "Purge failed: " . $e->getMessage()]);
        }
    }

    if ($action === 'insert_row') {
        $table = sanitize_identifier($data['table'] ?? '');
        $row = $data['row'] ?? [];
        if (!$table || !is_array($row) || count($row) === 0) out(["success" => false, "message" => "Invalid payload"]);

        // verify table exists
        $res = $inventory->query("SHOW TABLES LIKE '" . $table . "'");
        if (!$res->fetchColumn()) out(["success" => false, "message" => "Table not found"]);

        // Get allowed columns for this table
        $colsStmt = $inventory->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t");
        $colsStmt->execute([':t' => $table]);
        $allowed = $colsStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!$allowed) out(["success" => false, "message" => "Failed to inspect table columns"]);

        // Filter incoming row keys to allowed columns and ignore auto-increment primary keys and timestamps with default
        $insertCols = [];
        $insertVals = [];
        $params = [];
        foreach ($row as $k => $v) {
            $col = sanitize_identifier($k);
            if (!$col) continue;
            if (!in_array($col, $allowed)) continue;
            // skip id primary if it's named id
            if (strtolower($col) === 'id') continue;
            $insertCols[] = "`$col`";
            $insertVals[] = ":$col";
            $params[":".$col] = $v;
        }

        if (count($insertCols) === 0) out(["success" => false, "message" => "No valid columns to insert"]);

        $sql = "INSERT INTO `" . $table . "` (" . implode(',', $insertCols) . ") VALUES (" . implode(',', $insertVals) . ")";
        $stmt = $inventory->prepare($sql);
        try {
            $stmt->execute($params);
            record_user_activity('Added asset record', 'inventory', [
                'table' => $table,
                'record_id' => $inventory->lastInsertId()
            ]);
            out(["success" => true, "message" => "Row inserted", "insert_id" => $inventory->lastInsertId()]);
        } catch (PDOException $e) {
            out(["success" => false, "message" => "Insert failed: " . $e->getMessage()]);
        }
    }

    out(["success" => false, "message" => "Unknown action"]);

} catch (PDOException $e) {
    http_response_code(500);
    out(["success" => false, "message" => $e->getMessage()]);
}

?>
