<?php
// reports/examreports/generate_transcript_pdf.php

// ────────────────────────────────────────────────────────────────
// IMPORTANT: Place this file in the same folder as TranscriptReport.php
// ────────────────────────────────────────────────────────────────

require __DIR__ . '/../../../connection/db.php';
require __DIR__ . '/../functions.php';
require_once __DIR__ . '/../../../vendor/autoload.php'; // Composer autoload for dompdf

use Dompdf\Dompdf;
use Dompdf\Options;

// ── Security: You may want to add basic auth or token check later ──
// For now we reuse the same parameters as your original script

$year       = isset($_GET['year'])       ? (int)$_GET['year']       : 0;
$term       = isset($_GET['term'])       ? $_GET['term']            : '';
$class_id   = isset($_GET['class_id'])   ? (int)$_GET['class_id']   : 0;
$stream_id  = isset($_GET['stream_id'])  ? (int)$_GET['stream_id']  : 0;
$student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;

if (empty($year) || empty($term)) {
    die('Year and term are required.');
}

// ────────────────────────────────────────────────────────────────
// SINGLE STUDENT MODE or CLASS MODE ── same logic as your original
// ────────────────────────────────────────────────────────────────

if ($student_id > 0) {
    // Single student (parent view)
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
    $class_id  = $student['class_id'];
    $students  = [$student];
    $class_name = $student['form_name'];
} else {
    // Class mode (admin)
    if (empty($class_id)) {
        die('Class ID is required for class reports.');
    }

    $school_id = $_SESSION['school_id'] ?? 0;
    if (!$school_id) {
        die('School ID missing. Please log in.');
    }

    $stmt = $conn->prepare("SELECT form_name FROM classes WHERE class_id = ? AND school_id = ?");
    $stmt->bind_param("ii", $class_id, $school_id);
    $stmt->execute();
    $class_result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $class_name = $class_result['form_name'] ?? '';

    // Fetch students
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

// ── School details ──
$stmt = $conn->prepare("SELECT name, logo FROM schools WHERE school_id = ?");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$school = $stmt->get_result()->fetch_assoc();
$stmt->close();

$school_logo = $school['logo'] ?? 'https://academics.sifms.co.ke/manageschool/logos/school-logo.png';
if (strpos($school_logo, 'http') !== 0) {
    $school_logo = 'https://academics.sifms.co.ke/manageschool/logos/' . basename($school_logo);
}

// ── Stream name ──
$stream_name = 'All Streams';
if ($stream_id > 0) {
    $stmt = $conn->prepare("SELECT stream_name FROM streams WHERE stream_id = ? AND school_id = ?");
    $stmt->bind_param("ii", $stream_id, $school_id);
    $stmt->execute();
    $stream = $stmt->get_result()->fetch_assoc();
    $stream_name = $stream['stream_name'] ?? 'All Streams';
    $stmt->close();
}

// ── Exam names ──
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
$exam_ids   = array_column($exams, 'exam_id');

// ── School settings ──
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

// ── Class & Stream means ──
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
if ($stream_id > 0) {
    $stmt = $conn->prepare("
        SELECT AVG(average) as stream_mean
        FROM student_term_results_aggregates
        WHERE school_id = ? AND class_id = ? AND stream_id = ? AND term = ? AND year = ?
    ");
    $stmt->bind_param("iiisi", $school_id, $class_id, $stream_id, $term, $year);
    $stmt->execute();
    $stream_mean_result = $stmt->get_result()->fetch_assoc();
    $stream_mean = $stream_mean_result['stream_mean'] ? round($stream_mean_result['stream_mean'], 2) : 0;
    $stream_grade = getGradeAndPointsFunc($conn, $stream_mean, 1)['grade'];
    $stmt->close();
}

// ────────────────────────────────────────────────────────────────
// Start building HTML content
// ────────────────────────────────────────────────────────────────

ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Transcript - <?= htmlspecialchars($term . ' ' . $year) ?></title>
    <style>
        @page {
            size: A4;
            margin: 12mm 10mm;
        }
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 11px;
            color: #000;
            line-height: 1.4;
        }
        .report-container {
            max-width: 100%;
            margin-bottom: 20px;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #0d6efd;
            padding-bottom: 8px;
            margin-bottom: 12px;
        }
        .header img {
            width: 90px;
            height: 90px;
            object-fit: contain;
        }
        h2 {
            margin: 4px 0;
            font-size: 18px;
            color: #0d6efd;
        }
        .student-info {
            display: flex;
            gap: 15px;
            margin-bottom: 12px;
        }
        .student-info .card {
            flex: 1;
            border: 1px solid #0d6efd;
            padding: 10px;
        }
        .profile-pic {
            width: 70px;
            height: 70px;
            object-fit: cover;
            border-radius: 6px;
            border: 1px solid #ccc;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
            font-size: 10px;
        }
        th, td {
            border: 1px solid #ccc;
            padding: 4px 6px;
            text-align: center;
        }
        th {
            background: #e9f2ff;
            color: #0d47a1;
        }
        .remarks-box {
            border: 1px solid #0d6efd;
            background: #f8fcff;
            padding: 10px;
            margin: 12px 0;
            border-left: 5px solid #0d6efd;
        }
        .totals {
            font-weight: bold;
            background: #e9f2ff;
        }
        .footer {
            margin-top: 20px;
            border-top: 2px solid #0d6efd;
            padding-top: 8px;
            font-size: 10px;
        }
        .page-break {
            page-break-after: always;
        }
        img {
            max-width: 100%;
        }
    </style>
</head>
<body>

<?php
foreach ($students as $index => $student):
    $profile_picture = $student['profile_picture'] ?? 'https://academics.sifms.co.ke/manageschool/studentsprofile/defaultstudent.png';
    if (strpos($profile_picture, 'http') !== 0) {
        $profile_picture = 'https://academics.sifms.co.ke/manageschool/' . ltrim($profile_picture, '/');
    }

    // Student aggregate
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

    // Subjects
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
            SELECT e.exam_id, esa.subject_score
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

    $total_marks = $student_result['total_marks'] ?? 0;
    $max_marks   = ($student_result['min_subjects'] ?? count($subjects)) * 100;
?>

<div class="report-container">
    <div class="header">
        <img src="<?= htmlspecialchars($school_logo) ?>" alt="School Logo"><br>
        <h2><?= htmlspecialchars($school['name'] ?? 'KEILA HIGH SCHOOL') ?></h2>
        <p>Progress Report - <?= htmlspecialchars($term . ' - ' . $year) ?></p>
    </div>

    <div class="student-info">
        <div class="card">
            <strong>Name:</strong> <?= htmlspecialchars($student['full_name']) ?><br>
            <strong>Adm No:</strong> <?= htmlspecialchars($student['admission_no']) ?><br>
            <strong>Class:</strong> <?= htmlspecialchars($class_name . ' ' . ($student['stream_name'] ?? '')) ?><br>
            <strong>Grade:</strong> <?= htmlspecialchars($student_result['grade'] ?? 'N/A') ?>
        </div>
        <div class="card">
            <strong>Class Pos:</strong> <?= $student_result['class_position'] ?? 'N/A' ?> / <?= $student_result['class_total_students'] ?? 'N/A' ?><br>
            <strong>Stream Pos:</strong> <?= $student_result['stream_position'] ?? 'N/A' ?> / <?= $student_result['stream_total_students'] ?? 'N/A' ?><br>
            <strong>Average:</strong> <?= number_format($student_result['average'] ?? 0, 2) ?><br>
            <strong>KCPE:</strong> <?= $student['kcpe_score'] ?? 'N/A' ?> (<?= $student['kcpe_grade'] ?? 'N/A' ?>)
        </div>
    </div>

    <div class="remarks-box">
        <strong>Class Mean:</strong> <?= number_format($class_mean, 2) ?> (<?= $class_grade ?>)<br>
        <strong>Stream Mean:</strong> <?= number_format($stream_mean, 2) ?> (<?= $stream_grade ?>)
    </div>

    <table>
        <thead>
            <tr>
                <th>Subject</th>
                <?php foreach ($exam_names as $exam_name): ?>
                    <th><?= htmlspecialchars($exam_name) ?></th>
                <?php endforeach; ?>
                <th>Mean %</th>
                <th>Grade</th>
                <th>Remarks</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($subjects as $subject): ?>
                <tr>
                    <td style="text-align:left;"><?= htmlspecialchars($subject['subject_name']) ?></td>
                    <?php foreach ($exam_ids as $exam_id): ?>
                        <td><?= number_format($subject['scores'][$exam_id] ?? 0, 2) ?></td>
                    <?php endforeach; ?>
                    <td><?= number_format($subject['subject_mean'] ?? 0, 2) ?></td>
                    <td><?= htmlspecialchars($subject['subject_grade'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($subject['subject_teacher_remark_text'] ?? 'N/A') ?></td>
                </tr>
            <?php endforeach; ?>
            <tr class="totals">
                <td>Totals</td>
                <td colspan="<?= count($exam_names) + 4 ?>">
                    Total: <?= number_format($total_marks, 0) ?>/<?= $max_marks ?> | 
                    Avg: <?= number_format($student_result['average'] ?? 0, 2) ?>
                </td>
            </tr>
        </tbody>
    </table>

    <div class="remarks-box">
        <strong>Class Teacher:</strong> <?= htmlspecialchars($student_result['class_teacher_remark_text'] ?? 'N/A') ?><br>
        <strong>Principal:</strong> <?= htmlspecialchars($student_result['principal_remark_text'] ?? 'N/A') ?>
    </div>

    <div class="footer">
        <table style="border:none;">
            <tr>
                <td><strong>Closing:</strong> <?= $settings['closing_date'] ?? 'N/A' ?></td>
                <td><strong>Next Opening:</strong> <?= $settings['next_opening_date'] ?? 'N/A' ?></td>
                <td><strong>Fees Balance:</strong> Ksh <?= number_format(0, 2) ?></td> <!-- Add real query if needed -->
            </tr>
        </table>
        <p style="text-align:right; margin-top:8px;">
            Principal: <?= htmlspecialchars($settings['principal_name'] ?? 'N/A') ?>
        </p>
    </div>
</div>

<?php if ($index < count($students) - 1): ?>
    <div class="page-break"></div>
<?php endif; ?>

<?php endforeach; ?>

</body>
</html>
<?php

$html = ob_get_clean();

// ────────────────────────────────────────────────────────────────
// Generate PDF
// ────────────────────────────────────────────────────────────────

$options = new Options();
$options->set('isRemoteEnabled', true);      // Allow external images
$options->set('defaultFont', 'DejaVuSans');  // Supports more characters
$options->set('isHtml5ParserEnabled', true);
$options->set('isPhpEnabled', false);        // Security: disable PHP in HTML

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// ── Filename ──
$filename = ($student_id > 0)
    ? str_replace(' ', '_', htmlspecialchars($students[0]['full_name'] ?? 'Student')) . "_{$term}_{$year}.pdf"
    : "Transcripts_{$term}_{$year}.pdf";

// ── Output as download ──
$dompdf->stream($filename, ['Attachment' => true]);

exit;