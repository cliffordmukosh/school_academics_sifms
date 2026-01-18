<?php
// reports/examreports/schoolanalysis_perexam.php
session_start();
ob_start();
require __DIR__ . '../../../../connection/db.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['school_id']) || !isset($_SESSION['role_id'])) {
    header("Location: ../../login.php");
    exit;
}

// Check permissions
$role_id = $_SESSION['role_id'];
$school_id = $_SESSION['school_id'];
$stmt = $conn->prepare("
    SELECT p.name 
    FROM role_permissions rp 
    JOIN permissions p ON rp.permission_id = p.permission_id 
    WHERE rp.role_id = ? AND rp.school_id = ? AND p.name = 'view_exam_aggregates'
");
$stmt->bind_param("ii", $role_id, $school_id);
$stmt->execute();
$has_permission = $stmt->get_result()->num_rows > 0;
$stmt->close();

if (!$has_permission) {
    header("Location: ../../unauthorized.php");
    exit;
}

// Initialize variables
$error_message = '';
$school = ['name' => 'N/A', 'logo' => null];
$exam_name = '';
$term = '';
$year = date('Y');
$class_data = [];

// Get form inputs
$year = isset($_POST['year']) ? (int)$_POST['year'] : date('Y');
$exam_name = isset($_POST['exam_name']) ? trim($_POST['exam_name']) : '';
$term = isset($_POST['term']) ? trim($_POST['term']) : '';
$class_ids = isset($_POST['class_ids']) && is_array($_POST['class_ids']) ? array_map('intval', $_POST['class_ids']) : [];

// Validate inputs
if (!$year || !$exam_name || !$term || empty($class_ids)) {
    $error_message = "Error: Year, exam name, term, and at least one class are required.";
}

// Fetch school details
if (!$error_message) {
    $stmt = $conn->prepare("SELECT name, logo FROM schools WHERE school_id = ?");
    $stmt->bind_param("i", $school_id);
    $stmt->execute();
    $school = $stmt->get_result()->fetch_assoc() ?? ['name' => 'N/A', 'logo' => null];
    $stmt->close();
}

// Process school logo path
$school_logo = $school['logo'] ?? '';
if (empty($school_logo)) {
    $school_logo = 'https://academics.sifms.co.ke/manageschool/logos/school-logo.png';
} elseif (strpos($school_logo, 'http') !== 0) {
    // Normalize to correct logo directory
    $school_logo = 'https://academics.sifms.co.ke/manageschool/logos/' . basename($school_logo);
}


// Fetch exams and validate classes
$exams = [];
if (!$error_message) {
    $in_clause = implode(',', array_fill(0, count($class_ids), '?'));
    $stmt = $conn->prepare("
        SELECT exam_id, exam_name, class_id, term, YEAR(created_at) AS year, grading_system_id, min_subjects
        FROM exams 
        WHERE school_id = ? AND exam_name = ? AND term = ? AND YEAR(created_at) = ? 
        AND class_id IN ($in_clause) AND status = 'closed'
    ");
    $params = array_merge([$school_id, $exam_name, $term, $year], $class_ids);
    $types = str_repeat('i', count($class_ids) + 1) . 'ssi';
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $exams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($exams)) {
        $error_message = "Error: No closed exams found for the selected exam name, term, year, and classes.";
    }
}

// Function to get grade and points
function getGradeAndPointsFunc($conn, $value, $grading_system_id, $use_points = false) {
    if ($use_points) {
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

// Process grade analysis for each class
if (!$error_message) {
    foreach ($class_ids as $class_id) {
        $grade_data = [
            'form_name' => 'N/A',
            'streams' => [],
            'gender_analysis' => [
                'Male' => [
                    'entry' => 0,
                    'grades' => ['A' => 0, 'A-' => 0, 'B+' => 0, 'B' => 0, 'B-' => 0, 'C+' => 0, 'C' => 0, 'C-' => 0, 'D+' => 0, 'D' => 0, 'D-' => 0, 'E' => 0, 'X' => 0, 'Y' => 0],
                    'mean_score' => 0,
                    'grade' => ''
                ],
                'Female' => [
                    'entry' => 0,
                    'grades' => ['A' => 0, 'A-' => 0, 'B+' => 0, 'B' => 0, 'B-' => 0, 'C+' => 0, 'C' => 0, 'C-' => 0, 'D+' => 0, 'D' => 0, 'D-' => 0, 'E' => 0, 'X' => 0, 'Y' => 0],
                    'mean_score' => 0,
                    'grade' => ''
                ]
            ],
            'totals' => [
                'entry' => 0,
                'grades' => ['A' => 0, 'A-' => 0, 'B+' => 0, 'B' => 0, 'B-' => 0, 'C+' => 0, 'C' => 0, 'C-' => 0, 'D+' => 0, 'D' => 0, 'D-' => 0, 'E' => 0, 'X' => 0, 'Y' => 0],
                'mean_score' => 0,
                'grade' => ''
            ]
        ];

        $stmt = $conn->prepare("SELECT form_name FROM classes WHERE class_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $class_id, $school_id);
        $stmt->execute();
        $class = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$class) {
            $error_message = "Error: Invalid form selected for class ID $class_id.";
            break;
        }
        $grade_data['form_name'] = $class['form_name'];

        $exam = null;
        foreach ($exams as $e) {
            if ($e['class_id'] == $class_id) {
                $exam = $e;
                break;
            }
        }
        if (!$exam) {
            $error_message = "Error: No exam found for class ID $class_id.";
            break;
        }

        $stmt = $conn->prepare("SELECT stream_id, stream_name FROM streams WHERE school_id = ? AND class_id = ?");
        $stmt->bind_param("ii", $school_id, $class_id);
        $stmt->execute();
        $streams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (empty($streams)) {
            $error_message = "Error: No streams found for class ID $class_id.";
            break;
        }

        $stmt = $conn->prepare("
            SELECT es.subject_id, es.use_papers, cs.type
            FROM exam_subjects es
            JOIN class_subjects cs ON es.subject_id = cs.subject_id AND cs.class_id = ?
            WHERE es.exam_id = ?
        ");
        $stmt->bind_param("ii", $class_id, $exam['exam_id']);
        $stmt->execute();
        $subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (empty($subjects)) {
            $error_message = "Error: No subjects found for the selected exam in class ID $class_id.";
            break;
        }

        $uses_papers = false;
        foreach ($subjects as $subject) {
            if ($subject['use_papers']) {
                $uses_papers = true;
                break;
            }
        }

        $subject_papers = [];
        foreach ($subjects as $subject) {
            if ($subject['use_papers']) {
                $stmt = $conn->prepare("
                    SELECT esp.paper_id, sp.paper_name, esp.max_score, sp.contribution_percentage
                    FROM exam_subjects_papers esp
                    JOIN subject_papers sp ON esp.paper_id = sp.paper_id
                    WHERE esp.exam_id = ? AND esp.subject_id = ?
                ");
                $stmt->bind_param("ii", $exam['exam_id'], $subject['subject_id']);
                $stmt->execute();
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

        $total_class_points = 0;
        $total_class_students = 0;
        $male_points = 0;
        $male_students = 0;
        $female_points = 0;
        $female_students = 0;

        foreach ($streams as $stream) {
            $stream_id = $stream['stream_id'];
            $stream_data = [
                'stream_name' => $stream['stream_name'],
                'entry' => 0,
                'grades' => ['A' => 0, 'A-' => 0, 'B+' => 0, 'B' => 0, 'B-' => 0, 'C+' => 0, 'C' => 0, 'C-' => 0, 'D+' => 0, 'D' => 0, 'D-' => 0, 'E' => 0, 'X' => 0, 'Y' => 0],
                'mean_score' => 0,
                'grade' => ''
            ];

            $stmt = $conn->prepare("
                SELECT student_id, admission_no, full_name, gender
                FROM students
                WHERE stream_id = ? AND class_id = ? AND school_id = ? AND deleted_at IS NULL
            ");
            $stmt->bind_param("iii", $stream_id, $class_id, $school_id);
            $stmt->execute();
            $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            if (empty($students)) {
                $stream_data['entry'] = 0;
                $stream_data['grades']['X'] = 0;
                $grade_data['streams'][$stream_id] = $stream_data;
                continue;
            }

            $stmt = $conn->prepare("
                SELECT r.student_id, r.subject_id, r.paper_id, r.score
                FROM results r
                WHERE r.exam_id = ? AND r.student_id IN (
                    SELECT student_id FROM students WHERE stream_id = ? AND class_id = ? AND school_id = ? AND deleted_at IS NULL
                ) AND r.status = 'confirmed' AND r.score IS NOT NULL AND r.deleted_at IS NULL
            ");
            $stmt->bind_param("iiii", $exam['exam_id'], $stream_id, $class_id, $school_id);
            $stmt->execute();
            $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

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

            $min_subjects = $exam['min_subjects'] ?? 7;
            $total_stream_points = 0;
            $student_count = 0;

            foreach ($students as $student) {
                $student_id = $student['student_id'];
                $gender = $student['gender'];
                $subject_results = $student_results[$student_id] ?? [];

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

                    $grade_info = getGradeAndPointsFunc($conn, $subject_score, $exam['grading_system_id']);
                    $processed_subjects[] = [
                        'subject_id' => $subject_id,
                        'type' => $subject['type'],
                        'score' => $subject_score,
                        'points' => $grade_info['points']
                    ];
                }

                $selected_subjects = array_filter($processed_subjects, function($subj) {
                    return $subj['score'] > 0;
                });

                if ($uses_papers) {
                    $compulsory_subjects = array_filter($processed_subjects, function($subj) {
                        return $subj['type'] === 'compulsory' && $subj['score'] > 0;
                    });
                    $elective_subjects = array_filter($processed_subjects, function($subj) {
                        return $subj['type'] === 'elective' && $subj['score'] > 0;
                    });

                    usort($elective_subjects, function($a, $b) {
                        return $b['points'] <=> $a['points'];
                    });
                    $top_electives = array_slice($elective_subjects, 0, 2);

                    $compulsory_subjects = array_slice($compulsory_subjects, 0, 5);
                    $selected_subjects = array_merge($compulsory_subjects, $top_electives);
                }

                $total_points = array_sum(array_column($selected_subjects, 'points'));
                $num_subjects = count($selected_subjects);
                if ($num_subjects < $min_subjects) {
                    $stream_data['grades']['X']++;
                    $grade_data['gender_analysis'][$gender]['grades']['X']++;
                    continue;
                }
                $mean_points = ($min_subjects > 0) ? $total_points / $min_subjects : 0;
                $grade_info = getGradeAndPointsFunc($conn, $mean_points, $exam['grading_system_id'], true);

                if ($num_subjects > 0) {
                    $stream_data['grades'][$grade_info['grade']]++;
                    $grade_data['gender_analysis'][$gender]['grades'][$grade_info['grade']]++;
                    $total_stream_points += $mean_points;
                    $student_count++;
                    $total_class_points += $mean_points;
                    $total_class_students++;
                    if ($gender === 'Male') {
                        $male_points += $mean_points;
                        $male_students++;
                    } else {
                        $female_points += $mean_points;
                        $female_students++;
                    }
                } else {
                    $stream_data['grades']['X']++;
                    $grade_data['gender_analysis'][$gender]['grades']['X']++;
                }
            }

            $stream_data['entry'] = count($students);
            if ($student_count > 0) {
                $stream_data['mean_score'] = round($total_stream_points / $student_count, 2);
                $stream_data['grade'] = getGradeAndPointsFunc($conn, $stream_data['mean_score'], $exam['grading_system_id'], true)['grade'];
            }
            $grade_data['streams'][$stream_id] = $stream_data;
        }

        $grade_data['totals']['entry'] = array_sum(array_column($grade_data['streams'], 'entry'));
        foreach (['A', 'A-', 'B+', 'B', 'B-', 'C+', 'C', 'C-', 'D+', 'D', 'D-', 'E', 'X', 'Y'] as $grade) {
            $grade_data['totals']['grades'][$grade] = array_sum(array_column(array_map(function($stream) use ($grade) {
                return $stream['grades'][$grade];
            }, $grade_data['streams']), $grade));
        }
        if ($total_class_students > 0) {
            $grade_data['totals']['mean_score'] = round($total_class_points / $total_class_students, 2);
            $grade_data['totals']['grade'] = getGradeAndPointsFunc($conn, $grade_data['totals']['mean_score'], $exam['grading_system_id'], true)['grade'];
        }

        if ($male_students > 0) {
            $grade_data['gender_analysis']['Male']['entry'] = $male_students;
            $grade_data['gender_analysis']['Male']['mean_score'] = round($male_points / $male_students, 2);
            $grade_data['gender_analysis']['Male']['grade'] = getGradeAndPointsFunc($conn, $grade_data['gender_analysis']['Male']['mean_score'], $exam['grading_system_id'], true)['grade'];
        }
        if ($female_students > 0) {
            $grade_data['gender_analysis']['Female']['entry'] = $female_students;
            $grade_data['gender_analysis']['Female']['mean_score'] = round($female_points / $female_students, 2);
            $grade_data['gender_analysis']['Female']['grade'] = getGradeAndPointsFunc($conn, $grade_data['gender_analysis']['Female']['mean_score'], $exam['grading_system_id'], true)['grade'];
        }

        $class_data[$class_id] = $grade_data;
    }
}
?>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<style>
  .schoolanalysis_container {
    max-width: 1400px;
    margin: 10px auto;
    background: #fff;
    padding: 12px 15px;
    border-radius: 6px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
    font-size: 12px;
    page-break-after: always;
    font-family: Arial, Helvetica, sans-serif;
  }

  .schoolanalysis_h2 {
    font-size: 16px;
    margin-bottom: 2px;
    font-weight: bold;
    color: #0d6efd;
  }

  .schoolanalysis_header {
    text-align: center;
    border-bottom: 2px solid #0d6efd;
    margin-bottom: 6px;
    padding-bottom: 4px;
  }

  .schoolanalysis_header img {
    width: 100px;
    height: 100px;
    object-fit: contain;
    margin-bottom: 4px;
  }

  .schoolanalysis_table th {
    background: #e9f2ff !important;
    color: #0d47a1;
    text-align: center;
  }

  .schoolanalysis_table th,
  .schoolanalysis_table td {
    padding: 3px 5px !important;
    vertical-align: middle;
    font-size: 11px;
    border: 1px solid #dee2e6;
  }

  .schoolanalysis_title {
    border: 1px solid #0d6efd;
    padding: 6px 8px;
    margin-bottom: 6px;
    background: #f1f8ff;
    border-left: 5px solid #0d6efd;
    border-radius: 4px;
    font-size: 12px;
    text-align: center;
    font-weight: bold;
  }

  .schoolanalysis_footer {
    margin-top: 10px;
    font-size: 12px;
    border-top: 2px solid #0d6efd;
    padding-top: 5px;
  }

  .schoolanalysis_footer table td {
    padding: 2px 6px;
  }

  tr.totals {
    background: #e8ecef;
    font-weight: bold;
  }

  .error-message {
    text-align: center;
    color: #d32f2f;
    font-weight: bold;
    margin: 20px 0;
    background: #ffe6e6;
    padding: 10px;
    border-radius: 4px;
    font-size: 12px;
  }

  .class-section {
    margin-bottom: 30px;
  }

  .class-section:not(:first-child) {
    page-break-before: always;
  }

  .gender-table {
    margin-top: 20px;
  }

  .footer-signatures {
    margin-top: 50px;
    display: flex;
    justify-content: space-between;
    font-size: 12px;
    color: #333;
  }

  .footer-signatures div {
    width: 45%;
    border-top: 1px solid #ccc;
    padding-top: 10px;
  }

  .footer-signatures b {
    display: block;
    margin-top: 10px;
    text-decoration: underline;
  }

  /* Modal styles */
  .modal-loader .modal-content {
    text-align: center;
  }
  .loader-spinner {
    border: 4px solid #f3f3f3;
    border-top: 4px solid #0d6efd;
    border-radius: 50%;
    width: 40px;
    height: 40px;
    animation: spin 1s linear infinite;
    margin: 20px auto;
  }
  @keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
  }

  @media print {
    body {
      background: none;
      margin: 0;
      font-size: 12px;
    }
    .schoolanalysis_container {
      box-shadow: none;
      border-radius: 0;
      max-width: 100%;
      padding: 10mm;
      margin: 0;
    }
    .schoolanalysis_footer {
      position: relative;
      bottom: 0;
    }
    .no-print {
      display: none !important;
    }
  }

  @page {
    size: A4 landscape;
    margin: 10mm;
  }
</style>

<!-- Sticky Header (hidden in print) -->
<div class="no-print" style="
    display: flex;
    align-items: center;
    justify-content: space-between;
    background-color: #1a1f71; /* navy */
    padding: 10px 20px;
    border-radius: 8px;
    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
    color: #fff;
    margin-bottom: 15px;
    position: sticky;  /* Make it sticky */
    top: 0;            /* Stick to top */
    z-index: 9999;     /* Stay above other content */
">
  <!-- Left: School Logo -->
  <div style="display: flex; align-items: center; gap: 10px;">
    <img src="<?php echo htmlspecialchars($school_logo); ?>" alt="School Logo" 
         style="height: 50px; width: auto; object-fit: contain; border-radius: 5px;">
    <span style="font-size: 18px; font-weight: bold;">
      <?php echo htmlspecialchars($school['name'] ?? 'KEILA HIGH SCHOOL'); ?>
    </span>
  </div>

  <!-- Center: Report Title -->
  <div style="flex: 1; text-align: center; font-weight: bold; font-size: 16px;">
    Mean Grade Summary: <?php echo htmlspecialchars($exam_name); ?> (<?php echo htmlspecialchars($term); ?> - <?php echo $year; ?>)
  </div>

  <!-- Right: Buttons -->
  <div style="display: flex; gap: 8px; align-items: center;">
    <button style="background-color: #ff6b6b; border: none; padding: 6px 12px; border-radius: 5px; color: #fff; cursor: pointer; font-size: 12px;"
            onmouseover="this.style.backgroundColor='#e55b5b'" 
            onmouseout="this.style.backgroundColor='#ff6b6b'" 
            onclick="if (document.referrer) { window.location = document.referrer; } else { history.back(); location.reload(); }">
      <i class="fas fa-arrow-left"></i> Back
    </button>

    <button style="background-color: #007bff; border: none; padding: 6px 12px; border-radius: 5px; color: #fff; cursor: pointer; font-size: 12px;"
            onmouseover="this.style.backgroundColor='#0069d9'" onmouseout="this.style.backgroundColor='#007bff'" 
            onclick="printReport()">
      <i class="fas fa-print"></i> Print Report
    </button>
    
    <button style="background-color: #20c997; border: none; padding: 6px 12px; border-radius: 5px; color: #fff; cursor: pointer; font-size: 12px;"
            onmouseover="this.style.backgroundColor='#1aa179'" onmouseout="this.style.backgroundColor='#20c997'" 
            onclick="downloadReport()">
      <i class="fas fa-file-download"></i> Download Report
    </button>
  </div>
</div>

<!-- Download Modal -->
<div class="modal fade modal-loader" id="downloadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body">
                <div class="loader-spinner"></div>
                <p>Downloading the report...</p>
            </div>
        </div>
    </div>
</div>

<div class="schoolanalysis_container">
  <!-- Header -->
  <div class="schoolanalysis_header">
    <img src="<?php echo htmlspecialchars($school_logo); ?>" alt="Logo" /><br />
    <h2 class="schoolanalysis_h2"><?php echo htmlspecialchars($school['name'] ?? 'KEILA HIGH SCHOOL'); ?></h2>
    <p class="mb-0 fw-bold">MEAN GRADE SUMMARY</p>
    <p class="mb-0"><?php echo htmlspecialchars($exam_name . ' ' . $term . ' Year ' . $year); ?></p>
  </div>

  <!-- Class Tables or Error Message -->
  <?php if ($error_message): ?>
    <p class="error-message"><?php echo htmlspecialchars($error_message); ?></p>
  <?php elseif (empty($class_data)): ?>
    <p class="error-message">No data available for the selected exam and classes.</p>
  <?php else: ?>
    <?php foreach ($class_data as $class_id => $grade_data): ?>
      <div class="class-section">
        <div class="schoolanalysis_title">
          <?php echo htmlspecialchars($grade_data['form_name']); ?> RESULTS
        </div>
        <div class="table-responsive">
          <table class="table table-bordered table-sm text-center align-middle mb-2 schoolanalysis_table">
            <thead>
              <tr>
                <th>Stream</th>
                <th>Entry</th>
                <th>A</th><th>A-</th><th>B+</th><th>B</th><th>B-</th>
                <th>C+</th><th>C</th><th>C-</th><th>D+</th><th>D</th><th>D-</th>
                <th>E</th><th>X</th><th>Y</th>
                <th>Grade</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($grade_data['streams'] as $stream): ?>
                <tr>
                  <td><?php echo htmlspecialchars($stream['stream_name']); ?></td>
                  <td><?php echo $stream['entry']; ?></td>
                  <?php foreach (['A', 'A-', 'B+', 'B', 'B-', 'C+', 'C', 'C-', 'D+', 'D', 'D-', 'E', 'X', 'Y'] as $grade): ?>
                    <td><?php echo $stream['grades'][$grade]; ?></td>
                  <?php endforeach; ?>
                  <td><?php echo $stream['grade']; ?></td>
                </tr>
              <?php endforeach; ?>
              <tr class="totals">
                <td>TOTAL</td>
                <td><?php echo $grade_data['totals']['entry']; ?></td>
                <?php foreach (['A', 'A-', 'B+', 'B', 'B-', 'C+', 'C', 'C-', 'D+', 'D', 'D-', 'E', 'X', 'Y'] as $grade): ?>
                  <td><?php echo $grade_data['totals']['grades'][$grade]; ?></td>
                <?php endforeach; ?>
                <td><?php echo $grade_data['totals']['grade']; ?></td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Gender Analysis Table -->
        <div class="schoolanalysis_title">
          Gender Analysis for <?php echo htmlspecialchars($grade_data['form_name']); ?>
        </div>
        <div class="table-responsive">
          <table class="table table-bordered table-sm text-center align-middle mb-2 schoolanalysis_table gender-table">
            <thead>
              <tr>
                <th>Gender</th>
                <th>Entry</th>
                <th>A</th><th>A-</th><th>B+</th><th>B</th><th>B-</th>
                <th>C+</th><th>C</th><th>C-</th><th>D+</th><th>D</th><th>D-</th>
                <th>E</th><th>X</th><th>Y</th>
                <th>Grade</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach (['Male', 'Female'] as $gender): ?>
                <tr>
                  <td><?php echo $gender; ?></td>
                  <td><?php echo $grade_data['gender_analysis'][$gender]['entry']; ?></td>
                  <?php foreach (['A', 'A-', 'B+', 'B', 'B-', 'C+', 'C', 'C-', 'D+', 'D', 'D-', 'E', 'X', 'Y'] as $grade): ?>
                    <td><?php echo $grade_data['gender_analysis'][$gender]['grades'][$grade]; ?></td>
                  <?php endforeach; ?>
                  <td><?php echo $grade_data['gender_analysis'][$gender]['grade']; ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <!-- Footer Signatures -->
  <footer class="schoolanalysis_footer">
    <div class="footer-signatures">
      <div>
        <p><b>PREPARED BY</b></p>
        <p>SIGN: .................................... DATED:.........................</p>
        <p></p>
        <b>DEPUTY PRINCIPAL (ACADEMICS)</b>
      </div>
      <div>
        <p><b>APPROVED BY</b></p>
        <p>SIGN: .................................... DATED:.........................</p>
        <p></p>
        <b>PRINCIPAL</b>
      </div>
    </div>
  </footer>
</div>

<script>
function printReport() {
    window.print();
}

function downloadReport() {
    const containers = document.querySelectorAll('.schoolanalysis_container');
    if (containers.length === 0) {
        alert('No reports available to download.');
        return;
    }

    // Show the modal
    const modal = new bootstrap.Modal(document.getElementById('downloadModal'), {
        backdrop: 'static',
        keyboard: false
    });
    modal.show();

    const opt = {
        margin: 10,
        filename: 'School_Mean_Grade_Summary_<?php echo str_replace(" ", "_", htmlspecialchars($exam_name)) . "_" . htmlspecialchars($term) . "_" . $year . ".pdf"; ?>',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2, useCORS: true },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'landscape' }
    };

    const tempContainer = document.createElement('div');
    containers.forEach(container => {
        const clone = container.cloneNode(true);
        clone.querySelectorAll('.no-print').forEach(el => el.remove());
        tempContainer.appendChild(clone);
    });

    html2pdf().set(opt).from(tempContainer).save().then(() => {
        tempContainer.remove();
        modal.hide();
    }).catch(err => {
        console.error('PDF generation failed:', err);
        modal.hide();
        alert('Failed to generate PDF. Please try again.');
    });
}
</script>

<?php ob_end_flush(); ?>