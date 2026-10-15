<?php
declare(strict_types=1);

function asset_undo_quote(string $identifier): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new InvalidArgumentException('Undo contains an invalid database identifier.');
    }
    return '`' . $identifier . '`';
}

function asset_undo_ensure_table(PDO $control): void
{
    $control->exec(
        'CREATE TABLE IF NOT EXISTS app_undo_journal (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            token_hash CHAR(64) NOT NULL UNIQUE,
            user_id BIGINT UNSIGNED NOT NULL,
            payload LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            INDEX idx_app_undo_user_expiry(user_id, expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function asset_undo_store(
    PDO $control,
    int $userId,
    array $changes,
    array $metadata = [],
    ?string $batchToken = null
): ?string
{
    if ($userId <= 0 || !$changes) {
        return null;
    }
    if (count($changes) > 10000) {
        throw new RuntimeException('This change exceeds the safe Undo item limit.');
    }
    $payload = json_encode(
        ['changes' => $changes] + $metadata,
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($payload === false || strlen($payload) > 16 * 1024 * 1024) {
        throw new RuntimeException('The change is too large to offer a safe Undo action.');
    }

    if ($batchToken !== null && !preg_match('/^[a-f0-9]{64}$/', $batchToken)) {
        throw new InvalidArgumentException('The Undo batch identifier is invalid.');
    }
    asset_undo_ensure_table($control);
    $token = $batchToken ?? bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $control->beginTransaction();
    try {
        if ($batchToken !== null) {
            $existing = $control->prepare(
                'SELECT payload FROM app_undo_journal WHERE token_hash = ? AND user_id = ? '
                . 'AND used_at IS NULL AND expires_at > NOW() LIMIT 1 FOR UPDATE'
            );
            $existing->execute([$tokenHash, $userId]);
            $existingPayload = $existing->fetchColumn();
            if (is_string($existingPayload)) {
                $decoded = json_decode($existingPayload, true);
                $decoded = is_array($decoded) ? $decoded : [];
                $priorChanges = is_array($decoded['changes'] ?? null) ? $decoded['changes'] : [];
                $changes = [...$priorChanges, ...$changes];
                $metadata = array_replace($decoded, $metadata);
                unset($metadata['changes']);
            }
        }
        $control->prepare(
            'DELETE FROM app_undo_journal WHERE expires_at <= NOW() OR (user_id = ? AND token_hash <> ?)'
        )->execute([$userId, $tokenHash]);
        $payload = json_encode(
            ['changes' => $changes] + $metadata,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($payload === false || strlen($payload) > 16 * 1024 * 1024) {
            throw new RuntimeException('The change is too large to offer a safe Undo action.');
        }
        $statement = $control->prepare(
            'INSERT INTO app_undo_journal (token_hash,user_id,payload,created_at,expires_at) '
            . 'VALUES (?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 30 SECOND)) '
            . 'ON DUPLICATE KEY UPDATE payload = VALUES(payload), created_at = NOW(), '
            . 'expires_at = DATE_ADD(NOW(), INTERVAL 30 SECOND), used_at = NULL'
        );
        $statement->execute([$tokenHash, $userId, $payload]);
        $control->commit();
    } catch (Throwable $error) {
        if ($control->inTransaction()) {
            $control->rollBack();
        }
        throw $error;
    }
    return $token;
}

function asset_undo_normalized_row(array $row): array
{
    ksort($row);
    foreach ($row as $key => $value) {
        if (is_scalar($value) && $value !== null) {
            $row[$key] = (string)$value;
        }
    }
    return $row;
}

function asset_undo_current_row(PDO $inventory, string $table, string $key, mixed $value): ?array
{
    $statement = $inventory->prepare(
        'SELECT * FROM ' . asset_undo_quote($table) . ' WHERE ' . asset_undo_quote($key) . ' = ? LIMIT 2 FOR UPDATE'
    );
    $statement->execute([$value]);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) > 1) {
        throw new RuntimeException('Undo stopped because the record identifier is not unique.');
    }
    return $rows[0] ?? null;
}

function asset_undo_apply_change(PDO $inventory, array $change): void
{
    $table = (string)($change['table'] ?? '');
    $key = (string)($change['key'] ?? '');
    $before = $change['before'] ?? null;
    $after = $change['after'] ?? null;
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $key)
        || (!is_array($before) && $before !== null) || (!is_array($after) && $after !== null)) {
        throw new RuntimeException('Undo data is invalid.');
    }

    $expected = $after;
    if ($expected === null) {
        if (!is_array($before) || !array_key_exists($key, $before)) {
            throw new RuntimeException('Undo data is missing the original record.');
        }
        if (asset_undo_current_row($inventory, $table, $key, $before[$key]) !== null) {
            throw new RuntimeException('Undo stopped because another record now uses the original identifier.');
        }
        $schema = $inventory->query('SHOW COLUMNS FROM ' . asset_undo_quote($table))->fetchAll(PDO::FETCH_ASSOC);
        $insertValues = [];
        foreach ($before as $column => $value) {
            $columnSchema = null;
            foreach ($schema as $candidate) {
                if ((string)$candidate['Field'] === (string)$column) {
                    $columnSchema = $candidate;
                    break;
                }
            }
            if ($columnSchema === null || preg_match('/\b(?:virtual|stored)\s+generated\b/i', (string)($columnSchema['Extra'] ?? ''))) {
                continue;
            }
            $insertValues[$column] = $value;
        }
        if (!$insertValues) {
            throw new RuntimeException('Undo cannot restore this record safely.');
        }
        $columns = array_keys($insertValues);
        $statement = $inventory->prepare(
            'INSERT INTO ' . asset_undo_quote($table) . ' (' .
            implode(', ', array_map('asset_undo_quote', $columns)) . ') VALUES (' .
            implode(', ', array_fill(0, count($columns), '?')) . ')'
        );
        $statement->execute(array_values($insertValues));
        return;
    }

    if (!array_key_exists($key, $expected)) {
        throw new RuntimeException('Undo data is missing the expected record identifier.');
    }
    $current = asset_undo_current_row($inventory, $table, $key, $expected[$key]);
    if ($current === null || asset_undo_normalized_row($current) !== asset_undo_normalized_row($expected)) {
        throw new RuntimeException('Undo stopped because this record has changed since the successful update.');
    }

    if ($before === null) {
        $delete = $inventory->prepare(
            'DELETE FROM ' . asset_undo_quote($table) . ' WHERE ' . asset_undo_quote($key) . ' = ? LIMIT 1'
        );
        $delete->execute([$expected[$key]]);
        if ($delete->rowCount() !== 1) {
            throw new RuntimeException('Undo could not remove the newly created record.');
        }
        return;
    }

    if (!is_array($before) || !array_key_exists($key, $before)) {
        throw new RuntimeException('Undo data is missing the previous record.');
    }
    $updates = [];
    foreach ($before as $column => $value) {
        if (array_key_exists($column, $expected) && $value !== $expected[$column] && $column !== $key) {
            $updates[$column] = $value;
        }
    }
    if (!$updates) {
        return;
    }
    $assignments = array_map(
        static fn(string $column): string => asset_undo_quote($column) . ' = ?',
        array_keys($updates)
    );
    $statement = $inventory->prepare(
        'UPDATE ' . asset_undo_quote($table) . ' SET ' . implode(', ', $assignments) .
        ' WHERE ' . asset_undo_quote($key) . ' = ? LIMIT 1'
    );
    $statement->execute([...array_values($updates), $expected[$key]]);
}
