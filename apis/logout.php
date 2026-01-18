<?php
// apis/logout.php
session_start();
include '../cors.php';

header('Content-Type: application/json');

$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';

if (preg_match('/Bearer\s+(\S+)/', $authHeader, $matches)) {
    $token = $matches[1];
    if (isset($_SESSION['auth_token']) && $_SESSION['auth_token'] === $token) {
        session_unset();
        session_destroy();

        setcookie('auth_token', '', time() - 3600, '/', '', false, true);

        echo json_encode(['success' => true, 'message' => 'Logged out successfully']);
        exit;
    }
}

// Fallback: clear anyway
session_unset();
session_destroy();
setcookie('auth_token', '', time() - 3600, '/');

echo json_encode(['success' => true, 'message' => 'Logged out successfully']);
?>