<?php
// students/functions.php
session_start();
require __DIR__ . '/../../connection/db.php';
require __DIR__ . '/../../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;

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

// Handle file uploads
// Handle file uploads
function handleFileUpload($file, $field_name, $upload_dir = 'manageschool/studentsprofile/') {
    if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $allowed_types = ['image/jpeg', 'image/png', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    if (!in_array($file['type'], $allowed_types)) {
        return ['error' => 'Invalid file type for ' . $field_name];
    }
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid() . '.' . $ext;
    $destination = __DIR__ . '/../../' . $upload_dir . $filename;
    if (!is_dir(dirname($destination))) {
        mkdir(dirname($destination), 0755, true);
    }
    if (move_uploaded_file($file['tmp_name'], $destination)) {
        return $upload_dir . $filename;
    }
    return ['error' => 'Failed to upload ' . $field_name];
}

$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');
$school_id = $_SESSION['school_id'];
$user_id = $_SESSION['user_id'];
$role_id = $_SESSION['role_id'];

header('Content-Type: application/json');

switch ($action) {
    case 'add_student_key_details':
        if (!hasPermission($conn, $user_id, $role_id, 'manage_students', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied: manage_students']);
            exit;
        }

        $admission_no = sanitize($conn, $_POST['admission_no']);
        $full_name = sanitize($conn, $_POST['full_name']);
        $gender = sanitize($conn, $_POST['gender']);
        $class_id = (int)$_POST['class_id'];
        $stream_id = (int)$_POST['stream_id'];
        $dob = sanitize($conn, $_POST['dob']);
        $primary_phone = sanitize($conn, $_POST['primary_phone']);
        $secondary_phone = sanitize($conn, $_POST['secondary_phone']);
        $profile_picture_result = handleFileUpload($_FILES['profile_picture'] ?? null, 'profile_picture');

        if (empty($admission_no) || empty($full_name) || empty($gender) || empty($class_id) || empty($stream_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Required fields are missing']);
            exit;
        }

        if (!in_array($gender, ['Male', 'Female'])) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid gender']);
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

        // Check for duplicate admission number
        $stmt = $conn->prepare("SELECT student_id FROM students WHERE admission_no = ? AND school_id = ?");
        $stmt->bind_param("si", $admission_no, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Admission number already exists']);
            exit;
        }
        $stmt->close();

        if (is_array($profile_picture_result) && isset($profile_picture_result['error'])) {
            echo json_encode(['status' => 'error', 'message' => $profile_picture_result['error']]);
            exit;
        }
        $profile_picture = $profile_picture_result;

        $stmt = $conn->prepare("
            INSERT INTO students (
                school_id, class_id, stream_id, admission_no, full_name, gender, 
                dob, primary_phone, secondary_phone, profile_picture
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "iiisssssss",
            $school_id, $class_id, $stream_id, $admission_no, $full_name, $gender,
            $dob, $primary_phone, $secondary_phone, $profile_picture
        );
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Key student details added successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to add student: ' . $conn->error]);
        }
        $stmt->close();
        break;

    case 'add_student':
        if (!hasPermission($conn, $user_id, $role_id, 'manage_students', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied: manage_students']);
            exit;
        }

        $admission_no = sanitize($conn, $_POST['admission_no']);
        $full_name = sanitize($conn, $_POST['full_name']);
        $gender = sanitize($conn, $_POST['gender']);
        $class_id = (int)$_POST['class_id'];
        $stream_id = (int)$_POST['stream_id'];
        $dob = sanitize($conn, $_POST['dob']);
        $primary_phone = sanitize($conn, $_POST['primary_phone']);
        $secondary_phone = sanitize($conn, $_POST['secondary_phone']);
        $date_of_admission = sanitize($conn, $_POST['date_of_admission']);
        $upi = sanitize($conn, $_POST['upi']);
        $kcpe_index = sanitize($conn, $_POST['kcpe_index']);
        $kcpe_score = isset($_POST['kcpe_score']) && $_POST['kcpe_score'] !== '' ? (int)$_POST['kcpe_score'] : null;
        $kcpe_grade = sanitize($conn, $_POST['kcpe_grade']);
        $kcpe_year = isset($_POST['kcpe_year']) && $_POST['kcpe_year'] !== '' ? (int)$_POST['kcpe_year'] : null;
        $index_number = sanitize($conn, $_POST['index_number']);
        $previous_school = sanitize($conn, $_POST['previous_school']);
        $primary_school = sanitize($conn, $_POST['primary_school']);
        $guardian_name = sanitize($conn, $_POST['guardian_name']);
        $guardian_relation = sanitize($conn, $_POST['guardian_relation']);
        $primary_phone_2 = sanitize($conn, $_POST['primary_phone_2']);
        $secondary_phone_2 = sanitize($conn, $_POST['secondary_phone_2']);
        $birth_cert_number = sanitize($conn, $_POST['birth_cert_number']);
        $nationality = sanitize($conn, $_POST['nationality']);
        $place_of_birth = sanitize($conn, $_POST['place_of_birth']);
        $nhif = sanitize($conn, $_POST['nhif']);
        $general_comments = sanitize($conn, $_POST['general_comments']);
        $entry_position = sanitize($conn, $_POST['entry_position']);
        $profile_picture_result = handleFileUpload($_FILES['profile_picture'] ?? null, 'profile_picture');
        $enrollment_form_result = handleFileUpload($_FILES['enrollment_form'] ?? null, 'enrollment_form');

        if (empty($admission_no) || empty($full_name) || empty($gender) || empty($class_id) || empty($stream_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Required fields are missing']);
            exit;
        }

        if (!in_array($gender, ['Male', 'Female'])) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid gender']);
            exit;
        }

        if ($kcpe_score !== null && ($kcpe_score < 0 || $kcpe_score > 500)) {
            echo json_encode(['status' => 'error', 'message' => 'KCPE score must be between 0 and 500']);
            exit;
        }

        if ($kcpe_year !== null && ($kcpe_year < 1900 || $kcpe_year > date('Y'))) {
            echo json_encode(['status' => 'error', 'message' => 'KCPE year must be a valid year']);
            exit;
        }

        if (is_array($profile_picture_result) && isset($profile_picture_result['error'])) {
            echo json_encode(['status' => 'error', 'message' => $profile_picture_result['error']]);
            exit;
        }
        $profile_picture = $profile_picture_result;

        if (is_array($enrollment_form_result) && isset($enrollment_form_result['error'])) {
            echo json_encode(['status' => 'error', 'message' => $enrollment_form_result['error']]);
            exit;
        }
        $enrollment_form = $enrollment_form_result;

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

        // Check for duplicate admission number
        $stmt = $conn->prepare("SELECT student_id FROM students WHERE admission_no = ? AND school_id = ?");
        $stmt->bind_param("si", $admission_no, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Admission number already exists']);
            exit;
        }
        $stmt->close();

        $stmt = $conn->prepare("
            INSERT INTO students (
                school_id, class_id, stream_id, admission_no, full_name, gender, 
                date_of_admission, upi, kcpe_index, kcpe_score, kcpe_grade, kcpe_year, 
                index_number, dob, nationality, place_of_birth, previous_school, primary_phone, 
                primary_phone_2, secondary_phone, secondary_phone_2, guardian_name, guardian_relation, 
                primary_school, nhif, general_comments, enrollment_form, entry_position, profile_picture
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "iiissssssisississssssssssssss",
            $school_id, $class_id, $stream_id, $admission_no, $full_name, $gender,
            $date_of_admission, $upi, $kcpe_index, $kcpe_score, $kcpe_grade, $kcpe_year,
            $index_number, $dob, $nationality, $place_of_birth, $previous_school, $primary_phone,
            $primary_phone_2, $secondary_phone, $secondary_phone_2, $guardian_name, $guardian_relation,
            $primary_school, $nhif, $general_comments, $enrollment_form, $entry_position, $profile_picture
        );
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Student added successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to add student: ' . $conn->error]);
        }
        $stmt->close();
        break;

    case 'change_class':
        if (!hasPermission($conn, $user_id, $role_id, 'manage_students', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied: manage_students']);
            exit;
        }

        $student_id = (int)$_POST['student_id'];
        $class_id = (int)$_POST['class_id'];
        $stream_id = (int)$_POST['stream_id'];

        $stmt = $conn->prepare("SELECT student_id FROM students WHERE student_id = ? AND school_id = ? AND deleted_at IS NULL");
        $stmt->bind_param("ii", $student_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid student']);
            exit;
        }
        $stmt->close();

        $stmt = $conn->prepare("SELECT class_id FROM classes WHERE class_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $class_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid class']);
            exit;
        }
        $stmt->close();

        $stmt = $conn->prepare("SELECT stream_id FROM streams WHERE stream_id = ? AND school_id = ? AND class_id = ?");
        $stmt->bind_param("iii", $stream_id, $school_id, $class_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid stream for selected class']);
            exit;
        }
        $stmt->close();

        $stmt = $conn->prepare("UPDATE students SET class_id = ?, stream_id = ? WHERE student_id = ? AND school_id = ?");
        $stmt->bind_param("iiii", $class_id, $stream_id, $student_id, $school_id);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Class updated successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to update class: ' . $conn->error]);
        }
        $stmt->close();
        break;

    case 'change_stream':
        if (!hasPermission($conn, $user_id, $role_id, 'manage_students', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied: manage_students']);
            exit;
        }

        $student_id = (int)$_POST['student_id'];
        $stream_id = (int)$_POST['stream_id'];

        $stmt = $conn->prepare("SELECT student_id, class_id FROM students WHERE student_id = ? AND school_id = ? AND deleted_at IS NULL");
        $stmt->bind_param("ii", $student_id, $school_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid student']);
            exit;
        }
        $student = $result->fetch_assoc();
        $class_id = $student['class_id'];
        $stmt->close();

        $stmt = $conn->prepare("SELECT stream_id FROM streams WHERE stream_id = ? AND school_id = ? AND class_id = ?");
        $stmt->bind_param("iii", $stream_id, $school_id, $class_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid stream for student\'s class']);
            exit;
        }
        $stmt->close();

        $stmt = $conn->prepare("UPDATE students SET stream_id = ? WHERE student_id = ? AND school_id = ?");
        $stmt->bind_param("iii", $stream_id, $student_id, $school_id);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Stream updated successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to update stream: ' . $conn->error]);
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

        $stmt = $conn->prepare("SELECT class_id FROM classes WHERE class_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $class_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid class']);
            exit;
        }
        $stmt->close();

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

    case 'get_student_class':
        if (!hasPermission($conn, $user_id, $role_id, 'manage_students', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied: manage_students']);
            exit;
        }

        $student_id = (int)$_POST['student_id'];

        $stmt = $conn->prepare("SELECT class_id FROM students WHERE student_id = ? AND school_id = ? AND deleted_at IS NULL");
        $stmt->bind_param("ii", $student_id, $school_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid student']);
            exit;
        }
        $student = $result->fetch_assoc();
        echo json_encode(['status' => 'success', 'class_id' => $student['class_id']]);
        $stmt->close();
        break;

    case 'delete_student':
        if (!hasPermission($conn, $user_id, $role_id, 'manage_students', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied: manage_students']);
            exit;
        }

        $student_id = (int)$_POST['student_id'];

        $stmt = $conn->prepare("SELECT student_id FROM students WHERE student_id = ? AND school_id = ? AND deleted_at IS NULL");
        $stmt->bind_param("ii", $student_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid student']);
            exit;
        }
        $stmt->close();

        $stmt = $conn->prepare("UPDATE students SET deleted_at = CURRENT_TIMESTAMP WHERE student_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $student_id, $school_id);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Student deleted successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to delete student: ' . $conn->error]);
        }
        $stmt->close();
        break;

    case 'get_student':
        if (!hasPermission($conn, $user_id, $role_id, 'manage_students', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied: manage_students']);
            exit;
        }

        $student_id = (int)$_POST['student_id'];
        $stmt = $conn->prepare("
            SELECT student_id, admission_no, full_name, gender, dob, class_id, stream_id, 
                   primary_phone, secondary_phone, date_of_admission, enrollment_form, 
                   entry_position, kcpe_index, kcpe_score, kcpe_grade, kcpe_year, 
                   index_number, previous_school, primary_school, upi, guardian_name, 
                   guardian_relation, primary_phone_2, secondary_phone_2, birth_cert_number, 
                   nationality, place_of_birth, nhif, general_comments
            FROM students 
            WHERE student_id = ? AND school_id = ? AND deleted_at IS NULL
        ");
        $stmt->bind_param("ii", $student_id, $school_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Student not found']);
            exit;
        }
        $student = $result->fetch_assoc();
        echo json_encode(['status' => 'success', 'student' => $student]);
        $stmt->close();
        break;

    case 'edit_student':
    if (!hasPermission($conn, $user_id, $role_id, 'manage_students', $school_id)) {
        echo json_encode(['status' => 'error', 'message' => 'Permission denied: manage_students']);
        exit;
    }

    // Sanitize and fetch POST data
    $student_id = (int)$_POST['student_id'];
    $admission_no = sanitize($conn, $_POST['admission_no']);
    $full_name = sanitize($conn, $_POST['full_name']);
    $gender = sanitize($conn, $_POST['gender']);
    $class_id = (int)$_POST['class_id'];
    $stream_id = (int)$_POST['stream_id'];
    $dob = sanitize($conn, $_POST['dob']);
    $primary_phone = sanitize($conn, $_POST['primary_phone']);
    $secondary_phone = sanitize($conn, $_POST['secondary_phone']);
    $date_of_admission = sanitize($conn, $_POST['date_of_admission']);
    $upi = sanitize($conn, $_POST['upi']);
    $kcpe_index = sanitize($conn, $_POST['kcpe_index']);
    $kcpe_score = isset($_POST['kcpe_score']) && $_POST['kcpe_score'] !== '' ? (int)$_POST['kcpe_score'] : null;
    $kcpe_grade = sanitize($conn, $_POST['kcpe_grade']);
    $kcpe_year = isset($_POST['kcpe_year']) && $_POST['kcpe_year'] !== '' ? (int)$_POST['kcpe_year'] : null;
    $index_number = sanitize($conn, $_POST['index_number']);
    $previous_school = sanitize($conn, $_POST['previous_school']);
    $primary_school = sanitize($conn, $_POST['primary_school']);
    $guardian_name = sanitize($conn, $_POST['guardian_name']);
    $guardian_relation = sanitize($conn, $_POST['guardian_relation']);
    $primary_phone_2 = sanitize($conn, $_POST['primary_phone_2']);
    $secondary_phone_2 = sanitize($conn, $_POST['secondary_phone_2']);
    $birth_cert_number = sanitize($conn, $_POST['birth_cert_number']);
    $nationality = sanitize($conn, $_POST['nationality']);
    $place_of_birth = sanitize($conn, $_POST['place_of_birth']);
    $nhif = sanitize($conn, $_POST['nhif']);
    $general_comments = sanitize($conn, $_POST['general_comments']);
    $entry_position = sanitize($conn, $_POST['entry_position']);
    $profile_picture_result = handleFileUpload($_FILES['profile_picture'] ?? null, 'profile_picture');
    $enrollment_form_result = handleFileUpload($_FILES['enrollment_form'] ?? null, 'enrollment_form');

    // Required field checks
    if (empty($student_id) || empty($admission_no) || empty($full_name) || empty($gender) || empty($class_id) || empty($stream_id)) {
        echo json_encode(['status' => 'error', 'message' => 'Required fields are missing']);
        exit;
    }

    if (!in_array($gender, ['Male', 'Female'])) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid gender']);
        exit;
    }

    if ($kcpe_score !== null && ($kcpe_score < 0 || $kcpe_score > 500)) {
        echo json_encode(['status' => 'error', 'message' => 'KCPE score must be between 0 and 500']);
        exit;
    }

    if ($kcpe_year !== null && ($kcpe_year < 1900 || $kcpe_year > date('Y'))) {
        echo json_encode(['status' => 'error', 'message' => 'KCPE year must be a valid year']);
        exit;
    }

    // Verify student exists
    $stmt = $conn->prepare("SELECT student_id FROM students WHERE student_id = ? AND school_id = ? AND deleted_at IS NULL");
    $stmt->bind_param("ii", $student_id, $school_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        echo json_encode(['status' => 'error', 'message' => 'Student not found']);
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

    // Verify stream
    $stmt = $conn->prepare("SELECT stream_id FROM streams WHERE stream_id = ? AND school_id = ? AND class_id = ?");
    $stmt->bind_param("iii", $stream_id, $school_id, $class_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid stream for selected class']);
        exit;
    }
    $stmt->close();

    // Check for duplicate admission number
    $stmt = $conn->prepare("SELECT student_id FROM students WHERE admission_no = ? AND school_id = ? AND student_id != ?");
    $stmt->bind_param("sii", $admission_no, $school_id, $student_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Admission number already exists']);
        exit;
    }
    $stmt->close();

    // Handle file uploads
    if (is_array($profile_picture_result) && isset($profile_picture_result['error'])) {
        echo json_encode(['status' => 'error', 'message' => $profile_picture_result['error']]);
        exit;
    }
    $profile_picture = $profile_picture_result;

    if (is_array($enrollment_form_result) && isset($enrollment_form_result['error'])) {
        echo json_encode(['status' => 'error', 'message' => $enrollment_form_result['error']]);
        exit;
    }
    $enrollment_form = $enrollment_form_result;

    // Debug bind_param count
    $vars = [
        $admission_no, $full_name, $gender, $class_id, $stream_id,
        $dob, $primary_phone, $secondary_phone, $date_of_admission,
        $upi, $kcpe_index, $kcpe_score, $kcpe_grade, $kcpe_year,
        $index_number, $previous_school, $primary_school, $guardian_name,
        $guardian_relation, $primary_phone_2, $secondary_phone_2,
        $birth_cert_number, $nationality, $place_of_birth, $nhif,
        $general_comments, $enrollment_form, $entry_position, $profile_picture,
        $student_id, $school_id
    ];
    $typeString = "sssiisssssississsssssssssssssii";

    function debug_bind_param($types, $vars) {
        if (strlen($types) !== count($vars)) {
            echo "<pre style='color:red;'>🔴 bind_param mismatch detected!\n";
            echo "Number of type characters: " . strlen($types) . "\n";
            echo "Number of variables: " . count($vars) . "\n";
            echo "Variables:\n"; print_r($vars);
            echo "\nType string:\n$types\n</pre>";
            exit;
        }
    }

    debug_bind_param($typeString, $vars);

    // Prepare and execute update
    $stmt = $conn->prepare("
        UPDATE students SET
            admission_no = ?, full_name = ?, gender = ?, class_id = ?, stream_id = ?,
            dob = ?, primary_phone = ?, secondary_phone = ?, date_of_admission = ?,
            upi = ?, kcpe_index = ?, kcpe_score = ?, kcpe_grade = ?, kcpe_year = ?,
            index_number = ?, previous_school = ?, primary_school = ?, guardian_name = ?,
            guardian_relation = ?, primary_phone_2 = ?, secondary_phone_2 = ?,
            birth_cert_number = ?, nationality = ?, place_of_birth = ?, nhif = ?,
            general_comments = ?, enrollment_form = COALESCE(?, enrollment_form),
            entry_position = ?, profile_picture = COALESCE(?, profile_picture)
        WHERE student_id = ? AND school_id = ?
    ");

    $stmt->bind_param($typeString, ...$vars);

    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Student updated successfully']);
    } else {
        error_log('SQL Error: ' . $stmt->error);
        echo json_encode(['status' => 'error', 'message' => 'Failed to update student: ' . $stmt->error]);
    }
    $stmt->close();
break;


    case 'get_excel_format':
        if (!hasPermission($conn, $user_id, $role_id, 'manage_students', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied: manage_students']);
            exit;
        }

        $fields = [
            ['name' => 'admission_no', 'description' => 'Unique admission number', 'required' => true],
            ['name' => 'full_name', 'description' => 'Student\'s full name', 'required' => true],
            ['name' => 'gender', 'description' => 'Male or Female', 'required' => true],
            ['name' => 'class_name', 'description' => 'Class name (e.g., Form 1, matches classes.form_name)', 'required' => true],
            ['name' => 'stream_name', 'description' => 'Stream name (e.g., East, matches streams.stream_name)', 'required' => true],
            ['name' => 'dob', 'description' => 'Date of birth (YYYY-MM-DD)', 'required' => false],
            ['name' => 'primary_phone', 'description' => 'Primary phone number', 'required' => false],
            ['name' => 'secondary_phone', 'description' => 'Secondary phone number', 'required' => false],
            ['name' => 'date_of_admission', 'description' => 'Date of admission (YYYY-MM-DD)', 'required' => false],
            ['name' => 'upi', 'description' => 'Unique Personal Identifier', 'required' => false],
            ['name' => 'kcpe_index', 'description' => 'KCPE index number', 'required' => false],
            ['name' => 'kcpe_score', 'description' => 'KCPE score (0-500)', 'required' => false],
            ['name' => 'kcpe_grade', 'description' => 'KCPE grade (e.g., A)', 'required' => false],
            ['name' => 'kcpe_year', 'description' => 'KCPE year (e.g., 2023)', 'required' => false],
            ['name' => 'index_number', 'description' => 'Examination index number', 'required' => false],
            ['name' => 'previous_school', 'description' => 'Previous school attended', 'required' => false],
            ['name' => 'primary_school', 'description' => 'Primary school attended', 'required' => false],
            ['name' => 'guardian_name', 'description' => 'Guardian\'s name', 'required' => false],
            ['name' => 'guardian_relation', 'description' => 'Guardian\'s relation to student', 'required' => false],
            ['name' => 'primary_phone_2', 'description' => 'Guardian\'s primary phone number', 'required' => false],
            ['name' => 'secondary_phone_2', 'description' => 'Guardian\'s secondary phone number', 'required' => false],
            ['name' => 'birth_cert_number', 'description' => 'Birth certificate number', 'required' => false],
            ['name' => 'nationality', 'description' => 'Nationality', 'required' => false],
            ['name' => 'place_of_birth', 'description' => 'Place of birth', 'required' => false],
            ['name' => 'nhif', 'description' => 'NHIF number', 'required' => false],
            ['name' => 'general_comments', 'description' => 'General comments', 'required' => false],
            ['name' => 'entry_position', 'description' => 'Entry position (e.g., Top 10, 1st)', 'required' => false]
        ];
        echo json_encode(['status' => 'success', 'fields' => $fields]);
        break;

case 'download_excel_template':
    if (!hasPermission($conn, $user_id, $role_id, 'manage_students', $school_id)) {
        echo json_encode(['status' => 'error', 'message' => 'Permission denied: manage_students']);
        exit;
    }

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // Define column headers based on students table
    $headers = [
        'admission_no', 'full_name', 'gender', 'class_name', 'stream_name', 'dob', 'primary_phone',
        'secondary_phone', 'date_of_admission', 'upi', 'kcpe_index', 'kcpe_score', 'kcpe_grade',
        'kcpe_year', 'index_number', 'previous_school', 'primary_school', 'guardian_name',
        'guardian_relation', 'primary_phone_2', 'secondary_phone_2', 'birth_cert_number',
        'nationality', 'place_of_birth', 'nhif', 'general_comments', 'entry_position'
    ];

    // Set headers in the first row
    $col = 1;
    foreach ($headers as $header) {
        $columnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
        $sheet->setCellValue($columnLetter . '1', $header);
        $col++;
    }

    // Add two sample rows with numeric class_name
    $sampleData = [
        [
            'S001', 'John Doe', 'Male', '1', 'East', '2005-05-15', '0712345678',
            '0723456789', '2025-01-10', 'UPI123456', 'KCPE001', 350, 'B+', 2020,
            'IDX001', 'Previous High School', 'St. Mary Primary', 'Jane Doe',
            'Parent', '0734567890', '0745678901', 'BC123456', 'Kenyan', 'Nairobi',
            'NHIF123456', 'Good student', '1st'
        ],
        [
            'S002', 'Mary Jane', 'Female', '2', 'West', '2004-08-20', '0756789012',
            '0767890123', '2025-01-15', 'UPI789012', 'KCPE002', 400, 'A-', 2020,
            'IDX002', 'Another School', 'St. John Primary', 'James Jane',
            'Guardian', '0778901234', '0789012345', 'BC789012', 'Kenyan', 'Mombasa',
            'NHIF789012', 'Active in sports', '2nd'
        ]
    ];

    // Set sample data in rows 2 and 3
    foreach ($sampleData as $rowIndex => $rowData) {
        $col = 1;
        foreach ($rowData as $value) {
            $columnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            $sheet->setCellValue($columnLetter . ($rowIndex + 2), $value);
            $col++;
        }
    }

    // Auto-size columns
    foreach (range('A', $sheet->getHighestColumn()) as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    // Output the Excel file
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="students_template.xlsx"');
    header('Cache-Control: max-age=0');
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
    break;

  case 'import_students_excel':
    if (!hasPermission($conn, $user_id, $role_id, 'manage_students', $school_id)) {
        echo json_encode(['status' => 'error', 'message' => 'Permission denied: manage_students']);
        exit;
    }

    if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'No file uploaded or upload error']);
        exit;
    }

    $file = $_FILES['excel_file'];
    $allowed_types = ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel'];
    if (!in_array($file['type'], $allowed_types)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid file type. Only .xlsx or .xls allowed']);
        exit;
    }

    try {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file['tmp_name']);
        $sheet = $spreadsheet->getActiveSheet();
        $data = $sheet->toArray(null, true, true, true);
        $headers = array_shift($data); // Remove and get the first row as headers

        $required_fields = ['admission_no', 'full_name', 'gender', 'class_name', 'stream_name'];
        foreach ($required_fields as $field) {
            if (!in_array($field, $headers)) {
                echo json_encode(['status' => 'error', 'message' => "Missing required column: $field"]);
                exit;
            }
        }

        $success_count = 0;
        $errors = [];

        foreach ($data as $row_num => $row) {
            // Skip empty rows
            if (empty(array_filter($row))) continue;

            $admission_no = sanitize($conn, $row[array_search('admission_no', $headers)] ?? '');
            $full_name = sanitize($conn, $row[array_search('full_name', $headers)] ?? '');
            $gender = sanitize($conn, $row[array_search('gender', $headers)] ?? '');
            $class_name = sanitize($conn, $row[array_search('class_name', $headers)] ?? '');
            $stream_name = sanitize($conn, $row[array_search('stream_name', $headers)] ?? '');
            $dob = sanitize($conn, $row[array_search('dob', $headers)] ?? '');
            $primary_phone = sanitize($conn, $row[array_search('primary_phone', $headers)] ?? '');
            $secondary_phone = sanitize($conn, $row[array_search('secondary_phone', $headers)] ?? '');
            $date_of_admission = sanitize($conn, $row[array_search('date_of_admission', $headers)] ?? '');
            $upi = sanitize($conn, $row[array_search('upi', $headers)] ?? '');
            $kcpe_index = sanitize($conn, $row[array_search('kcpe_index', $headers)] ?? '');
            $kcpe_score = !empty($row[array_search('kcpe_score', $headers)] ?? '') ? (int)$row[array_search('kcpe_score', $headers)] : null;
            $kcpe_grade = sanitize($conn, $row[array_search('kcpe_grade', $headers)] ?? '');
            $kcpe_year = !empty($row[array_search('kcpe_year', $headers)] ?? '') ? (int)$row[array_search('kcpe_year', $headers)] : null;
            $index_number = sanitize($conn, $row[array_search('index_number', $headers)] ?? '');
            $previous_school = sanitize($conn, $row[array_search('previous_school', $headers)] ?? '');
            $primary_school = sanitize($conn, $row[array_search('primary_school', $headers)] ?? '');
            $guardian_name = sanitize($conn, $row[array_search('guardian_name', $headers)] ?? '');
            $guardian_relation = sanitize($conn, $row[array_search('guardian_relation', $headers)] ?? '');
            $primary_phone_2 = sanitize($conn, $row[array_search('primary_phone_2', $headers)] ?? '');
            $secondary_phone_2 = sanitize($conn, $row[array_search('secondary_phone_2', $headers)] ?? '');
            $birth_cert_number = sanitize($conn, $row[array_search('birth_cert_number', $headers)] ?? '');
            $nationality = sanitize($conn, $row[array_search('nationality', $headers)] ?? '');
            $place_of_birth = sanitize($conn, $row[array_search('place_of_birth', $headers)] ?? '');
            $nhif = sanitize($conn, $row[array_search('nhif', $headers)] ?? '');
            $general_comments = sanitize($conn, $row[array_search('general_comments', $headers)] ?? '');
            $entry_position = sanitize($conn, $row[array_search('entry_position', $headers)] ?? '');

            // Validate required fields
            if (empty($admission_no) || empty($full_name) || empty($gender) || empty($class_name) || empty($stream_name)) {
                $errors[] = "Row $row_num: Missing required fields";
                continue;
            }

            if (!in_array($gender, ['Male', 'Female'])) {
                $errors[] = "Row $row_num: Invalid gender ($gender)";
                continue;
            }

            if ($kcpe_score !== null && ($kcpe_score < 0 || $kcpe_score > 500)) {
                $errors[] = "Row $row_num: KCPE score must be between 0 and 500 ($kcpe_score)";
                continue;
            }

            if ($kcpe_year !== null && ($kcpe_year < 1900 || $kcpe_year > date('Y'))) {
                $errors[] = "Row $row_num: KCPE year must be a valid year ($kcpe_year)";
                continue;
            }

            // Normalize class_name (accept both "1" and "Form 1")
            $normalized_class_name = preg_replace('/^Form\s*/i', '', $class_name); // Remove "Form" prefix (case-insensitive)

            // Verify class (check both numeric and Form-prefixed names)
            $stmt = $conn->prepare("
                SELECT class_id 
                FROM classes 
                WHERE (form_name = ? OR form_name = CONCAT('Form ', ?)) AND school_id = ?
            ");
            $stmt->bind_param("ssi", $class_name, $normalized_class_name, $school_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows === 0) {
                $errors[] = "Row $row_num: Invalid class ($class_name)";
                continue;
            }
            $class = $result->fetch_assoc();
            $class_id = $class['class_id'];
            $stmt->close();

            // Verify stream
            $stmt = $conn->prepare("SELECT stream_id FROM streams WHERE stream_name = ? AND school_id = ? AND class_id = ?");
            $stmt->bind_param("sii", $stream_name, $school_id, $class_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows === 0) {
                $errors[] = "Row $row_num: Invalid stream ($stream_name) for class ($class_name)";
                continue;
            }
            $stream = $result->fetch_assoc();
            $stream_id = $stream['stream_id'];
            $stmt->close();

            // Check for duplicate admission number
            $stmt = $conn->prepare("SELECT student_id FROM students WHERE admission_no = ? AND school_id = ?");
            $stmt->bind_param("si", $admission_no, $school_id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $errors[] = "Row $row_num: Admission number already exists ($admission_no)";
                continue;
            }
            $stmt->close();

            // Insert student
            $stmt = $conn->prepare("
                INSERT INTO students (
                    school_id, class_id, stream_id, admission_no, full_name, gender,
                    date_of_admission, upi, kcpe_index, kcpe_score, kcpe_grade, kcpe_year,
                    index_number, dob, nationality, place_of_birth, previous_school, primary_phone,
                    primary_phone_2, secondary_phone, secondary_phone_2, guardian_name, guardian_relation,
                    primary_school, nhif, general_comments, entry_position
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                "iiissssssisississssssssssss",
                $school_id, $class_id, $stream_id, $admission_no, $full_name, $gender,
                $date_of_admission, $upi, $kcpe_index, $kcpe_score, $kcpe_grade, $kcpe_year,
                $index_number, $dob, $nationality, $place_of_birth, $previous_school, $primary_phone,
                $primary_phone_2, $secondary_phone, $secondary_phone_2, $guardian_name, $guardian_relation,
                $primary_school, $nhif, $general_comments, $entry_position
            );
            if ($stmt->execute()) {
                $success_count++;
            } else {
                $errors[] = "Row $row_num: Failed to insert student: " . $stmt->error;
            }
            $stmt->close();
        }

        if ($success_count > 0 && empty($errors)) {
            echo json_encode(['status' => 'success', 'message' => "Successfully imported $success_count students"]);
        } elseif ($success_count > 0) {
            echo json_encode(['status' => 'warning', 'message' => "Imported $success_count students with errors: " . implode('; ', $errors)]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'No students imported: ' . implode('; ', $errors)]);
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to process Excel file: ' . $e->getMessage()]);
    }
    break;
    case 'get_subjects_by_class':
        if (!hasPermission($conn, $user_id, $role_id, 'view_subjects', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied: view_subjects']);
            exit;
        }
        $class_id = (int)$_POST['class_id'];
        $stmt = $conn->prepare("
        SELECT s.subject_id, s.name
        FROM class_subjects cs
        JOIN subjects s ON cs.subject_id = s.subject_id
        WHERE cs.class_id = ? AND s.school_id = ?
    ");
        $stmt->bind_param("ii", $class_id, $school_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $subjects = $result->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['status' => 'success', 'subjects' => $subjects]);
        $stmt->close();
        break;

    case 'get_students_by_class':
        if (!hasPermission($conn, $user_id, $role_id, 'view_students', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied: view_students']);
            exit;
        }
        $class_id = (int)$_POST['class_id'];
        $stmt = $conn->prepare("
        SELECT student_id, full_name, admission_no
        FROM students
        WHERE class_id = ? AND school_id = ? AND deleted_at IS NULL
    ");
        $stmt->bind_param("ii", $class_id, $school_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $students = $result->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['status' => 'success', 'students' => $students]);
        $stmt->close();
        break;

    case 'get_custom_groups':
        if (!hasPermission($conn, $user_id, $role_id, 'manage_students', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied: manage_students']);
            exit;
        }
        $stmt = $conn->prepare("
        SELECT cg.group_id, cg.name, cg.description, c.class_id, c.form_name,
               GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ', ') AS subjects,
               GROUP_CONCAT(DISTINCT cgs.subject_id ORDER BY cgs.subject_id SEPARATOR ',') AS subject_ids,
               GROUP_CONCAT(DISTINCT st.full_name ORDER BY st.full_name SEPARATOR ', ') AS students,
               GROUP_CONCAT(DISTINCT cgst.student_id ORDER BY cgst.student_id SEPARATOR ',') AS student_ids
        FROM custom_groups cg
        JOIN classes c ON cg.class_id = c.class_id
        LEFT JOIN custom_group_subjects cgs ON cg.group_id = cgs.group_id
        LEFT JOIN subjects s ON cgs.subject_id = s.subject_id
        LEFT JOIN custom_group_students cgst ON cg.group_id = cgst.group_id
        LEFT JOIN students st ON cgst.student_id = st.student_id
        WHERE cg.school_id = ?
        GROUP BY cg.group_id
    ");
        $stmt->bind_param("i", $school_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $groups = $result->fetch_all(MYSQLI_ASSOC);
        foreach ($groups as &$group) {
            $group['subjects'] = explode(', ', $group['subjects'] ?? '');
            $group['subject_ids'] = explode(',', $group['subject_ids'] ?? '');
            $group['students'] = explode(', ', $group['students'] ?? '');
            $group['student_ids'] = explode(',', $group['student_ids'] ?? '');
        }
        echo json_encode(['status' => 'success', 'groups' => $groups]);
        $stmt->close();
        break;

    case 'add_custom_group':
        if (!hasPermission($conn, $user_id, $role_id, 'manage_students', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied: manage_students']);
            exit;
        }
        $name = sanitize($conn, $_POST['name']);
        $class_id = (int)$_POST['class_id'];
        $description = sanitize($conn, $_POST['description']);
        $subject_ids = $_POST['subject_ids'] ?? [];
        $student_ids = $_POST['student_ids'] ?? [];
        if (empty($name) || empty($class_id) || empty($subject_ids) || empty($student_ids)) {
            echo json_encode(['status' => 'error', 'message' => 'Required fields missing']);
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
        // Insert group
        $stmt = $conn->prepare("INSERT INTO custom_groups (school_id, name, class_id, description) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isis", $school_id, $name, $class_id, $description);
        if (!$stmt->execute()) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to create group: ' . $conn->error]);
            exit;
        }
        $group_id = $stmt->insert_id;
        $stmt->close();
        // Insert subjects
        foreach ($subject_ids as $subject_id) {
            $stmt = $conn->prepare("INSERT INTO custom_group_subjects (group_id, subject_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $group_id, $subject_id);
            $stmt->execute();
            $stmt->close();
        }
        // Insert students
        foreach ($student_ids as $student_id) {
            $stmt = $conn->prepare("INSERT INTO custom_group_students (group_id, student_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $group_id, $student_id);
            $stmt->execute();
            $stmt->close();
        }
        echo json_encode(['status' => 'success', 'message' => 'Group created successfully']);
        break;

    case 'edit_custom_group':
        if (!hasPermission($conn, $user_id, $role_id, 'manage_students', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied: manage_students']);
            exit;
        }
        $group_id = (int)$_POST['group_id'];
        $name = sanitize($conn, $_POST['name']);
        $class_id = (int)$_POST['class_id'];
        $description = sanitize($conn, $_POST['description']);
        $subject_ids = $_POST['subject_ids'] ?? [];
        $student_ids = $_POST['student_ids'] ?? [];
        if (empty($group_id) || empty($name) || empty($class_id) || empty($subject_ids) || empty($student_ids)) {
            echo json_encode(['status' => 'error', 'message' => 'Required fields missing']);
            exit;
        }
        // Verify group
        $stmt = $conn->prepare("SELECT group_id FROM custom_groups WHERE group_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $group_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid group']);
            exit;
        }
        $stmt->close();
        // Update group
        $stmt = $conn->prepare("UPDATE custom_groups SET name = ?, class_id = ?, description = ? WHERE group_id = ?");
        $stmt->bind_param("sisi", $name, $class_id, $description, $group_id);
        $stmt->execute();
        $stmt->close();
        // Clear existing subjects and students
        $stmt = $conn->prepare("DELETE FROM custom_group_subjects WHERE group_id = ?");
        $stmt->bind_param("i", $group_id);
        $stmt->execute();
        $stmt->close();
        $stmt = $conn->prepare("DELETE FROM custom_group_students WHERE group_id = ?");
        $stmt->bind_param("i", $group_id);
        $stmt->execute();
        $stmt->close();
        // Insert new subjects
        foreach ($subject_ids as $subject_id) {
            $stmt = $conn->prepare("INSERT INTO custom_group_subjects (group_id, subject_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $group_id, $subject_id);
            $stmt->execute();
            $stmt->close();
        }
        // Insert new students
        foreach ($student_ids as $student_id) {
            $stmt = $conn->prepare("INSERT INTO custom_group_students (group_id, student_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $group_id, $student_id);
            $stmt->execute();
            $stmt->close();
        }
        echo json_encode(['status' => 'success', 'message' => 'Group updated successfully']);
        break;

    case 'delete_custom_group':
        if (!hasPermission($conn, $user_id, $role_id, 'manage_students', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied: manage_students']);
            exit;
        }
        $group_id = (int)$_POST['group_id'];
        $stmt = $conn->prepare("DELETE FROM custom_groups WHERE group_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $group_id, $school_id);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Group deleted successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to delete group: ' . $conn->error]);
        }
        $stmt->close();
        break;
    case 'get_students_in_group':
        if (!hasPermission($conn, $user_id, $role_id, 'view_students', $school_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Permission denied']);
            exit;
        }

        $group_id = (int)$_POST['group_id'];

        $stmt = $conn->prepare("
        SELECT 
            s.student_id, 
            s.full_name, 
            s.admission_no
        FROM custom_group_students cgs
        JOIN students s ON cgs.student_id = s.student_id
        WHERE cgs.group_id = ? 
          AND s.school_id = ?
          AND s.deleted_at IS NULL
        ORDER BY s.full_name ASC
    ");

        $stmt->bind_param("ii", $group_id, $school_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $students = [];
        while ($row = $result->fetch_assoc()) {
            $students[] = $row;
        }

        echo json_encode([
            'status'   => 'success',
            'students' => $students
        ]);

        $stmt->close();
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
        break;
}


?>