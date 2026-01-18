<?php
// apis/get_single_exam_results.php
include 'cors.php';
require_once 'auth_middleware.php';

header('Content-Type: application/json');

$user = validateBearerToken();
$student_id = $user['student_id'];
$school_id  = $user['school_id'];

$exam_id = intval($_GET['exam_id'] ?? 0);
$year    = intval($_GET['year']    ?? 0);
$term    = trim($_GET['term']      ?? '');

if ($exam_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'exam_id is required']);
    exit;
}

// Build dynamic WHERE clause
$where  = "e.exam_id = ? AND e.school_id = ?";
$params = [$exam_id, $school_id];
$types  = "ii";

if ($year > 0) {
    $where .= " AND YEAR(e.created_at) = ?";
    $params[] = $year;
    $types   .= "i";
}

if ($term !== '') {
    $where .= " AND e.term = ?";
    $params[] = $term;
    $types   .= "s";
}

// Fetch exam details + aggregate
$stmt = $conn->prepare("
    SELECT 
        e.exam_id,
        e.exam_name, 
        e.term, 
        YEAR(e.created_at) AS year,
        ea.total_score, 
        ea.mean_score, 
        ea.mean_grade, 
        ea.total_points,
        ea.position_class, 
        ea.position_stream, 
        ea.remark_text AS principal_remark,
        e.min_subjects
    FROM exams e
    LEFT JOIN exam_aggregates ea 
        ON e.exam_id = ea.exam_id 
        AND ea.student_id = ?
    WHERE $where
    LIMIT 1
");
array_unshift($params, $student_id);
$types = "i" . $types;
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$exam = $result->fetch_assoc();
$stmt->close();

if (!$exam) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => 'Exam not found or no results for this student'
    ]);
    exit;
}

// Fetch subject results
$subjects_stmt = $conn->prepare("
    SELECT 
        s.name AS subject_name,
        esa.subject_score,
        esa.subject_grade,
        esa.remark_text AS subject_remark,
        CONCAT(u.first_name, ' ', COALESCE(u.other_names, '')) AS teacher_name
    FROM exam_subject_aggregates esa
    JOIN subjects s ON esa.subject_id = s.subject_id
    LEFT JOIN teacher_subjects ts 
        ON esa.subject_id = ts.subject_id
        AND ts.class_id = (SELECT class_id FROM students WHERE student_id = ? LIMIT 1)
        AND ts.academic_year = ?
    LEFT JOIN users u ON ts.user_id = u.user_id
    WHERE esa.exam_id = ? 
      AND esa.student_id = ? 
      AND esa.school_id = ?
    ORDER BY s.name
");
$subjects_stmt->bind_param("iiiii", $student_id, $exam['year'], $exam_id, $student_id, $school_id);
$subjects_stmt->execute();
$subjects_result = $subjects_stmt->get_result();

$subjects = [];
while ($row = $subjects_result->fetch_assoc()) {
    $subjects[] = $row;
}
$subjects_stmt->close();

$exam['subjects'] = $subjects;

echo json_encode([
    'success' => true,
    'exam'    => $exam
]);
?>