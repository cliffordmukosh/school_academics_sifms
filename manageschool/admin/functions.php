<?php
// academics/admin/functions.php
session_start();
require __DIR__ . '/../../connection/db.php';
require __DIR__ . '/../../vendor/autoload.php'; // For PhpSpreadsheet

use PhpOffice\PhpSpreadsheet\IOFactory;

// Ensure user is logged in, has 'Admin' role, and belongs to the school
if (!isset($_SESSION['user_id']) || $_SESSION['role_name'] !== 'Admin' || !isset($_SESSION['school_id'])) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access']);
    exit;
}

// Helper function to sanitize input
function sanitize($conn, $input)
{
    if ($input === '') return null; // Handle empty strings as NULL for nullable fields
    return trim($conn->real_escape_string($input)); // Only trim and escape for SQL safety
}

// Check for action in both POST and GET
$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');
$school_id = $_SESSION['school_id'];

header('Content-Type: application/json');

switch ($action) {
    case 'add_role':
        $role_name = sanitize($conn, $_POST['role_name']);
        if (empty($role_name)) {
            echo json_encode(['status' => 'error', 'message' => 'Role name is required']);
            exit;
        }
        // Check for duplicate role name in the school
        $stmt = $conn->prepare("SELECT role_id FROM roles WHERE school_id = ? AND role_name = ?");
        $stmt->bind_param("is", $school_id, $role_name);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Role name already exists']);
            exit;
        }
        $stmt->close();

        $stmt = $conn->prepare("INSERT INTO roles (school_id, role_name) VALUES (?, ?)");
        $stmt->bind_param("is", $school_id, $role_name);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Role added successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to add role: ' . $conn->error]);
        }
        $stmt->close();
        break;

    case 'assign_permissions_to_role':
        $role_id = (int)$_POST['role_id'];
        $permission_ids = isset($_POST['permission_ids']) && is_array($_POST['permission_ids']) ? $_POST['permission_ids'] : [];

        // Verify role belongs to the school
        $stmt = $conn->prepare("SELECT role_id FROM roles WHERE role_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $role_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid role for this school']);
            exit;
        }
        $stmt->close();

        if (empty($permission_ids)) {
            echo json_encode(['status' => 'error', 'message' => 'No permissions selected']);
            exit;
        }

        $success = true;
        $conn->begin_transaction();
        try {
            // Remove existing permissions for the role
            $stmt = $conn->prepare("DELETE FROM role_permissions WHERE role_id = ? AND school_id = ?");
            $stmt->bind_param("ii", $role_id, $school_id);
            $stmt->execute();
            $stmt->close();

            // Assign selected permissions
            $stmt = $conn->prepare("INSERT INTO role_permissions (role_id, permission_id, school_id) VALUES (?, ?, ?)");
            foreach ($permission_ids as $permission_id) {
                $permission_id = (int)$permission_id;
                // Verify permission exists
                $check_stmt = $conn->prepare("SELECT permission_id FROM permissions WHERE permission_id = ?");
                $check_stmt->bind_param("i", $permission_id);
                $check_stmt->execute();
                if ($check_stmt->get_result()->num_rows === 0) {
                    throw new Exception("Invalid permission ID: $permission_id");
                }
                $check_stmt->close();

                $stmt->bind_param("iii", $role_id, $permission_id, $school_id);
                if (!$stmt->execute()) {
                    throw new Exception("Failed to assign permission ID: $permission_id");
                }
            }
            $stmt->close();
            $conn->commit();
            echo json_encode(['status' => 'success', 'message' => 'Permissions assigned successfully']);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['status' => 'error', 'message' => 'Failed to assign permissions: ' . $e->getMessage()]);
        }
        break;

    case 'assign_roles_to_user':
        $user_id = (int)$_POST['user_id'];
        $role_id = (int)$_POST['role_id'];

        // Verify user belongs to the school
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE user_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $user_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid user for this school']);
            exit;
        }
        $stmt->close();

        // Verify role belongs to the school
        $stmt = $conn->prepare("SELECT role_id FROM roles WHERE role_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $role_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid role for this school']);
            exit;
        }
        $stmt->close();

        $stmt = $conn->prepare("UPDATE users SET role_id = ? WHERE user_id = ? AND school_id = ?");
        $stmt->bind_param("iii", $role_id, $user_id, $school_id);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Role assigned successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to assign role: ' . $conn->error]);
        }
        $stmt->close();
        break;

    case 'add_user':
        $first_name = sanitize($conn, $_POST['first_name']);
        $other_names = sanitize($conn, $_POST['other_names'] ?? '');
        $username = sanitize($conn, $_POST['username']);
        $email = $_POST['email'];
        $personal_email = sanitize($conn, $_POST['personal_email'] ?? '');
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];
        $role_id = (int)$_POST['role_id'];

        // Validate password match
        if ($password !== $confirm_password) {
            echo json_encode(['status' => 'error', 'message' => 'Passwords do not match']);
            exit;
        }

        // Validate email format
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid system email format']);
            exit;
        }
        if ($personal_email && !filter_var($personal_email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid personal email format']);
            exit;
        }

        // Verify role belongs to the school
        $stmt = $conn->prepare("SELECT role_id FROM roles WHERE role_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $role_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid role for this school']);
            exit;
        }
        $stmt->close();

        // Format username with @schoolname
        $stmt = $conn->prepare("SELECT name FROM schools WHERE school_id = ?");
        $stmt->bind_param("i", $school_id);
        $stmt->execute();
        $school = $stmt->get_result()->fetch_assoc();
        $formatted_username = $username . '@' . preg_replace('/[^A-Za-z0-9]/', '', strtolower($school['name']));
        $stmt->close();

        // Check for duplicate username or email
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE (username = ? OR email = ?) AND school_id = ?");
        $stmt->bind_param("ssi", $formatted_username, $email, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Username or system email already exists']);
            exit;
        }
        $stmt->close();

        // Insert user
        $password_hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $conn->prepare("
            INSERT INTO users (school_id, role_id, first_name, other_names, username, email, personal_email, password_hash, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')
        ");
        $stmt->bind_param("iissssss", $school_id, $role_id, $first_name, $other_names, $formatted_username, $email, $personal_email, $password_hash);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'User added successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to add user: ' . $conn->error]);
        }
        $stmt->close();
        break;

    case 'edit_user':
        $user_id = (int)$_POST['user_id'];
        $first_name = sanitize($conn, $_POST['first_name']);
        $other_names = sanitize($conn, $_POST['other_names'] ?? '');
        $username = sanitize($conn, $_POST['username']);
        $email = $_POST['email'];
        $personal_email = sanitize($conn, $_POST['personal_email'] ?? '');
        $role_id = (int)$_POST['role_id'];

        // Validate email format
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid system email format']);
            exit;
        }
        if ($personal_email && !filter_var($personal_email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid personal email format']);
            exit;
        }

        // Verify user belongs to the school
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE user_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $user_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid user for this school']);
            exit;
        }
        $stmt->close();

        // Verify role belongs to the school
        $stmt = $conn->prepare("SELECT role_id FROM roles WHERE role_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $role_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid role for this school']);
            exit;
        }
        $stmt->close();

        // Format username with @schoolname
        $stmt = $conn->prepare("SELECT name FROM schools WHERE school_id = ?");
        $stmt->bind_param("i", $school_id);
        $stmt->execute();
        $school = $stmt->get_result()->fetch_assoc();
        $formatted_username = $username . '@' . preg_replace('/[^A-Za-z0-9]/', '', strtolower($school['name']));
        $stmt->close();

        // Check for duplicate username or email (excluding current user)
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE (username = ? OR email = ?) AND school_id = ? AND user_id != ?");
        $stmt->bind_param("ssii", $formatted_username, $email, $school_id, $user_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Username or system email already exists']);
            exit;
        }
        $stmt->close();

        // Update user
        $stmt = $conn->prepare("
            UPDATE users 
            SET first_name = ?, other_names = ?, username = ?, email = ?, personal_email = ?, role_id = ? 
            WHERE user_id = ? AND school_id = ?
        ");
        $stmt->bind_param("sssssiii", $first_name, $other_names, $formatted_username, $email, $personal_email, $role_id, $user_id, $school_id);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'User updated successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to update user: ' . $conn->error]);
        }
        $stmt->close();
        break;

    case 'delete_user':
        $user_id = (int)$_POST['user_id'];

        // Prevent admin from deleting themselves
        if ($user_id == $_SESSION['user_id']) {
            echo json_encode(['status' => 'error', 'message' => 'You cannot delete your own account']);
            exit;
        }

        // Verify user belongs to the school
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE user_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $user_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid user for this school']);
            exit;
        }
        $stmt->close();

        // Soft delete user
        $stmt = $conn->prepare("UPDATE users SET status = 'inactive', deleted_at = CURRENT_TIMESTAMP WHERE user_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $user_id, $school_id);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'User deleted successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to delete user: ' . $conn->error]);
        }
        $stmt->close();
        break;

    case 'manage_settings':
        $term_name = sanitize($conn, $_POST['term_name']);
        $academic_year = (int)$_POST['academic_year'];
        $closing_date = sanitize($conn, $_POST['closing_date']);
        $next_opening_date = sanitize($conn, $_POST['next_opening_date']);
        $next_term_fees = isset($_POST['next_term_fees']) && $_POST['next_term_fees'] !== '' ? (float)$_POST['next_term_fees'] : null;
        $principal_name = sanitize($conn, $_POST['principal_name']);

        if (empty($term_name) || empty($academic_year)) {
            echo json_encode(['status' => 'error', 'message' => 'Term name and academic year are required']);
            exit;
        }

        // Validate term_name
        if (!in_array($term_name, ['Term 1', 'Term 2', 'Term 3'])) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid term name. Must be Term 1, Term 2, or Term 3']);
            exit;
        }

        // Check term count for the academic year
        $stmt = $conn->prepare("SELECT COUNT(*) as term_count FROM school_settings WHERE school_id = ? AND academic_year = ?");
        $stmt->bind_param("ii", $school_id, $academic_year);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $term_count = $result['term_count'];
        $stmt->close();

        if ($term_count >= 3) {
            $stmt = $conn->prepare("SELECT term_name FROM school_settings WHERE school_id = ? AND academic_year = ? AND term_name = ?");
            $stmt->bind_param("iis", $school_id, $academic_year, $term_name);
            $stmt->execute();
            if ($stmt->get_result()->num_rows === 0) {
                echo json_encode(['status' => 'error', 'message' => 'Cannot add more than 3 terms for the same academic year']);
                exit;
            }
            $stmt->close();
        }

        // Check for existing settings
        $stmt = $conn->prepare("SELECT setting_id FROM school_settings WHERE school_id = ? AND term_name = ? AND academic_year = ?");
        $stmt->bind_param("isi", $school_id, $term_name, $academic_year);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            // Update existing settings
            $stmt->close();
            $stmt = $conn->prepare("
                UPDATE school_settings 
                SET closing_date = ?, next_opening_date = ?, next_term_fees = ?, principal_name = ?
                WHERE school_id = ? AND term_name = ? AND academic_year = ?
            ");
            $stmt->bind_param("ssdssis", $closing_date, $next_opening_date, $next_term_fees, $principal_name, $school_id, $term_name, $academic_year);
        } else {
            // Insert new settings
            $stmt->close();
            $stmt = $conn->prepare("
                INSERT INTO school_settings (school_id, term_name, academic_year, closing_date, next_opening_date, next_term_fees, principal_name)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("isisssd", $school_id, $term_name, $academic_year, $closing_date, $next_opening_date, $next_term_fees, $principal_name);
        }

        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'School settings saved successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to save settings: ' . $conn->error]);
        }
        $stmt->close();
        break;

    case 'update_school_details':
        $name = sanitize($conn, $_POST['name']);
        $address = sanitize($conn, $_POST['address']);
        $email = $_POST['email'];
        $phone = sanitize($conn, $_POST['phone']);
        $logo = null;

        // Validate required fields
        if (empty($name) || empty($email)) {
            echo json_encode(['status' => 'error', 'message' => 'School name and email are required']);
            exit;
        }

        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid email format']);
            exit;
        }

        // Check for duplicate email
        $stmt = $conn->prepare("SELECT school_id FROM schools WHERE email = ? AND school_id != ?");
        $stmt->bind_param("si", $email, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Email already exists for another school']);
            exit;
        }
        $stmt->close();

        // Handle logo upload
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
            $file_name = $_FILES['logo']['name'];
            $file_extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            if (!in_array($file_extension, $allowed_extensions)) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid logo file format. Allowed formats: jpg, jpeg, png, gif']);
                exit;
            }

            $upload_dir = __DIR__ . '/../logos/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            $logo_filename = $school_id . '_' . time() . '.' . $file_extension;
            $logo_path = $upload_dir . $logo_filename;
            if (!move_uploaded_file($_FILES['logo']['tmp_name'], $logo_path)) {
                echo json_encode(['status' => 'error', 'message' => 'Failed to upload logo']);
                exit;
            }
            $logo = 'schoolacademics/manageschool/logos/' . $logo_filename;
        }

        // Update school details
        $stmt = $conn->prepare("
            UPDATE schools 
            SET name = ?, address = ?, email = ?, phone = ?, logo = COALESCE(?, logo)
            WHERE school_id = ?
        ");
        $stmt->bind_param("sssssi", $name, $address, $email, $phone, $logo, $school_id);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'School details updated successfully']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to update school details: ' . $conn->error]);
        }
        $stmt->close();
        break;

    case 'upload_fees':
        $setting_id = (int)$_POST['setting_id'];

        if (empty($setting_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Term and academic year are required']);
            exit;
        }

        if (!isset($_FILES['fees_file']) || $_FILES['fees_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['status' => 'error', 'message' => 'No file uploaded or upload error']);
            exit;
        }

        // Validate file type
        $file_name = $_FILES['fees_file']['name'];
        $allowed_extensions = ['xlsx', 'xls'];
        $file_extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        if (!in_array($file_extension, $allowed_extensions)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid file format. Please upload an Excel file (.xlsx or .xls)']);
            exit;
        }

        // Get setting_id
        $stmt = $conn->prepare("SELECT setting_id FROM school_settings WHERE setting_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $setting_id, $school_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid term or academic year. Please save settings first.']);
            exit;
        }
        $stmt->close();

        // Process Excel file
        try {
            $spreadsheet = IOFactory::load($_FILES['fees_file']['tmp_name']);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();
            $header = array_shift($rows); // Remove header row

            // Validate header
            if (!in_array('admission_no', array_map('strtolower', $header)) || !in_array('fees_balance', array_map('strtolower', $header))) {
                echo json_encode(['status' => 'error', 'message' => 'Excel file must contain admission_no and fees_balance columns']);
                exit;
            }

            $conn->begin_transaction();
            $stmt = $conn->prepare("
                INSERT INTO student_fees (school_id, student_id, admission_no, setting_id, fees_balance, uploaded_at)
                SELECT ?, s.student_id, ?, ?, ?, NOW()
                FROM students s
                WHERE s.admission_no = ? AND s.school_id = ? AND s.deleted_at IS NULL
                ON DUPLICATE KEY UPDATE fees_balance = ?, uploaded_at = NOW()
            ");

            $success_count = 0;
            $error_count = 0;
            foreach ($rows as $row) {
                $admission_no = trim($row[array_search('admission_no', array_map('strtolower', $header))]);
                $fees_balance = is_numeric($row[array_search('fees_balance', array_map('strtolower', $header))]) ? (float)$row[array_search('fees_balance', array_map('strtolower', $header))] : null;

                if (empty($admission_no) || $fees_balance === null) {
                    $error_count++;
                    continue;
                }

                $stmt->bind_param("isisdis", $school_id, $admission_no, $setting_id, $fees_balance, $admission_no, $school_id, $fees_balance);
                if ($stmt->execute()) {
                    $success_count++;
                } else {
                    $error_count++;
                }
            }
            $stmt->close();
            $conn->commit();

            echo json_encode([
                'status' => 'success',
                'message' => "Fees uploaded successfully: $success_count records processed, $error_count errors"
            ]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['status' => 'error', 'message' => 'Failed to process Excel file: ' . $e->getMessage()]);
        }
        break;

    case 'download_fees_template':
        // Skip permission check for Admin role
        if ($_SESSION['role_name'] !== 'Admin') {
            // Check permission for non-Admin users
            $stmt = $conn->prepare("
                SELECT p.name 
                FROM role_permissions rp 
                JOIN permissions p ON rp.permission_id = p.permission_id 
                JOIN users u ON u.role_id = rp.role_id 
                WHERE u.user_id = ? AND u.school_id = ? AND p.name = 'manage_fees'
            ");
            $stmt->bind_param("ii", $_SESSION['user_id'], $school_id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows === 0) {
                echo json_encode(['status' => 'error', 'message' => 'Insufficient permissions']);
                exit;
            }
            $stmt->close();
        }

        // Fetch all active students for the school
        $stmt = $conn->prepare("
            SELECT admission_no
            FROM students
            WHERE school_id = ? AND deleted_at IS NULL
            ORDER BY admission_no
        ");
        $stmt->bind_param("i", $school_id);
        $stmt->execute();
        $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (empty($students)) {
            echo json_encode(['status' => 'error', 'message' => 'No students found for this school']);
            exit;
        }

        try {
            // Create a new spreadsheet
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            // Set headers
            $sheet->setCellValue('A1', 'admission_no');
            $sheet->setCellValue('B1', 'fees_balance');

            // Populate student data
            $row = 2;
            foreach ($students as $student) {
                $sheet->setCellValue("A$row", $student['admission_no']);
                $sheet->setCellValue("B$row", ''); // Leave fees_balance blank
                $row++;
            }

            // Set column widths
            $sheet->getColumnDimension('A')->setAutoSize(true);
            $sheet->getColumnDimension('B')->setAutoSize(true);

            // Set headers for download
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment;filename="fees_template_' . $school_id . '.xlsx"');
            header('Cache-Control: max-age=0');

            // Write file to output
            $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->save('php://output');
            exit;
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to generate template: ' . $e->getMessage()]);
            exit;
        }
        break;
    case 'reset_user_password':
        $target_user_id = (int)$_POST['target_user_id'];
        $new_password   = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($target_user_id) || empty($new_password) || empty($confirm_password)) {
            echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
            exit;
        }

        if ($new_password !== $confirm_password) {
            echo json_encode(['status' => 'error', 'message' => 'Passwords do not match']);
            exit;
        }

        if (strlen($new_password) < 6) {
            echo json_encode(['status' => 'error', 'message' => 'Password must be at least 6 characters long']);
            exit;
        }

        // ────────────────────────────────────────────────
        // REMOVED: No longer block self-reset
        // if ($target_user_id === $_SESSION['user_id']) { ... }
        // ────────────────────────────────────────────────

        // Verify target user belongs to the same school (still good security)
        $stmt = $conn->prepare("
        SELECT user_id 
        FROM users 
        WHERE user_id = ? AND school_id = ? AND status = 'active'
    ");
        $stmt->bind_param("ii", $target_user_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid or inactive user']);
            exit;
        }
        $stmt->close();

        $password_hash = password_hash($new_password, PASSWORD_BCRYPT);

        $stmt = $conn->prepare("
        UPDATE users 
        SET password_hash = ? 
        WHERE user_id = ? AND school_id = ?
    ");
        $stmt->bind_param("sii", $password_hash, $target_user_id, $school_id);

        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                echo json_encode([
                    'status'  => 'success',
                    'message' => 'Password reset successfully'
                ]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'No changes made']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
        }
        $stmt->close();
        break;
    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
        break;
}
