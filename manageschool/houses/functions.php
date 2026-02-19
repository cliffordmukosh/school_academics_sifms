<?php
// houses/functions.php
session_start();
require __DIR__ . '/../../connection/db.php';
require __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

if (!isset($_SESSION['user_id']) || !isset($_SESSION['school_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$school_id = $_SESSION['school_id'];
// Support both GET and POST for action parameter
$action = $_POST['action'] ?? $_GET['action'] ?? '';

header('Content-Type: application/json');

switch ($action) {
    case 'add_house':
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if (empty($name)) {
            echo json_encode(['status' => 'error', 'message' => 'House name is required']);
            exit;
        }

        $stmt = $conn->prepare("SELECT house_id FROM houses WHERE school_id = ? AND name = ?");
        $stmt->bind_param("is", $school_id, $name);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'House name already exists']);
            exit;
        }
        $stmt->close();

        $stmt = $conn->prepare("INSERT INTO houses (school_id, name, description) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $school_id, $name, $description);
        $success = $stmt->execute();
        echo json_encode([
            'status'  => $success ? 'success' : 'error',
            'message' => $success ? 'House created' : 'Failed: ' . $conn->error
        ]);
        $stmt->close();
        break;

    case 'edit_house':
        $house_id = (int)($_POST['house_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($house_id <= 0 || empty($name)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid data']);
            exit;
        }

        $stmt = $conn->prepare("
            UPDATE houses 
            SET name = ?, description = ? 
            WHERE house_id = ? AND school_id = ?
        ");
        $stmt->bind_param("ssii", $name, $description, $house_id, $school_id);
        $success = $stmt->execute();
        echo json_encode([
            'status'  => $success ? 'success' : 'error',
            'message' => $success ? 'House updated' : 'Failed: ' . $conn->error
        ]);
        $stmt->close();
        break;
    case 'delete_house':
        $house_id = (int)($_POST['house_id'] ?? 0);
        if ($house_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid house']);
            exit;
        }

        // Option A: physically delete student assignment rows
        $stmt = $conn->prepare("DELETE FROM student_houses WHERE house_id = ?");
        $stmt->bind_param("i", $house_id);
        $stmt->execute();
        $stmt->close();

        // Option B: or just mark as inactive (if you want to keep history)
        // $conn->query("UPDATE student_houses SET is_current = 0 WHERE house_id = $house_id");

        // Now it's safe to delete the house
        $stmt = $conn->prepare("DELETE FROM houses WHERE house_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $house_id, $school_id);
        $success = $stmt->execute();

        if ($success) {
            echo json_encode([
                'status'  => 'success',
                'message' => 'House and all student assignments deleted'
            ]);
        } else {
            echo json_encode([
                'status'  => 'error',
                'message' => 'Delete failed: ' . $conn->error
            ]);
        }
        $stmt->close();
        break;
    case 'assign_students_via_excel':
        $house_id = (int)($_POST['house_id'] ?? 0);

        if ($house_id <= 0 || empty($_FILES['excel_file']['tmp_name'])) {
            echo json_encode(['status' => 'error', 'message' => 'House and file are required']);
            exit;
        }

        // Verify house exists
        $stmt = $conn->prepare("SELECT house_id FROM houses WHERE house_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $house_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid house']);
            exit;
        }
        $stmt->close();

        // Handle file upload
        $file = $_FILES['excel_file'];
        $allowed = ['xlsx', 'xls'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed)) {
            echo json_encode(['status' => 'error', 'message' => 'Only .xlsx or .xls allowed']);
            exit;
        }



        try {
            $spreadsheet = IOFactory::load($file['tmp_name']);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();

            $admissionNos = [];
            $headerPassed = false;

            foreach ($rows as $row) {
                if (!$headerPassed) {
                    // Skip header row
                    $headerPassed = true;
                    continue;
                }
                $adm = trim($row[0] ?? ''); // assuming column A = admission_no
                if (!empty($adm)) {
                    $admissionNos[] = $adm;
                }
            }

            if (empty($admissionNos)) {
                echo json_encode(['status' => 'error', 'message' => 'No valid admission numbers found']);
                exit;
            }

            // Find student_ids from admission_no
            $placeholders = implode(',', array_fill(0, count($admissionNos), '?'));
            $stmt = $conn->prepare("
            SELECT student_id, admission_no 
            FROM students 
            WHERE school_id = ? AND admission_no IN ($placeholders)
        ");
            $types = 'i' . str_repeat('s', count($admissionNos));
            $stmt->bind_param($types, $school_id, ...$admissionNos);
            $stmt->execute();
            $result = $stmt->get_result();

            $assigned = 0;
            $notFound = [];

            while ($student = $result->fetch_assoc()) {
                $sid = $student['student_id'];

                // Reset existing current house
                $conn->query("UPDATE student_houses SET is_current = 0 
                          WHERE student_id = $sid AND is_current = 1");

                // Assign new
                $ins = $conn->prepare("
                INSERT INTO student_houses (student_id, house_id, assigned_at, academic_year, is_current)
                VALUES (?, ?, CURDATE(), YEAR(CURDATE()), 1)
            ");
                $ins->bind_param("ii", $sid, $house_id);
                if ($ins->execute()) $assigned++;
                $ins->close();
            }

            $notFoundCount = count($admissionNos) - $assigned;

            echo json_encode([
                'status'  => 'success',
                'message' => "Assigned $assigned student(s). $notFoundCount admission number(s) not found."
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'Excel processing failed: ' . $e->getMessage()
            ]);
        }
        break;
    case 'download_house_template':
        // ────────────────────────────────────────────────
        // Generate and download sample Excel template
        // ────────────────────────────────────────────────
        try {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            // Headers
            $sheet->setCellValue('A1', 'name');
            $sheet->setCellValue('B1', 'admission_no');

            // Make headers bold
            $sheet->getStyle('A1:B1')->getFont()->setBold(true);

            // Optional: sample data (fake students)
            $sampleData = [
                ['John Mwangi', 'JSK/001/23'],
                ['Aisha Hassan', 'STD/045/24'],
                ['Peter Omondi', 'ADM-2024-156'],
                ['Mary Wanjiku', 'KCA/078/22'],
                ['James Kiprop', 'BRK/319/25'],
            ];

            $row = 2;
            foreach ($sampleData as $data) {
                $sheet->setCellValue("A$row", $data[0]);
                $sheet->setCellValue("B$row", $data[1]);
                $row++;
            }

            // Auto-size columns
            foreach (range('A', 'B') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }

            // Set filename and headers for download
            $filename = "House_Assignment_Template_" . date('Y-m-d') . ".xlsx";

            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment;filename="' . $filename . '"');
            header('Cache-Control: max-age=0');

            $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->save('php://output');
            exit; // Important - stop further execution

        } catch (Exception $e) {
            // Fallback if something goes wrong
            header('Content-Type: application/json');
            echo json_encode([
                'status'  => 'error',
                'message' => 'Could not generate template: ' . $e->getMessage()
            ]);
            exit;
        }
        break;
    case 'get_unassigned_students':
        $stmt = $conn->prepare("
            SELECT s.student_id, s.full_name, s.admission_no, c.form_name, st.stream_name
            FROM students s
            LEFT JOIN student_houses sh ON s.student_id = sh.student_id AND sh.is_current = 1
            JOIN classes c ON s.class_id = c.class_id
            JOIN streams st ON s.stream_id = st.stream_id
            WHERE s.school_id = ? AND sh.student_id IS NULL AND s.deleted_at IS NULL
            ORDER BY s.admission_no ASC
            LIMIT 200
        ");
        $stmt->bind_param("i", $school_id);
        $stmt->execute();
        $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['status' => 'success', 'students' => $students]);
        $stmt->close();
        break;

    case 'assign_students_to_house':
        $house_id    = (int)($_POST['house_id'] ?? 0);
        $student_ids = $_POST['student_ids'] ?? [];

        if ($house_id <= 0 || empty($student_ids)) {
            echo json_encode(['status' => 'error', 'message' => 'Missing house or students']);
            exit;
        }

        // Verify house exists
        $stmt = $conn->prepare("SELECT house_id FROM houses WHERE house_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $house_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid house']);
            exit;
        }
        $stmt->close();

        $success_count = 0;
        foreach ($student_ids as $sid) {
            $sid = (int)$sid;

            // Check if student already has a current house → reset it
            $conn->query("UPDATE student_houses SET is_current = 0 
                          WHERE student_id = $sid AND is_current = 1");

            $stmt = $conn->prepare("
                INSERT INTO student_houses (student_id, house_id, assigned_at, academic_year, is_current)
                VALUES (?, ?, CURDATE(), YEAR(CURDATE()), 1)
            ");
            $stmt->bind_param("ii", $sid, $house_id);
            if ($stmt->execute()) $success_count++;
            $stmt->close();
        }

        echo json_encode([
            'status'  => 'success',
            'message' => "Assigned $success_count student(s) to the house"
        ]);
        break;

    case 'get_students_in_house':
        $house_id = (int)($_POST['house_id'] ?? 0);

        $stmt = $conn->prepare("
            SELECT s.student_id, s.full_name, s.admission_no, c.form_name, st.stream_name
            FROM student_houses sh
            JOIN students s ON sh.student_id = s.student_id
            JOIN classes c ON s.class_id = c.class_id
            JOIN streams st ON s.stream_id = st.stream_id
            WHERE sh.house_id = ? AND sh.is_current = 1 AND s.school_id = ?
            ORDER BY s.full_name ASC
        ");
        $stmt->bind_param("ii", $house_id, $school_id);
        $stmt->execute();
        $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['status' => 'success', 'students' => $students]);
        $stmt->close();
        break;
    case 'preview_excel_students':
        if (empty($_FILES['excel_file']['tmp_name'])) {
            echo json_encode(['status' => 'error', 'message' => 'No file uploaded']);
            exit;
        }

        $file = $_FILES['excel_file'];
        $allowed = ['xlsx', 'xls'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed)) {
            echo json_encode(['status' => 'error', 'message' => 'Only .xlsx or .xls allowed']);
            exit;
        }

        try {
            $spreadsheet = IOFactory::load($file['tmp_name']);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray(null, true, true, true); // preserve column letters

            $students = [];
            $headerRow = true;

            // Try to detect headers (case-insensitive)
            $nameCol = null;
            $admCol  = null;

            foreach ($rows as $rowNum => $row) {
                if ($headerRow) {
                    foreach ($row as $col => $cell) {
                        $cellVal = trim(strtolower($cell ?? ''));
                        if (strpos($cellVal, 'name') !== false) {
                            $nameCol = $col;
                        }
                        if (strpos($cellVal, 'admission') !== false || strpos($cellVal, 'adm') !== false) {
                            $admCol = $col;
                        }
                    }
                    $headerRow = false;
                    continue;
                }

                // Skip empty rows
                if (empty($row[$admCol ?? 'B'])) continue;

                $name = trim($row[$nameCol ?? 'A'] ?? '');
                $admission_no = trim($row[$admCol ?? 'B'] ?? '');

                if (empty($admission_no)) continue;

                // Check if this admission_no exists
                $stmt = $conn->prepare("
                SELECT student_id, full_name 
                FROM students 
                WHERE school_id = ? AND admission_no = ?
            ");
                $stmt->bind_param("is", $school_id, $admission_no);
                $stmt->execute();
                $result = $stmt->get_result();
                $exists = $result->num_rows > 0;

                $students[] = [
                    'name'         => $name ?: ($exists ? $result->fetch_assoc()['full_name'] : 'Unknown'),
                    'admission_no' => $admission_no,
                    'exists'       => $exists,
                    'student_id'   => $exists ? $result->fetch_assoc()['student_id'] : null
                ];
                $stmt->close();
            }

            echo json_encode([
                'status'   => 'success',
                'students' => $students
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'Excel read error: ' . $e->getMessage()
            ]);
        }
        break;
    case 'remove_student_from_house':
        $student_id = (int)($_POST['student_id'] ?? 0);
        $house_id   = (int)($_POST['house_id'] ?? 0);

        if ($student_id <= 0 || $house_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid student or house']);
            exit;
        }

        // Verify the assignment exists and belongs to this school
        $stmt = $conn->prepare("
        SELECT sh.student_id 
        FROM student_houses sh
        JOIN students s ON sh.student_id = s.student_id
        WHERE sh.student_id = ? 
          AND sh.house_id = ? 
          AND sh.is_current = 1 
          AND s.school_id = ?
    ");
        $stmt->bind_param("iii", $student_id, $house_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Student not assigned to this house']);
            exit;
        }
        $stmt->close();

        // Option 1: Mark as not current (soft remove - keeps history)
        $stmt = $conn->prepare("
        UPDATE student_houses 
        SET is_current = 0 
        WHERE student_id = ? AND house_id = ? AND is_current = 1
    ");
        $stmt->bind_param("ii", $student_id, $house_id);
        $success = $stmt->execute();

        // Option 2: Physically delete the row (if you don't need history)
        // $stmt = $conn->prepare("DELETE FROM student_houses WHERE student_id = ? AND house_id = ?");
        // $stmt->bind_param("ii", $student_id, $house_id);
        // $success = $stmt->execute();

        if ($success) {
            echo json_encode([
                'status'  => 'success',
                'message' => 'Student removed from house'
            ]);
        } else {
            echo json_encode([
                'status'  => 'error',
                'message' => 'Failed to remove: ' . $conn->error
            ]);
        }
        $stmt->close();
        break;
    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
}
