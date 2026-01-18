<?php
// apis/get_student_exams.php
include 'cors.php';
require_once 'auth_middleware.php';

header('Content-Type: application/json');

$user = validateBearerToken();
$student_id = $user['student_id'];
$school_id = $user['school_id'];

// Optional: filter by year (e.g., ?year=2025). If not provided, use current year
$requested_year = intval($_GET['year'] ?? 0);
$current_year = date('Y'); // 2025 as per current date
$year = ($requested_year > 0) ? $requested_year : $current_year;

// Get student's current class_id
$stmt = $conn->prepare("
    SELECT class_id 
    FROM students 
    WHERE student_id = ? AND school_id = ?
");
$stmt->bind_param("ii", $student_id, $school_id);
$stmt->execute();
$result = $stmt->get_result();
$student = $result->fetch_assoc();
$stmt->close();

if (!$student) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Student not found']);
    exit;
}

$class_id = $student['class_id'];

// Fetch all distinct exams for this class in the specified/current year
// We use DISTINCT because one exam has multiple subjects (multiple rows in exams table)
$query = "
    SELECT DISTINCT
        e.exam_id,
        e.exam_name,
        e.term,
        YEAR(e.created_at) AS exam_year,
        e.status,
        e.min_subjects
    FROM exams e
    WHERE e.school_id = ?
      AND e.class_id = ?
      AND YEAR(e.created_at) = ?
    ORDER BY e.created_at DESC
";

$stmt = $conn->prepare($query);
$stmt->bind_param("iii", $school_id, $class_id, $year);
$stmt->execute();
$result = $stmt->get_result();

$exams = [];
while ($row = $result->fetch_assoc()) {
    // Optional: Check if student actually has results in this exam (to show only sat exams)
    $check_result = $conn->prepare("
        SELECT 1 
        FROM results 
        WHERE exam_id = ? AND student_id = ? AND school_id = ?
        LIMIT 1
    ");
    $check_result->bind_param("iii", $row['exam_id'], $student_id, $school_id);
    $check_result->execute();
    $has_result = $check_result->get_result()->num_rows > 0;
    $check_result->close();

    // Only include exams the student actually sat (has at least one result)
    if ($has_result) {
        $exams[] = $row;
    }
}
$stmt->close();

echo json_encode([
    'success' => true,
    'year' => $year,
    'exams' => $exams,
    'message' => count($exams) > 0 
        ? 'Exams found for the student in ' . $year 
        : 'No exams found for the student in ' . $year
]);
?>