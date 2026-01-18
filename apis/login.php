<?php
// apis/login.php
session_start();
include 'cors.php';
require_once '../connection/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$phone = trim($data['phone'] ?? '');

if (empty($phone) || !preg_match('/^0\d{9}$/', $phone)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid phone number. Use format 07xxxxxxxx']);
    exit;
}

$stmt = $conn->prepare("
    SELECT s.student_id, s.school_id, s.full_name, s.admission_no, s.gender,
           c.form_name AS class_name, str.stream_name
    FROM students s
    LEFT JOIN classes c ON s.class_id = c.class_id
    LEFT JOIN streams str ON s.stream_id = str.stream_id
    WHERE s.primary_phone = ?
    LIMIT 1
");
$stmt->bind_param("s", $phone);
$stmt->execute();
$result = $stmt->get_result();

if ($student = $result->fetch_assoc()) {
    $token = bin2hex(random_bytes(32));

    // Store in session
    $_SESSION['auth_token'] = $token;
    $_SESSION['student_id'] = $student['student_id'];
    $_SESSION['school_id'] = $student['school_id'];

    // Set HttpOnly cookie (secure in production with HTTPS)
    setcookie('auth_token', $token, [
        'expires' => time() + 86400 * 7, // 7 days
        'path' => '/',
        'secure' => false, // Set true in production
        'httponly' => true,
        'samesite' => 'Strict'
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Login successful',
        'token' => $token,
        'student' => [
            'student_id' => $student['student_id'],
            'full_name' => $student['full_name'],
            'admission_no' => $student['admission_no'],
            'class' => $student['class_name'] . ' ' . ($student['stream_name'] ?? ''),
            'gender' => $student['gender']
        ]
    ]);
} else {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No student found with this phone number']);
}
$stmt->close();
?>