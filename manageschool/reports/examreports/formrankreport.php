<?php
ob_start();
require __DIR__ . '/../../../connection/db.php';

$year     = (int)($_POST['year'] ?? 0);
$term     = $_POST['term'] ?? '';
$exam_ids = $_POST['exam_ids'] ?? [];

if (!$year || empty($term) || empty($exam_ids)) {
    die('Year, Term and at least one exam are required.');
}

$exam_ids = array_map('intval', (array)$exam_ids);

// Get school info
$stmt = $conn->prepare("SELECT school_id, exam_name FROM exams WHERE exam_id = ? LIMIT 1");
$stmt->bind_param("i", $exam_ids[0]);
$stmt->execute();
$first = $stmt->get_result()->fetch_assoc();
$stmt->close();

$school_id = $first['school_id'] ?? 0;
$exam_name = "Multiple Exams (" . count($exam_ids) . ")";

$stmt = $conn->prepare("SELECT name, logo FROM schools WHERE school_id = ?");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$school = $stmt->get_result()->fetch_assoc();
$stmt->close();

$school_logo = $school['logo'] ?? 'https://academics.sifms.co.ke/manageschool/logos/school-logo.png';
if (strpos($school_logo, 'http') !== 0) {
    $school_logo = 'https://academics.sifms.co.ke/manageschool/logos/' . basename($school_logo);
}

// Points map
$points_map = ['A' => 12, 'A-' => 11, 'B+' => 10, 'B' => 9, 'B-' => 8, 'C+' => 7, 'C' => 6, 'C-' => 5, 'D+' => 4, 'D' => 3, 'D-' => 2, 'E' => 1, 'X' => 0, 'Y' => 0, 'Z' => 0];

// Query - GROUP BY FORM ONLY
$placeholders = str_repeat('?,', count($exam_ids) - 1) . '?';
$stmt = $conn->prepare("
    SELECT 
        c.form_name,
        ea.mean_grade
    FROM exam_aggregates ea
    JOIN classes c ON ea.class_id = c.class_id
    WHERE ea.exam_id IN ($placeholders)
      AND ea.school_id = ?
      AND c.is_cbc = 0
");
$params = array_merge($exam_ids, [$school_id]);
$stmt->bind_param(str_repeat('i', count($exam_ids) + 1), ...$params);
$stmt->execute();
$result = $stmt->get_result();

$form_data = [];
while ($row = $result->fetch_assoc()) {
    $form = $row['form_name'];
    $grade = $row['mean_grade'];
    if (!isset($form_data[$form])) $form_data[$form] = ['counts' => [], 'entry' => 0, 'sum_points' => 0];
    $form_data[$form]['counts'][$grade] = ($form_data[$form]['counts'][$grade] ?? 0) + 1;
    $form_data[$form]['entry']++;
    $form_data[$form]['sum_points'] += $points_map[$grade] ?? 0;
}
$stmt->close();

// Calculate
$forms = [];
foreach ($form_data as $form_name => $data) {
    $entry = $data['entry'];
    $sum   = $data['sum_points'];
    $mean_point = $entry ? round($sum / $entry, 3) : 0;
    $floor_point = floor($mean_point);

    $mean_grade = 'E';
    if ($floor_point >= 12) $mean_grade = 'A';
    elseif ($floor_point >= 11) $mean_grade = 'A-';
    elseif ($floor_point >= 10) $mean_grade = 'B+';
    elseif ($floor_point >= 9)  $mean_grade = 'B';
    elseif ($floor_point >= 8)  $mean_grade = 'B-';
    elseif ($floor_point >= 7)  $mean_grade = 'C+';
    elseif ($floor_point >= 6)  $mean_grade = 'C';
    elseif ($floor_point >= 5)  $mean_grade = 'C-';
    elseif ($floor_point >= 4)  $mean_grade = 'D+';
    elseif ($floor_point >= 3)  $mean_grade = 'D';
    elseif ($floor_point >= 2)  $mean_grade = 'D-';

    $forms[] = [
        'form_name'  => $form_name,
        'counts'     => $data['counts'],
        'entry'      => $entry,
        'points'     => $sum,
        'mean_point' => $mean_point,
        'mean_grade' => $mean_grade
    ];
}

usort($forms, fn($a, $b) => $b['mean_point'] <=> $a['mean_point']);

$ordered_grades = ['A', 'A-', 'B+', 'B', 'B-', 'C+', 'C', 'C-', 'D+', 'D', 'D-', 'E', 'X', 'Y', 'Z'];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Form Rank Report</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        .examreport_container {
            max-width: 820px;
            margin: 10px auto;
            background: #fff;
            padding: 12px 15px;
            border-radius: 6px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
            font-size: 12px;
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
            padding: 3px 5px !important;
            vertical-align: middle;
            font-size: 11px;
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
            .no-print {
                display: none;
            }
        }
    </style>
</head>

<body>

    <div class="no-print" style="display:flex;align-items:center;justify-content:space-between;background:#1a1f71;padding:10px 20px;border-radius:8px;color:#fff;margin-bottom:15px;position:sticky;top:0;z-index:9999;">
        <div style="display:flex;align-items:center;gap:10px;">
            <img src="<?php echo htmlspecialchars($school_logo); ?>" style="height:50px;border-radius:5px;">
            <span style="font-size:18px;font-weight:bold;"><?php echo htmlspecialchars($school['name'] ?? 'School'); ?></span>
        </div>
        <div style="flex:1;text-align:center;font-weight:bold;font-size:16px;">
            Form Rank: <?php echo htmlspecialchars($exam_name); ?> (<?php echo htmlspecialchars($term . ' ' . $year); ?>)
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
            <p class="mb-0 fw-bold">Form Rank Report</p>
            <p class="mb-0"><?php echo htmlspecialchars($term . ' ' . $year); ?></p>
        </div>

        <table class="table table-bordered table-sm text-center align-middle mb-2 examreport_table">
            <thead>
                <tr>
                    <th>Form</th>
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
                    <th>X</th>
                    <th>Y</th>
                    <th>Z</th>
                    <th>Entry</th>
                    <th>Points</th>
                    <th>MeanPoint</th>
                    <th>Mean Grade</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($forms as $f): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($f['form_name']); ?></strong></td>
                        <?php foreach ($ordered_grades as $g): ?>
                            <td><?php echo $f['counts'][$g] ?? 0; ?></td>
                        <?php endforeach; ?>
                        <td><strong><?php echo $f['entry']; ?></strong></td>
                        <td><strong><?php echo $f['points']; ?></strong></td>
                        <td><strong><?php echo $f['mean_point']; ?></strong></td>
                        <td><strong><?php echo $f['mean_grade']; ?></strong></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <footer class="examreport_footer text-center">
            Generated on <?php echo date('Y-m-d H:i:s'); ?> &nbsp;&nbsp; Page 1
        </footer>
    </div>

    <script>
        function downloadReport() {
            const opt = {
                margin: 10,
                filename: 'Form_Rank_<?php echo $year; ?>.pdf',
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