<?php

require_once __DIR__ . '/config.php';

function activity_archive_directory(): string
{
    $directory = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '.activity-archive';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create the protected activity archive folder.');
    }
    return $directory;
}

function with_activity_archive_lock(callable $callback)
{
    $directory = activity_archive_directory();
    $lock = fopen($directory . DIRECTORY_SEPARATOR . '.archive.lock', 'c');
    if ($lock === false) {
        throw new RuntimeException('Could not open the activity archive lock.');
    }

    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('Could not lock the activity archive.');
        }
        return $callback($directory);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function activity_archive_rows(PDO $pdo, string $directory): void
{
    $statement = $pdo->query(
        'SELECT logs.LogID, logs.UserID, logs.Action, logs.Module, logs.DateTime, logs.Details,
                COALESCE(NULLIF(TRIM(CONCAT(users.FirstName, " ", users.LastName)), ""), users.Username, "Unknown user") AS ProfileName
         FROM ActivityLogs AS logs
         LEFT JOIN Users AS users ON users.UserID = logs.UserID
         WHERE logs.UserID IS NOT NULL
           AND logs.DateTime < DATE_SUB(NOW(), INTERVAL 1 MONTH)
         ORDER BY logs.DateTime ASC, logs.LogID ASC
         LIMIT 500'
    );
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return;
    }

    $groups = [];
    foreach ($rows as $row) {
        $month = (new DateTimeImmutable((string)$row['DateTime']))->format('Y-m');
        $userId = (int)$row['UserID'];
        $groups[$userId . ':' . $month]['user_id'] = $userId;
        $groups[$userId . ':' . $month]['month'] = $month;
        $groups[$userId . ':' . $month]['entries'][] = $row;
    }

    foreach ($groups as $group) {
        $logIds = array_map(static fn(array $entry): int => (int)$entry['LogID'], $group['entries']);
        $file = sprintf(
            'user-%d-%s-%d-%d.json',
            $group['user_id'],
            $group['month'],
            min($logIds),
            max($logIds)
        );
        $path = $directory . DIRECTORY_SEPARATOR . $file;

        if (is_file($path)) {
            $existing = json_decode((string)file_get_contents($path), true);
            if (!is_array($existing) || !is_array($existing['entries'] ?? null)) {
                throw new RuntimeException('An existing activity archive file is invalid: ' . $file);
            }

            $existingIds = array_map(
                static fn(array $entry): int => (int)($entry['LogID'] ?? 0),
                $existing['entries']
            );
            if (!array_diff($logIds, $existingIds)) {
                continue;
            }

            $entriesById = [];
            foreach ($existing['entries'] as $entry) {
                if (is_array($entry) && isset($entry['LogID'])) {
                    $entriesById[(int)$entry['LogID']] = $entry;
                }
            }
            foreach ($group['entries'] as $entry) {
                $entriesById[(int)$entry['LogID']] = $entry;
            }
            $group['entries'] = array_values($entriesById);
        }

        $document = [
            'schema_version' => 1,
            'user_id' => $group['user_id'],
            'month' => $group['month'],
            'archived_at' => date(DATE_ATOM),
            'entries' => $group['entries']
        ];
        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            throw new RuntimeException('Could not encode expired activity as JSON.');
        }

        $temporaryPath = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temporaryPath, $json, LOCK_EX) === false) {
            throw new RuntimeException('Could not write the temporary activity archive.');
        }
        if (!rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new RuntimeException('Could not finalize the JSON activity archive.');
        }
    }

    $placeholders = implode(',', array_fill(0, count($rows), '?'));
    $delete = $pdo->prepare("DELETE FROM ActivityLogs WHERE LogID IN ({$placeholders}) AND DateTime < DATE_SUB(NOW(), INTERVAL 1 MONTH)");
    $delete->execute(array_column($rows, 'LogID'));
}

function load_user_activity_history(PDO $pdo, int $userId): array
{
    return with_activity_archive_lock(static function (string $directory) use ($pdo, $userId): array {
        activity_archive_rows($pdo, $directory);

        $entriesById = [];
        $archivePattern = $directory . DIRECTORY_SEPARATOR . 'user-' . $userId . '-*.json';
        foreach (glob($archivePattern) ?: [] as $path) {
            $archive = json_decode((string)file_get_contents($path), true);
            if (!is_array($archive) || (int)($archive['user_id'] ?? 0) !== $userId || !is_array($archive['entries'] ?? null)) {
                throw new RuntimeException('An activity archive file is invalid: ' . basename($path));
            }
            foreach ($archive['entries'] as $entry) {
                if (is_array($entry) && isset($entry['LogID'])) {
                    $entriesById[(int)$entry['LogID']] = $entry;
                }
            }
        }

        $recent = $pdo->prepare(
            'SELECT logs.LogID, logs.UserID, logs.Action, logs.Module, logs.DateTime, logs.Details,
                    COALESCE(NULLIF(TRIM(CONCAT(users.FirstName, " ", users.LastName)), ""), users.Username, "Unknown user") AS ProfileName
             FROM ActivityLogs AS logs
             LEFT JOIN Users AS users ON users.UserID = logs.UserID
             WHERE logs.UserID = :user_id
             ORDER BY logs.DateTime DESC, logs.LogID DESC'
        );
        $recent->execute([':user_id' => $userId]);
        foreach ($recent->fetchAll(PDO::FETCH_ASSOC) as $entry) {
            $entriesById[(int)$entry['LogID']] = $entry;
        }

        $entries = array_values($entriesById);
        usort($entries, static function (array $left, array $right): int {
            $byDate = strcmp((string)$right['DateTime'], (string)$left['DateTime']);
            return $byDate !== 0 ? $byDate : (int)$right['LogID'] <=> (int)$left['LogID'];
        });
        return $entries;
    });
}
