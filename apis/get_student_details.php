<?php
// apis/get_student_details.php
include 'cors.php';  
require_once 'auth_middleware.php';

header('Content-Type: application/json');

$user = validateBearerToken();
$student_id = $user['student_id'];
$school_id  = $user['school_id'];

// Local development base URL (used for both logo and profile pictures)
$baseUrl = 'https://academics.sifms.co.ke/';

// 1. Fetch student details
$stmt = $conn->prepare("
    SELECT 
        s.student_id,
        s.full_name,
        s.admission_no,
        s.gender,
        s.dob,
        s.profile_picture,
        s.kcpe_score,
        s.kcpe_grade,
        s.primary_phone,
        c.form_name AS class_name,
        str.stream_name
    FROM students s
    LEFT JOIN classes c ON s.class_id = c.class_id
    LEFT JOIN streams str ON s.stream_id = str.stream_id
    WHERE s.student_id = ? AND s.school_id = ?
");
$stmt->bind_param("ii", $student_id, $school_id);
$stmt->execute();
$student_result = $stmt->get_result();
$student = $student_result->fetch_assoc();
$stmt->close();

if (!$student) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Student not found']);
    exit;
}

// Normalize profile picture → make it full URL if it's a relative path
if (!empty($student['profile_picture']) && strpos($student['profile_picture'], 'http') !== 0) {
    $student['profile_picture'] = $baseUrl . $student['profile_picture'];
}

// 2. Fetch school details
$stmt = $conn->prepare("
    SELECT 
        name AS school_name,
        address,
        email,
        phone,
        logo
    FROM schools
    WHERE school_id = ?
");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$school_result = $stmt->get_result();
$school = $school_result->fetch_assoc();
$stmt->close();

// Fallback if school not found
$school = $school ?: [
    'school_name' => '',
    'address'     => '',
    'email'       => '',
    'phone'       => '',
    'logo'        => ''
];

// Normalize school logo → make it full URL if relative
if (!empty($school['logo']) && strpos($school['logo'], 'http') !== 0) {
    $school['logo'] = $baseUrl . 'manageschool/logos/' . basename($school['logo']);
}

echo json_encode([
    'success' => true,
    'student' => $student,
    'school'  => $school
]);
?>