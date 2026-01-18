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
function hasPermission($conn, $user_id, $role_id, $permission_name, $school_id) {
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

// Helper function to sanitize input
function sanitize($conn, $input) {
    if ($input === '') return null;
    return trim($conn->real_escape_string($input));
}

// Function to get grade and points for a score (for future use, e.g., reports)
function getGradeAndPoints($conn, $grading_system_id, $score) {
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
    $subject_ids = isset($_POST['subject_ids']) ? $_POST['subject_ids'] : '';

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
    if (!$stmt) {
        error_log("❌ Prepare failed (get_exam_details): " . $conn->error);
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
        ob_end_flush();
        exit;
    }
    $stmt->bind_param("ii", $exam_id, $school_id);
    if (!$stmt->execute()) {
        error_log("❌ Execute failed (get_exam_details): " . $stmt->error);
        echo json_encode(['status' => 'error', 'message' => 'Database execution error']);
        $stmt->close();
        ob_end_flush();
        exit;
    }
    $exam = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$exam) {
        echo json_encode(['status' => 'error', 'message' => 'Exam not found']);
        ob_end_flush();
        exit;
    }

    // Fetch subjects with use_papers flag
    $query = "
        SELECT es.subject_id, s.name, es.use_papers
        FROM exam_subjects es
        JOIN subjects s ON es.subject_id = s.subject_id
        WHERE es.exam_id = ? AND s.school_id = ?
    ";
    $params = [$exam_id, $school_id];
    $types = "ii";

    // Filter by subject_ids if provided
    if (!empty($subject_ids)) {
        $subject_id_array = array_map('intval', explode(',', $subject_ids));
        $placeholders = implode(',', array_fill(0, count($subject_id_array), '?'));
        $query .= " AND es.subject_id IN ($placeholders)";
        $params = array_merge($params, $subject_id_array);
        $types .= str_repeat('i', count($subject_id_array));
    }

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log(" Prepare failed (get_subjects): " . $conn->error);
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
        ob_end_flush();
        exit;
    }
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) {
        error_log(" Execute failed (get_subjects): " . $stmt->error);
        echo json_encode(['status' => 'error', 'message' => 'Database execution error']);
        $stmt->close();
        ob_end_flush();
        exit;
    }
    $subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($subjects as &$subject) {
        if ($subject['use_papers']) {
            $stmt = $conn->prepare("
                SELECT sp.paper_id, sp.paper_name, esp.max_score
                FROM subject_papers sp
                JOIN exam_subjects_papers esp ON sp.paper_id = esp.paper_id
                WHERE esp.exam_id = ? AND sp.subject_id = ?
            ");
            if (!$stmt) {
                error_log(" Prepare failed (get_papers): " . $conn->error);
                echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
                ob_end_flush();
                exit;
            }
            $stmt->bind_param("ii", $exam_id, $subject['subject_id']);
            if (!$stmt->execute()) {
                error_log(" Execute failed (get_papers): " . $stmt->error);
                echo json_encode(['status' => 'error', 'message' => 'Database execution error']);
                $stmt->close();
                ob_end_flush();
                exit;
            }
            $subject['papers'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        } else {
            $subject['papers'] = [];
        }
    }

    error_log(" get_exam_subjects_with_papers success: " . json_encode(['exam_id' => $exam_id, 'subjects' => $subjects, 'class_id' => $exam['class_id']]));
    echo json_encode([
        'status' => 'success',
        'subjects' => $subjects,
        'class_id' => $exam['class_id'],
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

        $exam_id = (int)$_POST['exam_id'];
    $school_id = $_SESSION['school_id'];
    $user_id = $_SESSION['user_id'];
    $role_id = $_SESSION['role_id'];

        // ✅ Verify permissions
        if (!hasPermission($conn, $user_id, $role_id, 'view_results', $school_id)) {
        echo json_encode(['status' => 'error', 'message' => 'Permission denied']);
        ob_end_flush();
        exit;
    }

        // ✅ Validate exam
        $stmt = $conn->prepare("SELECT class_id, grading_system_id, status 
                            FROM exams 
                            WHERE exam_id = ? AND school_id = ?");
    if (!$stmt) {
        logError("SQL Error in get_exam_subjects_with_results (exam validation): " . $conn->error);
        echo json_encode(['status' => 'error', 'message' => 'Database error: Unable to validate exam']);
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

        // ✅ Fetch subjects with DISTINCT to avoid duplicates
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

        if (!$stmt) {
        logError("SQL Error in get_exam_subjects_with_results (subjects): " . $conn->error);
        echo json_encode(['status' => 'error', 'message' => 'Database error: Unable to fetch subjects']);
        ob_end_flush();
        exit;
    }
        $stmt->bind_param("ii", $exam_id, $school_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $subjects[] = $row;
    }
    $stmt->close();

        // Debug subjects
        error_log("Subjects for exam $exam_id: " . json_encode($subjects, JSON_PRETTY_PRINT));

        // ✅ Fetch papers per subject safely (no reference leakage)
        foreach ($subjects as $i => $subject) {
        if ($subject['use_papers']) {
                $stmt = $conn->prepare("
                SELECT esp.paper_id, sp.paper_name, esp.max_score
                FROM exam_subjects_papers esp
                JOIN subject_papers sp ON esp.paper_id = sp.paper_id
                WHERE esp.exam_id = ? AND esp.subject_id = ?
            ");
            if (!$stmt) {
                logError("SQL Error in get_exam_subjects_with_results (papers for subject {$subject['subject_id']}): " . $conn->error);
                echo json_encode(['status' => 'error', 'message' => "Database error: Unable to fetch papers for subject {$subject['name']}"]);
                ob_end_flush();
                exit;
            }
            $stmt->bind_param("ii", $exam_id, $subject['subject_id']);
            $stmt->execute();
                $resPapers = $stmt->get_result();
                $subjects[$i]['papers'] = [];
                while ($row = $resPapers->fetch_assoc()) {
                    $subjects[$i]['papers'][] = $row;
            }
            $stmt->close();
                if (empty($subjects[$i]['papers'])) {
                logError("Warning: No papers found for subject {$subject['subject_id']} ({$subject['name']}) with use_papers=1");
            }
        } else {
                $subjects[$i]['papers'] = [
                    ['paper_id' => 'null', 'paper_name' => '', 'max_score' => 100]
                ];
        }
    }

        //  If no subjects, return early
        if (empty($subjects)) {
        echo json_encode([
            'status' => 'success',
            'subjects' => [],
            'students' => [],
            'class_id' => $exam['class_id']
        ]);
        ob_end_flush();
        exit;
    }

        //  Fetch students and results
        $placeholders = implode(',', array_fill(0, count($subjects), '?'));
    $stmt = $conn->prepare("
        SELECT s.student_id, s.admission_no, s.full_name, r.subject_id, r.paper_id, r.score
        FROM students s
        LEFT JOIN results r 
            ON s.student_id = r.student_id 
           AND r.exam_id = ? 
           AND r.subject_id IN ($placeholders)
        JOIN exams e 
            ON e.exam_id = ? 
           AND e.class_id = s.class_id
        WHERE s.school_id = ?
    ");
    if (!$stmt) {
        logError("SQL Error in get_exam_subjects_with_results (students and results): " . $conn->error);
        echo json_encode(['status' => 'error', 'message' => 'Database error: Unable to fetch students and results']);
        ob_end_flush();
        exit;
    }

        // Build params dynamically
        $params = array_merge([$exam_id], array_column($subjects, 'subject_id'), [$exam_id, $school_id]);
    $types = 'i' . str_repeat('i', count($subjects)) . 'ii';
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

        $temp_results = [];
    while ($row = $result->fetch_assoc()) {
        if (!isset($temp_results[$row['student_id']])) {
            $temp_results[$row['student_id']] = [
                'student_id' => $row['student_id'],
                'admission_no' => $row['admission_no'],
                'full_name' => $row['full_name'],
                'results' => []
            ];
        }
        if ($row['subject_id']) {
            $paper_id = $row['paper_id'] !== null ? (string)$row['paper_id'] : 'null';
            $temp_results[$row['student_id']]['results'][$row['subject_id']][$paper_id] = [
                'score' => $row['score'] !== null ? (float)$row['score'] : null,
                'grade' => null,
                'points' => null
            ];
        }
    }
    $stmt->close();

        //  Assign grades
        foreach ($temp_results as &$student) {
        foreach ($subjects as $subject) {
            foreach (($subject['use_papers'] ? $subject['papers'] : [['paper_id' => 'null']]) as $paper) {
                $paper_id = $paper['paper_id'] ?? 'null';
                if (isset($student['results'][$subject['subject_id']][$paper_id]['score'])) {
                    $score = $student['results'][$subject['subject_id']][$paper_id]['score'];
                    $grade_points = getGradeAndPoints($conn, $exam['grading_system_id'], $score);
                    $student['results'][$subject['subject_id']][$paper_id]['grade'] = $grade_points['grade'];
                    $student['results'][$subject['subject_id']][$paper_id]['points'] = $grade_points['points'];
                }
            }
            }
    }

    echo json_encode([
        'status' => 'success',
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
    $stmt = $conn->prepare("
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
            echo json_encode(['status' => 'error', 'message' => 'Permission denied: view_exams']);
            ob_end_flush();
            exit;
        }

        $exam_id = isset($_POST['exam_id']) ? (int)$_POST['exam_id'] : 0;
        $class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
        $stream_id = isset($_POST['stream_id']) ? (int)$_POST['stream_id'] : null;
        $student_id = isset($_POST['student_id']) ? (int)$_POST['student_id'] : null;

        if (empty($exam_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Exam ID is required']);
            ob_end_flush();
            exit;
        }

        // Fetch exam class
        $stmt = $conn->prepare("SELECT class_id FROM exams WHERE exam_id = ? AND school_id = ?");
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
            echo json_encode(['status' => 'error', 'message' => 'Invalid exam']);
            ob_end_flush();
            exit;
        }

        $query = "
            SELECT s.student_id, s.full_name, s.class_id, s.stream_id
            FROM students s
            WHERE s.class_id = ? AND s.school_id = ?
        ";
        $params = [$exam['class_id'], $school_id];
        $types = "ii";

        if ($stream_id) {
            $query .= " AND s.stream_id = ?";
            $params[] = $stream_id;
            $types .= "i";
        }

        if ($student_id) {
            $query .= " AND s.student_id = ?";
            $params[] = $student_id;
            $types .= "i";
        }

        $stmt = $conn->prepare($query);
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Fetch existing results for these students
        foreach ($students as &$student) {
            $stmt = $conn->prepare("
                SELECT subject_id, paper_id, score
                FROM results
                WHERE exam_id = ? AND student_id = ?
            ");
            if (!$stmt) {
                echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
                ob_end_flush();
                exit;
            }
            $stmt->bind_param("ii", $exam_id, $student['student_id']);
            $stmt->execute();
            $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $student['results'] = [];
            foreach ($results as $result) {
                $paper_id = $result['paper_id'] ? $result['paper_id'] : 'null';
                $student['results'][$result['subject_id']][$paper_id] = ['score' => $result['score']];
            }
        }

        echo json_encode(['status' => 'success', 'students' => $students]);
        break;

   case 'upload_results_manually':
    $exam_id = isset($_POST['exam_id']) ? (int)$_POST['exam_id'] : 0;
    $subject_ids = isset($_POST['subject_ids']) ? trim($_POST['subject_ids']) : '';
    $results = isset($_POST['results']) ? $_POST['results'] : [];
    $school_id = $_SESSION['school_id'];
    $user_id = $_SESSION['user_id'];
    $role_id = $_SESSION['role_id'];

    if (!hasPermission($conn, $user_id, $role_id, 'enter_results', $school_id)) {
        echo json_encode(['status' => 'error', 'message' => 'Permission denied']);
        ob_end_flush();
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
        ob_end_flush();
        exit;
    }

    if (empty($exam_id) || empty($subject_ids) || empty($results)) {
        echo json_encode(['status' => 'error', 'message' => 'Exam ID, subject IDs, and results are required']);
        ob_end_flush();
        exit;
    }

    // Verify exam
    $stmt = $conn->prepare("SELECT class_id, grading_system_id FROM exams WHERE exam_id = ? AND school_id = ?");
    $stmt->bind_param("ii", $exam_id, $school_id);
    $stmt->execute();
    $exam = $stmt->get_result()->fetch_assoc();
    if (!$exam) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid exam']);
        ob_end_flush();
        exit;
    }
    $class_id = $exam['class_id'];
    $grading_system_id = $exam['grading_system_id'];
    $stmt->close();

    // Validate subject_ids
    $subject_id_array = array_filter(array_map('intval', explode(',', $subject_ids)));
    if (empty($subject_id_array)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid subject IDs']);
        ob_end_flush();
        exit;
    }
    $placeholders = implode(',', array_fill(0, count($subject_id_array), '?'));
    $stmt = $conn->prepare("SELECT es.subject_id, es.use_papers, s.name FROM exam_subjects es JOIN subjects s ON es.subject_id = s.subject_id WHERE es.exam_id = ? AND es.subject_id IN ($placeholders)");
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

    // Fetch papers for subjects with use_papers = 1
    $subject_papers = [];
    $subject_map = [];
    foreach ($valid_subjects as $subject) {
        $subject_id = $subject['subject_id'];
        $subject_map[$subject_id] = $subject['name'];
        $subject_papers[$subject_id] = [];
        if ($subject['use_papers'] == 1) {
            $stmt = $conn->prepare("SELECT paper_id, max_score, paper_name FROM exam_subjects_papers WHERE exam_id = ? AND subject_id = ?");
            $stmt->bind_param("ii", $exam_id, $subject_id);
            $stmt->execute();
            $subject_papers[$subject_id] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            if (empty($subject_papers[$subject_id])) {
                echo json_encode(['status' => 'error', 'message' => "No papers found for subject {$subject['name']} (use_papers = 1)"]);
                ob_end_flush();
                exit;
            }
        }
    }

    // Process results
    $errors = [];
    $conn->begin_transaction();
    try {
        foreach ($results as $student_id => $subjects_data) {
            // Verify student
            $stmt = $conn->prepare("SELECT student_id, class_id, stream_id FROM students WHERE student_id = ? AND school_id = ? AND class_id = ?");
            $stmt->bind_param("iii", $student_id, $school_id, $class_id);
            $stmt->execute();
            $student = $stmt->get_result()->fetch_assoc();
            if (!$student) {
                $errors[] = "Invalid student ID: $student_id";
                continue;
            }
            $stream_id = $student['stream_id'];
            $stmt->close();

            foreach ($valid_subjects as $subject) {
                $subject_id = $subject['subject_id'];
                if (!isset($subjects_data[$subject_id]) || empty($subjects_data[$subject_id])) {
                    $errors[] = "No data for subject ID: $subject_id for student $student_id";
                    continue;
                }

                $scores = $subjects_data[$subject_id];

                if ($subject['use_papers'] == 1) {
                    // Handle subjects with papers
                    foreach ($subject_papers[$subject_id] as $paper) {
                        $paper_id = $paper['paper_id'];
                        if (!isset($scores[$paper_id])) {
                            $errors[] = "No score provided for paper ID: $paper_id for subject $subject_id, student $student_id";
                            continue;
                        }
                        $score = $scores[$paper_id] === '' || $scores[$paper_id] === null ? null : (float)$scores[$paper_id];
                        $max_score = $paper['max_score'];
                        if ($score !== null && ($score < 0 || $score > $max_score)) {
                            $errors[] = "Score $score out of range (0-$max_score) for subject $subject_id, paper $paper_id, student $student_id";
                            continue;
                        }

                        // Fetch grade and points
                        $grade = null;
                        $points = null;
                        if ($score !== null) {
                            $stmt = $conn->prepare("SELECT grade, points FROM grading_rules WHERE grading_system_id = ? AND min_score <= ? AND max_score >= ?");
                            $stmt->bind_param("idd", $grading_system_id, $score, $score);
                            $stmt->execute();
                            $result = $stmt->get_result()->fetch_assoc();
                            if ($result) {
                                $grade = $result['grade'];
                                $points = $result['points'];
                            }
                            $stmt->close();
                        }

                        // Upsert result
                        $stmt = $conn->prepare("
                            INSERT INTO results (school_id, exam_id, student_id, class_id, stream_id, subject_id, paper_id, score, grade, points, status, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
                            ON DUPLICATE KEY UPDATE score = ?, grade = ?, points = ?, status = 'pending'
                        ");
                        $stmt->bind_param("iiiiiiisdsdsds", $school_id, $exam_id, $student_id, $class_id, $stream_id, $subject_id, $paper_id, $score, $grade, $points, $score, $grade, $points);
                        if (!$stmt->execute()) {
                            $errors[] = "Failed to save result for student $student_id, subject $subject_id, paper $paper_id: " . $stmt->error;
                        }
                        $stmt->close();
                    }
                } else {
                    // Handle subjects without papers
                    $score = isset($scores['null']) && $scores['null'] !== '' && $scores['null'] !== null ? (float)$scores['null'] : null;
                    $max_score = 100;
                    if ($score !== null && ($score < 0 || $score > $max_score)) {
                        $errors[] = "Score $score out of range (0-$max_score) for subject $subject_id, student $student_id";
                        continue;
                    }

                    // Fetch grade and points
                    $grade = null;
                    $points = null;
                    if ($score !== null) {
                        $stmt = $conn->prepare("SELECT grade, points FROM grading_rules WHERE grading_system_id = ? AND min_score <= ? AND max_score >= ?");
                        $stmt->bind_param("idd", $grading_system_id, $score, $score);
                        $stmt->execute();
                        $result = $stmt->get_result()->fetch_assoc();
                        if ($result) {
                            $grade = $result['grade'];
                            $points = $result['points'];
                        }
                        $stmt->close();
                    }

                    // Upsert result
                    $stmt = $conn->prepare("
                        INSERT INTO results (school_id, exam_id, student_id, class_id, stream_id, subject_id, paper_id, score, grade, points, status, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, 'pending', NOW())
                        ON DUPLICATE KEY UPDATE score = ?, grade = ?, points = ?, status = 'pending'
                    ");
                    $stmt->bind_param("iiiiisdsdsds", $school_id, $exam_id, $student_id, $class_id, $stream_id, $subject_id, $score, $grade, $points, $score, $grade, $points);
                    if (!$stmt->execute()) {
                        $errors[] = "Failed to save result for student $student_id, subject $subject_id: " . $stmt->error;
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
            echo json_encode(['status' => 'error', 'message' => 'No results saved. Errors: ' . implode('; ', $errors)]);
        }
    } catch (Exception $e) {
        $conn->rollback();
        error_log("❌ Transaction failed: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Transaction failed: ' . $e->getMessage()]);
    }
    ob_end_flush();
    break;

case 'upload_results_excel':
    if (!hasPermission($conn, $user_id, $role_id, 'enter_results', $school_id)) {
        echo json_encode(['status' => 'error', 'message' => 'Permission denied: enter_results']);
        ob_end_flush();
        exit;
    }

    $exam_id = isset($_POST['exam_id']) ? (int)$_POST['exam_id'] : 0;
    $subject_ids = isset($_POST['subject_ids']) ? trim($_POST['subject_ids']) : '';
    $scope = isset($_POST['scope']) ? $_POST['scope'] : '';
    $stream_id = isset($_POST['stream_id']) ? (int)$_POST['stream_id'] : 0;
    $student_id = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
    $file = isset($_FILES['excel_file']) ? $_FILES['excel_file'] : null;

    // Check exam status
    $stmt = $conn->prepare("SELECT status FROM exams WHERE exam_id = ? AND school_id = ?");
    $stmt->bind_param("ii", $exam_id, $school_id);
    $stmt->execute();
    $exam_status = $stmt->get_result()->fetch_assoc()['status'];
    $stmt->close();
    if ($exam_status === 'closed') {
        echo json_encode(['status' => 'error', 'message' => 'Cannot edit results for a closed exam']);
        ob_end_flush();
        exit;
    }

    // Validate inputs
    $errors = [];
    if (empty($exam_id)) {
        $errors[] = 'Exam ID is required';
    }
    if (empty($subject_ids)) {
        $errors[] = 'At least one subject must be selected';
    }
    if (empty($file['name']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'An Excel file is required';
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'File upload error: ' . $file['error'];
    }
    if (empty($scope) || !in_array($scope, ['class', 'stream', 'student'])) {
        $errors[] = 'Invalid scope';
    }
    if ($scope === 'stream' && empty($stream_id)) {
        $errors[] = 'Stream ID is required for stream scope';
    }
    if ($scope === 'student' && empty($student_id)) {
        $errors[] = 'Student ID is required for student scope';
    }

    if (!empty($errors)) {
        echo json_encode(['status' => 'error', 'message' => implode('; ', $errors)]);
        ob_end_flush();
        exit;
    }

    // Validate exam
    $stmt = $conn->prepare("SELECT class_id, grading_system_id FROM exams WHERE exam_id = ? AND school_id = ?");
    $stmt->bind_param("ii", $exam_id, $school_id);
    $stmt->execute();
    $exam = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$exam) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid exam ID']);
        ob_end_flush();
        exit;
    }
    $class_id = $exam['class_id'];
    $grading_system_id = $exam['grading_system_id'];

    // Validate subject_ids and check use_papers
    $subject_id_array = array_filter(array_map('intval', explode(',', $subject_ids)));
    if (empty($subject_id_array)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid subject IDs']);
        ob_end_flush();
        exit;
    }
    $placeholders = implode(',', array_fill(0, count($subject_id_array), '?'));
    $stmt = $conn->prepare("SELECT es.subject_id, s.name, es.use_papers FROM exam_subjects es JOIN subjects s ON es.subject_id = s.subject_id WHERE es.exam_id = ? AND es.subject_id IN ($placeholders)");
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

    // Fetch paper details for subjects with use_papers = 1
    $subject_papers = [];
    $subject_map = [];
    foreach ($valid_subjects as $subject) {
        $subject_id = $subject['subject_id'];
        $subject_map[$subject_id] = $subject['name'];
        $subject_papers[$subject_id] = [];
        if ($subject['use_papers'] == 1) {
            $stmt = $conn->prepare("
                SELECT sp.paper_id, sp.paper_name, esp.max_score
                FROM subject_papers sp
                JOIN exam_subjects_papers esp ON sp.paper_id = esp.paper_id
                WHERE esp.exam_id = ? AND sp.subject_id = ?
            ");
            $stmt->bind_param("ii", $exam_id, $subject_id);
            $stmt->execute();
            $subject_papers[$subject_id] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            if (empty($subject_papers[$subject_id])) {
                $errors[] = "No papers found for subject {$subject['name']} (use_papers = 1)";
            }
        }
    }

    if (!empty($errors)) {
        echo json_encode(['status' => 'error', 'message' => implode('; ', $errors)]);
        ob_end_flush();
        exit;
    }

    // Validate scope and fetch students with stream_id
    $students = [];
    $query = "SELECT student_id, admission_no, full_name, stream_id FROM students WHERE school_id = ? AND class_id = ?";
    $params = [$school_id, $class_id];
    $types = "ii";
    if ($scope === 'stream') {
        $query .= " AND stream_id = ?";
        $params[] = $stream_id;
        $types .= "i";
    } elseif ($scope === 'student') {
        $query .= " AND student_id = ?";
        $params[] = $student_id;
        $types .= "i";
    }
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $students_result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($students_result as $student) {
        $students[$student['admission_no']] = [
            'student_id' => $student['student_id'],
            'stream_id' => $student['stream_id']
        ];
    }
    if (empty($students)) {
        echo json_encode(['status' => 'error', 'message' => 'No students found for the selected scope']);
        ob_end_flush();
        exit;
    }

    // Process Excel file
    try {
        $spreadsheet = IOFactory::load($file['tmp_name']);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray();
        $header = array_shift($rows); // Remove header row

        // Validate header based on use_papers
        $expected_header = ['Admission No'];
        foreach ($valid_subjects as $subject) {
            $subject_id = $subject['subject_id'];
            if ($subject['use_papers'] == 1 && !empty($subject_papers[$subject_id])) {
                foreach ($subject_papers[$subject_id] as $paper) {
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
            echo json_encode(['status' => 'error', 'message' => 'Invalid Excel header format. Expected: ' . implode(', ', $expected_header)]);
            ob_end_flush();
            exit;
        }

        // Process each row
        $conn->begin_transaction();
        try {
            foreach ($rows as $row_num => $row) {
                $admission_no = trim($row[0]);
                if (!isset($students[$admission_no])) {
                    $errors[] = "Invalid admission number: $admission_no (Row " . ($row_num + 2) . ")";
                    continue;
                }
                $current_student_id = $students[$admission_no]['student_id'];
                $current_stream_id = $students[$admission_no]['stream_id'];

                // For single student scope, verify admission number
                if ($scope === 'student') {
                    $stmt = $conn->prepare("SELECT admission_no FROM students WHERE student_id = ?");
                    $stmt->bind_param("i", $student_id);
                    $stmt->execute();
                    $selected_admission_no = $stmt->get_result()->fetch_assoc()['admission_no'];
                    $stmt->close();
                    if ($admission_no !== $selected_admission_no) {
                        $errors[] = "Admission number $admission_no does not match selected student (Row " . ($row_num + 2) . ")";
                        continue;
                    }
                }

                // Check existing results
                $stmt = $conn->prepare("
                    SELECT subject_id, paper_id
                    FROM results
                    WHERE exam_id = ? AND student_id = ? AND subject_id IN ($placeholders)
                ");
                $params = array_merge([$exam_id, $current_student_id], $subject_id_array);
                $stmt->bind_param(str_repeat('i', count($params)), ...$params);
                $stmt->execute();
                $existing_results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                $existing_map = [];
                foreach ($existing_results as $res) {
                    $existing_map[$res['subject_id'] . ($res['paper_id'] ? ':' . $res['paper_id'] : '')] = true;
                }

                // Process scores for each subject
                $col_index = 1;
                foreach ($valid_subjects as $subject) {
                    $subject_id = $subject['subject_id'];
                    if ($subject['use_papers'] == 1 && !empty($subject_papers[$subject_id])) {
                        foreach ($subject_papers[$subject_id] as $paper) {
                            $score = isset($row[$col_index]) && is_numeric($row[$col_index]) ? (float)$row[$col_index] : null;
                            $col_index++;
                            if ($score === null || $score < 0 || $score > $paper['max_score']) {
                                if ($score !== null) {
                                    $errors[] = "Score $score out of range (0-{$paper['max_score']}) for subject {$subject['name']}, paper {$paper['paper_name']}, admission_no $admission_no (Row " . ($row_num + 2) . ")";
                                }
                                continue;
                            }
                            $key = $subject_id . ':' . $paper['paper_id'];
                            $grade = null;
                            $points = null;
                            if ($score !== null) {
                                $stmt = $conn->prepare("SELECT grade, points FROM grading_rules WHERE grading_system_id = ? AND min_score <= ? AND max_score >= ?");
                                $stmt->bind_param("idd", $grading_system_id, $score, $score);
                                $stmt->execute();
                                $result = $stmt->get_result()->fetch_assoc();
                                if ($result) {
                                    $grade = $result['grade'];
                                    $points = $result['points'];
                                }
                                $stmt->close();
                            }
                            if (isset($existing_map[$key])) {
                                // Update existing result
                                $stmt = $conn->prepare("
                                    UPDATE results
                                    SET score = ?, grade = ?, points = ?, updated_at = NOW()
                                    WHERE exam_id = ? AND student_id = ? AND subject_id = ? AND paper_id = ?
                                ");
                                $stmt->bind_param("dsdiiii", $score, $grade, $points, $exam_id, $current_student_id, $subject_id, $paper['paper_id']);
                            } else {
                                // Insert new result
                                $stmt = $conn->prepare("
                                    INSERT INTO results (school_id, exam_id, student_id, class_id, stream_id, subject_id, paper_id, score, grade, points, status, created_at)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
                                ");
                                $stmt->bind_param("iiiiiiisds", $school_id, $exam_id, $current_student_id, $class_id, $current_stream_id, $subject_id, $paper['paper_id'], $score, $grade, $points);
                            }
                            if (!$stmt->execute()) {
                                $errors[] = "Failed to save result for admission_no $admission_no, subject {$subject['name']}, paper {$paper['paper_name']} (Row " . ($row_num + 2) . "): " . $stmt->error;
                            }
                            $stmt->close();
                        }
                    } else {
                        $score = isset($row[$col_index]) && is_numeric($row[$col_index]) ? (float)$row[$col_index] : null;
                        $col_index++;
                        if ($score === null || $score < 0 || $score > 100) {
                            if ($score !== null) {
                                $errors[] = "Score $score out of range (0-100) for subject {$subject['name']}, admission_no $admission_no (Row " . ($row_num + 2) . ")";
                            }
                            continue;
                        }
                        $key = $subject_id . ':null';
                        $grade = null;
                        $points = null;
                        if ($score !== null) {
                            $stmt = $conn->prepare("SELECT grade, points FROM grading_rules WHERE grading_system_id = ? AND min_score <= ? AND max_score >= ?");
                            $stmt->bind_param("idd", $grading_system_id, $score, $score);
                            $stmt->execute();
                            $result = $stmt->get_result()->fetch_assoc();
                            if ($result) {
                                $grade = $result['grade'];
                                $points = $result['points'];
                            }
                            $stmt->close();
                        }
                        if (isset($existing_map[$key])) {
                            // Update existing result
                            $stmt = $conn->prepare("
                                UPDATE results
                                SET score = ?, grade = ?, points = ?, updated_at = NOW()
                                WHERE exam_id = ? AND student_id = ? AND subject_id = ? AND paper_id IS NULL
                            ");
                            $stmt->bind_param("dsdiii", $score, $grade, $points, $exam_id, $current_student_id, $subject_id);
                        } else {
                            // Insert new result
                            $stmt = $conn->prepare("
                                INSERT INTO results (school_id, exam_id, student_id, class_id, stream_id, subject_id, score, grade, points, status, created_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
                            ");
                            $stmt->bind_param("iiiiisdis", $school_id, $exam_id, $current_student_id, $class_id, $current_stream_id, $subject_id, $score, $grade, $points);
                        }
                        if (!$stmt->execute()) {
                            $errors[] = "Failed to save result for admission_no $admission_no, subject {$subject['name']} (Row " . ($row_num + 2) . "): " . $stmt->error;
                        }
                        $stmt->close();
                    }
                }
            }
            if (empty($errors)) {
                $conn->commit();
                echo json_encode(['status' => 'success', 'message' => 'Results uploaded successfully']);
            } else {
                $conn->rollback();
                echo json_encode(['status' => 'error', 'message' => 'No results saved. Errors: ' . implode('; ', $errors)]);
            }
        } catch (Exception $e) {
            $conn->rollback();
            error_log(" Error processing Excel: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Error processing Excel file: ' . $e->getMessage()]);
        }
    } catch (Exception $e) {
        error_log(" Error loading Excel: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Error loading Excel file: ' . $e->getMessage()]);
    }
    ob_end_flush();
    break;

    case 'publish_results':
    if (!hasPermission($conn, $user_id, $role_id, 'approve_results', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied: approve_results']);
        ob_end_flush();
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
            echo json_encode(['status' => 'error', 'message' => 'Permission denied: approve_results']);
            ob_end_flush();
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
            echo json_encode(['status' => 'error', 'message' => 'Permission denied: approve_results']);
            ob_end_flush();
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

    // Validate inputs
    if (empty($exam_id) || empty($subject_ids) || empty($scope) || empty($class_id)) {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['status' => 'error', 'message' => 'Exam ID, subject IDs, scope, and class ID are required']);
        exit;
    }
    if (!in_array($scope, ['class', 'stream', 'student'])) {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['status' => 'error', 'message' => 'Invalid scope']);
        exit;
    }
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

    // Verify exam and class
    $stmt = $conn->prepare("SELECT exam_id, class_id FROM exams WHERE exam_id = ? AND school_id = ?");
    $stmt->bind_param("ii", $exam_id, $school_id);
    $stmt->execute();
    $exam = $stmt->get_result()->fetch_assoc();
    if (!$exam) {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['status' => 'error', 'message' => 'Invalid exam']);
        exit;
    }
    if ($exam['class_id'] !== $class_id) {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['status' => 'error', 'message' => 'Class ID does not match exam']);
        exit;
    }
    $stmt->close();

    // Validate subject_ids
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
        echo json_encode(['status' => 'error', 'message' => 'One or more subject IDs are not associated with this exam']);
        exit;
    }

    // Fetch papers for subjects with use_papers = 1
    $headers = ['Admission No'];
    foreach ($subjects as $subject) {
        if ($subject['use_papers'] == 1) {
            $stmt = $conn->prepare("
                SELECT sp.paper_name, esp.max_score 
                FROM exam_subjects_papers esp 
                JOIN subject_papers sp ON esp.paper_id = sp.paper_id 
                WHERE esp.exam_id = ? AND esp.subject_id = ?
            ");
            $stmt->bind_param("ii", $exam_id, $subject['subject_id']);
            $stmt->execute();
            $papers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            if (empty($papers)) {
                header('HTTP/1.1 400 Bad Request');
                echo json_encode(['status' => 'error', 'message' => "No papers found for subject {$subject['name']}"]);
                exit;
            }
            foreach ($papers as $paper) {
                $headers[] = "{$subject['name']}-{$paper['paper_name']}";
            }
        } else {
            $headers[] = $subject['name'];
        }
    }

    // Initialize spreadsheet
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray($headers, NULL, 'A1');

    // Fetch students if include_students is enabled
    if ($include_students) {
        $students = [];
        if ($scope === 'class') {
            $stmt = $conn->prepare("SELECT admission_no FROM students WHERE school_id = ? AND class_id = ?");
            $stmt->bind_param("ii", $school_id, $class_id);
            $stmt->execute();
            $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        } elseif ($scope === 'stream') {
            $stmt = $conn->prepare("SELECT admission_no FROM students WHERE school_id = ? AND class_id = ? AND stream_id = ?");
            $stmt->bind_param("iii", $school_id, $class_id, $stream_id);
            $stmt->execute();
            $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        } elseif ($scope === 'student') {
            $stmt = $conn->prepare("SELECT admission_no FROM students WHERE school_id = ? AND class_id = ? AND admission_no = ?");
            $stmt->bind_param("iis", $school_id, $class_id, $admission_no);
            $stmt->execute();
            $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }

        if (empty($students)) {
            header('HTTP/1.1 400 Bad Request');
            echo json_encode(['status' => 'error', 'message' => 'No students found for the selected scope']);
            exit;
        }

        // Add student admission numbers to Excel
        $row = 2;
        foreach ($students as $student) {
            $sheet->setCellValue("A{$row}", $student['admission_no']);
            $row++;
        }
    }

    // Set headers for download
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="exam_results_template.xlsx"');
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

    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
        break;
}

ob_end_flush();
?>