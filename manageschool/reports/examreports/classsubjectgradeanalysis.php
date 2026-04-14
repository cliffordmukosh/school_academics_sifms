<?php
ob_start();
require __DIR__ . '/../../../connection/db.php';

$year     = (int)($_GET['year'] ?? 0);
$term     = $_GET['term'] ?? '';
$exam_id  = (int)($_GET['exam_id'] ?? 0);
$class_id = (int)($_GET['class_id'] ?? 0);

if (!$year || empty($term) || !$exam_id || !$class_id) {
    die('Year, Term, Exam and Class are required.');
}

// School & exam info
$stmt = $conn->prepare("SELECT school_id, exam_name FROM exams WHERE exam_id = ?");
$stmt->bind_param("i", $exam_id);
$stmt->execute();
$exam_row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$school_id = $exam_row['school_id'] ?? 0;
$exam_name = $exam_row['exam_name'] ?? 'Unknown Exam';

$stmt = $conn->prepare("SELECT name, logo FROM schools WHERE school_id = ?");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$school = $stmt->get_result()->fetch_assoc();
$stmt->close();

$school_logo = $school['logo'] ?? 'https://academics.sifms.co.ke/manageschool/logos/school-logo.png';
if (strpos($school_logo, 'http') !== 0) {
    $school_logo = 'https://academics.sifms.co.ke/manageschool/logos/' . basename($school_logo);
}

// Class name
$stmt = $conn->prepare("SELECT form_name FROM classes WHERE class_id = ?");
$stmt->bind_param("i", $class_id);
$stmt->execute();
$class_row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$form_name = $class_row['form_name'] ?? 'Form';

// Streams in this class
$stmt = $conn->prepare("SELECT stream_id, stream_name FROM streams WHERE class_id = ? ORDER BY stream_name");
$stmt->bind_param("i", $class_id);
$stmt->execute();
$streams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Subjects in this exam
$stmt = $conn->prepare("
    SELECT es.subject_id, s.name AS subject_name
    FROM exam_subjects es
    JOIN subjects s ON es.subject_id = s.subject_id
    WHERE es.exam_id = ?
");
$stmt->bind_param("i", $exam_id);
$stmt->execute();
$subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Points map
$points_map = ['A' => 12, 'A-' => 11, 'B+' => 10, 'B' => 9, 'B-' => 8, 'C+' => 7, 'C' => 6, 'C-' => 5, 'D+' => 4, 'D' => 3, 'D-' => 2, 'E' => 1];

// Teachers
$teachers = [];
$stmt = $conn->prepare("
    SELECT ts.subject_id, CONCAT(u.first_name, ' ', u.other_names) AS teacher_name
    FROM teacher_subjects ts
    JOIN users u ON ts.user_id = u.user_id
    WHERE ts.class_id = ? AND ts.school_id = ?
");
$stmt->bind_param("ii", $class_id, $school_id);
$stmt->execute();
$tres = $stmt->get_result();
while ($t = $tres->fetch_assoc()) $teachers[$t['subject_id']] = $t['teacher_name'];
$stmt->close();

$report_data = [];
$ordered_grades = ['A', 'A-', 'B+', 'B', 'B-', 'C+', 'C', 'C-', 'D+', 'D', 'D-', 'E'];

foreach ($subjects as $sub) {
    $subject_id = $sub['subject_id'];
    $subject_name = $sub['subject_name'];

    $subject_rows = [];

    // Per stream
    foreach ($streams as $str) {
        $stream_id = $str['stream_id'];
        $stream_name = $str['stream_name'];

        $stmt = $conn->prepare("
            SELECT esa.subject_grade, COUNT(*) AS cnt
            FROM exam_subject_aggregates esa
            JOIN students s ON esa.student_id = s.student_id
            WHERE esa.exam_id = ? AND esa.subject_id = ? AND s.stream_id = ?
            GROUP BY esa.subject_grade
        ");
        $stmt->bind_param("iii", $exam_id, $subject_id, $stream_id);
        $stmt->execute();
        $gc = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $counts = [];
        $entry = 0;
        $sum_points = 0;
        foreach ($gc as $g) {
            $grade = $g['subject_grade'];
            $cnt = (int)$g['cnt'];
            $counts[$grade] = $cnt;
            $entry += $cnt;
            $sum_points += $cnt * ($points_map[$grade] ?? 0);
        }

        $mean_point = $entry ? round($sum_points / $entry, 3) : 0;
        $floor = floor($mean_point);
        $mean_grade = 'E';
        if ($floor >= 12) $mean_grade = 'A';
        elseif ($floor >= 11) $mean_grade = 'A-';
        elseif ($floor >= 10) $mean_grade = 'B+';
        elseif ($floor >= 9) $mean_grade = 'B';
        elseif ($floor >= 8) $mean_grade = 'B-';
        elseif ($floor >= 7) $mean_grade = 'C+';
        elseif ($floor >= 6) $mean_grade = 'C';
        elseif ($floor >= 5) $mean_grade = 'C-';
        elseif ($floor >= 4) $mean_grade = 'D+';
        elseif ($floor >= 3) $mean_grade = 'D';
        elseif ($floor >= 2) $mean_grade = 'D-';

        $subject_rows[] = [
            'stream_name' => $stream_name,
            'counts' => $counts,
            'entry' => $entry,
            'mean_point' => $mean_point,
            'mean_grade' => $mean_grade
        ];
    }

    // FORM total row
    $stmt = $conn->prepare("
        SELECT esa.subject_grade, COUNT(*) AS cnt
        FROM exam_subject_aggregates esa
        JOIN students s ON esa.student_id = s.student_id
        WHERE esa.exam_id = ? AND esa.subject_id = ? AND s.class_id = ?
        GROUP BY esa.subject_grade
    ");
    $stmt->bind_param("iii", $exam_id, $subject_id, $class_id);
    $stmt->execute();
    $form_gc = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $form_counts = [];
    $form_entry = 0;
    $form_sum = 0;
    foreach ($form_gc as $g) {
        $grade = $g['subject_grade'];
        $cnt = (int)$g['cnt'];
        $form_counts[$grade] = $cnt;
        $form_entry += $cnt;
        $form_sum += $cnt * ($points_map[$grade] ?? 0);
    }

    $form_mean = $form_entry ? round($form_sum / $form_entry, 3) : 0;
    $form_floor = floor($form_mean);
    $form_grade = 'E';
    if ($form_floor >= 12) $form_grade = 'A';
    elseif ($form_floor >= 11) $form_grade = 'A-';
    elseif ($form_floor >= 10) $form_grade = 'B+';
    elseif ($form_floor >= 9) $form_grade = 'B';
    elseif ($form_floor >= 8) $form_grade = 'B-';
    elseif ($form_floor >= 7) $form_grade = 'C+';
    elseif ($form_floor >= 6) $form_grade = 'C';
    elseif ($form_floor >= 5) $form_grade = 'C-';
    elseif ($form_floor >= 4) $form_grade = 'D+';
    elseif ($form_floor >= 3) $form_grade = 'D';
    elseif ($form_floor >= 2) $form_grade = 'D-';

    $subject_rows[] = [
        'stream_name' => 'FORM',
        'counts' => $form_counts,
        'entry' => $form_entry,
        'mean_point' => $form_mean,
        'mean_grade' => $form_grade
    ];

    $report_data[] = [
        'subject_id'   => $subject_id,          // ← THIS LINE FIXES THE ERROR
        'subject_name' => $subject_name,
        'rows'         => $subject_rows
    ];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Subject Grade Analysis</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        .examreport_container {
            max-width: 900px;
            margin: 10px auto;
            background: #fff;
            padding: 12px 15px;
            border-radius: 6px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
            font-size: 11px;
            page-break-after: always;
        }

        .examreport_h2 {
            font-size: 16px;
            margin-bottom: 2px;
            font-weight: bold;
            color: #0d6efd;
        }

        .examreport_header {
            text-align: center;
            border-bottom: 2px solid #0d6efd;
            margin-bottom: 6px;
            padding-bottom: 4px;
        }

        .examreport_header img {
            width: 100px;
            height: 100px;
            object-fit: contain;
            margin-bottom: 4px;
        }

        .examreport_table th {
            background: #e9f2ff !important;
            color: #0d47a1;
            text-align: center;
        }

        .examreport_table th,
        .examreport_table td {
            padding: 2px 4px !important;
            vertical-align: middle;
            font-size: 10px;
            border: 1px solid #dee2e6;
        }

        .examreport_footer {
            margin-top: 10px;
            font-size: 12px;
            border-top: 2px solid #0d6efd;
            padding-top: 5px;
            text-align: center;
        }

        @media print {
            body {
                background: none;
                margin: 0;
            }

            .no-print {
                display: none !important;
            }
        }
    </style>
</head>

<body>

    <!-- Sticky Header -->
    <div class="no-print" style="display:flex;align-items:center;justify-content:space-between;background:#1a1f71;padding:10px 20px;border-radius:8px;color:#fff;margin-bottom:15px;position:sticky;top:0;z-index:9999;">
        <div style="display:flex;align-items:center;gap:10px;">
            <img src="<?php echo htmlspecialchars($school_logo); ?>" style="height:50px;border-radius:5px;">
            <span style="font-size:18px;font-weight:bold;"><?php echo htmlspecialchars($school['name'] ?? 'School'); ?></span>
        </div>
        <div style="flex:1;text-align:center;font-weight:bold;font-size:16px;">
            Subject Grade Analysis - <?php echo htmlspecialchars($form_name); ?> (<?php echo htmlspecialchars($term . ' ' . $year); ?>)
        </div>
        <div style="display:flex;gap:8px;">
            <button style="background:#ff6b6b;border:none;padding:6px 12px;border-radius:5px;color:#fff;" onclick="window.location.href='../index.php'">Back</button>
            <button style="background:#007bff;border:none;padding:6px 12px;border-radius:5px;color:#fff;" onclick="window.print()">Print</button>
            <button style="background:#20c997;border:none;padding:6px 12px;border-radius:5px;color:#fff;" onclick="downloadReport()">Download PDF</button>
        </div>
    </div>

    <div class="examreport_container">
        <div class="examreport_header">
            <img src="<?php echo htmlspecialchars($school_logo); ?>" alt="Logo" /><br />
            <h2 class="examreport_h2"><?php echo htmlspecialchars($school['name'] ?? 'School'); ?></h2>
            <p class="mb-0 fw-bold">Subject Grade Analysis</p>
            <p class="mb-0"><?php echo htmlspecialchars($form_name . ' - ' . $term . ' ' . $year); ?></p>
        </div>

        <?php foreach ($report_data as $sub): ?>
            <h5 class="mt-4 mb-2"><strong><?php echo htmlspecialchars($sub['subject_name']); ?></strong></h5>
            <table class="table table-bordered table-sm text-center align-middle mb-4 examreport_table">
                <thead>
                    <tr>
                        <th>NO</th>
                        <th>Class</th>
                        <th>A</th>
                        <th>A-</th>
                        <th>B+</th>
                        <th>B</th>
                        <th>B-</th>
                        <th>C+</th>
                        <th>C</th>
                        <th>C-</th>
                        <th>D+</th>
                        <th>D</th>
                        <th>D-</th>
                        <th>E</th>
                        <th>Entry</th>
                        <th>Mean Point</th>
                        <th>Mean Grade</th>
                        <th>Target</th>
                        <th>Dev</th>
                        <th>Teacher</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $no = 1;
                    foreach ($sub['rows'] as $r):
                        $teacher = $teachers[$sub['subject_id']] ?? 'N/A';  // now safe
                    ?>
                        <tr>
                            <td><?php echo $no++; ?></td>
                            <td><strong><?php echo htmlspecialchars($r['stream_name']); ?></strong></td>
                            <?php foreach ($ordered_grades as $g): ?>
                                <td><?php echo $r['counts'][$g] ?? 0; ?></td>
                            <?php endforeach; ?>
                            <td><strong><?php echo $r['entry']; ?></strong></td>
                            <td><strong><?php echo $r['mean_point']; ?></strong></td>
                            <td><strong><?php echo $r['mean_grade']; ?></strong></td>
                            <td>-</td>
                            <td>-</td>
                            <td><?php echo htmlspecialchars($teacher); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach; ?>

        <footer class="examreport_footer text-center">
            Generated on <?php echo date('Y-m-d H:i:s'); ?> &nbsp;&nbsp; Page 1
        </footer>
    </div>

    <script>
        function downloadReport() {
            const opt = {
                margin: 10,
                filename: 'Class_Subject_Analysis_<?php echo $year; ?>.pdf',
                image: {
                    type: 'jpeg',
                    quality: 0.98
                },
                html2canvas: {
                    scale: 2
                },
                jsPDF: {
                    unit: 'mm',
                    format: 'a4',
                    orientation: 'landscape'
                }
            };
            html2pdf().set(opt).from(document.querySelector('.examreport_container')).save();
        }
    </script>
</body>

</html>
<?php ob_end_flush(); ?>