<?php
// dormitories/functions.php
session_start();
require __DIR__ . '/../../connection/db.php';
require __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

if (!isset($_SESSION['user_id']) || !isset($_SESSION['school_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$school_id = $_SESSION['school_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

header('Content-Type: application/json');

switch ($action) {
    case 'add_dormitory':
        $name        = trim($_POST['name'] ?? '');
        $short_code  = trim($_POST['short_code'] ?? '');
        $gender      = $_POST['gender'] ?? 'Mixed';
        $capacity    = (int)($_POST['capacity'] ?? 0);
        $description = trim($_POST['description'] ?? '');

        if (empty($name)) {
            echo json_encode(['status' => 'error', 'message' => 'Dormitory name required']);
            exit;
        }

        $stmt = $conn->prepare("SELECT dormitory_id FROM dormitories WHERE school_id = ? AND name = ?");
        $stmt->bind_param("is", $school_id, $name);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Name already exists']);
            exit;
        }
        $stmt->close();

        $stmt = $conn->prepare("
            INSERT INTO dormitories (school_id, name, short_code, gender, capacity, description) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("isssis", $school_id, $name, $short_code, $gender, $capacity, $description);
        $success = $stmt->execute();
        echo json_encode([
            'status'  => $success ? 'success' : 'error',
            'message' => $success ? 'Dormitory created' : 'Failed: ' . $conn->error
        ]);
        $stmt->close();
        break;

    case 'edit_dormitory':
        $dorm_id     = (int)($_POST['dormitory_id'] ?? 0);
        $name        = trim($_POST['name'] ?? '');
        $short_code  = trim($_POST['short_code'] ?? '');
        $gender      = $_POST['gender'] ?? 'Mixed';
        $capacity    = (int)($_POST['capacity'] ?? 0);
        $description = trim($_POST['description'] ?? '');

        if ($dorm_id <= 0 || empty($name)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid data']);
            exit;
        }

        $stmt = $conn->prepare("
            UPDATE dormitories 
            SET name = ?, short_code = ?, gender = ?, capacity = ?, description = ? 
            WHERE dormitory_id = ? AND school_id = ?
        ");
        $stmt->bind_param("sssissi", $name, $short_code, $gender, $capacity, $description, $dorm_id, $school_id);
        $success = $stmt->execute();
        echo json_encode([
            'status'  => $success ? 'success' : 'error',
            'message' => $success ? 'Dormitory updated' : 'Failed: ' . $conn->error
        ]);
        $stmt->close();
        break;

    case 'delete_dormitory':
        $dorm_id = (int)($_POST['dormitory_id'] ?? 0);
        if ($dorm_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid dormitory']);
            exit;
        }

        $stmt = $conn->prepare("DELETE FROM student_dormitory_assignments WHERE dormitory_id = ?");
        $stmt->bind_param("i", $dorm_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM dormitories WHERE dormitory_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $dorm_id, $school_id);
        $success = $stmt->execute();

        echo json_encode([
            'status'  => $success ? 'success' : 'error',
            'message' => $success ? 'Dormitory and assignments deleted' : 'Delete failed: ' . $conn->error
        ]);
        $stmt->close();
        break;

    case 'assign_students_to_dorm':
        $dorm_id     = (int)($_POST['dormitory_id'] ?? 0);
        $student_ids = $_POST['student_ids'] ?? [];

        if ($dorm_id <= 0 || empty($student_ids)) {
            echo json_encode(['status' => 'error', 'message' => 'Missing dormitory or students']);
            exit;
        }

        $stmt = $conn->prepare("SELECT dormitory_id FROM dormitories WHERE dormitory_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $dorm_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid dormitory']);
            exit;
        }
        $stmt->close();

        $success_count = 0;
        foreach ($student_ids as $sid) {
            $sid = (int)$sid;

            // Reset any existing current assignment
            $conn->query("UPDATE student_dormitory_assignments SET is_current = 0 
                          WHERE student_id = $sid AND is_current = 1");

            $stmt = $conn->prepare("
                INSERT INTO student_dormitory_assignments 
                (school_id, student_id, dormitory_id, academic_year, assigned_at, is_current) 
                VALUES (?, ?, ?, YEAR(CURDATE()), CURDATE(), 1)
            ");
            $stmt->bind_param("iii", $school_id, $sid, $dorm_id);
            if ($stmt->execute()) $success_count++;
            $stmt->close();
        }

        echo json_encode([
            'status'  => 'success',
            'message' => "Assigned $success_count student(s)"
        ]);
        break;

    case 'remove_student_from_dorm':
        $student_id = (int)($_POST['student_id'] ?? 0);
        $dorm_id    = (int)($_POST['dormitory_id'] ?? 0);

        if ($student_id <= 0 || $dorm_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid data']);
            exit;
        }

        $stmt = $conn->prepare("
            UPDATE student_dormitory_assignments 
            SET is_current = 0 
            WHERE student_id = ? AND dormitory_id = ? AND is_current = 1
              AND EXISTS (SELECT 1 FROM students WHERE student_id = ? AND school_id = ?)
        ");
        $stmt->bind_param("iiii", $student_id, $dorm_id, $student_id, $school_id);
        $success = $stmt->execute();

        echo json_encode([
            'status'  => $success ? 'success' : 'error',
            'message' => $success ? 'Student removed' : 'Failed or not assigned: ' . $conn->error
        ]);
        $stmt->close();
        break;

    case 'get_unassigned_students':
        $current_year = date('Y');
        $stmt = $conn->prepare("
            SELECT 
                s.student_id, s.full_name, s.admission_no
            FROM students s
            LEFT JOIN student_dormitory_assignments sda 
                ON s.student_id = sda.student_id 
               AND sda.is_current = 1 
               AND sda.academic_year = ?
            WHERE s.school_id = ? 
              AND sda.student_id IS NULL 
              AND s.deleted_at IS NULL
            ORDER BY s.admission_no ASC
            LIMIT 200
        ");
        $stmt->bind_param("ii", $current_year, $school_id);
        $stmt->execute();
        $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['status' => 'success', 'students' => $students]);
        $stmt->close();
        break;

    case 'get_students_in_dorm':
        $dorm_id = (int)($_POST['dormitory_id'] ?? 0);
        $current_year = date('Y');

        $stmt = $conn->prepare("
            SELECT 
                s.student_id, s.full_name, s.admission_no
            FROM student_dormitory_assignments sda
            JOIN students s ON sda.student_id = s.student_id
            WHERE sda.dormitory_id = ? 
              AND sda.is_current = 1 
              AND sda.academic_year = ?
              AND s.school_id = ?
            ORDER BY s.full_name ASC
        ");
        $stmt->bind_param("iii", $dorm_id, $current_year, $school_id);
        $stmt->execute();
        $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['status' => 'success', 'students' => $students]);
        $stmt->close();
        break;

    case 'preview_excel_students':
        // ────────────────────────────────────────────────
        // Check upload success first
        // ────────────────────────────────────────────────
        if (empty($_FILES['excel_file']['tmp_name']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
            $errCode = $_FILES['excel_file']['error'] ?? 'unknown';
            $errMsg = [
                UPLOAD_ERR_INI_SIZE   => "File too large (exceeds php.ini upload_max_filesize)",
                UPLOAD_ERR_FORM_SIZE  => "File exceeds form MAX_FILE_SIZE limit",
                UPLOAD_ERR_PARTIAL    => "File only partially uploaded",
                UPLOAD_ERR_NO_FILE    => "No file was uploaded",
                UPLOAD_ERR_NO_TMP_DIR => "Missing temporary upload folder on server",
                UPLOAD_ERR_CANT_WRITE => "Failed to write file to disk",
                UPLOAD_ERR_EXTENSION  => "A PHP extension stopped the file upload"
            ][$errCode] ?? "Upload error (code: $errCode)";

            echo json_encode(['status' => 'error', 'message' => $errMsg]);
            error_log("Excel preview upload failed: $errMsg | File: " . ($_FILES['excel_file']['name'] ?? 'none'));
            exit;
        }

        $file = $_FILES['excel_file'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, ['xlsx', 'xls'])) {
            echo json_encode(['status' => 'error', 'message' => "Invalid file type: .$ext (only .xlsx or .xls allowed)"]);
            exit;
        }

        // Log attempt for debugging
        error_log("Excel preview started - File: {$file['name']} | Size: {$file['size']} bytes | Tmp: {$file['tmp_name']}");

        try {
            $spreadsheet = IOFactory::load($file['tmp_name']);
            $worksheet   = $spreadsheet->getActiveSheet();
            $rows        = $worksheet->toArray(null, true, true, true); // keep column letters

            $students    = [];
            $headerRow   = true;
            $nameCol     = null;
            $admCol      = null;

            foreach ($rows as $rowNum => $row) {
                if ($headerRow) {
                    // Detect columns case-insensitively
                    foreach ($row as $colLetter => $cell) {
                        $val = trim(strtolower($cell ?? ''));
                        if (strpos($val, 'name') !== false) {
                            $nameCol = $colLetter;
                        }
                        if (strpos($val, 'admission') !== false || strpos($val, 'adm') !== false || strpos($val, 'upi') !== false) {
                            $admCol = $colLetter;
                        }
                    }
                    $headerRow = false;
                    continue;
                }

                // Skip rows without admission number
                $adm = trim($row[$admCol ?? 'B'] ?? '');
                if (empty($adm)) continue;

                $name = trim($row[$nameCol ?? 'A'] ?? '');

                // Check if student exists in DB
                $stmt = $conn->prepare("
                    SELECT student_id, full_name 
                    FROM students 
                    WHERE school_id = ? AND admission_no = ?
                ");
                $stmt->bind_param("is", $school_id, $adm);
                $stmt->execute();
                $result = $stmt->get_result();
                $exists = $result->num_rows > 0;
                $studentData = $exists ? $result->fetch_assoc() : null;
                $stmt->close();

                $students[] = [
                    'name'         => $name ?: ($exists ? $studentData['full_name'] : 'Unknown'),
                    'admission_no' => $adm,
                    'exists'       => $exists,
                    'student_id'   => $exists ? $studentData['student_id'] : null
                ];
            }

            if (empty($students)) {
                echo json_encode([
                    'status'  => 'error',
                    'message' => 'No valid rows found in the Excel file (missing or empty admission_no column)'
                ]);
                exit;
            }

            echo json_encode([
                'status'   => 'success',
                'students' => $students
            ]);
        } catch (\Exception $e) {
            $realError = $e->getMessage();
            error_log("PhpSpreadsheet preview error: $realError | File: {$file['name']}");

            $userMsg = str_contains($realError, 'zip') || str_contains($realError, 'signature')
                ? "The file is not a valid Excel (.xlsx) file. Please open it in Microsoft Excel or Google Sheets, then Save As → Excel Workbook (.xlsx)."
                : "Excel file could not be read: $realError";

            echo json_encode([
                'status'  => 'error',
                'message' => $userMsg
            ]);
        }
        break;

    case 'download_dorm_template':
        try {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            $sheet->setCellValue('A1', 'name');
            $sheet->setCellValue('B1', 'admission_no');

            $sheet->getStyle('A1:B1')->getFont()->setBold(true);

            $sampleData = [
                ['Jane Akinyi', 'ADM/2025/001'],
                ['Kevin Otieno', 'JSK/045/24'],
                ['Fatuma Ali', 'KCA/112/23'],
                ['Brian Kipchoge', 'BRK/089/25'],
            ];

            $row = 2;
            foreach ($sampleData as $data) {
                $sheet->setCellValue("A$row", $data[0]);
                $sheet->setCellValue("B$row", $data[1]);
                $row++;
            }

            foreach (range('A', 'B') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }

            $filename = "Dormitory_Assignment_Template_" . date('Y-m-d') . ".xlsx";

            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment;filename="' . $filename . '"');
            header('Cache-Control: max-age=0');

            $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->save('php://output');
            exit;
        } catch (Exception $e) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Template error: ' . $e->getMessage()]);
            exit;
        }
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
}
