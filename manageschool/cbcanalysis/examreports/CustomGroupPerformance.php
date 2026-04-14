<?php
// reports/cbcanalysis/examreports/CustomGroupPerformance.php
session_start();
ob_start();
require __DIR__ . '../../../../connection/db.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['school_id']) || !isset($_SESSION['role_id'])) {
    header("Location: ../../login.php");
    exit;
}

$school_id = $_SESSION['school_id'];
$year      = $_POST['year']      ?? date('Y');
$class_id  = (int)($_POST['class_id']  ?? 0);
$exam_id   = (int)($_POST['exam_id']   ?? 0);
$group_id  = (int)($_POST['group_id']  ?? 0);

if (!$class_id || !$exam_id || !$group_id) {
    die("Required parameters missing: Form, Exam, and Custom Group are all required.");
}

// ────────────────────────────────────────────────
// Fetch school, class, exam, group details
// ────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT name, logo FROM schools WHERE school_id = ?");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$school = $stmt->get_result()->fetch_assoc();
$stmt->close();

$school_name = $school['name'] ?? 'School Name';
$school_logo = $school['logo'] ?? '';
if (empty($school_logo)) {
    $school_logo = 'https://academics.sifms.co.ke/manageschool/logos/school-logo.png';
} elseif (strpos($school_logo, 'http') !== 0) {
    $school_logo = 'https://academics.sifms.co.ke/manageschool/logos/' . basename($school_logo);
}

$stmt = $conn->prepare("SELECT form_name FROM classes WHERE class_id = ? AND school_id = ?");
$stmt->bind_param("ii", $class_id, $school_id);
$stmt->execute();
$class = $stmt->get_result()->fetch_assoc();
$stmt->close();
$class_name = $class['form_name'] ?? '';

$stmt = $conn->prepare("SELECT exam_name, term FROM exams WHERE exam_id = ? AND school_id = ?");
$stmt->bind_param("ii", $exam_id, $school_id);
$stmt->execute();
$exam = $stmt->get_result()->fetch_assoc();
$stmt->close();
$exam_name = $exam['exam_name'] ?? 'Unknown Exam';
$term      = $exam['term'] ?? '';

$stmt = $conn->prepare("SELECT name FROM custom_groups WHERE group_id = ? AND school_id = ?");
$stmt->bind_param("ii", $group_id, $school_id);
$stmt->execute();
$group = $stmt->get_result()->fetch_assoc();
$stmt->close();
$group_name = $group['name'] ?? 'Unknown Group';

// ────────────────────────────────────────────────
// CBC Grading function (same as your sample)
// ────────────────────────────────────────────────
function getCBCGradeAndPoints($score)
{
    if (!is_numeric($score) || $score < 0) return ['grade' => '-', 'points' => 0];
    if ($score >= 90) return ['grade' => 'EE1', 'points' => 8];
    if ($score >= 75) return ['grade' => 'EE2', 'points' => 7];
    if ($score >= 58) return ['grade' => 'ME1', 'points' => 6];
    if ($score >= 41) return ['grade' => 'ME2', 'points' => 5];
    if ($score >= 31) return ['grade' => 'AE1', 'points' => 4];
    if ($score >= 21) return ['grade' => 'AE2', 'points' => 3];
    if ($score >= 11) return ['grade' => 'BE1', 'points' => 2];
    return ['grade' => 'BE2', 'points' => 1];
}

// ────────────────────────────────────────────────
// Fetch students in the custom group
// ────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT s.student_id, s.admission_no, s.full_name
    FROM custom_group_students cgs
    JOIN students s ON cgs.student_id = s.student_id
    WHERE cgs.group_id = ? AND s.school_id = ? AND s.deleted_at IS NULL
    ORDER BY s.full_name ASC
");
$stmt->bind_param("ii", $group_id, $school_id);
$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ────────────────────────────────────────────────
// Fetch subjects for this exam
// ────────────────────────────────────────────────
$subjects = [];
$stmt = $conn->prepare("
    SELECT es.subject_id, s.name
    FROM exam_subjects es
    JOIN subjects s ON es.subject_id = s.subject_id
    WHERE es.exam_id = ?
    ORDER BY s.name
");
$stmt->bind_param("i", $exam_id);
$stmt->execute();
$subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ────────────────────────────────────────────────
// Fetch results for students in this group
// ────────────────────────────────────────────────
$student_results = [];
if (!empty($students)) {
    $student_ids = array_column($students, 'student_id');
    $placeholders = implode(',', array_fill(0, count($student_ids), '?'));

    $stmt = $conn->prepare("
        SELECT r.student_id, r.subject_id, r.score
        FROM results r
        WHERE r.exam_id = ?
          AND r.student_id IN ($placeholders)
          AND r.status = 'confirmed'
          AND r.score IS NOT NULL
    ");
    $types = 'i' . str_repeat('i', count($student_ids));
    $params = array_merge([$exam_id], $student_ids);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($results as $r) {
        $student_results[$r['student_id']][$r['subject_id']] = $r['score'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Custom Group Performance - <?= htmlspecialchars($group_name) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

    <style>
        .report_container {
            max-width: 1200px;
            margin: 10px auto;
            background: #fff;
            padding: 12px 15px;
            border-radius: 6px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
            font-size: 12px;
            font-family: Arial, Helvetica, sans-serif;
        }

        .report_h2 {
            font-size: 16px;
            margin-bottom: 2px;
            font-weight: bold;
            color: #0d6efd;
        }

        .report_header {
            text-align: center;
            border-bottom: 2px solid #0d6efd;
            margin-bottom: 6px;
            padding-bottom: 4px;
        }

        .report_header img {
            width: 100px;
            height: 100px;
            object-fit: contain;
            margin-bottom: 4px;
        }

        .report_table th {
            background: #e9f2ff !important;
            color: #0d47a1;
            text-align: center;
            font-size: 11px;
        }

        .report_table td {
            padding: 3px 5px !important;
            vertical-align: middle;
            font-size: 11px;
            border: 1px solid #dee2e6;
            text-align: center;
        }

        .report_title {
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

        .grade-ee {
            background: #d4edda;
            font-weight: bold;
        }

        .grade-me {
            background: #fff3cd;
        }

        .grade-ae {
            background: #cce5ff;
        }

        .grade-be {
            background: #f8d7da;
        }

        .mean-row {
            font-weight: bold;
            background: #f1f3f5;
        }

        .red-text {
            color: red;
            font-weight: bold;
        }

        @media print {
            body {
                margin: 0;
                font-size: 12px;
                background: none;
            }

            .report_container {
                box-shadow: none;
                border-radius: 0;
                max-width: 100%;
                padding: 10mm;
                margin: 0;
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
</head>

<body>

    <!-- Sticky Header (hidden in print) -->
    <div class="no-print" style="background:#1a1f71; color:white; padding:10px 20px; border-radius:8px; margin-bottom:15px; position:sticky; top:0; z-index:9999; display:flex; justify-content:space-between; align-items:center; box-shadow:0 4px 8px rgba(0,0,0,0.3);">
        <div style="display:flex; align-items:center; gap:12px;">
            <img src="<?= htmlspecialchars($school_logo) ?>" alt="Logo" style="height:50px; border-radius:5px;">
            <span style="font-weight:bold; font-size:18px;"><?= htmlspecialchars($school_name) ?></span>
        </div>

        <div style="flex:1; text-align:center; font-weight:bold; font-size:16px;">
            Custom Group Performance: <?= htmlspecialchars($group_name) ?> (<?= htmlspecialchars($class_name) ?> • <?= htmlspecialchars($exam_name) ?> • <?= htmlspecialchars($term) ?> • <?= $year ?>)
        </div>

        <div style="display:flex; gap:10px;">
            <button class="btn btn-danger btn-sm" onclick="history.back()">
                <i class="bi bi-arrow-left"></i> Back
            </button>
            <button class="btn btn-primary btn-sm" onclick="window.print()">
                <i class="bi bi-printer"></i> Print
            </button>
            <button class="btn btn-success btn-sm" onclick="downloadReport()">
                <i class="bi bi-download"></i> Download PDF
            </button>
        </div>
    </div>

    <div class="report_container">
        <!-- Header -->
        <div class="report_header">
            <img src="<?= htmlspecialchars($school_logo) ?>" alt="Logo" /><br>
            <h2 class="report_h2"><?= htmlspecialchars($school_name) ?></h2>
            <p class="mb-0 fw-bold">CUSTOM GROUP PERFORMANCE REPORT</p>
            <p class="mb-0"><?= htmlspecialchars($class_name) ?> • <?= htmlspecialchars($exam_name) ?> • <?= htmlspecialchars($term) ?> • <?= $year ?></p>
            <p class="mb-0"><strong>Group:</strong> <?= htmlspecialchars($group_name) ?></p>
        </div>

        <div class="report_title">STUDENT SUBJECT GRADES & AGGREGATES – CBC</div>

        <div class="table-responsive">
            <table class="table table-bordered table-sm text-center report_table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>ADM NO</th>
                        <th>Student Name</th>
                        <?php foreach ($subjects as $subj): ?>
                            <th title="<?= htmlspecialchars($subj['name']) ?>"><?= htmlspecialchars(substr($subj['name'], 0, 18)) ?></th>
                        <?php endforeach; ?>
                        <th>Total Points</th>
                        <th>Mean Points</th>
                        <th>Grade</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $rank = 1;
                    foreach ($students as $student):
                        $student_id = $student['student_id'];
                        $scores = $student_results[$student_id] ?? [];
                        $total_points = 0;
                        $subject_count = 0;
                    ?>
                        <tr>
                            <td><?= $rank++ ?></td>
                            <td><?= htmlspecialchars($student['admission_no']) ?></td>
                            <td style="text-align:left;"><?= htmlspecialchars($student['full_name']) ?></td>

                            <?php foreach ($subjects as $subj):
                                $score = $scores[$subj['subject_id']] ?? null;
                                $grade_info = $score !== null ? getCBCGradeAndPoints($score) : ['grade' => '—', 'points' => 0];
                                $total_points += $grade_info['points'];
                                $subject_count++;

                                $className = '';
                                if (strpos($grade_info['grade'], 'EE') === 0) $className = 'grade-ee';
                                elseif (strpos($grade_info['grade'], 'ME') === 0) $className = 'grade-me';
                                elseif (strpos($grade_info['grade'], 'AE') === 0) $className = 'grade-ae';
                                elseif (strpos($grade_info['grade'], 'BE') === 0) $className = 'grade-be';
                            ?>
                                <td class="<?= $className ?>"><?= $grade_info['grade'] ?></td>
                            <?php endforeach; ?>

                            <td class="mean-row"><?= $total_points ?></td>
                            <td class="mean-row"><?= $subject_count > 0 ? number_format($total_points / $subject_count, 2) : '—' ?></td>
                            <td class="mean-row"><?= $subject_count > 0 ? getCBCGradeAndPoints($total_points / $subject_count)['grade'] : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (empty($students)): ?>
                        <tr>
                            <td colspan="<?= count($subjects) + 6 ?>" class="text-center text-danger py-4">
                                No students found in this custom group for the selected exam.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Footer -->
        <footer class="mt-4 text-center">
            <small>Generated on: <?= date('Y-m-d H:i:s') ?> • Page 1 of 1</small>
        </footer>
    </div>

    <script>
        function downloadReport() {
            const element = document.querySelector('.report_container');
            const opt = {
                margin: 10,
                filename: `CustomGroup_${'<?= addslashes(str_replace(" ", "_", $group_name)) ?>'}_${'<?= $year ?>'}.pdf`,
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
                    orientation: 'landscape'
                }
            };
            html2pdf().set(opt).from(element).save();
        }
    </script>

</body>

</html>

<?php ob_end_flush(); ?>