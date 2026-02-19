<?php
// houses/functions.php
session_start();
require __DIR__ . '/../../connection/db.php';
require 'vendor/autoload.php'; // assuming composer install phpoffice/phpspreadsheet
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if (!isset($_SESSION['user_id']) || !isset($_SESSION['school_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$school_id = $_SESSION['school_id'];
$action = $_POST['action'] ?? '';

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
        // Delete assignments to remove FK references (unassigns students)
        $conn->query("DELETE FROM student_houses WHERE house_id = $house_id");
        // Now delete the house
        $stmt = $conn->prepare("DELETE FROM houses WHERE house_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $house_id, $school_id);
        $success = $stmt->execute();
        echo json_encode([
            'status' => $success ? 'success' : 'error',
            'message' => $success ? 'House deleted' : 'Failed: ' . $conn->error
        ]);
        $stmt->close();
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
        $house_id = (int)($_POST['house_id'] ?? 0);
        $student_ids = [];  // Will populate from file or POST

        // Handle file upload if present (CSV parsing; for Excel, use PhpSpreadsheet)
        if (isset($_FILES['students_file']) && $_FILES['students_file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['students_file']['tmp_name'];
            $file_type = pathinfo($_FILES['students_file']['name'], PATHINFO_EXTENSION);

            if ($file_type === 'csv') {
                if (($handle = fopen($file, "r")) !== FALSE) {
                    // Skip header if present (assume first row is data or header: NAME,ADMN)
                    fgetcsv($handle, 1000, ",");  // Skip potential header
                    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                        if (count($data) < 2) continue;  // Invalid row
                        $name = trim($data[0]);  // NAME (not used for lookup, just for ref)
                        $adm = trim($data[1]);   // ADMN
                        if (empty($adm)) continue;

                        // Check if student exists
                        $stmt = $conn->prepare("SELECT student_id FROM students WHERE admission_no = ? AND school_id = ? AND deleted_at IS NULL");
                        $stmt->bind_param("si", $adm, $school_id);
                        $stmt->execute();
                        $res = $stmt->get_result();
                        if ($row = $res->fetch_assoc()) {
                            $student_ids[] = $row['student_id'];
                        }
                        $stmt->close();
                    }
                    fclose($handle);
                }
            } else {
                // For .xls/.xlsx, install PhpSpreadsheet (composer require phpoffice/phpspreadsheet)
                // Example stub (uncomment after install):
                /*
            require 'vendor/autoload.php';
            use PhpOffice\PhpSpreadsheet\IOFactory;
            try {
                $spreadsheet = IOFactory::load($file);
                $worksheet = $spreadsheet->getActiveSheet();
                foreach ($worksheet->getRowIterator() as $row) {
                    $cellIterator = $row->getCellIterator();
                    $cellIterator->setIterateOnlyExistingCells(FALSE);
                    $cells = [];
                    foreach ($cellIterator as $cell) {
                        $cells[] = $cell->getValue();
                    }
                    if (count($cells) < 2 || empty($cells[1])) continue;
                    $adm = trim($cells[1]);
                    // Same query as above to add to $student_ids
                }
            } catch (Exception $e) {
                echo json_encode(['status' => 'error', 'message' => 'File parse error: ' . $e->getMessage()]);
                exit;
            }
            */
                echo json_encode(['status' => 'error', 'message' => 'Unsupported file type. Use CSV or install PhpSpreadsheet for Excel.']);
                exit;
            }
        } else {
            // Fallback to manual checkboxes
            $student_ids = $_POST['student_ids'] ?? [];
        }

        if ($house_id <= 0 || empty($student_ids)) {
            echo json_encode(['status' => 'error', 'message' => 'Missing house or students']);
            exit;
        }
        // Verify house exists (unchanged)
        $stmt = $conn->prepare("SELECT house_id FROM houses WHERE house_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $house_id, $school_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid house']);
            exit;
        }
        $stmt->close();

        // Assign (unchanged, but using $student_ids from file or manual)
        $success_count = 0;
        foreach ($student_ids as $sid) {
            $sid = (int)$sid;
            // Check if student already has a current house → reset it
            $conn->query("UPDATE student_houses SET is_current = 0 WHERE student_id = $sid AND is_current = 1");
            $stmt = $conn->prepare("
            INSERT INTO student_houses (student_id, house_id, assigned_at, academic_year, is_current)
            VALUES (?, ?, CURDATE(), YEAR(CURDATE()), 1)
        ");
            $stmt->bind_param("ii", $sid, $house_id);
            if ($stmt->execute()) $success_count++;
            $stmt->close();
        }
        echo json_encode([
            'status' => 'success',
            'message' => "Assigned $success_count student(s) to the house"
        ]);
        break;
// In functions.php - new case
case 'download_sample':


    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    
    $sheet->setCellValue('A1', 'NAME');
    $sheet->setCellValue('B1', 'ADMN');
    
    $sample = [
        ['John Mwangi', 'ADM00123'],
        ['Mary Achieng', 'ADM00456'],
        ['Peter Omondi', 'ADM00789'],
    ];
    
    $row = 2;
    foreach ($sample as $data) {
        $sheet->setCellValue('A'.$row, $data[0]);
        $sheet->setCellValue('B'.$row, $data[1]);
        $row++;
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="Sample_students_house.xlsx"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;

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

    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
}
