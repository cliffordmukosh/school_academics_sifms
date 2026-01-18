<?php
// reports/examreports/public_transcript_report.php
require __DIR__ . '/../../../connection/db.php';
require __DIR__ . '/../functions.php';

// No session check — public access for parents/mobile

$year = isset($_GET['year']) ? (int)$_GET['year'] : 0;
$term = isset($_GET['term']) ? $_GET['term'] : '';
$student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;

if (empty($year) || empty($term) || $student_id <= 0) {
    die('Year, term, and student_id are required.');
}

// Fetch single student
$stmt = $conn->prepare("
    SELECT 
        s.student_id, s.full_name, s.admission_no, s.profile_picture, s.kcpe_score, s.kcpe_grade,
        s.school_id, s.class_id, c.form_name, COALESCE(st.stream_name, '') as stream_name,
        s.stream_id
    FROM students s
    LEFT JOIN classes c ON s.class_id = c.class_id
    LEFT JOIN streams st ON s.stream_id = st.stream_id
    WHERE s.student_id = ?
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student) {
    die('Student not found.');
}

$school_id = $student['school_id'];
$class_id  = $student['class_id'];
$class_name = $student['form_name'];
$stream_name = $student['stream_name'];
$students = [$student]; // Single student only

// School details
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

// Exam names in this term/year
$stmt = $conn->prepare("
    SELECT exam_id, exam_name
    FROM exams
    WHERE school_id = ? AND class_id = ? AND term = ? AND YEAR(created_at) = ? AND status = 'closed'
    ORDER BY created_at
");
$stmt->bind_param("iisi", $school_id, $class_id, $term, $year);
$stmt->execute();
$exams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$exam_names = array_column($exams, 'exam_name', 'exam_id');
$exam_ids = array_column($exams, 'exam_id');

// School settings
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

// Fees balance
$stmt = $conn->prepare("
    SELECT fees_balance
    FROM student_fees
    WHERE school_id = ? AND student_id = ? AND setting_id = (
        SELECT setting_id FROM school_settings WHERE school_id = ? AND term_name = ? AND academic_year = ?
    )
");
$stmt->bind_param("iiisi", $school_id, $student_id, $school_id, $term, $year);
$stmt->execute();
$fees = $stmt->get_result()->fetch_assoc();
$stmt->close();
$fees_balance = $fees['fees_balance'] ?? 0;

// Class & stream means
$stmt = $conn->prepare("
    SELECT AVG(average) as class_mean
    FROM student_term_results_aggregates
    WHERE school_id = ? AND class_id = ? AND term = ? AND year = ?
");
$stmt->bind_param("iisi", $school_id, $class_id, $term, $year);
$stmt->execute();
$class_mean_result = $stmt->get_result()->fetch_assoc();
$class_mean = $class_mean_result['class_mean'] ? round($class_mean_result['class_mean'], 2) : 0;
$class_grade = getGradeAndPointsFunc($conn, $class_mean, 1)['grade'];
$stmt->close();

$stream_mean = $class_mean;
$stream_grade = $class_grade;
if ($student['stream_id'] > 0) {
    $stmt = $conn->prepare("
        SELECT AVG(average) as stream_mean
        FROM student_term_results_aggregates
        WHERE school_id = ? AND class_id = ? AND stream_id = ? AND term = ? AND year = ?
    ");
    $stmt->bind_param("iiisi", $school_id, $class_id, $student['stream_id'], $term, $year);
    $stmt->execute();
    $stream_mean_result = $stmt->get_result()->fetch_assoc();
    $stream_mean = $stream_mean_result['stream_mean'] ? round($stream_mean_result['stream_mean'], 2) : 0;
    $stream_grade = getGradeAndPointsFunc($conn, $stream_mean, 1)['grade'];
    $stmt->close();
}

// Student term data
$stmt = $conn->prepare("
    SELECT average, grade, class_position, stream_position, class_total_students, stream_total_students,
           total_marks, total_points, kcpe_score, kcpe_grade, class_teacher_remark_text, principal_remark_text, min_subjects
    FROM student_term_results_aggregates
    WHERE school_id = ? AND student_id = ? AND class_id = ? AND term = ? AND year = ?
");
$stmt->bind_param("iiisi", $school_id, $student_id, $class_id, $term, $year);
$stmt->execute();
$student_result = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student_result) {
    die('No transcript data for this term/year.');
}

// Subjects - FIXED: STRICT stream-specific teachers only (no fallback)
$stmt = $conn->prepare("
    SELECT 
        t.subject_id, 
        s.name as subject_name, 
        t.subject_mean, 
        t.subject_grade, 
        t.subject_teacher_remark_text,
        GROUP_CONCAT(DISTINCT CONCAT(u.first_name, ' ', COALESCE(u.other_names, '')) SEPARATOR ', ') as teacher_names
    FROM term_subject_totals t
    JOIN subjects s ON t.subject_id = s.subject_id
    LEFT JOIN teacher_subjects ts 
        ON t.subject_id = ts.subject_id 
        AND t.class_id = ts.class_id 
        AND t.school_id = ts.school_id
        AND ts.stream_id = ?                      -- ONLY student's exact stream
        AND ts.academic_year = ?                  -- match year
    LEFT JOIN users u ON ts.user_id = u.user_id
    WHERE t.school_id = ? 
      AND t.student_id = ? 
      AND t.class_id = ? 
      AND t.term = ? 
      AND t.year = ?
    GROUP BY t.subject_id
");
$stmt->bind_param("iiiiiss", 
    $student['stream_id'],   // student's stream_id
    $year,
    $school_id,
    $student['student_id'],
    $class_id,
    $term,
    $year
);
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
    $stmt->bind_param("iiisi", $school_id, $student_id, $subject['subject_id'], $term, $year);
    $stmt->execute();
    $scores = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $subject['scores'] = array_column($scores, 'subject_score', 'exam_id');
}
unset($subject);

// Historical progress
$stmt = $conn->prepare("
    SELECT term, year, total_points, class_position
    FROM student_termly_historical_data
    WHERE school_id = ? AND student_id = ?
    ORDER BY year, term
");
$stmt->bind_param("ii", $school_id, $student_id);
$stmt->execute();
$historical_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$progress_data = [];
foreach ($historical_data as $data) {
    $form = 'Form ' . ($data['year'] - ($student['kcpe_year'] ?? $year) + 1);
    $progress_data[$form][$data['term']] = [
        'total_points' => $data['total_points'],
        'position' => $data['class_position']
    ];
}

// Grading system for points
$stmt = $conn->prepare("
    SELECT grading_system_id
    FROM exams
    WHERE school_id = ? AND class_id = ? AND term = ? AND YEAR(created_at) = ?
    LIMIT 1
");
$stmt->bind_param("iisi", $school_id, $class_id, $term, $year);
$stmt->execute();
$grading = $stmt->get_result()->fetch_assoc();
$grading_system_id = $grading['grading_system_id'] ?? 1;
$stmt->close();

function pointGrade($conn, $points, $grading_system_id) {
    $points = floor($points);
    $stmt = $conn->prepare("SELECT grade FROM grading_rules WHERE grading_system_id = ? AND points = ? LIMIT 1");
    $stmt->bind_param("ii", $grading_system_id, $points);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result ? $result['grade'] : 'N/A';
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
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        @page {
            size: A4;
            margin: 10mm;
        }
        @media print {
            body { background: none; margin: 0; font-size: 12px; }
            .report-container { box-shadow: none; border-radius: 0; max-width: 100%; padding: 0; }
            .student-row { display: flex !important; flex-wrap: nowrap !important; }
            footer { position: absolute; bottom: 0; left: 0; right: 0; }
            .no-print { display: none !important; }
        }
        /* Smaller header text */
        .no-print span { font-size: 16px !important; }
        .no-print div[style*="text-align:center"] { font-size: 14px !important; }
    </style>
</head>
<body>

<div class="no-print" style="display: flex; align-items: center; justify-content: space-between; background-color: #1a1f71; padding: 10px 20px; border-radius: 8px; box-shadow: 0 4px 8px rgba(0,0,0,0.2); color: #fff; margin-bottom: 15px; position: sticky; top: 0; z-index: 9999;">
    <div style="display: flex; align-items: center; gap: 10px;">
        <img src="<?php echo htmlspecialchars($school_logo); ?>" style="height: 50px; border-radius: 5px;">
        <span style="font-size: 16px; font-weight: bold;"><?php echo htmlspecialchars($school['name'] ?? 'School'); ?></span>
    </div>
    <div style="flex: 1; text-align: center; font-weight: bold; font-size: 14px;">
        Progress Report - <?php echo htmlspecialchars($term); ?> / <?php echo $year; ?>
    </div>
    <div style="display: flex; gap: 8px;">
        <button style="background:#007bff; border:none; padding:6px 12px; border-radius:5px; color:#fff;" onclick="window.print()">Print</button>
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
        $profile_picture = 'https://academics.sifms.co.ke/' . ltrim($profile_picture, '/');
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
               GROUP_CONCAT(DISTINCT CONCAT(u.first_name, ' ', COALESCE(u.other_names, '')) SEPARATOR ', ') as teacher_names
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
        $stmt->bind_param("iiisi", $school_id, $student_id, $subject['subject_id'], $term, $year);
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
        ORDER BY year, term
    ");
    $stmt->bind_param("ii", $school_id, $student_id);
    $stmt->execute();
    $historical_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $progress_data = [];
    foreach ($historical_data as $data) {
        $form = 'Form ' . ($data['year'] - ($student['kcpe_year'] ?? $year) + 1);
        $progress_data[$form][$data['term']] = [
            'total_points' => $data['total_points'],
            'position' => $data['class_position']
        ];
    }

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
                    <div class="d-flex align-items:center">
                        <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Student Photo" class="profile-pic me-2" />
                        <div>
                            <p class="mb-1"><strong>Name:</strong> <?php echo htmlspecialchars($student['full_name']); ?></p>
                            <p class="mb-1"><strong>Adm No:</strong> <?php echo htmlspecialchars($student['admission_no']); ?></p>
                            <p class="mb-1"><strong>Class:</strong> 
                                <?php echo htmlspecialchars($class_name . ' ' . ($student['stream_name'] ?? '')); ?>
                            </p>
                            <p class="mb-0"><strong>Grade:</strong> <?php echo htmlspecialchars($student_result['grade'] ?? 'N/A'); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card h-100">
                <div class="card-body">
                    <p class="mb-1"><strong>Class Position:</strong> 
                        <?php echo $student_result['class_position'] ?? 'N/A'; ?> 
                        out of <?php echo $student_result['class_total_students'] ?? 'N/A'; ?>
                    </p>
                    <p class="mb-1"><strong>Stream Position:</strong> 
                        <?php echo $student_result['stream_position'] ?? 'N/A'; ?> 
                        out of <?php echo $student_result['stream_total_students'] ?? 'N/A'; ?>
                    </p>
                    <p class="mb-1"><strong>Current Term Avg:</strong> 
                        <?php echo number_format($student_result['average'] ?? 0, 2); ?>
                    </p>
                    <p class="mb-1"><strong>Deviation:</strong> 
                        <?php echo $deviation; ?>
                    </p>
                </div>
            </div>
        </div>

        <div class="remarks-box">
            <strong>Class Mean:</strong> <?php echo number_format($class_mean, 2); ?> 
            <strong>Class Grade:</strong> <?php echo $class_grade; ?> 
            <strong>Stream Mean:</strong> <?php echo number_format($stream_mean, 2); ?> 
            <strong>Stream Grade:</strong> <?php echo $stream_grade; ?> 
            <strong>Student Mean:</strong> <?php echo number_format($student_result['average'] ?? 0, 2); ?> 
            <strong>Student Grade:</strong> <?php echo $student_result['grade'] ?? 'N/A'; ?> 
            <strong>KCPE:</strong> 
                <?php echo $student['kcpe_score'] ?? 'N/A'; ?>
                (<?php echo $student['kcpe_grade'] ?? 'N/A'; ?>)
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

        <table class="table table-sm table-bordered mb-2 align-middle">
            <tr>
                <td width="55%">
                    <strong>PROGRESS ANALYSIS</strong><br />
                    Total Marks: <?php echo number_format($total_marks, 0); ?>/<?php echo $max_marks; ?> &nbsp; | &nbsp; 
                    Mean Points: <?php echo number_format($student_result['total_points'] ?? 0, 3); ?><br />

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
                            <?php
                            $terms = ['Term 1', 'Term 2', 'Term 3'];
                            $form1_data = $progress_data['Form 1'] ?? [];
                            $form2_data = $progress_data['Form 2'] ?? [];
                            foreach ($terms as $t): ?>
                                <td><?php echo isset($form1_data[$t]['total_points']) ? number_format($form1_data[$t]['total_points'], 1) : '-'; ?></td>
                            <?php endforeach; ?>
                            <td><?php echo isset($form1_data['Term 3']['position']) ? $form1_data['Term 3']['position'] . '/-' : '-'; ?></td>
                            <?php foreach ($terms as $t): ?>
                                <td><?php echo isset($form2_data[$t]['total_points']) ? number_format($form2_data[$t]['total_points'], 1) : '-'; ?></td>
                            <?php endforeach; ?>
                            <td><?php echo isset($form2_data['Term 3']['position']) ? $form2_data['Term 3']['position'] . '/-' : '-'; ?></td>
                        </tr>
                        <tr>
                            <td colspan="4">Mean Points: <?php echo count($form1_data) > 0 ? number_format(array_sum(array_column($form1_data, 'total_points')) / count($form1_data), 1) . ' (' . pointGrade($conn, array_sum(array_column($form1_data, 'total_points')) / count($form1_data), $grading_system_id) . ')' : '-'; ?></td>
                            <td colspan="4">Mean Points: <?php echo count($form2_data) > 0 ? number_format(array_sum(array_column($form2_data, 'total_points')) / count($form2_data), 1) . ' (' . pointGrade($conn, array_sum(array_column($form2_data, 'total_points')) / count($form2_data), $grading_system_id) . ')' : '-'; ?></td>
                        </tr>
                        <!-- Form 3 + Form 4 -->
                        <tr>
                            <th colspan="4">FORM THREE</th>
                            <th colspan="4">FORM FOUR</th>
                        </tr>
                        <tr>
                            <td>I</td><td>II</td><td>III</td><td>Pos/Out</td>
                            <td>I</td><td>II</td><td>III</td><td>Pos/Out</td>
                        </tr>
                        <tr>
                            <?php
                            $form3_data = $progress_data['Form 3'] ?? [];
                            $form4_data = $progress_data['Form 4'] ?? [];
                            foreach ($terms as $t): ?>
                                <td><?php echo isset($form3_data[$t]['total_points']) ? number_format($form3_data[$t]['total_points'], 1) : '-'; ?></td>
                            <?php endforeach; ?>
                            <td><?php echo isset($form3_data['Term 3']['position']) ? $form3_data['Term 3']['position'] . '/-' : '-'; ?></td>
                            <?php foreach ($terms as $t): ?>
                                <td><?php echo isset($form4_data[$t]['total_points']) ? number_format($form4_data[$t]['total_points'], 1) : '-'; ?></td>
                            <?php endforeach; ?>
                            <td><?php echo isset($form4_data['Term 3']['position']) ? $form4_data['Term 3']['position'] . '/-' : '-'; ?></td>
                        </tr>
                        <tr>
                            <td colspan="4">Mean Points: <?php echo count($form3_data) > 0 ? number_format(array_sum(array_column($form3_data, 'total_points')) / count($form3_data), 1) . ' (' . pointGrade($conn, array_sum(array_column($form3_data, 'total_points')) / count($form3_data), $grading_system_id) . ')' : '-'; ?></td>
                            <td colspan="4">Mean Points: <?php echo count($form4_data) > 0 ? number_format(array_sum(array_column($form4_data, 'total_points')) / count($form4_data), 1) . ' (' . pointGrade($conn, array_sum(array_column($form4_data, 'total_points')) / count($form4_data), $grading_system_id) . ')' : '-'; ?></td>
                        </tr>
                    </table>
                </td>
                <td>
                    <strong>Graphical Analysis</strong><br />
                    <canvas id="progressChart" width="400" height="250"></canvas>
                    <?php if (!empty($progress_data)): ?>
                        <script>
                            const ctx = document.getElementById('progressChart').getContext('2d');
                            new Chart(ctx, {
                                type: 'line',
                                data: {
                                    labels: [<?php echo implode(',', array_map(fn($l) => "'$l'", array_keys(array_merge(...array_values($progress_data))))); ?>],
                                    datasets: [{
                                        label: 'Mean Points',
                                        data: [<?php echo implode(',', array_map(fn($d) => $d['total_points'] ?? 0, array_merge(...array_values($progress_data)))); ?>],
                                        borderColor: 'blue',
                                        backgroundColor: 'rgba(0, 123, 255, 0.2)',
                                        fill: true,
                                        tension: 0.3,
                                        pointRadius: 4
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    plugins: { legend: { position: 'top' } },
                                    scales: {
                                        y: { beginAtZero: true, max: 12, title: { display: true, text: 'Mean Points (1-12)' } },
                                        x: { title: { display: true, text: 'Terms' } }
                                    }
                                }
                            });
                        </script>
                    <?php else: ?>
                        <div class="graph-placeholder">No progress data available</div>
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
function downloadReport() {
    const containers = document.querySelectorAll('.report-container');
    if (containers.length === 0) return alert('No report to download');

    const modal = new bootstrap.Modal(document.getElementById('downloadModal'));
    modal.show();

    const temp = document.createElement('div');
    temp.style.position = 'absolute'; temp.style.left = '-9999px';
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

    const filename = "Transcript_<?php echo htmlspecialchars($student['admission_no'] ?? 'Student'); ?>_<?php echo $term . '_' . $year; ?>.pdf";

    html2pdf().set({
        margin: 10,
        filename: filename,
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2, useCORS: true },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
    }).from(temp).save().then(() => {
        temp.remove();
        modal.hide();
    });
}
</script>
</body>
</html>