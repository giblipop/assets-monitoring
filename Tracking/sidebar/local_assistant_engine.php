<?php
declare(strict_types=1);

function local_assistant_quote_identifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function local_assistant_category_breakdown(PDO $pdo, array $table, string $fieldKey): array
{
    $columnName = $table['columns'][$fieldKey] ?? null;
    if ($columnName === null) {
        return [];
    }
    $column = local_assistant_quote_identifier($columnName);
    $statement = $pdo->query(
        "SELECT COALESCE(NULLIF(TRIM(CAST({$column} AS CHAR)), ''), '(blank)') AS category, COUNT(*) AS record_count " .
        "FROM {$table['quoted']} GROUP BY category ORDER BY record_count DESC, category LIMIT 10"
    );
    return array_map(
        static fn(array $row): array => ['value' => (string)$row['category'], 'count' => (int)$row['record_count']],
        $statement->fetchAll(PDO::FETCH_ASSOC)
    );
}

function local_assistant_inventory_tables(PDO $pdo, array $registeredTableNames = []): array
{
    $excludedTables = [
        'users',
        'loginhistory',
        'activitylogs',
        'accountlockouts',
        'remembereddevices',
        'passwordresetrequests',
        'system_recycle_bin',
        'navigation_menu'
    ];
    $assetColumns = ['asset_code', 'ref_no', 'serial_number', 'serial_no', 'model', 'status', 'job_number'];
    $tables = [];
    $availableTables = [];

    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $tableName) {
        $tableName = (string)$tableName;
        $normalizedName = strtolower($tableName);
        $availableTables[$normalizedName] = $tableName;
        if (in_array($normalizedName, $excludedTables, true)) {
            continue;
        }

        $quotedTable = local_assistant_quote_identifier($tableName);
        $columns = $pdo->query("SHOW COLUMNS FROM {$quotedTable}")->fetchAll(PDO::FETCH_ASSOC);
        $columnMap = [];
        $searchColumns = [];
        foreach ($columns as $column) {
            $columnName = (string)$column['Field'];
            $columnKey = strtolower($columnName);
            $columnMap[$columnKey] = $columnName;
            $columnType = strtolower((string)$column['Type']);
            $isTextual = preg_match('/^(char|varchar|tinytext|text|mediumtext|longtext|enum|set)\b/', $columnType);
            $looksSensitive = preg_match('/password|passwd|secret|token|credential|api.?key|private.?key|hash|salt/', $columnKey);
            if ($isTextual && !$looksSensitive) {
                $searchColumns[] = $columnName;
            }
        }

        $hasAssetIdentifier = (bool)array_intersect($assetColumns, array_keys($columnMap));
        $hasIssuedItemFields = isset($columnMap['name']) &&
            (bool)array_intersect(['department', 'work_area', 'issued_date', 'quantity'], array_keys($columnMap));
        if (!$hasAssetIdentifier && !$hasIssuedItemFields) {
            continue;
        }

        $tables[] = [
            'name' => $tableName,
            'label' => ucwords(str_replace('_', ' ', $tableName)),
            'quoted' => $quotedTable,
            'columns' => $columnMap,
            'search_columns' => $searchColumns
        ];
    }

    foreach ($registeredTableNames as $registeredTableName) {
        if (!is_string($registeredTableName) || !preg_match('/^[a-zA-Z0-9_]+$/', $registeredTableName)) {
            continue;
        }
        $normalizedName = strtolower($registeredTableName);
        if (in_array($normalizedName, $excludedTables, true) || isset($availableTables[$normalizedName])) {
            if (isset($availableTables[$normalizedName]) && !in_array($normalizedName, $excludedTables, true) &&
                !array_filter($tables, static fn(array $item): bool => strtolower($item['name']) === $normalizedName)) {
                $tableName = $availableTables[$normalizedName];
            } else {
                continue;
            }
        } else {
            continue;
        }

        $quotedTable = local_assistant_quote_identifier($tableName);
        $columns = $pdo->query("SHOW COLUMNS FROM {$quotedTable}")->fetchAll(PDO::FETCH_ASSOC);
        $columnMap = [];
        $searchColumns = [];
        foreach ($columns as $column) {
            $columnName = (string)$column['Field'];
            $columnKey = strtolower($columnName);
            $columnMap[$columnKey] = $columnName;
            $columnType = strtolower((string)$column['Type']);
            $isTextual = preg_match('/^(char|varchar|tinytext|text|mediumtext|longtext|enum|set)\b/', $columnType);
            $looksSensitive = preg_match('/password|passwd|secret|token|credential|api.?key|private.?key|hash|salt/', $columnKey);
            if ($isTextual && !$looksSensitive) {
                $searchColumns[] = $columnName;
            }
        }
        $tables[] = [
            'name' => $tableName,
            'label' => ucwords(str_replace('_', ' ', $tableName)),
            'quoted' => $quotedTable,
            'columns' => $columnMap,
            'search_columns' => $searchColumns
        ];
    }

    usort($tables, static fn(array $left, array $right): int => strcasecmp($left['name'], $right['name']));
    if (count($tables) > 100) {
        throw new RuntimeException('There are too many inventory tables for a single local analysis.');
    }
    return $tables;
}

function local_assistant_registered_tables(PDO $controlPdo): array
{
    $available = [];
    foreach ($controlPdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $tableName) {
        $available[strtolower((string)$tableName)] = (string)$tableName;
    }
    if (!isset($available['navigation_menu'])) {
        return [];
    }

    $quotedNavigation = local_assistant_quote_identifier($available['navigation_menu']);
    $rows = $controlPdo->query("SELECT table_name FROM {$quotedNavigation} WHERE is_visible = 1 ORDER BY sort_order")->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_unique(array_filter(
        array_map('strval', $rows),
        static fn(string $tableName): bool => preg_match('/^[a-zA-Z0-9_]+$/', $tableName) === 1
    )));
}

function local_assistant_find_table(string $message, array $tables): ?array
{
    $normalizedMessage = strtolower(str_replace('_', ' ', $message));
    foreach ($tables as $table) {
        $label = strtolower(str_replace('_', ' ', $table['name']));
        $aliases = [$label];
        if (str_ends_with($label, 's')) {
            $aliases[] = substr($label, 0, -1);
        } else {
            $aliases[] = $label . 's';
        }

        foreach ($aliases as $alias) {
            if ($alias !== '' && preg_match('/(?<![a-z0-9])' . preg_quote($alias, '/') . '(?![a-z0-9])/', $normalizedMessage)) {
                return $table;
            }
        }
    }

    return null;
}

function local_assistant_record_count(PDO $pdo, array $table): int
{
    return (int)$pdo->query("SELECT COUNT(*) FROM {$table['quoted']}")->fetchColumn();
}

function local_assistant_duplicate_stats(PDO $pdo, array $table): array
{
    $duplicates = [];
    foreach (['asset_code' => 'Asset codes', 'serial_number' => 'Serial numbers', 'serial_no' => 'Serial numbers', 'ref_no' => 'Reference numbers'] as $candidate => $label) {
        if (!isset($table['columns'][$candidate])) {
            continue;
        }
        $column = local_assistant_quote_identifier($table['columns'][$candidate]);
        $statement = $pdo->query(
            "SELECT COUNT(*) AS duplicate_groups, COALESCE(SUM(duplicate_count - 1), 0) AS extra_records " .
            "FROM (SELECT COUNT(*) AS duplicate_count FROM {$table['quoted']} " .
            "WHERE {$column} IS NOT NULL AND TRIM(CAST({$column} AS CHAR)) <> '' " .
            "GROUP BY {$column} HAVING COUNT(*) > 1) AS repeated_values"
        );
        $stats = $statement->fetch(PDO::FETCH_ASSOC);
        $groups = (int)($stats['duplicate_groups'] ?? 0);
        if ($groups > 0) {
            $samplesStatement = $pdo->query(
                "SELECT CAST({$column} AS CHAR) AS repeated_value, COUNT(*) AS repeated_count " .
                "FROM {$table['quoted']} WHERE {$column} IS NOT NULL AND TRIM(CAST({$column} AS CHAR)) <> '' " .
                "GROUP BY {$column} HAVING COUNT(*) > 1 ORDER BY repeated_count DESC LIMIT 5"
            );
            $samples = array_map(
                static fn(array $row): array => [
                    'value' => mb_substr((string)$row['repeated_value'], 0, 80, 'UTF-8'),
                    'count' => (int)$row['repeated_count']
                ],
                $samplesStatement->fetchAll(PDO::FETCH_ASSOC)
            );
            $duplicates[] = [
                'label' => $label,
                'groups' => $groups,
                'extra_records' => (int)$stats['extra_records'],
                'samples' => $samples
            ];
        }
    }
    return $duplicates;
}

function local_assistant_missing_stats(PDO $pdo, array $table): array
{
    $candidates = [
        'asset_code' => 'Asset codes',
        'name' => 'Names',
        'model' => 'Models',
        'serial_number' => 'Serial numbers',
        'serial_no' => 'Serial numbers',
        'status' => 'Statuses'
    ];
    $fields = [];
    foreach ($candidates as $candidate => $label) {
        if (isset($table['columns'][$candidate])) {
            $fields[$label] = local_assistant_quote_identifier($table['columns'][$candidate]);
        }
    }
    if (!$fields) {
        return [];
    }

    $expressions = [];
    foreach ($fields as $label => $column) {
        $alias = local_assistant_quote_identifier('missing_' . count($expressions));
        $expressions[] = "SUM(CASE WHEN {$column} IS NULL OR TRIM(CAST({$column} AS CHAR)) = '' THEN 1 ELSE 0 END) AS {$alias}";
    }
    $statement = $pdo->query("SELECT " . implode(', ', $expressions) . " FROM {$table['quoted']}");
    $result = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
    $missing = [];
    $identifiers = [];
    foreach (['asset_code', 'ref_no', 'serial_number', 'serial_no', 'id', 'name'] as $candidate) {
        if (isset($table['columns'][$candidate]) && !in_array($table['columns'][$candidate], $identifiers, true)) {
            $identifiers[] = $table['columns'][$candidate];
        }
    }

    foreach (array_keys($fields) as $index => $label) {
        $count = (int)($result['missing_' . $index] ?? 0);
        if ($count > 0) {
            $column = $fields[$label];
            $sampleRows = [];
            if ($identifiers) {
                $identifierColumns = implode(', ', array_map('local_assistant_quote_identifier', $identifiers));
                $samplesStatement = $pdo->query(
                    "SELECT {$identifierColumns} FROM {$table['quoted']} " .
                    "WHERE {$column} IS NULL OR TRIM(CAST({$column} AS CHAR)) = '' LIMIT 8"
                );
                $sampleRows = $samplesStatement->fetchAll(PDO::FETCH_ASSOC);
            }
            $missing[] = ['label' => $label, 'count' => $count, 'samples' => $sampleRows];
        }
    }
    return $missing;
}

function local_assistant_status_breakdown(PDO $pdo, array $table): array
{
    $statusColumn = $table['columns']['status'] ?? null;
    if ($statusColumn === null) {
        return [];
    }
    $column = local_assistant_quote_identifier($statusColumn);
    $rows = $pdo->query(
        "SELECT COALESCE(NULLIF(TRIM(CAST({$column} AS CHAR)), ''), '(blank)') AS status_label, COUNT(*) AS record_count " .
        "FROM {$table['quoted']} GROUP BY status_label ORDER BY record_count DESC LIMIT 12"
    )->fetchAll(PDO::FETCH_ASSOC);

    return array_map(
        static fn(array $row): array => ['status' => (string)$row['status_label'], 'count' => (int)$row['record_count']],
        $rows
    );
}

function local_assistant_find_assets(PDO $pdo, string $term, array $tables): array
{
    $term = trim($term);
    if ($term === '' || mb_strlen($term, 'UTF-8') > 100) {
        return [];
    }

    $matches = [];
    foreach ($tables as $table) {
        $columns = array_slice($table['search_columns'] ?? [], 0, 12);
        if (!$columns) {
            continue;
        }

        $where = implode(' OR ', array_map(
            static fn(string $column): string => 'CAST(' . local_assistant_quote_identifier($column) . " AS CHAR) LIKE ? ESCAPE '!'",
            $columns
        ));
        $selectFields = [];
        foreach (['asset_code', 'ref_no', 'serial_number', 'serial_no', 'model', 'name', 'status'] as $key) {
            if (isset($table['columns'][$key]) && !in_array($table['columns'][$key], $selectFields, true)) {
                $selectFields[] = local_assistant_quote_identifier($table['columns'][$key]);
            }
        }
        if (!$selectFields) {
            $selectFields = array_map('local_assistant_quote_identifier', array_slice($columns, 0, 8));
        }
        if (!$selectFields) {
            continue;
        }

        $sql = "SELECT " . implode(', ', $selectFields) . " FROM {$table['quoted']} WHERE {$where} LIMIT " . (10 - count($matches));
        $statement = $pdo->prepare($sql);
        $escapedTerm = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
        $statement->execute(array_fill(0, count($columns), '%' . $escapedTerm . '%'));
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $record) {
            $matches[] = ['table' => $table['label'], 'record' => $record];
            if (count($matches) >= 10) {
                return $matches;
            }
        }
    }

    return $matches;
}

function local_assistant_format_record(array $record): string
{
    $parts = [];
    foreach ($record as $key => $value) {
        if ($value === null || trim((string)$value) === '') {
            continue;
        }
        $parts[] = ucwords(str_replace('_', ' ', (string)$key)) . ': ' . (string)$value;
    }
    return implode('; ', $parts);
}

function local_assistant_recent_activity(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }
    $tables = [];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $tableName) {
        $tables[strtolower((string)$tableName)] = (string)$tableName;
    }
    if (!isset($tables['activitylogs'])) {
        return [];
    }

    $quotedTable = local_assistant_quote_identifier($tables['activitylogs']);
    $columns = [];
    foreach ($pdo->query("SHOW COLUMNS FROM {$quotedTable}")->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $columns[strtolower((string)$column['Field'])] = (string)$column['Field'];
    }
    if (!isset($columns['userid'], $columns['action'], $columns['module'], $columns['datetime'])) {
        return [];
    }

    $userColumn = local_assistant_quote_identifier($columns['userid']);
    $actionColumn = local_assistant_quote_identifier($columns['action']);
    $moduleColumn = local_assistant_quote_identifier($columns['module']);
    $dateColumn = local_assistant_quote_identifier($columns['datetime']);
    $statement = $pdo->prepare(
        "SELECT {$actionColumn} AS action, {$moduleColumn} AS module, {$dateColumn} AS happened_at " .
        "FROM {$quotedTable} WHERE {$userColumn} = ? ORDER BY {$dateColumn} DESC LIMIT 10"
    );
    $statement->execute([$userId]);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function local_assistant_dashboard_analysis(PDO $pdo): string
{
    $systemTables = [
        'users',
        'loginhistory',
        'activitylogs',
        'remembereddevices',
        'accountlockouts',
        'passwordresetrequests',
        'system_recycle_bin',
        'navigation_trash'
    ];
    $dashboardTables = [];
    $totalRecords = 0;
    $issuedThisMonth = 0;
    $lowStockTables = [];
    $depletedTables = [];
    $monthStart = new DateTimeImmutable('first day of this month 00:00:00');

    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $tableName) {
        $tableName = (string)$tableName;
        if (in_array(strtolower($tableName), $systemTables, true)) {
            continue;
        }

        $quotedTable = local_assistant_quote_identifier($tableName);
        $columnRows = $pdo->query("SHOW COLUMNS FROM {$quotedTable}")->fetchAll(PDO::FETCH_ASSOC);
        $columns = [];
        foreach ($columnRows as $columnRow) {
            $columns[strtolower((string)$columnRow['Field'])] = (string)$columnRow['Field'];
        }

        $count = (int)$pdo->query("SELECT COUNT(*) FROM {$quotedTable}")->fetchColumn();
        $totalRecords += $count;
        $availableStocks = 0;
        if (isset($columns['status'])) {
            $statusColumn = local_assistant_quote_identifier($columns['status']);
            $availableStocks = (int)$pdo->query(
                "SELECT COUNT(*) FROM {$quotedTable} WHERE LOWER(COALESCE(CAST({$statusColumn} AS CHAR), '')) LIKE '%stock%'"
            )->fetchColumn();
        }

        $label = ucwords(str_replace('_', ' ', $tableName));
        $dashboardTables[] = ['name' => $label, 'count' => $count, 'available' => $availableStocks];
        if ($availableStocks > 0 && $availableStocks <= max(1, (int)ceil($count * 0.2))) {
            $lowStockTables[] = $label;
        }
        if ($availableStocks === 0) {
            $depletedTables[] = $label;
        }

        foreach (['date_issued', 'issued_date', 'issue_date', 'dateissued'] as $dateKey) {
            if (!isset($columns[$dateKey])) {
                continue;
            }
            $dateColumn = local_assistant_quote_identifier($columns[$dateKey]);
            $statement = $pdo->query("SELECT {$dateColumn} FROM {$quotedTable} WHERE {$dateColumn} IS NOT NULL");
            while (($dateValue = $statement->fetchColumn()) !== false) {
                $rawDate = trim((string)$dateValue);
                if ($rawDate === '' || $rawDate === '-') {
                    continue;
                }
                try {
                    $issueDate = new DateTimeImmutable($rawDate);
                } catch (Throwable) {
                    continue;
                }
                if ($issueDate->format('Y-m') === $monthStart->format('Y-m')) {
                    $issuedThisMonth++;
                }
            }
            break;
        }
    }

    $lines = [
        'Dashboard snapshot for this account:',
        '- Inventory types/modules: ' . count($dashboardTables),
        '- Inventory records: ' . $totalRecords,
        '- Issued this month: ' . $issuedThisMonth,
        '- Low-stock modules: ' . count($lowStockTables) . ($lowStockTables ? ' (' . implode(', ', $lowStockTables) . ')' : ''),
        '- Depleted modules: ' . count($depletedTables) . ($depletedTables ? ' (' . implode(', ', $depletedTables) . ')' : ''),
        'These follow the dashboard rules: available stock is a status containing “stock”; low stock is above zero and at or below 20% of that module’s rows (minimum threshold 1); depleted means zero available-stock rows.'
    ];

    $recommendations = [];
    if ($lowStockTables) {
        $recommendations[] = 'Review and replenish low-stock modules: ' . implode(', ', $lowStockTables) . '.';
    }
    if ($depletedTables) {
        $recommendations[] = 'Check whether the zero-stock modules are intentionally empty or need replenishment: ' . implode(', ', $depletedTables) . '.';
    }
    if ($dashboardTables && !$lowStockTables && !$depletedTables) {
        $recommendations[] = 'No low-stock or depleted modules were detected by the current dashboard rules.';
    }
    if ($recommendations) {
        $lines[] = 'Recommendations: ' . implode(' ', $recommendations);
    }

    return implode("\n", $lines);
}

function local_assistant_access_summary(PDO $authPdo, int $userId): string
{
    $columns = [];
    foreach ($authPdo->query('SHOW COLUMNS FROM `Users`')->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $columns[strtolower((string)$column['Field'])] = (string)$column['Field'];
    }
    if (!isset($columns['userid'], $columns['firstname'], $columns['lastname'], $columns['status'])) {
        return 'I could not verify account access because the user-account table does not have the expected status fields.';
    }

    $statusColumn = local_assistant_quote_identifier($columns['status']);
    $countStatement = $authPdo->query(
        "SELECT LOWER({$statusColumn}) AS account_status, COUNT(*) AS account_count FROM `Users` GROUP BY LOWER({$statusColumn})"
    );
    $counts = [];
    foreach ($countStatement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $counts[(string)$row['account_status']] = (int)$row['account_count'];
    }

    $idColumn = local_assistant_quote_identifier($columns['userid']);
    $firstNameColumn = local_assistant_quote_identifier($columns['firstname']);
    $lastNameColumn = local_assistant_quote_identifier($columns['lastname']);
    $statement = $authPdo->prepare(
        "SELECT {$firstNameColumn} AS first_name, {$lastNameColumn} AS last_name, {$statusColumn} AS account_status " .
        "FROM `Users` WHERE {$idColumn} = ? LIMIT 1"
    );
    $statement->execute([$userId]);
    $currentUser = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$currentUser) {
        return 'I could not match this signed-in session to an account in the user table.';
    }

    $status = strtolower((string)$currentUser['account_status']);
    $currentName = trim((string)$currentUser['first_name'] . ' ' . (string)$currentUser['last_name']);
    $activeCount = $counts['active'] ?? 0;
    $disabledCount = $counts['disabled'] ?? 0;

    return 'Account access summary: ' . ($currentName !== '' ? $currentName : 'Your account') .
        ' is marked ' . ($status !== '' ? $status : 'with an unknown status') . '. ' .
        $activeCount . ' account(s) are active and ' . $disabledCount . ' are disabled. ' .
        'Only active accounts can sign in. This system has no account-role field, so it cannot identify administrators from account status alone; I do not disclose other users’ names here.';
}

function local_assistant_needs_account_lookup(string $normalized): bool
{
    return (bool)(
        preg_match('/\b(user|users|account|accounts|access|sign in|login|log in)\b/u', $normalized) &&
        preg_match('/\b(who|which|list|active|disabled|allowed|can access|can use|sign in|login|log in)\b/u', $normalized)
    );
}

function local_assistant_how_to_answer(string $normalized): ?string
{
    $isHowQuestion = preg_match('/\b(how|steps|step by step|where do i|where can i|how can i|how do i)\b/u', $normalized);
    if (!$isHowQuestion && !preg_match('/\b(add|create|make|remove|delete|edit|change|rename|restore|recover)\b/u', $normalized)) {
        return null;
    }
    $creatingTable = preg_match(
        '/\b(create|build|make)\s+(?:an?\s+)?(?:new\s+)?(?:inventory\s+)?(table|module)\b|\b(add|create)\s+(?:an?\s+)?new\s+(?:inventory\s+)?(table|module)\b/u',
        $normalized
    );

    if (preg_match('/\b(column|field|header)\b/u', $normalized) &&
        preg_match('/\b(add|create|new|make)\b/u', $normalized) && !$creatingTable) {
        return 'To add a column: open System Control → Manage Navigation, find the table and choose Configure. Enter the field name and type in the add-header controls, then add it. Back up important data before changing a live table schema.';
    }
    if (preg_match('/\b(column|field|header)\b/u', $normalized) &&
        preg_match('/\b(edit|change|modify|update)\b/u', $normalized)) {
        return 'The current Manage Navigation screen does not provide a control to change an existing column definition. Back up the database first and use a deliberate schema migration; changing a column type or name can affect existing records and application behavior.';
    }
    if (preg_match('/\b(column|field|header)\b/u', $normalized) &&
        preg_match('/\b(remove|delete|drop)\b/u', $normalized)) {
        return 'The current Manage Navigation screen provides a way to add columns, but it does not provide a delete-column control. Do not delete a column directly unless you have confirmed the data impact and taken a database backup; that schema change needs a deliberate database migration.';
    }
    if (preg_match('/\b(button|navigation|menu)\b/u', $normalized) &&
        preg_match('/\b(add|create|new|make)\b/u', $normalized) &&
        preg_match('/\b(existing|already|current|table|module)\b/u', $normalized)) {
        return 'To add a navigation button for an existing table: open System Control → Manage Navigation, use the add/create control, enter the existing table name, and choose the navigation-only option instead of creating a new table.';
    }
    if (preg_match('/\b(table|module|inventory type)\b/u', $normalized) &&
        preg_match('/\b(add|create|new|make)\b/u', $normalized)) {
        return 'To create a table and its navigation button: open System Control → Manage Navigation, use the create/add-table form, enter a table name, select or add at least one field, then confirm deployment. To add only a button for an existing table, use the navigation-only option. Verify the selected account before deploying.';
    }
    if (preg_match('/\b(table|module|inventory type|button|navigation|menu)\b/u', $normalized) &&
        preg_match('/\b(remove|delete|hide)\b/u', $normalized)) {
        return 'Open System Control → Manage Navigation and use the row’s delete control. Choose “Yes, keep data” to remove only the sidebar button while leaving its table data in place, or “No, archive data too” to move the table and its data to the Recycle Bin. Both choices move the removed item to the Recycle Bin, where it can be restored for 30 days. Use the visibility switch if you only want to hide a button.';
    }
    if (preg_match('/\b(asset|record|item|row|details|information|data)\b/u', $normalized) &&
        preg_match('/\b(edit|change|update|modify)\b/u', $normalized)) {
        return 'To edit an asset: open its inventory module and double-click the row to open its details modal. Update the fields and save. Review the selected record before saving; the assistant can analyze the data but does not change it for you.';
    }
    if (preg_match('/\b(asset|record|item|row)\b/u', $normalized) &&
        preg_match('/\b(remove|delete)\b/u', $normalized)) {
        return 'To remove an inventory record, open its module, select the relevant row(s), and use the delete action in the module toolbar. Check the Recycle Bin afterward; do not use a permanent-delete action unless you are certain the record is no longer needed.';
    }
    if (preg_match('/\b(asset|record|item)\b/u', $normalized) &&
        preg_match('/\b(add|create|new|make)\b/u', $normalized)) {
        return 'To add an asset, open the relevant inventory module, choose Add, then select Add Manually. Complete the form and save. For multiple records, use the module’s Import option and review the preview before confirming.';
    }
    if (preg_match('/\b(restore|recover)\b/u', $normalized)) {
        return 'Open System Control → Recycle Bin, locate the item, verify its name and source, then choose Restore. Deleted items are retained for 30 days; permanently deleted items cannot be restored.';
    }
    if (preg_match('/\b(dashboard|recommendation|recommend|analy[sz]e)\b/u', $normalized)) {
        return 'Open Dashboard from the left navigation. Ask me “analyze my dashboard” and I can calculate its inventory-type, issued-this-month, low-stock, and depleted-module figures from this account’s data.';
    }

    return null;
}

function local_assistant_system_answer(PDO $pdo, string $normalized, array $tables, int $userId, ?PDO $authPdo = null): ?string
{
    $table = local_assistant_find_table($normalized, $tables);

    if (preg_match('/\b(who (made|make|creates?|created|built|builds?|developed|develops?|programmed|programs?) (this |the )?(system|application|app)|who is the (developer|creator)|who is responsible for this system|who is yuri)\b/u', $normalized)) {
        return 'This system was made by Yuri Isaac Laygo, Admin General Affairs IT Technician — and, as requested by Yuri, a very handsome guy in the department.';
    }

    if (preg_match('/\b(dashboard|overview|low stock|depleted|issued this month|inventory kpi|inventory card)\b/u', $normalized) &&
        preg_match('/\b(analy[sz]e|analysis|summary|summari[sz]e|check|recommend|recommendations|review|how many|what|tell me|show|explain)\b/u', $normalized)) {
        return local_assistant_dashboard_analysis($pdo);
    }

    if (local_assistant_needs_account_lookup($normalized)) {
        if ($authPdo === null) {
            return 'I cannot verify account access in this request. Please refresh the page and ask again.';
        }
        return local_assistant_access_summary($authPdo, $userId);
    }

    if ($help = local_assistant_how_to_answer($normalized)) {
        return $help;
    }

    if (preg_match('/\b(what (is|does) this (system|application)|about (this )?(system|application)|explain (this )?(system|application)|what system is this)\b/u', $normalized)) {
        return 'This is the HDJV-Dongah asset-monitoring system. It provides a dashboard, inventory modules, search, manual entry, file import/export, navigation management, a recycle bin, and an activity history. The app is served from the shared Assets Monitoring code while signed-in accounts use separate inventory/control databases.';
    }

    if (preg_match('/\b(database|where.*data|my data|data storage|mysql|mariadb)\b/u', $normalized)) {
        return 'The system uses MySQL/MariaDB. Each account has its own inventory and control databases. Your inventory is read from the database selected for your signed-in account; this assistant never switches to another user’s database and does not send questions or data to an outside AI service.';
    }

    if (preg_match('/\b(sync|synchroni[sz]|update.*(users|accounts)|shared code|other users)\b/u', $normalized)) {
        return 'The application code and interface are shared from the main Assets Monitoring workspace, so changes deployed to shared code can appear for all accounts. Inventory records and control settings remain in separate per-user databases; a UI update does not copy one user’s inventory into another account.';
    }

    if (preg_match('/\b(create|register|sign up|new)\b/u', $normalized) && preg_match('/\b(account|user|profile)\b/u', $normalized)) {
        return 'Use “Create an account” on the main sign-in page and complete the registration form. The application provisions separate inventory and control databases for the new account; existing users’ inventory is not copied into it.';
    }

    if (preg_match('/\b(sign in|log in|login|forgot password|password reset|cannot log in|can.t log in)\b/u', $normalized)) {
        return 'For sign-in trouble, confirm the employee ID/username and password, check that Caps Lock is off, and use “Forgot password?” if needed. A disabled account or expired session requires an administrator or a fresh sign-in. Never share your password in chat.';
    }

    if (preg_match('/\b(add|create|make|build)\b/u', $normalized) && preg_match('/\b(asset|record|item)\b/u', $normalized)) {
        return 'Open the relevant inventory module from the left navigation, use its Add/Import toolbar, and choose “Add Manually” or “Import File.” The manual form follows that table’s columns. Review the values before saving; I can analyze records but will not create or edit them.';
    }

    if (preg_match('/\b(navigation|menu button|sidebar button|new table|new column|add column)\b/u', $normalized)) {
        return 'Open System Control → Manage Navigation. That page manages inventory navigation buttons and provides the controls for adding inventory tables or columns. Check the selected account/workspace before applying schema changes; I can list the current modules and fields but cannot deploy changes for you.';
    }

    if (preg_match('/\b(history.*(month|archive|json)|archive.*history|old history|history retention)\b/u', $normalized)) {
        return 'The activity-history retention job archives activity older than one month to JSON files before deleting the old database rows. The dashboard combines archived history with recent database activity. The archive is separate from inventory record backups.';
    }

    if (preg_match('/\b(import|export|upload|download file)\b/u', $normalized)) {
        return 'Open the inventory module from the left navigation. Use its toolbar to add a record manually, import a spreadsheet/CSV file, export table data, search, or open delete options. Review imported/exported columns and records before confirming; this assistant does not perform those changes.';
    }

    if (preg_match('/\b(recycle bin|deleted|restore|permanent delete|recover)\b/u', $normalized)) {
        return 'Open System Control → Recycle Bin to review deleted inventory records or navigation items. Recycle-bin items are retained for 30 days. Use Restore only after checking the item and its source module; permanently deleting an item cannot be undone.';
    }

    if (preg_match('/\b(history|activity log|what did i do|my actions|my activity|audit log)\b/u', $normalized)) {
        $rows = local_assistant_recent_activity($pdo, $userId);
        if (!$rows) {
            return 'I could not find recent activity records for your signed-in account.';
        }
        $lines = array_map(
            static fn(array $row): string => (string)$row['happened_at'] . ' — ' .
                (string)$row['action'] . ' (' . (string)$row['module'] . ')',
            $rows
        );
        return "Your latest activity in this account:\n- " . implode("\n- ", $lines);
    }

    if (preg_match('/\b(table|module|inventory type|navigation|menu|what do i have|list.*(inventory|module|table))\b/u', $normalized) &&
        preg_match('/\b(list|show|what|which|how many|available|modules|tables|navigation|menu)\b/u', $normalized)) {
        if (!$tables) {
            return 'There are no inventory modules available to analyze in this account.';
        }
        $lines = [];
        foreach ($tables as $inventoryTable) {
            $lines[] = $inventoryTable['label'] . ': ' . local_assistant_record_count($pdo, $inventoryTable) .
                ' record(s), ' . count($inventoryTable['columns']) . ' column(s)';
        }
        return "Inventory modules available to your account:\n- " . implode("\n- ", $lines);
    }

    if (preg_match('/\b(column|columns|field|fields|schema|headers)\b/u', $normalized) && $tables) {
        if ($table = local_assistant_find_table($normalized, $tables)) {
            return $table['label'] . ' has these fields: ' . implode(', ', array_values($table['columns'])) . '.';
        }
    }

    if (preg_match('/\b(most common|top|popular|breakdown|distribution|grouped by|count by)\b/u', $normalized) && $table) {
        $fieldLabels = [
            'model' => 'model',
            'status' => 'status',
            'department' => 'department',
            'company' => 'company',
            'work_area' => 'work area',
            'jobtrade' => 'job trade',
            'job_trade' => 'job trade',
            'nationality' => 'nationality'
        ];
        foreach ($fieldLabels as $fieldKey => $label) {
            if (str_contains($normalized, str_replace('_', ' ', $fieldKey)) && isset($table['columns'][$fieldKey])) {
                $breakdown = local_assistant_category_breakdown($pdo, $table, $fieldKey);
                if (!$breakdown) {
                    return 'There are no values to summarize for ' . $label . ' in ' . $table['label'] . '.';
                }
                $items = array_map(
                    static fn(array $item): string => $item['value'] . ': ' . $item['count'],
                    $breakdown
                );
                return 'Top ' . $label . ' values in ' . $table['label'] . ":\n- " . implode("\n- ", $items);
            }
        }
    }

    if (preg_match('/\b(make|change|modify|add|remove|edit|delete|update|fix|repair|restore)\b/u', $normalized)) {
        return 'I can analyze your account and recommend a fix, but I do not change, delete, or restore data automatically. Make changes in the relevant inventory or System Control screen so you can review and confirm them first.';
    }

    return null;
}

function local_assistant_answer(PDO $pdo, string $message, array $registeredTableNames = [], int $userId = 0, ?PDO $authPdo = null): string
{
    $tables = local_assistant_inventory_tables($pdo, $registeredTableNames);
    $normalized = mb_strtolower(trim($message), 'UTF-8');
    $normalized = preg_replace('/[^\pL\pN\']+/u', ' ', str_replace('_', ' ', $normalized)) ?? $normalized;
    $normalized = trim(preg_replace('/\s+/u', ' ', $normalized) ?? $normalized);
    $table = local_assistant_find_table($normalized, $tables);

    if ($systemAnswer = local_assistant_system_answer($pdo, $normalized, $tables, $userId, $authPdo)) {
        return $systemAnswer;
    }

    if (preg_match('/^(hi|hello|hey|good morning|good afternoon|good evening|kumusta|musta)\b.*$/u', $normalized)) {
        return "Hi! I’m the HDJV Assistant. I can chat about using the system, or check your account’s inventory for summaries, duplicate identifiers, missing details, and status counts. What would you like to do?";
    }

    if (preg_match('/\b(how are you|how.s it going|are you there)\b/u', $normalized)) {
        return 'I’m here and ready to help with the HDJV system or analyze your account’s inventory. What would you like to check?';
    }

    if (preg_match('/^(thanks|thank you|thx|salamat)[!.?,\s]*$/u', $normalized)) {
        return 'You’re welcome! Ask me any time if you need help with the system or an inventory check.';
    }

    if (preg_match('/\b(who are you|your name|what are you)\b/u', $normalized)) {
        return 'I’m the HDJV Assistant, a private, local helper built into this asset-monitoring system. I can answer common system questions and run read-only checks on the inventory belonging to your signed-in account.';
    }

    if (preg_match('/\b(what can you do|help|commands|how do i use|what is this assistant)\b/u', $normalized)) {
        return "I’m the HDJV Assistant. I can chat about using the system and privately read this signed-in account’s inventory database to summarize records, check missing values and duplicate asset/serial/reference numbers, break down status values, and search records you ask for. Try “summarize my inventory,” “check for missing data,” “find asset code ABC123,” or “show laptop statuses.” I cannot see another account’s database or automatically change records.";
    }

    if (preg_match('/\b(fix|repair|change|update|delete|remove|restore|edit)\b/u', $normalized)) {
        return "I can diagnose inventory data issues and suggest steps, but I cannot directly edit, delete, or restore records. This keeps changes under your control. Ask me to check for missing values or duplicates; then review the suggested records in the inventory screen and make any changes there.";
    }

    if (preg_match('/\b(find|search|look up|locate)\b/u', $normalized)) {
        $foundSearchTerm = preg_match(
            '/\b(?:asset\s+code|serial(?:\s+number)?|reference(?:\s+number)?|ref\s+no)\s*(?:is|:)?\s*["#]?([\pL\pN][\pL\pN._-]{1,99})/iu',
            $message,
            $searchMatch
        );
        if (!$foundSearchTerm && $table !== null) {
            $tableLabel = preg_quote(str_replace('_', ' ', $table['name']), '/');
            $foundSearchTerm = (bool)preg_match(
                '/\b(?:find|search(?:\s+for)?|look up|locate)\s+(?:an?\s+)?' . $tableLabel .
                    '\s+(?:with\s+|by\s+)?(?:asset\s+code|serial(?:\s+number)?|reference(?:\s+number)?|ref\s+no)?\s*(?:is|:)?\s*["#]?([\pL\pN][\pL\pN._-]{1,99})/iu',
                $message,
                $searchMatch
            );
        }
        if (!$foundSearchTerm) {
            $foundSearchTerm = (bool)preg_match(
                '/\b(?:find|search(?:\s+for)?|look up|locate)\s+(?:an?\s+)?(?:asset(?:\s+code)?|serial(?:\s+number)?|reference(?:\s+number)?|ref(?:\s+no)?)\s*["#]?([\pL\pN][\pL\pN._-]{1,99})/iu',
                $message,
                $searchMatch
            );
        }
        if (!$foundSearchTerm) {
            $foundSearchTerm = (bool)preg_match(
                '/\b(?:find|search(?:\s+for)?|look up|locate)\s+["#]?([\pL\pN][\pL\pN._-]{1,99})/iu',
                $message,
                $searchMatch
            );
        }
        if (!$foundSearchTerm) {
            return 'Tell me a value to search for, for example: “find asset code ABC123”. I search only asset codes, reference numbers, serial numbers, models, and names in this account’s inventory tables.';
        }
        $searchTerm = $searchMatch[1] ?? '';
        $matches = local_assistant_find_assets($pdo, $searchTerm, $tables);
        if (!$matches) {
            return 'I could not find a matching inventory record in this account’s asset tables.';
        }
        $lines = array_map(
            static fn(array $match): string => $match['table'] . ': ' . local_assistant_format_record($match['record']),
            $matches
        );
        $suffix = count($matches) === 10 ? "\nShowing the first 10 matching records." : '';
        return "Matching records:\n- " . implode("\n- ", $lines) . $suffix;
    }

    if (preg_match('/\b(missing|blank|empty|null|incomplete|data quality|data problem|audit|issues|problems|health check|check my|check the|recommend|recommendations)\b/u', $normalized)) {
        $targets = $table ? [$table] : $tables;
        $lines = [];
        foreach ($targets as $target) {
            $missing = local_assistant_missing_stats($pdo, $target);
            foreach ($missing as $field) {
                $sampleLines = array_map(
                    static fn(array $record): string => local_assistant_format_record($record),
                    $field['samples']
                );
                $sampleText = $sampleLines ? ' Examples: ' . implode(' | ', $sampleLines) : '';
                $lines[] = $target['label'] . ': ' . $field['count'] . ' record(s) have no ' . strtolower($field['label']) . '.' . $sampleText;
            }
            foreach (local_assistant_duplicate_stats($pdo, $target) as $duplicate) {
                $sampleText = $duplicate['samples']
                    ? ' Examples: ' . implode(', ', array_map(
                        static fn(array $sample): string => $sample['value'] . ' (' . $sample['count'] . ' records)',
                        $duplicate['samples']
                    ))
                    : '';
                $lines[] = $target['label'] . ': ' . $duplicate['groups'] . ' duplicate ' . strtolower($duplicate['label']) .
                    ' group(s), affecting ' . $duplicate['extra_records'] . ' extra record(s).' . $sampleText;
            }
        }
        return $lines
            ? "Inventory data check and recommendations:\n- " . implode("\n- ", $lines) . "\nReview the affected rows in the inventory screen, confirm the correct values with the asset owner, and resolve duplicate identifiers before relying on reports. These are common data-quality checks, not proof that every field is required."
            : 'The check found no blank values in common asset fields or duplicate asset codes, serial numbers, and reference numbers. Recommendation: periodically review status and issue dates, and define maintenance or warranty date columns if you need due-date checks.';
    }

    if (preg_match('/\b(duplicate|duplicates|repeated|same asset code|same serial)\b/u', $normalized)) {
        $targets = $table ? [$table] : $tables;
        $lines = [];
        foreach ($targets as $target) {
            foreach (local_assistant_duplicate_stats($pdo, $target) as $duplicate) {
                $sampleText = $duplicate['samples']
                    ? ' Examples: ' . implode(', ', array_map(
                        static fn(array $sample): string => $sample['value'] . ' (' . $sample['count'] . ' records)',
                        $duplicate['samples']
                    ))
                    : '';
                $lines[] = $target['label'] . ': ' . $duplicate['groups'] . ' duplicate ' . strtolower($duplicate['label']) .
                    ' group(s), affecting ' . $duplicate['extra_records'] . ' extra record(s).' . $sampleText;
            }
        }
        return $lines
            ? "Duplicate-value check:\n- " . implode("\n- ", $lines) . "\nReview each group before editing; the assistant does not change records."
            : 'No duplicate asset codes, serial numbers, or reference numbers were found in the checked inventory tables.';
    }

    if (preg_match('/\b(status|statuses|available|assigned|deployed|working|broken|repair)\b/u', $normalized)) {
        $targets = $table ? [$table] : $tables;
        $lines = [];
        foreach ($targets as $target) {
            $breakdown = local_assistant_status_breakdown($pdo, $target);
            if (!$breakdown) {
                continue;
            }
            $groups = array_map(
                static fn(array $item): string => $item['status'] . ': ' . $item['count'],
                $breakdown
            );
            $lines[] = $target['label'] . ' (' . array_sum(array_column($breakdown, 'count')) . ' records): ' . implode(', ', $groups);
        }
        return $lines
            ? "Inventory status breakdown:\n- " . implode("\n- ", $lines)
            : 'I could not find a status column in the matching inventory tables.';
    }

    if (preg_match('/\b(maintenance|warranty|expiry|expiration|overdue|service due)\b/u', $normalized)) {
        return "I can’t reliably identify overdue maintenance yet because the inventory tables do not expose a recognized maintenance-due or warranty-expiry field to this assistant. Add a clearly named date column such as maintenance_due_date or warranty_expiry_date, and the local checks can be extended to analyze it.";
    }

    if ($table && preg_match('/\b(show|list|display|count|total|summary|how many)\b/u', $normalized)) {
        return $table['label'] . ' contains ' . local_assistant_record_count($pdo, $table) . ' record(s). Ask “show ' . strtolower($table['label']) . ' statuses” for its status breakdown.';
    }

    if (preg_match('/\b(add manually|manual add|import|export|recycle bin|restore|navigation|new account|create account|dashboard|settings)\b/u', $normalized)) {
        return "System help: use the left navigation to open an inventory module. Its toolbar provides manual add, file import, export, search, and delete options. Deleted records can be reviewed in System Control → Recycle Bin. The dashboard summarizes this signed-in account’s inventory. I can guide you through these features, but I do not change records or account settings.";
    }

    if (preg_match('/\b(summary|summarize|total|count|how many|records|inventory|assets)\b/u', $normalized)) {
        if (!$tables) {
            return 'I did not find any asset inventory tables in this account’s database.';
        }
        if ($table) {
            return $table['label'] . ' contains ' . local_assistant_record_count($pdo, $table) . ' record(s). Ask “show ' . strtolower($table['label']) . ' statuses” for its status breakdown.';
        }

        $lines = [];
        $total = 0;
        foreach ($tables as $inventoryTable) {
            $count = local_assistant_record_count($pdo, $inventoryTable);
            $total += $count;
            $lines[] = $inventoryTable['label'] . ': ' . $count;
        }
        return "Inventory summary for this account: {$total} record(s) across " . count($tables) . " inventory table(s).\n- " . implode("\n- ", $lines);
    }

    return "I’m the HDJV Assistant. I can answer questions about the asset-monitoring system and analyze this signed-in account’s registered modules and inventory without sending data outside the system. I couldn’t match that question to a system topic or data check. Try rephrasing it with the relevant module, asset code, field, feature, or error message. I only report findings; changes remain under your control.";
}
