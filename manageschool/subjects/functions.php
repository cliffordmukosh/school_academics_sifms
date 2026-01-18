<?php
// subjects/functions.php
session_start();
ob_start(); // Start output buffering to capture any unexpected output
require __DIR__ . '/../../connection/db.php';

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
        return false; // Return false if query preparation fails
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

$action = isset($_POST['action']) ? $_POST['action'] : '';
$school_id = $_SESSION['school_id'];
$user_id = $_SESSION['user_id'];
$role_id = $_SESSION['role_id'];

header('Content-Type: application/json');

switch ($action) {
    case 'add_subject':
        if (!hasPermission($conn, $user_id, $role_id, 'manage_subjects', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied']);
            exit;
        }

        $name           = trim($_POST['name'] ?? '');
        $type           = trim($_POST['type'] ?? '');
        $initial        = trim($_POST['subject_initial'] ?? '');
        $code           = !empty($_POST['subject_code']) ? (int)$_POST['subject_code'] : null;
        $is_cbc         = isset($_POST['is_cbc']) ? 1 : 0;

        if (empty($name) || empty($type)) {
            echo json_encode(['status' => 'error', 'message' => 'Subject name and type are required']);
            exit;
        }

        if (!in_array($type, ['compulsory', 'elective'])) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid subject type']);
            exit;
        }

        // Check duplicate name
        $stmt = $conn->prepare("SELECT subject_id FROM subjects WHERE school_id = ? AND name = ?");
        $stmt->bind_param("is", $school_id, $name);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Subject name already exists']);
            $stmt->close();
            exit;
        }
        $stmt->close();

        $stmt = $conn->prepare("
        INSERT INTO subjects 
        (school_id, name, type, subject_initial, subject_code, is_cbc, is_global) 
        VALUES (?, ?, ?, ?, ?, ?, FALSE)
    ");
        $stmt->bind_param("isssii", $school_id, $name, $type, $initial, $code, $is_cbc);

        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Subject added successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to add subject: ' . $conn->error]);
        }
        $stmt->close();
        break;


    case 'edit_subject':
        if (!hasPermission($conn, $user_id, $role_id, 'manage_subjects', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied']);
            exit;
        }

        $subject_id = (int)($_POST['subject_id'] ?? 0);
        $name       = trim($_POST['name'] ?? '');
        $type       = trim($_POST['type'] ?? '');
        $initial    = trim($_POST['subject_initial'] ?? '');
        $code       = !empty($_POST['subject_code']) ? (int)$_POST['subject_code'] : null;
        $is_cbc     = isset($_POST['is_cbc']) ? 1 : 0;

        if ($subject_id <= 0 || empty($name) || empty($type)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid or incomplete data']);
            exit;
        }

        if (!in_array($type, ['compulsory', 'elective'])) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid subject type']);
            exit;
        }

        // Check duplicate name (exclude self)
        $stmt = $conn->prepare("SELECT subject_id FROM subjects WHERE school_id = ? AND name = ? AND subject_id != ?");
        $stmt->bind_param("isi", $school_id, $name, $subject_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Subject name already exists']);
            $stmt->close();
            exit;
        }
        $stmt->close();

        $stmt = $conn->prepare("
        UPDATE subjects 
        SET name = ?, type = ?, subject_initial = ?, subject_code = ?, is_cbc = ? 
        WHERE subject_id = ? AND school_id = ?
    ");
        $stmt->bind_param("sssiiii", $name, $type, $initial, $code, $is_cbc, $subject_id, $school_id);

        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Subject updated successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to update: ' . $conn->error]);
        }
        $stmt->close();
        break;

    case 'delete_subject':
        if (!hasPermission($conn, $user_id, $role_id, 'manage_subjects', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied: manage_subjects']);
            ob_end_flush();
            exit;
        }

        $subject_id = (int)$_POST['subject_id'];

        // Verify subject
        $stmt = $conn->prepare("SELECT subject_id FROM subjects WHERE subject_id = ? AND school_id = ?");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("ii", $subject_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid subject']);
            $stmt->close();
            ob_end_flush();
            exit;
        }
        $stmt->close();

        // Check if subject is assigned to classes
        $stmt = $conn->prepare("SELECT class_subject_id FROM class_subjects WHERE subject_id = ?");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("i", $subject_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Cannot delete subject assigned to classes. Remove assignments first.']);
            $stmt->close();
            ob_end_flush();
            exit;
        }
        $stmt->close();

        // Check if subject is linked to exams
        $stmt = $conn->prepare("SELECT exam_id FROM exam_subjects WHERE subject_id = ?");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("i", $subject_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Cannot delete subject linked to exams. Remove exam associations first.']);
            $stmt->close();
            ob_end_flush();
            exit;
        }
        $stmt->close();

        // Check if subject is linked to teacher_subjects
        $stmt = $conn->prepare("SELECT teacher_subject_id FROM teacher_subjects WHERE subject_id = ?");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("i", $subject_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Cannot delete subject linked to teachers. Remove teacher assignments first.']);
            $stmt->close();
            ob_end_flush();
            exit;
        }
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM subjects WHERE subject_id = ? AND school_id = ?");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("ii", $subject_id, $school_id);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Subject deleted successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to delete subject: ' . $conn->error]);
        }
        $stmt->close();
        break;

    case 'assign_subject':
        if (!hasPermission($conn, $user_id, $role_id, 'manage_subjects', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied: manage_subjects']);
            ob_end_flush();
            exit;
        }

        $class_id = (int)$_POST['class_id'];
        $subject_id = (int)$_POST['subject_id'];
        $type = sanitize($conn, $_POST['type']);
        $use_papers = isset($_POST['use_papers']) ? 1 : 0;

        if (empty($class_id) || empty($subject_id) || empty($type)) {
            echo json_encode(['status' => 'error', 'message' => 'Class, subject, and type are required']);
            ob_end_flush();
            exit;
        }

        // Validate type
        if (!in_array($type, ['compulsory', 'elective'])) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid assignment type']);
            ob_end_flush();
            exit;
        }

        // Verify class
        $stmt = $conn->prepare("SELECT class_id FROM classes WHERE class_id = ? AND school_id = ?");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("ii", $class_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid class']);
            $stmt->close();
            ob_end_flush();
            exit;
        }
        $stmt->close();

        // Verify subject
        $stmt = $conn->prepare("SELECT subject_id FROM subjects WHERE subject_id = ? AND school_id = ?");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("ii", $subject_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid subject']);
            $stmt->close();
            ob_end_flush();
            exit;
        }
        $stmt->close();

        // Check for duplicate assignment
        $stmt = $conn->prepare("SELECT class_subject_id FROM class_subjects WHERE class_id = ? AND subject_id = ?");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("ii", $class_id, $subject_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Subject already assigned to this class']);
            $stmt->close();
            ob_end_flush();
            exit;
        }
        $stmt->close();

        $stmt = $conn->prepare("INSERT INTO class_subjects (class_id, subject_id, type, use_papers) VALUES (?, ?, ?, ?)");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("iisi", $class_id, $subject_id, $type, $use_papers);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Subject assigned successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to assign subject: ' . $conn->error]);
        }
        $stmt->close();
        break;

    case 'remove_subject_assignment':
        if (!hasPermission($conn, $user_id, $role_id, 'manage_subjects', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied: manage_subjects']);
            ob_end_flush();
            exit;
        }

        $class_id = (int)$_POST['class_id'];
        $subject_id = (int)$_POST['subject_id'];

        // Verify assignment
        $stmt = $conn->prepare("SELECT class_subject_id FROM class_subjects WHERE class_id = ? AND subject_id = ?");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("ii", $class_id, $subject_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid assignment']);
            $stmt->close();
            ob_end_flush();
            exit;
        }
        $stmt->close();

        // Check if subject is linked to exams for this class
        $stmt = $conn->prepare("
            SELECT es.exam_id
            FROM exam_subjects es
            JOIN exams e ON es.exam_id = e.exam_id
            WHERE es.subject_id = ? AND e.class_id = ?
        ");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("ii", $subject_id, $class_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Cannot remove subject assigned to exams for this class. Remove exam associations first.']);
            $stmt->close();
            ob_end_flush();
            exit;
        }
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM class_subjects WHERE class_id = ? AND subject_id = ?");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("ii", $class_id, $subject_id);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Subject assignment removed successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to remove subject assignment: ' . $conn->error]);
        }
        $stmt->close();
        break;

    case 'get_subjects_and_assignments':
        if (!hasPermission($conn, $user_id, $role_id, 'view_subjects', $school_id) || 
            !hasPermission($conn, $user_id, $role_id, 'view_classes', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied']);
            ob_end_flush();
            exit;
        }

        $stmt = $conn->prepare("SELECT class_id, form_name FROM classes WHERE school_id = ? ORDER BY form_name");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("i", $school_id);
        $stmt->execute();
        $classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $stmt = $conn->prepare("SELECT subject_id, name, type FROM subjects WHERE school_id = ? ORDER BY name");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("i", $school_id);
        $stmt->execute();
        $subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $stmt = $conn->prepare("
            SELECT cs.class_subject_id, cs.class_id, cs.subject_id, cs.type, cs.use_papers, c.form_name, s.name
            FROM class_subjects cs
            JOIN classes c ON cs.class_id = c.class_id
            JOIN subjects s ON cs.subject_id = s.subject_id
            WHERE c.school_id = ?
            ORDER BY c.form_name, s.name
        ");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("i", $school_id);
        $stmt->execute();
        $assignments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        echo json_encode(['status' => 'success', 'classes' => $classes, 'subjects' => $subjects, 'assignments' => $assignments]);
        break;

    case 'get_subject_papers':
        if (!hasPermission($conn, $user_id, $role_id, 'view_subject_papers', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied: view_subject_papers']);
            ob_end_flush();
            exit;
        }

        $subject_id = (int)$_POST['subject_id'];

        // Verify subject belongs to school
        $stmt = $conn->prepare("SELECT subject_id FROM subjects WHERE subject_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $subject_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid subject']);
            $stmt->close();
            ob_end_flush();
            exit;
        }
        $stmt->close();

        $stmt = $conn->prepare("SELECT paper_id, paper_name, max_score, contribution_percentage, description FROM subject_papers WHERE subject_id = ? ORDER BY paper_name");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("i", $subject_id);
        $stmt->execute();
        $papers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        echo json_encode(['status' => 'success', 'papers' => $papers]);
        break;

    case 'add_paper':
        if (!hasPermission($conn, $user_id, $role_id, 'manage_subject_papers', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied: manage_subject_papers']);
            ob_end_flush();
            exit;
        }

        $subject_id = (int)$_POST['subject_id'];
        $paper_name = sanitize($conn, $_POST['paper_name']);
        $max_score = (float)$_POST['max_score'];
        $contribution_percentage = (float)$_POST['contribution_percentage'];
        $description = sanitize($conn, $_POST['description']);

        if (empty($paper_name) || $max_score <= 0 || $contribution_percentage <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Paper name, max score, and contribution percentage are required and must be positive']);
            ob_end_flush();
            exit;
        }

        // Verify subject
        $stmt = $conn->prepare("SELECT subject_id FROM subjects WHERE subject_id = ? AND school_id = ?");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("ii", $subject_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid subject']);
            $stmt->close();
            ob_end_flush();
            exit;
        }
        $stmt->close();

        // Check for duplicate paper name
        $stmt = $conn->prepare("SELECT paper_id FROM subject_papers WHERE subject_id = ? AND paper_name = ?");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("is", $subject_id, $paper_name);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Paper name already exists for this subject']);
            $stmt->close();
            ob_end_flush();
            exit;
        }
        $stmt->close();

        $stmt = $conn->prepare("INSERT INTO subject_papers (school_id, subject_id, paper_name, max_score, contribution_percentage, description) VALUES (?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("iisdds", $school_id, $subject_id, $paper_name, $max_score, $contribution_percentage, $description);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Paper added successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to add paper: ' . $conn->error]);
        }
        $stmt->close();
        break;

    case 'edit_paper':
        if (!hasPermission($conn, $user_id, $role_id, 'manage_subject_papers', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied: manage_subject_papers']);
            ob_end_flush();
            exit;
        }

        $paper_id = (int)$_POST['paper_id'];
        $paper_name = sanitize($conn, $_POST['paper_name']);
        $max_score = (float)$_POST['max_score'];
        $contribution_percentage = (float)$_POST['contribution_percentage'];
        $description = sanitize($conn, $_POST['description']);

        if (empty($paper_name) || $max_score <= 0 || $contribution_percentage <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Paper name, max score, and contribution percentage are required and must be positive']);
            ob_end_flush();
            exit;
        }

        // Verify paper belongs to school subject
        $stmt = $conn->prepare("SELECT p.paper_id FROM subject_papers p JOIN subjects s ON p.subject_id = s.subject_id WHERE p.paper_id = ? AND s.school_id = ?");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("ii", $paper_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid paper']);
            $stmt->close();
            ob_end_flush();
            exit;
        }
        $stmt->close();

        // Check for duplicate paper name (excluding current)
        $stmt = $conn->prepare("SELECT p.paper_id FROM subject_papers p WHERE p.subject_id = (SELECT subject_id FROM subject_papers WHERE paper_id = ?) AND p.paper_name = ? AND p.paper_id != ?");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("isi", $paper_id, $paper_name, $paper_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Paper name already exists for this subject']);
            $stmt->close();
            ob_end_flush();
            exit;
        }
        $stmt->close();

        $stmt = $conn->prepare("UPDATE subject_papers SET paper_name = ?, max_score = ?, contribution_percentage = ?, description = ? WHERE paper_id = ?");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("sddsi", $paper_name, $max_score, $contribution_percentage, $description, $paper_id);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Paper updated successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to update paper: ' . $conn->error]);
        }
        $stmt->close();
        break;

    case 'delete_paper':
        if (!hasPermission($conn, $user_id, $role_id, 'manage_subject_papers', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied: manage_subject_papers']);
            ob_end_flush();
            exit;
        }

        $paper_id = (int)$_POST['paper_id'];

        // Verify paper belongs to school subject
        $stmt = $conn->prepare("SELECT p.paper_id FROM subject_papers p JOIN subjects s ON p.subject_id = s.subject_id WHERE p.paper_id = ? AND s.school_id = ?");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("ii", $paper_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid paper']);
            $stmt->close();
            ob_end_flush();
            exit;
        }
        $stmt->close();

        // Optional: Check if paper is used in results or exams
        $stmt = $conn->prepare("SELECT result_id FROM results WHERE paper_id = ?");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("i", $paper_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Cannot delete paper used in results']);
            $stmt->close();
            ob_end_flush();
            exit;
        }
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM subject_papers WHERE paper_id = ?");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("i", $paper_id);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Paper deleted successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to delete paper: ' . $conn->error]);
        }
        $stmt->close();
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
        break;



    case 'get_available_subjects':
        if (!hasPermission($conn, $user_id, $role_id, 'view_subjects', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied']);
            ob_end_flush();
            exit;
        }

        $class_id = (int)$_POST['class_id'];

        // Verify class and get its is_cbc value
        $stmt = $conn->prepare("SELECT class_id, is_cbc FROM classes WHERE class_id = ? AND school_id = ?");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("ii", $class_id, $school_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid class']);
            $stmt->close();
            ob_end_flush();
            exit;
        }
        $class = $result->fetch_assoc();
        $is_cbc_class = (int)$class['is_cbc']; // 1 = CBC class, 0 = 8-4-4 class
        $stmt->close();

        // Now fetch only subjects that match the curriculum AND are not already assigned
        $stmt = $conn->prepare("
        SELECT s.subject_id, s.name, s.type 
        FROM subjects s 
        WHERE s.school_id = ? 
          AND s.is_cbc = ?
          AND NOT EXISTS (
              SELECT 1 FROM class_subjects cs 
              WHERE cs.class_id = ? AND cs.subject_id = s.subject_id
          )
        ORDER BY s.name
    ");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("iii", $school_id, $is_cbc_class, $class_id);
        $stmt->execute();
        $subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        echo json_encode([
            'status' => 'success',
            'subjects' => $subjects,
            'debug' => ['class_is_cbc' => $is_cbc_class] // Optional: remove later if not needed
        ]);
        break;

case 'bulk_assign_subjects':
    if (!hasPermission($conn, $user_id, $role_id, 'manage_subjects', $school_id)) {
        echo json_encode(['status' => 'error', 'message' => 'Permission denied: manage_subjects']);
        ob_end_flush();
        exit;
    }

    $class_id = (int)$_POST['class_id'];
    $type = sanitize($conn, $_POST['type']);
    $use_papers = isset($_POST['use_papers']) ? 1 : 0;
    $subject_ids = isset($_POST['subject_ids']) ? $_POST['subject_ids'] : [];

    if (empty($class_id) || empty($type) || !is_array($subject_ids) || empty($subject_ids)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid input: class, type, and at least one subject required']);
        ob_end_flush();
        exit;
    }

    // Validate type
    if (!in_array($type, ['compulsory', 'elective'])) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid assignment type']);
        ob_end_flush();
        exit;
    }

    // Verify class
    $stmt = $conn->prepare("SELECT class_id FROM classes WHERE class_id = ? AND school_id = ?");
    if (!$stmt) {
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
        ob_end_flush();
        exit;
    }
    $stmt->bind_param("ii", $class_id, $school_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid class']);
        $stmt->close();
        ob_end_flush();
        exit;
    }
    $stmt->close();

    $conn->begin_transaction();
    try {
        $insertStmt = $conn->prepare("INSERT INTO class_subjects (class_id, subject_id, type, use_papers) VALUES (?, ?, ?, ?)");
        if (!$insertStmt) {
            throw new Exception('Database error: ' . $conn->error);
        }

        foreach ($subject_ids as $subject_id) {
            $subject_id = (int)$subject_id;

            // Verify subject and not already assigned
            $checkStmt = $conn->prepare("SELECT subject_id FROM subjects WHERE subject_id = ? AND school_id = ?");
            $checkStmt->bind_param("ii", $subject_id, $school_id);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows === 0) {
                throw new Exception('Invalid subject: ' . $subject_id);
            }
            $checkStmt->close();

            $checkAssign = $conn->prepare("SELECT class_subject_id FROM class_subjects WHERE class_id = ? AND subject_id = ?");
            $checkAssign->bind_param("ii", $class_id, $subject_id);
            $checkAssign->execute();
            if ($checkAssign->get_result()->num_rows > 0) {
                throw new Exception('Subject already assigned: ' . $subject_id);
            }
            $checkAssign->close();

            $insertStmt->bind_param("iisi", $class_id, $subject_id, $type, $use_papers);
            if (!$insertStmt->execute()) {
                throw new Exception('Failed to assign subject ' . $subject_id . ': ' . $conn->error);
            }
        }

        $insertStmt->close();
        $conn->commit();
        echo json_encode(['status' => 'success', 'message' => 'Subjects assigned successfully']);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    break;

     
}

ob_end_flush(); // Send output and clear buffer
?>