<?php
// apis/get_transcript_results.php
include 'cors.php'; // Adjust path if needed
require_once 'auth_middleware.php';

header('Content-Type: application/json');

$user = validateBearerToken();
$student_id = $user['student_id'];
$school_id = $user['school_id'];

// Optional filters: ?year=2025&term=Term%202
$year_filter = intval($_GET['year'] ?? 0);
$term_filter = $_GET['term'] ?? null; // e.g., "Term 2"

// Build base WHERE conditions
$where = "WHERE stra.student_id = ? AND stra.school_id = ?";
$params = [$student_id, $school_id];
$types = "ii";

if ($year_filter > 0) {
    $where .= " AND stra.year = ?";
    $params[] = $year_filter;
    $types .= "i";
}
if ($term_filter !== null) {
    $where .= " AND stra.term = ?";
    $params[] = $term_filter;
    $types .= "s";
}

// Fetch term aggregates (one per term/year)
$query = "
    SELECT 
        stra.term,
        stra.year,
        stra.average,
        stra.grade,
        stra.total_points,
        stra.class_position,
        stra.stream_position,
        stra.class_total_students,
        stra.stream_total_students,
        stra.class_teacher_remark_text,
        stra.principal_remark_text,
        stra.kcpe_score,
        stra.kcpe_grade,
        stra.min_subjects
    FROM student_term_results_aggregates stra
    $where
    ORDER BY stra.year DESC,
             CASE stra.term
               WHEN 'Term 1' THEN 1
               WHEN 'Term 2' THEN 2
               WHEN 'Term 3' THEN 3
             END DESC
";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$transcripts = [];

while ($term_data = $result->fetch_assoc()) {
    $term = $term_data['term'];
    $year = $term_data['year'];

    // Fetch all exams in this term/year for the student's class
    $class_stmt = $conn->prepare("SELECT class_id FROM students WHERE student_id = ? AND school_id = ?");
    $class_stmt->bind_param("ii", $student_id, $school_id);
    $class_stmt->execute();
    $class_result = $class_stmt->get_result();
    $class_row = $class_result->fetch_assoc();
    $class_id = $class_row['class_id'] ?? 0;
    $class_stmt->close();

    if ($class_id === 0) {
        $term_data['exams'] = [];
        $term_data['subjects'] = [];
        $transcripts[] = $term_data;
        continue;
    }

    // Get exam list
    $exam_stmt = $conn->prepare("
        SELECT exam_id, exam_name
        FROM exams
        WHERE school_id = ? AND class_id = ? AND term = ? AND YEAR(created_at) = ? AND status = 'closed'
        ORDER BY created_at
    ");
    $exam_stmt->bind_param("iisi", $school_id, $class_id, $term, $year);
    $exam_stmt->execute();
    $exam_result = $exam_stmt->get_result();

    $exam_names = [];
    $exam_ids = [];
    while ($exam = $exam_result->fetch_assoc()) {
        $exam_ids[] = $exam['exam_id'];
        $exam_names[$exam['exam_id']] = $exam['exam_name'];
    }
    $exam_stmt->close();

    // Fetch subject performance across exams in this term
    $subjects = [];
    if (!empty($exam_ids)) {
        $in_placeholders = str_repeat('?,', count($exam_ids) - 1) . '?';
        $subj_query = "
            SELECT 
                s.name AS subject_name,
                esa.exam_id,
                esa.subject_score,
                tst.subject_mean,
                tst.subject_grade,
                tst.subject_teacher_remark_text,
                CONCAT(u.first_name, ' ', COALESCE(u.other_names, '')) AS teacher_name
            FROM exam_subject_aggregates esa
            JOIN subjects s ON esa.subject_id = s.subject_id
            LEFT JOIN term_subject_totals tst ON 
                tst.student_id = esa.student_id AND 
                tst.subject_id = esa.subject_id AND
                tst.term = ? AND tst.year = ? AND tst.school_id = ?
            LEFT JOIN teacher_subjects ts ON esa.subject_id = ts.subject_id AND ts.academic_year = ?
            LEFT JOIN users u ON ts.user_id = u.user_id
            WHERE esa.student_id = ? 
              AND esa.school_id = ?
              AND esa.exam_id IN ($in_placeholders)
            ORDER BY s.name, esa.exam_id
        ";

        $subj_types =
            "siiiii" . str_repeat("i", count($exam_ids));

        $subj_params = array_merge(
            [$term, $year, $school_id, $year, $student_id, $school_id],
            $exam_ids
        );

        $subj_stmt = $conn->prepare($subj_query);
        $subj_stmt->bind_param($subj_types, ...$subj_params);

        $subj_stmt->execute();
        $subj_result = $subj_stmt->get_result();

        $current_subject = null;
        while ($row = $subj_result->fetch_assoc()) {
            if ($current_subject !== $row['subject_name']) {
                if ($current_subject !== null) {
                    $subjects[] = $current_subject;
                }
                $current_subject = [
                    'subject_name' => $row['subject_name'],
                    'mean' => $row['subject_mean'] ?? 0,
                    'grade' => $row['subject_grade'] ?? 'N/A',
                    'remark' => $row['subject_teacher_remark_text'] ?? '',
                    'teacher' => $row['teacher_name'] ?? 'N/A',
                    'scores' => [],
                ];
            }

            if (isset($exam_names[$row['exam_id']])) {
                $current_subject['scores'][$exam_names[$row['exam_id']]] = number_format($row['subject_score'] ?? 0, 2);
            }
        }
        if ($current_subject !== null) {
            $subjects[] = $current_subject;
        }
        $subj_stmt->close();
    }

    $term_data['exams'] = array_values($exam_names); // List of exam names
    $term_data['subjects'] = $subjects;

    $transcripts[] = $term_data;
}
$stmt->close();

echo json_encode([
    'success' => true,
    'transcripts' => $transcripts
]);
?>