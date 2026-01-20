<?php
// reports/examreports/CustomGroupReport.php
ob_start();
require __DIR__ . '/../../../connection/db.php';

// SESSION / SCHOOL ID
session_start();
$school_id = $_SESSION['school_id'] ?? 0;
if ($school_id <= 0) {
    die('Session error: School ID missing.');
}

// Get parameters
$group_id = (int)($_GET['group_id'] ?? 0);
$exam_id  = (int)($_GET['exam_id'] ?? 0);

if ($group_id <= 0 || $exam_id <= 0) {
    die('Group ID and Exam ID are required.');
}

// 1. School info
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

// 2. Group name + class_id
$stmt = $conn->prepare("SELECT name, class_id FROM custom_groups WHERE group_id = ? AND school_id = ?");
$stmt->bind_param("ii", $group_id, $school_id);
$stmt->execute();
$group = $stmt->get_result()->fetch_assoc();
$stmt->close();
$group_name = $group['name'] ?? 'Unknown Group';
$class_id   = $group['class_id'] ?? 0;

// 3. Exam info
$stmt = $conn->prepare("SELECT exam_name, term FROM exams WHERE exam_id = ? AND school_id = ?");
$stmt->bind_param("ii", $exam_id, $school_id);
$stmt->execute();
$exam = $stmt->get_result()->fetch_assoc();
$stmt->close();
$exam_name = $exam['exam_name'] ?? 'Unknown Exam';
$term = $exam['term'] ?? 'N/A';

// 4. Subjects from exam + filter by custom group
$stmt = $conn->prepare("
    SELECT DISTINCT es.subject_id, s.name
    FROM exam_subjects es
    JOIN subjects s ON es.subject_id = s.subject_id
    JOIN custom_group_subjects cgs ON cgs.subject_id = es.subject_id AND cgs.group_id = ?
    WHERE es.exam_id = ?
    ORDER BY s.name
");
$stmt->bind_param("ii", $group_id, $exam_id);
$stmt->execute();
$subjects_result = $stmt->get_result();
$subjects = [];
while ($row = $subjects_result->fetch_assoc()) {
    $subjects[$row['subject_id']] = $row['name'];
}
$stmt->close();

if (empty($subjects)) {
    die('No matching subjects found between the selected exam and custom group.');
}

// 5. Students in group
$stmt = $conn->prepare("
    SELECT s.student_id, s.full_name, s.admission_no
    FROM custom_group_students cgs
    JOIN students s ON cgs.student_id = s.student_id
    WHERE cgs.group_id = ? AND s.school_id = ? AND s.class_id = ?
    ORDER BY s.full_name
");
$stmt->bind_param("iii", $group_id, $school_id, $class_id);
$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$total_students = count($students);
if ($total_students === 0) {
    die('No students found in this custom group.');
}

// 6. Fetch scores
$student_ids = array_column($students, 'student_id');
$placeholders = implode(',', array_fill(0, count($student_ids), '?'));
$stmt = $conn->prepare("
    SELECT esa.student_id, esa.subject_id, esa.subject_score
    FROM exam_subject_aggregates esa
    WHERE esa.exam_id = ? AND esa.student_id IN ($placeholders)
");
$params = array_merge([$exam_id], $student_ids);
$types = 'i' . str_repeat('i', count($student_ids));
$stmt->bind_param($types, ...$params);
$stmt->execute();
$scores_result = $stmt->get_result();
$scores = [];
while ($row = $scores_result->fetch_assoc()) {
    $scores[$row['student_id']][$row['subject_id']] = $row['subject_score'];
}
$stmt->close();

// 7. Build data + ranks
$report_data = [];
foreach ($students as $stu) {
    $row = [
        'full_name'    => $stu['full_name'],
        'admission_no' => $stu['admission_no'],
        'subjects'     => []
    ];
    foreach ($subjects as $sub_id => $sub_name) {
        $score = $scores[$stu['student_id']][$sub_id] ?? null;
        $row['subjects'][$sub_id] = ['score' => $score];
    }
    $report_data[] = $row;
}

// Rank per subject (group-internal)
foreach (array_keys($subjects) as $sub_id) {
    $subject_scores = [];
    foreach ($report_data as $idx => $row) {
        $score = $row['subjects'][$sub_id]['score'];
        if ($score !== null) {
            $subject_scores[] = ['idx' => $idx, 'score' => $score];
        }
    }
    usort($subject_scores, fn($a, $b) => $b['score'] <=> $a['score']);
    $rank = 1;
    $prev_score = null;
    foreach ($subject_scores as $pos => $entry) {
        if ($prev_score !== null && $entry['score'] < $prev_score) {
            $rank = $pos + 1;
        }
        $report_data[$entry['idx']]['subjects'][$sub_id]['rank'] = $rank;
        $prev_score = $entry['score'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Custom Group Report - <?= htmlspecialchars($group_name) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        .examreport_container {
            max-width: 1000px;
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
            white-space: nowrap;
        }

        .examreport_table th,
        .examreport_table td {
            padding: 4px 6px !important;
            vertical-align: middle;
            font-size: 11px;
            border: 1px solid #dee2e6;
        }

        .examreport_table .subject-col {
            text-align: left !important;
        }

        .examreport_footer {
            margin-top: 10px;
            font-size: 12px;
            border-top: 2px solid #0d6efd;
            padding-top: 5px;
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
            size: landscape;
            margin: 10mm;
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
            Group Report: <?= htmlspecialchars($group_name) ?>
        </div>
        <div style="display:flex;gap:8px;">
            <button style="background:#ff6b6b;border:none;padding:6px 12px;border-radius:5px;color:#fff;" onclick="history.back()">Back</button>
            <button style="background:#007bff;border:none;padding:6px 12px;border-radius:5px;color:#fff;" onclick="window.print()">Print</button>
            <button style="background:#20c997;border:none;padding:6px 12px;border-radius:5px;color:#fff;" onclick="downloadReport()">Download PDF</button>
        </div>
    </div>

    <div class="examreport_container">
        <div class="examreport_header">
            <img src="<?= htmlspecialchars($school_logo) ?>" alt="Logo" /><br />
            <h2 class="examreport_h2"><?= htmlspecialchars($school_name) ?></h2>
            <p class="mb-0 fw-bold">Custom Group Subject Results Report</p>
            <p class="mb-0"><?= htmlspecialchars($group_name . ' – ' . $exam_name . ' (' . $term . ')') ?></p>
        </div>

        <table class="table table-bordered table-sm text-center align-middle mb-2 examreport_table">
            <thead>
                <tr>
                    <th class="subject-col" rowspan="2">Student Name</th>
                    <th rowspan="2">Adm No</th>
                    <?php foreach ($subjects as $sub_id => $sub_name):
                        // Count students with score for this subject
                        $count_with_score = 0;
                        foreach ($report_data as $row) {
                            if ($row['subjects'][$sub_id]['score'] !== null) $count_with_score++;
                        }
                    ?>
                        <th colspan="2"><?= htmlspecialchars($sub_name) ?></th>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <?php foreach ($subjects as $sub_id => $sub_name):
                        $count_with_score = 0;
                        foreach ($report_data as $row) {
                            if ($row['subjects'][$sub_id]['score'] !== null) $count_with_score++;
                        }
                    ?>
                        <th>Score</th>
                        <th>Rank (out of <?= $count_with_score ?: $total_students ?>)</th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($report_data)): ?>
                    <tr>
                        <td colspan="<?= (count($subjects) * 2) + 2 ?>" class="text-center py-4">
                            No students or results found in this group for the selected exam.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($report_data as $row): ?>
                        <tr>
                            <td class="subject-col"><?= htmlspecialchars($row['full_name']) ?></td>
                            <td><?= htmlspecialchars($row['admission_no']) ?></td>
                            <?php foreach (array_keys($subjects) as $sub_id):
                                $data = $row['subjects'][$sub_id] ?? null;
                                $score_display = $data && $data['score'] !== null ? number_format($data['score'], 2) : '-';
                                $rank_display  = $data && $data['score'] !== null ? $data['rank'] : '-';
                            ?>
                                <td><?= $score_display ?></td>
                                <td><?= $rank_display ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="examreport_footer text-center">
            <small>Generated on <?= date('d M Y H:i') ?> | Total Students in Group: <?= $total_students ?></small>
        </div>
    </div>

    <script>
        function downloadReport() {
            const element = document.querySelector('.examreport_container');
            const opt = {
                margin: 10,
                filename: 'Custom_Group_Results_<?= date('Ymd') ?>.pdf',
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
            html2pdf().set(opt).from(element).save();
        }
    </script>

</body>

</html>

<?php ob_end_flush(); ?>