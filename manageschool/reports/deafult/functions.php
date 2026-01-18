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
        
    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
        break;
}

ob_end_flush();
?>
