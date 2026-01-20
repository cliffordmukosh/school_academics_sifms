<?php
// reports/functions.php
session_start();
ob_start();
require __DIR__ . '/../../connection/db.php';

// === PUBLIC ACCESS FOR REPORT PAGES (mobile app) ===
$script_name = basename($_SERVER['SCRIPT_NAME']);

// Allow these report files to be accessed without an active session
$public_report_files = [
    'TranscriptReport.php',
    'public_transcript_report.php',
    // Add more report files here in future if needed
    // 'ReportCard.php',
    // 'FeesStatement.php'
];

$is_public_report = in_array($script_name, $public_report_files);

// Only redirect to login for non-report pages
if (!$is_public_report) {
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
}

// Set variables safely (0 if no session)
$school_id = $_SESSION['school_id'] ?? 0;
$user_id = $_SESSION['user_id'] ?? 0;
$role_id = $_SESSION['role_id'] ?? 0;

function getGradeAndPointsFunc($conn, $value, $grading_system_id, $use_points = false) {
    if ($use_points) {
        // Floor the points to a whole number
        $value = floor($value);
        // Fetch grade based on floored points
        $stmt = $conn->prepare("
            SELECT grade, points
            FROM grading_rules
            WHERE grading_system_id = ? AND points = ?
            ORDER BY points DESC LIMIT 1
        ");
        if (!$stmt) {
            error_log("SQL Error in getGradeAndPointsFunc (points): " . $conn->error);
            return ['grade' => 'N/A', 'points' => 0];
        }
        $stmt->bind_param("ii", $grading_system_id, $value);
    } else {
        // Fetch grade based on score
        $stmt = $conn->prepare("
            SELECT grade, points
            FROM grading_rules
            WHERE grading_system_id = ? AND ? >= min_score AND ? <= max_score
            ORDER BY ABS(? - (min_score + max_score)/2) ASC LIMIT 1
        ");
        if (!$stmt) {
            error_log("SQL Error in getGradeAndPointsFunc (score): " . $conn->error);
            return ['grade' => 'N/A', 'points' => 0];
        }
        $stmt->bind_param("iddd", $grading_system_id, $value, $value, $value);
    }
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Fallback: if no match and value > 0, use lowest non-X/Y grade
    if (!$result && $value > 0) {
        $fallback = $conn->prepare("
            SELECT grade, points 
            FROM grading_rules 
            WHERE grading_system_id = ? AND grade NOT IN ('X', 'Y') 
            ORDER BY min_score ASC LIMIT 1
        ");
        if ($fallback) {
            $fallback->bind_param("i", $grading_system_id);
            $fallback->execute();
            $fallback_result = $fallback->get_result()->fetch_assoc();
            $fallback->close();
            return $fallback_result ? ['grade' => $fallback_result['grade'], 'points' => $fallback_result['points']] : ['grade' => 'E', 'points' => 1];
        }
    }
    $grade = $result ? $result['grade'] : ($value > 0 ? 'E' : 'N/A');
    $points = $result ? $result['points'] : ($value > 0 ? 1 : 0);
    error_log("Grading for value $value (use_points: " . ($use_points ? 'true' : 'false') . "): Grade $grade, Points $points");
    return ['grade' => $grade, 'points' => $points];
}

// Only process AJAX requests
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if (empty($action)) {
        error_log("No action provided in AJAX request");
        echo json_encode(['status' => 'error', 'message' => 'No action specified']);
        ob_end_flush();
        exit;
    }

    switch ($action) {
        // Common Actions
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

        case 'get_terms_for_class':
            $class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
            if (empty($class_id)) {
                echo json_encode(['status' => 'error', 'message' => 'Class ID is required']);
                ob_end_flush();
                exit;
            }
            $stmt = $conn->prepare("
                SELECT DISTINCT term
                FROM exams
                WHERE school_id = ? AND class_id = ? AND status = 'closed'
                AND EXISTS (SELECT 1 FROM results r WHERE r.exam_id = exams.exam_id AND r.status = 'confirmed')
                ORDER BY term DESC
            ");
            if (!$stmt) {
                echo json_encode(['status' => 'error', 'message' => 'Failed to prepare query for terms']);
                ob_end_flush();
                exit;
            }
            $stmt->bind_param("ii", $school_id, $class_id);
            $stmt->execute();
            $terms = $stmt->get_result()->fetch_all(MYSQLI_NUM);
            $stmt->close();
            $terms = array_column($terms, 0);
            error_log("get_terms_for_class: Fetched " . count($terms) . " terms for class_id=$class_id");
            echo json_encode(['status' => 'success', 'terms' => $terms]);
            break;

        case 'get_exams_for_class':
            $class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
            $term = isset($_POST['term']) ? $_POST['term'] : '';
            if (empty($class_id) || empty($term)) {
                echo json_encode(['status' => 'error', 'message' => 'Class ID and Term are required']);
                ob_end_flush();
                exit;
            }
            $stmt = $conn->prepare("
                SELECT exam_id, exam_name, term
                FROM exams
                WHERE school_id = ? AND class_id = ? AND term = ? AND status = 'closed'
                AND EXISTS (SELECT 1 FROM results r WHERE r.exam_id = exams.exam_id AND r.status = 'confirmed')
                ORDER BY created_at DESC
            ");
            if (!$stmt) {
                echo json_encode(['status' => 'error', 'message' => 'Failed to prepare query for exams']);
                ob_end_flush();
                exit;
            }
            $stmt->bind_param("iis", $school_id, $class_id, $term);
            $stmt->execute();
            $exams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            error_log("get_exams_for_class: Fetched " . count($exams) . " exams for class_id=$class_id, term=$term");
            echo json_encode(['status' => 'success', 'exams' => $exams]);
            break;

        case 'get_subjects_for_class':
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
                WHERE cs.class_id = ? AND s.school_id = ? AND s.deleted_at IS NULL
                ORDER BY s.name
            ");
            if (!$stmt) {
                echo json_encode(['status' => 'error', 'message' => 'Failed to prepare query for subjects']);
                ob_end_flush();
                exit;
            }
            $stmt->bind_param("ii", $class_id, $school_id);
            $stmt->execute();
            $subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            error_log("get_subjects_for_class: Fetched " . count($subjects) . " subjects for class_id=$class_id");
            echo json_encode(['status' => 'success', 'subjects' => $subjects]);
            break;

        case 'get_teachers_for_class':
            $class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
            if (empty($class_id)) {
                echo json_encode(['status' => 'error', 'message' => 'Class ID is required']);
                ob_end_flush();
                exit;
            }
            $stmt = $conn->prepare("
                SELECT DISTINCT u.user_id, CONCAT(u.first_name, ' ', u.other_names) AS full_name
                FROM teacher_subjects ts
                JOIN users u ON ts.user_id = u.user_id
                WHERE ts.class_id = ? AND ts.school_id = ? AND u.status = 'active' AND u.deleted_at IS NULL
                ORDER BY full_name
            ");
            if (!$stmt) {
                echo json_encode(['status' => 'error', 'message' => 'Failed to prepare query for teachers']);
                ob_end_flush();
                exit;
            }
            $stmt->bind_param("ii", $class_id, $school_id);
            $stmt->execute();
            $teachers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            error_log("get_teachers_for_class: Fetched " . count($teachers) . " teachers for class_id=$class_id");
            echo json_encode(['status' => 'success', 'teachers' => $teachers]);
            break;

        // Analysis Modal Actions
        case 'get_analysis':
            $class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
            $term = isset($_POST['term']) ? $_POST['term'] : '';
            $exam_id = isset($_POST['exam_id']) ? (int)$_POST['exam_id'] : 0;
            $stream_id = isset($_POST['stream_id']) ? (int)$_POST['stream_id'] : 0;
            if (empty($class_id) || empty($term) || empty($exam_id)) {
                echo json_encode(['status' => 'error', 'message' => 'Form, Term, and Exam are required']);
                ob_end_flush();
                exit;
            }

            // Fetch exam details
            $stmt = $conn->prepare("
                SELECT grading_system_id, min_subjects
                FROM exams
                WHERE exam_id = ? AND school_id = ? AND status = 'closed'
            ");
            if (!$stmt) {
                echo json_encode(['status' => 'error', 'message' => 'Failed to prepare query for exam']);
                ob_end_flush();
                exit;
            }
            $stmt->bind_param("ii", $exam_id, $school_id);
            $stmt->execute();
            $exam = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$exam) {
                echo json_encode(['status' => 'error', 'message' => 'No valid exam found']);
                ob_end_flush();
                exit;
            }
            $grading_system_id = $exam['grading_system_id'];
            $min_subjects = $exam['min_subjects'] ?? 7;

            // Fetch subjects and their types
            $stmt = $conn->prepare("
                SELECT es.subject_id, es.use_papers, cs.type
                FROM exam_subjects es
                JOIN class_subjects cs ON es.subject_id = cs.subject_id AND cs.class_id = ?
                WHERE es.exam_id = ?
            ");
            if (!$stmt) {
                echo json_encode(['status' => 'error', 'message' => 'Failed to prepare query for subjects']);
                ob_end_flush();
                exit;
            }
            $stmt->bind_param("ii", $class_id, $exam_id);
            $stmt->execute();
            $subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            // Check if any subject uses papers
            $uses_papers = false;
            foreach ($subjects as $subject) {
                if ($subject['use_papers']) {
                    $uses_papers = true;
                    break;
                }
            }

            // Fetch paper details for use_papers = 1
            $subject_papers = [];
            foreach ($subjects as $subject) {
                if ($subject['use_papers']) {
                    $stmt = $conn->prepare("
                        SELECT esp.paper_id, sp.paper_name, esp.max_score, sp.contribution_percentage
                        FROM exam_subjects_papers esp
                        JOIN subject_papers sp ON esp.paper_id = sp.paper_id
                        WHERE esp.exam_id = ? AND esp.subject_id = ?
                    ");
                    if (!$stmt) {
                        error_log("SQL Error in get_analysis (papers for subject {$subject['subject_id']}): " . $conn->error);
                        echo json_encode(['status' => 'error', 'message' => "Failed to fetch papers for subject {$subject['subject_id']}"]);
                        ob_end_flush();
                        exit;
                    }
                    $stmt->bind_param("ii", $exam_id, $subject['subject_id']);
                    $stmt->execute();
                    $papers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmt->close();

                    // Ensure contribution_percentage sums to 100
                    $total_percentage = array_sum(array_column($papers, 'contribution_percentage'));
                    if ($total_percentage == 0 || count($papers) == 0) {
                        $equal_percentage = count($papers) > 0 ? 100 / count($papers) : 100;
                        foreach ($papers as &$paper) {
                            $paper['contribution_percentage'] = $equal_percentage;
                        }
                    } elseif ($total_percentage != 100) {
                        error_log("Warning: Contribution percentages for subject {$subject['subject_id']} sum to {$total_percentage}, normalizing to 100");
                        $scale = 100 / $total_percentage;
                        foreach ($papers as &$paper) {
                            $paper['contribution_percentage'] *= $scale;
                        }
                    }
                    $subject_papers[$subject['subject_id']] = $papers;
                } else {
                    $subject_papers[$subject['subject_id']] = [['paper_id' => null, 'paper_name' => '', 'max_score' => 100, 'contribution_percentage' => 100]];
                }
            }

            // Fetch students (all streams if stream_id = 0, else specific stream)
            $query = "
                SELECT s.student_id, s.admission_no, s.full_name, st.stream_name
                FROM students s
                JOIN streams st ON s.stream_id = st.stream_id
                WHERE s.class_id = ? AND s.school_id = ? AND s.deleted_at IS NULL
            ";
            if ($stream_id != 0) {
                $query .= " AND s.stream_id = ?";
            }
            $query .= " ORDER BY s.full_name";
            $stmt = $conn->prepare($query);
            if (!$stmt) {
                echo json_encode(['status' => 'error', 'message' => 'Failed to prepare query for students']);
                ob_end_flush();
                exit;
            }
            if ($stream_id != 0) {
                $stmt->bind_param("iii", $class_id, $school_id, $stream_id);
            } else {
                $stmt->bind_param("ii", $class_id, $school_id);
            }
            $stmt->execute();
            $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            if (empty($students)) {
                echo json_encode(['status' => 'success', 'students' => [], 'message' => 'No students found']);
                ob_end_flush();
                exit;
            }

            // Fetch confirmed results
            $student_ids = array_column($students, 'student_id');
            $placeholders = implode(',', array_fill(0, count($student_ids), '?'));
            $stmt = $conn->prepare("
                SELECT r.student_id, r.subject_id, r.paper_id, r.score
                FROM results r
                WHERE r.exam_id = ? AND r.student_id IN ($placeholders) AND r.status = 'confirmed' AND r.score IS NOT NULL AND r.deleted_at IS NULL
            ");
            if (!$stmt) {
                echo json_encode(['status' => 'error', 'message' => 'Failed to prepare query for results']);
                ob_end_flush();
                exit;
            }
            $types = str_repeat('i', count($student_ids) + 1);
            $params = array_merge([$exam_id], $student_ids);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            // Organize results by student and subject
            $student_results = [];
            foreach ($results as $result) {
                $student_id = $result['student_id'];
                $subject_id = $result['subject_id'];
                $paper_id = $result['paper_id'] !== null ? $result['paper_id'] : null;
                $student_results[$student_id][$subject_id][] = [
                    'paper_id' => $paper_id,
                    'score' => $result['score']
                ];
            }

            // Calculate for each student
            $student_data = [];
            foreach ($students as $student) {
                $student_id = $student['student_id'];
                $subject_results = $student_results[$student_id] ?? [];

                // Calculate subject scores
                $processed_subjects = [];
                foreach ($subjects as $subject) {
                    $subject_id = $subject['subject_id'];
                    $papers = $subject_papers[$subject_id] ?? [];
                    $subject_score = 0;

                    if ($subject['use_papers']) {
                        foreach ($papers as $paper) {
                            $paper_id = $paper['paper_id'];
                            $max_score = $paper['max_score'] ?? 100;
                            $contribution = $paper['contribution_percentage'] ?? 100;
                            $paper_score = null;
                            if (isset($subject_results[$subject_id])) {
                                foreach ($subject_results[$subject_id] as $result) {
                                    if ($result['paper_id'] == $paper_id) {
                                        $paper_score = $result['score'];
                                        break;
                                    }
                                }
                            }
                            if ($paper_score !== null && $max_score > 0) {
                                $subject_score += ($paper_score / $max_score) * ($contribution / 100) * 100;
                            }
                        }
                    } else {
                        if (isset($subject_results[$subject_id])) {
                            foreach ($subject_results[$subject_id] as $result) {
                                if ($result['paper_id'] === null) {
                                    $subject_score = $result['score'] ?? 0;
                                    break;
                                }
                            }
                        }
                    }

                    $grade_info = getGradeAndPointsFunc($conn, $subject_score, $grading_system_id);
                    $processed_subjects[] = [
                        'subject_id' => $subject_id,
                        'type' => $subject['type'],
                        'score' => $subject_score,
                        'points' => $grade_info['points']
                    ];
                }

                // Select subjects for calculation
                $selected_subjects = array_filter($processed_subjects, function($subj) {
                    return $subj['score'] > 0;
                });

                if ($uses_papers) {
                    // Separate compulsory and elective subjects
                    $compulsory_subjects = array_filter($processed_subjects, function($subj) {
                        return $subj['type'] === 'compulsory' && $subj['score'] > 0;
                    });
                    $elective_subjects = array_filter($processed_subjects, function($subj) {
                        return $subj['type'] === 'elective' && $subj['score'] > 0;
                    });

                    // Sort electives by points descending and select top 2
                    usort($elective_subjects, function($a, $b) {
                        return $b['points'] <=> $a['points'];
                    });
                    $top_electives = array_slice($elective_subjects, 0, 2);

                    // Combine compulsory (up to 5) and top 2 electives
                    $compulsory_subjects = array_slice($compulsory_subjects, 0, 5);
                    $selected_subjects = array_merge($compulsory_subjects, $top_electives);
                }

                // Calculate totals and averages
                $total_marks = array_sum(array_column($selected_subjects, 'score'));
                $total_points = array_sum(array_column($selected_subjects, 'points'));
                $num_subjects = count($selected_subjects);
                $average_marks = ($min_subjects > 0) ? $total_marks / $min_subjects : 0;
                $mean_points = ($min_subjects > 0) ? $total_points / $min_subjects : 0;

                // Get grade for average points
                $grade_info = getGradeAndPointsFunc($conn, $mean_points, $grading_system_id, true);

                $student_data[] = [
                    'admission_no' => $student['admission_no'],
                    'full_name' => $student['full_name'],
                    'stream_name' => $student['stream_name'],
                    'total_marks' => round($total_marks, 2),
                    'average_marks' => round($average_marks, 2),
                    'grade' => $grade_info['grade'],
                    'total_points' => round($mean_points, 2),
                    'rank' => 0
                ];
            }

            // Sort and rank by total_points
            usort($student_data, function($a, $b) {
                return $b['total_points'] <=> $a['total_points'];
            });

            // Assign ranks (handling ties)
            $rank = 1;
            $prev_points = null;
            foreach ($student_data as &$data) {
                if ($prev_points !== null && $data['total_points'] < $prev_points) {
                    $rank++;
                }
                $data['rank'] = $rank;
                $prev_points = $data['total_points'];
            }

            // Log sample data for debugging
            if (!empty($student_data)) {
                error_log("Debug: Sample student data for class {$class_id}, term {$term}, stream {$stream_id}: " . json_encode(array_slice($student_data, 0, 1), JSON_PRETTY_PRINT));
            }

            echo json_encode(['status' => 'success', 'students' => $student_data]);
            break;

        // School Grade Analysis Actions
        case 'get_exams_by_year':
            $year = isset($_POST['year']) ? (int)$_POST['year'] : 0;
            if (empty($year)) {
                error_log("get_exams_by_year: Year is required");
                echo json_encode(['status' => 'error', 'message' => 'Year is required']);
                ob_end_flush();
                exit;
            }
            $stmt = $conn->prepare("
                SELECT DISTINCT exam_name, term
                FROM exams
                WHERE school_id = ? AND YEAR(created_at) = ? AND status = 'closed'
                AND EXISTS (SELECT 1 FROM results r WHERE r.exam_id = exams.exam_id AND r.status = 'confirmed')
                ORDER BY exam_name
            ");
            if (!$stmt) {
                error_log("get_exams_by_year: Failed to prepare query - " . $conn->error);
                echo json_encode(['status' => 'error', 'message' => 'Failed to prepare query for exams']);
                ob_end_flush();
                exit;
            }
            $stmt->bind_param("ii", $school_id, $year);
            $stmt->execute();
            $exams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            error_log("get_exams_by_year: Fetched " . count($exams) . " exams for year=$year, school_id=$school_id");
            echo json_encode(['status' => 'success', 'exams' => $exams]);
            break;

        case 'get_classes_by_exam':
            $year = isset($_POST['year']) ? (int)$_POST['year'] : 0;
            $exam_name = isset($_POST['exam_name']) ? trim($_POST['exam_name']) : '';
            $term = isset($_POST['term']) ? trim($_POST['term']) : '';
            if (empty($year) || empty($exam_name) || empty($term)) {
                error_log("get_classes_by_exam: Year, exam name, and term are required");
                echo json_encode(['status' => 'error', 'message' => 'Year, exam name, and term are required']);
                ob_end_flush();
                exit;
            }
            $stmt = $conn->prepare("
                SELECT DISTINCT c.class_id, c.form_name
                FROM exams e
                JOIN classes c ON e.class_id = c.class_id
                WHERE e.school_id = ? AND e.exam_name = ? AND e.term = ? AND YEAR(e.created_at) = ? AND e.status = 'closed'
                AND EXISTS (SELECT 1 FROM results r WHERE r.exam_id = e.exam_id AND r.status = 'confirmed')
                ORDER BY c.form_name
            ");
            if (!$stmt) {
                error_log("get_classes_by_exam: Failed to prepare query - " . $conn->error);
                echo json_encode(['status' => 'error', 'message' => 'Failed to prepare query for classes']);
                ob_end_flush();
                exit;
            }
            $stmt->bind_param("issi", $school_id, $exam_name, $term, $year);
            $stmt->execute();
            $classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            error_log("get_classes_by_exam: Fetched " . count($classes) . " classes for year=$year, exam_name=$exam_name, term=$term");
            echo json_encode(['status' => 'success', 'classes' => $classes]);
            break;

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

        case 'get_students_for_stream':
            $class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
            $stream_id = isset($_POST['stream_id']) ? (int)$_POST['stream_id'] : 0;
            if (empty($class_id)) {
                echo json_encode(['status' => 'error', 'message' => 'Class ID is required']);
                ob_end_flush();
                exit;
            }
            if ($stream_id == 0) {
                // All students in class (all streams)
                $stmt = $conn->prepare("
                    SELECT student_id, full_name, admission_no
                    FROM students
                    WHERE class_id = ? AND school_id = ? AND deleted_at IS NULL
                    ORDER BY full_name
                ");
                if (!$stmt) {
                    echo json_encode(['status' => 'error', 'message' => 'Failed to prepare query for students']);
                    ob_end_flush();
                    exit;
                }
                $stmt->bind_param("ii", $class_id, $school_id);
            } else {
                // Students in specific stream
                $stmt = $conn->prepare("
                    SELECT student_id, full_name, admission_no
                    FROM students
                    WHERE class_id = ? AND stream_id = ? AND school_id = ? AND deleted_at IS NULL
                    ORDER BY full_name
                ");
                if (!$stmt) {
                    echo json_encode(['status' => 'error', 'message' => 'Failed to prepare query for students']);
                    ob_end_flush();
                    exit;
                }
                $stmt->bind_param("iii", $class_id, $stream_id, $school_id);
            }
            $stmt->execute();
            $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            error_log("get_students_for_stream: Fetched " . count($students) . " students for class_id=$class_id, stream_id=$stream_id");
            echo json_encode(['status' => 'success', 'students' => $students]);
            break;

        case 'generate_transcript':
            $year = isset($_POST['year']) ? (int)$_POST['year'] : 0;
            $term = isset($_POST['term']) ? $_POST['term'] : '';
            $class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
            $stream_id = isset($_POST['stream_id']) ? (int)$_POST['stream_id'] : 0;
            $school_id = isset($_POST['school_id']) ? (int)$_POST['school_id'] : 0;

            if (empty($year) || empty($term) || empty($class_id) || empty($school_id)) {
                error_log("generate_transcript: Year, Term, Form, and School ID are required");
                echo json_encode(['status' => 'error', 'message' => 'Year, Term, Form, and School ID are required']);
                ob_end_flush();
                exit;
            }

            // Validate that there are confirmed exams for the selected term, year, and class
            $stmt = $conn->prepare("
                SELECT COUNT(*) AS exam_count
                FROM exams
                WHERE school_id = ? AND class_id = ? AND term = ? AND YEAR(created_at) = ? AND status = 'closed'
                AND EXISTS (SELECT 1 FROM results r WHERE r.exam_id = exams.exam_id AND r.status = 'confirmed')
            ");
            if (!$stmt) {
                error_log("generate_transcript: Failed to prepare query to validate exams - " . $conn->error);
                echo json_encode(['status' => 'error', 'message' => 'Failed to prepare query to validate exams']);
                ob_end_flush();
                exit;
            }
            $stmt->bind_param("iisi", $school_id, $class_id, $term, $year);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($result['exam_count'] == 0) {
                error_log("generate_transcript: No confirmed exams found for year=$year, term=$term, class_id=$class_id");
                echo json_encode(['status' => 'error', 'message' => 'No confirmed exams found for the selected term, year, and form']);
                ob_end_flush();
                exit;
            }

            // Delete existing data from term_subject_aggregates for the selected term and year
            $stmt = $conn->prepare("
                DELETE FROM term_subject_aggregates
                WHERE school_id = ? AND term = ? AND year = ?
            ");
            if (!$stmt) {
                error_log("generate_transcript: Failed to prepare query to delete term_subject_aggregates - " . $conn->error);
                echo json_encode(['status' => 'error', 'message' => 'Failed to prepare query to delete term_subject_aggregates']);
                ob_end_flush();
                exit;
            }
            $stmt->bind_param("isi", $school_id, $term, $year);
            $stmt->execute();
            $stmt->close();

            // Call the stored procedure to generate term subject aggregates
            $stmt = $conn->prepare("CALL sp_generate_term_subject_aggregates(?, ?, ?)");
            if (!$stmt) {
                error_log("generate_transcript: Failed to prepare stored procedure call - " . $conn->error);
                echo json_encode(['status' => 'error', 'message' => 'Failed to prepare stored procedure call']);
                ob_end_flush();
                exit;
            }
            $stmt->bind_param("iis", $school_id, $class_id, $term);
            try {
                $stmt->execute();
            } catch (Exception $e) {
                error_log("generate_transcript: Stored procedure execution failed - " . $e->getMessage());
                echo json_encode(['status' => 'error', 'message' => 'Stored procedure execution failed: ' . $e->getMessage()]);
                $stmt->close();
                ob_end_flush();
                exit;
            }
            $stmt->close();

            // Construct the download URL
            $download_url = "reports/examreports/TranscriptReport.php?year=$year&term=" . urlencode($term) . "&class_id=$class_id&stream_id=$stream_id&school_id=$school_id";
            error_log("generate_transcript: Generated download URL: $download_url");
            echo json_encode(['status' => 'success', 'download_url' => $download_url]);
            break;

        // Legacy School Analysis Actions (if needed)
        case 'get_school_analysis_terms':
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
            error_log("get_school_analysis_terms: Fetched " . count($terms) . " terms for class_id=$class_id, year=$year");
            echo json_encode(['status' => 'success', 'terms' => $terms]);
            break;

        case 'get_school_analysis_exams':
            $class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
            $term = isset($_POST['term']) ? $_POST['term'] : '';
            $year = isset($_POST['year']) ? (int)$_POST['year'] : 0;
            if (empty($class_id) || empty($term) || empty($year)) {
                echo json_encode(['status' => 'error', 'message' => 'Class ID, Term, and Year are required']);
                ob_end_flush();
                exit;
            }
            $stmt = $conn->prepare("
                SELECT exam_id, exam_name, term
                FROM exams
                WHERE school_id = ? AND class_id = ? AND term = ? AND YEAR(created_at) = ? AND status = 'closed'
                AND EXISTS (SELECT 1 FROM results r WHERE r.exam_id = exams.exam_id AND r.status = 'confirmed')
                ORDER BY created_at DESC
            ");
            if (!$stmt) {
                echo json_encode(['status' => 'error', 'message' => 'Failed to prepare query for exams']);
                ob_end_flush();
                exit;
            }
            $stmt->bind_param("iisi", $school_id, $class_id, $term, $year);
            $stmt->execute();
            $exams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            error_log("get_school_analysis_exams: Fetched " . count($exams) . " exams for class_id=$class_id, term=$term, year=$year");
            echo json_encode(['status' => 'success', 'exams' => $exams]);
            break;
        case 'get_custom_groups_and_exams':
            // Fetch all custom groups for this school
            $stmt = $conn->prepare("
        SELECT group_id, name
        FROM custom_groups
        WHERE school_id = ?
        ORDER BY name
    ");
            $stmt->bind_param("i", $school_id);
            $stmt->execute();
            $groups = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            // Fetch all closed exams (you can filter further if needed)
            $stmt = $conn->prepare("
        SELECT exam_id, exam_name, term
        FROM exams
        WHERE school_id = ? AND status = 'closed'
        ORDER BY created_at DESC
    ");
            $stmt->bind_param("i", $school_id);
            $stmt->execute();
            $exams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            echo json_encode([
                'status' => 'success',
                'groups' => $groups,
                'exams'  => $exams
            ]);
            break;

        case 'get_group_subject_results':
            $group_id = (int)($_POST['group_id'] ?? 0);
            $exam_id  = (int)($_POST['exam_id'] ?? 0);

            if ($group_id <= 0 || $exam_id <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'Group and Exam are required']);
                break;
            }

            // Group name
            $stmt = $conn->prepare("SELECT name FROM custom_groups WHERE group_id = ? AND school_id = ?");
            $stmt->bind_param("ii", $group_id, $school_id);
            $stmt->execute();
            $group = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $group_name = $group['name'] ?? 'Unknown Group';

            // Exam name + term
            $stmt = $conn->prepare("SELECT exam_name, term FROM exams WHERE exam_id = ? AND school_id = ?");
            $stmt->bind_param("ii", $exam_id, $school_id);
            $stmt->execute();
            $exam = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $exam_name = $exam['exam_name'] ?? 'Unknown Exam';
            $term = $exam['term'] ?? 'N/A';

            // Subjects in this group
            $stmt = $conn->prepare("
        SELECT s.subject_id, s.name
        FROM custom_group_subjects cgs
        JOIN subjects s ON cgs.subject_id = s.subject_id
        WHERE cgs.group_id = ?
        ORDER BY s.name
    ");
            $stmt->bind_param("i", $group_id);
            $stmt->execute();
            $subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            // Students in this group
            $stmt = $conn->prepare("
        SELECT s.student_id, s.full_name, s.admission_no
        FROM custom_group_students cgs
        JOIN students s ON cgs.student_id = s.student_id
        WHERE cgs.group_id = ? AND s.school_id = ?
        ORDER BY s.full_name
    ");
            $stmt->bind_param("ii", $group_id, $school_id);
            $stmt->execute();
            $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $total_students = count($students);

            if ($total_students === 0) {
                echo json_encode([
                    'status'       => 'success',
                    'results'      => [],
                    'subjects'     => $subjects,
                    'group_name'   => $group_name,
                    'exam_name'    => $exam_name,
                    'term'         => $term,
                    'school_name'  => $school['name'] ?? 'School',
                    'school_logo'  => $school_logo ?? '',
                    'total_students' => 0
                ]);
                break;
            }

            // Get subject scores for this exam
            $student_ids = array_column($students, 'student_id');
            $placeholders = implode(',', array_fill(0, count($student_ids), '?'));
            $stmt = $conn->prepare("
        SELECT esa.student_id, esa.subject_id, esa.subject_score
        FROM exam_subject_aggregates esa
        WHERE esa.exam_id = ? AND esa.student_id IN ($placeholders)
    ");
            $params = array_merge([$exam_id], $student_ids);
            $types = 'i' . str_repeat('i', count($student_ids));
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $scores_result = $stmt->get_result();
            $scores = [];
            while ($row = $scores_result->fetch_assoc()) {
                $scores[$row['student_id']][$row['subject_id']] = $row['subject_score'];
            }
            $stmt->close();

            // Build report data
            $results = [];
            foreach ($students as $stu) {
                $row = [
                    'full_name'     => $stu['full_name'],
                    'admission_no'  => $stu['admission_no'],
                    'subjects'      => []
                ];
                foreach ($subjects as $sub) {
                    $sub_id = $sub['subject_id'];
                    $score = $scores[$stu['student_id']][$sub_id] ?? null;
                    $row['subjects'][$sub_id] = ['score' => $score];
                }
                $results[] = $row;
            }

            // Calculate ranks per subject (only within group)
            foreach ($subjects as $sub) {
                $sub_id = $sub['subject_id'];
                $subject_scores = [];
                foreach ($results as $idx => $row) {
                    $score = $row['subjects'][$sub_id]['score'];
                    if ($score !== null) {
                        $subject_scores[] = ['idx' => $idx, 'score' => $score];
                    }
                }
                usort($subject_scores, fn($a, $b) => $b['score'] <=> $a['score']);
                $rank = 1;
                $prev = null;
                foreach ($subject_scores as $pos => $entry) {
                    if ($prev !== null && $entry['score'] < $prev) $rank = $pos + 1;
                    $results[$entry['idx']]['subjects'][$sub_id]['rank'] = $rank;
                    $prev = $entry['score'];
                }
            }

            echo json_encode([
                'status'         => 'success',
                'results'        => $results,
                'subjects'       => $subjects,
                'group_name'     => $group_name,
                'exam_name'      => $exam_name,
                'term'           => $term,
                'school_name'    => $school['name'] ?? 'School',
                'school_logo'    => $school_logo ?? '',
                'total_students' => $total_students
            ]);
            break;
        default:
            error_log("Invalid action received: $action");
            echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
            break;
    }
    ob_end_flush();
    exit;
}
?>