<?php
// reports/functions.php
session_start();
ob_start();
require __DIR__ . '/../../connection/db.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['school_id']) || !isset($_SESSION['role_id'])) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access']);
    ob_end_flush();
    exit;
}

$action = isset($_POST['action']) ? $_POST['action'] : '';
$school_id = $_SESSION['school_id'];
$user_id = $_SESSION['user_id'];
$role_id = $_SESSION['role_id'];

header('Content-Type: application/json');

// Function to get grade and points based on score or total points
function getGradeAndPointsFunc($conn, $value, $grading_system_id, $use_points = false) {
    if ($use_points) {
        // Fetch grade based on points
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

switch ($action) {
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
        echo json_encode(['status' => 'success', 'terms' => $terms]);
        break;

    case 'get_analysis':
        $class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
        $term = isset($_POST['term']) ? $_POST['term'] : '';
        $stream_id = isset($_POST['stream_id']) ? (int)$_POST['stream_id'] : 0;
        if (empty($class_id) || empty($term) || empty($stream_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Form, Term and Stream are required']);
            ob_end_flush();
            exit;
        }

        // Find the exam_id for the class and term (latest closed exam with confirmed results)
        $stmt = $conn->prepare("
            SELECT exam_id, grading_system_id, min_subjects
            FROM exams
            WHERE school_id = ? AND class_id = ? AND term = ? AND status = 'closed'
            AND EXISTS (SELECT 1 FROM results r WHERE r.exam_id = exams.exam_id AND r.status = 'confirmed')
            ORDER BY created_at DESC LIMIT 1
        ");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to prepare query for exam']);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("iis", $school_id, $class_id, $term);
        $stmt->execute();
        $exam = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$exam) {
            echo json_encode(['status' => 'error', 'message' => 'No closed exam found for this form and term']);
            ob_end_flush();
            exit;
        }

            $exam_id = isset($_POST['exam_id']) ? (int)$_POST['exam_id'] : 0;
            if (empty($exam_id)) {
                echo json_encode(['status' => 'error', 'message' => 'Exam ID is required']);
                ob_end_flush();
                exit;
            }
            $stmt = $conn->prepare("
                SELECT grading_system_id, min_subjects
                FROM exams
                WHERE exam_id = ? AND school_id = ? AND status = 'closed'
            ");
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

                // Ensure contribution_percentage sums to 100, default to equal if missing
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

        // Fetch students in the stream
        $stmt = $conn->prepare("
            SELECT student_id, admission_no, full_name
            FROM students
            WHERE stream_id = ? AND class_id = ? AND school_id = ? AND deleted_at IS NULL
        ");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to prepare query for students']);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("iii", $stream_id, $class_id, $school_id);
        $stmt->execute();
        $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (empty($students)) {
            echo json_encode(['status' => 'success', 'students' => [], 'message' => 'No students found in the selected stream']);
            ob_end_flush();
            exit;
        }

        // Fetch confirmed results
        $stmt = $conn->prepare("
            SELECT r.student_id, r.subject_id, r.paper_id, r.score
            FROM results r
            WHERE r.exam_id = ? AND r.student_id IN (
                SELECT student_id FROM students WHERE stream_id = ? AND class_id = ? AND school_id = ? AND deleted_at IS NULL
            ) AND r.status = 'confirmed' AND r.score IS NOT NULL AND r.deleted_at IS NULL
        ");
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to prepare query for results']);
            ob_end_flush();
            exit;
        }
        $stmt->bind_param("iiii", $exam_id, $stream_id, $class_id, $school_id);
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

            // Debug logging
            error_log("Debug: Student {$student['full_name']} - Total Marks: $total_marks, Num Subjects: $num_subjects, Min Subjects: $min_subjects, Average Marks: $average_marks, Grade: {$grade_info['grade']}");

            $student_data[] = [
                'admission_no' => $student['admission_no'],
                'full_name' => $student['full_name'],
                'total_marks' => round($total_marks, 2),
                'average_marks' => round($average_marks, 2),
                'grade' => $grade_info['grade'],
                'total_points' => round($mean_points, 2),
                'rank' => 0
            ];
        }

        // Sort by mean_points for ranking
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
        $stmt->bind_param("iis", $school_id, $class_id, $term);
        $stmt->execute();
        $exams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        echo json_encode(['status' => 'success', 'exams' => $exams]);
        break;
    case 'get_students_for_stream':
    $stream_id = isset($_POST['stream_id']) ? (int)$_POST['stream_id'] : 0;
    $class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
    if (empty($stream_id) || empty($class_id)) {
        echo json_encode(['status' => 'error', 'message' => 'Stream ID and Class ID are required']);
        ob_end_flush();
        exit;
    }
    $stmt = $conn->prepare("
        SELECT student_id, full_name, admission_no
        FROM students
        WHERE stream_id = ? AND class_id = ? AND school_id = ? AND deleted_at IS NULL
        ORDER BY full_name
    ");
    if (!$stmt) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to prepare query for students']);
        ob_end_flush();
        exit;
    }
    $stmt->bind_param("iii", $stream_id, $class_id, $school_id);
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    echo json_encode(['status' => 'success', 'students' => $students]);
    break;
    
  case 'get_student_report_card':
    // Initialize response
    $response = ['status' => 'error', 'message' => 'An error occurred', 'exam_names' => [], 'html' => '', 'chart_data' => ['labels' => [], 'data' => []]];
    
    try {
        $class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
        $term = isset($_POST['term']) ? $_POST['term'] : '';
        $stream_id = isset($_POST['stream_id']) ? (int)$_POST['stream_id'] : 0;
        $student_id = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
        if (empty($class_id) || empty($term) || empty($stream_id) || empty($student_id)) {
            $response['message'] = 'Form, Term, Stream, and Student are required';
            echo json_encode($response);
            ob_end_flush();
            exit;
        }

        // Fetch school details
        $stmt = $conn->prepare("SELECT name, logo FROM schools WHERE school_id = ?");
        $stmt->bind_param("i", $school_id);
        if (!$stmt->execute()) {
            $response['message'] = 'Failed to fetch school details';
            echo json_encode($response);
            ob_end_flush();
            exit;
        }
        $school = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$school) {
            $response['message'] = 'School not found';
            echo json_encode($response);
            ob_end_flush();
            exit;
        }

        // Fetch student details with LEFT JOIN for streams
$stmt = $conn->prepare("
    SELECT s.full_name, s.admission_no, c.form_name, st.stream_name, s.profile_picture
    FROM students s
    LEFT JOIN classes c ON s.class_id = c.class_id AND c.school_id = ?
    LEFT JOIN streams st ON s.stream_id = st.stream_id AND st.school_id = ?
    WHERE s.student_id = ? AND s.school_id = ? AND s.deleted_at IS NULL
");
if (!$stmt) {
    error_log("Prepare failed: " . $conn->error);
    $response['message'] = 'Failed to prepare query for student details';
    echo json_encode($response);
    ob_end_flush();
    exit;
}
$stmt->bind_param("iiii", $school_id, $school_id, $student_id, $school_id);
if (!$stmt->execute()) {
    error_log("Execute failed: " . $stmt->error);
    $response['message'] = 'Failed to execute query for student details';
    echo json_encode($response);
    ob_end_flush();
    exit;
}
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$student) {
    error_log("No student data found for student_id: $student_id, school_id: $school_id");
    $response['message'] = 'Student not found or no valid data';
    echo json_encode($response);
    ob_end_flush();
    exit;
}
error_log("Student data: " . json_encode($student));

        // Fetch closed exams with confirmed results
        $stmt = $conn->prepare("
            SELECT exam_id, exam_name, grading_system_id, min_subjects
            FROM exams
            WHERE school_id = ? AND class_id = ? AND term = ? AND status = 'closed'
            AND EXISTS (SELECT 1 FROM results r WHERE r.exam_id = exams.exam_id AND r.status = 'confirmed')
            ORDER BY created_at ASC
        ");
        $stmt->bind_param("iis", $school_id, $class_id, $term);
        if (!$stmt->execute()) {
            $response['message'] = 'Failed to fetch exams';
            echo json_encode($response);
            ob_end_flush();
            exit;
        }
        $exams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (empty($exams)) {
            $response['message'] = 'No closed exams with confirmed results found for this term';
            echo json_encode($response);
            ob_end_flush();
            exit;
        }
        $exam_names = array_column($exams, 'exam_name');
        $num_exams = count($exams);

        // Fetch subjects and their types
        $stmt = $conn->prepare("
            SELECT es.subject_id, s.name, es.use_papers, cs.type
            FROM exam_subjects es
            JOIN subjects s ON es.subject_id = s.subject_id
            JOIN class_subjects cs ON es.subject_id = cs.subject_id AND cs.class_id = ?
            WHERE es.exam_id = ?
        ");
        $stmt->bind_param("ii", $class_id, $exams[0]['exam_id']);
        if (!$stmt->execute()) {
            $response['message'] = 'Failed to fetch subjects';
            echo json_encode($response);
            ob_end_flush();
            exit;
        }
        $subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Fetch paper details for subjects with use_papers = 1
        $subject_papers = [];
        foreach ($subjects as $subject) {
            if ($subject['use_papers']) {
                $stmt = $conn->prepare("
                    SELECT esp.paper_id, sp.paper_name, esp.max_score, sp.contribution_percentage
                    FROM exam_subjects_papers esp
                    JOIN subject_papers sp ON esp.paper_id = sp.paper_id
                    WHERE esp.exam_id = ? AND esp.subject_id = ?
                ");
                $stmt->bind_param("ii", $exams[0]['exam_id'], $subject['subject_id']);
                if (!$stmt->execute()) {
                    $response['message'] = 'Failed to fetch papers for subject ' . $subject['name'];
                    echo json_encode($response);
                    ob_end_flush();
                    exit;
                }
                $papers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();

                $total_percentage = array_sum(array_column($papers, 'contribution_percentage'));
                if ($total_percentage == 0 || count($papers) == 0) {
                    $equal_percentage = count($papers) > 0 ? 100 / count($papers) : 100;
                    foreach ($papers as &$paper) {
                        $paper['contribution_percentage'] = $equal_percentage;
                    }
                } elseif ($total_percentage != 100) {
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

        // Fetch results for the student across all exams
        $results = [];
        foreach ($exams as $exam) {
            $stmt = $conn->prepare("
                SELECT r.subject_id, r.paper_id, r.score, r.subject_teacher_remark_text
                FROM results r
                WHERE r.exam_id = ? AND r.student_id = ? AND r.status = 'confirmed' AND r.score IS NOT NULL AND r.deleted_at IS NULL
            ");
            $stmt->bind_param("ii", $exam['exam_id'], $student_id);
            if (!$stmt->execute()) {
                $response['message'] = 'Failed to fetch results for exam ' . $exam['exam_id'];
                echo json_encode($response);
                ob_end_flush();
                exit;
            }
            $exam_results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $results[$exam['exam_id']] = $exam_results;
        }

        // Calculate mean scores per subject, only include subjects with non-null scores
        $subject_data = [];
        $total_marks = 0;
        $total_points = 0;
        $num_subjects = 0;
        $grading_system_id = $exams[0]['grading_system_id'];
        $min_subjects = $exams[0]['min_subjects'] ?? 7;

        foreach ($subjects as $subject) {
            $subject_id = $subject['subject_id'];
            $scores = array_fill(0, $num_exams, null);
            $sum_score = 0;
            $count = 0;

            foreach ($exams as $index => $exam) {
                $exam_id = $exam['exam_id'];
                $exam_results = $results[$exam_id] ?? [];
                $subject_score = 0;

                if ($subject['use_papers']) {
                    $papers = $subject_papers[$subject_id] ?? [];
                    foreach ($papers as $paper) {
                        $paper_id = $paper['paper_id'];
                        $max_score = $paper['max_score'] ?? 100;
                        $contribution = $paper['contribution_percentage'] ?? 100;
                        $paper_score = null;
                        foreach ($exam_results as $result) {
                            if ($result['subject_id'] == $subject_id && $result['paper_id'] == $paper_id) {
                                $paper_score = $result['score'];
                                break;
                            }
                        }
                        if ($paper_score !== null && $max_score > 0) {
                            $subject_score += ($paper_score / $max_score) * ($contribution / 100) * 100;
                        }
                    }
                } else {
                    foreach ($exam_results as $result) {
                        if ($result['subject_id'] == $subject_id && $result['paper_id'] === null) {
                            $subject_score = $result['score'] ?? 0;
                            break;
                        }
                    }
                }
                if ($subject_score > 0) {
                    $scores[$index] = round($subject_score, 1);
                    $sum_score += $subject_score;
                    $count++;
                }
            }

            $mean_score = $num_exams > 0 ? $sum_score / $num_exams : 0;
            if ($mean_score == 0) {
                continue; // Skip subjects with no valid scores
            }

            $grade_info = getGradeAndPointsFunc($conn, $mean_score, $grading_system_id);

            $stmt = $conn->prepare("
                SELECT u.first_name, u.other_names
                FROM teacher_subjects ts
                JOIN users u ON ts.user_id = u.user_id
                WHERE ts.subject_id = ? AND ts.class_id = ? AND ts.school_id = ?
                LIMIT 1
            ");
            $stmt->bind_param("iii", $subject_id, $class_id, $school_id);
            if (!$stmt->execute()) {
                $response['message'] = 'Failed to fetch teacher for subject ' . $subject['name'];
                echo json_encode($response);
                ob_end_flush();
                exit;
            }
            $teacher = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $teacher_name = $teacher ? ($teacher['first_name'] . ' ' . ($teacher['other_names'] ?? '')) : 'N/A';

            $stmt = $conn->prepare("
                SELECT remark_text
                FROM remarks_rules
                WHERE school_id IS NULL AND category = 'subject_teacher' AND ? >= min_score AND ? <= max_score
                LIMIT 1
            ");
            $stmt->bind_param("dd", $mean_score, $mean_score);
            if (!$stmt->execute()) {
                $response['message'] = 'Failed to fetch remark for subject ' . $subject['name'];
                echo json_encode($response);
                ob_end_flush();
                exit;
            }
            $remark = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $remark_text = $remark ? $remark['remark_text'] : 'N/A';

            $total_marks += $mean_score;
            $total_points += $grade_info['points'];
            $num_subjects++;

            $subject_data[] = [
                'subject_name' => $subject['name'],
                'scores' => $scores,
                'mean_score' => round($mean_score, 1),
                'grade' => $grade_info['grade'],
                'points' => $grade_info['points'],
                'remark' => $remark_text,
                'teacher' => $teacher_name
            ];
        }

        // Calculate M.Score and grade
        $m_score = $min_subjects > 0 ? $total_points / $min_subjects : 0;
        $student_grade_info = getGradeAndPointsFunc($conn, $m_score, $grading_system_id, true);
        $student_mean = $num_subjects > 0 ? $total_marks / $num_subjects : 0;

        // Fetch class and form means
        $class_mean = 0;
        $form_mean = 0;
        $class_grade = 'N/A';
        $form_grade = 'N/A';
        $stmt = $conn->prepare("
            SELECT AVG(r.score) as mean_score
            FROM results r
            WHERE r.exam_id IN (
                SELECT exam_id FROM exams WHERE school_id = ? AND class_id = ? AND term = ? AND status = 'closed'
            ) AND r.stream_id = ? AND r.status = 'confirmed' AND r.score IS NOT NULL
        ");
        $stmt->bind_param("iisi", $school_id, $class_id, $term, $stream_id);
        if (!$stmt->execute()) {
            $response['message'] = 'Failed to fetch class mean';
            echo json_encode($response);
            ob_end_flush();
            exit;
        }
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($result && $result['mean_score']) {
            $class_mean = round($result['mean_score'], 2);
            $class_grade_info = getGradeAndPointsFunc($conn, $class_mean, $grading_system_id);
            $class_grade = $class_grade_info['grade'];
        }

        $stmt = $conn->prepare("
            SELECT AVG(r.score) as mean_score
            FROM results r
            WHERE r.exam_id IN (
                SELECT exam_id FROM exams WHERE school_id = ? AND class_id = ? AND term = ? AND status = 'closed'
            ) AND r.status = 'confirmed' AND r.score IS NOT NULL
        ");
        $stmt->bind_param("iis", $school_id, $class_id, $term);
        if (!$stmt->execute()) {
            $response['message'] = 'Failed to fetch form mean';
            echo json_encode($response);
            ob_end_flush();
            exit;
        }
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($result && $result['mean_score']) {
            $form_mean = round($result['mean_score'], 2);
            $form_grade_info = getGradeAndPointsFunc($conn, $form_mean, $grading_system_id);
            $form_grade = $form_grade_info['grade'];
        }

        // Fetch student rankings based on M.Score
        $stream_rankings = [];
        $stmt = $conn->prepare("
            SELECT s.student_id
            FROM students s
            WHERE s.stream_id = ? AND s.class_id = ? AND s.school_id = ? AND s.deleted_at IS NULL
        ");
        $stmt->bind_param("iii", $stream_id, $class_id, $school_id);
        if (!$stmt->execute()) {
            $response['message'] = 'Failed to fetch stream students';
            echo json_encode($response);
            ob_end_flush();
            exit;
        }
        $stream_students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($stream_students as $student) {
            $stmt = $conn->prepare("
                SELECT r.subject_id, AVG(r.score) / ? as mean_score
                FROM results r
                WHERE r.exam_id IN (
                    SELECT exam_id FROM exams WHERE school_id = ? AND class_id = ? AND term = ? AND status = 'closed'
                ) AND r.student_id = ? AND r.status = 'confirmed' AND r.score IS NOT NULL
                GROUP BY r.subject_id
            ");
            $stmt->bind_param("diisi", $num_exams, $school_id, $class_id, $term, $student['student_id']);
            if (!$stmt->execute()) {
                $response['message'] = 'Failed to fetch subject means for student ' . $student['student_id'];
                echo json_encode($response);
                ob_end_flush();
                exit;
            }
            $subjects_for_student = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $subject_points = [];
            foreach ($subjects_for_student as $subj) {
                $grade_info = getGradeAndPointsFunc($conn, $subj['mean_score'], $grading_system_id);
                $subject_points[] = $grade_info['points'];
            }
            $m_score_student = count($subject_points) >= $min_subjects ? array_sum($subject_points) / $min_subjects : 0;
            $stream_rankings[] = ['student_id' => $student['student_id'], 'm_score' => $m_score_student];
        }
        usort($stream_rankings, function($a, $b) {
            return $b['m_score'] <=> $a['m_score'];
        });
        $class_position = 0;
        $class_total = count($stream_rankings);
        foreach ($stream_rankings as $index => $rank) {
            if ($rank['student_id'] == $student_id) {
                $class_position = $index + 1;
                break;
            }
        }

        // Form position
        $form_rankings = [];
        $stmt = $conn->prepare("
            SELECT s.student_id
            FROM students s
            WHERE s.class_id = ? AND s.school_id = ? AND s.deleted_at IS NULL
        ");
        $stmt->bind_param("ii", $class_id, $school_id);
        if (!$stmt->execute()) {
            $response['message'] = 'Failed to fetch form students';
            echo json_encode($response);
            ob_end_flush();
            exit;
        }
        $form_students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($form_students as $student) {
            $stmt = $conn->prepare("
                SELECT r.subject_id, AVG(r.score) / ? as mean_score
                FROM results r
                WHERE r.exam_id IN (
                    SELECT exam_id FROM exams WHERE school_id = ? AND class_id = ? AND term = ? AND status = 'closed'
                ) AND r.student_id = ? AND r.status = 'confirmed' AND r.score IS NOT NULL
                GROUP BY r.subject_id
            ");
            $stmt->bind_param("diisi", $num_exams, $school_id, $class_id, $term, $student['student_id']);
            if (!$stmt->execute()) {
                $response['message'] = 'Failed to fetch subject means for student ' . $student['student_id'];
                echo json_encode($response);
                ob_end_flush();
                exit;
            }
            $subjects_for_student = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $subject_points = [];
            foreach ($subjects_for_student as $subj) {
                $grade_info = getGradeAndPointsFunc($conn, $subj['mean_score'], $grading_system_id);
                $subject_points[] = $grade_info['points'];
            }
            $m_score_student = count($subject_points) >= $min_subjects ? array_sum($subject_points) / $min_subjects : 0;
            $form_rankings[] = ['student_id' => $student['student_id'], 'm_score' => $m_score_student];
        }
        usort($form_rankings, function($a, $b) {
            return $b['m_score'] <=> $a['m_score'];
        });
        $form_position = 0;
        $form_total = count($form_rankings);
        foreach ($form_rankings as $index => $rank) {
            if ($rank['student_id'] == $student_id) {
                $form_position = $index + 1;
                break;
            }
        }

        // Fetch historical performance for progress analysis (using M.Score)
        $progress_data = [];
        $chart_labels = [];
        $chart_data = [];
        $stmt = $conn->prepare("
            SELECT e.class_id, c.form_name, e.term, e.year
            FROM results r
            JOIN exams e ON r.exam_id = e.exam_id
            JOIN classes c ON e.class_id = c.class_id
            WHERE r.student_id = ? AND r.school_id = ? AND r.status = 'confirmed' AND r.score IS NOT NULL
            GROUP BY e.class_id, e.term, e.year
            ORDER BY e.year, e.class_id, e.term
        ");
        $stmt->bind_param("ii", $student_id, $school_id);
        if (!$stmt->execute()) {
            $response['message'] = 'Failed to fetch historical performance';
            echo json_encode($response);
            ob_end_flush();
            exit;
        }
        $progress_results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($progress_results as $row) {
            $stmt = $conn->prepare("
                SELECT r.subject_id, AVG(r.score) / (
                    SELECT COUNT(*) FROM exams e2
                    WHERE e2.class_id = e.class_id AND e2.term = e.term AND e2.year = e.year
                    AND e2.status = 'closed' AND EXISTS (
                        SELECT 1 FROM results r2 WHERE r2.exam_id = e2.exam_id AND r2.status = 'confirmed'
                    )
                ) as mean_score
                FROM results r
                JOIN exams e ON r.exam_id = e.exam_id
                WHERE r.student_id = ? AND r.school_id = ? AND e.class_id = ? AND e.term = ? AND e.year = ?
                AND r.status = 'confirmed' AND r.score IS NOT NULL
                GROUP BY r.subject_id
            ");
            $stmt->bind_param("iiiss", $student_id, $school_id, $row['class_id'], $row['term'], $row['year']);
            if (!$stmt->execute()) {
                $response['message'] = 'Failed to fetch subject means for term ' . $row['term'];
                echo json_encode($response);
                ob_end_flush();
                exit;
            }
            $subjects_for_term = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $subject_points = [];
            foreach ($subjects_for_term as $subj) {
                $grade_info = getGradeAndPointsFunc($conn, $subj['mean_score'], $grading_system_id);
                $subject_points[] = $grade_info['points'];
            }
            $term_m_score = count($subject_points) >= $min_subjects ? array_sum($subject_points) / $min_subjects : 0;
            $term_grade = getGradeAndPointsFunc($conn, $term_m_score, $grading_system_id, true)['grade'];

            $key = $row['form_name'] . ' ' . $row['term'];
            $progress_data[$row['form_name']][$row['term']] = [
                'mean' => round($term_m_score, 1),
                'grade' => $term_grade
            ];
            $chart_labels[] = $key;
            $chart_data[] = $term_m_score;
        }

        // Fetch remarks for class teacher and principal
        $stmt = $conn->prepare("
            SELECT remark_text
            FROM remarks_rules
            WHERE school_id IS NULL AND category = 'class_teacher' AND ? >= min_score AND ? <= max_score
            LIMIT 1
        ");
        $stmt->bind_param("dd", $student_mean, $student_mean);
        if (!$stmt->execute()) {
            $response['message'] = 'Failed to fetch class teacher remark';
            echo json_encode($response);
            ob_end_flush();
            exit;
        }
        $class_teacher_remark = $stmt->get_result()->fetch_assoc()['remark_text'] ?? 'N/A';
        $stmt->close();

        $stmt = $conn->prepare("
            SELECT remark_text
            FROM remarks_rules
            WHERE school_id IS NULL AND category = 'principal' AND ? >= min_score AND ? <= max_score
            LIMIT 1
        ");
        $stmt->bind_param("dd", $student_mean, $student_mean);
        if (!$stmt->execute()) {
            $response['message'] = 'Failed to fetch principal remark';
            echo json_encode($response);
            ob_end_flush();
            exit;
        }
        $principal_remark = $stmt->get_result()->fetch_assoc()['remark_text'] ?? 'N/A';
        $stmt->close();

        // Generate report card HTML
        $html = '
        <div class="report-container" style="font-size: 12px;">
            <div class="header">
                <img src="' . ($school['logo'] ? htmlspecialchars($school['logo']) : './assets/images/school-logo.png') . '" alt="Logo">
                <h2>' . htmlspecialchars($school['name']) . '</h2>
                <p class="mb-0">Progress Report for Term ' . htmlspecialchars($term) . ' - Year ' . date('Y') . '</p>
            </div>
            <div class="student-row mb-2">
                <div class="card h-100">
                    <div class="card-body">
<div class="d-flex align-items-center">
    <img src="' . (isset($student['profile_picture']) && $student['profile_picture'] ? htmlspecialchars($student['profile_picture']) : './assets/images/default-profile.png') . '" alt="Student Photo" class="profile-pic me-2">
    <div>
        <p class="mb-1"><strong>Name:</strong> ' . htmlspecialchars($student['full_name'] ?? 'N/A') . '</p>
        <p class="mb-1"><strong>Adm No:</strong> ' . htmlspecialchars($student['admission_no'] ?? 'N/A') . '</p>
        <p class="mb-1"><strong>Class:</strong> ' . htmlspecialchars(($student['form_name'] ?? 'N/A') . ' ' . ($student['stream_name'] ?? '')) . '</p>
        <p class="mb-0"><strong>Grade:</strong> ' . $student_grade_info['grade'] . '</p>
    </div>
</div>
                    </div>
                </div>
                <div class="card h-100">
                    <div class="card-body">
                        <p class="mb-1"><strong>Overall Position:</strong> ' . $form_position . ' out of ' . $form_total . '</p>
                        <p class="mb-1"><strong>Class Position:</strong> ' . $class_position . ' out of ' . $class_total . '</p>
                        <p class="mb-1"><strong>Current Term Avg:</strong> ' . round($student_mean, 2) . '%</p>
                        <p class="mb-0"><strong>M.Score:</strong> ' . round($m_score, 2) . '</p>
                    </div>
                </div>
            </div>
            <div class="remarks-box">
                <strong>Class Mean:</strong> ' . $class_mean . ' &nbsp;
                <strong>Class Grade:</strong> ' . $class_grade . ' &nbsp;
                <strong>Form Mean:</strong> ' . $form_mean . ' &nbsp;
                <strong>Form Grade:</strong> ' . $form_grade . ' &nbsp;
                <strong>Student Mean:</strong> ' . round($student_mean, 2) . ' &nbsp;
                <strong>Student Grade:</strong> ' . $student_grade_info['grade'] . '
            </div>
            <table class="table table-bordered table-sm text-center align-middle mb-2">
                <thead>
                    <tr id="dynamicExamHeaders">
                        <th>Subject</th>';
        foreach ($exam_names as $exam_name) {
            $html .= '<th>' . htmlspecialchars($exam_name) . '</th>';
        }
        $html .= '
                        <th>Mean %</th>
                        <th>Grade</th>
                        <th>Remarks</th>
                        <th>Subject Teacher</th>
                    </tr>
                </thead>
                <tbody>';

        $total_possible_marks = $num_subjects * 100;
        foreach ($subject_data as $subj) {
            $html .= '
                    <tr>
                        <td>' . htmlspecialchars($subj['subject_name']) . '</td>';
            foreach ($subj['scores'] as $score) {
                $html .= '<td>' . ($score !== null ? $score : '-') . '</td>';
            }
            $html .= '
                        <td>' . $subj['mean_score'] . '</td>
                        <td>' . $subj['grade'] . '</td>
                        <td>' . htmlspecialchars($subj['remark']) . '</td>
                        <td>' . htmlspecialchars($subj['teacher']) . '</td>
                    </tr>';
        }

        $html .= '
                    <tr class="table-primary fw-bold">
                        <td>Totals</td>
                        <td colspan="' . ($num_exams + 4) . '">Total Marks: ' . round($total_marks, 1) . '/' . $total_possible_marks . ' | Average: ' . round($student_mean, 1) . '% | M.Score: ' . round($m_score, 2) . '</td>
                    </tr>
                </tbody>
            </table>
            <table class="table table-sm table-bordered mb-2 align-middle">
                <tr>
                    <td width="55%">
                        <strong>PROGRESS ANALYSIS</strong><br>
                        Total Marks: ' . round($total_marks, 1) . '/' . $total_possible_marks . ' &nbsp; | &nbsp; M.Score: ' . round($m_score, 2) . '<br>
                        <table class="table table-bordered table-sm text-center mt-1 mb-1">
                            <tr>
                                <th colspan="4">FORM ONE</th>
                                <th colspan="4">FORM TWO</th>
                            </tr>
                            <tr>
                                <td>I</td><td>II</td><td>III</td><td>Pos/Out</td>
                                <td>I</td><td>II</td><td>III</td><td>Pos/Out</td>
                            </tr>
                            <tr>
                                <td>' . ($progress_data['Form 1']['Term 1']['mean'] ?? '-') . '</td>
                                <td>' . ($progress_data['Form 1']['Term 2']['mean'] ?? '-') . '</td>
                                <td>' . ($progress_data['Form 1']['Term 3']['mean'] ?? '-') . '</td>
                                <td>-</td>
                                <td>' . ($progress_data['Form 2']['Term 1']['mean'] ?? '-') . '</td>
                                <td>' . ($progress_data['Form 2']['Term 2']['mean'] ?? '-') . '</td>
                                <td>' . ($progress_data['Form 2']['Term 3']['mean'] ?? '-') . '</td>
                                <td>-</td>
                            </tr>
                            <tr>
                                <td colspan="4">Mean: ' . ($progress_data['Form 1']['Term 1']['mean'] ?? '-') . ' (' . ($progress_data['Form 1']['Term 1']['grade'] ?? '-') . ')</td>
                                <td colspan="4">Mean: ' . ($progress_data['Form 2']['Term 1']['mean'] ?? '-') . ' (' . ($progress_data['Form 2']['Term 1']['grade'] ?? '-') . ')</td>
                            </tr>
                            <tr>
                                <th colspan="4">FORM THREE</th>
                                <th colspan="4">FORM FOUR</th>
                            </tr>
                            <tr>
                                <td>I</td><td>II</td><td>III</td><td>Pos/Out</td>
                                <td>I</td><td>II</td><td>III</td><td>Pos/Out</td>
                            </tr>
                            <tr>
                                <td>' . ($progress_data['Form 3']['Term 1']['mean'] ?? '-') . '</td>
                                <td>' . ($progress_data['Form 3']['Term 2']['mean'] ?? '-') . '</td>
                                <td>' . ($progress_data['Form 3']['Term 3']['mean'] ?? '-') . '</td>
                                <td>-</td>
                                <td>' . ($progress_data['Form 4']['Term 1']['mean'] ?? '-') . '</td>
                                <td>' . ($progress_data['Form 4']['Term 2']['mean'] ?? '-') . '</td>
                                <td>' . ($progress_data['Form 4']['Term 3']['mean'] ?? '-') . '</td>
                                <td>-</td>
                            </tr>
                            <tr>
                                <td colspan="4">Mean: ' . ($progress_data['Form 3']['Term 1']['mean'] ?? '-') . ' (' . ($progress_data['Form 3']['Term 1']['grade'] ?? '-') . ')</td>
                                <td colspan="4">Mean: ' . ($progress_data['Form 4']['Term 1']['mean'] ?? '-') . ' (' . ($progress_data['Form 4']['Term 1']['grade'] ?? '-') . ')</td>
                            </tr>
                        </table>
                    </td>
                    <td>
                        <strong>Graphical Analysis</strong><br>
                        <canvas id="progressChart" width="400" height="250"></canvas>
                    </td>
                </tr>
            </table>
            <div class="remarks-box mb-2">
                <p class="mb-1"><strong>Class Teacher:</strong> ' . htmlspecialchars($class_teacher_remark) . '</p>
                <p class="mb-0"><strong>Principal:</strong> ' . htmlspecialchars($principal_remark) . '</p>
            </div>
            <footer>
                <table class="table table-borderless table-sm mb-1 w-100">
                    <tr>
                        <td><strong>Closing Date:</strong> 15 Nov 2025</td>
                        <td><strong>Next Opening:</strong> 5 Jan 2026</td>
                        <td><strong>Fees Balance:</strong> Ksh 12,500</td>
                        <td><strong>Next Term Fees:</strong> Ksh 45,000</td>
                    </tr>
                </table>
                <p class="text-end mb-0">
                    <strong>Principal’s Sign:</strong>
                    <img src="' . ($school['logo'] ? htmlspecialchars($school['logo']) : './assets/images/school-logo.png') . '" alt="Principal\'s Signature" style="max-height:40px; vertical-align:middle;">
                </p>
            </footer>
        </div>';

        $response = [
            'status' => 'success',
            'html' => $html,
            'exam_names' => $exam_names,
            'chart_data' => [
                'labels' => $chart_labels,
                'data' => $chart_data
            ]
        ];
        echo json_encode($response);
    } catch (Exception $e) {
        $response['message'] = 'Server error: ' . $e->getMessage();
        echo json_encode($response);
    }
    ob_end_flush();
    exit;
    break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
        break;
}

ob_end_flush();
?>
