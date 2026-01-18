<?php
// messaging/functions.php
session_start();
ob_start();
require __DIR__ . '/../../connection/db.php';

// Error logging setup
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../error.log');
error_reporting(E_ALL | E_STRICT);

// API configuration
define('API_KEY', 'b4e69853162316c2db235c8a444eb265');
define('PARTNER_ID', '36');
define('SHORTCODE', 'TEXTME');
define('API_URL', 'https://isms.celcomafrica.com/api/services/sendsms/');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['school_id']) || !isset($_SESSION['role_id'])) {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('HTTP/1.1 403 Forbidden');
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized access']);
        ob_end_flush();
        exit;
    }
    header('Location: ../../login.php');
    exit;
}

$school_id = $_SESSION['school_id'];
$user_id = $_SESSION['user_id'];

// Function to get grade based on score
function getGrade($conn, $score, $grading_system_id) {
    $stmt = $conn->prepare("
        SELECT grade
        FROM grading_rules
        WHERE grading_system_id = ? AND ? >= min_score AND ? <= max_score
        LIMIT 1
    ");
    $stmt->bind_param("idd", $grading_system_id, $score, $score);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result['grade'] ?? 'N/A';
}

// Function to format phone number
function formatPhoneNumber($phone) {
    if (!$phone) {
        return null;
    }
    $phone = preg_replace('/\D/', '', $phone); // Remove non-digits
    if (preg_match('/^0[17]/', $phone)) { // Starts with 01 or 07
        return '254' . substr($phone, 1);
    } elseif (preg_match('/^\+?254/', $phone)) { // Starts with +254 or 254
        return '254' . preg_replace('/^\+?254/', '', $phone);
    } elseif (strlen($phone) === 9 && preg_match('/^[17]/', $phone)) { // 9-digit starting with 1 or 7
        return '254' . $phone;
    }
    return null; // Invalid
}

// Only process AJAX requests
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    switch ($action) {

        // Report Card and Transcript Actions
        case 'get_terms_for_class_and_year':
            $class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
            $year = isset($_POST['year']) ? (int)$_POST['year'] : 0;
            if (empty($class_id) || empty($year)) {
                echo json_encode(['status' => 'error', 'message' => 'Class ID and Year are required']);
                ob_end_flush();
                exit;
            }
            $stmt = $conn->prepare("
                SELECT DISTINCT term
                FROM exams
                WHERE school_id = ? AND class_id = ? AND YEAR(created_at) = ? AND status = 'closed'
                AND EXISTS (SELECT 1 FROM results r WHERE r.exam_id = exams.exam_id AND r.status = 'confirmed')
                ORDER BY term DESC
            ");
            if (!$stmt) {
                echo json_encode(['status' => 'error', 'message' => 'Failed to prepare query for terms']);
                ob_end_flush();
                exit;
            }
            $stmt->bind_param("iii", $school_id, $class_id, $year);
            $stmt->execute();
            $terms = $stmt->get_result()->fetch_all(MYSQLI_NUM);
            $stmt->close();
            $terms = array_column($terms, 0);
            error_log("get_terms_for_class_and_year: Fetched " . count($terms) . " terms for class_id=$class_id, year=$year");
            echo json_encode(['status' => 'success', 'terms' => $terms]);
            break;

        case 'get_exams_for_class_and_year':
            $class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
            $year = isset($_POST['year']) ? (int)$_POST['year'] : 0;
            if (empty($class_id) || empty($year)) {
                echo json_encode(['status' => 'error', 'message' => 'Class ID and Year are required']);
                ob_end_flush();
                exit;
            }
            $stmt = $conn->prepare("
                SELECT exam_id, exam_name
                FROM exams
                WHERE school_id = ? AND class_id = ? AND YEAR(created_at) = ? AND status = 'closed'
                AND EXISTS (SELECT 1 FROM results r WHERE r.exam_id = exams.exam_id AND r.status = 'confirmed')
                ORDER BY created_at DESC
            ");
            if (!$stmt) {
                echo json_encode(['status' => 'error', 'message' => 'Failed to prepare query for exams']);
                ob_end_flush();
                exit;
            }
            $stmt->bind_param("iii", $school_id, $class_id, $year);
            $stmt->execute();
            $exams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            error_log("get_exams_for_class_and_year: Fetched " . count($exams) . " exams for class_id=$class_id, year=$year");
            echo json_encode(['status' => 'success', 'exams' => $exams]);
            break;

        case 'get_streams':
            $class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
            if (empty($class_id)) {
                echo json_encode(['status' => 'error', 'message' => 'Class ID is required']);
                ob_end_flush();
                exit;
            }
            $stmt = $conn->prepare("SELECT stream_id, stream_name FROM streams WHERE class_id = ? AND school_id = ?");
            if (!$stmt) {
                echo json_encode(['status' => 'error', 'message' => 'Failed to prepare query for streams']);
                ob_end_flush();
                exit;
            }
            $stmt->bind_param("ii", $class_id, $school_id);
            $stmt->execute();
            $streams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            error_log("get_streams: Fetched " . count($streams) . " streams for class_id=$class_id");
            echo json_encode(['status' => 'success', 'streams' => $streams]);
            break;

        case 'get_students_for_class':
            $class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
            $stmt = $conn->prepare("
                SELECT student_id, full_name, admission_no, primary_phone, class_id, stream_id
                FROM students
                WHERE class_id = ? AND school_id = ? AND deleted_at IS NULL
                ORDER BY full_name
            ");
            $stmt->bind_param("ii", $class_id, $school_id);
            $stmt->execute();
            $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            echo json_encode(['status' => 'success', 'students' => $students]);
            break;

       case 'get_all_teachers':
    $stmt = $conn->prepare("
        SELECT u.user_id AS teacher_id, CONCAT(u.first_name, ' ', u.other_names) AS full_name, u.phone_number AS phone
        FROM users u
        JOIN roles r ON u.role_id = r.role_id
        WHERE u.school_id = ? AND r.role_name = 'Teacher' AND u.status = 'active' AND u.deleted_at IS NULL
        ORDER BY u.first_name, u.other_names
    ");
    if (!$stmt) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to prepare query for teachers']);
        ob_end_flush();
        exit;
    }
    $stmt->bind_param("i", $school_id);
    $stmt->execute();
    $teachers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    echo json_encode(['status' => 'success', 'teachers' => $teachers]);
    break;

        case 'get_preview':
            $recipient_type = $_POST['recipient_type'] ?? '';
            $message_type = $_POST['message_type'] ?? '';
            $year = (int)($_POST['year'] ?? 0);
            $class_id = (int)($_POST['class_id'] ?? 0);
            $term = $_POST['term'] ?? '';
            $exam_id = (int)($_POST['exam_id'] ?? 0);
            $scope = $_POST['recipient_scope'] ?? '';
            $stream_id = (int)($_POST['stream_id'] ?? 0);
            $student_id = (int)($_POST['student_id'] ?? 0);

            if ($recipient_type !== 'parents') {
                echo json_encode(['status' => 'error', 'message' => 'Only parents supported for preview.']);
                exit;
            }

            // Get school name and phone
            $stmt = $conn->prepare("SELECT name, phone FROM schools WHERE school_id = ?");
            $stmt->bind_param("i", $school_id);
            $stmt->execute();
            $school = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $school_name = $school['name'] ?? 'School';
            $school_phone = $school['phone'] ?? 'N/A';

            // Fetch exam_name if exam_results
            $exam_name = '';
            if ($message_type === 'exam_results' && $exam_id) {
                $stmt = $conn->prepare("SELECT exam_name FROM exams WHERE exam_id = ?");
                $stmt->bind_param("i", $exam_id);
                $stmt->execute();
                $exam_result = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $exam_name = $exam_result['exam_name'] ?? 'N/A';
            }

            // Determine students to send to
            $query = "
                SELECT 
                    s.student_id, s.full_name, s.admission_no, s.primary_phone, s.class_id, s.stream_id,
                    c.form_name AS class_name,
                    st.stream_name
                FROM students s
                LEFT JOIN classes c ON s.class_id = c.class_id
                LEFT JOIN streams st ON s.stream_id = st.stream_id AND st.school_id = ?
                WHERE s.school_id = ?
            ";
            $params = [$school_id, $school_id];
            $types = "ii";
            if ($scope === 'entire_class') {
                $query .= " AND s.class_id = ?";
                $params[] = $class_id;
                $types .= "i";
            } elseif ($scope === 'specific_stream') {
                $query .= " AND s.stream_id = ?";
                $params[] = $stream_id;
                $types .= "i";
            } elseif ($scope === 'specific_student') {
                $query .= " AND s.student_id = ?";
                $params[] = $student_id;
                $types .= "i";
            }
            $query .= " AND s.deleted_at IS NULL ORDER BY s.full_name";

            $stmt = $conn->prepare($query);
            if (!$stmt) {
                echo json_encode(['status' => 'error', 'message' => 'Failed to prepare query for students']);
                ob_end_flush();
                exit;
            }
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            if (empty($students)) {
                echo json_encode(['status' => 'error', 'message' => 'No students found for the selected criteria']);
                ob_end_flush();
                exit;
            }

            $previews = [];
            foreach ($students as $student) {
                $message = "From: $school_name ($school_phone)\n\n";
                $message .= "Dear Parent /Guardian here are " . ($message_type === 'exam_results' ? $exam_name : $term) . " results for your\n";
                $message .= "Student: {$student['full_name']} ADM: {$student['admission_no']}\n";
                $message .= "Class {$student['class_name']} " . ($student['stream_name'] ?? 'N/A') . "\n";

                if ($message_type === 'exam_results') {
                    $message .= "Exam name: $exam_name\n";

                    // Fetch aggregate with min_subjects from exams table
                    $stmt = $conn->prepare("
                        SELECT ea.*, e.min_subjects 
                        FROM exam_aggregates ea 
                        JOIN exams e ON ea.exam_id = e.exam_id 
                        WHERE ea.exam_id = ? AND ea.student_id = ?
                    ");
                    $stmt->bind_param("ii", $exam_id, $student['student_id']);
                    $stmt->execute();
                    $aggregate = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    // Fetch subject aggregates with subject names
                    $stmt = $conn->prepare("
                        SELECT esa.*, s.name AS subject_name
                        FROM exam_subject_aggregates esa
                        JOIN subjects s ON esa.subject_id = s.subject_id
                        WHERE esa.exam_id = ? AND esa.student_id = ? AND s.school_id = ?
                        ORDER BY esa.subject_id
                    ");
                    $stmt->bind_param("iii", $exam_id, $student['student_id'], $school_id);
                    $stmt->execute();
                    $subject_aggregates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmt->close();

                    if ($aggregate) {
                        $message .= "Total Score: {$aggregate['total_score']}/" . ($aggregate['min_subjects'] * 100) . "\nMean Score: {$aggregate['mean_score']}\nMean Grade: {$aggregate['mean_grade']}\nPosition in Class: {$aggregate['position_class']}\nPosition in Stream: {$aggregate['position_stream']}\n\nSubjects:\n";
                        foreach ($subject_aggregates as $subj) {
                            $message .= "{$subj['subject_name']}: {$subj['subject_score']}\n";
                        }
                    } else {
                        $message .= "No exam results available.\n";
                    }
                } elseif ($message_type === 'term_results') {
                    // Fetch aggregate from student_term_results_aggregates
                    $stmt = $conn->prepare("SELECT * FROM student_term_results_aggregates WHERE term = ? AND year = ? AND student_id = ?");
                    $stmt->bind_param("sii", $term, $year, $student['student_id']);
                    $stmt->execute();
                    $aggregate = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    // Fetch subject totals with subject names
                    $stmt = $conn->prepare("
                        SELECT tst.*, s.name AS subject_name
                        FROM term_subject_totals tst
                        JOIN subjects s ON tst.subject_id = s.subject_id
                        WHERE tst.term = ? AND tst.year = ? AND tst.student_id = ? AND s.school_id = ?
                        ORDER BY tst.subject_id
                    ");
                    $stmt->bind_param("siii", $term, $year, $student['student_id'], $school_id);
                    $stmt->execute();
                    $subject_totals = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmt->close();

                    if ($aggregate) {
                        $message .= "Total Marks: {$aggregate['total_marks']}/" . ($aggregate['min_subjects'] * 100) . "\nAverage: {$aggregate['average']}\nGrade: {$aggregate['grade']}\nClass Position: {$aggregate['class_position']}\nStream Position: {$aggregate['stream_position']}\n\nSubjects:\n";
                        foreach ($subject_totals as $subj) {
                            $message .= "{$subj['subject_name']}: {$subj['subject_mean']}\n";
                        }
                    } else {
                        $message .= "No term results available.\n";
                    }
                }

                $previews[] = [
                    'recipient_note' => "{$student['full_name']} (Parent)",
                    'phone' => $student['primary_phone'] ?? 'N/A',
                    'message' => $message
                ];
            }

            echo json_encode(['status' => 'success', 'previews' => $previews]);
            break;
case 'send_message':
    $recipient_type = $_POST['recipient_type'] ?? '';
    $message_type = $_POST['message_type'] ?? '';
    $year = (int)($_POST['year'] ?? 0);
    $class_id = (int)($_POST['class_id'] ?? 0);
    $term = $_POST['term'] ?? '';
    $exam_id = (int)($_POST['exam_id'] ?? 0);
    $scope = $_POST['recipient_scope'] ?? '';
    $stream_id = (int)($_POST['stream_id'] ?? 0);
    $student_id = (int)($_POST['student_id'] ?? 0);

    if ($recipient_type !== 'parents') {
        echo json_encode(['status' => 'error', 'message' => 'Only parents supported currently.']);
        exit;
    }

    if (!in_array($message_type, ['exam_results', 'term_results', 'general'])) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid message type. Must be exam_results, term_results, or general.']);
        exit;
    }

    // Get school name and phone
    $stmt = $conn->prepare("SELECT name, phone FROM schools WHERE school_id = ?");
    $stmt->bind_param("i", $school_id);
    $stmt->execute();
    $school = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $school_name = $school['name'] ?? 'School';
    $school_phone = $school['phone'] ?? 'N/A';

    // Fetch exam_name if exam_results
    $exam_name = '';
    if ($message_type === 'exam_results' && $exam_id) {
        $stmt = $conn->prepare("SELECT exam_name FROM exams WHERE exam_id = ?");
        $stmt->bind_param("i", $exam_id);
        $stmt->execute();
        $exam_result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $exam_name = $exam_result['exam_name'] ?? 'N/A';
    }

    // Determine reference_id and reference_type
    $reference_id = null;
    $reference_type = null;
    if ($message_type === 'exam_results') {
        $reference_id = $exam_id;
        $reference_type = 'exam';
    } elseif ($message_type === 'term_results') {
        $stmt = $conn->prepare("SELECT setting_id FROM school_settings WHERE school_id = ? AND term_name = ? AND academic_year = ?");
        $stmt->bind_param("isi", $school_id, $term, $year);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($result) {
            $reference_id = $result['setting_id'];
            $reference_type = 'term';
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Term settings not found.']);
            exit;
        }
    }

    // Determine students to send to
    $query = "
        SELECT 
            s.student_id, s.full_name, s.admission_no, s.primary_phone, s.class_id, s.stream_id,
            c.form_name AS class_name,
            st.stream_name
        FROM students s
        LEFT JOIN classes c ON s.class_id = c.class_id
        LEFT JOIN streams st ON s.stream_id = st.stream_id AND st.school_id = ?
        WHERE s.school_id = ?
    ";
    $params = [$school_id, $school_id];
    $types = "ii";
    if ($scope === 'entire_class') {
        $query .= " AND s.class_id = ?";
        $params[] = $class_id;
        $types .= "i";
    } elseif ($scope === 'specific_stream') {
        $query .= " AND s.stream_id = ?";
        $params[] = $stream_id;
        $types .= "i";
    } elseif ($scope === 'specific_student') {
        $query .= " AND s.student_id = ?";
        $params[] = $student_id;
        $types .= "i";
    }
    $query .= " AND s.deleted_at IS NULL ORDER BY s.full_name";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to prepare query for students']);
        ob_end_flush();
        exit;
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($students)) {
        echo json_encode(['status' => 'error', 'message' => 'No students found for the selected criteria']);
        exit;
    }

    // Prepare insert statement (include teacher_id, which is NULL for parents)
    $insert_stmt = $conn->prepare("
        INSERT INTO messages_sent 
        (school_id, sent_by, student_id, class_id, stream_id, teacher_id, recipient_type, recipient_phone, message_content, message_type, reference_id, reference_type, status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $success_count = 0;
    $fail_count = 0;
    $responses = [];

    foreach ($students as $student) {
        try {
            $raw_phone = $student['primary_phone'] ?? '';
            $formatted_phone = formatPhoneNumber($raw_phone);
            $message_content = ''; // Initialize for all cases
            $status = 'failed';
            $teacher_id = null; // Always NULL for parent messages

            if (empty($raw_phone) || !$formatted_phone) {
                error_log("Invalid phone for student {$student['student_id']}: $raw_phone");
                error_log("Debug: message_type=$message_type, recipient_type=$recipient_type");
                $message_content = 'Failed: Invalid phone number';
                $status = 'failed';
                $insert_stmt->bind_param("iiiiisssssiss",
                    $school_id, $user_id, $student['student_id'], $student['class_id'],
                    $student['stream_id'], $teacher_id, $recipient_type, $raw_phone,
                    $message_content, $message_type, $reference_id, $reference_type, $status
                );
                $insert_stmt->execute();
                $fail_count++;
                $responses[] = ['student_id' => $student['student_id'], 'status' => $status, 'reason' => 'Invalid phone'];
                continue;
            }

            // Build message
            $message_content = "From: $school_name ($school_phone)\n\n";
            $message_content .= "Dear Parent /Guardian here are " . ($message_type === 'exam_results' ? $exam_name : $term) . " results for your\n";
            $message_content .= "Student: {$student['full_name']} ADM: {$student['admission_no']}\n";
            $message_content .= "Class {$student['class_name']} " . ($student['stream_name'] ?? 'N/A') . "\n";

            if ($message_type === 'exam_results') {
                $message_content .= "Exam name: $exam_name\n";

                // Fetch aggregate with min_subjects from exams table
                $agg_stmt = $conn->prepare("
                    SELECT ea.*, e.min_subjects 
                    FROM exam_aggregates ea 
                    JOIN exams e ON ea.exam_id = e.exam_id 
                    WHERE ea.exam_id = ? AND ea.student_id = ?
                ");
                $agg_stmt->bind_param("ii", $exam_id, $student['student_id']);
                $agg_stmt->execute();
                $aggregate = $agg_stmt->get_result()->fetch_assoc();
                $agg_stmt->close();

                // Fetch subject aggregates
                $subj_stmt = $conn->prepare("
                    SELECT esa.*, s.name AS subject_name
                    FROM exam_subject_aggregates esa
                    JOIN subjects s ON esa.subject_id = s.subject_id
                    WHERE esa.exam_id = ? AND esa.student_id = ? AND s.school_id = ?
                    ORDER BY esa.subject_id
                ");
                $subj_stmt->bind_param("iii", $exam_id, $student['student_id'], $school_id);
                $subj_stmt->execute();
                $subject_aggregates = $subj_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $subj_stmt->close();

                if ($aggregate) {
                    $message_content .= "Total Score: {$aggregate['total_score']}/" . ($aggregate['min_subjects'] * 100) . "\nMean Score: {$aggregate['mean_score']}\nMean Grade: {$aggregate['mean_grade']}\nPosition in Class: {$aggregate['position_class']}\nPosition in Stream: {$aggregate['position_stream']}\n\nSubjects:\n";
                    foreach ($subject_aggregates as $subj) {
                        $message_content .= "{$subj['subject_name']}: {$subj['subject_score']}\n";
                    }
                } else {
                    $message_content .= "No exam results available.\n";
                }
            } elseif ($message_type === 'term_results') {
                // Fetch aggregate
                $agg_stmt = $conn->prepare("SELECT * FROM student_term_results_aggregates WHERE term = ? AND year = ? AND student_id = ?");
                $agg_stmt->bind_param("sii", $term, $year, $student['student_id']);
                $agg_stmt->execute();
                $aggregate = $agg_stmt->get_result()->fetch_assoc();
                $agg_stmt->close();

                // Fetch subject totals
                $subj_stmt = $conn->prepare("
                    SELECT tst.*, s.name AS subject_name
                    FROM term_subject_totals tst
                    JOIN subjects s ON tst.subject_id = s.subject_id
                    WHERE tst.term = ? AND tst.year = ? AND tst.student_id = ? AND s.school_id = ?
                    ORDER BY tst.subject_id
                ");
                $subj_stmt->bind_param("siii", $term, $year, $student['student_id'], $school_id);
                $subj_stmt->execute();
                $subject_totals = $subj_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $subj_stmt->close();

                if ($aggregate) {
                    $message_content .= "Total Marks: {$aggregate['total_marks']}/" . ($aggregate['min_subjects'] * 100) . "\nAverage: {$aggregate['average']}\nGrade: {$aggregate['grade']}\nClass Position: {$aggregate['class_position']}\nStream Position: {$aggregate['stream_position']}\n\nSubjects:\n";
                    foreach ($subject_totals as $subj) {
                        $message_content .= "{$subj['subject_name']}: {$subj['subject_mean']}\n";
                    }
                } else {
                    $message_content .= "No term results available.\n";
                }
            } else {
                // For 'general' message_type, no additional data is needed
                $message_content .= "General message: Contact school for details.\n";
            }

            // Send via API
            $apiData = [
                'partnerID' => PARTNER_ID,
                'apikey' => API_KEY,
                'mobile' => $formatted_phone,
                'message' => $message_content,
                'shortcode' => SHORTCODE,
                'pass_type' => 'plain'
            ];

            $ch = curl_init(API_URL);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($apiData));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $apiResponse = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($apiResponse === false || $curlError) {
                error_log("cURL error for phone $formatted_phone: $curlError");
                $status = 'failed';
                $responseData = ['error' => 'cURL failed'];
            } else {
                $responseData = json_decode($apiResponse, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    error_log("JSON decode error for phone $formatted_phone: " . json_last_error_msg());
                    $status = 'failed';
                    $responseData = ['error' => 'Invalid JSON response'];
                } else {
                    $status = ($httpCode === 200 && isset($responseData['status']) && $responseData['status'] === 'success') ? 'sent' : 'failed';
                    if ($status === 'failed') {
                        error_log("API error for phone $formatted_phone: " . ($apiResponse ?: 'No response'));
                    }
                }
            }

            if ($status === 'sent') {
                $success_count++;
            } else {
                $fail_count++;
            }

            // Insert into DB
            $insert_stmt->bind_param("iiiiisssssiss",
                $school_id, $user_id, $student['student_id'], $student['class_id'],
                $student['stream_id'], $teacher_id, $recipient_type, $raw_phone,
                $message_content, $message_type, $reference_id, $reference_type, $status
            );
            $insert_stmt->execute();

            $responses[] = [
                'student_id' => $student['student_id'],
                'phone' => $formatted_phone,
                'status' => $status,
                'response' => $responseData
            ];
        } catch (Exception $e) {
            error_log("Error processing student {$student['student_id']}: " . $e->getMessage());
            $fail_count++;
            $responses[] = ['student_id' => $student['student_id'], 'status' => 'failed', 'reason' => $e->getMessage()];
        }
    }
    $insert_stmt->close();

    // Response with counts
    $summary = "Sent: $success_count, Failed: $fail_count";
    echo json_encode(['status' => 'success', 'message' => $summary, 'details' => $responses]);
    break;
    case 'send_teacher_message':
    $subject = $_POST['subject'] ?? '';
    $message_content = $_POST['message_content'] ?? '';
    $teacher_recipient_type = $_POST['teacher_recipient_type'] ?? '';
    $specific_teacher_id = (int)($_POST['specific_teacher_id'] ?? 0);

    if (empty($subject) || empty($message_content)) {
        echo json_encode(['status' => 'error', 'message' => 'Subject and message are required']);
        exit;
    }
    if (empty($teacher_recipient_type) && empty($specific_teacher_id)) {
        echo json_encode(['status' => 'error', 'message' => 'Please select a recipient type or specific teacher']);
        exit;
    }

    // Get school name and phone
    $stmt = $conn->prepare("SELECT name, phone FROM schools WHERE school_id = ?");
    $stmt->bind_param("i", $school_id);
    $stmt->execute();
    $school = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $school_name = $school['name'] ?? 'School';
    $school_phone = $school['phone'] ?? 'N/A';

    // Determine teachers to send to
    $query = "
        SELECT u.user_id AS teacher_id, CONCAT(u.first_name, ' ', u.other_names) AS full_name, u.phone_number AS phone
        FROM users u
        JOIN roles r ON u.role_id = r.role_id
    ";
    $params = [$school_id];
    $types = "i";
    if ($teacher_recipient_type === 'specific_teacher' && $specific_teacher_id) {
        $query .= " WHERE u.school_id = ? AND u.user_id = ? AND r.role_name = 'Teacher' AND u.status = 'active' AND u.deleted_at IS NULL";
        $params[] = $specific_teacher_id;
        $types .= "i";
    } else {
        $query .= " WHERE u.school_id = ? AND r.role_name = 'Teacher' AND u.status = 'active' AND u.deleted_at IS NULL";
        if ($teacher_recipient_type === 'all_class_teachers') {
            $query .= " AND EXISTS (SELECT 1 FROM class_teachers ct WHERE ct.user_id = u.user_id AND ct.school_id = ?)";
            $params[] = $school_id;
            $types .= "i";
        } elseif ($teacher_recipient_type === 'all_class_supervisors') {
            $query .= " AND EXISTS (SELECT 1 FROM class_supervisors cs WHERE cs.user_id = u.user_id AND cs.school_id = ?)";
            $params[] = $school_id;
            $types .= "i";
        }
    }
    $query .= " ORDER BY u.first_name, u.other_names";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("Failed to prepare query for teachers: " . $conn->error);
        echo json_encode(['status' => 'error', 'message' => 'Failed to prepare query for teachers']);
        ob_end_flush();
        exit;
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $teachers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($teachers)) {
        echo json_encode(['status' => 'error', 'message' => 'No teachers found for the selected criteria']);
        exit;
    }

    // Prepare insert statement
    $insert_stmt = $conn->prepare("
        INSERT INTO messages_sent 
        (school_id, sent_by, teacher_id, recipient_type, recipient_phone, message_content, message_type, status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$insert_stmt) {
        error_log("Failed to prepare insert statement: " . $conn->error);
        echo json_encode(['status' => 'error', 'message' => 'Failed to prepare insert statement']);
        ob_end_flush();
        exit;
    }

    $success_count = 0;
    $fail_count = 0;
    $responses = [];

    foreach ($teachers as $teacher) {
        $teacher_id = $teacher['teacher_id'];
        $raw_phone = $teacher['phone'] ?? '';
        $recipient_type = 'teacher';
        $message_type = 'custom';
        $status = 'failed'; // Default to failed, update to 'sent' if successful
        $message_body = ''; // Initialize to empty, populate if phone is valid

        // Validate and format phone number
        $formatted_phone = formatPhoneNumber($raw_phone);
        if (empty($raw_phone) || !$formatted_phone) {
            error_log("Invalid phone for teacher $teacher_id: $raw_phone");
            $reason = 'Invalid phone';
            // Bind variables to avoid reference error
            $recipient_phone = $raw_phone;
            $insert_stmt->bind_param("iissssss", 
                $school_id, $user_id, $teacher_id, $recipient_type, 
                $recipient_phone, $message_body, $message_type, $status
            );
            $insert_stmt->execute();
            $fail_count++;
            $responses[] = ['teacher_id' => $teacher_id, 'status' => $status, 'reason' => $reason];
            continue;
        }

        // Build message
        $message_body = "From: $school_name ($school_phone)\n\n";
        $message_body .= "Subject: $subject\n\n";
        $message_body .= "Dear {$teacher['full_name']},\n\n";
        $message_body .= "$message_content\n";

        // Send via API
        $apiData = [
            'partnerID' => PARTNER_ID,
            'apikey' => API_KEY,
            'mobile' => $formatted_phone,
            'message' => $message_body,
            'shortcode' => SHORTCODE,
            'pass_type' => 'plain'
        ];

        $ch = curl_init(API_URL);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($apiData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $apiResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($apiResponse === false || $curlError) {
            error_log("cURL error for phone $formatted_phone: $curlError");
            $responseData = ['error' => 'cURL failed'];
            $status = 'failed';
        } else {
            $responseData = json_decode($apiResponse, true) ?? ['error' => 'Invalid JSON response'];
            $status = ($httpCode === 200 && isset($responseData['status']) && $responseData['status'] === 'success') ? 'sent' : 'failed';
            if ($status === 'failed') {
                error_log("API error for phone $formatted_phone: " . ($apiResponse ?: 'No response'));
            }
        }

        if ($status === 'sent') {
            $success_count++;
        } else {
            $fail_count++;
        }

        // Insert into DB
        $recipient_phone = $raw_phone;
        $insert_stmt->bind_param("iissssss", 
            $school_id, $user_id, $teacher_id, $recipient_type, 
            $recipient_phone, $message_body, $message_type, $status
        );
        $insert_stmt->execute();

        $responses[] = [
            'teacher_id' => $teacher_id,
            'phone' => $formatted_phone,
            'status' => $status,
            'response' => $responseData
        ];
    }
    $insert_stmt->close();

    $summary = "Sent: $success_count, Failed: $fail_count";
    echo json_encode(['status' => 'success', 'message' => $summary, 'details' => $responses]);
    break;
    
   case 'get_messages':
    $stmt = $conn->prepare("
        SELECT 
            ms.message_id AS id, 
            ms.recipient_phone, 
            ms.message_type AS type, 
            ms.sent_at, 
            ms.status, 
            ms.message_content AS content, 
            ms.recipient_type,
            COALESCE(s.full_name, u.first_name, u.other_names) AS recipient_name
        FROM messages_sent ms
        LEFT JOIN students s ON ms.student_id = s.student_id
        LEFT JOIN users u ON ms.teacher_id = u.user_id
        LEFT JOIN roles r ON u.role_id = r.role_id AND r.role_name = 'Teacher'
        WHERE ms.school_id = ?
        ORDER BY ms.sent_at DESC
        LIMIT 100
    ");
    $stmt->bind_param("i", $school_id);
    $stmt->execute();
    $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $messages = [];
    foreach ($results as $row) {
        $recipient = $row['recipient_phone']; // Default to phone number
        if ($row['recipient_name']) {
            if ($row['recipient_type'] === 'parent') {
                $recipient = "Parent of {$row['recipient_name']}";
            } elseif ($row['recipient_type'] === 'teacher') {
                $recipient = $row['recipient_name'];
            }
        }
        $messages[] = [
            'id' => $row['id'],
            'recipient' => $recipient,
            'type' => $row['type'],
            'sent_at' => $row['sent_at'],
            'status' => $row['status'],
            'content' => $row['content']
        ];
    }
    echo json_encode(['status' => 'success', 'messages' => $messages]);
    break;
        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
            break;
    }
    ob_end_flush();
    exit;
}
?>