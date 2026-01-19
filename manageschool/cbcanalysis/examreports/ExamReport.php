<?php
// reports/examreports/ExamReport.php
ob_start();
require __DIR__ . '/../../../connection/db.php';

session_start();
if (!isset($_SESSION['user_id']) || !isset($_SESSION['school_id']) || !isset($_SESSION['role_id'])) {
    header("Location: ../../login.php");
    exit;
}

$school_id = (int)$_SESSION['school_id'];

// Get GET parameters
$year        = $_GET['year']       ?? '';
$class_id    = (int)($_GET['class_id']    ?? 0);
$exam_id     = (int)($_GET['exam_id']     ?? 0);
$stream_id   = (int)($_GET['stream_id']   ?? 0);
$student_id  = (int)($_GET['student_id']  ?? 0); // For parent mobile view

if (empty($year) || $exam_id <= 0) {
    die('Year and exam_id are required.');
}

// Optional: Verify class is CBC
$stmt = $conn->prepare("SELECT is_cbc, form_name FROM classes WHERE class_id = ? AND school_id = ?");
$stmt->bind_param("ii", $class_id, $school_id);
$stmt->execute();
$class_info = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$class_info || $class_info['is_cbc'] != 1) {
    die("This class is not configured for CBC curriculum.");
}
$class_name = $class_info['form_name'] ?? 'Unknown Class';

// Run grading procedure
$stmt = $conn->prepare("CALL cbc_exam_graded(?, ?, ?, ?)");
if (!$stmt) {
    die("Failed to prepare procedure call: " . $conn->error);
}
$stmt->bind_param("iiii", $school_id, $class_id, $exam_id, $stream_id);
if (!$stmt->execute()) {
    die("Procedure execution failed: " . $stmt->error);
}
$stmt->close();

// ────────────────────────────────────────────────
// Load students (single student or whole class/stream)
// ────────────────────────────────────────────────
if ($student_id > 0) {
    // Parent / single student mode
    $stmt = $conn->prepare("
        SELECT 
            s.student_id, s.full_name, s.admission_no, s.profile_picture, s.gender,
            s.school_id, s.class_id, c.form_name, COALESCE(str.stream_name, '') AS stream_name
        FROM students s
        LEFT JOIN classes c ON s.class_id = c.class_id
        LEFT JOIN streams str ON s.stream_id = str.stream_id
        WHERE s.student_id = ? AND s.school_id = ?
    ");
    $stmt->bind_param("ii", $student_id, $school_id);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$student) {
        die('Student not found or access denied.');
    }

    $school_id = $student['school_id'];
    $class_id = $student['class_id'];
    $class_name = $student['form_name'];
    $students = [$student];
} else {
    // Admin - class / stream report
    if ($class_id <= 0) {
        die('Class ID is required for class reports.');
    }

    if ($stream_id > 0) {
        $stmt = $conn->prepare("
            SELECT 
                s.student_id, s.full_name, s.admission_no, s.profile_picture, s.gender,
                COALESCE(str.stream_name, '') AS stream_name
            FROM students s
            JOIN streams str ON s.stream_id = str.stream_id
            WHERE s.class_id = ? AND s.stream_id = ? AND s.school_id = ?
            ORDER BY s.full_name
        ");
        $stmt->bind_param("iii", $class_id, $stream_id, $school_id);
    } else {
        $stmt = $conn->prepare("
            SELECT 
                s.student_id, s.full_name, s.admission_no, s.profile_picture, s.gender,
                COALESCE(str.stream_name, '') AS stream_name
            FROM students s
            LEFT JOIN streams str ON s.stream_id = str.stream_id
            WHERE s.class_id = ? AND s.school_id = ?
            ORDER BY s.full_name
        ");
        $stmt->bind_param("ii", $class_id, $school_id);
    }
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($students)) {
        die('No students found in this class/stream.');
    }
}

// School info
$stmt = $conn->prepare("SELECT name, logo FROM schools WHERE school_id = ?");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$school = $stmt->get_result()->fetch_assoc();
$stmt->close();

$school_logo = $school['logo'] ?? 'https://academics.sifms.co.ke/manageschool/logos/school-logo.png';
if (strpos($school_logo, 'http') !== 0) {
    $school_logo = 'https://academics.sifms.co.ke/manageschool/logos/' . basename($school_logo);
}

// Exam info
$stmt = $conn->prepare("
    SELECT exam_name, term, grading_system_id, min_subjects 
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

$max_total_score = $exam['min_subjects'] ? $exam['min_subjects'] * 100 : 0;

// ────────────────────────────────────────────────
// HTML + Loop through students
// ────────────────────────────────────────────────
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
        .examreport_container {
            max-width: 820px;
            margin: 10px auto;
            background: #fff;
            padding: 16px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.12);
            font-size: 13px;
            page-break-after: always;
            font-family: Arial, Helvetica, sans-serif;
        }

        .examreport_h2 {
            font-size: 18px;
            margin-bottom: 4px;
            font-weight: bold;
            color: #0d6efd;
        }

        .examreport_header {
            text-align: center;
            border-bottom: 2px solid #0d6efd;
            padding-bottom: 8px;
            margin-bottom: 12px;
        }

        .examreport_header img {
            width: 90px;
            height: 90px;
            object-fit: contain;
            margin-bottom: 6px;
        }

        .examreport_profile_pic {
            width: 70px;
            height: 70px;
            object-fit: cover;
            border-radius: 6px;
            border: 1px solid #ccc;
        }

        .examreport_table th {
            background: #e9f2ff !important;
            color: #0d47a1;
            text-align: center;
            font-size: 11.5px;
        }

        .examreport_table td,
        .examreport_table th {
            padding: 5px 7px !important;
            font-size: 11.5px;
            border: 1px solid #dee2e6;
        }

        .examreport_table .subject-col,
        .examreport_table .remarks-col,
        .examreport_table .teacher-col {
            text-align: left !important;
        }

        .examreport_remarks {
            border: 1px solid #0d6efd;
            padding: 10px;
            margin: 12px 0;
            background: #f8fcff;
            border-left: 5px solid #0d6efd;
            border-radius: 5px;
            font-size: 12.5px;
        }

        .examreport_footer {
            margin-top: 16px;
            font-size: 12px;
            border-top: 2px solid #0d6efd;
            padding-top: 8px;
        }

        .loader-spinner {
            border: 5px solid #f3f3f3;
            border-top: 5px solid #0d6efd;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
            margin: 30px auto;
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
                margin: 0;
                font-size: 11px;
                background: white;
            }

            .examreport_container {
                box-shadow: none;
                border-radius: 0;
                padding: 12mm;
                max-width: none;
            }

            .no-print {
                display: none !important;
            }
        }

        @page {
            size: A4;
            margin: 12mm;
        }
    </style>
</head>

<body>

    <!-- Sticky control bar (only on screen) -->
    <div class="no-print" style="position:sticky; top:0; z-index:1000; background:#1a1f71; color:white; padding:12px 20px; border-radius:0 0 8px 8px; display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; box-shadow:0 4px 10px rgba(0,0,0,0.3);">
        <div style="display:flex; align-items:center; gap:12px;">
            <img src="<?= htmlspecialchars($school_logo) ?>" style="height:48px; border-radius:6px;" alt="School Logo">
            <strong><?= htmlspecialchars($school['name'] ?? 'School') ?></strong>
        </div>
        <div style="font-weight:bold; font-size:17px;">
            <?= htmlspecialchars($exam['exam_name']) ?> • <?= htmlspecialchars($exam['term'] . ' ' . $year) ?>
        </div>
        <div>
            <button class="btn btn-sm btn-danger me-2" onclick="history.back()">Back</button>
            <button class="btn btn-sm btn-primary me-2" onclick="window.print()">Print</button>
            <button class="btn btn-sm btn-success" onclick="downloadReport()">Download PDF</button>
        </div>
    </div>

    <!-- PDF Generation Loader -->
    <div class="modal fade" id="downloadModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body text-center py-5">
                    <div class="loader-spinner"></div>
                    <p class="mt-3 fw-bold">Generating PDF...</p>
                </div>
            </div>
        </div>
    </div>

    <?php foreach ($students as $student):
        $current_student_id = $student['student_id'];

        // Aggregate (main results summary)
        $stmt = $conn->prepare("
            SELECT 
                ea.*,
                st.full_name,
                st.admission_no,
                st.profile_picture,
                COALESCE(str.stream_name, '') AS stream_name
            FROM cbc_exam_aggregates ea
            JOIN students st ON ea.student_id = st.student_id
            LEFT JOIN streams str ON st.stream_id = str.stream_id
            WHERE ea.exam_id = ? 
              AND ea.student_id = ? 
              AND ea.school_id = ?
        ");
        $stmt->bind_param("iii", $exam_id, $current_student_id, $school_id);
        $stmt->execute();
        $agg = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$agg) continue;

        // Subject level results
        $stmt = $conn->prepare("
            SELECT 
                esa.*,
                s.name AS subject_name
                
            FROM cbc_exam_subject_aggregates esa
            JOIN subjects s ON esa.subject_id = s.subject_id
    
            WHERE esa.exam_id = ? 
              AND esa.student_id = ?
            ORDER BY esa.subject_id
        ");
        $stmt->bind_param("ii", $exam_id, $current_student_id);
        $stmt->execute();
        $sub_aggregates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Subject teachers
        $teacher_stream_id = $student['stream_id'] ?? 0; // better to use student's stream
        $stmt = $conn->prepare("
            SELECT 
                ts.subject_id, 
                CONCAT(u.first_name, ' ', COALESCE(u.other_names,'')) AS teacher_name
            FROM teacher_subjects ts
            JOIN users u ON ts.user_id = u.user_id
            WHERE ts.school_id = ?
              AND ts.class_id = ?
              AND (ts.stream_id = ? OR ts.stream_id IS NULL)
              AND ts.academic_year = ?
        ");
        $stmt->bind_param("iiii", $school_id, $class_id, $teacher_stream_id, $year);
        $stmt->execute();
        $teacher_result = $stmt->get_result();
        $teachers = [];
        while ($row = $teacher_result->fetch_assoc()) {
            $teachers[$row['subject_id']] = $row['teacher_name'];
        }
        $stmt->close();

        // Profile picture fallback
        $profile_picture = $agg['profile_picture'] ?? '';
        if (empty($profile_picture)) {
            $profile_picture = 'https://academics.sifms.co.ke/manageschool/studentsprofile/defaultstudent.png';
        } elseif (strpos($profile_picture, 'http') !== 0) {
            $profile_picture = 'https://academics.sifms.co.ke/manageschool/' . ltrim($profile_picture, '/');
        }

    ?>
        <div class="examreport_container">
            <div class="examreport_header">
                <img src="<?= htmlspecialchars($school_logo) ?>" alt="School Logo"><br>
                <h2 class="examreport_h2"><?= htmlspecialchars($school['name'] ?? 'School') ?></h2>
                <p class="mb-1 fw-bold">Academic Report</p>
                <p class="mb-0"><?= htmlspecialchars($exam['exam_name'] . ' - ' . $exam['term'] . ' ' . $year) ?></p>
            </div>

            <div class="row mb-3">
                <div class="col-12">
                    <div class="card border-primary">
                        <div class="card-body d-flex align-items-center">
                            <img src="<?= htmlspecialchars($profile_picture) ?>" class="examreport_profile_pic me-3" alt="Student">
                            <div>
                                <p class="mb-1"><strong>Name:</strong> <?= htmlspecialchars($agg['full_name']) ?></p>
                                <p class="mb-1"><strong>Adm No:</strong> <?= htmlspecialchars($agg['admission_no'] ?? '-') ?></p>
                                <p class="mb-0"><strong>Class:</strong> <?= htmlspecialchars($class_name . ' ' . $agg['stream_name']) ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <table class="table table-bordered table-sm examreport_table mb-3">
                <thead>
                    <tr>
                        <th class="subject-col">Subject</th>
                        <th>Marks (%)</th>
                        <th>Grade</th>
                        <th>Points</th>
                        <th class="remarks-col">Remarks</th>
                        <th class="teacher-col">Teacher</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sub_aggregates as $sub):
                        if ($sub['subject_score'] === null) continue;
                        $teacher = $teachers[$sub['subject_id']] ?? '—';
                    ?>
                        <tr>
                            <td class="subject-col"><?= htmlspecialchars($sub['subject_name']) ?></td>
                            <td><?= number_format($sub['subject_score'], 2) ?></td>
                            <td><?= htmlspecialchars($sub['subject_grade'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($sub['subject_points'] ?? '—') ?></td>
                            <td class="remarks-col"><?= htmlspecialchars($sub['subject_teacher_remark_text'] ?? '—') ?></td>
                            <td class="teacher-col"><?= htmlspecialchars($teacher) ?></td>
                        </tr>
                    <?php endforeach; ?>

                    <tr class="table-primary fw-bold">
                        <td class="subject-col">Total</td>
                        <td colspan="4">
                            <?= number_format($agg['total_score'] ?? 0, 2) ?> / <?= $max_total_score ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div class="examreport_remarks">
                <p class="mb-2"><strong>Class Teacher:</strong> <?= htmlspecialchars($agg['class_teacher_remark_text'] ?? '—') ?></p>
                <p class="mb-0"><strong>Principal:</strong> <?= htmlspecialchars($agg['principal_remark_text'] ?? '—') ?></p>
            </div>

            <footer class="examreport_footer">
                <small>Note: This is a computer-generated report. For official use only.</small>
            </footer>
        </div>
    <?php endforeach; ?>

    <script>
        function downloadReport() {
            const containers = document.querySelectorAll('.examreport_container');
            if (containers.length === 0) {
                alert('No reports to download.');
                return;
            }

            const modal = new bootstrap.Modal(document.getElementById('downloadModal'));
            modal.show();

            const opt = {
                margin: 12,
                filename: '<?= $student_id > 0 ? "Report_" . ($agg['admission_no'] ?? "student") . ".pdf" : "Class_Report_" . $year . ".pdf" ?>',
                image: {
                    type: 'jpeg',
                    quality: 0.98
                },
                html2canvas: {
                    scale: 2,
                    useCORS: true,
                    logging: false
                },
                jsPDF: {
                    unit: 'mm',
                    format: 'a4',
                    orientation: 'portrait'
                }
            };

            const wrapper = document.createElement('div');
            containers.forEach(c => {
                const clone = c.cloneNode(true);
                clone.querySelectorAll('.no-print')?.forEach(el => el.remove());
                wrapper.appendChild(clone);
            });

            html2pdf().set(opt).from(wrapper).save().then(() => {
                wrapper.remove();
                modal.hide();
            }).catch(err => {
                console.error(err);
                modal.hide();
                alert('Failed to generate PDF. Please try again.');
            });
        }
    </script>

</body>

</html>
<?php ob_end_flush(); ?>