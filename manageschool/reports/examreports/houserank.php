<?php
ob_start();
require __DIR__ . '/../../../connection/db.php';

// ─── PARAMETERS ────────────────────────────────────────────────────────────────
$year     = (int)($_POST['year'] ?? 0);
$term     = trim($_POST['term'] ?? '');
$exam_ids = array_map('intval', (array)($_POST['exam_ids'] ?? []));

if ($year < 2000 || empty($term) || empty($exam_ids)) {
    die("Year, Term and at least one exam are required.");
}

$exam_ids_str = implode(',', $exam_ids);

// ─── Get school_id ─────────────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT school_id FROM exams WHERE exam_id IN ($exam_ids_str) LIMIT 1");
$stmt->execute();
$school_id = $stmt->get_result()->fetch_column() ?: 0;
$stmt->close();

if (!$school_id) die("Invalid exam selection.");

// ─── School info ───────────────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT name, logo FROM schools WHERE school_id = ?");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$school = $stmt->get_result()->fetch_assoc();
$stmt->close();

$school_name = $school['name'] ?? 'School';
$school_logo = $school['logo'] ?? 'https://academics.sifms.co.ke/manageschool/logos/school-logo.png';
if (strpos($school_logo, 'http') !== 0) {
    $school_logo = 'https://academics.sifms.co.ke/manageschool/logos/' . basename($school_logo);
}

// ─── MAIN QUERY ────────────────────────────────────────────────────────────────
// Now we sum total_points per student and count unique students per house
$stmt = $conn->prepare("
    SELECT 
        h.name AS house_name,
        SUM(ea.total_points) AS total_house_points,
        COUNT(DISTINCT ea.student_id) AS total_students
    FROM exam_aggregates ea
    JOIN student_houses sh 
        ON ea.student_id = sh.student_id 
       AND sh.academic_year = ?
       AND sh.is_current = 1
    JOIN houses h ON sh.house_id = h.house_id
    WHERE ea.exam_id IN ($exam_ids_str)
      AND ea.school_id = ?
      AND ea.year = ?
      AND ea.term = ?
    GROUP BY h.house_id, h.name
    ORDER BY total_house_points DESC
");
$stmt->bind_param("iiii", $year, $school_id, $year, $term);
$stmt->execute();
$result = $stmt->get_result();

$final = [];
while ($row = $result->fetch_assoc()) {
    $house_name     = $row['house_name'];
    $total_points   = (float)$row['total_house_points'];
    $total_students = (int)$row['total_students'];

    // House mean points = total points of all students ÷ number of unique students
    $mean_points = ($total_students > 0)
        ? round($total_points / $total_students, 3)
        : 0.000;

    $floor = floor($mean_points);

    $grade = 'E';
    if ($floor >= 12) $grade = 'A';
    elseif ($floor >= 11) $grade = 'A-';
    elseif ($floor >= 10) $grade = 'B+';
    elseif ($floor >= 9)  $grade = 'B';
    elseif ($floor >= 8)  $grade = 'B-';
    elseif ($floor >= 7)  $grade = 'C+';
    elseif ($floor >= 6)  $grade = 'C';
    elseif ($floor >= 5)  $grade = 'C-';
    elseif ($floor >= 4)  $grade = 'D+';
    elseif ($floor >= 3)  $grade = 'D';
    elseif ($floor >= 2)  $grade = 'D-';

    $final[] = [
        'house_name'   => $house_name,
        'students'     => $total_students,
        'mean_point'   => $mean_points,
        'mean_grade'   => $grade
    ];
}
$stmt->close();

usort($final, fn($a, $b) => $b['mean_point'] <=> $a['mean_point']);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>House Rank Analysis - <?= htmlspecialchars($term . ' ' . $year) ?></title>
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

    <div class="no-print" style="display:flex;align-items:center;justify-content:space-between;background:#1a1f71;padding:10px 20px;border-radius:8px;color:#fff;margin-bottom:15px;position:sticky;top:0;z-index:9999;">
        <div style="display:flex;align-items:center;gap:10px;">
            <img src="<?= htmlspecialchars($school_logo) ?>" style="height:50px;border-radius:5px;">
            <span style="font-size:18px;font-weight:bold;"><?= htmlspecialchars($school_name) ?></span>
        </div>
        <div style="flex:1;text-align:center;font-weight:bold;font-size:16px;">
            House Rank Analysis (<?= htmlspecialchars($term . ' ' . $year) ?>)
        </div>
        <div style="display:flex;gap:8px;">
            <button style="background:#ff6b6b;border:none;padding:6px 12px;border-radius:5px;color:#fff;" onclick="history.back()">Back</button>
            <button style="background:#007bff;border:none;padding:6px 12px;border-radius:5px;color:#fff;" onclick="window.print()">Print</button>
            <button style="background:#20c997;border:none;padding:6px 12px;border-radius:5px;color:#fff;" onclick="downloadReport()">Download PDF</button>
        </div>
    </div>

    <div class="examreport_container">
        <div class="examreport_header">
            <img src="<?= htmlspecialchars($school_logo) ?>" alt="Logo" /><br>
            <h2 class="examreport_h2"><?= htmlspecialchars($school_name) ?></h2>
            <p class="mb-0 fw-bold">House Rank Analysis</p>
            <p class="mb-0"><?= htmlspecialchars($term . ' ' . $year) ?></p>
        </div>

        <table class="table table-bordered table-sm text-center align-middle mb-2 examreport_table">
            <thead>
                <tr>
                    <th>Rank</th>
                    <th>House</th>
                    <th>Students</th>
                    <th>Mean Points</th>
                    <th>Mean Grade</th>
                </tr>
            </thead>
            <tbody>
                <?php $rank = 1;
                foreach ($final as $i => $h): ?>
                    <tr>
                        <td><strong><?= $rank ?></strong></td>
                        <td><strong><?= htmlspecialchars($h['house_name']) ?></strong></td>
                        <td><?= number_format($h['students']) ?></td>
                        <td><strong><?= number_format($h['mean_point'], 3) ?></strong></td>
                        <td><strong><?= htmlspecialchars($h['mean_grade']) ?></strong></td>
                    </tr>
                    <?php
                    if ($i < count($final) - 1 && $final[$i + 1]['mean_point'] < $h['mean_point']) {
                        $rank = $i + 2;
                    }
                    ?>
                <?php endforeach; ?>

                <?php if (empty($final)): ?>
                    <tr>
                        <td colspan="5" class="text-muted py-4 text-center">No house performance data found</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <footer class="examreport_footer text-center">
            Generated on <?= date('Y-m-d H:i:s') ?>        Page 1
        </footer>
    </div>

    <script>
        function downloadReport() {
            const opt = {
                margin: 10,
                filename: `House_Rank_<?= $year ?>_<?= urlencode($term) ?>.pdf`,
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
                    orientation: 'portrait'
                }
            };
            html2pdf().set(opt).from(document.querySelector('.examreport_container')).save();
        }
    </script>

</body>

</html>

<?php ob_end_flush(); ?>