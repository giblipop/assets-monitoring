<?php
declare(strict_types=1);

require_once __DIR__ . '/../connector/auth_guard.php';
require_once __DIR__ . '/local_assistant_engine.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function local_assistant_respond(array $body, int $status): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    local_assistant_respond(['success' => false, 'message' => 'Use POST to ask the HDJV Assistant.'], 405);
}

$csrfToken = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$sessionToken = (string)($_SESSION['csrf'] ?? '');
if ($csrfToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $csrfToken)) {
    local_assistant_respond(['success' => false, 'message' => 'Your session token expired. Refresh the page and try again.'], 403);
}

if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) {
    local_assistant_respond(['success' => false, 'message' => 'Please keep your question under 2,000 characters.'], 413);
}

$request = json_decode(file_get_contents('php://input'), true);
$message = is_array($request) ? ($request['message'] ?? null) : null;
if (!is_string($message) || trim($message) === '' || mb_strlen($message, 'UTF-8') > 2000) {
    local_assistant_respond(['success' => false, 'message' => 'Enter a question of up to 2,000 characters.'], 400);
}

$now = time();
$recentRequests = array_values(array_filter(
    $_SESSION['local_assistant_requests'] ?? [],
    static fn($timestamp): bool => is_int($timestamp) && $timestamp > $now - 60
));
if (count($recentRequests) >= 30) {
    local_assistant_respond(['success' => false, 'message' => 'Please wait a minute before sending more questions.'], 429);
}
$recentRequests[] = $now;
$_SESSION['local_assistant_requests'] = $recentRequests;
$userId = (int)$_SESSION['uid'];
session_write_close();

try {
    global $DB_HOST, $DB_USER, $DB_PASS, $DB_INVENTORY, $DB_CONTROL;
    $pdoOptions = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ];
    $inventoryPdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_INVENTORY};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        $pdoOptions
    );
    $controlPdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_CONTROL};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        $pdoOptions
    );
    $authPdo = null;
    if (local_assistant_needs_account_lookup(mb_strtolower(trim($message), 'UTF-8'))) {
        $authPdo = new PDO(
            "mysql:host={$DB_HOST};dbname=inventory;charset=utf8mb4",
            $DB_USER,
            $DB_PASS,
            $pdoOptions
        );
    }
    $registeredTables = local_assistant_registered_tables($controlPdo);
    $answer = local_assistant_answer($inventoryPdo, trim($message), $registeredTables, $userId, $authPdo);
    local_assistant_respond(['success' => true, 'message' => $answer, 'mode' => 'local-system'], 200);
} catch (Throwable $error) {
    error_log('HDJV Assistant local analysis failed: ' . $error->getMessage());
    local_assistant_respond([
        'success' => false,
        'message' => 'The HDJV Assistant could not complete its local analysis. Check the server log or try a more specific system or data question.'
    ], 500);
}
