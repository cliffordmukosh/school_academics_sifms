<?php
// apis/auth_middleware.php
session_start();
require_once '../connection/db.php';

function validateBearerToken() {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (!preg_match('/Bearer\s+(\S+)/', $authHeader, $matches)) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Missing or invalid Authorization header']);
        exit;
    }

    $token = $matches[1];

    if (!isset($_SESSION['auth_token']) || $_SESSION['auth_token'] !== $token) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid or expired token']);
        exit;
    }

    if (!isset($_SESSION['student_id']) || !isset($_SESSION['school_id'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid session data']);
        exit;
    }

    return [
        'student_id' => $_SESSION['student_id'],
        'school_id' => $_SESSION['school_id']
    ];
}
?>