<?php
// reports/examreports/TranscriptReport.php
require __DIR__ . '/../../../connection/db.php';
require __DIR__ . '/../functions.php';

// Remove session check so mobile app (parents) can access without login session
// Comment out if you want to keep admin-only session protection
/*
if (!isset($_SESSION['user_id']) || !isset($_SESSION['school_id']) || !isset($_SESSION['role_id'])) {
    header("Location: ../../login.php");
    exit;
}
*/

$year = isset($_GET['year']) ? (int)$_GET['year'] : 0;
$term = isset($_GET['term']) ? $_GET['term'] : '';
$class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$stream_id = isset($_GET['stream_id']) ? (int)$_GET['stream_id'] : 0;
$student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0; // NEW: Single student mode

if (empty($year) || empty($term)) {
    die('Year and term are required.');
}

// SINGLE STUDENT MODE (parent app)
if ($student_id > 0) {
    $stmt = $conn->prepare("
        SELECT 
            s.student_id, s.full_name, s.admission_no, s.profile_picture, s.kcpe_score, s.kcpe_grade,
            s.school_id, s.class_id, c.form_name, COALESCE(st.stream_name, '') as stream_name
        FROM students s
        LEFT JOIN classes c ON s.class_id = c.class_id
        LEFT JOIN streams st ON s.stream_id = st.stream_id
        WHERE s.student_id = ?
    ");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $student = $result->fetch_assoc();
    $stmt->close();

    if (!$student) {
        die('Student not found.');
    }

    $school_id = $student['school_id'];
    $class_id = $student['class_id'];
    $students = [$student]; // Only this student
    $class_name = $student['form_name'];
} else {
    // CLASS MODE (admin)
    if (empty($class_id)) {
        die('Class ID is required for class reports.');
    }

    if (isset($_SESSION['school_id'])) {
        $school_id = $_SESSION['school_id'];
    } else {
        die('School ID missing.');
    }

    $stmt = $conn->prepare("SELECT form_name FROM classes WHERE class_id = ? AND school_id = ?");
    $stmt->bind_param("ii", $class_id, $school_id);
    $stmt->execute();
    $class_result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $class_name = $class_result['form_name'] ?? '';

    // Fetch students (original logic)
    if ($stream_id > 0) {
        $stmt = $conn->prepare("
            SELECT s.student_id, s.full_name, s.admission_no, s.profile_picture, s.kcpe_score, s.kcpe_grade,
                   COALESCE(st.stream_name, '') as stream_name
            FROM students s
            JOIN streams st ON s.stream_id = st.stream_id
            WHERE s.class_id = ? AND s.stream_id = ? AND s.school_id = ?
            ORDER BY s.full_name
        ");
        $stmt->bind_param("iii", $class_id, $stream_id, $school_id);
    } else {
        $stmt = $conn->prepare("
            SELECT s.student_id, s.full_name, s.admission_no, s.profile_picture, s.kcpe_score, s.kcpe_grade,
                   COALESCE(st.stream_name, '') as stream_name
            FROM students s
            LEFT JOIN streams st ON s.stream_id = st.stream_id
            WHERE s.class_id = ? AND s.school_id = ?
            ORDER BY s.full_name
        ");
        $stmt->bind_param("ii", $class_id, $school_id);
    }
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($students)) {
        die('No students found.');
    }
}

if (isset($school_id) && $school_id > 0) {
    $call_stmt = $conn->prepare("CALL sp_generate_historical_data(?)");
    $call_stmt->bind_param("i", $school_id);

    if (!$call_stmt->execute()) {
        error_log("Failed to generate historical data for school ID $school_id: " . $call_stmt->error);
    }

    $call_stmt->close();
}

// Fetch school details
$stmt = $conn->prepare("SELECT name, logo FROM schools WHERE school_id = ?");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$school = $stmt->get_result()->fetch_assoc();
$stmt->close();

$school_logo = $school['logo'] ?? '';
if (empty($school_logo)) {
    $school_logo = 'https://academics.sifms.co.ke/manageschool/logos/school-logo.png';
} elseif (strpos($school_logo, 'http') !== 0) {
    $school_logo = 'https://academics.sifms.co.ke/manageschool/logos/' . basename($school_logo);
}

// Fetch stream name
$stream_name = 'All Streams';
if ($stream_id > 0) {
    $stmt = $conn->prepare("SELECT stream_name FROM streams WHERE stream_id = ? AND school_id = ?");
    $stmt->bind_param("ii", $stream_id, $school_id);
    $stmt->execute();
    $stream = $stmt->get_result()->fetch_assoc();
    $stream_name = $stream['stream_name'] ?? 'All Streams';
    $stmt->close();
}

// Fetch exam names
$stmt = $conn->prepare("
    SELECT exam_id, exam_name
    FROM exams
    WHERE school_id = ? AND class_id = ? AND term = ? AND YEAR(created_at) = ? AND status = 'closed'
    AND EXISTS (SELECT 1 FROM results r WHERE r.exam_id = exams.exam_id AND r.status = 'confirmed')
    ORDER BY created_at
");
$stmt->bind_param("iisi", $school_id, $class_id, $term, $year);
$stmt->execute();
$exams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$exam_names = array_column($exams, 'exam_name', 'exam_id');
$exam_ids = array_column($exams, 'exam_id');

// Fetch school settings
$stmt = $conn->prepare("
    SELECT closing_date, next_opening_date, next_term_fees, principal_name, principal_signature
    FROM school_settings
    WHERE school_id = ? AND term_name = ? AND academic_year = ?
");
$stmt->bind_param("isi", $school_id, $term, $year);
$stmt->execute();
$settings = $stmt->get_result()->fetch_assoc();
$stmt->close();

$principal_signature = $settings['principal_signature'] ?? '';
if (empty($principal_signature)) {
    $principal_signature = 'https://academics.sifms.co.ke/manageschool/logos/school-logo.png';
} elseif (strpos($principal_signature, 'http') !== 0) {
    $principal_signature = 'https://academics.sifms.co.ke/manageschool/' . ltrim(str_replace('manageschool/', '', $principal_signature), '/');
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transcript Report</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .report-container {
            max-width: 820px;
            margin: 10px auto;
            background: #fff;
            padding: 12px 15px;
            border-radius: 6px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
            page-break-after: always;
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

        .table th,
        .table td {
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

        .subjects-table td:first-child,
        .subjects-table td:nth-last-child(2) {
            text-align: left !important;
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
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
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
    <div class="no-print" style="display: flex; align-items: center; justify-content: space-between; background-color: #1a1f71; padding: 10px 20px; border-radius: 8px; box-shadow: 0 4px 8px rgba(0,0,0,0.2); color: #fff; margin-bottom: 15px; position: sticky; top: 0; z-index: 9999;">
        <div style="display: flex; align-items: center; gap: 10px;">
            <img src="<?php echo htmlspecialchars($school_logo); ?>" style="height: 50px; border-radius: 5px;">
            <span style="font-size: 18px; font-weight: bold;"><?php echo htmlspecialchars($school['name'] ?? 'School'); ?></span>
        </div>
        <div style="flex: 1; text-align: center; font-weight: bold; font-size: 16px;">
            Progress Report - <?php echo htmlspecialchars($term); ?> / <?php echo $year; ?>
        </div>
        <div style="display: flex; gap: 8px;">
            <button style="background:#ff6b6b; border:none; padding:6px 12px; border-radius:5px; color:#fff;" onclick="history.back()">Back</button>
            <button style="background:#007bff; border:none; padding:6px 12px; border-radius:5px; color:#fff;" onclick="window.print()">Print</button>
            <a href="generate_transcript_pdf.php?year=<?= urlencode($year) ?>&term=<?= urlencode($term) ?>&class_id=<?= $class_id ?>&stream_id=<?= $stream_id ?>&student_id=<?= $student_id ?>"
                class="btn btn-success" target="_blank">
                Download Transcript PDF
            </a>
        </div>
    </div>

    <div class="modal fade modal-loader" id="downloadModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body text-center">
                    <div class="loader-spinner"></div>
                    <p>Generating PDF...</p>
                </div>
            </div>
        </div>
    </div>

    <?php foreach ($students as $student): ?>
        <?php
        $profile_picture = $student['profile_picture'] ?? '';
        if (empty($profile_picture)) {
            $profile_picture = 'https://academics.sifms.co.ke/manageschool/studentsprofile/defaultstudent.png';
        } elseif (strpos($profile_picture, 'http') !== 0) {
            $profile_picture = 'https://academics.sifms.co.ke/manageschool/' . ltrim($profile_picture, '/');
        }

        $stmt = $conn->prepare("
            SELECT average, grade, class_position, stream_position, class_total_students, stream_total_students,
                   total_marks, total_points, kcpe_score, kcpe_grade, class_teacher_remark_text, principal_remark_text, min_subjects
            FROM student_term_results_aggregates
            WHERE school_id = ? AND student_id = ? AND class_id = ? AND term = ? AND year = ?
        ");
        $stmt->bind_param("iiisi", $school_id, $student['student_id'], $class_id, $term, $year);
        $stmt->execute();
        $student_result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$student_result) continue;

        $stmt = $conn->prepare("
            SELECT t.subject_id, s.name as subject_name, t.subject_mean, t.subject_grade, t.subject_teacher_remark_text,
                   GROUP_CONCAT(DISTINCT u.first_name SEPARATOR ', ') as teacher_names
            FROM term_subject_totals t
            JOIN subjects s ON t.subject_id = s.subject_id
            LEFT JOIN teacher_subjects ts ON t.subject_id = ts.subject_id AND t.class_id = ts.class_id AND t.school_id = ts.school_id
            LEFT JOIN users u ON ts.user_id = u.user_id
            WHERE t.school_id = ? AND t.student_id = ? AND t.class_id = ? AND t.term = ? AND t.year = ?
            GROUP BY t.subject_id
        ");
        $stmt->bind_param("iiisi", $school_id, $student['student_id'], $class_id, $term, $year);
        $stmt->execute();
        $subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($subjects as &$subject) {
            $stmt = $conn->prepare("
                SELECT e.exam_id, e.exam_name, esa.subject_score
                FROM exam_subject_aggregates esa
                JOIN exams e ON esa.exam_id = e.exam_id
                WHERE esa.school_id = ? AND esa.student_id = ? AND esa.subject_id = ? AND e.term = ? AND YEAR(e.created_at) = ?
            ");
            $stmt->bind_param("iiisi", $school_id, $student['student_id'], $subject['subject_id'], $term, $year);
            $stmt->execute();
            $scores = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $subject['scores'] = array_column($scores, 'subject_score', 'exam_id');
        }
        unset($subject);

        $stmt = $conn->prepare("
            SELECT term, year, total_points, class_position
            FROM student_termly_historical_data
            WHERE school_id = ? AND student_id = ?
            ORDER BY year ASC, FIELD(term, 'Term 1', 'Term 2', 'Term 3')
        ");
        $stmt->bind_param("ii", $school_id, $student['student_id']);
        $stmt->execute();
        $historical_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Populate $progress_data correctly for Grade 10 / Grade 11
        $progress_data = [];

        foreach ($historical_data as $row) {
            if ($row['year'] == $year) {
                $form_key = 'Grade 10';           // current year → current grade
            } else if ($row['year'] < $year) {
                $form_key = 'Grade 10';           // previous years → still under Grade 10 for now
            } else {
                $form_key = 'Grade 11';           // future or misordered years
            }

            if (!isset($progress_data[$form_key])) {
                $progress_data[$form_key] = [];
            }

            $progress_data[$form_key][$row['term']] = [
                'total_points' => $row['total_points'],
                'position'     => $row['class_position']
            ];
        }

        // Prepare chart data using total_points (0–100 scale)
        $chart_labels = [];
        $chart_points = [];
        $chart_positions = [];

        foreach ($historical_data as $row) {
            $label = $row['term'] . ' ' . $row['year'];
            $chart_labels[] = $label;
            $chart_points[] = (float)$row['total_points'];
            $chart_positions[] = $row['class_position'];
        }

        $stmt = $conn->prepare("
            SELECT fees_balance
            FROM student_fees
            WHERE school_id = ? AND student_id = ? AND setting_id = (
                SELECT setting_id FROM school_settings WHERE school_id = ? AND term_name = ? AND academic_year = ?
            )
        ");
        $stmt->bind_param("iiisi", $school_id, $student['student_id'], $school_id, $term, $year);
        $stmt->execute();
        $fees = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $fees_balance = $fees['fees_balance'] ?? 0;

        $deviation = 0;
        $current_avg = $student_result['average'];
        $stmt = $conn->prepare("
            SELECT average
            FROM student_term_results_aggregates
            WHERE school_id = ? AND student_id = ? AND class_id != ? AND term != ? AND year <= ?
            ORDER BY year DESC, term DESC
            LIMIT 1
        ");
        $stmt->bind_param("iiisi", $school_id, $student['student_id'], $class_id, $term, $year);
        $stmt->execute();
        $prev_result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($prev_result) {
            $prev_avg = $prev_result['average'];
            $deviation = round($current_avg - $prev_avg, 2);
        }
        ?>

        <div class="report-container" style="font-size: 12px">
            <div class="header">
                <img src="<?php echo htmlspecialchars($school_logo); ?>" alt="Logo" /><br />
                <h2><?php echo htmlspecialchars($school['name'] ?? 'KEILA HIGH SCHOOL'); ?></h2>
                <p class="mb-0">Progress Report for <?php echo htmlspecialchars($term . ' - Year ' . $year); ?></p>
            </div>

            <div class="student-row mb-2">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Student Photo" class="profile-pic me-2" />
                            <div>
                                <p class="mb-1"><strong>Name:</strong> <?php echo htmlspecialchars($student['full_name']); ?></p>
                                <p class="mb-1"><strong>Adm No:</strong> <?php echo htmlspecialchars($student['admission_no']); ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card h-100">
                    <div class="card-body">
                        <p class="mb-1"><strong>Class:</strong>
                            <?php echo htmlspecialchars($class_name . ' ' . ($student['stream_name'] ?? '')); ?>
                        </p>
                        <p class="mb-0"><strong>Grade:</strong> <?php echo htmlspecialchars($student_result['grade'] ?? 'N/A'); ?></p>
                    </div>
                </div>
            </div>

            <table class="table table-bordered table-sm text-center align-middle mb-2 subjects-table">
                <thead>
                    <tr>
                        <th>Subject</th>
                        <?php foreach ($exam_names as $exam_id => $exam_name): ?>
                            <th><?php echo htmlspecialchars($exam_name); ?></th>
                        <?php endforeach; ?>
                        <th>Mean %</th>
                        <th>Grade</th>
                        <th>Remarks</th>
                        <th>Subject Teacher</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $total_marks = $student_result['total_marks'] ?? 0;
                    $max_marks = ($student_result['min_subjects'] ?? count($subjects)) * 100;
                    foreach ($subjects as $subject): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                            <?php foreach ($exam_ids as $exam_id): ?>
                                <td><?php echo number_format($subject['scores'][$exam_id] ?? 0, 2); ?></td>
                            <?php endforeach; ?>
                            <td><?php echo number_format($subject['subject_mean'] ?? 0, 2); ?></td>
                            <td><?php echo htmlspecialchars($subject['subject_grade'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($subject['subject_teacher_remark_text'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($subject['teacher_names'] ?? 'N/A'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="table-primary fw-bold">
                        <td>Totals</td>
                        <td colspan="<?php echo count($exam_names) + 4; ?>">
                            Total Marks: <?php echo number_format($total_marks, 0); ?>/<?php echo $max_marks; ?> |
                            Average: <?php echo number_format($student_result['average'] ?? 0, 2); ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <?php
            $stmt = $conn->prepare("
                SELECT grading_system_id
                FROM exams
                WHERE school_id = ? AND class_id = ? AND term = ? AND YEAR(created_at) = ?
                LIMIT 1
            ");
            $stmt->bind_param("iisi", $school_id, $class_id, $term, $year);
            $stmt->execute();
            $grading_system = $stmt->get_result()->fetch_assoc();
            $grading_system_id = $grading_system['grading_system_id'] ?? 1;
            $stmt->close();
            ?>

            <table class="table table-sm table-bordered mb-2 align-middle">
                <tr>
                    <td width="55%">
                        <strong>PROGRESS ANALYSIS</strong><br />
                        Total Marks: <?php echo number_format($total_marks, 0); ?>/<?php echo $max_marks; ?> &nbsp; | &nbsp;

                        <table class="table table-bordered table-sm text-center mt-1 mb-1">
                            <tr>
                                <th colspan="4">Grade 10</th>
                                <th colspan="4">Grade 11</th>
                            </tr>
                            <tr>
                                <td>I</td>
                                <td>II</td>
                                <td>III</td>
                                <td>Pos/Out</td>
                                <td>I</td>
                                <td>II</td>
                                <td>III</td>
                                <td>Pos/Out</td>
                            </tr>
                            <tr>
                                <?php
                                $terms = ['Term 1', 'Term 2', 'Term 3'];
                                $form1_data = $progress_data['Grade 10'] ?? [];
                                $form2_data = $progress_data['Grade 11'] ?? [];

                                // Grade 10: points for Term 1, 2, 3 + position only on Term 3
                                foreach ($terms as $t): ?>
                                    <td><?php echo isset($form1_data[$t]['total_points']) ? number_format($form1_data[$t]['total_points'], 1) : '-'; ?></td>
                                <?php endforeach; ?>
                                <td><?php echo isset($form1_data['Term 3']['position']) ? $form1_data['Term 3']['position'] . '/-' : '-'; ?></td>

                                <?php
                                // Grade 11: points for Term 1, 2, 3 + position only on Term 3
                                foreach ($terms as $t): ?>
                                    <td><?php echo isset($form2_data[$t]['total_points']) ? number_format($form2_data[$t]['total_points'], 1) : '-'; ?></td>
                                <?php endforeach; ?>
                                <td><?php echo isset($form2_data['Term 3']['position']) ? $form2_data['Term 3']['position'] . '/-' : '-'; ?></td>
                            </tr>
                        </table>
                    </td>
                    <td>
                        <strong>Graphical Analysis</strong><br />

                        <canvas id="progressChart_<?php echo $student['student_id']; ?>" width="400" height="250"></canvas>

                        <?php if (!empty($chart_points)): ?>
                            <script>
                                window['chart_progressChart_<?php echo $student['student_id']; ?>'] = new Chart(
                                    document.getElementById('progressChart_<?php echo $student['student_id']; ?>').getContext('2d'), {
                                        type: 'line',
                                        data: {
                                            labels: <?php echo json_encode($chart_labels); ?>,
                                            datasets: [{
                                                label: 'Total Points',
                                                data: <?php echo json_encode($chart_points); ?>,
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
                                                legend: {
                                                    position: 'top'
                                                }
                                            },
                                            scales: {
                                                y: {
                                                    min: 0,
                                                    max: 100,
                                                    beginAtZero: true,
                                                    title: {
                                                        display: true,
                                                        text: 'Total Points (0–100)'
                                                    }
                                                },
                                                x: {
                                                    title: {
                                                        display: true,
                                                        text: 'Term & Year'
                                                    }
                                                }
                                            }
                                        }
                                    }
                                );
                            </script>
                        <?php else: ?>
                            <div class="graph-placeholder">No historical progress data available</div>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <div class="remarks-box mb-2">
                <p class="mb-1"><strong>Class Teacher:</strong> <?php echo htmlspecialchars($student_result['class_teacher_remark_text'] ?? 'N/A'); ?></p>
                <p class="mb-0"><strong>Principal:</strong> <?php echo htmlspecialchars($student_result['principal_remark_text'] ?? 'N/A'); ?></p>
            </div>

            <footer>
                <table class="table table-borderless table-sm mb-1 w-100">
                    <tr>
                        <td><strong>Closing Date:</strong> <?php echo $settings['closing_date'] ?? 'N/A'; ?></td>
                        <td><strong>Next Opening:</strong> <?php echo $settings['next_opening_date'] ?? 'N/A'; ?></td>
                        <td><strong>Fees Balance:</strong> Ksh <?php echo number_format($fees_balance, 2); ?></td>
                        <td><strong>Next Term Fees:</strong> Ksh <?php echo number_format($settings['next_term_fees'] ?? 0, 2); ?></td>
                    </tr>
                </table>
                <p class="text-end mb-0">
                    <strong>Principal:</strong> <?php echo htmlspecialchars($settings['principal_name'] ?? 'N/A'); ?><br />
                </p>
            </footer>
        </div>
    <?php endforeach; ?>

    <script>
        const chartInstances = [];
        <?php foreach ($students as $student): ?>
            if (window['chart_progressChart_<?php echo $student['student_id']; ?>']) {
                chartInstances.push(window['chart_progressChart_<?php echo $student['student_id']; ?>']);
            }
        <?php endforeach; ?>

        function downloadReport() {
            const containers = document.querySelectorAll('.report-container');
            if (containers.length === 0) return alert('No report to download');

            const modal = new bootstrap.Modal(document.getElementById('downloadModal'));
            modal.show();

            chartInstances.forEach(c => c?.destroy?.());
            chartInstances.length = 0;

            const temp = document.createElement('div');
            temp.style.position = 'absolute';
            temp.style.left = '-9999px';
            document.body.appendChild(temp);

            containers.forEach((c, i) => {
                const clone = c.cloneNode(true);
                clone.querySelectorAll('.no-print').forEach(e => e.remove());
                temp.appendChild(clone);
                if (i < containers.length - 1) {
                    const brk = document.createElement('div');
                    brk.style.pageBreakAfter = 'always';
                    temp.appendChild(brk);
                }
            });

            const filename = containers.length === 1 ?
                '<?php echo htmlspecialchars($students[0]['full_name'] ?? 'Transcript'); ?>_<?php echo $term . '_' . $year; ?>.pdf' :
                'Transcripts_<?php echo $term . '_' . $year; ?>.pdf';

            html2pdf().set({
                margin: 10,
                filename: filename,
                image: {
                    type: 'jpeg',
                    quality: 0.98
                },
                html2canvas: {
                    scale: 2,
                    useCORS: true
                },
                jsPDF: {
                    unit: 'mm',
                    format: 'a4',
                    orientation: 'portrait'
                }
            }).from(temp).save().then(() => {
                temp.remove();
                modal.hide();
            });
        }
    </script>
</body>

</html>