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

    // Construct full term label (e.g., "Form 1 Term 1")
    $full_term = $class_name && $term ? "$class_name $term" : $term;
} else {
    $full_term = $term;
}

// Define terms for graph and table
$terms = ['Form 1 Term 1', 'Form 1 Term 2', 'Form 1 Term 3', 'Form 2 Term 1', 'Form 2 Term 2', 'Form 2 Term 3', 'Form 3 Term 1', 'Form 3 Term 2', 'Form 3 Term 3', 'Form 4 Term 1', 'Form 4 Term 2', 'Form 4 Term 3'];

// Fetch students in the stream
$students = [];
if ($class_id && $term && $stream_id) {
    $stmt = $conn->prepare("
        SELECT student_id, full_name, admission_no, profile_picture,kcpe_score, kcpe_grade, stream_id,kcpe_year
        FROM students
        WHERE stream_id = ? AND class_id = ? AND school_id = ? AND deleted_at IS NULL
        ORDER BY full_name
    ");
    $stmt->bind_param("iii", $stream_id, $class_id, $school_id);
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Fetch historical data for all students
$historical_scores = [];
$forms = ['Form 1', 'Form 2', 'Form 3', 'Form 4'];
$term_numbers = ['Term 1', 'Term 2', 'Term 3'];
foreach ($students as $student) {
    $student_id = $student['student_id'];
    $historical_scores[$student_id] = [];
    foreach ($forms as $form) {
        foreach ($term_numbers as $term_number) {
            $term_label = "$form $term_number";
            $class_stmt = $conn->prepare("SELECT class_id FROM classes WHERE form_name = ? AND school_id = ?");
            $class_stmt->bind_param("si", $form, $school_id);
            $class_stmt->execute();
            $class_result = $class_stmt->get_result()->fetch_assoc();
            $class_stmt->close();
            $form_class_id = $class_result['class_id'] ?? null;

            if ($form_class_id) {
                $exam_stmt = $conn->prepare("
                    SELECT exam_id, min_subjects, grading_system_id
                    FROM exams
                    WHERE school_id = ? AND class_id = ? AND term = ? AND status = 'closed'
                    AND EXISTS (SELECT 1 FROM results r WHERE r.exam_id = exams.exam_id AND r.status = 'confirmed')
                ");
                $exam_stmt->bind_param("iis", $school_id, $form_class_id, $term_number);
                $exam_stmt->execute();
                $exams_historical = $exam_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $exam_stmt->close();

                $num_exams_historical = count($exams_historical);
                $min_subjects_historical = $exams_historical ? ($exams_historical[0]['min_subjects'] ?? 7) : 7;
                $grading_system_id = $exams_historical ? $exams_historical[0]['grading_system_id'] : 1;

                $subject_scores = [];
                if ($exams_historical) {
                    foreach ($exams_historical as $exam) {
                        $exam_id = $exam['exam_id'];
                        $stmt = $conn->prepare("
                            SELECT es.subject_id, es.use_papers, cs.type
                            FROM exam_subjects es
                            JOIN class_subjects cs ON es.subject_id = cs.subject_id AND cs.class_id = ?
                            WHERE es.exam_id = ?
                        ");
                        $stmt->bind_param("ii", $form_class_id, $exam_id);
                        $stmt->execute();
                        $subjects_result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                        $stmt->close();

                        $exam_subjects = [];
                        foreach ($subjects_result as $subj) {
                            $exam_subjects[$subj['subject_id']] = $subj;
                        }

                        $subject_papers = [];
                        foreach ($exam_subjects as $subject_id => $subject) {
                            if ($subject['use_papers']) {
                                $stmt = $conn->prepare("
                                    SELECT esp.paper_id, esp.max_score, sp.contribution_percentage
                                    FROM exam_subjects_papers esp
                                    JOIN subject_papers sp ON esp.paper_id = sp.paper_id
                                    WHERE esp.exam_id = ? AND esp.subject_id = ?
                                ");
                                $stmt->bind_param("ii", $exam_id, $subject_id);
                                $stmt->execute();
                                $subject_papers[$subject_id] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                                $stmt->close();
                            } else {
                                $subject_papers[$subject_id] = [['paper_id' => null, 'max_score' => 100, 'contribution_percentage' => 100]];
                            }
                        }

                        $stmt = $conn->prepare("
                            SELECT exam_id, subject_id, paper_id, score
                            FROM results
                            WHERE exam_id = ? AND student_id = ? AND status = 'confirmed' AND score IS NOT NULL
                        ");
                        $stmt->bind_param("ii", $exam_id, $student_id);
                        $stmt->execute();
                        $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                        $stmt->close();

                        foreach ($exam_subjects as $subject_id => $subject) {
                            $subject_score_sum = 0;
                            $exam_count = 0;
                            foreach ($exams_historical as $exam_h) {
                                $subject_score = 0;
                                if ($subject['use_papers']) {
                                    foreach ($subject_papers[$subject_id] as $paper) {
                                        $paper_id = $paper['paper_id'];
                                        $max_score = $paper['max_score'] ?? 100;
                                        $contribution = $paper['contribution_percentage'] ?? 100;
                                        $paper_score = null;
                                        foreach ($results as $result) {
                                            if ($result['exam_id'] == $exam_h['exam_id'] && $result['subject_id'] == $subject_id && $result['paper_id'] == $paper_id) {
                                                $paper_score = $result['score'];
                                                break;
                                            }
                                        }
                                        if ($paper_score !== null && $max_score > 0) {
                                            $subject_score += ($paper_score / $max_score) * ($contribution / 100) * 100;
                                        }
                                    }
                                } else {
                                    foreach ($results as $result) {
                                        if ($result['exam_id'] == $exam_h['exam_id'] && $result['subject_id'] == $subject_id && $result['paper_id'] === null) {
                                            $subject_score = $result['score'] ?? 0;
                                            break;
                                        }
                                    }
                                }
                                if ($subject_score > 0) {
                                    $subject_score_sum += $subject_score;
                                    $exam_count++;
                                }
                            }
                            if ($exam_count > 0) {
                                $mean_score = $subject_score_sum / $num_exams_historical;
                                $grade_info = getGradeAndPoints($conn, $mean_score, $grading_system_id);
                                $subject_scores[$subject_id] = [
                                    'mean_score' => $mean_score,
                                    'points' => $grade_info['points'],
                                    'type' => $subject['type']
                                ];
                            }
                        }
                    }

                    $selected_subjects = array_filter($subject_scores, function($subj) {
                        return $subj['mean_score'] > 0;
                    });

                    if (!empty($exam_subjects) && $exam_subjects[array_key_first($exam_subjects)]['use_papers']) {
                        $compulsory_subjects = array_filter($selected_subjects, function($subj) {
                            return $subj['type'] === 'compulsory';
                        });
                        $elective_subjects = array_filter($selected_subjects, function($subj) {
                            return $subj['type'] === 'elective';
                        });
                        usort($elective_subjects, function($a, $b) {
                            return $b['points'] <=> $a['points'];
                        });
                        $top_electives = array_slice($elective_subjects, 0, 2);
                        $compulsory_subjects = array_slice($compulsory_subjects, 0, 5);
                        $selected_subjects = array_merge($compulsory_subjects, $top_electives);
                    } else {
                        usort($selected_subjects, function($a, $b) {
                            return $b['points'] <=> $a['points'];
                        });
                        $selected_subjects = array_slice($selected_subjects, 0, $min_subjects_historical);
                    }

                    $total_points = array_sum(array_column($selected_subjects, 'points'));
                    $m_score = count($selected_subjects) > 0 ? $total_points / $min_subjects_historical : 0;

                    // Calculate position
                    $stmt = $conn->prepare("
                        SELECT student_id, AVG(score) as avg_score
                        FROM results
                        WHERE exam_id IN (" . implode(',', array_column($exams_historical, 'exam_id')) . ") AND status = 'confirmed' AND score IS NOT NULL
                        GROUP BY student_id
                        ORDER BY avg_score DESC
                    ");
                    $stmt->execute();
                    $rankings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmt->close();
                    $position = 0;
                    $total_students = count($rankings);
                    foreach ($rankings as $index => $rank) {
                        if ($rank['student_id'] == $student_id) {
                            $position = $index + 1;
                            break;
                        }
                    }

                    $historical_scores[$student_id][$term_label] = [
                        'm_score' => round($m_score, 2),
                        'points' => $total_points,
                        'position' => $position ?: '-',
                        'total_students' => $total_students ?: '-'
                    ];
                }
            }
        }
        error_log('Historical scores for student ' . $student_id . ': ' . json_encode($historical_scores[$student_id]));
    }
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
$num_exams = count($exams);
$min_subjects = $exams ? ($exams[0]['min_subjects'] ?? 7) : 7;

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

// Function to get remarks with fallback
function getRemark($conn, $score, $category, $school_id) {
    $stmt = $conn->prepare("
        SELECT remark_text
        FROM remarks_rules
        WHERE (school_id = ? OR school_id IS NULL) AND category = ?
        AND ? >= min_score AND ? <= max_score
        LIMIT 1
    ");
    $stmt->bind_param("isdd", $school_id, $category, $score, $score);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$result) {
        $fallback = $conn->prepare("
            SELECT remark_text
            FROM remarks_rules
            WHERE (school_id = ? OR school_id IS NULL) AND category = ?
            ORDER BY min_score ASC LIMIT 1
        ");
        $fallback->bind_param("is", $school_id, $category);
        $fallback->execute();
        $result = $fallback->get_result()->fetch_assoc();
        $fallback->close();
        return $result['remark_text'] ?? "No remark available.";
    }
    return $result['remark_text'];
}

// Fetch subjects and calculate aggregates for current term
$subjects = [];
$student_aggregates = [];
if ($exams && $stream_id) {
    foreach ($students as $student) {
        $student_id = $student['student_id'];
        $subject_scores = [];
        $total_points = 0;
        $total_marks = 0;

        // Fetch subjects for the exams
        $exam_subjects = [];
        foreach ($exams as $exam) {
            $exam_id = $exam['exam_id'];
            $stmt = $conn->prepare("
                SELECT es.subject_id, es.use_papers, cs.type, s.name
                FROM exam_subjects es
                JOIN class_subjects cs ON es.subject_id = cs.subject_id AND cs.class_id = ?
                JOIN subjects s ON es.subject_id = s.subject_id
                WHERE es.exam_id = ?
            ");
            $stmt->bind_param("ii", $class_id, $exam_id);
            $stmt->execute();
            $subjects_result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            foreach ($subjects_result as $subj) {
                $exam_subjects[$subj['subject_id']] = $subj;
                $subjects[$subj['subject_id']] = $subj['name'];
            }
        }

        // Fetch subject papers
        $subject_papers = [];
        foreach ($exam_subjects as $subject_id => $subject) {
            if ($subject['use_papers']) {
                $stmt = $conn->prepare("
                    SELECT esp.paper_id, sp.paper_name, esp.max_score, sp.contribution_percentage
                    FROM exam_subjects_papers esp
                    JOIN subject_papers sp ON esp.paper_id = sp.paper_id
                    WHERE esp.exam_id IN (" . implode(',', array_column($exams, 'exam_id')) . ") AND esp.subject_id = ?
                ");
                $stmt->bind_param("i", $subject_id);
                $stmt->execute();
                $subject_papers[$subject_id] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
            } else {
                $subject_papers[$subject_id] = [['paper_id' => null, 'paper_name' => '', 'max_score' => 100, 'contribution_percentage' => 100]];
            }
        }

        // Fetch results for all exams
        $stmt = $conn->prepare("
            SELECT r.exam_id, r.subject_id, r.paper_id, r.score, r.subject_teacher_remark_text, u.first_name, u.other_names
            FROM results r
            LEFT JOIN teacher_subjects ts ON r.subject_id = ts.subject_id AND ts.class_id = r.class_id AND ts.stream_id = r.stream_id
            LEFT JOIN users u ON ts.user_id = u.user_id
            WHERE r.exam_id IN (" . implode(',', array_column($exams, 'exam_id')) . ") AND r.student_id = ? AND r.status = 'confirmed' AND r.score IS NOT NULL
        ");
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Calculate mean score per subject
        foreach ($exam_subjects as $subject_id => $subject) {
            $subject_score_sum = 0;
            $exam_count = 0;
            $subject_remarks = [];
            $teacher_name = '';
            $papers = $subject_papers[$subject_id] ?? [];

            foreach ($exams as $exam) {
                $exam_id = $exam['exam_id'];
                $grading_system_id = $exam['grading_system_id'];
                $subject_score = 0;

                if ($subject['use_papers']) {
                    foreach ($papers as $paper) {
                        $paper_id = $paper['paper_id'];
                        $max_score = $paper['max_score'] ?? 100;
                        $contribution = $paper['contribution_percentage'] ?? 100;
                        $paper_score = null;
                        foreach ($results as $result) {
                            if ($result['exam_id'] == $exam_id && $result['subject_id'] == $subject_id && $result['paper_id'] == $paper_id) {
                                $paper_score = $result['score'];
                                $subject_remarks[$exam_id] = $result['subject_teacher_remark_text'] ?? '';
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
                        if ($result['exam_id'] == $exam_id && $result['subject_id'] == $subject_id && $result['paper_id'] === null) {
                            $subject_score = $result['score'] ?? 0;
                            $subject_remarks[$exam_id] = $result['subject_teacher_remark_text'] ?? '';
                            $teacher_name = $result['first_name'] . ' ' . ($result['other_names'] ?? '');
                            break;
                        }
                    }
                }

                if ($subject_score > 0) {
                    $subject_score_sum += $subject_score;
                    $exam_count++;
                }
            }

            if ($exam_count > 0) {
                $mean_score = $subject_score_sum / $num_exams;
                $grade_info = getGradeAndPoints($conn, $mean_score, $exams[0]['grading_system_id']);
                $stmt = $conn->prepare("
                    SELECT remark_text
                    FROM remarks_rules
                    WHERE (school_id = ? OR school_id IS NULL) AND category = 'subject_teacher'
                    AND ? >= min_score AND ? <= max_score
                    LIMIT 1
                ");
                $stmt->bind_param("idd", $school_id, $mean_score, $mean_score);
                $stmt->execute();
                $result = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $subject_remarks_mean = $result['remark_text'] ?? getRemark($conn, $mean_score, 'subject_teacher', $school_id);

                $subject_scores[$subject_id]['mean_score'] = round($mean_score, 1);
                $subject_scores[$subject_id]['mean_grade'] = $grade_info['grade'];
                $subject_scores[$subject_id]['mean_points'] = $grade_info['points'];
                $subject_scores[$subject_id]['mean_remarks'] = $subject_remarks_mean;
                $subject_scores[$subject_id]['type'] = $subject['type'];
                $subject_scores[$subject_id]['teacher'] = $teacher_name;
                foreach ($exams as $exam) {
                    $subject_scores[$subject_id]['exams'][$exam['exam_id']] = [
                        'score' => isset($subject_remarks[$exam['exam_id']]) ? round($subject_score, 1) : '-',
                        'grade' => isset($subject_remarks[$exam['exam_id']]) ? getGradeAndPoints($conn, $subject_score, $exam['grading_system_id'])['grade'] : '-',
                        'points' => isset($subject_remarks[$exam['exam_id']]) ? getGradeAndPoints($conn, $subject_score, $exam['grading_system_id'])['points'] : 0,
                        'remarks' => $subject_remarks[$exam['exam_id']] ?? '-',
                        'teacher' => $teacher_name
                    ];
                }
            }
        }

        // Select subjects for aggregate
        $selected_subjects = array_filter($subject_scores, function($subj) {
            return isset($subj['mean_score']) && $subj['mean_score'] > 0;
        });

        if (!empty($exam_subjects) && $exam_subjects[array_key_first($exam_subjects)]['use_papers']) {
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
        } else {
            usort($selected_subjects, function($a, $b) {
                return $b['mean_points'] <=> $a['mean_points'];
            });
            $selected_subjects = array_slice($selected_subjects, 0, $min_subjects);
        }

        $total_marks = array_sum(array_column($selected_subjects, 'mean_score'));
        $total_points = array_sum(array_column($selected_subjects, 'mean_points'));
        $num_subjects = count($selected_subjects);
        $average_marks = $num_subjects > 0 ? $total_marks / $min_subjects : 0;
        $m_score = $num_subjects > 0 ? $total_points / $min_subjects : 0;
        $grade_info = getGradeAndPoints($conn, $m_score, $exams[0]['grading_system_id'], true);

        // Update historical scores with current term data
        if ($full_term && $m_score > 0) {
            $historical_scores[$student_id][$full_term] = [
                'm_score' => round($m_score, 2),
                'points' => $total_points,
                'position' => $student_aggregates[$student_id]['class_rank'] ?? '-',
                'total_students' => $class_total_students ?? '-'
            ];
        }

        // Fetch remarks with fallback
        $remarks = [
            'class_teacher' => getRemark($conn, $average_marks, 'class_teacher', $school_id),
            'principal' => getRemark($conn, $average_marks, 'principal', $school_id)
        ];

        $student_aggregates[$student_id] = [
            'student' => [
                'student_id' => $student['student_id'],
                'full_name' => $student['full_name'],
                'admission_no' => $student['admission_no'],
                'profile_picture' => $student['profile_picture'],
                'kcpe_grade' => $student['kcpe_grade'],
                'kcpe_score' => $student['kcpe_score'],
                'kcpe_year' => $student['kcpe_year'],
                'stream_id' => $student['stream_id']
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
        SELECT s.student_id, AVG(r.score) as avg_score
        FROM students s
        JOIN results r ON s.student_id = r.student_id
        WHERE s.class_id = ? AND s.school_id = ? AND r.exam_id IN (
            SELECT exam_id FROM exams WHERE term = ? AND class_id = ? AND status = 'closed'
        ) AND r.status = 'confirmed' AND r.score IS NOT NULL
        GROUP BY s.student_id
    ");
    $stmt->bind_param("iisi", $class_id, $school_id, $term, $class_id);
    $stmt->execute();
    $class_results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($class_results as $result) {
        $class_total += $result['avg_score'];
        $class_count++;
    }
    $class_mean = $class_count > 0 ? $class_total / $class_count : 0;
    $class_grade = getGradeAndPoints($conn, $class_mean, $exams[0]['grading_system_id'])['grade'];

    $stmt = $conn->prepare("
        SELECT s.student_id, AVG(r.score) as avg_score
        FROM students s
        JOIN results r ON s.student_id = r.student_id
        WHERE s.stream_id = ? AND s.class_id = ? AND s.school_id = ? AND r.exam_id IN (
            SELECT exam_id FROM exams WHERE term = ? AND class_id = ? AND status = 'closed'
        ) AND r.status = 'confirmed' AND r.score IS NOT NULL
        GROUP BY s.student_id
    ");
    $stmt->bind_param("iiisi", $stream_id, $class_id, $school_id, $term, $class_id);
    $stmt->execute();
    $stream_results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($stream_results as $result) {
        $stream_total += $result['avg_score'];
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
}


// Calculate deviation for the current term
$deviation = 'N/A';
$student_id = $student['student_id'];
$current_m_score = $agg['m_score'];
$previous_term = null;

// Find the previous term in the sequence
$term_index = array_search($full_term, $terms);
if ($term_index !== false && $term_index > 0) {
    $previous_term = $terms[$term_index - 1];
}

if ($previous_term && isset($historical_scores[$student_id][$previous_term]) && $historical_scores[$student_id][$previous_term]['m_score'] > 0) {
    $previous_m_score = $historical_scores[$student_id][$previous_term]['m_score'];
    $deviation = round($current_m_score - $previous_m_score, 2);
    $deviation = $deviation > 0 ? '+' . $deviation : $deviation; // Add + for positive deviation
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
    padding: 11px 15px;
    border-radius: 6px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
    page-break-after: always;
    width: 100%;
    box-sizing: border-box;
}
h2 {
    font-size: 14px;
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
.table {
    table-layout: fixed;
    width: 100%;
}
.table th {
    background: #e9f2ff !important;
    color: #0d47a1;
    text-align: center;
}
.table th:first-child, .table td:first-child {
    width: 20%;
}
.table th:not(:first-child), .table td:not(:first-child) {
    width: calc(80% / <?php echo count($exams) + 3; ?>);
}
.centertext {
    text-align: left;
    margin-left: 0px;
}
.table th, .table td {
    padding: 3px 5px !important;
    font-size: 10px;
    word-wrap: break-word;
    overflow: hidden;
}
.remarks-box {
    border: 1px solid #0d6efd;
    padding: 6px 8px;
    margin-bottom: 6px;
    background: #f1f8ff;
    border-left: 5px solid #0d6efd;
    border-radius: 4px;
    font-size: 10px;
}
.graph-placeholder {
    height: 80px;
    border: 1px dashed #999;
    text-align: center;
    font-size: 10px;
    line-height: 80px;
    color: #555;
    background: #fafafa;
}
footer {
    margin-top: 10px;
    font-size: 10px;
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
    min-width: 0;
}
.student-row .card-body {
    padding: 6px;
    font-size: 10px;
}
@page {
    size: A4;
    margin: 5mm;
}
@media print {
    body {
        background: none;
        margin: 0;
        font-size: 10px;
    }
    .report-container {
        box-shadow: none;
        border-radius: 0;
        max-width: 100%;
        width: 200mm; /* Fit A4 width (210mm - 5mm margins) */
        padding: 5mm;
        page-break-after: always;
        page-break-before: always;
        page-break-inside: avoid;
        box-sizing: border-box;
    }
    .no-print {
        display: none !important;
    }
    .table th, .table td {
        padding: 2px 4px !important;
        font-size: 9px;
        word-wrap: break-word;
        overflow: hidden;
    }
    .student-row {
        display: flex !important;
        flex-wrap: nowrap !important;
        gap: 4px;
    }
    .student-row .card {
        flex: 1;
        min-width: 0;
    }
    .student-row .card-body {
        padding: 4px;
        font-size: 9px;
    }
    .remarks-box {
        padding: 4px 6px;
        font-size: 9px;
    }
    .graph-placeholder {
        height: 60px;
        font-size: 9px;
        line-height: 60px;
        border: 1px dashed #999;
        background: #fafafa;
    }
    footer {
        position: relative;
        font-size: 9px;
        padding-top: 3px;
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
        $max_total = $min_subjects * 100;
        // Log M.Score and term for debugging
        error_log('Student ' . $student['student_id'] . ': M.Score=' . $agg['m_score'] . ', Full Term=' . $full_term);
        ?>
        <div class="report-container" style="font-size: 12px;">
            <!-- Header -->
            <div class="header">
                <img src="<?php echo htmlspecialchars($school_logo); ?>" alt="Logo"><br>
                <h2><?php echo htmlspecialchars($school['name']); ?></h2>
                <p class="mb-0">Progress Report for <?php echo htmlspecialchars($full_term); ?> - Year 2025</p>
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
                       <p class="mb-0"><strong>Deviation:</strong> <?php echo $deviation; ?></p>
                      
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
                <strong>KCPE Score:</strong> <?php echo htmlspecialchars($student['kcpe_score']); ?> (<?php echo htmlspecialchars($student['kcpe_grade']); ?>)

                
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
                <tbody >
                    <?php
                    $total_marks = $agg['total_marks'];
                    foreach ($agg['subjects'] as $subject_id => $data):
                        if (isset($data['mean_score']) && $data['mean_score'] > 0):
                    ?>
                        <tr>
                            <td class="centertext" ><?php echo htmlspecialchars($subjects[$subject_id]); ?></td>
                            <?php foreach ($exams as $exam): ?>
                                <td><?php echo isset($data['exams'][$exam['exam_id']]) ? $data['exams'][$exam['exam_id']]['score'] : '-'; ?></td>
                            <?php endforeach; ?>
                            <td><?php echo $data['mean_score']; ?></td>
                            <td><?php echo $data['mean_grade']; ?></td>
                            <td class="centertext"><?php echo htmlspecialchars($data['mean_remarks']); ?></td>
                            <td class="centertext" ><?php echo htmlspecialchars($data['teacher'] ?? 'N/A'); ?></td>
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
                        <?php $chart_id = 'progressChart_' . $student['student_id'] . '_' . uniqid(); ?>
                        <canvas id="<?php echo $chart_id; ?>" width="400" height="250"></canvas>
                        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
                       <script>
    (function() {
        console.log('Attempting to render chart for student <?php echo $student['student_id']; ?>, canvas ID: <?php echo $chart_id; ?>');
        const canvas = document.getElementById('<?php echo $chart_id; ?>');
        if (!canvas) {
            console.error('Canvas element not found for ID: <?php echo $chart_id; ?>');
            return;
        }
        const ctx = canvas.getContext('2d');
        if (!ctx) {
            console.error('Failed to get 2D context for canvas ID: <?php echo $chart_id; ?>');
            return;
        }
        const historicalScores = <?php echo json_encode($historical_scores[$student['student_id']]); ?>;
        const terms = <?php echo json_encode($terms); ?>;
        const data = terms.map(term => historicalScores[term] && historicalScores[term].m_score > 0 ? historicalScores[term].m_score : null);
        console.log('Chart data for student <?php echo $student['student_id']; ?>:', data, 'Current term:', '<?php echo addslashes($full_term); ?>', 'M.Score:', <?php echo $agg['m_score'] > 0 ? $agg['m_score'] : 'null'; ?>);
        if (data.every(val => val === null)) {
            console.warn('No valid M.Score data for chart for student <?php echo $student['student_id']; ?>');
            canvas.parentElement.innerHTML = '<div class="graph-placeholder">No data available for graph</div>';
            return;
        }
        try {
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: ['F1 T1', 'F1 T2', 'F1 T3', 'F2 T1', 'F2 T2', 'F2 T3', 'F3 T1', 'F3 T2', 'F3 T3', 'F4 T1', 'F4 T2', 'F4 T3'],
                    datasets: [{
                        label: 'Mean Score',
                        data: data,
                        borderColor: 'blue',
                        backgroundColor: 'rgba(0, 123, 255, 0.2)',
                        fill: true, // Enable fill under the line
                        tension: 0.3,
                        pointRadius: 4,
                        spanGaps: true // Connect points across null values
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
            console.log('Chart rendered successfully for student <?php echo $student['student_id']; ?>');
        } catch (error) {
            console.error('Error rendering chart for student <?php echo $student['student_id']; ?>:', error);
        }
    })();
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
    
<?php include __DIR__ . '/../footer.php'; ?>