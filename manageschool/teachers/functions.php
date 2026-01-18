<?php
// teachers/functions.php
session_start();
require __DIR__ . '/../../connection/db.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['school_id']) || !isset($_SESSION['role_id'])) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access']);
    exit;
}

// Permission check function
function hasPermission($conn, $role_id, $permission_name, $school_id) {
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

// Sanitize input
function sanitize($conn, $input) {
    if ($input === '' || $input === null) return null;
    return trim($conn->real_escape_string($input));
}

$action = isset($_POST['action']) ? $_POST['action'] : '';
$school_id = $_SESSION['school_id'];
$user_id = $_SESSION['user_id'];
$role_id = $_SESSION['role_id'];

header('Content-Type: application/json');

switch ($action) {
    case 'add_teacher':
        if (!hasPermission($conn, $role_id, 'manage_teachers', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied']);
            exit;
        }

        $first_name = sanitize($conn, $_POST['first_name']);
        $other_names = sanitize($conn, $_POST['other_names']);
        $username = sanitize($conn, $_POST['username']);
        $password = $_POST['password'];
        $email = sanitize($conn, $_POST['email']);
        $personal_email = sanitize($conn, $_POST['personal_email']);
        $phone_number = sanitize($conn, $_POST['phone_number']);
        $gender = sanitize($conn, $_POST['gender']);
        $tsc_number = sanitize($conn, $_POST['tsc_number']);
        $employee_number = sanitize($conn, $_POST['employee_number']);

        if (empty($first_name) || empty($username) || empty($password)) {
            echo json_encode(['status' => 'error', 'message' => 'First name, username, and password are required']);
            exit;
        }

        // Check unique username
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Username already exists']);
            exit;
        }
        $stmt->close();

        // Check unique email (if provided)
        if ($email) {
            $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND school_id = ?");
            $stmt->bind_param("si", $email, $school_id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                echo json_encode(['status' => 'error', 'message' => 'Email already exists']);
                exit;
            }
            $stmt->close();
        }

        // Get Teacher role_id
        $stmt = $conn->prepare("SELECT role_id FROM roles WHERE school_id = ? AND role_name = 'Teacher'");
        $stmt->bind_param("i", $school_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Teacher role not found']);
            exit;
        }
        $teacher_role = $result->fetch_assoc()['role_id'];
        $stmt->close();

        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("
            INSERT INTO users (
                school_id, role_id, first_name, other_names, username, email, personal_email, 
                phone_number, gender, tsc_number, employee_number, password_hash, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
        ");
        $stmt->bind_param(
            "iissssssssss",
            $school_id, $teacher_role, $first_name, $other_names, $username, $email, 
            $personal_email, $phone_number, $gender, $tsc_number, $employee_number, $password_hash
        );
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Teacher added successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to add teacher: ' . $conn->error]);
        }
        $stmt->close();
        break;

    case 'edit_teacher':
        if (!hasPermission($conn, $role_id, 'manage_teachers', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied']);
            exit;
        }

        $user_id = (int)$_POST['user_id'];
        $first_name = sanitize($conn, $_POST['first_name']);
        $other_names = sanitize($conn, $_POST['other_names']);
        $username = sanitize($conn, $_POST['username']);
        $password = $_POST['password'];
        $email = sanitize($conn, $_POST['email']);
        $personal_email = sanitize($conn, $_POST['personal_email']);
        $phone_number = sanitize($conn, $_POST['phone_number']);
        $gender = sanitize($conn, $_POST['gender']);
        $tsc_number = sanitize($conn, $_POST['tsc_number']);
        $employee_number = sanitize($conn, $_POST['employee_number']);
        $status = sanitize($conn, $_POST['status']);

        if (empty($first_name) || empty($username) || empty($status)) {
            echo json_encode(['status' => 'error', 'message' => 'First name, username, and status are required']);
            exit;
        }

        // Verify teacher
        $stmt = $conn->prepare("
            SELECT u.user_id 
            FROM users u 
            JOIN roles r ON u.role_id = r.role_id 
            WHERE u.user_id = ? AND u.school_id = ? AND r.role_name = 'Teacher' AND u.deleted_at IS NULL
        ");
        $stmt->bind_param("ii", $user_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid teacher']);
            exit;
        }
        $stmt->close();

        // Check unique username
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ? AND user_id != ?");
        $stmt->bind_param("si", $username, $user_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Username already exists']);
            exit;
        }
        $stmt->close();

        // Check unique email (if provided)
        if ($email) {
            $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND school_id = ? AND user_id != ?");
            $stmt->bind_param("sii", $email, $school_id, $user_id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                echo json_encode(['status' => 'error', 'message' => 'Email already exists']);
                exit;
            }
            $stmt->close();
        }

        // Prepare update query
        $query = "
            UPDATE users 
            SET first_name = ?, other_names = ?, username = ?, email = ?, personal_email = ?, 
                phone_number = ?, gender = ?, tsc_number = ?, employee_number = ?, status = ?
        ";
        $params = [$first_name, $other_names, $username, $email, $personal_email, $phone_number, $gender, $tsc_number, $employee_number, $status];

        if (!empty($password)) {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $query .= ", password_hash = ?";
            $params[] = $password_hash;
        }

        $query .= " WHERE user_id = ? AND school_id = ?";
        $params[] = $user_id;
        $params[] = $school_id;

        $stmt = $conn->prepare($query);
        $stmt->bind_param(str_repeat('s', count($params) - 2) . 'ii', ...$params);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Teacher updated successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to update teacher: ' . $conn->error]);
        }
        $stmt->close();
        break;

    case 'delete_teacher':
        if (!hasPermission($conn, $role_id, 'manage_teachers', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied']);
            exit;
        }

        $user_id = (int)$_POST['user_id'];

        // Verify teacher
        $stmt = $conn->prepare("
            SELECT u.user_id 
            FROM users u 
            JOIN roles r ON u.role_id = r.role_id 
            WHERE u.user_id = ? AND u.school_id = ? AND r.role_name = 'Teacher' AND u.deleted_at IS NULL
        ");
        $stmt->bind_param("ii", $user_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid teacher']);
            exit;
        }
        $stmt->close();

        // Soft delete
        $stmt = $conn->prepare("UPDATE users SET deleted_at = CURRENT_TIMESTAMP WHERE user_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $user_id, $school_id);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Teacher deleted successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to delete teacher: ' . $conn->error]);
        }
        $stmt->close();
        break;

    case 'assign_subject':
        if (!hasPermission($conn, $role_id, 'assign_subjects', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied']);
            exit;
        }

        $teacher_id = (int)$_POST['user_id'];
        $subject_id = (int)$_POST['subject_id'];
        $class_id = !empty($_POST['class_id']) ? (int)$_POST['class_id'] : null;
        $stream_id = !empty($_POST['stream_id']) ? (int)$_POST['stream_id'] : null;
        $academic_year = !empty($_POST['academic_year']) ? (int)$_POST['academic_year'] : null;

        // Validate required fields
        if (!$teacher_id || !$subject_id || !$academic_year) {
            echo json_encode(['status' => 'error', 'message' => 'Teacher, subject, and academic year are required']);
            exit;
        }

        // Verify teacher
        $stmt = $conn->prepare("
            SELECT u.user_id 
            FROM users u 
            JOIN roles r ON u.role_id = r.role_id 
            WHERE u.user_id = ? AND u.school_id = ? AND r.role_name = 'Teacher' AND u.deleted_at IS NULL
        ");
        $stmt->bind_param("ii", $teacher_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid teacher']);
            exit;
        }
        $stmt->close();

        // Verify subject
        $stmt = $conn->prepare("SELECT subject_id FROM subjects WHERE subject_id = ? AND school_id = ? AND deleted_at IS NULL");
        $stmt->bind_param("ii", $subject_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid subject']);
            exit;
        }
        $stmt->close();

        // Verify class (if provided)
        if ($class_id) {
            $stmt = $conn->prepare("SELECT class_id FROM classes WHERE class_id = ? AND school_id = ?");
            $stmt->bind_param("ii", $class_id, $school_id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows === 0) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid class']);
                exit;
            }
            $stmt->close();
        }

        // Verify stream (if provided)
        if ($stream_id) {
            if (!$class_id) {
                echo json_encode(['status' => 'error', 'message' => 'Class must be selected if stream is provided']);
                exit;
            }
            $stmt = $conn->prepare("SELECT stream_id FROM streams WHERE stream_id = ? AND school_id = ? AND class_id = ?");
            $stmt->bind_param("iii", $stream_id, $school_id, $class_id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows === 0) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid stream for selected class']);
                exit;
            }
            $stmt->close();
        }

        // Check for duplicate assignment
        $query = "
            SELECT teacher_subject_id 
            FROM teacher_subjects 
            WHERE school_id = ? AND user_id = ? AND subject_id = ? AND academic_year = ?
        ";
        $params = [$school_id, $teacher_id, $subject_id, $academic_year];
        $types = "iiii";

        if ($class_id !== null) {
            $query .= " AND class_id = ?";
            $params[] = $class_id;
            $types .= "i";
        } else {
            $query .= " AND class_id IS NULL";
        }

        if ($stream_id !== null) {
            $query .= " AND stream_id = ?";
            $params[] = $stream_id;
            $types .= "i";
        } else {
            $query .= " AND stream_id IS NULL";
        }

        $stmt = $conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Subject already assigned to this teacher for the selected class/stream/year']);
            exit;
        }
        $stmt->close();

        // Insert assignment
        $stmt = $conn->prepare("
            INSERT INTO teacher_subjects (school_id, user_id, subject_id, class_id, stream_id, academic_year)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("iiiiis", $school_id, $teacher_id, $subject_id, $class_id, $stream_id, $academic_year);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Subject assigned successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to assign subject: ' . $conn->error]);
        }
        $stmt->close();
        break;

    case 'edit_assignment':
        if (!hasPermission($conn, $role_id, 'assign_subjects', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied']);
            exit;
        }

        $teacher_subject_id = (int)$_POST['teacher_subject_id'];
        $teacher_id = (int)$_POST['user_id'];
        $subject_id = (int)$_POST['subject_id'];
        $class_id = !empty($_POST['class_id']) ? (int)$_POST['class_id'] : null;
        $stream_id = !empty($_POST['stream_id']) ? (int)$_POST['stream_id'] : null;
        $academic_year = !empty($_POST['academic_year']) ? (int)$_POST['academic_year'] : null;

        // Validate required fields
        if (!$teacher_subject_id || !$teacher_id || !$subject_id || !$academic_year) {
            echo json_encode(['status' => 'error', 'message' => 'Assignment ID, teacher, subject, and academic year are required']);
            exit;
        }

        // Verify assignment
        $stmt = $conn->prepare("SELECT teacher_subject_id FROM teacher_subjects WHERE teacher_subject_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $teacher_subject_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid assignment']);
            exit;
        }
        $stmt->close();

        // Verify teacher
        $stmt = $conn->prepare("
            SELECT u.user_id 
            FROM users u 
            JOIN roles r ON u.role_id = r.role_id 
            WHERE u.user_id = ? AND u.school_id = ? AND r.role_name = 'Teacher' AND u.deleted_at IS NULL
        ");
        $stmt->bind_param("ii", $teacher_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid teacher']);
            exit;
        }
        $stmt->close();

        // Verify subject
        $stmt = $conn->prepare("SELECT subject_id FROM subjects WHERE subject_id = ? AND school_id = ? AND deleted_at IS NULL");
        $stmt->bind_param("ii", $subject_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid subject']);
            exit;
        }
        $stmt->close();

        // Verify class (if provided)
        if ($class_id) {
            $stmt = $conn->prepare("SELECT class_id FROM classes WHERE class_id = ? AND school_id = ?");
            $stmt->bind_param("ii", $class_id, $school_id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows === 0) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid class']);
                exit;
            }
            $stmt->close();
        }

        // Verify stream (if provided)
        if ($stream_id) {
            if (!$class_id) {
                echo json_encode(['status' => 'error', 'message' => 'Class must be selected if stream is provided']);
                exit;
            }
            $stmt = $conn->prepare("SELECT stream_id FROM streams WHERE stream_id = ? AND school_id = ? AND class_id = ?");
            $stmt->bind_param("iii", $stream_id, $school_id, $class_id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows === 0) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid stream for selected class']);
                exit;
            }
            $stmt->close();
        }

        // Check for duplicate assignment (excluding current assignment)
        $query = "
            SELECT teacher_subject_id 
            FROM teacher_subjects 
            WHERE school_id = ? AND user_id = ? AND subject_id = ? AND academic_year = ? 
            AND teacher_subject_id != ?
        ";
        $params = [$school_id, $teacher_id, $subject_id, $academic_year, $teacher_subject_id];
        $types = "iiisi";

        if ($class_id !== null) {
            $query .= " AND class_id = ?";
            $params[] = $class_id;
            $types .= "i";
        } else {
            $query .= " AND class_id IS NULL";
        }

        if ($stream_id !== null) {
            $query .= " AND stream_id = ?";
            $params[] = $stream_id;
            $types .= "i";
        } else {
            $query .= " AND stream_id IS NULL";
        }

        $stmt = $conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Subject already assigned to this teacher for the selected class/stream/year']);
            exit;
        }
        $stmt->close();

        // Update assignment
        $stmt = $conn->prepare("
            UPDATE teacher_subjects 
            SET subject_id = ?, class_id = ?, stream_id = ?, academic_year = ?
            WHERE teacher_subject_id = ? AND school_id = ?
        ");
        $stmt->bind_param("iisiii", $subject_id, $class_id, $stream_id, $academic_year, $teacher_subject_id, $school_id);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Assignment updated successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to update assignment: ' . $conn->error]);
        }
        $stmt->close();
        break;

    case 'delete_assignment':
        if (!hasPermission($conn, $role_id, 'assign_subjects', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied']);
            exit;
        }

        $teacher_subject_id = (int)$_POST['teacher_subject_id'];

        // Verify assignment
        $stmt = $conn->prepare("SELECT teacher_subject_id FROM teacher_subjects WHERE teacher_subject_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $teacher_subject_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid assignment']);
            exit;
        }
        $stmt->close();

        // Delete assignment
        $stmt = $conn->prepare("DELETE FROM teacher_subjects WHERE teacher_subject_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $teacher_subject_id, $school_id);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Assignment deleted successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to delete assignment: ' . $conn->error]);
        }
        $stmt->close();
        break;

    case 'get_assign_options':
        if (!hasPermission($conn, $role_id, 'assign_subjects', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied']);
            exit;
        }

        // Fetch subjects
        $stmt = $conn->prepare("SELECT subject_id, name FROM subjects WHERE school_id = ? AND deleted_at IS NULL ORDER BY name");
        $stmt->bind_param("i", $school_id);
        $stmt->execute();
        $subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Fetch classes
        $stmt = $conn->prepare("SELECT class_id, form_name FROM classes WHERE school_id = ? ORDER BY form_name");
        $stmt->bind_param("i", $school_id);
        $stmt->execute();
        $classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Fetch streams
        $stmt = $conn->prepare("SELECT stream_id, stream_name, class_id FROM streams WHERE school_id = ? ORDER BY stream_name");
        $stmt->bind_param("i", $school_id);
        $stmt->execute();
        $streams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        echo json_encode([
            'status' => 'success',
            'subjects' => $subjects ?: [],
            'classes' => $classes ?: [],
            'streams' => $streams ?: []
        ]);
        break;

    case 'get_streams_by_class':
        if (!hasPermission($conn, $role_id, 'assign_subjects', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied']);
            exit;
        }

        $class_id = !empty($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
        if ($class_id === 0) {
            echo json_encode(['status' => 'success', 'streams' => []]);
            exit;
        }

        $stmt = $conn->prepare("SELECT stream_id, stream_name FROM streams WHERE school_id = ? AND class_id = ? ORDER BY stream_name");
        $stmt->bind_param("ii", $school_id, $class_id);
        $stmt->execute();
        $streams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        echo json_encode(['status' => 'success', 'streams' => $streams ?: []]);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
        break;
}

$conn->close();
?>