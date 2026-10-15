<?php
declare(strict_types=1);

require_once __DIR__ . '/../connector/auth_guard.php';
require_once __DIR__ . '/../connector/activity_logger.php';
require_once __DIR__ . '/../connector/undo_support.php';

class EmployeeIssueConflict extends RuntimeException
{
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function employee_issue_respond(array $body, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function employee_issue_quote(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function employee_issue_columns(PDO $pdo, string $table): array
{
    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM ' . employee_issue_quote($table))->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $columns[strtolower((string)$column['Field'])] = (string)$column['Field'];
    }
    return $columns;
}

function employee_issue_schema(PDO $pdo, string $table): array
{
    $schema = [];
    foreach ($pdo->query('SHOW COLUMNS FROM ' . employee_issue_quote($table))->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $schema[strtolower((string)$column['Field'])] = $column;
    }
    return $schema;
}

function employee_issue_column(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (isset($columns[$candidate])) {
            return $columns[$candidate];
        }
    }
    $normalizedCandidates = array_map(
        static fn(string $candidate): string => preg_replace('/[^a-z0-9]/', '', strtolower($candidate)),
        $candidates
    );
    foreach ($columns as $normalized => $actual) {
        $compact = preg_replace('/[^a-z0-9]/', '', strtolower((string)$normalized));
        if (in_array($compact, $normalizedCandidates, true)) {
            return $actual;
        }
    }
    return null;
}

function employee_issue_assignee_columns(array $columns): array
{
    $aliases = [
        'assignedto', 'issuedto', 'employeename', 'assignedemployee',
        'issuedemployee', 'assignedtoname', 'issuedtoname',
        'assignedemployeename', 'employeefullname', 'nameofemployee', 'employee',
        'staffname', 'staff', 'empname', 'assignedperson',
        'borrower', 'borrowername', 'holder', 'holdername',
        'assignee', 'assigneename', 'custodian', 'custodianname',
        'personincharge', 'currentholder', 'currentuser',
        'username', 'name'
    ];
    $matches = [];
    foreach ($columns as $normalized => $actual) {
        $compact = preg_replace('/[^a-z0-9]/', '', (string)$normalized);
        if (in_array($compact, $aliases, true)) {
            $matches[$compact] = $actual;
        }
    }
    $ordered = [];
    foreach ($aliases as $alias) {
        if (isset($matches[$alias])) {
            $ordered[] = $matches[$alias];
        }
    }
    return $ordered;
}

function employee_issue_is_generic_name_column(string $column): bool
{
    return preg_replace('/[^a-z0-9]/', '', strtolower($column)) === 'name';
}

function employee_issue_assignee_column(array $columns): ?string
{
    return employee_issue_assignee_columns($columns)[0] ?? null;
}

function employee_issue_assignee_assignment_sql(array $columns, string $statusColumn, string $employeeName): array
{
    $assignees = employee_issue_assignee_columns($columns);
    $conditions = [];
    $values = [];
    foreach ($assignees as $assignee) {
        $condition = '(LOWER(TRIM(CAST(' . employee_issue_quote($assignee) . ' AS CHAR))) = LOWER(TRIM(?))';
        $condition .= ' AND ' . employee_issue_assignee_status_condition($assignee, $statusColumn);
        $conditions[] = $condition . ')';
        $values[] = $employeeName;
    }
    return [$conditions, $values];
}

function employee_issue_assignee_status_condition(string $assignee, string $statusColumn): string
{
    if (!employee_issue_is_generic_name_column($assignee)) {
        return '1=1';
    }
    $status = employee_issue_quote($statusColumn);
    return '(' . $status . ' IS NULL OR TRIM(CAST(' . $status . ' AS CHAR)) = \'\' OR NOT ' .
        employee_issue_available_predicate($statusColumn) . ')';
}

function employee_issue_status_type(PDO $inventory, string $table, string $statusColumn): string
{
    foreach ($inventory->query('SHOW COLUMNS FROM ' . employee_issue_quote($table))->fetchAll(PDO::FETCH_ASSOC) as $column) {
        if (strcasecmp((string)$column['Field'], $statusColumn) === 0) {
            return strtolower((string)$column['Type']);
        }
    }
    return '';
}

function employee_issue_status_supports_received(PDO $inventory, string $table, string $statusColumn): bool
{
    $type = employee_issue_status_type($inventory, $table, $statusColumn);
    if (preg_match('/^(char|varchar|tinytext|text|mediumtext|longtext)\b/', $type)) {
        return true;
    }
    if (!str_starts_with($type, 'enum(')) {
        return false;
    }
    return preg_match("/(?:^|,)\\s*'received'\\s*(?:,|\\))/i", $type) === 1;
}

function employee_issue_available_status(string $status): bool
{
    $normalized = strtolower(trim($status));
    if ($normalized === '' || preg_match('/\b(out of stock|no stock|unavailable|not available|issued|assigned|deployed|checked out|checked-out|in use|borrowed|loaned|allocated|with employee|reserved|repair|broken|damaged|maintenance|disposed)\b/', $normalized)) {
        return false;
    }
    return str_contains($normalized, 'stock') || str_contains($normalized, 'available');
}

function employee_issue_available_predicate(string $statusColumn): string
{
    $status = 'LOWER(CAST(' . employee_issue_quote($statusColumn) . ' AS CHAR))';
    return "(((
        {$status} LIKE '%stock%' AND {$status} NOT LIKE '%out of stock%' AND {$status} NOT LIKE '%no stock%'
    ) OR (
        {$status} LIKE '%available%' AND {$status} NOT LIKE '%unavailable%' AND {$status} NOT LIKE '%not available%'
    )) AND {$status} NOT LIKE '%issued%' AND {$status} NOT LIKE '%assigned%' AND
        {$status} NOT LIKE '%deployed%' AND {$status} NOT LIKE '%checked out%' AND
        {$status} NOT LIKE '%checked-out%' AND {$status} NOT LIKE '%in use%' AND
        {$status} NOT LIKE '%borrowed%' AND {$status} NOT LIKE '%loaned%' AND
        {$status} NOT LIKE '%allocated%' AND {$status} NOT LIKE '%with employee%' AND {$status} NOT LIKE '%reserved%' AND
        {$status} NOT LIKE '%repair%' AND {$status} NOT LIKE '%broken%' AND
        {$status} NOT LIKE '%damaged%' AND {$status} NOT LIKE '%maintenance%' AND {$status} NOT LIKE '%disposed%')";
}

function employee_issue_modules(PDO $inventory, PDO $control): array
{
    $existing = [];
    foreach ($inventory->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $tableName) {
        $existing[strtolower((string)$tableName)] = (string)$tableName;
    }
    $controlTables = [];
    foreach ($control->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $tableName) {
        $controlTables[strtolower((string)$tableName)] = (string)$tableName;
    }
    if (!isset($controlTables['navigation_menu'])) {
        throw new RuntimeException('The account navigation configuration is unavailable.');
    }

    $navigationTable = employee_issue_quote($controlTables['navigation_menu']);
    $statement = $control->query("SELECT table_name FROM {$navigationTable} WHERE is_visible = 1 ORDER BY sort_order");
    $modules = [];
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $registeredName) {
        $registeredName = (string)$registeredName;
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $registeredName)) {
            continue;
        }
        $normalized = strtolower($registeredName);
        if (!isset($existing[$normalized]) || in_array($normalized, [
            'users', 'loginhistory', 'activitylogs', 'remembereddevices',
            'accountlockouts', 'passwordresetrequests', 'system_recycle_bin',
            'navigation_trash'
        ], true)) {
            continue;
        }

        $tableName = $existing[$normalized];
        $columns = employee_issue_columns($inventory, $tableName);
        $identifier = employee_issue_column($columns, ['id', 'asset_code']);
        $status = employee_issue_column($columns, ['status']);
        $assignee = employee_issue_assignee_column($columns);
        $statusSupportsReceived = $status !== null && employee_issue_status_supports_received($inventory, $tableName, $status);
        $returnable = $identifier !== null && $status !== null && $assignee !== null;
        $issueable = $returnable && $statusSupportsReceived;
        if ($returnable && !isset($columns['last_user'])) {
            try {
                $inventory->exec(
                    'ALTER TABLE ' . employee_issue_quote($tableName) .
                    ' ADD COLUMN last_user VARCHAR(150) NULL'
                );
            } catch (PDOException $error) {
                $columns = employee_issue_columns($inventory, $tableName);
                if (!isset($columns['last_user'])) {
                    throw $error;
                }
            }
            $columns = employee_issue_columns($inventory, $tableName);
        }
        $modules[] = [
            'name' => $tableName,
            'label' => ucwords(str_replace('_', ' ', $tableName)),
            'returnable' => $returnable,
            'issueable' => $issueable
        ];
    }
    return $modules;
}

function employee_issue_find_module(PDO $inventory, array $modules, string $name): array
{
    foreach ($modules as $module) {
        if (strcasecmp($module['name'], $name) === 0) {
            $module['columns'] = employee_issue_columns($inventory, $module['name']);
            return $module;
        }
    }
    throw new InvalidArgumentException('Choose a visible inventory module from this account.');
}

function employee_issue_read_assets(PDO $inventory, array $module, string $search, int $offset = 0): array
{
    if (!$module['issueable']) {
        return ['assets' => [], 'has_more' => false];
    }
    $columns = $module['columns'];
    $identifier = employee_issue_column($columns, ['id', 'asset_code']);
    $status = employee_issue_column($columns, ['status']);
    $selectKeys = ['asset_code', 'ref_no', 'serial_number', 'serial_no', 'model', 'name', 'status'];
    $selected = [];
    if ($identifier !== null) {
        $selected[] = $identifier;
    }
    foreach ($selectKeys as $key) {
        if (isset($columns[$key]) && !in_array($columns[$key], $selected, true)) {
            $selected[] = $columns[$key];
        }
    }

    $where = employee_issue_available_predicate($status);
    $values = [];
    if ($search !== '') {
        $searchColumns = [];
        $searchColumnNames = ['asset_code', 'ref_no', 'serial_number', 'serial_no', 'model', 'name'];
        if ($identifier !== null) {
            $searchColumnNames[] = strtolower($identifier);
        }
        foreach (array_unique($searchColumnNames) as $key) {
            if (isset($columns[$key]) && !in_array($columns[$key], array_column($searchColumns, 'column'), true)) {
                $searchColumns[] = [
                    'sql' => 'LOWER(CAST(' . employee_issue_quote($columns[$key]) . " AS CHAR)) LIKE LOWER(?) ESCAPE '!'",
                    'column' => $columns[$key]
                ];
            }
        }
        if ($searchColumns) {
            $where = '(' . $where . ') AND (' . implode(' OR ', array_column($searchColumns, 'sql')) . ')';
            foreach ($searchColumns as $_) {
                $values[] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
            }
        }
    }

    $sql = 'SELECT ' . implode(', ', array_map('employee_issue_quote', $selected)) .
        ' FROM ' . employee_issue_quote($module['name']) . ' WHERE ' . $where .
        ' ORDER BY ' . employee_issue_quote($identifier) . ' LIMIT 101 OFFSET ' . $offset;
    $statement = $inventory->prepare($sql);
    $statement->execute($values);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    $hasMore = count($rows) > 100;
    if ($hasMore) {
        array_pop($rows);
    }
    $assetNumber = employee_issue_column($columns, ['asset_code', 'ref_no', 'serial_number', 'serial_no']);
    foreach ($rows as &$row) {
        $row['id'] = (string)($row[$identifier] ?? '');
        $row['asset_number'] = (string)($assetNumber !== null ? ($row[$assetNumber] ?? '') : $row['id']);
        $row['model'] = (string)(isset($columns['model']) && isset($row[$columns['model']]) ? $row[$columns['model']] : '');
        $row['serial_number'] = (string)(isset($columns['serial_number']) && isset($row[$columns['serial_number']])
            ? $row[$columns['serial_number']]
            : (isset($columns['serial_no']) && isset($row[$columns['serial_no']]) ? $row[$columns['serial_no']] : ''));
        $row['status'] = (string)($row[$status] ?? '');
    }
    unset($row);
    return ['assets' => $rows, 'has_more' => $hasMore];
}

function employee_issue_employees(PDO $inventory, array $modules, string $search): array
{
    $matches = [];
    $seen = [];
    $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
    foreach ($modules as $module) {
        if (empty($module['returnable'])) {
            continue;
        }
        $columns = employee_issue_columns($inventory, $module['name']);
        $nameColumns = employee_issue_assignee_columns($columns);
        $status = employee_issue_column($columns, ['status']);
        if (!$nameColumns || $status === null) {
            continue;
        }
        $department = employee_issue_column($columns, ['department']);
        $workArea = employee_issue_column($columns, ['work_area', 'workarea']);
        $departmentSelect = $department === null ? "''" : 'MAX(' . employee_issue_quote($department) . ')';
        $workAreaSelect = $workArea === null ? "''" : 'MAX(' . employee_issue_quote($workArea) . ')';
        foreach ($nameColumns as $name) {
            $sql = 'SELECT ' . employee_issue_quote($name) . ' AS employee_name, ' . $departmentSelect .
                ' AS department, ' . $workAreaSelect . ' AS work_area FROM ' . employee_issue_quote($module['name']) .
                ' WHERE ' . employee_issue_assignee_status_condition($name, $status) . ' AND ' .
                employee_issue_quote($name) . ' IS NOT NULL AND TRIM(CAST(' .
                employee_issue_quote($name) . ' AS CHAR)) <> \'\' AND LOWER(CAST(' . employee_issue_quote($name) .
                " AS CHAR)) LIKE LOWER(?) ESCAPE '!' GROUP BY " . employee_issue_quote($name) . ' ORDER BY employee_name LIMIT 30';
            $statement = $inventory->prepare($sql);
            $statement->execute([$pattern]);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $employeeName = trim((string)$row['employee_name']);
                $key = mb_strtolower($employeeName, 'UTF-8');
                if ($employeeName === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $matches[] = [
                    'name' => $employeeName,
                    'department' => (string)$row['department'],
                    'work_area' => (string)$row['work_area']
                ];
                if (count($matches) >= 30) {
                    return $matches;
                }
            }
        }
    }
    return $matches;
}

function employee_issue_assigned_assets(PDO $inventory, array $modules, string $employeeName): array
{
    $rows = [];
    foreach ($modules as $module) {
        if (empty($module['returnable'])) {
            continue;
        }
        $columns = employee_issue_columns($inventory, $module['name']);
        $assignees = employee_issue_assignee_columns($columns);
        $status = employee_issue_column($columns, ['status']);
        $issueDate = employee_issue_column($columns, ['issued_date', 'date_issued', 'issue_date', 'dateissued']);
        if (!$assignees || $status === null) {
            continue;
        }
        [$assigneeConditions, $assigneeValues] = employee_issue_assignee_assignment_sql($columns, $status, $employeeName);
        if (!$assigneeConditions) {
            continue;
        }
        $selected = [];
        foreach (['asset_code', 'ref_no', 'serial_number', 'serial_no', 'model', 'name', 'department', 'work_area', 'issued_date', 'date_issued', 'issue_date', 'dateissued', 'status'] as $key) {
            if (isset($columns[$key]) && !in_array($columns[$key], $selected, true)) {
                $selected[] = $columns[$key];
            }
        }
        if ($issueDate !== null && !in_array($issueDate, $selected, true)) {
            $selected[] = $issueDate;
        }
        $identifier = employee_issue_column($columns, ['id', 'asset_code']);
        if ($identifier !== null && !in_array($identifier, $selected, true)) {
            $selected[] = $identifier;
        }
        if (isset($columns['last_user']) && !in_array($columns['last_user'], $selected, true)) {
            $selected[] = $columns['last_user'];
        }
        foreach ($assignees as $assignee) {
            if (!in_array($assignee, $selected, true)) {
                $selected[] = $assignee;
            }
        }
        if (!$selected) {
            continue;
        }
        $sql = 'SELECT ' . implode(', ', array_map('employee_issue_quote', $selected)) .
            ' FROM ' . employee_issue_quote($module['name']) . ' WHERE (' .
            implode(' OR ', $assigneeConditions) . ')';
        $statement = $inventory->prepare($sql);
        $statement->execute($assigneeValues);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $record) {
            $record['module'] = $module['label'];
            $record['table'] = $module['name'];
            $record['id'] = (string)($identifier !== null ? ($record[$identifier] ?? '') : '');
            $record['date_issued'] = (string)($issueDate !== null ? ($record[$issueDate] ?? '') : '');
            $record['last_user'] = (string)(isset($columns['last_user'], $record[$columns['last_user']]) ? $record[$columns['last_user']] : '');
            $rows[] = $record;
        }
    }
    return $rows;
}

function employee_issue_blank_fields(PDO $inventory, array $module, string $recordId): array
{
    if (!$module['issueable']) {
        throw new InvalidArgumentException($module['label'] . ' is not configured for asset issuance.');
    }
    $columns = $module['columns'];
    $identifier = employee_issue_column($columns, ['id', 'asset_code']);
    $statement = $inventory->prepare(
        'SELECT * FROM ' . employee_issue_quote($module['name']) .
        ' WHERE ' . employee_issue_quote($identifier) . ' = ? LIMIT 2' .
        ($inventory->inTransaction() ? ' FOR UPDATE' : '')
    );
    $statement->execute([$recordId]);
    $records = $statement->fetchAll(PDO::FETCH_ASSOC);
    if (count($records) !== 1) {
        throw new EmployeeIssueConflict('The selected asset could not be identified uniquely. Refresh the asset list and try again.');
    }

    $row = $records[0];
    $schema = employee_issue_schema($inventory, $module['name']);
    $status = employee_issue_column($columns, ['status']);
    if (!employee_issue_available_status((string)($row[$status] ?? ''))) {
        throw new EmployeeIssueConflict('This asset is no longer available. Refresh the module list and try again.');
    }
    $assignee = employee_issue_assignee_column($columns);
    $issueDate = employee_issue_column($columns, ['issued_date', 'date_issued', 'issue_date', 'dateissued']);
    $protected = array_filter([$identifier, $status, $assignee, $issueDate]);
    $fields = [];

    foreach ($schema as $normalizedName => $column) {
        $fieldName = (string)$column['Field'];
        $rawFieldType = (string)$column['Type'];
        $fieldType = strtolower($rawFieldType);
        $extra = strtolower((string)($column['Extra'] ?? ''));
        if (in_array($fieldName, $protected, true) ||
            $normalizedName === 'last_user' ||
            in_array($normalizedName, ['department', 'work_area', 'workarea', 'jobtrade', 'job_trade', 'nationality'], true) ||
            preg_match('/password|passwd|secret|token|credential|api.?key|private.?key|hash|salt/i', $fieldName) ||
            preg_match('/auto_increment|generated/', $extra) ||
            preg_match('/^(created_at|updated_at|deleted_at|created_on|updated_on)$/i', $fieldName)) {
            continue;
        }
        $value = $row[$fieldName] ?? null;
        if ($value !== null && trim((string)$value) !== '') {
            continue;
        }

        $inputType = 'text';
        if (preg_match('/^(date|datetime|timestamp)\b/', $fieldType)) {
            $inputType = 'date';
        } elseif (preg_match('/^(tinyint|smallint|mediumint|int|bigint)\b/', $fieldType)) {
            $inputType = 'number';
        } elseif (preg_match('/^(decimal|float|double)\b/', $fieldType)) {
            $inputType = 'number';
        } elseif (preg_match('/^(text|mediumtext|longtext)\b/', $fieldType)) {
            $inputType = 'textarea';
        }

        $choices = [];
        if (str_starts_with($fieldType, 'enum(')) {
            preg_match_all("/'((?:\\\\.|[^'])*)'/", $rawFieldType, $matches);
            $choices = array_values(array_filter(
                array_map('stripcslashes', $matches[1] ?? []),
                static fn(string $choice): bool => $choice !== ''
            ));
            if ($choices) {
                $inputType = 'select';
            }
        }
        $maxLength = 4000;
        if (preg_match('/^(?:var)?char\((\d+)\)/', $fieldType, $lengthMatch)) {
            $maxLength = (int)$lengthMatch[1];
        } elseif (preg_match('/^(?:tinytext|tinyblob)\b/', $fieldType)) {
            $maxLength = 255;
        }

        $fields[] = [
            'name' => $fieldName,
            'label' => ucwords(str_replace('_', ' ', $fieldName)),
            'type' => $inputType,
            'max_length' => $maxLength,
            'required' => strtoupper((string)$column['Null']) === 'NO' &&
                $column['Default'] === null &&
                !str_contains($extra, 'auto_increment'),
            'choices' => $choices
        ];
    }

    return $fields;
}

function employee_issue_validate_field_value(array $field, mixed $value): string
{
    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        throw new InvalidArgumentException('A module field value is invalid.');
    }
    $value = trim((string)$value);
    if ($value === '') {
        if ($field['required']) {
            throw new InvalidArgumentException($field['label'] . ' is required before this asset can be issued.');
        }
        return '';
    }
    if (mb_strlen($value, 'UTF-8') > (int)($field['max_length'] ?? 4000)) {
        throw new InvalidArgumentException($field['label'] . ' is too long.');
    }
    if ($field['type'] === 'number' && !is_numeric($value)) {
        throw new InvalidArgumentException($field['label'] . ' must be numeric.');
    }
    if ($field['type'] === 'date') {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException($field['label'] . ' must be a valid date.');
        }
    }
    if ($field['type'] === 'select' && !in_array($value, $field['choices'], true)) {
        throw new InvalidArgumentException('Choose a valid value for ' . $field['label'] . '.');
    }
    return $value;
}

function employee_issue_apply(PDO $inventory, array $modules, array $employee, array $selections, array &$undoChanges): int
{
    $name = trim((string)($employee['name'] ?? ''));
    $department = trim((string)($employee['department'] ?? ''));
    $workArea = trim((string)($employee['work_area'] ?? ''));
    $jobTrade = trim((string)($employee['job_trade'] ?? ''));
    $nationality = trim((string)($employee['nationality'] ?? ''));
    if ($name === '' || mb_strlen($name, 'UTF-8') > 150 ||
        mb_strlen($department, 'UTF-8') > 150 || mb_strlen($workArea, 'UTF-8') > 150 ||
        mb_strlen($jobTrade, 'UTF-8') > 150 || mb_strlen($nationality, 'UTF-8') > 100) {
        throw new InvalidArgumentException('Check the employee details and allowed field lengths.');
    }
    if (!$selections || count($selections) > 100) {
        throw new InvalidArgumentException('Select between 1 and 100 assets to issue.');
    }

    $inventory->beginTransaction();
    $updated = 0;
    $seen = [];
    try {
        foreach ($selections as $selection) {
            if (!is_array($selection)) {
                throw new InvalidArgumentException('The selected asset list is invalid.');
            }
            $moduleName = trim((string)($selection['table'] ?? ''));
            $recordId = trim((string)($selection['id'] ?? ''));
            if ($recordId === '' || mb_strlen($recordId, 'UTF-8') > 200) {
                throw new InvalidArgumentException('A selected asset is missing its record identifier.');
            }
            $module = employee_issue_find_module($inventory, $modules, $moduleName);
            if (!$module['issueable']) {
                throw new InvalidArgumentException($module['label'] . ' does not have the fields required to issue assets.');
            }
            $duplicateKey = strtolower($module['name']) . ':' . $recordId;
            if (isset($seen[$duplicateKey])) {
                throw new InvalidArgumentException('The selected asset list contains a duplicate.');
            }
            $seen[$duplicateKey] = true;

            $columns = $module['columns'];
            $identifier = employee_issue_column($columns, ['id', 'asset_code']);
            $status = employee_issue_column($columns, ['status']);
            $assignee = employee_issue_assignee_column($columns);
            $matchCount = $inventory->prepare(
                'SELECT COUNT(*) FROM ' . employee_issue_quote($module['name']) .
                ' WHERE ' . employee_issue_quote($identifier) . ' = ?'
            );
            $matchCount->execute([$recordId]);
            if ((int)$matchCount->fetchColumn() !== 1) {
                throw new EmployeeIssueConflict('A selected asset could not be identified uniquely in ' . $module['label'] . '.');
            }
            $recordQuery = $inventory->prepare(
                'SELECT * FROM ' . employee_issue_quote($module['name']) .
                ' WHERE ' . employee_issue_quote($identifier) . ' = ? LIMIT 1 FOR UPDATE'
            );
            $recordQuery->execute([$recordId]);
            $record = $recordQuery->fetch(PDO::FETCH_ASSOC);
            $currentStatus = $record[$status] ?? false;
            if ($currentStatus === false || !employee_issue_available_status((string)$currentStatus)) {
                throw new EmployeeIssueConflict('An asset is no longer available in ' . $module['label'] . '. Refresh the list and try again.');
            }

            $updates = [
                $assignee => $name,
                $status => 'RECEIVED',
                $columns['last_user'] => $name
            ];
            foreach ([
                'department' => $department,
                'work_area' => $workArea,
                'workarea' => $workArea,
                'jobtrade' => $jobTrade,
                'job_trade' => $jobTrade,
                'nationality' => $nationality
            ] as $field => $value) {
                if ($value !== '' && isset($columns[$field])) {
                    $updates[$columns[$field]] = $value;
                }
            }
            $issueDate = employee_issue_column($columns, ['issued_date', 'date_issued', 'issue_date', 'dateissued']);
            if ($issueDate !== null) {
                $updates[$issueDate] = date('Y-m-d');
            }
            $schema = employee_issue_schema($inventory, $module['name']);
            $inputFields = $selection['fields'] ?? [];
            if (!is_array($inputFields)) {
                throw new InvalidArgumentException('Module form values are invalid.');
            }
            $blankFields = [];
            foreach (employee_issue_blank_fields($inventory, $module, $recordId) as $field) {
                $blankFields[strtolower($field['name'])] = $field;
            }
            $submittedFieldNames = array_change_key_case($inputFields, CASE_LOWER);
            foreach ($inputFields as $fieldName => $fieldValue) {
                if (!is_string($fieldName) || !isset($blankFields[strtolower($fieldName)])) {
                    throw new InvalidArgumentException('A submitted value is not a blank field in ' . $module['label'] . '.');
                }
                $field = $blankFields[strtolower($fieldName)];
                $columnName = $schema[strtolower($fieldName)]['Field'];
                if (isset($updates[$columnName])) {
                    throw new InvalidArgumentException('A module-managed field cannot be changed in the issuance form.');
                }
                $validatedValue = employee_issue_validate_field_value($field, $fieldValue);
                if ($validatedValue !== '') {
                    $updates[$columnName] = $validatedValue;
                }
            }
            foreach (array_keys($updates) as $columnName) {
                $submittedFieldNames[strtolower($columnName)] = true;
            }
            foreach ($blankFields as $fieldName => $field) {
                if ($field['required'] && !array_key_exists($fieldName, $submittedFieldNames)) {
                    throw new InvalidArgumentException($field['label'] . ' is required before this asset can be issued.');
                }
            }

            $setParts = [];
            foreach ($updates as $column => $_) {
                $setParts[] = employee_issue_quote($column) . ' = ?';
            }
            $updateSql = 'UPDATE ' . employee_issue_quote($module['name']) . ' SET ' .
                implode(', ', $setParts) . ' WHERE ' . employee_issue_quote($identifier) .
                ' = ? AND ' . employee_issue_available_predicate($status) . ' LIMIT 1';
            $update = $inventory->prepare($updateSql);
            $update->execute(array_merge(array_values($updates), [$recordId]));
            if ($update->rowCount() !== 1) {
                throw new EmployeeIssueConflict('An asset could not be issued because it changed during this request. Refresh and try again.');
            }
            $afterQuery = $inventory->prepare(
                'SELECT * FROM ' . employee_issue_quote($module['name']) .
                ' WHERE ' . employee_issue_quote($identifier) . ' = ? LIMIT 1'
            );
            $afterQuery->execute([$recordId]);
            $after = $afterQuery->fetch(PDO::FETCH_ASSOC);
            if (!$after) {
                throw new EmployeeIssueConflict('The issued asset could not be verified for Undo.');
            }
            $undoChanges[] = [
                'table' => $module['name'],
                'key' => $identifier,
                'before' => $record,
                'after' => $after
            ];
            $updated++;
        }
        $inventory->commit();
    } catch (Throwable $error) {
        if ($inventory->inTransaction()) {
            $inventory->rollBack();
        }
        throw $error;
    }

    return $updated;
}

function employee_issue_return_status(PDO $inventory, string $table, string $statusColumn): string
{
    $type = employee_issue_status_type($inventory, $table, $statusColumn);
    if (!str_starts_with($type, 'enum(')) {
        return 'STOCKS';
    }
    preg_match_all("/'((?:\\\\.|[^'])*)'/", $type, $matches);
    foreach ($matches[1] ?? [] as $choice) {
        $choice = stripcslashes($choice);
        if ($choice === 'STOCKS') {
            return $choice;
        }
    }
    throw new InvalidArgumentException(
        'This module status field must allow the STOCKS value before assets can be returned.'
    );
}

function employee_issue_return(PDO $inventory, array $modules, array $employee, array $selections, array &$undoChanges): int
{
    $name = trim((string)($employee['name'] ?? ''));
    if ($name === '' || mb_strlen($name, 'UTF-8') > 150 || !$selections || count($selections) > 100) {
        throw new InvalidArgumentException('Choose an employee and between 1 and 100 assigned assets to return.');
    }

    $inventory->beginTransaction();
    $returned = 0;
    $seen = [];
    try {
        foreach ($selections as $selection) {
            if (!is_array($selection)) {
                throw new InvalidArgumentException('The selected return list is invalid.');
            }
            $module = employee_issue_find_module($inventory, $modules, trim((string)($selection['table'] ?? '')));
            $recordId = trim((string)($selection['id'] ?? ''));
            if (empty($module['returnable']) || $recordId === '' || mb_strlen($recordId, 'UTF-8') > 200) {
                throw new InvalidArgumentException('A returned asset selection is invalid.');
            }
            $duplicateKey = strtolower($module['name']) . ':' . $recordId;
            if (isset($seen[$duplicateKey])) {
                throw new InvalidArgumentException('The return list contains a duplicate asset.');
            }
            $seen[$duplicateKey] = true;

            $columns = $module['columns'];
            $identifier = employee_issue_column($columns, ['id', 'asset_code']);
            $status = employee_issue_column($columns, ['status']);
            $assignees = employee_issue_assignee_columns($columns);
            $select = $inventory->prepare(
                'SELECT *' .
                ' FROM ' . employee_issue_quote($module['name']) .
                ' WHERE ' . employee_issue_quote($identifier) . ' = ? LIMIT 2 FOR UPDATE'
            );
            $select->execute([$recordId]);
            $matchingRecords = $select->fetchAll(PDO::FETCH_ASSOC);
            if (count($matchingRecords) !== 1) {
                throw new EmployeeIssueConflict('An asset is no longer assigned to this employee. Refresh the list and try again.');
            }
            $record = $matchingRecords[0];
            $currentStatus = (string)($record[$status] ?? '');
            $explicitAssignees = [];
            $legacyNameMatches = [];
            foreach ($assignees as $assigneeColumn) {
                $isLegacyNameField = employee_issue_is_generic_name_column($assigneeColumn);
                if (strcasecmp(trim((string)($record[$assigneeColumn] ?? '')), $name) !== 0 ||
                    ($isLegacyNameField && employee_issue_available_status($currentStatus))) {
                    continue;
                }
                if ($isLegacyNameField) {
                    $legacyNameMatches[] = $assigneeColumn;
                } else {
                    $explicitAssignees[] = $assigneeColumn;
                }
            }
            $matchedAssignees = $explicitAssignees ?: $legacyNameMatches;
            if (!$matchedAssignees) {
                throw new EmployeeIssueConflict('This asset is no longer assigned to this employee. Refresh the list and try again.');
            }
            $currentAssignee = $name;

            $updates = [
                $columns['last_user'] => $currentAssignee,
                $status => employee_issue_return_status($inventory, $module['name'], $status)
            ];
            foreach ($matchedAssignees as $assigneeColumn) {
                $updates[$assigneeColumn] = '';
            }
            foreach (['department', 'work_area', 'workarea', 'jobtrade', 'job_trade', 'nationality', 'job_number'] as $field) {
                if (isset($columns[$field])) {
                    $updates[$columns[$field]] = '';
                }
            }
            $issueDate = employee_issue_column($columns, ['issued_date', 'date_issued', 'issue_date', 'dateissued']);
            if ($issueDate !== null) {
                $schema = employee_issue_schema($inventory, $module['name']);
                $updates[$issueDate] = strtoupper((string)$schema[strtolower($issueDate)]['Null']) === 'YES' ? null : '';
            }
            $setParts = array_map(
                static fn(string $column): string => employee_issue_quote($column) . ' = ?',
                array_keys($updates)
            );
            [$assigneeConditions, $assigneeValues] = employee_issue_assignee_assignment_sql($columns, $status, $name);
            $update = $inventory->prepare(
                'UPDATE ' . employee_issue_quote($module['name']) . ' SET ' . implode(', ', $setParts) .
                ' WHERE ' . employee_issue_quote($identifier) . ' = ? AND (' .
                implode(' OR ', $assigneeConditions) . ') LIMIT 1'
            );
            $update->execute([...array_values($updates), $recordId, ...$assigneeValues]);
            if ($update->rowCount() !== 1) {
                throw new EmployeeIssueConflict('An asset could not be returned because it changed. Refresh and try again.');
            }
            $afterQuery = $inventory->prepare(
                'SELECT * FROM ' . employee_issue_quote($module['name']) .
                ' WHERE ' . employee_issue_quote($identifier) . ' = ? LIMIT 1'
            );
            $afterQuery->execute([$recordId]);
            $after = $afterQuery->fetch(PDO::FETCH_ASSOC);
            if (!$after) {
                throw new EmployeeIssueConflict('The returned asset could not be verified for Undo.');
            }
            $undoChanges[] = [
                'table' => $module['name'],
                'key' => $identifier,
                'before' => $record,
                'after' => $after
            ];
            $returned++;
        }
        $inventory->commit();
    } catch (Throwable $error) {
        if ($inventory->inTransaction()) {
            $inventory->rollBack();
        }
        throw $error;
    }
    return $returned;
}

$method = (string)($_SERVER['REQUEST_METHOD'] ?? '');
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    employee_issue_respond(['success' => false, 'message' => 'Use GET or POST for issuance actions.'], 405);
}

$request = null;
if ($method === 'POST') {
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 32768) {
        employee_issue_respond(['success' => false, 'message' => 'The selected asset request is too large.'], 413);
    }
    $csrfToken = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $sessionToken = (string)($_SESSION['csrf'] ?? '');
    if ($csrfToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $csrfToken)) {
        employee_issue_respond(['success' => false, 'message' => 'Your session token expired. Refresh the page and try again.'], 403);
    }
    $request = json_decode(file_get_contents('php://input'), true);
    if (!is_array($request) || !in_array(($request['action'] ?? ''), ['issue', 'return'], true)) {
        employee_issue_respond(['success' => false, 'message' => 'The issuance request is invalid.'], 400);
    }
}
session_write_close();

try {
    global $DB_HOST, $DB_USER, $DB_PASS, $DB_INVENTORY, $DB_CONTROL;
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ];
    $inventory = new PDO("mysql:host={$DB_HOST};dbname={$DB_INVENTORY};charset=utf8mb4", $DB_USER, $DB_PASS, $options);
    $control = new PDO("mysql:host={$DB_HOST};dbname={$DB_CONTROL};charset=utf8mb4", $DB_USER, $DB_PASS, $options);
    $modules = employee_issue_modules($inventory, $control);

    if ($method === 'GET') {
        $action = (string)($_GET['action'] ?? '');
        if ($action === 'modules') {
            employee_issue_respond(['success' => true, 'modules' => $modules]);
        }
        if ($action === 'employees') {
            $search = trim((string)($_GET['q'] ?? ''));
            if (mb_strlen($search, 'UTF-8') < 2 || mb_strlen($search, 'UTF-8') > 100) {
                employee_issue_respond(['success' => true, 'employees' => []]);
            }
            employee_issue_respond(['success' => true, 'employees' => employee_issue_employees($inventory, $modules, $search)]);
        }
        if ($action === 'assets') {
            $module = employee_issue_find_module($inventory, $modules, (string)($_GET['table'] ?? ''));
            $search = trim((string)($_GET['q'] ?? ''));
            if (mb_strlen($search, 'UTF-8') > 100) {
                employee_issue_respond(['success' => false, 'message' => 'Search text is too long.'], 400);
            }
            $offset = filter_var($_GET['offset'] ?? 0, FILTER_VALIDATE_INT);
            if ($offset === false || $offset < 0 || $offset > 100000) {
                employee_issue_respond(['success' => false, 'message' => 'Asset list position is invalid.'], 400);
            }
            employee_issue_respond(['success' => true] + employee_issue_read_assets($inventory, $module, $search, $offset));
        }
        if ($action === 'asset_fields') {
            $module = employee_issue_find_module($inventory, $modules, (string)($_GET['table'] ?? ''));
            $recordId = trim((string)($_GET['id'] ?? ''));
            if ($recordId === '' || mb_strlen($recordId, 'UTF-8') > 200) {
                employee_issue_respond(['success' => false, 'message' => 'Choose a valid asset.'], 400);
            }
            employee_issue_respond([
                'success' => true,
                'fields' => employee_issue_blank_fields($inventory, $module, $recordId)
            ]);
        }
        if ($action === 'assigned') {
            $name = trim((string)($_GET['name'] ?? ''));
            if ($name === '' || mb_strlen($name, 'UTF-8') > 150) {
                employee_issue_respond(['success' => false, 'message' => 'Choose a valid employee name.'], 400);
            }
            employee_issue_respond(['success' => true, 'assets' => employee_issue_assigned_assets($inventory, $modules, $name)]);
        }
        header('Allow: GET, POST');
        employee_issue_respond(['success' => false, 'message' => 'Choose a supported issuance action.'], 400);
    }

    $undoChanges = [];
    if ($request['action'] === 'return') {
        $updated = employee_issue_return(
            $inventory,
            $modules,
            is_array($request['employee'] ?? null) ? $request['employee'] : [],
            is_array($request['assets'] ?? null) ? $request['assets'] : [],
            $undoChanges
        );
        $message = $updated . ' asset(s) returned. Employee details were cleared; asset information was kept.';
        $activityAction = 'Returned inventory assets';
    } else {
        $updated = employee_issue_apply(
            $inventory,
            $modules,
            is_array($request['employee'] ?? null) ? $request['employee'] : [],
            is_array($request['assets'] ?? null) ? $request['assets'] : [],
            $undoChanges
        );
        $message = $updated . ' asset(s) issued successfully.';
        $activityAction = 'Issued inventory assets';
    }
    try {
        record_user_activity($activityAction, 'inventory', [
            'asset_count' => $updated,
            'modules' => array_values(array_unique(array_map(
                static fn(array $item): string => (string)($item['table'] ?? ''),
                $request['assets']
            )))
        ]);
    } catch (Throwable $error) {
        error_log('Employee assets were updated but activity logging failed: ' . $error->getMessage());
        $message .= ' The assets were updated, but the activity log could not be saved; do not repeat this action.';
    }
    $undoToken = null;
    try {
        $undoToken = asset_undo_store($control, (int)($_SESSION['uid'] ?? 0), $undoChanges);
    } catch (Throwable $undoError) {
        error_log('Employee asset action completed but Undo could not be saved: ' . $undoError->getMessage());
        $message .= ' Undo could not be saved for this action.';
    }
    employee_issue_respond(['success' => true, 'message' => $message, 'undo_token' => $undoToken]);
} catch (InvalidArgumentException $error) {
    employee_issue_respond(['success' => false, 'message' => $error->getMessage()], 400);
} catch (EmployeeIssueConflict $error) {
    employee_issue_respond(['success' => false, 'message' => $error->getMessage()], 409);
} catch (Throwable $error) {
    error_log('Employee asset action failed: ' . $error->getMessage());
    employee_issue_respond(['success' => false, 'message' => 'The asset action could not be completed. Check the server log or try again.'], 500);
}
