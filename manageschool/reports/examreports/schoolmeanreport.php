<?php
ob_start();
require __DIR__ . '/../../../connection/db.php';

// ====================== PARAMETERS ======================
$year    = (int)($_GET['year'] ?? 0);
$exam_id = (int)($_GET['exam_id'] ?? 0);

if (!$year || !$exam_id) {
    die('Year and Exam ID are required.');
}

// ====================== GET SCHOOL_ID + TERM + EXAM NAME ======================
$stmt = $conn->prepare("SELECT school_id, term, exam_name FROM exams WHERE exam_id = ?");
$stmt->bind_param("i", $exam_id);
$stmt->execute();
$exam_row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$school_id = $exam_row['school_id'] ?? 0;
$term      = $exam_row['term'] ?? '';
$exam_name = $exam_row['exam_name'] ?? 'Unknown Exam';

if (!$school_id) die('Exam not found.');

// ====================== SCHOOL DETAILS ======================
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

// ====================== GRADE DISTRIBUTION ======================
$stmt = $conn->prepare("
    SELECT mean_grade, COUNT(*) AS entry
    FROM exam_aggregates ea
    JOIN classes c ON ea.class_id = c.class_id
    WHERE ea.school_id = ?
      AND YEAR(ea.created_at) = ?
      AND ea.term = ?
      AND c.is_cbc = 0
    GROUP BY mean_grade
");
$stmt->bind_param("iis", $school_id, $year, $term);
$stmt->execute();
$result = $stmt->get_result();

$grades = [];
$total_students = 0;
while ($row = $result->fetch_assoc()) {
    $grades[$row['mean_grade']] = (int)$row['entry'];
    $total_students += (int)$row['entry'];
}
$stmt->close();

// ====================== YOUR REQUESTED MEAN POINT CALCULATION ======================
// Points map (exactly from your grading_rules)
$points_map = [
    'A'  => 12,
    'A-' => 11,
    'B+' => 10,
    'B'  => 9,
    'B-' => 8,
    'C+' => 7,
    'C'  => 6,
    'C-' => 5,
    'D+' => 4,
    'D'  => 3,
    'D-' => 2,
    'E'  => 1
];

$sum_points = 0;
foreach ($grades as $grade => $count) {
    $sum_points += $count * ($points_map[$grade] ?? 0);
}

$mean_point_raw = $total_students > 0 ? $sum_points / $total_students : 0;
$mean_point     = round($mean_point_raw, 3);           // display value
$floor_point    = floor($mean_point_raw);              // for grade lookup (no rounding)

// Grade lookup using floored value
function getMeanGrade($point)
{
    if ($point >= 12) return 'A';
    if ($point >= 11) return 'A-';
    if ($point >= 10) return 'B+';
    if ($point >= 9)  return 'B';
    if ($point >= 8)  return 'B-';
    if ($point >= 7)  return 'C+';
    if ($point >= 6)  return 'C';
    if ($point >= 5)  return 'C-';
    if ($point >= 4)  return 'D+';
    if ($point >= 3)  return 'D';
    if ($point >= 2)  return 'D-';
    return 'E';
}
$mean_grade = getMeanGrade($floor_point);

// Order grades for table
$ordered = ['A', 'A-', 'B+', 'B', 'B-', 'C+', 'C', 'C-', 'D+', 'D', 'D-', 'E'];
$grade_rows = [];
foreach ($ordered as $g) {
    $grade_rows[] = ['grade' => $g, 'entry' => $grades[$g] ?? 0];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Mean Grade Analysis</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        /* EXACT SAME STYLES AS ExamReport.php */
        .examreport_container {
            max-width: 820px;
            margin: 10px auto;
            background: #fff;
            padding: 12px 15px;
            border-radius: 6px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
            font-size: 12px;
            page-break-after: always;
            font-family: Arial, Helvetica, sans-serif;
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

        .examreport_remarks {
            border: 1px solid #0d6efd;
            padding: 6px 8px;
            margin-bottom: 6px;
            background: #f1f8ff;
            border-left: 5px solid #0d6efd;
            border-radius: 4px;
            font-size: 12px;
        }

        .examreport_footer {
            margin-top: 10px;
            font-size: 12px;
            border-top: 2px solid #0d6efd;
            padding-top: 5px;
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

        @media print {
            body {
                background: none;
                margin: 0;
                font-size: 12px;
            }

            .examreport_container {
                box-shadow: none;
                border-radius: 0;
                max-width: 100%;
                padding: 10mm;
            }

            .no-print {
                display: none !important;
            }
        }

        @page {
            size: A4;
            margin: 10mm;
        }
    </style>
</head>

<body>

    <!-- Sticky Header (EXACT SAME) -->
    <div class="no-print" style="display:flex;align-items:center;justify-content:space-between;background:#1a1f71;padding:10px 20px;border-radius:8px;color:#fff;margin-bottom:15px;position:sticky;top:0;z-index:9999;">
        <div style="display:flex;align-items:center;gap:10px;">
            <img src="<?php echo htmlspecialchars($school_logo); ?>" style="height:50px;border-radius:5px;">
            <span style="font-size:18px;font-weight:bold;"><?php echo htmlspecialchars($school['name'] ?? 'School'); ?></span>
        </div>
        <div style="flex:1;text-align:center;font-weight:bold;font-size:16px;">
            MeanGrade Analysis: <?php echo htmlspecialchars($exam_name); ?> (<?php echo htmlspecialchars($term . ' - ' . $year); ?>)
        </div>
        <div style="display:flex;gap:8px;">
            <button style="background:#ff6b6b;border:none;padding:6px 12px;border-radius:5px;color:#fff;" onclick="window.location.href='../index.php'">Back</button> <button style="background:#007bff;border:none;padding:6px 12px;border-radius:5px;color:#fff;" onclick="window.print()">Print</button>
            <button style="background:#20c997;border:none;padding:6px 12px;border-radius:5px;color:#fff;" onclick="downloadReport()">Download PDF</button>
        </div>
    </div>

    <!-- Download Modal -->
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

    <div class="examreport_container">
        <div class="examreport_header">
            <img src="<?php echo htmlspecialchars($school_logo); ?>" alt="Logo" /><br />
            <h2 class="examreport_h2"><?php echo htmlspecialchars($school['name'] ?? 'School'); ?></h2>
            <p class="mb-0 fw-bold">MeanGrade Analysis</p>
            <p class="mb-0"><?php echo htmlspecialchars($exam_name . ' ' . $term . ' Year ' . $year); ?></p>
        </div>

        <table class="table table-bordered table-sm text-center align-middle mb-2 examreport_table">
            <thead>
                <tr>
                    <th>Grade</th>
                    <th>Entry</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($grade_rows as $r): ?>
                    <tr>
                        <td><?php echo $r['grade']; ?></td>
                        <td><?php echo $r['entry']; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="examreport_remarks">
            <strong>Students Count/Entry:</strong> <?php echo $total_students; ?> &nbsp;&nbsp;
            <strong>Mean Point:</strong> <?php echo $mean_point; ?> &nbsp;&nbsp;
            <strong>Mean Grade:</strong> <?php echo $mean_grade; ?>
        </div>

        <footer class="examreport_footer text-center">
            Generated on <?php echo date('Y-m-d H:i:s'); ?> &nbsp;&nbsp; Page 1
        </footer>
    </div>

    <script>
        function downloadReport() {
            const modal = new bootstrap.Modal(document.getElementById('downloadModal'), {
                backdrop: 'static',
                keyboard: false
            });
            modal.show();
            const opt = {
                margin: 10,
                filename: 'School_Mean_<?php echo $year; ?>.pdf',
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
            };
            html2pdf().set(opt).from(document.querySelector('.examreport_container')).save().then(() => modal.hide())
                .catch(() => {
                    modal.hide();
                    alert('PDF generation failed.');
                });
        }
    </script>
</body>

</html>
<?php ob_end_flush(); ?>