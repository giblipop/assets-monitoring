<?php
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/';
require_once __DIR__ . '/config.php';
$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_INVENTORY);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}