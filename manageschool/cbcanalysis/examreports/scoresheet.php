<?php
session_start();
ob_start(); // Prevent unwanted output before headers
require __DIR__ . '../../../../connection/db.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['school_id']) || !isset($_SESSION['role_id'])) {
  header("Location: ../../login.php");
  exit;
}

$school_id   = (int)$_SESSION['school_id'];
$class_id    = (int)($_POST['class_id']    ?? 0);
$stream_id   = (int)($_POST['stream_id']   ?? 0);
$group_id    = (int)($_POST['group_id']    ?? 0);
$subject_id  = (int)($_POST['subject_id']  ?? 0);

$is_group_mode = $group_id > 0;

// ────────────────────────────────────────────────────────────────
// Auto-detect subject if group mode and no subject was sent
// ────────────────────────────────────────────────────────────────
if ($is_group_mode && $subject_id === 0) {
  $stmt = $conn->prepare("
        SELECT subject_id 
        FROM custom_group_subjects 
        WHERE group_id = ? 
        LIMIT 1
    ");
  $stmt->bind_param("i", $group_id);
  $stmt->execute();
  $result = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if ($result && !empty($result['subject_id'])) {
    $subject_id = (int)$result['subject_id'];
  }
}

// ────────────────────────────────────────────────────────────────
// Final validation
// ────────────────────────────────────────────────────────────────
if ($class_id === 0 || $subject_id === 0 || (!$is_group_mode && $stream_id === 0)) {
  die("Required parameters missing.<br><pre>Received:\n" . print_r($_POST, true) . "</pre>");
}

// ────────────────────────────────────────────────────────────────
// School info
// ────────────────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT name, logo, phone, email FROM schools WHERE school_id = ?");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$school = $stmt->get_result()->fetch_assoc();
$stmt->close();

$school_logo = $school['logo'] ?? 'https://academics.sifms.co.ke/manageschool/logos/school-logo.png';
if (strpos($school_logo, 'http') !== 0) {
  $school_logo = 'https://academics.sifms.co.ke/manageschool/logos/' . basename($school_logo);
}

// ────────────────────────────────────────────────────────────────
// Class name
// ────────────────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT form_name FROM classes WHERE class_id = ? AND school_id = ?");
$stmt->bind_param("ii", $class_id, $school_id);
$stmt->execute();
$class = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ────────────────────────────────────────────────────────────────
// Stream OR Group name
// ────────────────────────────────────────────────────────────────
$display_name = '';
if ($is_group_mode) {
  $stmt = $conn->prepare("SELECT name FROM custom_groups WHERE group_id = ? AND school_id = ?");
  $stmt->bind_param("ii", $group_id, $school_id);
  $stmt->execute();
  $group = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  $display_name = $group['name'] ?? 'Custom Group';
} else {
  $stmt = $conn->prepare("SELECT stream_name FROM streams WHERE stream_id = ? AND class_id = ? AND school_id = ?");
  $stmt->bind_param("iii", $stream_id, $class_id, $school_id);
  $stmt->execute();
  $stream = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  $display_name = $stream['stream_name'] ?? 'Stream';
}

// ────────────────────────────────────────────────────────────────
// Subject name
// ────────────────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT name FROM subjects WHERE subject_id = ? AND school_id = ?");
$stmt->bind_param("ii", $subject_id, $school_id);
$stmt->execute();
$subject = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ────────────────────────────────────────────────────────────────
// Teacher (for this subject + class)
// ────────────────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT CONCAT(u.first_name, ' ', COALESCE(u.other_names, '')) AS teacher_name
    FROM teacher_subjects ts
    JOIN users u ON ts.user_id = u.user_id
    WHERE ts.subject_id = ? AND ts.class_id = ? AND ts.school_id = ?
      AND u.status = 'active' AND u.deleted_at IS NULL
    LIMIT 1
");
$stmt->bind_param("iii", $subject_id, $class_id, $school_id);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ────────────────────────────────────────────────────────────────
// Students (group mode OR stream mode)
// ────────────────────────────────────────────────────────────────
if ($is_group_mode) {
  $stmt = $conn->prepare("
        SELECT s.admission_no, s.full_name
        FROM students s
        INNER JOIN custom_group_students cgs ON s.student_id = cgs.student_id
        WHERE cgs.group_id = ? 
          AND s.class_id = ? 
          AND s.school_id = ?
          AND s.deleted_at IS NULL
        ORDER BY CAST(s.admission_no AS UNSIGNED), s.admission_no
    ");
  $stmt->bind_param("iii", $group_id, $class_id, $school_id);
} else {
  $stmt = $conn->prepare("
        SELECT admission_no, full_name
        FROM students
        WHERE stream_id = ? 
          AND class_id = ? 
          AND school_id = ? 
          AND deleted_at IS NULL
        ORDER BY CAST(admission_no AS UNSIGNED), admission_no
    ");
  $stmt->bind_param("iii", $stream_id, $class_id, $school_id);
}

$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Score Sheet - CBC</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

  <style>
    .scoresheet_container {
      max-width: 900px;
      margin: 10px auto;
      background: #fff;
      padding: 12px 15px;
      border-radius: 6px;
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
      font-size: 12px;
      page-break-after: always;
      font-family: Arial, Helvetica, sans-serif;
    }

    .scoresheet_h2 {
      font-size: 16px;
      margin-bottom: 2px;
      font-weight: bold;
      color: #0d6efd;
    }

    .scoresheet_header {
      text-align: center;
      border-bottom: 2px solid #0d6efd;
      margin-bottom: 6px;
      padding-bottom: 4px;
    }

    .scoresheet_header img {
      width: 100px;
      height: 100px;
      object-fit: contain;
      margin-bottom: 4px;
    }

    .scoresheet_table th {
      background: #e9f2ff !important;
      color: #0d47a1;
      text-align: center;
    }

    .scoresheet_table th,
    .scoresheet_table td {
      padding: 3px 5px !important;
      vertical-align: middle;
      font-size: 11px;
      border: 1px solid #dee2e6;
    }

    .scoresheet_label {
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

    .scoresheet_exam_label {
      border-bottom: 1px dotted #0d6efd;
      padding: 6px 8px;
      margin-bottom: 6px;
      font-size: 12px;
      text-align: center;
      font-style: italic;
    }

    .scoresheet_footer {
      margin-top: 10px;
      font-size: 12px;
      border-top: 2px solid #0d6efd;
      padding-top: 5px;
      text-align: center;
    }

    .no-print {
      display: flex;
      align-items: center;
      justify-content: space-between;
      background-color: #1a1f71;
      padding: 10px 20px;
      border-radius: 8px;
      box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
      color: #fff;
      margin-bottom: 15px;
      position: sticky;
      top: 0;
      z-index: 9999;
    }

    @media print {
      body {
        background: none;
        margin: 0;
        font-size: 12px;
      }

      .scoresheet_container {
        box-shadow: none;
        border-radius: 0;
        max-width: 100%;
        padding: 10mm;
        margin: 0;
      }

      .no-print {
        display: none !important;
      }

      .scoresheet_footer {
        position: relative;
        bottom: 0;
      }
    }

    @page {
      size: A4;
      margin: 10mm;
    }

    .name-left {
      text-align: left !important;
    }
  </style>
</head>

<body>

  <!-- Sticky Header (only visible on screen, hidden when printing) -->
  <div class="no-print">
    <div style="display: flex; align-items: center; gap: 10px;">
      <img src="<?= htmlspecialchars($school_logo) ?>" alt="School Logo"
        style="height: 50px; width: auto; object-fit: contain; border-radius: 5px;">
      <span style="font-size: 18px; font-weight: bold;">
        <?= htmlspecialchars($school['name'] ?? 'School Name') ?>
      </span>
    </div>

    <div style="flex: 1; text-align: center; font-weight: bold; font-size: 16px;">
      Score Sheet: <?= htmlspecialchars(($class['form_name'] ?? '') . ' ' . $display_name . ' - ' . ($subject['name'] ?? 'Subject')) ?>
    </div>

    <div style="display: flex; gap: 8px;">
      <button class="btn btn-danger btn-sm" onclick="history.back()">
        <i class="bi bi-arrow-left"></i> Back
      </button>
      <button class="btn btn-primary btn-sm" onclick="printReport()">
        <i class="bi bi-printer"></i> Print
      </button>
      <!-- <button class="btn btn-success btn-sm" onclick="downloadReport()">
        <i class="bi bi-download"></i> Download PDF
      </button> -->
    </div>
  </div>

  <!-- Download loading modal -->
  <div class="modal fade modal-loader" id="downloadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content text-center">
        <div class="modal-body">
          <div class="loader-spinner"></div>
          <p>Generating PDF...</p>
        </div>
      </div>
    </div>
  </div>

  <div class="scoresheet_container">
    <div class="scoresheet_header">
      <img src="<?= htmlspecialchars($school_logo) ?>" alt="Logo" /><br />
      <h2 class="scoresheet_h2"><?= htmlspecialchars($school['name'] ?? 'School Name') ?></h2>
      <p class="mb-0 fw-bold">Score Sheet (CBC)</p>
      <p class="mb-0">
        <?= htmlspecialchars($class['form_name'] ?? '') ?>
        <?= htmlspecialchars($display_name) ?>
        – <?= htmlspecialchars($subject['name'] ?? 'Subject') ?>
      </p>
    </div>

    <div class="scoresheet_label">
      TEACHER: <?= htmlspecialchars($teacher['teacher_name'] ?? 'Not Assigned') ?>
    </div>

    <div class="scoresheet_exam_label">
      EXAM NAME: _______________________ &nbsp;&nbsp; TERM: _______________
    </div>

    <table class="table table-bordered table-sm text-center align-middle scoresheet_table">
      <thead>
        <tr>
          <th>ADM NO</th>
          <th class="name-left">NAME</th>
          <th>MARKS</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($students as $student): ?>
          <tr>
            <td><?= htmlspecialchars($student['admission_no']) ?></td>
            <td class="name-left"><?= htmlspecialchars($student['full_name']) ?></td>
            <td></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="scoresheet_footer">
      Total Students: <strong><?= count($students) ?></strong><br>
      Prepared by: ___________________________ &nbsp;&nbsp;
      Checked by: ___________________________
    </div>
  </div>

  <script>
    // Print function
    function printReport() {
      window.print();
    }

    // Download PDF function
    function downloadReport() {
      const modal = new bootstrap.Modal(document.getElementById('downloadModal'), {
        backdrop: 'static',
        keyboard: false
      });
      modal.show();

      const element = document.querySelector('.scoresheet_container');
      const opt = {
        margin: 10,
        filename: 'Score_Sheet_<?php echo htmlspecialchars(($class['form_name'] ?? '') . '_' . ($stream['stream_name'] ?? '') . '_' . ($subject['name'] ?? 'Subject') . '.pdf'); ?>',
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


      html2pdf().set(opt).from(element).save().then(() => {
        modal.hide();
      }).catch(err => {
        console.error(err);
        modal.hide();
        alert('Failed to generate PDF. Try again.');
      });
    }
  </script>

  <?php ob_end_flush(); ?>