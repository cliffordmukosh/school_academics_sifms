<?php
// classes/functions.php
session_start();
require __DIR__ . '/../../connection/db.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['school_id']) || !isset($_SESSION['role_id'])) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access']);
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
    case 'add_class':
        if (!hasPermission($conn, $user_id, $role_id, 'manage_classes', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied: manage_classes']);
            exit;
        }

        $form_name = sanitize($conn, $_POST['form_name']);
        $description = sanitize($conn, $_POST['description']);

        if (empty($form_name)) {
            echo json_encode(['status' => 'error', 'message' => 'Class name is required']);
            exit;
        }

        // Check for duplicate class name
        $stmt = $conn->prepare("SELECT class_id FROM classes WHERE school_id = ? AND form_name = ?");
        $stmt->bind_param("is", $school_id, $form_name);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Class name already exists']);
            exit;
        }
        $stmt->close();

        $stmt = $conn->prepare("INSERT INTO classes (school_id, form_name, description) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $school_id, $form_name, $description);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Class added successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to add class: ' . $conn->error]);
        }
        $stmt->close();
        break;

    case 'edit_class':
        if (!hasPermission($conn, $user_id, $role_id, 'manage_classes', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied: manage_classes']);
            exit;
        }

        $class_id = (int)$_POST['class_id'];
        $form_name = sanitize($conn, $_POST['form_name']);
        $description = sanitize($conn, $_POST['description']);

        if (empty($form_name)) {
            echo json_encode(['status' => 'error', 'message' => 'Class name is required']);
            exit;
        }

        // Verify class
        $stmt = $conn->prepare("SELECT class_id FROM classes WHERE class_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $class_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid class']);
            exit;
        }
        $stmt->close();

        // Check for duplicate class name
        $stmt = $conn->prepare("SELECT class_id FROM classes WHERE school_id = ? AND form_name = ? AND class_id != ?");
        $stmt->bind_param("isi", $school_id, $form_name, $class_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Class name already exists']);
            exit;
        }
        $stmt->close();

        $stmt = $conn->prepare("UPDATE classes SET form_name = ?, description = ? WHERE class_id = ? AND school_id = ?");
        $stmt->bind_param("ssii", $form_name, $description, $class_id, $school_id);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Class updated successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to update class: ' . $conn->error]);
        }
        $stmt->close();
        break;

    case 'delete_class':
        if (!hasPermission($conn, $user_id, $role_id, 'manage_classes', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied: manage_classes']);
            exit;
        }

        $class_id = (int)$_POST['class_id'];

        // Verify class
        $stmt = $conn->prepare("SELECT class_id FROM classes WHERE class_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $class_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid class']);
            exit;
        }
        $stmt->close();

        // Check if class has streams
        $stmt = $conn->prepare("SELECT stream_id FROM streams WHERE class_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $class_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Cannot delete class with associated streams. Delete streams first.']);
            exit;
        }
        $stmt->close();

        // Check if class has students
        $stmt = $conn->prepare("SELECT student_id FROM students WHERE class_id = ? AND school_id = ? AND deleted_at IS NULL");
        $stmt->bind_param("ii", $class_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Cannot delete class with enrolled students. Move students first.']);
            exit;
        }
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM classes WHERE class_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $class_id, $school_id);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Class deleted successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to delete class: ' . $conn->error]);
        }
        $stmt->close();
        break;

    case 'add_stream':
        if (!hasPermission($conn, $user_id, $role_id, 'manage_streams', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied: manage_streams']);
            exit;
        }

        $class_id = (int)$_POST['class_id'];
        $stream_name = sanitize($conn, $_POST['stream_name']);
        $description = sanitize($conn, $_POST['description']);

        if (empty($class_id) || empty($stream_name)) {
            echo json_encode(['status' => 'error', 'message' => 'Class and stream name are required']);
            exit;
        }

        // Verify class
        $stmt = $conn->prepare("SELECT class_id FROM classes WHERE class_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $class_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid class']);
            exit;
        }
        $stmt->close();

        // Check for duplicate stream name in the class
        $stmt = $conn->prepare("SELECT stream_id FROM streams WHERE school_id = ? AND class_id = ? AND stream_name = ?");
        $stmt->bind_param("iis", $school_id, $class_id, $stream_name);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Stream name already exists for this class']);
            exit;
        }
        $stmt->close();

        $stmt = $conn->prepare("INSERT INTO streams (school_id, class_id, stream_name, description) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iiss", $school_id, $class_id, $stream_name, $description);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Stream added successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to add stream: ' . $conn->error]);
        }
        $stmt->close();
        break;

    case 'edit_stream':
        if (!hasPermission($conn, $user_id, $role_id, 'manage_streams', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied: manage_streams']);
            exit;
        }

        $stream_id = (int)$_POST['stream_id'];
        $class_id = (int)$_POST['class_id'];
        $stream_name = sanitize($conn, $_POST['stream_name']);
        $description = sanitize($conn, $_POST['description']);

        if (empty($class_id) || empty($stream_name)) {
            echo json_encode(['status' => 'error', 'message' => 'Class and stream name are required']);
            exit;
        }

        // Verify stream
        $stmt = $conn->prepare("SELECT stream_id FROM streams WHERE stream_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $stream_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid stream']);
            exit;
        }
        $stmt->close();

        // Verify class
        $stmt = $conn->prepare("SELECT class_id FROM classes WHERE class_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $class_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid class']);
            exit;
        }
        $stmt->close();

        // Check for duplicate stream name in the class
        $stmt = $conn->prepare("SELECT stream_id FROM streams WHERE school_id = ? AND class_id = ? AND stream_name = ? AND stream_id != ?");
        $stmt->bind_param("iisi", $school_id, $class_id, $stream_name, $stream_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Stream name already exists for this class']);
            exit;
        }
        $stmt->close();

        $stmt = $conn->prepare("UPDATE streams SET class_id = ?, stream_name = ?, description = ? WHERE stream_id = ? AND school_id = ?");
        $stmt->bind_param("issii", $class_id, $stream_name, $description, $stream_id, $school_id);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Stream updated successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to update stream: ' . $conn->error]);
        }
        $stmt->close();
        break;

    case 'delete_stream':
        if (!hasPermission($conn, $user_id, $role_id, 'manage_streams', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied: manage_streams']);
            exit;
        }

        $stream_id = (int)$_POST['stream_id'];

        // Verify stream
        $stmt = $conn->prepare("SELECT stream_id, class_id FROM streams WHERE stream_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $stream_id, $school_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid stream']);
            exit;
        }
        $stream = $result->fetch_assoc();
        $class_id = $stream['class_id'];
        $stmt->close();

        // Check if stream has students
        $stmt = $conn->prepare("SELECT student_id FROM students WHERE stream_id = ? AND school_id = ? AND deleted_at IS NULL");
        $stmt->bind_param("ii", $stream_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Cannot delete stream with enrolled students. Move students first.']);
            exit;
        }
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM streams WHERE stream_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $stream_id, $school_id);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Stream deleted successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to delete stream: ' . $conn->error]);
        }
        $stmt->close();
        break;

    case 'move_students':
        if (!hasPermission($conn, $user_id, $role_id, 'manage_students', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied: manage_students']);
            exit;
        }

        $source_class_id = (int)$_POST['source_class_id'];
        $source_stream_id = (int)$_POST['source_stream_id'];
        $new_class_id = (int)$_POST['class_id'];
        $new_stream_id = (int)$_POST['stream_id'];

        // Verify source class
        $stmt = $conn->prepare("SELECT class_id FROM classes WHERE class_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $source_class_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid source class']);
            exit;
        }
        $stmt->close();

        // Verify source stream
        $stmt = $conn->prepare("SELECT stream_id FROM streams WHERE stream_id = ? AND school_id = ? AND class_id = ?");
        $stmt->bind_param("iii", $source_stream_id, $school_id, $source_class_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid source stream']);
            exit;
        }
        $stmt->close();

        // Verify new class
        $stmt = $conn->prepare("SELECT class_id FROM classes WHERE class_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $new_class_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid new class']);
            exit;
        }
        $stmt->close();

        // Verify new stream
        $stmt = $conn->prepare("SELECT stream_id FROM streams WHERE stream_id = ? AND school_id = ? AND class_id = ?");
        $stmt->bind_param("iii", $new_stream_id, $school_id, $new_class_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid new stream for selected class']);
            exit;
        }
        $stmt->close();

        // Fetch students in the source class and stream
        $stmt = $conn->prepare("
            SELECT student_id
            FROM students
            WHERE class_id = ? AND stream_id = ? AND school_id = ? AND deleted_at IS NULL
        ");
        $stmt->bind_param("iii", $source_class_id, $source_stream_id, $school_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $student_ids = [];
        while ($row = $result->fetch_assoc()) {
            $student_ids[] = $row['student_id'];
        }
        $stmt->close();

        if (empty($student_ids)) {
            echo json_encode(['status' => 'error', 'message' => 'No students found in the selected class and stream']);
            exit;
        }

        // Move students
        $stmt = $conn->prepare("UPDATE students SET class_id = ?, stream_id = ? WHERE student_id = ? AND school_id = ?");
        $success_count = 0;
        foreach ($student_ids as $student_id) {
            $stmt->bind_param("iiii", $new_class_id, $new_stream_id, $student_id, $school_id);
            if ($stmt->execute()) {
                $success_count++;
            }
        }
        $stmt->close();

        if ($success_count === count($student_ids)) {
            echo json_encode(['status' => 'success', 'message' => "Moved $success_count student(s) successfully"]);
        } else {
            echo json_encode(['status' => 'error', 'message' => "Moved $success_count out of " . count($student_ids) . " students"]);
        }
        break;

    case 'get_classes_and_streams':
        if (!hasPermission($conn, $user_id, $role_id, 'manage_classes', $school_id) || 
            !hasPermission($conn, $user_id, $role_id, 'manage_streams', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied']);
            exit;
        }

        $stmt = $conn->prepare("SELECT class_id, form_name, description FROM classes WHERE school_id = ? ORDER BY form_name");
        $stmt->bind_param("i", $school_id);
        $stmt->execute();
        $classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $stmt = $conn->prepare("SELECT stream_id, stream_name, class_id, description FROM streams WHERE school_id = ? ORDER BY stream_name");
        $stmt->bind_param("i", $school_id);
        $stmt->execute();
        $streams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        echo json_encode(['status' => 'success', 'classes' => $classes, 'streams' => $streams]);
        break;

        case 'get_teachers':
    if (!hasPermission($conn, $user_id, $role_id, 'view_teachers', $school_id)) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Permission denied: view_teachers']);
        exit;
    }

    // Clear output buffer to prevent stray output
    ob_clean();

    // Query to fetch users with the 'Teacher' role
    $stmt = $conn->prepare("
        SELECT u.user_id, CONCAT(u.first_name, ' ', COALESCE(u.other_names, '')) AS full_name
        FROM users u
        JOIN roles r ON u.role_id = r.role_id
        WHERE u.school_id = ? AND u.status = 'active' AND u.deleted_at IS NULL
        AND r.role_name = 'Teacher'
        ORDER BY u.first_name
    ");
    $stmt->bind_param("i", $school_id);
    if (!$stmt->execute()) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Database query failed: ' . $conn->error]);
        exit;
    }
    $teachers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Log the number of teachers fetched for debugging
    error_log('Fetched ' . count($teachers) . ' teachers for school_id ' . $school_id);

    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'teachers' => $teachers]);
    exit;
    break;

  case 'assign_class_supervisor':
    if (!hasPermission($conn, $user_id, $role_id, 'manage_class_supervisors', $school_id)) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Permission denied: manage_class_supervisors']);
        exit;
    }

    $class_id = (int)$_POST['class_id'];
    $user_id = (int)$_POST['user_id'];
    $academic_year = (int)$_POST['academic_year'];

    if (empty($class_id) || empty($user_id) || empty($academic_year)) {
        echo json_encode(['status' => 'error', 'message' => 'Class, teacher, and academic year are required']);
        exit;
    }

    // Verify class
    $stmt = $conn->prepare("SELECT class_id FROM classes WHERE class_id = ? AND school_id = ?");
    $stmt->bind_param("ii", $class_id, $school_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid class']);
        exit;
    }
    $stmt->close();

    // Verify teacher
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE user_id = ? AND school_id = ? AND status = 'active' AND deleted_at IS NULL");
    $stmt->bind_param("ii", $user_id, $school_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid or inactive teacher']);
        exit;
    }
    $stmt->close();

    // Check for existing supervisor
    $stmt = $conn->prepare("SELECT supervisor_id FROM class_supervisors WHERE school_id = ? AND class_id = ? AND academic_year = ?");
    $stmt->bind_param("iii", $school_id, $class_id, $academic_year);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        // Update existing supervisor
        $stmt = $conn->prepare("UPDATE class_supervisors SET user_id = ? WHERE school_id = ? AND class_id = ? AND academic_year = ?");
        $stmt->bind_param("iiii", $user_id, $school_id, $class_id, $academic_year);
    } else {
        // Insert new supervisor
        $stmt = $conn->prepare("INSERT INTO class_supervisors (school_id, user_id, class_id, academic_year) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iiii", $school_id, $user_id, $class_id, $academic_year);
    }
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Class supervisor assigned successfully']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to assign class supervisor: ' . $conn->error]);
    }
    $stmt->close();
    break;

case 'assign_class_teacher':
    if (!hasPermission($conn, $user_id, $role_id, 'manage_class_teachers', $school_id)) {
        echo json_encode(['status' => 'error', 'message' => 'Permission denied: manage_class_teachers']);
        exit;
    }

    $class_id = (int)$_POST['class_id'];
    $stream_id = (int)$_POST['stream_id'];
    $user_id = (int)$_POST['user_id'];
    $academic_year = (int)$_POST['academic_year'];

    if (empty($class_id) || empty($stream_id) || empty($user_id) || empty($academic_year)) {
        echo json_encode(['status' => 'error', 'message' => 'Class, stream, teacher, and academic year are required']);
        exit;
    }

    // Verify class
    $stmt = $conn->prepare("SELECT class_id FROM classes WHERE class_id = ? AND school_id = ?");
    $stmt->bind_param("ii", $class_id, $school_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid class']);
        exit;
    }
    $stmt->close();

    // Verify stream
    $stmt = $conn->prepare("SELECT stream_id FROM streams WHERE stream_id = ? AND school_id = ? AND class_id = ?");
    $stmt->bind_param("iii", $stream_id, $school_id, $class_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid stream for selected class']);
        exit;
    }
    $stmt->close();

    // Verify teacher
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE user_id = ? AND school_id = ? AND status = 'active' AND deleted_at IS NULL");
    $stmt->bind_param("ii", $user_id, $school_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid or inactive teacher']);
        exit;
    }
    $stmt->close();

    // Check for existing class teacher
    $stmt = $conn->prepare("SELECT class_teacher_id FROM class_teachers WHERE school_id = ? AND stream_id = ? AND academic_year = ?");
    $stmt->bind_param("iii", $school_id, $stream_id, $academic_year);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        // Update existing class teacher
        $stmt = $conn->prepare("UPDATE class_teachers SET user_id = ?, class_id = ? WHERE school_id = ? AND stream_id = ? AND academic_year = ?");
        $stmt->bind_param("iiiii", $user_id, $class_id, $school_id, $stream_id, $academic_year);
    } else {
        // Insert new class teacher
        $stmt = $conn->prepare("INSERT INTO class_teachers (school_id, user_id, class_id, stream_id, academic_year) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iiiii", $school_id, $user_id, $class_id, $stream_id, $academic_year);
    }
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Class teacher assigned successfully']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to assign class teacher: ' . $conn->error]);
    }
    $stmt->close();
    break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
        break;
}
?>