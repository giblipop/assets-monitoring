<?php
require_once __DIR__ . '/../../connector/auth_guard.php';
// Simple migration script to copy existing sidebars and inventory into a new `control_panel` database.
// Run once from the browser or CLI: http://localhost/assets-monitoring/Tracking/inventory/settings/migrate_to_control_panel.php

header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

$host = 'localhost';
$user = 'root';
$pass = '';
$newDb = 'control_panel_db';

try {
    $pdo = new PDO("mysql:host=$host;charset=utf8", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // Create new DB if not exists
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$newDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "Created/ensured database: $newDb\n";

    // Connect to existing sidebars and inventory
    $sidebars = new PDO("mysql:host=$host;dbname=sidebars;charset=utf8", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $inventory = new PDO("mysql:host=$host;dbname=inventory;charset=utf8", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $control = new PDO("mysql:host=$host;dbname=$newDb;charset=utf8", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // 1) Migrate navigation_menu
    echo "Migrating navigation_menu...\n";
    $control->exec("CREATE TABLE IF NOT EXISTS navigation_menu (
        unique_id VARCHAR(64) PRIMARY KEY,
        table_name VARCHAR(255),
        icon VARCHAR(128),
        sort_order INT DEFAULT 0,
        is_visible TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $rows = $sidebars->query('SELECT * FROM navigation_menu')->fetchAll(PDO::FETCH_ASSOC);
    $ins = $control->prepare('REPLACE INTO navigation_menu (unique_id, table_name, icon, sort_order, is_visible, created_at) VALUES (:u,:t,:i,:s,:v,:c)');
    foreach ($rows as $r) {
        $ins->execute([
            ':u' => $r['unique_id'] ?? uniqid('nm_', true),
            ':t' => $r['table_name'] ?? '',
            ':i' => $r['icon'] ?? '',
            ':s' => intval($r['sort_order'] ?? 0),
            ':v' => intval($r['is_visible'] ?? 1),
            ':c' => $r['created_at'] ?? null
        ]);
    }
    echo "Navigation menu migrated: " . count($rows) . " rows\n";

    // 2) Migrate inventory tables: create tables and copy rows
    echo "Migrating inventory tables...\n";
    $tables = $inventory->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        echo "- Processing table: $t\n";
        $create = $inventory->query("SHOW CREATE TABLE `" . $t . "`")->fetch(PDO::FETCH_ASSOC);
        if (!isset($create['Create Table'])) {
            // different key name on some servers
            $create = array_values($create)[1] ?? null;
        } else {
            $create = $create['Create Table'];
        }
        if (!$create) {
            echo "  Skipping: cannot read CREATE TABLE statement\n";
            continue;
        }

        // adjust CREATE TABLE to be safe and use IF NOT EXISTS
        $create = preg_replace('/^CREATE TABLE `?'.preg_quote($t,'/').'`?/i', 'CREATE TABLE IF NOT EXISTS `' . $t . '`', $create);
        // remove DEFINER clauses if any
        $create = preg_replace('/DEFINER=`[^`]+`@`[^`]+`/i', '', $create);

        try {
            $control->exec($create);
        } catch (PDOException $e) {
            echo "  Failed to create table $t: " . $e->getMessage() . "\n";
            continue;
        }

        // copy rows
        try {
            $rows = $inventory->query("SELECT * FROM `" . $t . "`")->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) === 0) { echo "  No rows to copy.\n"; continue; }
            $cols = array_keys($rows[0]);
            $colList = '`' . implode('`,`', $cols) . '`';
            $placeholders = ':' . implode(',:', $cols);
            $stmt = $control->prepare('REPLACE INTO `'.$t.'` ('.$colList.') VALUES ('.$placeholders.')');
            foreach ($rows as $r) {
                $params = [];
                foreach ($cols as $c) $params[':'.$c] = $r[$c];
                $stmt->execute($params);
            }
            echo "  Copied " . count($rows) . " rows into $t\n";
        } catch (PDOException $e) {
            echo "  Failed to copy rows for $t: " . $e->getMessage() . "\n";
        }
    }

    echo "Migration complete. Control panel database: $newDb\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

?>
