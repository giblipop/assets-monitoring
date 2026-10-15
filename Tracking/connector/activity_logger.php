<?php
require_once __DIR__ . '/config.php';

function record_user_activity(string $action, string $module, array $details = []): void
{
    $userId = (int)($_SESSION['uid'] ?? 0);
    if ($userId <= 0) {
        throw new RuntimeException('Cannot record activity without an authenticated user.');
    }

    global $DB_HOST, $DB_USER, $DB_PASS;
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname=inventory;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdo->exec("CREATE TABLE IF NOT EXISTS ActivityLogs (
        LogID BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        UserID BIGINT UNSIGNED NULL,
        Action VARCHAR(100) NOT NULL,
        Module VARCHAR(100) NOT NULL,
        DateTime DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        DeviceName VARCHAR(255) NULL,
        IPAddress VARCHAR(45) NULL,
        Details JSON NULL,
        INDEX idx_activity_user_date(UserID,DateTime)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $statement = $pdo->prepare(
        'INSERT INTO ActivityLogs (UserID, Action, Module, DeviceName, IPAddress, Details) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $statement->execute([
        $userId,
        substr($action, 0, 100),
        substr($module, 0, 100),
        substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown device'), 0, 255),
        filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ?: null,
        $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null
    ]);
}
