<?php
// reports/examreports/public_exam_report.php
ob_start();
require __DIR__ . '/../../../connection/db.php';

// Only allow access with student_id + exam_id (no session/login required)
$student_id = (int)($_GET['student_id'] ?? 0);
$exam_id    = (int)($_GET['exam_id']    ?? 0);

if ($student_id <= 0 || $exam_id <= 0) {
    http_response_code(400);
    die('student_id and exam_id are required.');
}

// Fetch student + school + class info
$stmt = $conn->prepare("
    SELECT 
        s.student_id, s.full_name, s.admission_no, s.profile_picture, s.gender,
        s.school_id, s.class_id, c.form_name, COALESCE(str.stream_name, '') AS stream_name,
        s.stream_id
    FROM students s
    LEFT JOIN classes c ON s.class_id = c.class_id
    LEFT JOIN streams str ON s.stream_id = str.stream_id
    WHERE s.student_id = ?
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student) {
    die('Student not found.');
}

$school_id         = $student['school_id'];
$class_id          = $student['class_id'];
$class_name        = $student['form_name'];
$stream_name       = $student['stream_name'];
$stream_id_current = $student['stream_id'] ?? 0;

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

// Fetch exam details
$stmt = $conn->prepare("
    SELECT exam_name, term, YEAR(created_at) AS year, grading_system_id, min_subjects
    FROM exams 
    WHERE exam_id = ? AND school_id = ?
");
$stmt->bind_param("ii", $exam_id, $school_id);
$stmt->execute();
$exam = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$exam) {
    die('Exam not found.');
}

$year              = $exam['year'];
$grading_system_id = $exam['grading_system_id'];
$max_total_score   = $exam['min_subjects'] ? $exam['min_subjects'] * 100 : 0;

// Fetch student's aggregate
$stmt = $conn->prepare("
    SELECT ea.*, st.full_name, st.admission_no, st.profile_picture, cl.form_name AS class_name, str.stream_name
    FROM exam_aggregates ea
    JOIN students st ON ea.student_id = st.student_id
    JOIN classes cl ON ea.class_id = cl.class_id
    LEFT JOIN streams str ON ea.stream_id = str.stream_id
    WHERE ea.exam_id = ? AND ea.student_id = ? AND ea.school_id = ?
");
$stmt->bind_param("iii", $exam_id, $student_id, $school_id);
$stmt->execute();
$agg = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$agg) {
    die('No results available for this exam.');
}

// Class stats
$stmt = $conn->prepare("
    SELECT COUNT(*) AS class_count, AVG(mean_score) AS class_mean
    FROM exam_aggregates 
    WHERE exam_id = ? AND class_id = ? AND school_id = ?
");
$stmt->bind_param("iii", $exam_id, $class_id, $school_id);
$stmt->execute();
$class_stats = $stmt->get_result()->fetch_assoc() ?? ['class_count' => 1, 'class_mean' => 0];
$stmt->close();

$class_mean_raw = $class_stats['class_mean'] ?? 0;
$class_mean     = $class_mean_raw > 0 ? number_format($class_mean_raw, 2) : 'N/A';
$class_student_count = $class_stats['class_count'];

// Stream stats
$stmt = $conn->prepare("
    SELECT COUNT(*) AS stream_count, AVG(mean_score) AS stream_mean
    FROM exam_aggregates 
    WHERE exam_id = ? AND stream_id = ? AND school_id = ?
");
$stmt->bind_param("iii", $exam_id, $stream_id_current, $school_id);
$stmt->execute();
$stream_stats = $stmt->get_result()->fetch_assoc() ?? ['stream_count' => 1, 'stream_mean' => 0];
$stmt->close();

$stream_mean_raw = $stream_stats['stream_mean'] ?? 0;
$stream_mean     = $stream_mean_raw > 0 ? number_format($stream_mean_raw, 2) : 'N/A';
$stream_student_count = $stream_stats['stream_count'];

// Grade function
function getGrade($conn, $score, $grading_system_id) {
    if ($score <= 0) return 'N/A';
    $stmt = $conn->prepare("
        SELECT grade 
        FROM grading_rules
        WHERE grading_system_id = ? 
          AND ? >= min_score AND ? <= max_score
        LIMIT 1
    ");
    $stmt->bind_param("idd", $grading_system_id, $score, $score);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result['grade'] ?? 'N/A';
}

$class_grade  = getGrade($conn, floor($class_mean_raw + 0.5), $grading_system_id);
$stream_grade = getGrade($conn, floor($stream_mean_raw + 0.5), $grading_system_id);

// Subject results
$stmt = $conn->prepare("
    SELECT esa.*, s.name AS subject_name
    FROM exam_subject_aggregates esa
    JOIN subjects s ON esa.subject_id = s.subject_id
    WHERE esa.exam_id = ? AND esa.student_id = ?
");
$stmt->bind_param("ii", $exam_id, $student_id);
$stmt->execute();
$sub_aggregates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Teachers (fallback to class-wide if no stream match)
$stmt = $conn->prepare("
    SELECT ts.subject_id, CONCAT(u.first_name, ' ', COALESCE(u.other_names, '')) AS teacher_name
    FROM teacher_subjects ts
    JOIN users u ON ts.user_id = u.user_id
    WHERE ts.school_id = ? 
      AND ts.class_id = ? 
      AND ts.academic_year = ?
      AND (ts.stream_id = ? OR ts.stream_id IS NULL OR ts.stream_id = 0)
    ORDER BY CASE WHEN ts.stream_id = ? THEN 0 ELSE 1 END
");
$stmt->bind_param("iiiii", $school_id, $class_id, $year, $stream_id_current, $stream_id_current);
$stmt->execute();
$teacher_result = $stmt->get_result();
$teachers = [];
while ($row = $teacher_result->fetch_assoc()) {
    $teachers[$row['subject_id']] = trim($row['teacher_name']);
}
$stmt->close();

// Profile picture fallback
$profile_picture = $agg['profile_picture'] ?? '';
if (empty($profile_picture)) {
    $profile_picture = 'https://academics.sifms.co.ke/manageschool/studentsprofile/defaultstudent.png';
} elseif (strpos($profile_picture, 'http') !== 0) {
    $profile_picture = 'https://academics.sifms.co.ke/' . ltrim($profile_picture, '/');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam Report</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        /* YOUR ORIGINAL STYLES - UNCHANGED */
        .examreport_container { max-width: 820px; margin: 10px auto; background: #fff; padding: 12px 15px; border-radius: 6px; box-shadow: 0 2px 6px rgba(0,0,0,0.1); font-size: 12px; page-break-after: always; font-family: Arial, Helvetica, sans-serif; }
        .examreport_h2 { font-size: 16px; margin-bottom: 2px; font-weight: bold; color: #0d6efd; }
        .examreport_header { text-align: center; border-bottom: 2px solid #0d6efd; margin-bottom: 6px; padding-bottom: 4px; }
        .examreport_header img { width: 100px; height: 100px; object-fit: contain; margin-bottom: 4px; }
        .examreport_profile_pic { width: 65px; height: 65px; object-fit: cover; border-radius: 6px; border: 1px solid #ccc; }
        .examreport_table th { background: #e9f2ff !important; color: #0d47a1; text-align: center; }
        .examreport_table th, .examreport_table td { padding: 3px 5px !important; vertical-align: middle; font-size: 11px; border: 1px solid #dee2e6; }
        .examreport_table .subject-col, .examreport_table .remarks-col, .examreport_table .teacher-col { text-align: left !important; }
        .examreport_remarks { border: 1px solid #0d6efd; padding: 6px 8px; margin-bottom: 6px; background: #f1f8ff; border-left: 5px solid #0d6efd; border-radius: 4px; font-size: 12px; }
        .examreport_student_row { display: flex; flex-wrap: nowrap !important; gap: 6px; }
        .examreport_student_row .card { flex: 1; border: 1px solid #0d6efd; }
        .examreport_student_row .card-body { padding: 6px; font-size: 12px; }
        .examreport_footer { margin-top: 10px; font-size: 12px; border-top: 2px solid #0d6efd; padding-top: 5px; }
        .loader-spinner { border: 4px solid #f3f3f3; border-top: 4px solid #0d6efd; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 20px auto; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        @media print { body { background: none; margin: 0; font-size: 12px; } .examreport_container { box-shadow: none; border-radius: 0; max-width: 100%; padding: 10mm; } .no-print { display: none !important; } }
        @page { size: A4; margin: 10mm; }

        /* Small header text adjustment */
        .no-print span { font-size: 16px !important; }
        .no-print div[style*="text-align:center"] { font-size: 14px !important; }
    </style>
</head>
<body>

<!-- Sticky Header (text made smaller) -->
<div class="no-print" style="display:flex;align-items:center;justify-content:space-between;background:#1a1f71;padding:10px 20px;border-radius:8px;color:#fff;margin-bottom:15px;position:sticky;top:0;z-index:9999;">
    <div style="display:flex;align-items:center;gap:10px;">
        <img src="<?php echo htmlspecialchars($school_logo); ?>" style="height:50px;border-radius:5px;">
        <span style="font-size:16px;font-weight:bold;"><?php echo htmlspecialchars($school['name'] ?? 'School'); ?></span>
    </div>
    <div style="flex:1;text-align:center;font-weight:bold;font-size:14px;">
        Exam Report: <?php echo htmlspecialchars($exam['exam_name']); ?> (<?php echo htmlspecialchars($exam['term'] . ' - ' . $year); ?>)
    </div>
    <div style="display:flex;gap:8px;">
        <button style="background:#007bff;border:none;padding:6px 12px;border-radius:5px;color:#fff;" onclick="window.print()">Print</button>
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
        <p class="mb-0 fw-bold">Exam Report</p>
        <p class="mb-0"><?php echo htmlspecialchars($exam['exam_name'] . ' ' . $exam['term'] . ' Year ' . $year); ?></p>
    </div>

    <div class="examreport_student_row mb-2">
        <div class="card h-100">
            <div class="card-body d-flex align-items:center">
                <img src="<?php echo htmlspecialchars($profile_picture); ?>" class="examreport_profile_pic me-2" />
                <div>
                    <p class="mb-1"><strong>Name:</strong> <?php echo htmlspecialchars($agg['full_name']); ?></p>
                    <p class="mb-1"><strong>Adm No:</strong> <?php echo htmlspecialchars($agg['admission_no']); ?></p>
                    <p class="mb-1"><strong>Class:</strong> <?php echo htmlspecialchars($class_name . ' ' . ($stream_name ?? '')); ?></p>
                    <p class="mb-0"><strong>Overall Grade:</strong> <?php echo htmlspecialchars($agg['mean_grade']); ?></p>
                </div>
            </div>
        </div>
        <div class="card h-100">
            <div class="card-body">
                <p class="mb-1"><strong>Class Position:</strong> <?php echo ($agg['position_class'] ?? 'N/A') . ' / ' . $class_student_count; ?></p>
                <p class="mb-1"><strong>Stream Position:</strong> <?php echo ($agg['position_stream'] ?? 'N/A') . ' / ' . $stream_student_count; ?></p>
                <p class="mb-1"><strong>Total Marks:</strong> <?php echo number_format($agg['total_score'], 2) . ' / ' . $max_total_score; ?></p>
                <p class="mb-0"><strong>Average %:</strong> <?php echo number_format($agg['mean_score'], 1); ?></p>
            </div>
        </div>
    </div>

    <div class="examreport_remarks">
        <strong>Class Mean:</strong> <?php echo $class_mean; ?> &nbsp;
        <strong>Class Grade:</strong> <?php echo $class_grade; ?> &nbsp;
        <strong>Stream Mean:</strong> <?php echo $stream_mean; ?> &nbsp;
        <strong>Stream Grade:</strong> <?php echo $stream_grade; ?> &nbsp;
        <strong>Student Mean:</strong> <?php echo number_format($agg['mean_score'], 1); ?> &nbsp;
        <strong>Student Grade:</strong> <?php echo htmlspecialchars($agg['mean_grade']); ?>
    </div>

    <table class="table table-bordered table-sm text-center align-middle mb-2 examreport_table">
        <thead>
            <tr>
                <th class="subject-col">Subject</th>
                <th>Marks (%)</th>
                <th>Grade</th>
                <th class="remarks-col">Remarks</th>
                <th class="teacher-col">Teacher</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($sub_aggregates as $sub_agg):
                if (!isset($sub_agg['subject_score']) || is_null($sub_agg['subject_score'])) continue;
                $teacher_name = $teachers[$sub_agg['subject_id']] ?? 'N/A';
            ?>
                <tr>
                    <td class="subject-col"><?php echo htmlspecialchars($sub_agg['subject_name']); ?></td>
                    <td><?php echo number_format($sub_agg['subject_score'], 2); ?></td>
                    <td><?php echo htmlspecialchars($sub_agg['subject_grade'] ?? 'N/A'); ?></td>
                    <td class="remarks-col"><?php echo htmlspecialchars($sub_agg['remark_text'] ?? 'N/A'); ?></td>
                    <td class="teacher-col"><?php echo htmlspecialchars($teacher_name); ?></td>
                </tr>
            <?php endforeach; ?>
            <tr class="table-primary fw-bold">
                <td class="subject-col">Totals</td>
                <td colspan="4">
                    <?php echo number_format($agg['total_score'], 2) . ' / ' . $max_total_score; ?> | 
                    Average: <?php echo number_format($agg['mean_score'], 1); ?>% | 
                    Grade: <?php echo htmlspecialchars($agg['mean_grade']); ?>
                </td>
            </tr>
        </tbody>
    </table>

    <div class="examreport_remarks mb-2">
        <p class="mb-1"><strong>Class Teacher:</strong> <?php echo htmlspecialchars($agg['class_teacher_remark'] ?? 'N/A'); ?></p>
        <p class="mb-0"><strong>Principal:</strong> <?php echo htmlspecialchars($agg['remark_text'] ?? 'N/A'); ?></p>
    </div>

    <footer class="examreport_footer">
        <table class="table table-borderless table-sm mb-1 w-100">
            <tr>
                <td><strong>Fees Balance:</strong> N/A</td>
                <td><strong>Next Term Fees:</strong> N/A</td>
            </tr>
        </table>
    </footer>
</div>

<script>
function printReport() {
    window.print();
}

function downloadReport() {
    const containers = document.querySelectorAll('.examreport_container');
    if (containers.length === 0) {
        alert('No reports available to download.');
        return;
    }

    const modal = new bootstrap.Modal(document.getElementById('downloadModal'), {
        backdrop: 'static',
        keyboard: false
    });
    modal.show();

    const opt = {
        margin: 10,
        filename: "Exam_Report_<?php echo htmlspecialchars($agg['admission_no'] ?? 'Student'); ?>.pdf",
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2, useCORS: true },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
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

</body>
</html>

<?php ob_end_flush(); ?>