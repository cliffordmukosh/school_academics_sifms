<?php
// exams/functions.php
session_start();
ob_start(); // Start output buffering
require __DIR__ . '/../../connection/db.php';
require __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;


// Check if user is logged in and has appropriate permissions
if (!isset($_SESSION['user_id']) || !isset($_SESSION['school_id']) || !isset($_SESSION['role_id'])) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access']);
    ob_end_flush();
    exit;
}

// Check user permissions
function hasPermission($conn, $user_id, $role_id, $permission_name, $school_id)
{
    $stmt = $conn->prepare("
        SELECT 1
        FROM role_permissions rp
        JOIN permissions p ON rp.permission_id = p.permission_id
        WHERE rp.role_id = ? AND p.name = ? AND (rp.school_id = ? OR p.is_global = TRUE)
    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("isi", $role_id, $permission_name, $school_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $has_permission = $result->num_rows > 0;
    $stmt->close();
    return $has_permission;
}
/**
 * Check if the current user (teacher) is assigned to teach this subject in this class/stream.
 * Admins and non-teachers always return true.
 */
function isTeacherAssignedToSubject($conn, $user_id, $role_name, $class_id, $subject_id, $stream_id = null)
{
    if ($role_name !== 'Teacher') {
        return true; // Admins, HODs, etc. bypass
    }

    $query = "
        SELECT 1 
        FROM teacher_subjects 
        WHERE user_id = ? 
          AND subject_id = ? 
          AND (class_id = ? OR class_id IS NULL)
    ";
    $types = "iii";
    $params = [$user_id, $subject_id, $class_id];

    if ($stream_id !== null) {
        $query .= " AND (stream_id = ? OR stream_id IS NULL)";
        $types .= "i";
        $params[] = $stream_id;
    }

    $stmt = $conn->prepare($query);
    if (!$stmt) return false;

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $allowed = $result->num_rows > 0;
    $stmt->close();

    return $allowed;
}
// Helper function to sanitize input
function sanitize($conn, $input)
{
    if ($input === '') return null;
    return trim($conn->real_escape_string($input));
}

// Function to get grade and points for a score (for future use, e.g., reports)
function getGradeAndPoints($conn, $grading_system_id, $score)
{
    if ($score === null || $score === '') {
        return ['grade' => null, 'points' => null];
    }

    $stmt = $conn->prepare("
        SELECT grade, points
        FROM grading_rules
        WHERE grading_system_id = ? AND min_score <= ? AND max_score >= ? AND grade NOT IN ('X', 'Y')
    ");
    if (!$stmt) {
        return ['grade' => null, 'points' => null];
    }
    $stmt->bind_param("idd", $grading_system_id, $score, $score);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result ?: ['grade' => null, 'points' => null];
}

$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');
$school_id = $_SESSION['school_id'];
$user_id = $_SESSION['user_id'];
$role_id = $_SESSION['role_id'];

header('Content-Type: application/json');

switch ($action) {

    case 'create_exam':
        if (!hasPermission($conn, $user_id, $role_id, 'manage_exams', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied: manage_exams']);
            ob_end_flush();
            exit;
        }

        $exam_name = sanitize($conn, $_POST['exam_name'] ?? '');
        $term = sanitize($conn, $_POST['term'] ?? '');
        $exam_type = sanitize($conn, $_POST['exam_type'] ?? null);
        $year = isset($_POST['year']) ? (int)$_POST['year'] : null;
        $grading_system_id = isset($_POST['grading_system_id']) ? (int)$_POST['grading_system_id'] : 0;
        $class_data = isset($_POST['class_data']) ? json_decode($_POST['class_data'], true) : [];

        if (empty($exam_name) || empty($term) || empty($grading_system_id) || empty($class_data)) {
            echo json_encode(['status' => 'error', 'message' => 'Exam name, term, grading system, and class data are required']);
            ob_end_flush();
            exit;
        }

        // Verify grading system
        $stmt = $conn->prepare("SELECT grading_system_id FROM grading_systems WHERE grading_system_id = ? AND (school_id = ?)");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("ii", $grading_system_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid grading system']);
            $stmt->close();
            ob_end_flush();
            exit;
        }
        $stmt->close();

        $conn->begin_transaction();
        try {
            foreach ($class_data as $class) {
                $class_id = (int)$class['class_id'];
                $min_subjects = (int)$class['min_subjects'];
                $subjects = $class['subjects'] ?? [];

                if ($min_subjects < 1) {
                    throw new Exception("Minimum subjects for class ID $class_id must be at least 1");
                }
                if (empty($subjects)) {
                    throw new Exception("No subjects provided for class ID $class_id");
                }

                // Verify class
                $stmt = $conn->prepare("SELECT class_id FROM classes WHERE class_id = ? AND school_id = ?");
                if (!$stmt) {
                    throw new Exception('Database error: ' . $conn->error);
                }
                $stmt->bind_param("ii", $class_id, $school_id);
                $stmt->execute();
                if ($stmt->get_result()->num_rows === 0) {
                    throw new Exception("Invalid class ID: $class_id");
                }
                $stmt->close();

                // Create exam
                $stmt = $conn->prepare("
                INSERT INTO exams (school_id, class_id, exam_name, term, exam_type, year, status, grading_system_id, min_subjects, created_by)
                VALUES (?, ?, ?, ?, ?, ?, 'active', ?, ?, ?)
            ");
                if (!$stmt) {
                    throw new Exception('Database error: ' . $conn->error);
                }
                $stmt->bind_param("iisssiiii", $school_id, $class_id, $exam_name, $term, $exam_type, $year, $grading_system_id, $min_subjects, $user_id);
                if (!$stmt->execute()) {
                    throw new Exception('Failed to create exam: ' . $conn->error);
                }
                $exam_id = $conn->insert_id;
                $stmt->close();

                // Assign subjects to exam with use_papers flag
                foreach ($subjects as $subject) {
                    $stmt = $conn->prepare("
                    INSERT INTO exam_subjects (exam_id, class_id, subject_id, use_papers)
                    VALUES (?, ?, ?, ?)
                ");
                    if (!$stmt) {
                        throw new Exception('Database error: ' . $conn->error);
                    }
                    $use_papers = $subject['use_papers'] ? 1 : 0;
                    $stmt->bind_param("iiii", $exam_id, $class_id, $subject['subject_id'], $use_papers);
                    if (!$stmt->execute()) {
                        throw new Exception('Failed to assign subject: ' . $conn->error);
                    }
                    $stmt->close();

                    if ($use_papers && !empty($subject['papers'])) {
                        foreach ($subject['papers'] as $paper) {
                            $stmt = $conn->prepare("
                            INSERT INTO exam_subjects_papers (exam_id, subject_id, paper_id, max_score)
                            VALUES (?, ?, ?, ?)
                        ");
                            if (!$stmt) {
                                throw new Exception('Database error: ' . $conn->error);
                            }
                            $stmt->bind_param("iiid", $exam_id, $subject['subject_id'], $paper['paper_id'], $paper['max_score']);
                            if (!$stmt->execute()) {
                                throw new Exception('Failed to assign paper: ' . $conn->error);
                            }
                            $stmt->close();
                        }
                    }
                }
            }

            $conn->commit();
            echo json_encode(['status' => 'success', 'message' => 'Exams created successfully for all selected classes']);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['status' => 'error', 'message' => 'Failed to create exams: ' . $e->getMessage()]);
        }
        ob_end_flush();
        exit;

    case 'delete_exam':
        if (!hasPermission($conn, $user_id, $role_id, 'manage_exams', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied: manage_exams']);
            ob_end_flush();
            exit;
        }

        $exam_id = isset($_POST['exam_id']) ? (int)$_POST['exam_id'] : 0;

        if (empty($exam_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Exam ID is required']);
            ob_end_flush();
            exit;
        }

        // Verify exam
        $stmt = $conn->prepare("SELECT status FROM exams WHERE exam_id = ? AND school_id = ?");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("ii", $exam_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid exam']);
            $stmt->close();
            ob_end_flush();
            exit;
        }
        $stmt->close();

        // Delete results
        $stmt = $conn->prepare("DELETE FROM results WHERE exam_id = ?");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("i", $exam_id);
        if (!$stmt->execute()) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to delete results: ' . $conn->error]);
            $stmt->close();
            ob_end_flush();
            exit;
        }
        $stmt->close();

        // Delete exam subjects papers
        $stmt = $conn->prepare("DELETE FROM exam_subjects_papers WHERE exam_id = ?");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("i", $exam_id);
        if (!$stmt->execute()) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to delete exam papers: ' . $conn->error]);
            $stmt->close();
            ob_end_flush();
            exit;
        }
        $stmt->close();

        // Delete exam subjects
        $stmt = $conn->prepare("DELETE FROM exam_subjects WHERE exam_id = ?");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("i", $exam_id);
        if (!$stmt->execute()) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to delete exam subjects: ' . $conn->error]);
            $stmt->close();
            ob_end_flush();
            exit;
        }
        $stmt->close();

        // Delete exam
        $stmt = $conn->prepare("DELETE FROM exams WHERE exam_id = ? AND school_id = ?");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("ii", $exam_id, $school_id);
        if (!$stmt->execute()) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to delete exam: ' . $conn->error]);
            $stmt->close();
            ob_end_flush();
            exit;
        }
        $stmt->close();

        echo json_encode(['status' => 'success', 'message' => 'Exam deleted successfully']);
        break;

    case 'get_exams':
        if (!hasPermission($conn, $user_id, $role_id, 'view_exams', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied: view_exams']);
            ob_end_flush();
            exit;
        }

        $stmt = $conn->prepare("
            SELECT e.exam_id, e.exam_name, e.term, e.status, c.form_name, g.name AS grading_system_name
            FROM exams e
            JOIN classes c ON e.class_id = c.class_id
            JOIN grading_systems g ON e.grading_system_id = g.grading_system_id
            WHERE e.school_id = ?
            ORDER BY e.created_at DESC
        ");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("i", $school_id);
        $stmt->execute();
        $exams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        echo json_encode(['status' => 'success', 'exams' => $exams]);
        break;

    case 'get_exam_details':
        if (!hasPermission($conn, $user_id, $role_id, 'view_exams', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied: view_exams']);
            ob_end_flush();
            exit;
        }

        $exam_id = isset($_POST['exam_id']) ? (int)$_POST['exam_id'] : 0;

        if (empty($exam_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Exam ID is required']);
            ob_end_flush();
            exit;
        }

        $stmt = $conn->prepare("
            SELECT e.exam_id, e.exam_name, e.class_id, c.form_name, e.term, e.exam_type, e.year, e.status, e.grading_system_id, e.min_subjects
            FROM exams e
            JOIN classes c ON e.class_id = c.class_id
            WHERE e.exam_id = ? AND e.school_id = ?
        ");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("ii", $exam_id, $school_id);
        $stmt->execute();
        $exam = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$exam) {
            echo json_encode(['status' => 'error', 'message' => 'Exam not found']);
            ob_end_flush();
            exit;
        }

        echo json_encode(['status' => 'success', 'exam' => $exam]);
        break;
    case 'get_exam_subjects_with_papers':
        if (!hasPermission($conn, $user_id, $role_id, 'view_exams', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied: view_exams']);
            ob_end_flush();
            exit;
        }

        $exam_id = isset($_POST['exam_id']) ? (int)$_POST['exam_id'] : 0;
        $subject_ids = isset($_POST['subject_ids']) ? trim($_POST['subject_ids']) : '';

        if (empty($exam_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Exam ID is required']);
            ob_end_flush();
            exit;
        }

        // Fetch exam class for context
        $stmt = $conn->prepare("
        SELECT e.class_id, c.form_name 
        FROM exams e 
        JOIN classes c ON e.class_id = c.class_id 
        WHERE e.exam_id = ? AND e.school_id = ?
    ");
        $stmt->bind_param("ii", $exam_id, $school_id);
        $stmt->execute();
        $exam = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$exam) {
            echo json_encode(['status' => 'error', 'message' => 'Exam not found']);
            ob_end_flush();
            exit;
        }

        $class_id = $exam['class_id'];

        // Base query for subjects linked to this exam
        $query = "
        SELECT es.subject_id, s.name, es.use_papers
        FROM exam_subjects es
        JOIN subjects s ON es.subject_id = s.subject_id
        WHERE es.exam_id = ? AND s.school_id = ?
    ";
        $params = [$exam_id, $school_id];
        $types = "ii";

        // Optional: filter by specific subject_ids (if passed)
        if (!empty($subject_ids)) {
            $subject_id_array = array_filter(array_map('intval', explode(',', $subject_ids)));
            if (!empty($subject_id_array)) {
                $placeholders = implode(',', array_fill(0, count($subject_id_array), '?'));
                $query .= " AND es.subject_id IN ($placeholders)";
                $params = array_merge($params, $subject_id_array);
                $types .= str_repeat('i', count($subject_id_array));
            }
        }

        // === IMPORTANT: Restrict to teacher's assigned subjects ===
        if ($_SESSION['role_name'] === 'Teacher') {
            $query .= "
            AND EXISTS (
                SELECT 1 
                FROM teacher_subjects ts 
                WHERE ts.user_id = ? 
                  AND ts.subject_id = es.subject_id 
                  AND (ts.class_id = ? OR ts.class_id IS NULL)
                  AND (ts.academic_year = YEAR(CURDATE()) OR ts.academic_year IS NULL)
            )
        ";
            $params[] = $user_id;
            $params[] = $class_id;
            $types .= "ii";
        }

        $stmt = $conn->prepare($query);
        if (!$stmt) {
            error_log("Prepare failed (get_exam_subjects_with_papers): " . $conn->error);
            echo json_encode(['status' => 'error', 'message' => 'Database error']);
            ob_end_flush();
            exit;
        }

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Fetch papers for subjects that use them
        foreach ($subjects as &$subject) {
            if ($subject['use_papers']) {
                $stmt = $conn->prepare("
                SELECT sp.paper_id, sp.paper_name, esp.max_score
                FROM subject_papers sp
                JOIN exam_subjects_papers esp ON sp.paper_id = esp.paper_id
                WHERE esp.exam_id = ? AND sp.subject_id = ?
            ");
                $stmt->bind_param("ii", $exam_id, $subject['subject_id']);
                $stmt->execute();
                $subject['papers'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
            } else {
                $subject['papers'] = [];
            }
        }

        echo json_encode([
            'status' => 'success',
            'subjects' => $subjects,
            'class_id' => $class_id,
            'form_name' => $exam['form_name']
        ]);
        ob_end_flush();
        break;

    case 'get_class_subjects_with_papers':
        if (!hasPermission($conn, $user_id, $role_id, 'view_exams', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied']);
            ob_end_flush();
            exit;
        }
        $class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
        if (!$class_id) {
            echo json_encode(['status' => 'error', 'message' => 'Class ID is required']);
            ob_end_flush();
            exit;
        }
        $stmt = $conn->prepare("
            SELECT s.subject_id, s.name, cs.use_papers
            FROM class_subjects cs
            JOIN subjects s ON cs.subject_id = s.subject_id
            WHERE cs.class_id = ? AND s.school_id = ?
        ");
        $stmt->bind_param("ii", $class_id, $school_id);
        $stmt->execute();
        $subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($subjects as &$subject) {
            if ($subject['use_papers']) {
                $stmt = $conn->prepare("
                    SELECT paper_id, paper_name, max_score
                    FROM subject_papers
                    WHERE subject_id = ?
                ");
                $stmt->bind_param("i", $subject['subject_id']);
                $stmt->execute();
                $subject['papers'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
            } else {
                $subject['papers'] = [];
            }
        }
        echo json_encode(['status' => 'success', 'subjects' => $subjects]);
        break;
    case 'get_exam_subjects_with_results':
        ob_start();

        if (!isset($_POST['exam_id']) || !is_numeric($_POST['exam_id'])) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid or missing exam ID']);
            ob_end_flush();
            exit;
        }

        $exam_id   = (int)$_POST['exam_id'];
        $school_id = $_SESSION['school_id'];
        $user_id   = $_SESSION['user_id'];
        $role_id   = $_SESSION['role_id'];

        // Verify permissions
        if (!hasPermission($conn, $user_id, $role_id, 'view_results', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied']);
            ob_end_flush();
            exit;
        }

        // Validate exam exists
        $stmt = $conn->prepare("
        SELECT class_id, grading_system_id, status 
        FROM exams 
        WHERE exam_id = ? AND school_id = ?
    ");
        $stmt->bind_param("ii", $exam_id, $school_id);
        $stmt->execute();
        $exam = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$exam) {
            echo json_encode(['status' => 'error', 'message' => 'Exam not found']);
            ob_end_flush();
            exit;
        }

        // Fetch subjects (with DISTINCT to avoid duplicates)
        $subjects = [];
        $stmt = $conn->prepare("
        SELECT DISTINCT es.subject_id, s.name, es.use_papers
        FROM exam_subjects es
        JOIN subjects s ON es.subject_id = s.subject_id
        JOIN exams e ON es.exam_id = e.exam_id
        WHERE es.exam_id = ?
          AND es.class_id = e.class_id
          AND s.school_id = ?
          AND s.deleted_at IS NULL
    ");
        $stmt->bind_param("ii", $exam_id, $school_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $subjects[] = $row;
        }
        $stmt->close();

        // Fetch papers for each subject that uses papers
        foreach ($subjects as $i => &$subject) {  // Use reference (&) to modify array
            if ($subject['use_papers']) {
                $stmt = $conn->prepare("
                SELECT esp.paper_id, sp.paper_name, esp.max_score
                FROM exam_subjects_papers esp
                JOIN subject_papers sp ON esp.paper_id = sp.paper_id
                WHERE esp.exam_id = ? AND esp.subject_id = ?
            ");
                $stmt->bind_param("ii", $exam_id, $subject['subject_id']);
                $stmt->execute();
                $resPapers = $stmt->get_result();
                $subject['papers'] = $resPapers->fetch_all(MYSQLI_ASSOC);
                $stmt->close();

                // Fallback if no papers found (safety)
                if (empty($subject['papers'])) {
                    $subject['papers'] = [['paper_id' => 'null', 'paper_name' => '', 'max_score' => 100]];
                }
            } else {
                $subject['papers'] = [['paper_id' => 'null', 'paper_name' => '', 'max_score' => 100]];
            }
        }
        unset($subject); // End reference

        // If no subjects → early return
        if (empty($subjects)) {
            echo json_encode([
                'status'   => 'success',
                'subjects' => [],
                'students' => [],
                'class_id' => $exam['class_id']
            ]);
            ob_end_flush();
            exit;
        }

        // Fetch students + results + stream_id
        $placeholders = implode(',', array_fill(0, count($subjects), '?'));
        $stmt = $conn->prepare("
        SELECT 
            s.student_id, 
            s.admission_no, 
            s.full_name, 
            s.stream_id,               -- IMPORTANT: Added this!
            r.subject_id, 
            r.paper_id, 
            r.score
        FROM students s
        LEFT JOIN results r 
            ON s.student_id = r.student_id 
           AND r.exam_id = ? 
           AND r.subject_id IN ($placeholders)
        JOIN exams e 
            ON e.exam_id = ? 
           AND e.class_id = s.class_id
        WHERE s.school_id = ?
          AND s.deleted_at IS NULL
    ");

        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: Unable to prepare students query']);
            ob_end_flush();
            exit;
        }

        // Bind parameters dynamically
        $params = array_merge([$exam_id], array_column($subjects, 'subject_id'), [$exam_id, $school_id]);
        $types  = 'i' . str_repeat('i', count($subjects)) . 'ii';
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $temp_results = [];
        while ($row = $result->fetch_assoc()) {
            $sid = $row['student_id'];
            if (!isset($temp_results[$sid])) {
                $temp_results[$sid] = [
                    'student_id'   => $sid,
                    'admission_no' => $row['admission_no'] ?? '',      // Force string, prevent null
                    'full_name'    => $row['full_name'] ?? 'Unknown',
                    'stream_id'    => $row['stream_id'] ?? null,       // Now included!
                    'results'      => []
                ];
            }

            if ($row['subject_id']) {
                $paper_id = $row['paper_id'] !== null ? (string)$row['paper_id'] : 'null';
                $temp_results[$sid]['results'][$row['subject_id']][$paper_id] = [
                    'score' => $row['score'] !== null ? (float)$row['score'] : null,
                    'grade' => null,
                    'points' => null
                ];
            }
        }
        $stmt->close();

        // Assign grades & points
        foreach ($temp_results as &$student) {
            foreach ($subjects as $subject) {
                foreach ($subject['papers'] as $paper) {
                    $paper_id = $paper['paper_id'] ?? 'null';
                    $key = $subject['subject_id'];
                    if (isset($student['results'][$key][$paper_id]['score'])) {
                        $score = $student['results'][$key][$paper_id]['score'];
                        $grade_points = getGradeAndPoints($conn, $exam['grading_system_id'], $score);
                        $student['results'][$key][$paper_id]['grade']  = $grade_points['grade'];
                        $student['results'][$key][$paper_id]['points'] = $grade_points['points'];
                    }
                }
            }
        }
        unset($student);

        // Final response
        echo json_encode([
            'status'   => 'success',
            'subjects' => $subjects,
            'students' => array_values($temp_results),
            'class_id' => $exam['class_id']
        ], JSON_NUMERIC_CHECK);

        ob_end_flush();
        exit;

    case 'save_exam_result':
        $exam_id = isset($_POST['exam_id']) ? (int)$_POST['exam_id'] : 0;
        $school_id = $_SESSION['school_id'];
        $student_id = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
        $subject_id = isset($_POST['subject_id']) ? (int)$_POST['subject_id'] : 0;
        $paper_id = isset($_POST['paper_id']) ? (int)$_POST['paper_id'] : null;
        $score = isset($_POST['score']) && $_POST['score'] !== '' ? (float)$_POST['score'] : null;

        // Validate inputs
        if (empty($exam_id) || empty($student_id) || empty($subject_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Exam ID, student ID, and subject ID are required']);
            exit;
        }

        // Check exam status
        $stmt = $conn->prepare("SELECT status FROM exams WHERE exam_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $exam_id, $school_id);
        $stmt->execute();
        $exam_status = $stmt->get_result()->fetch_assoc()['status'];
        $stmt->close();
        if ($exam_status === 'closed') {
            echo json_encode(['status' => 'error', 'message' => 'Cannot edit results for a closed exam']);
            exit;
        }

        // Check permissions 
        $user_id = $_SESSION['user_id'] ?? 0;
        $stmt = $conn->prepare("
        SELECT 1 FROM role_permissions rp
        JOIN roles r ON rp.role_id = r.role_id
        JOIN users u ON u.role_id = r.role_id
        WHERE u.user_id = ? AND rp.permission_id = (SELECT permission_id FROM permissions WHERE name = 'enter_results')
    ");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $has_permission = $stmt->get_result()->fetch_row();
        $stmt->close();
        if (!$has_permission) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied']);
            exit;
        }

        // Fetch exam details
        $stmt = $conn->prepare("
        SELECT e.class_id, e.grading_system_id, es.use_papers
        FROM exams e
        JOIN exam_subjects es ON e.exam_id = es.exam_id
        WHERE e.exam_id = ? AND e.school_id = ? AND es.subject_id = ?
    ");
        $stmt->bind_param("iii", $exam_id, $school_id, $subject_id);
        $stmt->execute();
        $exam = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$exam) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid exam or subject']);
            exit;
        }
        $class_id = $exam['class_id'];
        $grading_system_id = $exam['grading_system_id'];
        $use_papers = $exam['use_papers'];

        // Validate paper_id and max_score
        $max_score = 100; // Default
        if ($use_papers && $paper_id) {
            $stmt = $conn->prepare("
            SELECT max_score FROM exam_subjects_papers
            WHERE exam_id = ? AND subject_id = ? AND paper_id = ?
        ");
            $stmt->bind_param("iii", $exam_id, $subject_id, $paper_id);
            $stmt->execute();
            $paper = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$paper) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid paper ID']);
                exit;
            }
            $max_score = $paper['max_score'];
        }

        // Validate score
        if ($score !== null && ($score < 0 || $score > $max_score)) {
            echo json_encode(['status' => 'error', 'message' => "Score must be between 0 and $max_score"]);
            exit;
        }

        // Fetch stream_id for the student
        $stmt = $conn->prepare("SELECT stream_id FROM students WHERE student_id = ? AND school_id = ? AND class_id = ?");
        $stmt->bind_param("iii", $student_id, $school_id, $class_id);
        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();
        // Prevent teachers from editing subjects they don't teach
        if ($_SESSION['role_name'] === 'Teacher') {
            $stream_id = $student['stream_id'];
            if (!isTeacherAssignedToSubject($conn, $user_id, $_SESSION['role_name'], $class_id, $subject_id, $stream_id)) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'You are not assigned to teach this subject in this class/stream. Contact admin.'
                ]);
                exit;
            }
        }
        $stmt->close();

        if (!$student) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid student ID']);
            exit;
        }
        $stream_id = $student['stream_id'];

        // Calculate grade and points
        $grade = null;
        $points = null;
        if ($score !== null) {
            $stmt = $conn->prepare("
            SELECT grade, points
            FROM grading_rules
            WHERE grading_system_id = ? AND min_score <= ? AND max_score >= ?
        ");
            $stmt->bind_param("idd", $grading_system_id, $score, $score);
            $stmt->execute();
            $grading_rule = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($grading_rule) {
                $grade = $grading_rule['grade'];
                $points = $grading_rule['points'];
            }
        }

        // Check if result exists
        $stmt = $conn->prepare(
            "
        SELECT result_id FROM results
        WHERE exam_id = ? AND student_id = ? AND subject_id = ? AND paper_id " . ($paper_id ? "= ?" : "IS NULL")
        );
        if ($paper_id) {
            $stmt->bind_param("iiii", $exam_id, $student_id, $subject_id, $paper_id);
        } else {
            $stmt->bind_param("iii", $exam_id, $student_id, $subject_id);
        }
        $stmt->execute();
        $existing_result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($existing_result) {
            // Update existing result
            $stmt = $conn->prepare("
            UPDATE results
            SET score = ?, grade = ?, points = ?, status = 'pending'
            WHERE result_id = ?
        ");
            $stmt->bind_param("dsii", $score, $grade, $points, $existing_result['result_id']);
        } else {
            // Insert new result
            $stmt = $conn->prepare("
    INSERT INTO results (
        school_id, exam_id, student_id, class_id, stream_id, 
        subject_id, paper_id, score, grade, points, status, created_at
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
");

            // ✅ make sure $paper_id is either int or NULL
            if (empty($paper_id)) {
                $paper_id = null;
            }

            // ✅ corrected bind_param string: 7 ints, 1 double, 1 string, 1 int
            $stmt->bind_param(
                "iiiiiiidis",
                $school_id,
                $exam_id,
                $student_id,
                $class_id,
                $stream_id,
                $subject_id,
                $paper_id,
                $score,   // double
                $grade,   // string
                $points   // int
            );
        }
        $success = $stmt->execute();
        $stmt->close();

        if ($success) {
            echo json_encode(['status' => 'success', 'message' => 'Result saved']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to save result']);
        }
        break;

    case 'get_exam_results_with_papers':
        if (!hasPermission($conn, $user_id, $role_id, 'view_exams', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied']);
            ob_end_flush();
            exit;
        }
        $exam_id = isset($_POST['exam_id']) ? (int)$_POST['exam_id'] : 0;
        $subject_id = isset($_POST['subject_id']) ? (int)$_POST['subject_id'] : 0;
        if (!$exam_id || !$subject_id) {
            echo json_encode(['status' => 'error', 'message' => 'Exam ID and Subject ID are required']);
            ob_end_flush();
            exit;
        }
        $stmt = $conn->prepare("
            SELECT cs.use_papers
            FROM exam_subjects es
            JOIN class_subjects cs ON es.subject_id = cs.subject_id AND es.class_id = cs.class_id
            WHERE es.exam_id = ? AND es.subject_id = ?
        ");
        $stmt->bind_param("ii", $exam_id, $subject_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $use_papers = $result['use_papers'] ?? false;
        $stmt->close();
        $papers = [];
        if ($use_papers) {
            $stmt = $conn->prepare("
                SELECT paper_id, paper_name, max_score
                FROM subject_papers
                WHERE subject_id = ?
            ");
            $stmt->bind_param("i", $subject_id);
            $stmt->execute();
            $papers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
        $stmt = $conn->prepare("
            SELECT st.student_id, CONCAT(st.first_name, ' ', st.last_name) AS student_name,
                   er.score, er.paper_id
            FROM students st
            LEFT JOIN exam_results er ON er.student_id = st.student_id AND er.exam_id = ? AND er.subject_id = ?
            JOIN student_classes sc ON st.student_id = sc.student_id
            JOIN exams e ON e.exam_id = ? AND e.class_id = sc.class_id
            WHERE sc.school_id = ?
        ");
        $stmt->bind_param("iiii", $exam_id, $subject_id, $exam_id, $school_id);
        $stmt->execute();
        $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $formatted_results = [];
        foreach ($results as $result) {
            $formatted_result = [
                'student_id' => $result['student_id'],
                'student_name' => $result['student_name'],
                'score' => $result['paper_id'] === null ? $result['score'] : null,
                'scores' => []
            ];
            if ($use_papers && $result['paper_id']) {
                $formatted_result['scores'][$result['paper_id']] = $result['score'];
            }
            $formatted_results[] = $formatted_result;
        }
        echo json_encode([
            'status' => 'success',
            'use_papers' => $use_papers,
            'papers' => $papers,
            'results' => $formatted_results
        ]);
        break;

    case 'get_class_subjects':
        if (!hasPermission($conn, $user_id, $role_id, 'view_exams', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied: view_exams']);
            ob_end_flush();
            exit;
        }

        $class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;

        if (empty($class_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Class ID is required']);
            ob_end_flush();
            exit;
        }

        $stmt = $conn->prepare("
            SELECT s.subject_id, s.name
            FROM class_subjects cs
            JOIN subjects s ON cs.subject_id = s.subject_id
            WHERE cs.class_id = ? AND s.school_id = ?
        ");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("ii", $class_id, $school_id);
        $stmt->execute();
        $subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        echo json_encode(['status' => 'success', 'subjects' => $subjects]);
        break;

    case 'get_streams':
        if (!hasPermission($conn, $user_id, $role_id, 'view_exams', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied: view_exams']);
            ob_end_flush();
            exit;
        }

        $class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;

        if (empty($class_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Class ID is required']);
            ob_end_flush();
            exit;
        }

        $stmt = $conn->prepare("SELECT stream_id, stream_name FROM streams WHERE class_id = ? AND school_id = ?");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("ii", $class_id, $school_id);
        $stmt->execute();
        $streams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        echo json_encode(['status' => 'success', 'streams' => $streams]);
        break;

    case 'get_students':
        if (!hasPermission($conn, $user_id, $role_id, 'view_exams', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied']);
            exit;
        }

        $exam_id    = isset($_POST['exam_id'])    ? (int)$_POST['exam_id']    : 0;
        $class_id   = isset($_POST['class_id'])   ? (int)$_POST['class_id']   : 0;
        $stream_id  = isset($_POST['stream_id'])  ? (int)$_POST['stream_id']  : null;
        $student_id = isset($_POST['student_id']) ? (int)$_POST['student_id'] : null;
        $group_id   = isset($_POST['group_id'])   ? (int)$_POST['group_id']   : null;

        if (empty($exam_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Exam ID is required']);
            exit;
        }

        // Get exam class_id if not provided
        if (!$class_id) {
            $stmt = $conn->prepare("SELECT class_id FROM exams WHERE exam_id = ? AND school_id = ?");
            $stmt->bind_param("ii", $exam_id, $school_id);
            $stmt->execute();
            $exam = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$exam) {
                echo json_encode(['status' => 'error', 'message' => 'Exam not found']);
                exit;
            }
            $class_id = $exam['class_id'];
        }

        $query = "
        SELECT s.student_id, s.admission_no, s.full_name, s.stream_id
        FROM students s
        WHERE s.school_id = ? AND s.class_id = ? AND s.deleted_at IS NULL
    ";
        $params = [$school_id, $class_id];
        $types  = "ii";

        if ($stream_id !== null) {
            $query .= " AND s.stream_id = ?";
            $params[] = $stream_id;
            $types .= "i";
        }

        if ($student_id !== null) {
            $query .= " AND s.student_id = ?";
            $params[] = $student_id;
            $types .= "i";
        }

        if ($group_id !== null) {
            $query = "
            SELECT s.student_id, s.admission_no, s.full_name, s.stream_id
            FROM custom_group_students cgs
            JOIN students s ON cgs.student_id = s.student_id
            WHERE cgs.group_id = ? 
              AND s.school_id = ? 
              AND s.class_id = ?
              AND s.deleted_at IS NULL
            ORDER BY s.admission_no ASC, s.full_name ASC
        ";
            $params = [$group_id, $school_id, $class_id];
            $types  = "iii";
        } else {
            $query .= " ORDER BY s.admission_no ASC, s.full_name ASC";
        }

        $stmt = $conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (empty($students)) {
            echo json_encode(['status' => 'success', 'students' => []]);
            exit;
        }

        // Fetch existing results (same as before)
        foreach ($students as &$student) {
            $stmt = $conn->prepare("
            SELECT subject_id, paper_id, score
            FROM results
            WHERE exam_id = ? AND student_id = ?
        ");
            $stmt->bind_param("ii", $exam_id, $student['student_id']);
            $stmt->execute();
            $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $student['results'] = [];
            foreach ($results as $r) {
                $paper = $r['paper_id'] ? $r['paper_id'] : 'null';
                $student['results'][$r['subject_id']][$paper] = ['score' => $r['score']];
            }
        }

        echo json_encode(['status' => 'success', 'students' => $students]);
        break;
    case 'upload_results_manually':
        $exam_id = isset($_POST['exam_id']) ? (int)$_POST['exam_id'] : 0;
        $subject_ids = isset($_POST['subject_ids']) ? trim($_POST['subject_ids']) : '';
        $results = isset($_POST['results']) ? $_POST['results'] : [];

        // Get scope from form data
        $scope = isset($_POST['scope']) ? trim($_POST['scope']) : '';

        $school_id = $_SESSION['school_id'];
        $user_id = $_SESSION['user_id'];
        $role_id = $_SESSION['role_id'];

        if (!hasPermission($conn, $user_id, $role_id, 'enter_results', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied']);
            ob_end_flush();
            exit;
        }

        // Validate scope early - includes custom_group
        $valid_scopes = ['class', 'stream', 'student', 'custom_group'];
        if (empty($scope) || !in_array($scope, $valid_scopes)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid or missing scope value']);
            ob_end_flush();
            exit;
        }

        // Check exam status
        $stmt = $conn->prepare("SELECT status FROM exams WHERE exam_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $exam_id, $school_id);
        $stmt->execute();
        $exam_status_result = $stmt->get_result()->fetch_assoc();
        $exam_status = $exam_status_result ? $exam_status_result['status'] : null;
        $stmt->close();

        if ($exam_status === 'closed') {
            echo json_encode(['status' => 'error', 'message' => 'Cannot edit results for a closed exam']);
            ob_end_flush();
            exit;
        }

        // Basic required fields
        if (empty($exam_id) || empty($results)) {
            echo json_encode(['status' => 'error', 'message' => 'Exam ID and results data are required']);
            ob_end_flush();
            exit;
        }

        // Verify exam exists and get class/grading info
        $stmt = $conn->prepare("SELECT class_id, grading_system_id FROM exams WHERE exam_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $exam_id, $school_id);
        $stmt->execute();
        $exam = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$exam) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid exam']);
            ob_end_flush();
            exit;
        }

        $class_id = $exam['class_id'];
        $grading_system_id = $exam['grading_system_id'];

        // ── Load subjects dynamically based on scope ──
        $valid_subjects = [];

        if ($scope === 'custom_group') {
            $group_id = isset($_POST['group_id']) ? (int)$_POST['group_id'] : 0;
            if (!$group_id) {
                echo json_encode(['status' => 'error', 'message' => 'Group ID is required for custom group scope']);
                ob_end_flush();
                exit;
            }

            // Fetch subjects from custom group (same logic as get_custom_group_data)
            $stmt = $conn->prepare("
            SELECT 
                s.subject_id, 
                s.name, 
                COALESCE(cs.use_papers, 0) AS use_papers
            FROM custom_group_subjects cgs
            JOIN subjects s ON cgs.subject_id = s.subject_id
            LEFT JOIN class_subjects cs 
                   ON cs.subject_id = s.subject_id 
                  AND cs.class_id = ?
            WHERE cgs.group_id = ? 
              AND s.school_id = ?
        ");
            $stmt->bind_param("iii", $class_id, $group_id, $school_id);
            $stmt->execute();
            $valid_subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            if (empty($valid_subjects)) {
                echo json_encode(['status' => 'error', 'message' => 'No subjects found in this custom group']);
                ob_end_flush();
                exit;
            }
        } else {
            // Normal scopes require subject_ids
            if (empty($subject_ids)) {
                echo json_encode(['status' => 'error', 'message' => 'Subject IDs are required for this scope']);
                ob_end_flush();
                exit;
            }

            $subject_id_array = array_filter(array_map('intval', explode(',', $subject_ids)));
            if (empty($subject_id_array)) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid subject IDs']);
                ob_end_flush();
                exit;
            }

            $placeholders = implode(',', array_fill(0, count($subject_id_array), '?'));
            $stmt = $conn->prepare("
            SELECT es.subject_id, es.use_papers, s.name 
            FROM exam_subjects es 
            JOIN subjects s ON es.subject_id = s.subject_id 
            WHERE es.exam_id = ? AND es.subject_id IN ($placeholders)
        ");
            $params = array_merge([$exam_id], $subject_id_array);
            $stmt->bind_param(str_repeat('i', count($params)), ...$params);
            $stmt->execute();
            $valid_subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            if (count($valid_subjects) !== count($subject_id_array)) {
                echo json_encode(['status' => 'error', 'message' => 'One or more subject IDs are not associated with this exam']);
                ob_end_flush();
                exit;
            }
        }

        // Restrict teachers to only their assigned subjects/class/stream
        if ($_SESSION['role_name'] === 'Teacher') {
            $restricted_subjects = [];
            foreach ($valid_subjects as $subject) {
                $stream_check = ($scope === 'stream' && isset($_POST['stream_id'])) ? (int)$_POST['stream_id'] : null;
                if (!isTeacherAssignedToSubject($conn, $user_id, $_SESSION['role_name'], $class_id, $subject['subject_id'], $stream_check)) {
                    $restricted_subjects[] = htmlspecialchars($subject['name']);
                }
            }

            if (!empty($restricted_subjects)) {
                $msg = "You are not assigned to enter results for: " . implode(', ', $restricted_subjects) . ".";
                echo json_encode(['status' => 'error', 'message' => $msg]);
                ob_end_flush();
                exit;
            }
        }

        // Fetch papers for subjects that use them
        $subject_papers = [];
        foreach ($valid_subjects as $subject) {
            $subject_id = $subject['subject_id'];
            $subject_papers[$subject_id] = [];

            if ($subject['use_papers'] == 1) {
                $stmt = $conn->prepare("
                SELECT paper_id, max_score, paper_name 
                FROM exam_subjects_papers 
                WHERE exam_id = ? AND subject_id = ?
            ");
                $stmt->bind_param("ii", $exam_id, $subject_id);
                $stmt->execute();
                $subject_papers[$subject_id] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();

                if (empty($subject_papers[$subject_id])) {
                    echo json_encode([
                        'status' => 'error',
                        'message' => "No papers found for subject {$subject['name']} (use_papers = 1)"
                    ]);
                    ob_end_flush();
                    exit;
                }
            }
        }

        // ── Handle custom_group student validation ──
        $allowed_student_ids = [];
        if ($scope === 'custom_group') {
            $group_id = (int)($_POST['group_id'] ?? 0);
            $stmt = $conn->prepare("
            SELECT s.student_id
            FROM custom_group_students cgs
            JOIN students s ON cgs.student_id = s.student_id
            WHERE cgs.group_id = ? 
              AND s.school_id = ? 
              AND s.class_id = ?
              AND s.deleted_at IS NULL
        ");
            $stmt->bind_param("iii", $group_id, $school_id, $class_id);
            $stmt->execute();
            $group_students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            if (empty($group_students)) {
                echo json_encode(['status' => 'error', 'message' => 'No students found in this custom group']);
                ob_end_flush();
                exit;
            }

            $allowed_student_ids = array_column($group_students, 'student_id');
        }

        // Process all results inside transaction
        $errors = [];
        $conn->begin_transaction();

        try {
            foreach ($results as $student_id => $subjects_data) {
                // Security: only allow students from the selected scope/group
                if ($scope === 'custom_group' && !in_array($student_id, $allowed_student_ids)) {
                    $errors[] = "Student ID $student_id is not part of the selected custom group";
                    continue;
                }

                // Verify student belongs to the correct class
                $stmt = $conn->prepare("
                SELECT student_id, class_id, stream_id 
                FROM students 
                WHERE student_id = ? AND school_id = ? AND class_id = ?
            ");
                $stmt->bind_param("iii", $student_id, $school_id, $class_id);
                $stmt->execute();
                $student = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$student) {
                    $errors[] = "Invalid student ID: $student_id (not in this class)";
                    continue;
                }

                $stream_id = $student['stream_id'];

                foreach ($valid_subjects as $subject) {
                    $subject_id = $subject['subject_id'];

                    if (!isset($subjects_data[$subject_id]) || empty($subjects_data[$subject_id])) {
                        $errors[] = "No data provided for subject ID $subject_id for student $student_id";
                        continue;
                    }

                    $scores = $subjects_data[$subject_id];

                    if ($subject['use_papers'] == 1) {
                        // Papers mode
                        foreach ($subject_papers[$subject_id] as $paper) {
                            $paper_id = $paper['paper_id'];

                            if (!isset($scores[$paper_id])) {
                                $errors[] = "Missing score for paper ID $paper_id (subject $subject_id, student $student_id)";
                                continue;
                            }

                            $score = ($scores[$paper_id] === '' || $scores[$paper_id] === null) ? null : (float)$scores[$paper_id];
                            $max_score = $paper['max_score'];

                            if ($score !== null && ($score < 0 || $score > $max_score)) {
                                $errors[] = "Invalid score $score (0-$max_score) for paper $paper_id, subject $subject_id, student $student_id";
                                continue;
                            }

                            $grade = $points = null;
                            if ($score !== null) {
                                $stmt = $conn->prepare("
                                SELECT grade, points 
                                FROM grading_rules 
                                WHERE grading_system_id = ? AND min_score <= ? AND max_score >= ?
                            ");
                                $stmt->bind_param("idd", $grading_system_id, $score, $score);
                                $stmt->execute();
                                $grade_row = $stmt->get_result()->fetch_assoc();
                                if ($grade_row) {
                                    $grade = $grade_row['grade'];
                                    $points = $grade_row['points'];
                                }
                                $stmt->close();
                            }

                            // Upsert
                            $stmt = $conn->prepare("
                            INSERT INTO results (
                                school_id, exam_id, student_id, class_id, stream_id, 
                                subject_id, paper_id, score, grade, points, status, created_at
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
                            ON DUPLICATE KEY UPDATE 
                                score = ?, grade = ?, points = ?, status = 'pending'
                        ");
                            $stmt->bind_param(
                                "iiiiiiisdsdsds",
                                $school_id,
                                $exam_id,
                                $student_id,
                                $class_id,
                                $stream_id,
                                $subject_id,
                                $paper_id,
                                $score,
                                $grade,
                                $points,
                                $score,
                                $grade,
                                $points
                            );
                            if (!$stmt->execute()) {
                                $errors[] = "DB error saving paper $paper_id (subject $subject_id, student $student_id): " . $stmt->error;
                            }
                            $stmt->close();
                        }
                    } else {
                        // No papers
                        $score = (isset($scores['null']) && $scores['null'] !== '' && $scores['null'] !== null)
                            ? (float)$scores['null']
                            : null;

                        if ($score !== null && ($score < 0 || $score > 100)) {
                            $errors[] = "Invalid score $score (0-100) for subject $subject_id, student $student_id";
                            continue;
                        }

                        $grade = $points = null;
                        if ($score !== null) {
                            $stmt = $conn->prepare("
                            SELECT grade, points 
                            FROM grading_rules 
                            WHERE grading_system_id = ? AND min_score <= ? AND max_score >= ?
                        ");
                            $stmt->bind_param("idd", $grading_system_id, $score, $score);
                            $stmt->execute();
                            $grade_row = $stmt->get_result()->fetch_assoc();
                            if ($grade_row) {
                                $grade = $grade_row['grade'];
                                $points = $grade_row['points'];
                            }
                            $stmt->close();
                        }

                        // Upsert (paper_id = NULL)
                        $stmt = $conn->prepare("
                        INSERT INTO results (
                            school_id, exam_id, student_id, class_id, stream_id, 
                            subject_id, paper_id, score, grade, points, status, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, 'pending', NOW())
                        ON DUPLICATE KEY UPDATE 
                            score = ?, grade = ?, points = ?, status = 'pending'
                    ");
                        $stmt->bind_param(
                            "iiiiisdsdsds",
                            $school_id,
                            $exam_id,
                            $student_id,
                            $class_id,
                            $stream_id,
                            $subject_id,
                            $score,
                            $grade,
                            $points,
                            $score,
                            $grade,
                            $points
                        );
                        if (!$stmt->execute()) {
                            $errors[] = "DB error saving subject $subject_id for student $student_id: " . $stmt->error;
                        }
                        $stmt->close();
                    }
                }
            }

            if (empty($errors)) {
                $conn->commit();
                echo json_encode(['status' => 'success', 'message' => 'Results saved successfully']);
            } else {
                $conn->rollback();
                echo json_encode([
                    'status' => 'error',
                    'message' => 'No results saved due to errors: ' . implode('; ', $errors)
                ]);
            }
        } catch (Exception $e) {
            $conn->rollback();
            error_log("Transaction failed in upload_results_manually: " . $e->getMessage());
            echo json_encode([
                'status' => 'error',
                'message' => 'Server error during save: ' . $e->getMessage()
            ]);
        }

        ob_end_flush();
        break;
    case 'upload_results_excel':
        if (!hasPermission($conn, $user_id, $role_id, 'enter_results', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied: enter_results']);
            ob_end_flush();
            exit;
        }

        $exam_id     = (int)($_POST['exam_id']     ?? 0);
        $scope       = trim($_POST['scope']        ?? '');
        $class_id    = (int)($_POST['class_id']    ?? 0);
        $stream_id   = (int)($_POST['stream_id']   ?? 0);
        $student_id  = (int)($_POST['student_id']  ?? 0);
        $group_id    = (int)($_POST['group_id']    ?? 0);
        $subject_ids = trim($_POST['subject_ids']  ?? '');
        $file        = $_FILES['excel_file']       ?? null;

        // ── 1. Basic validation ──
        if (empty($exam_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Exam ID is required']);
            ob_end_flush();
            exit;
        }

        $valid_scopes = ['class', 'stream', 'student', 'custom_group'];
        if (empty($scope) || !in_array($scope, $valid_scopes)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid or missing scope']);
            ob_end_flush();
            exit;
        }

        if (empty($file['name']) || $file['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['status' => 'error', 'message' => 'Valid Excel file is required']);
            ob_end_flush();
            exit;
        }

        // Check exam status & fetch class/grading info
        $stmt = $conn->prepare("
        SELECT status, class_id, grading_system_id 
        FROM exams 
        WHERE exam_id = ? AND school_id = ?
    ");
        $stmt->bind_param("ii", $exam_id, $school_id);
        $stmt->execute();
        $exam = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$exam) {
            echo json_encode(['status' => 'error', 'message' => 'Exam not found']);
            ob_end_flush();
            exit;
        }

        if ($exam['status'] === 'closed') {
            echo json_encode(['status' => 'error', 'message' => 'Cannot upload results to a closed exam']);
            ob_end_flush();
            exit;
        }

        $class_id_from_exam = $exam['class_id'];
        $grading_system_id  = $exam['grading_system_id'];

        // Use form class_id if provided, otherwise fallback to exam's class_id
        $class_id = $class_id ?: $class_id_from_exam;

        // ── 2. Load subjects depending on scope ──
        $subjects = [];
        $subject_papers = [];

        if ($scope === 'custom_group') {
            // ── CUSTOM GROUP: Load subjects from the group (ignore form subject_ids) ──
            if (!$group_id) {
                echo json_encode(['status' => 'error', 'message' => 'Group ID is required for custom group scope']);
                ob_end_flush();
                exit;
            }

            $stmt = $conn->prepare("
            SELECT 
                s.subject_id, 
                s.name, 
                COALESCE(cs.use_papers, 0) AS use_papers
            FROM custom_group_subjects cgs
            JOIN subjects s ON cgs.subject_id = s.subject_id
            LEFT JOIN class_subjects cs 
                ON cs.subject_id = s.subject_id 
                AND cs.class_id = ?
            WHERE cgs.group_id = ? 
              AND s.school_id = ?
              AND s.deleted_at IS NULL
        ");
            $stmt->bind_param("iii", $class_id, $group_id, $school_id);
            $stmt->execute();
            $subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            if (empty($subjects)) {
                echo json_encode(['status' => 'error', 'message' => 'No subjects assigned to this custom group for this class']);
                ob_end_flush();
                exit;
            }
        } else {
            // ── Normal scopes (class, stream, student): Require subject_ids from form ──
            if (empty($subject_ids)) {
                echo json_encode(['status' => 'error', 'message' => 'At least one subject must be selected']);
                ob_end_flush();
                exit;
            }

            $subject_id_array = array_filter(array_map('intval', explode(',', $subject_ids)));
            if (empty($subject_id_array)) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid subject IDs']);
                ob_end_flush();
                exit;
            }

            $placeholders = implode(',', array_fill(0, count($subject_id_array), '?'));
            $stmt = $conn->prepare("
            SELECT es.subject_id, s.name, es.use_papers
            FROM exam_subjects es
            JOIN subjects s ON es.subject_id = s.subject_id
            WHERE es.exam_id = ? AND es.subject_id IN ($placeholders)
        ");
            $params = array_merge([$exam_id], $subject_id_array);
            $stmt->bind_param(str_repeat('i', count($params)), ...$params);
            $stmt->execute();
            $subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            if (count($subjects) !== count($subject_id_array)) {
                echo json_encode(['status' => 'error', 'message' => 'One or more selected subjects are not valid for this exam']);
                ob_end_flush();
                exit;
            }
        }

        // ── 3. Load papers for subjects that use papers ──
        foreach ($subjects as $subj) {
            $sid = $subj['subject_id'];
            $subject_papers[$sid] = [];
            if ($subj['use_papers']) {
                $stmt = $conn->prepare("
                SELECT esp.paper_id, sp.paper_name, esp.max_score
                FROM exam_subjects_papers esp
                JOIN subject_papers sp ON esp.paper_id = sp.paper_id
                WHERE esp.exam_id = ? AND esp.subject_id = ?
            ");
                $stmt->bind_param("ii", $exam_id, $sid);
                $stmt->execute();
                $subject_papers[$sid] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();

                if (empty($subject_papers[$sid])) {
                    echo json_encode([
                        'status' => 'error',
                        'message' => "Subject '{$subj['name']}' is set to use papers but no papers are defined"
                    ]);
                    ob_end_flush();
                    exit;
                }
            }
        }

        // ── 4. Load allowed students based on scope ──
        $allowed_students = []; // key = admission_no → ['student_id' => ..., 'stream_id' => ...]

        if ($scope === 'custom_group') {
            $stmt = $conn->prepare("
            SELECT s.student_id, s.admission_no, s.stream_id
            FROM custom_group_students cgs
            JOIN students s ON cgs.student_id = s.student_id
            WHERE cgs.group_id = ?
              AND s.school_id = ?
              AND s.class_id = ?
              AND s.deleted_at IS NULL
        ");
            $stmt->bind_param("iii", $group_id, $school_id, $class_id);
        } else {
            $query = "
            SELECT student_id, admission_no, stream_id 
            FROM students 
            WHERE school_id = ? AND class_id = ?
        ";
            $params = [$school_id, $class_id];
            $types = "ii";

            if ($scope === 'stream' && $stream_id) {
                $query .= " AND stream_id = ?";
                $params[] = $stream_id;
                $types .= "i";
            } elseif ($scope === 'student' && $student_id) {
                $query .= " AND student_id = ?";
                $params[] = $student_id;
                $types .= "i";
            }

            $stmt = $conn->prepare($query);
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $student_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($student_rows as $s) {
            $allowed_students[trim($s['admission_no'])] = [
                'student_id' => $s['student_id'],
                'stream_id'  => $s['stream_id']
            ];
        }

        if (empty($allowed_students)) {
            echo json_encode(['status' => 'error', 'message' => 'No students found for the selected scope']);
            ob_end_flush();
            exit;
        }

        // ── 5. Restrict teachers to their assigned subjects ──
        if ($_SESSION['role_name'] === 'Teacher') {
            $restricted = [];
            foreach ($subjects as $subj) {
                $stream_check = ($scope === 'stream') ? $stream_id : null;
                if (!isTeacherAssignedToSubject($conn, $user_id, $_SESSION['role_name'], $class_id, $subj['subject_id'], $stream_check)) {
                    $restricted[] = htmlspecialchars($subj['name']);
                }
            }
            if (!empty($restricted)) {
                echo json_encode([
                    'status' => 'error',
                    'message' => "You are not assigned to upload results for: " . implode(', ', $restricted) . ". Contact admin."
                ]);
                ob_end_flush();
                exit;
            }
        }

        // ── 6. Process the Excel file ──
        try {
            $spreadsheet = IOFactory::load($file['tmp_name']);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray();
            $header = array_shift($rows); // Remove header row

            // ── Header validation (your existing logic, kept as-is) ──
            $expected_header = [];
            $first_header_cell = trim($header[0] ?? '');

            if ($first_header_cell === 'Student Name') {
                $expected_header = ['Student Name', 'Admission No'];
            } else {
                $expected_header = ['Admission No'];
            }

            foreach ($subjects as $subject) {
                $sid = $subject['subject_id'];
                if ($subject['use_papers'] && !empty($subject_papers[$sid])) {
                    foreach ($subject_papers[$sid] as $paper) {
                        $expected_header[] = $subject['name'] . '-' . $paper['paper_name'];
                    }
                } else {
                    $expected_header[] = $subject['name'];
                }
            }

            $header_valid = true;
            foreach ($expected_header as $i => $expected) {
                if (!isset($header[$i]) || trim($header[$i]) !== $expected) {
                    $header_valid = false;
                    break;
                }
            }

            if (!$header_valid) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Invalid Excel header format. Expected: ' . implode(', ', $expected_header)
                ]);
                ob_end_flush();
                exit;
            }

            // ── Start transaction for saving results ──
            $conn->begin_transaction();
            $save_errors = [];

            foreach ($rows as $row_num => $row) {
                // Adjust column index based on header layout
                $adm_col = (trim($header[0] ?? '') === 'Student Name') ? 1 : 0;
                $admission_no = trim($row[$adm_col] ?? '');

                if (empty($admission_no) || !isset($allowed_students[$admission_no])) {
                    $save_errors[] = "Invalid or missing admission number '$admission_no' (Row " . ($row_num + 2) . ")";
                    continue;
                }

                $current_student_id = $allowed_students[$admission_no]['student_id'];
                $current_stream_id  = $allowed_students[$admission_no]['stream_id'];

                // For single-student scope, extra check
                if ($scope === 'student' && $student_id && $current_student_id != $student_id) {
                    $save_errors[] = "Admission $admission_no does not match selected student (Row " . ($row_num + 2) . ")";
                    continue;
                }

                // Start column index for scores
                $col_index = $adm_col + 1;

                foreach ($subjects as $subject) {
                    $sid = $subject['subject_id'];
                    $sname = $subject['name'];

                    if ($subject['use_papers'] && !empty($subject_papers[$sid])) {
                        // Multi-paper subject
                        foreach ($subject_papers[$sid] as $paper) {
                            $score = isset($row[$col_index]) && is_numeric($row[$col_index]) ? (float)$row[$col_index] : null;
                            $col_index++;

                            if ($score !== null && ($score < 0 || $score > $paper['max_score'])) {
                                $save_errors[] = "Invalid score $score (0-{$paper['max_score']}) for {$sname} - {$paper['paper_name']}, adm $admission_no (Row " . ($row_num + 2) . ")";
                                continue;
                            }

                            $grade = $points = null;
                            if ($score !== null) {
                                $gstmt = $conn->prepare("
                                SELECT grade, points 
                                FROM grading_rules 
                                WHERE grading_system_id = ? AND min_score <= ? AND max_score >= ?
                            ");
                                $gstmt->bind_param("idd", $grading_system_id, $score, $score);
                                $gstmt->execute();
                                $grow = $gstmt->get_result()->fetch_assoc();
                                if ($grow) {
                                    $grade = $grow['grade'];
                                    $points = $grow['points'];
                                }
                                $gstmt->close();
                            }

                            // Upsert result
                            $ustmt = $conn->prepare("
                            INSERT INTO results (
                                school_id, exam_id, student_id, class_id, stream_id,
                                subject_id, paper_id, score, grade, points, status, created_at
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
                            ON DUPLICATE KEY UPDATE
                                score = ?, grade = ?, points = ?, updated_at = NOW()
                        ");
                            $ustmt->bind_param(
                                "iiiiiiisdsdsds",
                                $school_id,
                                $exam_id,
                                $current_student_id,
                                $class_id,
                                $current_stream_id,
                                $sid,
                                $paper['paper_id'],
                                $score,
                                $grade,
                                $points,
                                $score,
                                $grade,
                                $points
                            );

                            if (!$ustmt->execute()) {
                                $save_errors[] = "DB error saving {$sname} - {$paper['paper_name']} for adm $admission_no: " . $ustmt->error;
                            }
                            $ustmt->close();
                        }
                    } else {
                        // Single score subject
                        $score = isset($row[$col_index]) && is_numeric($row[$col_index]) ? (float)$row[$col_index] : null;
                        $col_index++;

                        if ($score !== null && ($score < 0 || $score > 100)) {
                            $save_errors[] = "Invalid score $score (0-100) for $sname, adm $admission_no (Row " . ($row_num + 2) . ")";
                            continue;
                        }

                        $grade = $points = null;
                        if ($score !== null) {
                            $gstmt = $conn->prepare("
                            SELECT grade, points 
                            FROM grading_rules 
                            WHERE grading_system_id = ? AND min_score <= ? AND max_score >= ?
                        ");
                            $gstmt->bind_param("idd", $grading_system_id, $score, $score);
                            $gstmt->execute();
                            $grow = $gstmt->get_result()->fetch_assoc();
                            if ($grow) {
                                $grade = $grow['grade'];
                                $points = $grow['points'];
                            }
                            $gstmt->close();
                        }

                        // Upsert (paper_id = NULL)
                        $ustmt = $conn->prepare("
                        INSERT INTO results (
                            school_id, exam_id, student_id, class_id, stream_id,
                            subject_id, paper_id, score, grade, points, status, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, 'pending', NOW())
                        ON DUPLICATE KEY UPDATE
                            score = ?, grade = ?, points = ?, updated_at = NOW()
                    ");
                        $ustmt->bind_param(
                            "iiiiisdsdsds",
                            $school_id,
                            $exam_id,
                            $current_student_id,
                            $class_id,
                            $current_stream_id,
                            $sid,
                            $score,
                            $grade,
                            $points,
                            $score,
                            $grade,
                            $points
                        );

                        if (!$ustmt->execute()) {
                            $save_errors[] = "DB error saving $sname for adm $admission_no: " . $ustmt->error;
                        }
                        $ustmt->close();
                    }
                }
            }

            if (empty($save_errors)) {
                $conn->commit();
                echo json_encode(['status' => 'success', 'message' => 'Results uploaded successfully']);
            } else {
                $conn->rollback();
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Some rows failed to save. Errors: ' . implode('; ', $save_errors)
                ]);
            }
        } catch (Exception $e) {
            $conn->rollback();
            error_log("Excel upload failed: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Server error processing Excel: ' . $e->getMessage()]);
        }

        ob_end_flush();
        break;

    case 'publish_results':
        if (!hasPermission($conn, $user_id, $role_id, 'approve_results', $school_id)) {
            // silently stop — no alert, no message
            echo json_encode(['status' => 'forbidden']);
            exit;
        }

        $exam_id = isset($_POST['exam_id']) ? (int)$_POST['exam_id'] : 0;
        $confirm = isset($_POST['confirm']) ? (int)$_POST['confirm'] : 0;

        if (empty($exam_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Exam ID is required']);
            ob_end_flush();
            exit;
        }

        // Check if confirmation is provided
        if ($confirm !== 1) {
            echo json_encode([
                'status' => 'confirmation_required',
                'message' => 'Publishing results will finalize them, making them visible to students and preventing further edits. Please confirm to proceed.',
                'confirmations_needed' => 1
            ]);
            ob_end_flush();
            exit;
        }

        // Start transaction
        $conn->begin_transaction();
        try {
            // Fetch exam details
            $stmt = $conn->prepare("SELECT term, YEAR(created_at) AS year, class_id, status FROM exams WHERE exam_id = ? AND school_id = ? AND deleted_at IS NULL");
            if (!$stmt) {
                throw new Exception('Database error: ' . $conn->error);
            }
            $stmt->bind_param("ii", $exam_id, $school_id);
            if (!$stmt->execute()) {
                throw new Exception('Failed to fetch exam details: ' . $conn->error);
            }
            $result = $stmt->get_result();
            $exam = $result->fetch_assoc();
            $stmt->close();

            if (!$exam) {
                throw new Exception('Exam not found for the given exam_id and school_id');
            }

            $term = $exam['term'];
            $year = $exam['year'];
            $class_id = $exam['class_id'];

            // Update all results to confirmed for the exam
            $stmt = $conn->prepare("UPDATE results SET status = 'confirmed' WHERE exam_id = ? AND school_id = ?");
            if (!$stmt) {
                throw new Exception('Database error: ' . $conn->error);
            }
            $stmt->bind_param("ii", $exam_id, $school_id);
            if (!$stmt->execute()) {
                throw new Exception('Failed to update results status: ' . $conn->error);
            }
            $stmt->close();

            // Update exam status to closed
            $stmt = $conn->prepare("UPDATE exams SET status = 'closed' WHERE exam_id = ? AND school_id = ?");
            if (!$stmt) {
                throw new Exception('Database error: ' . $conn->error);
            }
            $stmt->bind_param("ii", $exam_id, $school_id);
            if (!$stmt->execute()) {
                throw new Exception('Failed to update exam status: ' . $conn->error);
            }
            $stmt->close();

            // Delete existing single exam aggregates
            $stmt = $conn->prepare("DELETE FROM exam_aggregates WHERE exam_id = ? AND school_id = ?");
            if (!$stmt) {
                throw new Exception('Database error: ' . $conn->error);
            }
            $stmt->bind_param("ii", $exam_id, $school_id);
            if (!$stmt->execute()) {
                throw new Exception('Failed to delete existing aggregates: ' . $conn->error);
            }
            $stmt->close();

            // Delete existing single exam subject aggregates
            $stmt = $conn->prepare("DELETE FROM exam_subject_aggregates WHERE exam_id = ? AND school_id = ?");
            if (!$stmt) {
                throw new Exception('Database error: ' . $conn->error);
            }
            $stmt->bind_param("ii", $exam_id, $school_id);
            if (!$stmt->execute()) {
                throw new Exception('Failed to delete existing subject aggregates: ' . $conn->error);
            }
            $stmt->close();

            // Call stored procedure to generate single exam aggregates
            $stmt = $conn->prepare("CALL sp_generate_exam_aggregates(?, ?)");
            if (!$stmt) {
                throw new Exception('Database error preparing stored procedure: ' . $conn->error);
            }
            $stmt->bind_param("ii", $exam_id, $school_id);
            if (!$stmt->execute()) {
                throw new Exception('Failed to execute stored procedure: ' . $conn->error);
            }
            $stmt->close();

            // Log the action
            $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, school_id, action, description, created_at) VALUES (?, ?, ?, ?, NOW())");
            $action = 'publish_exam_results';
            $description = "Published results, closed exam, and updated aggregates for exam_id: $exam_id, class_id: $class_id";
            $stmt->bind_param("iiss", $user_id, $school_id, $action, $description);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            echo json_encode(['status' => 'success', 'message' => 'Results published, exam closed, and aggregates updated successfully']);
        } catch (Exception $e) {
            $conn->rollback();
            error_log("❌ Publish results failed: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        ob_end_flush();
        break;

    case 'create_grading_system':
        if (!hasPermission($conn, $user_id, $role_id, 'manage_grading_systems', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied: manage_grading_systems']);
            ob_end_flush();
            exit;
        }

        $name = sanitize($conn, $_POST['name'] ?? '');
        $rules = isset($_POST['rules']) ? (array)$_POST['rules'] : [];

        if (empty($name) || empty($rules)) {
            echo json_encode(['status' => 'error', 'message' => 'Grading system name and at least one rule are required']);
            ob_end_flush();
            exit;
        }

        $stmt = $conn->prepare("INSERT INTO grading_systems (school_id, name, is_default) VALUES (?, ?, FALSE)");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("is", $school_id, $name);
        if (!$stmt->execute()) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to create grading system: ' . $conn->error]);
            $stmt->close();
            ob_end_flush();
            exit;
        }
        $grading_system_id = $conn->insert_id;
        $stmt->close();

        foreach ($rules as $rule) {
            $grade = sanitize($conn, $rule['grade'] ?? '');
            $min_score = isset($rule['min_score']) ? (float)$rule['min_score'] : null;
            $max_score = isset($rule['max_score']) ? (float)$rule['max_score'] : null;
            $points = isset($rule['points']) ? (int)$rule['points'] : null;
            $description = sanitize($conn, $rule['description'] ?? '');

            if (empty($grade) || $min_score === null || $max_score === null || $points === null) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid grading rule data']);
                ob_end_flush();
                exit;
            }

            $stmt = $conn->prepare("
                INSERT INTO grading_rules (grading_system_id, grade, min_score, max_score, points, description)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            if (!$stmt) {
                echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
                ob_end_flush();
                exit;
            }
            $stmt->bind_param("isddis", $grading_system_id, $grade, $min_score, $max_score, $points, $description);
            if (!$stmt->execute()) {
                echo json_encode(['status' => 'error', 'message' => 'Failed to add grading rule: ' . $conn->error]);
                $stmt->close();
                ob_end_flush();
                exit;
            }
            $stmt->close();
        }

        echo json_encode(['status' => 'success', 'message' => 'Grading system created successfully']);
        break;

    case 'get_grading_systems':
        if (!hasPermission($conn, $user_id, $role_id, 'approve_results', $school_id)) {
            // silently stop — no alert, no message
            echo json_encode(['status' => 'forbidden']);
            exit;
        }


        $stmt = $conn->prepare("
            SELECT 
                gs.grading_system_id,
                gs.name,
                gs.is_default,
                IFNULL(MAX(gr.is_cbc), 0) AS is_cbc
            FROM grading_systems gs
            LEFT JOIN grading_rules gr 
                ON gs.grading_system_id = gr.grading_system_id
            WHERE gs.school_id = ?
              AND gs.school_id IS NOT NULL
            GROUP BY gs.grading_system_id, gs.name, gs.is_default
            ORDER BY gs.created_at DESC, gs.name ASC
        ");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("i", $school_id);
        $stmt->execute();
        $grading_systems = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        echo json_encode(['status' => 'success', 'grading_systems' => $grading_systems]);
        break;

    case 'get_grading_rules':
        if (!hasPermission($conn, $user_id, $role_id, 'approve_results', $school_id)) {
            // silently stop — no alert, no message
            echo json_encode(['status' => 'forbidden']);
            exit;
        }


        $grading_system_id = isset($_POST['grading_system_id']) ? (int)$_POST['grading_system_id'] : 0;

        if (empty($grading_system_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Grading system ID is required']);
            ob_end_flush();
            exit;
        }

        $stmt = $conn->prepare("
            SELECT grade, min_score, max_score, points, description
            FROM grading_rules
            WHERE grading_system_id = ?
            ORDER BY min_score DESC
        ");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("i", $grading_system_id);
        $stmt->execute();
        $rules = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        echo json_encode(['status' => 'success', 'rules' => $rules]);
        break;
    case 'generate_excel_template':
        $exam_id = isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : 0;
        $subject_ids = isset($_GET['subject_ids']) ? trim($_GET['subject_ids']) : '';
        $scope = isset($_GET['scope']) ? trim($_GET['scope']) : '';
        $class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
        $stream_id = isset($_GET['stream_id']) ? (int)$_GET['stream_id'] : 0;
        $group_id  = isset($_GET['group_id'])  ? (int)$_GET['group_id']  : 0;
        $admission_no = isset($_GET['admission_no']) ? trim($_GET['admission_no']) : '';
        $include_students = isset($_GET['include_students']) ? (int)$_GET['include_students'] : 0;

        $school_id = $_SESSION['school_id'];
        $user_id = $_SESSION['user_id'];
        $role_id = $_SESSION['role_id'];

        // Validate permissions
        if (!hasPermission($conn, $user_id, $role_id, 'enter_results', $school_id)) {
            header('HTTP/1.1 403 Forbidden');
            echo json_encode(['status' => 'error', 'message' => 'Permission denied']);
            exit;
        }

        // Basic validation
        if (empty($exam_id) || empty($scope) || empty($class_id)) {
            header('HTTP/1.1 400 Bad Request');
            echo json_encode(['status' => 'error', 'message' => 'Exam ID, scope, and class ID are required']);
            exit;
        }

        $valid_scopes = ['class', 'stream', 'student', 'custom_group'];
        if (!in_array($scope, $valid_scopes)) {
            header('HTTP/1.1 400 Bad Request');
            echo json_encode(['status' => 'error', 'message' => 'Invalid scope']);
            exit;
        }

        // Scope-specific required fields
        if ($scope === 'stream' && empty($stream_id)) {
            header('HTTP/1.1 400 Bad Request');
            echo json_encode(['status' => 'error', 'message' => 'Stream ID is required for stream scope']);
            exit;
        }
        if ($scope === 'student' && empty($admission_no)) {
            header('HTTP/1.1 400 Bad Request');
            echo json_encode(['status' => 'error', 'message' => 'Admission number is required for student scope']);
            exit;
        }
        if ($scope === 'custom_group' && empty($group_id)) {
            header('HTTP/1.1 400 Bad Request');
            echo json_encode(['status' => 'error', 'message' => 'Group ID is required for custom group scope']);
            exit;
        }

        // Verify exam and class match
        $stmt = $conn->prepare("SELECT exam_id, class_id FROM exams WHERE exam_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $exam_id, $school_id);
        $stmt->execute();
        $exam = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$exam || $exam['class_id'] != $class_id) {
            header('HTTP/1.1 400 Bad Request');
            echo json_encode(['status' => 'error', 'message' => 'Invalid exam or class mismatch']);
            exit;
        }

        // ── Load subjects based on scope ──
        $subjects = [];

        if ($scope === 'custom_group') {
            $stmt = $conn->prepare("
            SELECT 
                s.subject_id, 
                s.name, 
                COALESCE(cs.use_papers, 0) AS use_papers
            FROM custom_group_subjects cgs
            JOIN subjects s ON cgs.subject_id = s.subject_id
            LEFT JOIN class_subjects cs 
                   ON cs.subject_id = s.subject_id 
                  AND cs.class_id = ?
            WHERE cgs.group_id = ? 
              AND s.school_id = ?
        ");
            $stmt->bind_param("iii", $class_id, $group_id, $school_id);
            $stmt->execute();
            $subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            if (empty($subjects)) {
                header('HTTP/1.1 400 Bad Request');
                echo json_encode(['status' => 'error', 'message' => 'No subjects found in this custom group']);
                exit;
            }
        } else {
            if (empty($subject_ids)) {
                header('HTTP/1.1 400 Bad Request');
                echo json_encode(['status' => 'error', 'message' => 'Subject IDs are required for this scope']);
                exit;
            }

            $subject_id_array = array_filter(array_map('intval', explode(',', $subject_ids)));
            if (empty($subject_id_array)) {
                header('HTTP/1.1 400 Bad Request');
                echo json_encode(['status' => 'error', 'message' => 'Invalid subject IDs']);
                exit;
            }

            $placeholders = implode(',', array_fill(0, count($subject_id_array), '?'));
            $stmt = $conn->prepare("
            SELECT es.subject_id, es.use_papers, s.name 
            FROM exam_subjects es 
            JOIN subjects s ON es.subject_id = s.subject_id 
            WHERE es.exam_id = ? AND es.subject_id IN ($placeholders)
        ");
            $params = array_merge([$exam_id], $subject_id_array);
            $stmt->bind_param(str_repeat('i', count($params)), ...$params);
            $stmt->execute();
            $subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            if (count($subjects) !== count($subject_id_array)) {
                header('HTTP/1.1 400 Bad Request');
                echo json_encode(['status' => 'error', 'message' => 'One or more subject IDs are invalid for this exam']);
                exit;
            }
        }

        // Fetch papers for subjects that use them
        $subject_papers = [];
        foreach ($subjects as $subject) {
            $subject_id = $subject['subject_id'];
            $subject_papers[$subject_id] = [];

            if ($subject['use_papers'] == 1) {
                $stmt = $conn->prepare("
                SELECT esp.paper_id, sp.paper_name, esp.max_score
                FROM exam_subjects_papers esp
                JOIN subject_papers sp ON esp.paper_id = sp.paper_id
                WHERE esp.exam_id = ? AND esp.subject_id = ?
            ");
                $stmt->bind_param("ii", $exam_id, $subject_id);
                $stmt->execute();
                $subject_papers[$subject_id] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();

                if (empty($subject_papers[$subject_id])) {
                    header('HTTP/1.1 400 Bad Request');
                    echo json_encode([
                        'status' => 'error',
                        'message' => "No papers defined for subject '{$subject['name']}' (use_papers=1)"
                    ]);
                    exit;
                }
            }
        }

        // ── Build header ──
        $headers = ['Student Name', 'Admission No'];

        foreach ($subjects as $subject) {
            if ($subject['use_papers'] == 1 && !empty($subject_papers[$subject['subject_id']])) {
                foreach ($subject_papers[$subject['subject_id']] as $paper) {
                    $headers[] = "{$subject['name']}-{$paper['paper_name']}";
                }
            } else {
                $headers[] = $subject['name'];
            }
        }

        // Create spreadsheet
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($headers, NULL, 'A1');

        // Add student list if requested
        if ($include_students) {
            $students = [];

            if ($scope === 'custom_group') {
                $stmt = $conn->prepare("
                SELECT s.full_name, s.admission_no
                FROM custom_group_students cgs
                JOIN students s ON cgs.student_id = s.student_id
                WHERE cgs.group_id = ? 
                  AND s.school_id = ? 
                  AND s.deleted_at IS NULL
                ORDER BY s.admission_no ASC, s.full_name ASC
            ");
                $stmt->bind_param("ii", $group_id, $school_id);
            } elseif ($scope === 'class') {
                $stmt = $conn->prepare("
                SELECT full_name, admission_no
                FROM students
                WHERE school_id = ? AND class_id = ?
                ORDER BY admission_no ASC, full_name ASC
            ");
                $stmt->bind_param("ii", $school_id, $class_id);
            } elseif ($scope === 'stream') {
                $stmt = $conn->prepare("
                SELECT full_name, admission_no
                FROM students
                WHERE school_id = ? AND class_id = ? AND stream_id = ?
                ORDER BY admission_no ASC, full_name ASC
            ");
                $stmt->bind_param("iii", $school_id, $class_id, $stream_id);
            } elseif ($scope === 'student') {
                $stmt = $conn->prepare("
                SELECT full_name, admission_no
                FROM students
                WHERE school_id = ? AND class_id = ? AND admission_no = ?
            ");
                $stmt->bind_param("iis", $school_id, $class_id, $admission_no);
            }

            if (isset($stmt)) {
                $stmt->execute();
                $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
            }

            if (!empty($students)) {
                $row = 2;
                foreach ($students as $student) {
                    $sheet->setCellValue("A{$row}", $student['full_name']);
                    $sheet->setCellValue("B{$row}", $student['admission_no']);
                    $row++;
                }
            }
        }

        // Force download
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="exam_results_template_custom.xlsx"');
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
        break;

    case 'delete_grading_system':
        if (!hasPermission($conn, $user_id, $role_id, 'manage_grading_systems', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied: manage_grading_systems']);
            ob_end_flush();
            exit;
        }

        $grading_system_id = isset($_POST['grading_system_id']) ? (int)$_POST['grading_system_id'] : 0;

        if (empty($grading_system_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Grading system ID is required']);
            ob_end_flush();
            exit;
        }

        // Check if grading system is default
        $stmt = $conn->prepare("SELECT is_default FROM grading_systems WHERE grading_system_id = ? AND school_id = ?");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("ii", $grading_system_id, $school_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$result) {
            echo json_encode(['status' => 'error', 'message' => 'Grading system not found']);
            ob_end_flush();
            exit;
        }

        if ($result['is_default']) {
            echo json_encode(['status' => 'error', 'message' => 'Cannot delete default grading system']);
            ob_end_flush();
            exit;
        }

        // Check if used in any exams
        $stmt = $conn->prepare("SELECT exam_id FROM exams WHERE grading_system_id = ?");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("i", $grading_system_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Cannot delete grading system used in exams']);
            $stmt->close();
            ob_end_flush();
            exit;
        }
        $stmt->close();

        // Delete grading rules
        $stmt = $conn->prepare("DELETE FROM grading_rules WHERE grading_system_id = ?");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("i", $grading_system_id);
        if (!$stmt->execute()) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to delete grading rules: ' . $conn->error]);
            $stmt->close();
            ob_end_flush();
            exit;
        }
        $stmt->close();

        // Delete grading system
        $stmt = $conn->prepare("DELETE FROM grading_systems WHERE grading_system_id = ? AND school_id = ?");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("ii", $grading_system_id, $school_id);
        if (!$stmt->execute()) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to delete grading system: ' . $conn->error]);
            $stmt->close();
            ob_end_flush();
            exit;
        }
        $stmt->close();

        echo json_encode(['status' => 'success', 'message' => 'Grading system deleted successfully']);
        break;
    case 'get_custom_groups_for_exam':
        $exam_id = (int)($_POST['exam_id'] ?? 0);
        if (!$exam_id) {
            echo json_encode(['status' => 'error', 'message' => 'Exam ID required']);
            exit;
        }

        // Get the class_id of this exam
        $stmt = $conn->prepare("SELECT class_id FROM exams WHERE exam_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $exam_id, $school_id);
        $stmt->execute();
        $exam = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$exam) {
            echo json_encode(['status' => 'error', 'message' => 'Exam not found']);
            exit;
        }

        $class_id = $exam['class_id'];

        $stmt = $conn->prepare("
        SELECT group_id, name
        FROM custom_groups
        WHERE school_id = ? AND class_id = ?
        ORDER BY name
    ");
        $stmt->bind_param("ii", $school_id, $class_id);
        $stmt->execute();
        $groups = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        echo json_encode([
            'status' => 'success',
            'groups' => $groups
        ]);
        break;
    case 'get_custom_group_data':
        $exam_id  = (int)($_POST['exam_id']  ?? 0);
        $group_id = (int)($_POST['group_id'] ?? 0);

        if (!$exam_id || !$group_id) {
            echo json_encode(['status' => 'error', 'message' => 'Missing exam_id or group_id']);
            exit;
        }

        // Get group students — sorted by Admission No ASC (as requested)
        $stmt = $conn->prepare("
        SELECT s.student_id, s.admission_no, s.full_name, s.stream_id
        FROM custom_group_students cgs
        JOIN students s ON cgs.student_id = s.student_id
        WHERE cgs.group_id = ? 
          AND s.school_id = ? 
          AND s.deleted_at IS NULL
        ORDER BY s.admission_no ASC, s.full_name ASC
    ");
        $stmt->bind_param("ii", $group_id, $school_id);
        $stmt->execute();
        $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Get group subjects – get use_papers from class_subjects (via exam's class)
        $stmt = $conn->prepare("
        SELECT 
            s.subject_id, 
            s.name, 
            COALESCE(cs.use_papers, 0) AS use_papers
        FROM custom_group_subjects cgs
        JOIN subjects s ON cgs.subject_id = s.subject_id
        LEFT JOIN class_subjects cs 
               ON cs.subject_id = s.subject_id 
              AND cs.class_id = (SELECT class_id FROM exams WHERE exam_id = ?)
        WHERE cgs.group_id = ? 
          AND s.school_id = ?
    ");
        $stmt->bind_param("iii", $exam_id, $group_id, $school_id);
        $stmt->execute();
        $subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Add papers for subjects that use them
        foreach ($subjects as &$subj) {
            if ($subj['use_papers']) {
                $stmt = $conn->prepare("
                SELECT paper_id, paper_name, max_score
                FROM subject_papers
                WHERE subject_id = ?
            ");
                $stmt->bind_param("i", $subj['subject_id']);
                $stmt->execute();
                $subj['papers'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
            } else {
                $subj['papers'] = [];
            }
        }

        // Attach existing results (same as before)
        foreach ($students as &$student) {
            $stmt = $conn->prepare("
            SELECT subject_id, paper_id, score
            FROM results
            WHERE exam_id = ? AND student_id = ?
        ");
            $stmt->bind_param("ii", $exam_id, $student['student_id']);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $student['results'] = [];
            foreach ($res as $r) {
                $paper = $r['paper_id'] ?? 'null';
                $student['results'][$r['subject_id']][$paper] = ['score' => $r['score']];
            }
        }

        echo json_encode([
            'status'   => 'success',
            'students' => $students,
            'subjects' => $subjects
        ]);
        break;
    case 'export_raw_pdf':
        require_once __DIR__ . '/../../vendor/autoload.php'; // mpdf

        $exam_id      = (int)($_GET['exam_id']      ?? 0);
        $scope        = trim($_GET['scope']         ?? '');
        $group_id     = (int)($_GET['group_id']     ?? 0);
        $stream_id    = (int)($_GET['stream_id']    ?? 0);
        $subject_id   = (int)($_GET['subject_id']   ?? 0);
        $adm_filter   = trim($_GET['adm_filter']    ?? '');

        if (!$exam_id || !in_array($scope, ['current_view', 'stream', 'custom_group'])) {
            http_response_code(400);
            die("Invalid parameters");
        }

        // ── Get exam basic info ───────────────────────────────────────
        $stmt = $conn->prepare("
        SELECT e.exam_name, e.term, c.form_name, e.class_id
        FROM exams e
        JOIN classes c ON e.class_id = c.class_id
        WHERE e.exam_id = ? AND e.school_id = ?
    ");
        $stmt->bind_param("ii", $exam_id, $school_id);
        $stmt->execute();
        $exam = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$exam) die("Exam not found");

        // ── Determine context title (shown in PDF header) ─────────────
        $context_line = '';

        if ($stream_id) {
            $stmt2 = $conn->prepare("SELECT stream_name FROM streams WHERE stream_id = ?");
            $stmt2->bind_param("i", $stream_id);
            $stmt2->execute();
            $row = $stmt2->get_result()->fetch_assoc();
            $stmt2->close();
            if ($row) {
                if ($scope === 'stream') {
                    $context_line = "Stream: <strong>" . htmlspecialchars($row['stream_name']) . "</strong>";
                } else {
                    $context_line = "Filtered by Stream: <strong>" . htmlspecialchars($row['stream_name']) . "</strong>";
                }
            }
        } elseif ($scope === 'custom_group' && $group_id) {
            $stmt2 = $conn->prepare("SELECT name FROM custom_groups WHERE group_id = ?");
            $stmt2->bind_param("i", $group_id);
            $stmt2->execute();
            $row = $stmt2->get_result()->fetch_assoc();
            $stmt2->close();
            if ($row) {
                $context_line = "Custom Group: <strong>" . htmlspecialchars($row['name']) . "</strong>";
            }
        }

        // ── Load subjects ─────────────────────────────────────────────
        $subjects = [];

        if ($scope === 'custom_group' && $group_id) {
            // ── CUSTOM GROUP: Use ONLY subjects assigned to this group ──
            $stmt = $conn->prepare("
            SELECT 
                s.subject_id,
                s.name,
                COALESCE(cs.use_papers, 0) AS use_papers
            FROM custom_group_subjects cgs
            JOIN subjects s ON cgs.subject_id = s.subject_id
            LEFT JOIN class_subjects cs 
                   ON cs.subject_id = s.subject_id 
                  AND cs.class_id = ?
            WHERE cgs.group_id = ?
              AND s.school_id = ?
              AND s.deleted_at IS NULL
            ORDER BY s.name
        ");
            $stmt->bind_param("iii", $exam['class_id'], $group_id, $school_id);
            $stmt->execute();
            $subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            if (empty($subjects)) die("No subjects assigned to this custom group");
        } else {
            // Normal scopes: use exam_subjects (with optional subject filter for current_view)
            $where_subj = "";
            $subj_params = [$exam_id];
            $subj_types = "i";

            if ($scope === 'current_view' && $subject_id) {
                $where_subj = " AND es.subject_id = ?";
                $subj_params[] = $subject_id;
                $subj_types .= "i";
            }

            $stmt = $conn->prepare("
            SELECT es.subject_id, s.name, es.use_papers
            FROM exam_subjects es
            JOIN subjects s ON es.subject_id = s.subject_id
            WHERE es.exam_id = ? $where_subj
            ORDER BY s.name
        ");
            $stmt->bind_param($subj_types, ...$subj_params);
            $stmt->execute();
            $subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            if (empty($subjects)) die("No subjects found");
        }

        // ── Students ──────────────────────────────────────────────────
        $query = "
        SELECT s.student_id, s.admission_no, s.full_name
        FROM students s
        WHERE s.school_id = ?
          AND s.class_id = ?
          AND s.deleted_at IS NULL
    ";
        $params = [$school_id, $exam['class_id']];
        $types = "ii";

        // Stream filter (applies to current_view + stream scope)
        if ($stream_id) {
            $query .= " AND s.stream_id = ?";
            $params[] = $stream_id;
            $types .= "i";
        }
        // Custom group students
        elseif ($scope === 'custom_group' && $group_id) {
            $query = "
            SELECT s.student_id, s.admission_no, s.full_name
            FROM custom_group_students cgs
            JOIN students s ON cgs.student_id = s.student_id
            WHERE cgs.group_id = ?
              AND s.school_id = ?
              AND s.class_id = ?
              AND s.deleted_at IS NULL
        ";
            $params = [$group_id, $school_id, $exam['class_id']];
            $types = "iii";
        }

        if ($adm_filter !== '') {
            $query .= " AND s.admission_no LIKE ?";
            $params[] = "%$adm_filter%";
            $types .= "s";
        }

        $query .= " ORDER BY s.admission_no ASC";

        $stmt = $conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (empty($students)) die("No students found for selected scope");

        // ── Load results ──────────────────────────────────────────────
        foreach ($students as &$st) {
            $stmt = $conn->prepare("
            SELECT subject_id, paper_id, score
            FROM results
            WHERE exam_id = ? AND student_id = ?
        ");
            $stmt->bind_param("ii", $exam_id, $st['student_id']);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $st['results'] = [];
            foreach ($res as $r) {
                $paper = $r['paper_id'] ?? 'null';
                $st['results'][$r['subject_id']][$paper] = $r['score'] ?? '-';
            }
        }

        // ── Build PDF ─────────────────────────────────────────────────
        $mpdf = new \Mpdf\Mpdf([
            'mode'         => 'utf-8',
            'format'       => 'A4-L',
            'margin_left'  => 12,
            'margin_right' => 12,
            'margin_top'   => 18,
            'margin_bottom' => 18,
        ]);

        $html = '
    <div style="text-align:center; margin-bottom:12px;">
        <h2 style="margin:0; font-size:18pt;">' . htmlspecialchars($exam['exam_name']) . '</h2>
        <p style="margin:6px 0 2px 0; font-size:12pt;">
            Class: ' . htmlspecialchars($exam['form_name']) . ' • ' . htmlspecialchars($exam['term']) . '
        </p>';

        if ($context_line) {
            $html .= '
        <p style="margin:2px 0; font-size:11pt; color:#444;">
            ' . $context_line . '
        </p>';
        }

        $html .= '
    </div>
    <hr style="margin:12px 0 18px 0; border:0; border-top:1px solid #aaa;">
    <table border="1" cellpadding="6" cellspacing="0" style="width:100%; border-collapse:collapse; font-size:9.2pt;">
        <thead style="background:#f0f2f5;">
            <tr>
                <th style="width:55px; text-align:center;">Adm No</th>
                <th style="width:170px; text-align:left;">Student Name</th>';

        foreach ($subjects as $subj) {
            if ($subj['use_papers']) {
                $stmt = $conn->prepare("
                SELECT sp.paper_name, esp.max_score
                FROM exam_subjects_papers esp
                JOIN subject_papers sp ON esp.paper_id = sp.paper_id
                WHERE esp.exam_id = ? AND esp.subject_id = ?
                ORDER BY sp.paper_name
            ");
                $stmt->bind_param("ii", $exam_id, $subj['subject_id']);
                $stmt->execute();
                $papers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();

                foreach ($papers as $p) {
                    $html .= "<th style=\"width:65px; text-align:center;\">{$p['paper_name']}<br><small>(max {$p['max_score']})</small></th>";
                }
            } else {
                $html .= "<th style=\"width:85px; text-align:center;\">{$subj['name']}<br><small>(max 100)</small></th>";
            }
        }

        $html .= '
            </tr>
        </thead>
        <tbody>';

        foreach ($students as $student) {
            $html .= '
        <tr>
            <td style="text-align:center;">' . htmlspecialchars($student['admission_no']) . '</td>
            <td>' . htmlspecialchars($student['full_name']) . '</td>';

            foreach ($subjects as $subj) {
                if ($subj['use_papers']) {
                    $stmt = $conn->prepare("
                    SELECT paper_id 
                    FROM exam_subjects_papers 
                    WHERE exam_id = ? AND subject_id = ?
                ");
                    $stmt->bind_param("ii", $exam_id, $subj['subject_id']);
                    $stmt->execute();
                    $paperIds = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'paper_id');
                    $stmt->close();

                    foreach ($paperIds as $pid) {
                        $score = $student['results'][$subj['subject_id']][$pid] ?? '-';
                        $html .= "<td style=\"text-align:center;\">$score</td>";
                    }
                } else {
                    $score = $student['results'][$subj['subject_id']]['null'] ?? '-';
                    $html .= "<td style=\"text-align:center;\">$score</td>";
                }
            }
            $html .= '</tr>';
        }

        $html .= '
        </tbody>
    </table>';

        $mpdf->WriteHTML($html);
        $mpdf->Output("Raw_Results_" . date('Y-m-d') . ".pdf", \Mpdf\Output\Destination::DOWNLOAD);

        exit;
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
        break;
}

ob_end_flush();
