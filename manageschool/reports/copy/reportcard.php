<?php
// reports/reportcard.php
session_start();
ob_start();
require __DIR__ . '/../../connection/db.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['school_id']) || !isset($_SESSION['role_id'])) {
    header("Location: ../../login.php");
    exit;
}

$school_id = $_SESSION['school_id'];

// Fetch school details
$stmt = $conn->prepare("SELECT name, logo FROM schools WHERE school_id = ?");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$school = $stmt->get_result()->fetch_assoc();
$school_logo = $school['logo'] ? "../logos/{$school['logo']}" : "../logos/school-logo.png";
$stmt->close();

// Fetch form, term, and stream from POST
$class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
$term = isset($_POST['term']) ? $_POST['term'] : '';
$stream_id = isset($_POST['stream_id']) ? (int)$_POST['stream_id'] : 0;

// Fetch students in the stream
$students = [];
if ($class_id && $term && $stream_id) {
    $stmt = $conn->prepare("
        SELECT student_id, full_name, admission_no, profile_picture, kcpe_grade, stream_id
        FROM students
        WHERE stream_id = ? AND class_id = ? AND school_id = ? AND deleted_at IS NULL
        ORDER BY full_name
    ");
    $stmt->bind_param("iii", $stream_id, $class_id, $school_id);
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Fetch class and stream details
$class_name = '';
$stream_name = '';
if ($class_id && $stream_id) {
    $stmt = $conn->prepare("SELECT form_name FROM classes WHERE class_id = ? AND school_id = ?");
    $stmt->bind_param("ii", $class_id, $school_id);
    $stmt->execute();
    $class = $stmt->get_result()->fetch_assoc();
    $class_name = $class['form_name'] ?? '';
    $stmt->close();

    $stmt = $conn->prepare("SELECT stream_name FROM streams WHERE stream_id = ? AND school_id = ?");
    $stmt->bind_param("ii", $stream_id, $school_id);
    $stmt->execute();
    $stream = $stmt->get_result()->fetch_assoc();
    $stream_name = $stream['stream_name'] ?? '';
    $stmt->close();
}

// Fetch exams for the selected term and class
$exams = [];
if ($class_id && $term) {
    $stmt = $conn->prepare("
        SELECT exam_id, exam_name, min_subjects, grading_system_id
        FROM exams
        WHERE school_id = ? AND class_id = ? AND term = ? AND status = 'closed'
        AND EXISTS (SELECT 1 FROM results r WHERE r.exam_id = exams.exam_id AND r.status = 'confirmed')
        ORDER BY created_at
    ");
    $stmt->bind_param("iis", $school_id, $class_id, $term);
    $stmt->execute();
    $exams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Function to get grade and points
function getGradeAndPoints($conn, $value, $grading_system_id, $use_points = false) {
    if ($use_points) {
        $stmt = $conn->prepare("
            SELECT grade, points
            FROM grading_rules
            WHERE grading_system_id = ? AND points = ?
            ORDER BY points DESC LIMIT 1
        ");
        $stmt->bind_param("ii", $grading_system_id, $value);
    } else {
        $stmt = $conn->prepare("
            SELECT grade, points
            FROM grading_rules
            WHERE grading_system_id = ? AND ? >= min_score AND ? <= max_score
            ORDER BY ABS(? - (min_score + max_score)/2) ASC LIMIT 1
        ");
        $stmt->bind_param("iddd", $grading_system_id, $value, $value, $value);
    }
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$result && $value > 0) {
        $fallback = $conn->prepare("
            SELECT grade, points 
            FROM grading_rules 
            WHERE grading_system_id = ? AND grade NOT IN ('X', 'Y') 
            ORDER BY min_score ASC LIMIT 1
        ");
        $fallback->bind_param("i", $grading_system_id);
        $fallback->execute();
        $fallback_result = $fallback->get_result()->fetch_assoc();
        $fallback->close();
        return $fallback_result ? ['grade' => $fallback_result['grade'], 'points' => $fallback_result['points']] : ['grade' => 'E', 'points' => 1];
    }
    return [
        'grade' => $result ? $result['grade'] : ($value > 0 ? 'E' : 'N/A'),
        'points' => $result ? $result['points'] : ($value > 0 ? 1 : 0)
    ];
}

// Fetch subjects and calculate aggregates
$subjects = [];
$student_aggregates = [];
if ($exams && $stream_id) {
    foreach ($students as $student) {
        $student_id = $student['student_id'];
        $subject_scores = [];
        $total_points = 0;
        $total_marks = 0;
        $num_subjects = 0;

        foreach ($exams as $exam) {
            $exam_id = $exam['exam_id'];
            $min_subjects = $exam['min_subjects'] ?? 7;
            $grading_system_id = $exam['grading_system_id'];

            // Fetch subjects for the exam
            $stmt = $conn->prepare("
                SELECT es.subject_id, es.use_papers, cs.type, s.name
                FROM exam_subjects es
                JOIN class_subjects cs ON es.subject_id = cs.subject_id AND cs.class_id = ?
                JOIN subjects s ON es.subject_id = s.subject_id
                WHERE es.exam_id = ?
            ");
            $stmt->bind_param("ii", $class_id, $exam_id);
            $stmt->execute();
            $exam_subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            // Fetch subject papers
            $subject_papers = [];
            foreach ($exam_subjects as $subject) {
                $subject_id = $subject['subject_id'];
                if ($subject['use_papers']) {
                    $stmt = $conn->prepare("
                        SELECT esp.paper_id, sp.paper_name, esp.max_score, sp.contribution_percentage
                        FROM exam_subjects_papers esp
                        JOIN subject_papers sp ON esp.paper_id = sp.paper_id
                        WHERE esp.exam_id = ? AND esp.subject_id = ?
                    ");
                    $stmt->bind_param("ii", $exam_id, $subject_id);
                    $stmt->execute();
                    $subject_papers[$subject_id] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmt->close();
                } else {
                    $subject_papers[$subject_id] = [['paper_id' => null, 'paper_name' => '', 'max_score' => 100, 'contribution_percentage' => 100]];
                }
                $subjects[$subject_id] = $subject['name'];
            }

            // Fetch results
            $stmt = $conn->prepare("
                SELECT r.subject_id, r.paper_id, r.score, r.subject_teacher_remark_text, u.first_name, u.other_names
                FROM results r
                LEFT JOIN teacher_subjects ts ON r.subject_id = ts.subject_id AND ts.class_id = r.class_id AND ts.stream_id = r.stream_id
                LEFT JOIN users u ON ts.user_id = u.user_id
                WHERE r.exam_id = ? AND r.student_id = ? AND r.status = 'confirmed' AND r.score IS NOT NULL
            ");
            $stmt->bind_param("ii", $exam_id, $student_id);
            $stmt->execute();
            $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            // Calculate subject scores
            foreach ($exam_subjects as $subject) {
                $subject_id = $subject['subject_id'];
                $subject_score = 0;
                $subject_remarks = '';
                $teacher_name = '';
                $papers = $subject_papers[$subject_id] ?? [];

                if ($subject['use_papers']) {
                    foreach ($papers as $paper) {
                        $paper_id = $paper['paper_id'];
                        $max_score = $paper['max_score'] ?? 100;
                        $contribution = $paper['contribution_percentage'] ?? 100;
                        $paper_score = null;
                        foreach ($results as $result) {
                            if ($result['subject_id'] == $subject_id && $result['paper_id'] == $paper_id) {
                                $paper_score = $result['score'];
                                $subject_remarks = $result['subject_teacher_remark_text'] ?? '';
                                $teacher_name = $result['first_name'] . ' ' . ($result['other_names'] ?? '');
                                break;
                            }
                        }
                        if ($paper_score !== null && $max_score > 0) {
                            $subject_score += ($paper_score / $max_score) * ($contribution / 100) * 100;
                        }
                    }
                } else {
                    foreach ($results as $result) {
                        if ($result['subject_id'] == $subject_id && $result['paper_id'] === null) {
                            $subject_score = $result['score'] ?? 0;
                            $subject_remarks = $result['subject_teacher_remark_text'] ?? '';
                            $teacher_name = $result['first_name'] . ' ' . ($result['other_names'] ?? '');
                            break;
                        }
                    }
                }

                $grade_info = getGradeAndPoints($conn, $subject_score, $grading_system_id);
                $subject_scores[$subject_id]['exams'][$exam_id] = [
                    'score' => round($subject_score, 1),
                    'grade' => $grade_info['grade'],
                    'points' => $grade_info['points'],
                    'remarks' => $subject_remarks,
                    'teacher' => $teacher_name
                ];
                $subject_scores[$subject_id]['type'] = $subject['type'];
                $subject_scores[$subject_id]['total_score'] = ($subject_scores[$subject_id]['total_score'] ?? 0) + $subject_score;
                $subject_scores[$subject_id]['count'] = ($subject_scores[$subject_id]['count'] ?? 0) + ($subject_score > 0 ? 1 : 0);
            }
        }

        // Calculate mean scores per subject
        foreach ($subject_scores as $subject_id => &$data) {
            $data['mean_score'] = $data['count'] > 0 ? round($data['total_score'] / $data['count'], 1) : 0;
            $grade_info = getGradeAndPoints($conn, $data['mean_score'], $grading_system_id);
            $data['mean_grade'] = $grade_info['grade'];
            $data['mean_points'] = $grade_info['points'];
            $data['mean_remarks'] = '';
            $stmt = $conn->prepare("
                SELECT remark_text
                FROM remarks_rules
                WHERE school_id IS NULL AND category = 'subject_teacher'
                AND ? >= min_score AND ? <= max_score
                LIMIT 1
            ");
            $stmt->bind_param("dd", $data['mean_score'], $data['mean_score']);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $data['mean_remarks'] = $result['remark_text'] ?? 'N/A';
            $stmt->close();
        }

        // Select subjects for aggregate
        $selected_subjects = array_filter($subject_scores, function($subj) {
            return $subj['mean_score'] > 0;
        });

        if (!empty($exam_subjects) && $exam_subjects[0]['use_papers']) {
            $compulsory_subjects = array_filter($selected_subjects, function($subj) {
                return $subj['type'] === 'compulsory';
            });
            $elective_subjects = array_filter($selected_subjects, function($subj) {
                return $subj['type'] === 'elective';
            });
            usort($elective_subjects, function($a, $b) {
                return $b['mean_points'] <=> $a['mean_points'];
            });
            $top_electives = array_slice($elective_subjects, 0, 2);
            $compulsory_subjects = array_slice($compulsory_subjects, 0, 5);
            $selected_subjects = array_merge($compulsory_subjects, $top_electives);
        }

        $total_marks = array_sum(array_column($selected_subjects, 'mean_score'));
        $total_points = array_sum(array_column($selected_subjects, 'mean_points'));
        $num_subjects = count($selected_subjects);
        $min_subjects = $exams[0]['min_subjects'] ?? 7;
        $average_marks = $num_subjects > 0 ? $total_marks / $min_subjects : 0;
        $m_score = $num_subjects > 0 ? $total_points / $min_subjects : 0;
        $grade_info = getGradeAndPoints($conn, $m_score, $exams[0]['grading_system_id'], true);

        // Fetch remarks
        $remarks = [
            'class_teacher' => '',
            'principal' => ''
        ];
        $stmt = $conn->prepare("
            SELECT remark_text
            FROM remarks_rules
            WHERE school_id IS NULL AND category = 'class_teacher'
            AND ? >= min_score AND ? <= max_score
            LIMIT 1
        ");
        $stmt->bind_param("dd", $average_marks, $average_marks);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $remarks['class_teacher'] = $result['remark_text'] ?? 'N/A';
        $stmt->close();

        $stmt = $conn->prepare("
            SELECT remark_text
            FROM remarks_rules
            WHERE school_id IS NULL AND category = 'principal'
            AND ? >= min_score AND ? <= max_score
            LIMIT 1
        ");
        $stmt->bind_param("dd", $average_marks, $average_marks);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $remarks['principal'] = $result['remark_text'] ?? 'N/A';
        $stmt->close();

        $student_aggregates[$student_id] = [
            'student' => [
                'student_id' => $student['student_id'],
                'full_name' => $student['full_name'],
                'admission_no' => $student['admission_no'],
                'profile_picture' => $student['profile_picture'],
                'kcpe_grade' => $student['kcpe_grade'],
                'stream_id' => $student['stream_id'] // Explicitly include stream_id
            ],
            'subjects' => $subject_scores,
            'total_marks' => round($total_marks, 1),
            'average_marks' => round($average_marks, 1),
            'm_score' => round($m_score, 2),
            'grade' => $grade_info['grade'],
            'remarks' => $remarks
        ];
    }

    // Calculate class and stream means
    $class_total = 0;
    $stream_total = 0;
    $class_count = 0;
    $stream_count = 0;

    $stmt = $conn->prepare("
        SELECT s.student_id, r.score
        FROM students s
        JOIN results r ON s.student_id = r.student_id
        WHERE s.class_id = ? AND s.school_id = ? AND r.exam_id IN (
            SELECT exam_id FROM exams WHERE term = ? AND class_id = ? AND status = 'closed'
        ) AND r.status = 'confirmed' AND r.score IS NOT NULL
    ");
    $stmt->bind_param("iisi", $class_id, $school_id, $term, $class_id);
    $stmt->execute();
    $class_results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($class_results as $result) {
        $class_total += $result['score'];
        $class_count++;
    }
    $class_mean = $class_count > 0 ? $class_total / $class_count : 0;
    $class_grade = getGradeAndPoints($conn, $class_mean, $exams[0]['grading_system_id'])['grade'];

    $stmt = $conn->prepare("
        SELECT s.student_id, r.score
        FROM students s
        JOIN results r ON s.student_id = r.student_id
        WHERE s.stream_id = ? AND s.class_id = ? AND s.school_id = ? AND r.exam_id IN (
            SELECT exam_id FROM exams WHERE term = ? AND class_id = ? AND status = 'closed'
        ) AND r.status = 'confirmed' AND r.score IS NOT NULL
    ");
    $stmt->bind_param("iiisi", $stream_id, $class_id, $school_id, $term, $class_id);
    $stmt->execute();
    $stream_results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($stream_results as $result) {
        $stream_total += $result['score'];
        $stream_count++;
    }
    $stream_mean = $stream_count > 0 ? $stream_total / $stream_count : 0;
    $stream_grade = getGradeAndPoints($conn, $stream_mean, $exams[0]['grading_system_id'])['grade'];

    // Rank students
    usort($student_aggregates, function($a, $b) {
        return $b['m_score'] <=> $a['m_score'];
    });
    $class_rank = 1;
    $prev_m_score = null;
    foreach ($student_aggregates as &$agg) {
        if ($prev_m_score !== null && $agg['m_score'] < $prev_m_score) {
            $class_rank++;
        }
        $agg['class_rank'] = $class_rank;
        $prev_m_score = $agg['m_score'];
    }

    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM students WHERE class_id = ? AND school_id = ? AND deleted_at IS NULL");
    $stmt->bind_param("ii", $class_id, $school_id);
    $stmt->execute();
    $class_total_students = $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM students WHERE stream_id = ? AND school_id = ? AND deleted_at IS NULL");
    $stmt->bind_param("ii", $stream_id, $school_id);
    $stmt->execute();
    $stream_total_students = $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();

    $stream_aggregates = array_filter($student_aggregates, function($agg) use ($stream_id) {
        return $agg['student']['stream_id'] == $stream_id;
    });
    usort($stream_aggregates, function($a, $b) {
        return $b['m_score'] <=> $a['m_score'];
    });
    $stream_rank = 1;
    $prev_m_score = null;
    foreach ($student_aggregates as &$agg) {
        if ($agg['student']['stream_id'] == $stream_id) {
            if ($prev_m_score !== null && $agg['m_score'] < $prev_m_score) {
                $stream_rank++;
            }
            $agg['stream_rank'] = $stream_rank;
            $prev_m_score = $agg['m_score'];
        } else {
            $agg['stream_rank'] = 0;
        }
    }

    // Fetch historical M.Scores for graph
    $historical_scores = [];
    foreach ($students as $student) {
        $student_id = $student['student_id'];
        $historical_scores[$student_id] = [];
        $terms = ['Form 1 Term 1', 'Form 1 Term 2', 'Form 1 Term 3', 'Form 2 Term 1', 'Form 2 Term 2', 'Form 2 Term 3', 'Form 3 Term 1', 'Form 3 Term 2', 'Form 3 Term 3', 'Form 4 Term 1', 'Form 4 Term 2', 'Form 4 Term 3'];
        foreach ($terms as $term_label) {
            [$form, $term_name] = explode(' Term ', $term_label);
            $form_class = str_replace('Form ', '', $form);
            $stmt = $conn->prepare("
                SELECT e.exam_id, e.min_subjects, e.grading_system_id
                FROM exams e
                JOIN classes c ON e.class_id = c.class_id
                WHERE c.form_name = ? AND e.term = ? AND e.status = 'closed'
                AND EXISTS (SELECT 1 FROM results r WHERE r.exam_id = e.exam_id AND r.student_id = ? AND r.status = 'confirmed')
            ");
            $term_num = str_replace('Term ', '', $term_name);
            $stmt->bind_param("sii", $form, $term_num, $student_id);
            $stmt->execute();
            $exam = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $m_score = 0;
            $position = 0;
            $total_students = 0;
            if ($exam) {
                $exam_id = $exam['exam_id'];
                $min_subjects = $exam['min_subjects'] ?? 7;
                $grading_system_id = $exam['grading_system_id'];

                $stmt = $conn->prepare("
                    SELECT r.subject_id, r.score, cs.type
                    FROM results r
                    JOIN exam_subjects es ON r.exam_id = es.exam_id AND r.subject_id = es.subject_id
                    JOIN class_subjects cs ON es.subject_id = cs.subject_id AND cs.class_id = r.class_id
                    WHERE r.exam_id = ? AND r.student_id = ? AND r.status = 'confirmed' AND r.score IS NOT NULL
                ");
                $stmt->bind_param("ii", $exam_id, $student_id);
                $stmt->execute();
                $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();

                $subject_scores = [];
                foreach ($results as $result) {
                    $subject_id = $result['subject_id'];
                    $subject_scores[$subject_id] = [
                        'score' => $result['score'],
                        'type' => $result['type']
                    ];
                }

                $selected_subjects = array_filter($subject_scores, function($subj) {
                    return $subj['score'] > 0;
                });

                if (!empty($selected_subjects)) {
                    $compulsory = array_filter($selected_subjects, function($subj) {
                        return $subj['type'] === 'compulsory';
                    });
                    $electives = array_filter($selected_subjects, function($subj) {
                        return $subj['type'] === 'elective';
                    });
                    usort($electives, function($a, $b) {
                        return $b['score'] <=> $a['score'];
                    });
                    $top_electives = array_slice($electives, 0, 2);
                    $compulsory = array_slice($compulsory, 0, 5);
                    $selected_subjects = array_merge($compulsory, $top_electives);

                    $total_points = 0;
                    foreach ($selected_subjects as $subj) {
                        $grade_info = getGradeAndPoints($conn, $subj['score'], $grading_system_id);
                        $total_points += $grade_info['points'];
                    }
                    $m_score = count($selected_subjects) > 0 ? $total_points / $min_subjects : 0;

                    // Calculate position
                    $stmt = $conn->prepare("
                        SELECT student_id
                        FROM results r
                        WHERE r.exam_id = ? AND r.status = 'confirmed'
                        GROUP BY r.student_id
                        HAVING SUM(CASE WHEN r.score IS NOT NULL THEN (
                            SELECT points FROM grading_rules
                            WHERE grading_system_id = ? AND r.score >= min_score AND r.score <= max_score
                            LIMIT 1
                        ) ELSE 0 END) / ? > ?
                    ");
                    $stmt->bind_param("iidd", $exam_id, $grading_system_id, $min_subjects, $m_score);
                    $stmt->execute();
                    $position = $stmt->get_result()->num_rows + 1;
                    $stmt->close();

                    $stmt = $conn->prepare("SELECT COUNT(DISTINCT student_id) as total FROM results WHERE exam_id = ? AND status = 'confirmed'");
                    $stmt->bind_param("i", $exam_id);
                    $stmt->execute();
                    $total_students = $stmt->get_result()->fetch_assoc()['total'];
                    $stmt->close();
                }
            }
            $historical_scores[$student_id][$term_label] = [
                'm_score' => round($m_score, 2),
                'position' => $position,
                'total_students' => $total_students
            ];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Termly Report Card</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .report-container {
            max-width: 820px;
            margin: 10px auto;
            background: #fff;
            padding: 12px 15px;
            border-radius: 6px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
        }
        h2 {
            font-size: 16px;
            margin-bottom: 2px;
            font-weight: bold;
            color: #0d6efd;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #0d6efd;
            margin-bottom: 6px;
            padding-bottom: 4px;
        }
        .header img {
            width: 100px;
            height: 100px;
            object-fit: contain;
            margin-bottom: 4px;
        }
        .profile-pic {
            width: 65px;
            height: 65px;
            object-fit: cover;
            border-radius: 6px;
            border: 1px solid #ccc;
        }
        .table th {
            background: #e9f2ff !important;
            color: #0d47a1;
            text-align: center;
        }
        .table th, .table td {
            padding: 3px 5px !important;
            vertical-align: middle;
            font-size: 11px;
        }
        .remarks-box {
            border: 1px solid #0d6efd;
            padding: 6px 8px;
            margin-bottom: 6px;
            background: #f1f8ff;
            border-left: 5px solid #0d6efd;
            border-radius: 4px;
            font-size: 12px;
        }
        .graph-placeholder {
            height: 140px;
            border: 1px dashed #999;
            text-align: center;
            font-size: 10px;
            line-height: 140px;
            color: #555;
            background: #fafafa;
        }
        footer {
            margin-top: 10px;
            font-size: 12px;
            border-top: 2px solid #0d6efd;
            padding-top: 5px;
        }
        footer table td {
            padding: 2px 6px;
        }
        .student-row {
            display: flex;
            flex-wrap: nowrap !important;
            gap: 6px;
        }
        .student-row .card {
            flex: 1;
            border: 1px solid #0d6efd;
        }
        .student-row .card-body {
            padding: 6px;
            font-size: 12px;
        }
        @page {
            size: A4;
            margin: 10mm;
        }
        @media print {
            body {
                background: none;
                margin: 0;
                font-size: 12px;
            }
            .report-container {
                box-shadow: none;
                border-radius: 0;
                max-width: 100%;
                padding: 0;
            }
            .student-row {
                display: flex !important;
                flex-wrap: nowrap !important;
            }
            footer {
                position: absolute;
                bottom: 0;
                left: 0;
                right: 0;
            }
            .no-print {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    <!-- Download and Print Buttons -->
    <div class="text-end mb-2 no-print">
        <button class="btn btn-primary btn-sm me-2" onclick="window.print()">🖨️ Print Report</button>
        <a href="download_reports.php?class_id=<?php echo $class_id; ?>&term=<?php echo urlencode($term); ?>&stream_id=<?php echo $stream_id; ?>" class="btn btn-success btn-sm">📥 Download All Reports</a>
    </div>

    <?php foreach ($student_aggregates as $agg): ?>
        <?php
        $student = $agg['student'];
        $profile_pic = $student['profile_picture'] ? "../studentsprofile/{$student['profile_picture']}" : "../studentsprofile/defaultstudent.png";
        ?>
        <div class="report-container" style="font-size: 12px;">
            <!-- Header -->
            <div class="header">
                <img src="<?php echo htmlspecialchars($school_logo); ?>" alt="Logo"><br>
                <h2><?php echo htmlspecialchars($school['name']); ?></h2>
                <p class="mb-0">Progress Report for <?php echo htmlspecialchars($term); ?> - Year 2025</p>
            </div>

            <!-- Student Info + Performance Row -->
            <div class="student-row mb-2">
                <!-- Student Info -->
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <img src="<?php echo htmlspecialchars($profile_pic); ?>" alt="Student Photo" class="profile-pic me-2">
                            <div>
                                <p class="mb-1"><strong>Name:</strong> <?php echo htmlspecialchars($student['full_name']); ?></p>
                                <p class="mb-1"><strong>Adm No:</strong> <?php echo htmlspecialchars($student['admission_no']); ?></p>
                                <p class="mb-1"><strong>Class:</strong> <?php echo htmlspecialchars($class_name . ' ' . $stream_name); ?></p>
                                <p class="mb-0"><strong>Grade:</strong> <?php echo htmlspecialchars($agg['grade']); ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Performance -->
                <div class="card h-100">
                    <div class="card-body">
                        <p class="mb-1"><strong>Class Position:</strong> <?php echo $agg['class_rank']; ?> out of <?php echo $class_total_students; ?></p>
                        <p class="mb-1"><strong>Stream Position:</strong> <?php echo $agg['stream_rank']; ?> out of <?php echo $stream_total_students; ?></p>
                        <p class="mb-1"><strong>Current Term Avg:</strong> <?php echo $agg['average_marks']; ?>%</p>
                        <p class="mb-0"><strong>Deviation:</strong> N/A</p>
                    </div>
                </div>
            </div>

            <!-- Class Means -->
            <div class="remarks-box">
                <strong>Class Mean:</strong> <?php echo round($class_mean, 2); ?> &nbsp;
                <strong>Class Grade:</strong> <?php echo $class_grade; ?> &nbsp;
                <strong>Stream Mean:</strong> <?php echo round($stream_mean, 2); ?> &nbsp;
                <strong>Stream Grade:</strong> <?php echo $stream_grade; ?> &nbsp;
                <strong>Student Mean:</strong> <?php echo $agg['average_marks']; ?> &nbsp;
                <strong>Student Grade:</strong> <?php echo $agg['grade']; ?>
            </div>

            <!-- Subjects Table -->
            <table class="table table-bordered table-sm text-center align-middle mb-2">
                <thead>
                    <tr>
                        <th>Subject</th>
                        <?php foreach ($exams as $exam): ?>
                            <th><?php echo htmlspecialchars($exam['exam_name']); ?></th>
                        <?php endforeach; ?>
                        <th>Mean %</th>
                        <th>Grade</th>
                        <th>Remarks</th>
                        <th>Subject Teacher</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $total_marks = 0;
                    $max_total = count($exams) * 100 * count($agg['subjects']);
                    foreach ($agg['subjects'] as $subject_id => $data):
                        if ($data['mean_score'] > 0):
                            $total_marks += $data['mean_score'];
                    ?>
                        <tr>
                            <td><?php echo htmlspecialchars($subjects[$subject_id]); ?></td>
                            <?php foreach ($exams as $exam): ?>
                                <td><?php echo isset($data['exams'][$exam['exam_id']]) ? $data['exams'][$exam['exam_id']]['score'] : '-'; ?></td>
                            <?php endforeach; ?>
                            <td><?php echo $data['mean_score']; ?></td>
                            <td><?php echo $data['mean_grade']; ?></td>
                            <td><?php echo htmlspecialchars($data['mean_remarks']); ?></td>
                            <td><?php echo htmlspecialchars($data['exams'][end($exams)['exam_id']]['teacher'] ?? 'N/A'); ?></td>
                        </tr>
                    <?php endif; endforeach; ?>
                    <tr class="table-primary fw-bold">
                        <td>Totals</td>
                        <td colspan="<?php echo count($exams) + 3; ?>">
                            Total Marks: <?php echo $total_marks; ?>/<?php echo $max_total; ?> | Average: <?php echo $agg['average_marks']; ?>%
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- Progress + Graph -->
            <table class="table table-sm table-bordered mb-2 align-middle">
                <tr>
                    <td width="55%">
                        <strong>PROGRESS ANALYSIS</strong><br>
                        Total Marks: <?php echo $total_marks; ?>/<?php echo $max_total; ?> &nbsp; | &nbsp; M.Score: <?php echo $agg['m_score']; ?><br>
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
                                <?php for ($i = 1; $i <= 3; $i++): ?>
                                    <td><?php echo $historical_scores[$student['student_id']]['Form 1 Term ' . $i]['m_score'] ?? '-'; ?></td>
                                <?php endfor; ?>
                                <td><?php echo ($historical_scores[$student['student_id']]['Form 1 Term 3']['position'] ?? '-') . '/' . ($historical_scores[$student['student_id']]['Form 1 Term 3']['total_students'] ?? '-'); ?></td>
                                <?php for ($i = 1; $i <= 3; $i++): ?>
                                    <td><?php echo $historical_scores[$student['student_id']]['Form 2 Term ' . $i]['m_score'] ?? '-'; ?></td>
                                <?php endfor; ?>
                                <td><?php echo ($historical_scores[$student['student_id']]['Form 2 Term 3']['position'] ?? '-') . '/' . ($historical_scores[$student['student_id']]['Form 2 Term 3']['total_students'] ?? '-'); ?></td>
                            </tr>
                            <tr>
                                <td colspan="4">
                                    Mean: <?php
                                    $f1_scores = array_filter([
                                        $historical_scores[$student['student_id']]['Form 1 Term 1']['m_score'] ?? 0,
                                        $historical_scores[$student['student_id']]['Form 1 Term 2']['m_score'] ?? 0,
                                        $historical_scores[$student['student_id']]['Form 1 Term 3']['m_score'] ?? 0
                                    ]);
                                    $f1_mean = count($f1_scores) > 0 ? array_sum($f1_scores) / count($f1_scores) : 0;
                                    echo round($f1_mean, 1);
                                    $f1_grade = getGradeAndPoints($conn, $f1_mean, $exams[0]['grading_system_id'], true)['grade'];
                                    echo " ($f1_grade)";
                                    ?>
                                </td>
                                <td colspan="4">
                                    Mean: <?php
                                    $f2_scores = array_filter([
                                        $historical_scores[$student['student_id']]['Form 2 Term 1']['m_score'] ?? 0,
                                        $historical_scores[$student['student_id']]['Form 2 Term 2']['m_score'] ?? 0,
                                        $historical_scores[$student['student_id']]['Form 2 Term 3']['m_score'] ?? 0
                                    ]);
                                    $f2_mean = count($f2_scores) > 0 ? array_sum($f2_scores) / count($f2_scores) : 0;
                                    echo round($f2_mean, 1);
                                    $f2_grade = getGradeAndPoints($conn, $f2_mean, $exams[0]['grading_system_id'], true)['grade'];
                                    echo " ($f2_grade)";
                                    ?>
                                </td>
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
                                <?php for ($i = 1; $i <= 3; $i++): ?>
                                    <td><?php echo $historical_scores[$student['student_id']]['Form 3 Term ' . $i]['m_score'] ?? '-'; ?></td>
                                <?php endfor; ?>
                                <td><?php echo ($historical_scores[$student['student_id']]['Form 3 Term 3']['position'] ?? '-') . '/' . ($historical_scores[$student['student_id']]['Form 3 Term 3']['total_students'] ?? '-'); ?></td>
                                <?php for ($i = 1; $i <= 3; $i++): ?>
                                    <td><?php echo $historical_scores[$student['student_id']]['Form 4 Term ' . $i]['m_score'] ?? '-'; ?></td>
                                <?php endfor; ?>
                                <td><?php echo ($historical_scores[$student['student_id']]['Form 4 Term 3']['position'] ?? '-') . '/' . ($historical_scores[$student['student_id']]['Form 4 Term 3']['total_students'] ?? '-'); ?></td>
                            </tr>
                            <tr>
                                <td colspan="4">
                                    Mean: <?php
                                    $f3_scores = array_filter([
                                        $historical_scores[$student['student_id']]['Form 3 Term 1']['m_score'] ?? 0,
                                        $historical_scores[$student['student_id']]['Form 3 Term 2']['m_score'] ?? 0,
                                        $historical_scores[$student['student_id']]['Form 3 Term 3']['m_score'] ?? 0
                                    ]);
                                    $f3_mean = count($f3_scores) > 0 ? array_sum($f3_scores) / count($f3_scores) : 0;
                                    echo round($f3_mean, 1);
                                    $f3_grade = getGradeAndPoints($conn, $f3_mean, $exams[0]['grading_system_id'], true)['grade'];
                                    echo " ($f3_grade)";
                                    ?>
                                </td>
                                <td colspan="4">
                                    Mean: <?php
                                    $f4_scores = array_filter([
                                        $historical_scores[$student['student_id']]['Form 4 Term 1']['m_score'] ?? 0,
                                        $historical_scores[$student['student_id']]['Form 4 Term 2']['m_score'] ?? 0,
                                        $historical_scores[$student['student_id']]['Form 4 Term 3']['m_score'] ?? 0
                                    ]);
                                    $f4_mean = count($f4_scores) > 0 ? array_sum($f4_scores) / count($f4_scores) : 0;
                                    echo round($f4_mean, 1);
                                    $f4_grade = getGradeAndPoints($conn, $f4_mean, $exams[0]['grading_system_id'], true)['grade'];
                                    echo " ($f4_grade)";
                                    ?>
                                </td>
                            </tr>
                        </table>
                    </td>
                    <td>
                        <strong>Graphical Analysis</strong><br>
                        <canvas id="progressChart_<?php echo $student['student_id']; ?>" width="400" height="250"></canvas>
                        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
                        <script>
                            const ctx_<?php echo $student['student_id']; ?> = document.getElementById('progressChart_<?php echo $student['student_id']; ?>').getContext('2d');
                            new Chart(ctx_<?php echo $student['student_id']; ?>, {
                                type: 'line',
                                data: {
                                    labels: ['Form 1 T1', 'Form 1 T2', 'Form 1 T3', 'Form 2 T1', 'Form 2 T2', 'Form 2 T3', 'Form 3 T1', 'Form 3 T2', 'Form 3 T3', 'Form 4 T1', 'Form 4 T2', 'Form 4 T3'],
                                    datasets: [{
                                        label: 'Mean Score',
                                        data: [
                                            <?php
                                            $scores = [];
                                            foreach (['Form 1 Term 1', 'Form 1 Term 2', 'Form 1 Term 3', 'Form 2 Term 1', 'Form 2 Term 2', 'Form 2 Term 3', 'Form 3 Term 1', 'Form 3 Term 2', 'Form 3 Term 3', 'Form 4 Term 1', 'Form 4 Term 2', 'Form 4 Term 3'] as $term_label) {
                                                $scores[] = $historical_scores[$student['student_id']][$term_label]['m_score'] ?? 'null';
                                            }
                                            echo implode(',', $scores);
                                            ?>
                                        ],
                                        borderColor: 'blue',
                                        backgroundColor: 'rgba(0, 123, 255, 0.2)',
                                        fill: true,
                                        tension: 0.3,
                                        pointRadius: 4
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    plugins: {
                                        legend: { display: true, position: 'top' }
                                    },
                                    scales: {
                                        y: {
                                            beginAtZero: true,
                                            max: 12,
                                            title: { display: true, text: 'M.Score (1-12)' }
                                        },
                                        x: {
                                            title: { display: true, text: 'Terms' }
                                        }
                                    }
                                }
                            });
                        </script>
                    </td>
                </tr>
            </table>

            <!-- Comments -->
            <div class="remarks-box mb-2">
                <p class="mb-1"><strong>Class Teacher:</strong> <?php echo htmlspecialchars($agg['remarks']['class_teacher']); ?></p>
                <p class="mb-0"><strong>Principal:</strong> <?php echo htmlspecialchars($agg['remarks']['principal']); ?></p>
            </div>

            <!-- Footer -->
            <footer>
                <table class="table table-borderless table-sm mb-1 w-100">
                    <tr>
                        <td><strong>Closing Date:</strong> </td>
                        <td><strong>Next Opening:</strong> </td>
                        <td><strong>Fees Balance:</strong> </td>
                        <td><strong>Next Term Fees:</strong> </td>
                    </tr>
                </table>
                <p class="text-end mb-0">
                    <strong>Principal's Sign:</strong>
                    <img src="manageschool/logos/sighn.png" alt="Principal's Signature" style="max-height:40px; vertical-align:middle;">
                </p>
            </footer>
        </div>
    <?php endforeach; ?>
</body>
</html>