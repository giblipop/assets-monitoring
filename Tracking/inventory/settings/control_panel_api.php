<?php
require_once __DIR__ . '/../../connector/auth_guard.php';
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

$require_path = __DIR__ . '/../../connector/config.php';
if (file_exists($require_path)) {
    require_once $require_path;
} else {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Missing config.php']);
    exit;
}

function sanitize($v){ return preg_replace('/[^a-zA-Z0-9_]/','',$v); }
function out($d){ echo json_encode($d); exit; }

function control_archive_table_folder(string $table): array
{
    $root = realpath(__DIR__ . '/..');
    $candidate = $root ? realpath($root . DIRECTORY_SEPARATOR . $table) : false;
    if (!$root || !$candidate || dirname($candidate) !== $root || !is_dir($candidate)) {
        return [];
    }
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($candidate, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->isLink()) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($candidate) + 1));
        $contents = file_get_contents($file->getPathname());
        if ($contents === false) {
            throw new RuntimeException('Could not archive the existing inventory table folder.');
        }
        $files[] = ['path' => $relative, 'content' => base64_encode($contents)];
    }
    return $files;
}

function control_remove_table_folder(string $table): void
{
    $root = realpath(__DIR__ . '/..');
    $candidate = $root ? realpath($root . DIRECTORY_SEPARATOR . $table) : false;
    if (!$root || !$candidate || dirname($candidate) !== $root || !is_dir($candidate)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($candidate, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isLink() || $entry->isFile()) {
            if (!unlink($entry->getPathname())) {
                throw new RuntimeException('Could not remove the archived inventory table folder.');
            }
        } elseif (!rmdir($entry->getPathname())) {
            throw new RuntimeException('Could not remove the archived inventory table folder.');
        }
    }
    if (!rmdir($candidate)) {
        throw new RuntimeException('Could not remove the archived inventory table folder.');
    }
}

try {
    $sidebars = new PDO("mysql:host={$DB_HOST};dbname={$DB_CONTROL};charset=utf8", $DB_USER, $DB_PASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $inventory = new PDO("mysql:host={$DB_HOST};dbname={$DB_INVENTORY};charset=utf8", $DB_USER, $DB_PASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $sidebars->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $inventory->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo = $sidebars; // compatibility alias
} catch (PDOException $e) {
    http_response_code(500);
    out(['success'=>false,'message'=>'DB connect failed: '.$e->getMessage()]);
}

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'GET'){
    if (!empty($_GET['get_config'])){
        $uid = $_GET['get_config'];
        $stmt = $pdo->prepare('SELECT * FROM navigation_menu WHERE unique_id = :u LIMIT 1');
        $stmt->execute([':u'=>$uid]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        out($r?:[]);
    }
    if (!empty($_GET['get_headers'])){
        $t = sanitize($_GET['get_headers']); if (!$t) out([]);
        try { $cols = $inventory->query("DESCRIBE `".$t."`")->fetchAll(PDO::FETCH_ASSOC); $out=[]; foreach($cols as $c){ $type=strtolower($c['Type']); if(strpos($type,'(')!==false)$type=substr($type,0,strpos($type,'(')); $out[]= ['field'=>$c['Field'],'type'=>strtoupper($type)]; } out($out);} catch(Exception $e){ out([]);}    
    }
    $rows = $pdo->query('SELECT unique_id,table_name,icon,sort_order,is_visible FROM navigation_menu ORDER BY sort_order ASC')->fetchAll(PDO::FETCH_ASSOC);
    out($rows);
}

$raw = file_get_contents('php://input'); $data = json_decode($raw,true)??[]; $action = $data['action']??'';
try{
    if ($action==='update_configuration'){
        $uid = $data['unique_id']??''; if (!$uid) out(['success'=>false,'message'=>'Missing unique_id']);
        $fields=[];$params=[':u'=>$uid];
        if (isset($data['table_name'])){ $fields[]='table_name=:t'; $params[':t']=sanitize($data['table_name']); }
        if (isset($data['icon'])){ $fields[]='icon=:i'; $params[':i']=preg_replace('/[^a-zA-Z0-9_\-]/','',$data['icon']); }
        if (isset($data['is_visible'])){ $fields[]='is_visible=:v'; $params[':v']=intval($data['is_visible']); }
        if (count($fields)===0) out(['success'=>false,'message'=>'No fields']);
        $sql='UPDATE navigation_menu SET '.implode(',',$fields).' WHERE unique_id=:u'; $stmt=$pdo->prepare($sql); $stmt->execute($params); out(['success'=>true,'message'=>'Configuration updated']);
    }

    if ($action==='delete'){
        $uid=$data['unique_id']??''; if(!$uid) out(['success'=>false,'message'=>'Missing unique_id']);
        $stmt=$pdo->prepare('SELECT * FROM navigation_menu WHERE unique_id=:u LIMIT 1'); $stmt->execute([':u'=>$uid]); $nav=$stmt->fetch(PDO::FETCH_ASSOC); if(!$nav)out(['success'=>false,'message'=>'Not found']);
        $table=sanitize($nav['table_name']??''); $drop=(int)($data['drop_table']??0)===1; $type='navigation'; $payload=$nav;
        $exists=$inventory->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t'); $exists->execute([':t'=>$table]);
        if($drop && $table && $exists->fetchColumn()) { $create=$inventory->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM); $rows=$inventory->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC); $payload=['table_name'=>$table,'navigation'=>$nav,'create_sql'=>$create[1]??null,'rows'=>$rows]; $type='inventory_table'; }
        $pdo->exec("CREATE TABLE IF NOT EXISTS system_recycle_bin (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,item_type VARCHAR(32) NOT NULL,source_table VARCHAR(255) NULL,item_name VARCHAR(255) NOT NULL DEFAULT '',payload LONGTEXT NOT NULL,deleted_at DATETIME NOT NULL,expires_at DATETIME NOT NULL,INDEX idx_recycle_expires(expires_at),INDEX idx_recycle_type(item_type)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->beginTransaction();
        try { $ins=$pdo->prepare('INSERT INTO system_recycle_bin (item_type,source_table,item_name,payload,deleted_at,expires_at) VALUES (:type,:table,:name,:payload,NOW(),DATE_ADD(NOW(), INTERVAL 30 DAY))'); $ins->execute([':type'=>$type,':table'=>$table?:null,':name'=>$table?:'Navigation entry',':payload'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)]); $del=$pdo->prepare('DELETE FROM navigation_menu WHERE unique_id=:u'); $del->execute([':u'=>$uid]); if($drop&&$table)$inventory->exec('DROP TABLE IF EXISTS `'.$table.'`'); $pdo->commit(); }
        catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        out(['success'=>true,'message'=>'Moved to the recycle bin. Items are kept for 30 days.']);
    }
    if ($action==='create_new_table'){
        $table = sanitize($data['table_name']??''); $headers = $data['headers']??[]; if (!$table||!is_array($headers)||count($headers)===0) out(['success'=>false,'message'=>'Invalid payload']);
        if (in_array(strtolower($table), ['users','loginhistory','activitylogs','remembereddevices','accountlockouts',
            'passwordresetrequests','accountrecoveryattempts','workspacemembers','workspaceinvitations',
            'workspacepreferences','workspaceinvitationattempts','adminrecyclebin','adminauditlog',
            'navigation_menu','system_recycle_bin'], true)) {
            out(['success'=>false,'message'=>'That name is reserved for a protected system table. Choose another table name.']);
        }
        $cols=[]; foreach($headers as $h){ $f=sanitize($h['field']??''); $t=strtoupper($h['type']??'VARCHAR'); $map=['VARCHAR'=>'VARCHAR(255)','TEXT'=>'TEXT','INT'=>'INT','DATE'=>'DATE','TIMESTAMP'=>'TIMESTAMP']; $sqlType=$map[$t]??'VARCHAR(255)'; if(!$f) continue; $cols[]="`$f` $sqlType NULL"; }
        if (count($cols)===0) out(['success'=>false,'message'=>'No valid columns']);
        $existingType = $inventory->prepare(
            'SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $existingType->execute([$table]);
        $tableType = $existingType->fetchColumn();
        $choice = (string)($data['existing_choice'] ?? '');
        if ($tableType && !in_array($choice, ['use_existing', 'archive_and_create'], true)) {
            out([
                'success'=>true,
                'code'=>'table_exists',
                'message'=>'A database table with this name already exists. Choose whether to use it or archive it before creating a new table.'
            ]);
        }
        if ($tableType && $tableType !== 'BASE TABLE') {
            out(['success'=>false,'message'=>'A view with this name already exists. Rename or remove the view before creating a table.']);
        }

        $navigation = $pdo->prepare('SELECT * FROM navigation_menu WHERE table_name = ? ORDER BY sort_order, unique_id');
        $navigation->execute([$table]);
        $navigationEntries = $navigation->fetchAll(PDO::FETCH_ASSOC);
        $existingNavigation = $navigationEntries[0] ?? null;
        if ($tableType && $choice === 'use_existing') {
            if (!$existingNavigation) {
                $sort = (int)$pdo->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM navigation_menu')->fetchColumn();
                $add = $pdo->prepare(
                    'INSERT INTO navigation_menu (unique_id,table_name,icon,sort_order,is_visible) VALUES (?,?,?,?,1)'
                );
                $add->execute([uniqid('nm_', true), $table, 'grid', $sort]);
            }
            out(['success'=>true,'message'=>'The existing table was kept unchanged and added to navigation.']);
        }

        if ($tableType && $choice === 'archive_and_create') {
            $size = $inventory->prepare(
                'SELECT COALESCE(DATA_LENGTH + INDEX_LENGTH, 0) FROM information_schema.TABLES '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            );
            $size->execute([$table]);
            if ((int)$size->fetchColumn() > 16 * 1024 * 1024) {
                out(['success'=>false,'message'=>'This table is too large for a safe recycle-bin archive. It was not changed. Create a full backup and use the administrator recovery tools.']);
            }
            $triggerCheck = $inventory->prepare(
                'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ?'
            );
            $triggerCheck->execute([$table]);
            $viewCheck = $inventory->prepare(
                'SELECT COUNT(*) FROM information_schema.VIEW_TABLE_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            );
            $viewCheck->execute([$table]);
            $foreignKeyCheck = $inventory->prepare(
                'SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE '
                . 'WHERE REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = ?'
            );
            $foreignKeyCheck->execute([$table]);
            if ((int)$triggerCheck->fetchColumn() > 0 || (int)$viewCheck->fetchColumn() > 0
                || (int)$foreignKeyCheck->fetchColumn() > 0) {
                out(['success'=>false,'message'=>'This table has database dependencies and cannot be safely moved to the recycle bin. Nothing was changed.']);
            }

            $createResult = $inventory->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM);
            if (!isset($createResult[1])) {
                throw new RuntimeException('The existing table definition could not be read. Nothing was changed.');
            }
            $columnsInfo = $inventory->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);
            $selectColumns = [];
            $binaryColumns = [];
            foreach ($columnsInfo as $column) {
                $columnName = (string)$column['Field'];
                if (preg_match('/\b(?:virtual|stored)\s+generated\b/i', (string)($column['Extra'] ?? ''))) {
                    continue;
                }
                $selectColumns[] = '`' . str_replace('`', '``', $columnName) . '`';
                if (preg_match('/\b(binary|varbinary|tinyblob|blob|mediumblob|longblob|bit)\b/i', (string)$column['Type'])) {
                    $binaryColumns[$columnName] = true;
                }
            }
            $rows = $selectColumns
                ? $inventory->query('SELECT ' . implode(', ', $selectColumns) . ' FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC)
                : [];
            foreach ($rows as &$row) {
                foreach ($binaryColumns as $columnName => $_) {
                    if (array_key_exists($columnName, $row) && $row[$columnName] !== null) {
                        $row[$columnName] = ['__asset_backup_binary_base64' => base64_encode((string)$row[$columnName])];
                    }
                }
            }
            unset($row);

            $folderFiles = control_archive_table_folder($table);
            $payload = [
                'table_name' => $table,
                'navigation' => $existingNavigation ?: null,
                'navigation_entries' => $navigationEntries,
                'create_sql' => (string)$createResult[1],
                'rows' => $rows,
                'folder_files' => $folderFiles
            ];
            $pdo->exec("CREATE TABLE IF NOT EXISTS system_recycle_bin (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                item_type VARCHAR(32) NOT NULL,
                source_table VARCHAR(255) NULL,
                item_name VARCHAR(255) NOT NULL DEFAULT '',
                payload LONGTEXT NOT NULL,
                deleted_at DATETIME NOT NULL,
                expires_at DATETIME NOT NULL,
                INDEX idx_recycle_expires (expires_at),
                INDEX idx_recycle_type (item_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($encodedPayload === false) {
                throw new RuntimeException('The existing table could not be safely archived. Nothing was changed.');
            }
            $insertArchive = $pdo->prepare(
                'INSERT INTO system_recycle_bin (item_type,source_table,item_name,payload,deleted_at,expires_at) '
                . 'VALUES (\'inventory_table\', ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY))'
            );
            $insertArchive->execute([$table, $table, $encodedPayload]);

            $inventory->exec('DROP TABLE `'.$table.'`');
            $deleteNavigation = $pdo->prepare('DELETE FROM navigation_menu WHERE table_name = ?');
            $deleteNavigation->execute([$table]);
            if ($folderFiles) {
                control_remove_table_folder($table);
            }
        }

        $create = "CREATE TABLE `".$table."` ( `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY, ".implode(', ',$cols).", `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ) ENGINE=InnoDB DEFAULT CHARSET=utf8;";
        $inventory->exec($create);
        $uid = uniqid('nm_',true); $s = $pdo->query('SELECT COALESCE(MAX(sort_order),0)+1 AS next_order FROM navigation_menu')->fetch(PDO::FETCH_ASSOC)['next_order'];
        $stmt=$pdo->prepare('INSERT INTO navigation_menu (unique_id,table_name,icon,sort_order,is_visible) VALUES (:u,:t,:i,:s,1)'); $stmt->execute([':u'=>$uid,':t'=>$table,':i'=>'grid',':s'=>$s]);
        out(['success'=>true,'message'=>$tableType ? 'The old table was archived in the recycle bin and a new table was created.' : 'Table created and navigation node added']);
    }

    if ($action==='add_header_row'){
        $table = sanitize($data['table_name']??''); $field = sanitize($data['field_name']??''); $ft = strtoupper($data['field_type']??'VARCHAR'); if (!$table||!$field) out(['success'=>false,'message'=>'Invalid']); $map=['VARCHAR'=>'VARCHAR(255)','TEXT'=>'TEXT','INT'=>'INT','DATE'=>'DATE','TIMESTAMP'=>'TIMESTAMP']; $sqlType=$map[$ft]??'VARCHAR(255)';
        $colCheck = $inventory->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c"); $colCheck->execute([':t'=>$table,':c'=>$field]); if (intval($colCheck->fetchColumn())>0) out(['success'=>false,'message'=>'Column exists']);
        $inventory->exec("ALTER TABLE `".$table."` ADD COLUMN `".$field."` " . $sqlType . " NULL"); out(['success'=>true,'message'=>'Header added']);
    }

    out(['success'=>false,'message'=>'Unknown action']);

} catch (PDOException $e){ http_response_code(500); out(['success'=>false,'message'=>$e->getMessage()]); }


?>
