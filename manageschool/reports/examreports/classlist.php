<?php
session_start();
ob_start(); // Start output buffering to prevent unwanted output
require __DIR__ . '../../../../connection/db.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['school_id']) || !isset($_SESSION['role_id'])) {
  header("Location: ../../login.php");
  exit;
}

$school_id = $_SESSION['school_id'];
$class_id  = isset($_POST['class_id'])  ? (int)$_POST['class_id']  : 0;
$stream_id = isset($_POST['stream_id']) ? (int)$_POST['stream_id'] : 0;
$group_id  = isset($_POST['group_id'])  ? (int)$_POST['group_id']  : 0;
$subject_id = isset($_POST['subject_id']) && $_POST['subject_id'] !== ''
  ? (int)$_POST['subject_id']
  : 0;

// ────────────────────────────────────────────────
// Prepare fallbacks & titles
// ────────────────────────────────────────────────
$subject_name = '—';
$teacher_name = '______ ___';
$list_title   = 'Class List';

// ────────────────────────────────────────────────
// Fetch school details
// ────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT name, logo, phone, email FROM schools WHERE school_id = ?");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$school = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Process school logo path
$school_logo = $school['logo'] ?? '';
if (empty($school_logo)) {
  $school_logo = 'https://academics.sifms.co.ke/manageschool/logos/school-logo.png';
} elseif (strpos($school_logo, 'http') !== 0) {
  $school_logo = 'https://academics.sifms.co.ke/manageschool/logos/' . basename($school_logo);
}

// Fetch class details
$stmt = $conn->prepare("SELECT form_name FROM classes WHERE class_id = ? AND school_id = ?");
$stmt->bind_param("ii", $class_id, $school_id);
$stmt->execute();
$class = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Fetch stream details
$stream_name = '';
if ($stream_id > 0) {
  $stmt = $conn->prepare("SELECT stream_name FROM streams WHERE stream_id = ? AND class_id = ? AND school_id = ?");
  $stmt->bind_param("iii", $stream_id, $class_id, $school_id);
  $stmt->execute();
  $stream = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  $stream_name = $stream['stream_name'] ?? '';
}

// Fetch group name if group_id is provided
$group_name = '';
if ($group_id > 0) {
  $stmt = $conn->prepare("SELECT name FROM custom_groups WHERE group_id = ? AND school_id = ?");
  $stmt->bind_param("ii", $group_id, $school_id);
  $stmt->execute();
  $group_result = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if ($group_result) {
    $group_name = $group_result['name'];
    $list_title = "Custom Group: " . $group_name;
  }
}

// Fetch subject (only if selected)
$subject = null;
if ($subject_id > 0) {
  $stmt = $conn->prepare("SELECT name FROM subjects WHERE subject_id = ? AND school_id = ?");
  $stmt->bind_param("ii", $subject_id, $school_id);
  $stmt->execute();
  $subject = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if ($subject) {
    $subject_name = $subject['name'];
  }
}

// Fetch teacher – only if subject is selected
if ($subject_id > 0) {
  $stmt = $conn->prepare("
        SELECT CONCAT(u.first_name, ' ', COALESCE(u.other_names, '')) AS teacher_name
        FROM teacher_subjects ts
        JOIN users u ON ts.user_id = u.user_id
        WHERE ts.subject_id = ?
          AND ts.class_id = ?
          AND ts.school_id = ?
          AND u.status = 'active'
          AND u.deleted_at IS NULL
        LIMIT 1
    ");
  $stmt->bind_param("iii", $subject_id, $class_id, $school_id);
  $stmt->execute();
  $teacher_result = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if ($teacher_result && !empty($teacher_result['teacher_name'])) {
    $teacher_name = $teacher_result['teacher_name'];
  }
}

// ────────────────────────────────────────────────
// Fetch students – supports custom group
// ────────────────────────────────────────────────
$students = [];

if ($group_id > 0) {
  $stmt = $conn->prepare("
        SELECT s.admission_no, s.full_name, s.kcpe_score
        FROM custom_group_students cgs
        JOIN students s ON cgs.student_id = s.student_id
        WHERE cgs.group_id = ?
          AND s.school_id = ?
          AND s.deleted_at IS NULL
        ORDER BY s.admission_no ASC
    ");
  $stmt->bind_param("ii", $group_id, $school_id);
  $stmt->execute();
  $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
} elseif ($stream_id > 0) {
  $stmt = $conn->prepare("
        SELECT admission_no, full_name, kcpe_score
        FROM students
        WHERE stream_id = ? AND class_id = ? AND school_id = ? AND deleted_at IS NULL
        ORDER BY admission_no ASC
    ");
  $stmt->bind_param("iii", $stream_id, $class_id, $school_id);
  $stmt->execute();
  $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
} else {
  $stmt = $conn->prepare("
        SELECT admission_no, full_name, kcpe_score
        FROM students
        WHERE class_id = ? AND school_id = ? AND deleted_at IS NULL
        ORDER BY admission_no ASC
    ");
  $stmt->bind_param("ii", $class_id, $school_id);
  $stmt->execute();
  $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Class List - <?php echo htmlspecialchars($list_title); ?></title>

  <!-- Bootstrap CSS (for screen only) -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

  <!-- html2pdf (for download) -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

  <style>
    /* ── SCREEN STYLES (unchanged) ── */
    .classlist_container {
      max-width: 950px;
      margin: 10px auto;
      background: #fff;
      padding: 15px 20px;
      border-radius: 6px;
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
      font-size: 15px;
      font-family: Arial, Helvetica, sans-serif;
    }

    .classlist_h2 {
      font-size: 22px;
      margin-bottom: 4px;
      font-weight: bold;
      color: #0d6efd;
    }

    .classlist_header {
      text-align: center;
      border-bottom: 2px solid #0d6efd;
      margin-bottom: 10px;
      padding-bottom: 6px;
    }

    .classlist_header img {
      width: 110px;
      height: 110px;
      object-fit: contain;
      margin-bottom: 6px;
    }

    .classlist_table th {
      background: #e9f2ff !important;
      color: #0d47a1;
      text-align: center;
      font-size: 15px;
      font-weight: bold;
    }

    .classlist_table th,
    .classlist_table td {
      padding: 8px 10px !important;
      vertical-align: middle;
      font-size: 15px;
      border: 1px solid #dee2e6;
    }

    .classlist_label {
      border: 1px solid #0d6efd;
      padding: 10px;
      margin-bottom: 12px;
      background: #f1f8ff;
      border-left: 6px solid #0d6efd;
      border-radius: 5px;
      font-size: 17px;
      text-align: center;
      font-weight: bold;
    }

    .classlist_footer {
      margin-top: 15px;
      font-size: 14px;
      border-top: 2px solid #0d6efd;
      padding-top: 8px;
    }

    .classlist_footer table td {
      padding: 4px 8px;
    }

    /* Modal loader */
    .modal-loader .modal-content {
      text-align: center;
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

    /* ── PRINT & PDF STYLES ── (only these are modified) */
    @media print {
      body {
        background: white;
        margin: 0;
        font-size: 13px;
        /* smaller font for more rows */
        color: black;
      }

      .no-print {
        display: none !important;
      }

      .classlist_container {
        box-shadow: none;
        border-radius: 0;
        max-width: 100%;
        margin: 0;
        padding: 8mm 10mm 15mm 10mm;
        /* tighter padding = more space for students */
        font-size: 12.5px;
        line-height: 1.28;
        /* tighter line height */
      }

      .classlist_header {
        border-bottom: 1.5px solid #0d6efd;
        padding-bottom: 4mm;
        margin-bottom: 6mm;
      }

      .classlist_header img {
        width: 80px;
        height: 80px;
      }

      .classlist_h2 {
        font-size: 18px;
        margin-bottom: 2mm;
      }

      .classlist_label {
        font-size: 15px;
        padding: 6px;
        margin-bottom: 8mm;
      }

      .classlist_table {
        width: 100%;
        font-size: 11.8px;
        /* smaller table text */
        border-collapse: collapse;
      }

      .classlist_table th,
      .classlist_table td {
        padding: 4px 6px !important;
        border: 0.5px solid #000;
        line-height: 1.2;
      }

      .classlist_table th {
        background: #e9f2ff !important;
        font-size: 12px;
      }

      /* Force header to repeat on every page */
      thead {
        display: table-header-group;
      }

      /* Prevent row splitting across pages */
      tbody tr {
        page-break-inside: avoid !important;
        break-inside: avoid-page !important;
      }

      /* Ensure at least ~27 rows per page (adjust height if needed) */
      tr {
        height: 18px;
        /* tighter row height → more rows per page */
      }

      /* Footer spacing */
      .classlist_footer {
        margin-top: 10mm;
        font-size: 11px;
      }
    }

    @page {
      size: A4 portrait;
      margin: 10mm 8mm 12mm 8mm;
      /* reduced margins → more content per page */
    }
  </style>
</head>

<body>

  <!-- Sticky Header (hidden in print) -->
  <div class="no-print" style="
    display: flex;
    align-items: center;
    justify-content: space-between;
    background-color: #1a1f71;
    padding: 10px 20px;
    border-radius: 8px;
    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
    color: #fff;
    margin-bottom: 15px;
    position: sticky;
    top: 0;
    z-index: 9999;
">
    <div style="display: flex; align-items: center; gap: 10px;">
      <img src="<?php echo htmlspecialchars($school_logo); ?>" alt="School Logo"
        style="height: 50px; width: auto; object-fit: contain; border-radius: 5px;">
      <span style="font-size: 18px; font-weight: bold;">
        <?php echo htmlspecialchars($school['name'] ?? 'KEILA HIGH SCHOOL'); ?>
      </span>
    </div>
    <div style="flex: 1; text-align: center; font-weight: bold; font-size: 16px;">
      <?php echo htmlspecialchars($list_title); ?>: <?php
                                                    $subtitle_parts = [];
                                                    if (!empty($class['form_name'])) $subtitle_parts[] = $class['form_name'];
                                                    if (!empty($stream_name)) $subtitle_parts[] = $stream_name;
                                                    if (!empty($group_name)) $subtitle_parts = [$group_name];
                                                    if ($subject_name !== '—') $subtitle_parts[] = $subject_name;
                                                    echo htmlspecialchars(implode(' - ', $subtitle_parts) . ' ' . date('Y'));
                                                    ?>
    </div>
    <div style="display: flex; gap: 8px; align-items: center;">
      <button style="background-color: #ff6b6b; border: none; padding: 6px 12px; border-radius: 5px; color: #fff; cursor: pointer; font-size: 12px;"
        onmouseover="this.style.backgroundColor='#e55b5b'" onmouseout="this.style.backgroundColor='#ff6b6b'"
        onclick="if (document.referrer) { window.location = document.referrer; } else { history.back(); location.reload(); }">
        <i class="fas fa-arrow-left"></i> Back
      </button>
      <button style="background-color: #007bff; border: none; padding: 6px 12px; border-radius: 5px; color: #fff; cursor: pointer; font-size: 12px;"
        onmouseover="this.style.backgroundColor='#0069d9'" onmouseout="this.style.backgroundColor='#007bff'"
        onclick="printReport()">
        <i class="fas fa-print"></i> Print Report
      </button>
      <button style="background-color: #6c757d; border: none; padding: 6px 12px; border-radius: 5px; color: #fff; font-size: 12px; cursor: not-allowed; opacity: 0.65;"
        disabled
        title="Download feature is currently disabled">
        <i class="fas fa-file-download"></i> Download Report
      </button>
    </div>
  </div>

  <!-- Download Modal -->
  <div class="modal fade modal-loader" id="downloadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-body">
          <div class="loader-spinner"></div>
          <p>Downloading the report...</p>
        </div>
      </div>
    </div>
  </div>

  <!-- Main content (this is what gets printed/PDF'd) -->
  <div class="classlist_container">
    <div class="classlist_header">
      <img src="<?php echo htmlspecialchars($school_logo); ?>" alt="Logo" /><br />
      <h2 class="classlist_h2"><?php echo htmlspecialchars($school['name'] ?? 'HIGH SCHOOL'); ?></h2>
      <p class="mb-0 fw-bold"><?php echo htmlspecialchars($list_title); ?></p>
      <p class="mb-0">
        <?php
        $current_year = date('Y');
        $subtitle_parts = [];
        if (!empty($class['form_name'])) $subtitle_parts[] = $class['form_name'];
        if (!empty($stream_name)) $subtitle_parts[] = $stream_name;
        if (!empty($group_name)) $subtitle_parts = [$group_name];
        if ($subject_name !== '—') $subtitle_parts[] = $subject_name;
        echo htmlspecialchars(implode(' - ', $subtitle_parts) . ' ' . $current_year);
        ?>
      </p>
    </div>

    <div class="classlist_label">
      TEACHER: <?php echo htmlspecialchars($teacher_name); ?>
    </div>

    <table class="table table-bordered table-sm text-center align-middle mb-2 classlist_table">
      <thead>
        <tr>
          <th>#</th>
          <th>ADMNO</th>
          <th>NAME</th>
          <th>KCPE</th>
          <th style="width:15%"></th>
          <th style="width:15%"></th>
          <th style="width:15%"></th>
          <th style="width:15%"></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($students)): ?>
          <tr>
            <td colspan="8" class="text-center text-danger py-4">
              No students found in this selection (check group/stream assignments).
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($students as $index => $student): ?>
            <tr>
              <td><?php echo $index + 1; ?></td>
              <td><?php echo htmlspecialchars($student['admission_no']); ?></td>
              <td style="text-align:left;"><?php echo htmlspecialchars($student['full_name']); ?></td>
              <td><?php echo htmlspecialchars($student['kcpe_score'] ?? '—'); ?></td>
              <td></td>
              <td></td>
              <td></td>
              <td></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

  <script>
    // Print (browser print dialog)
    function printReport() {
      window.print();
    }

    // Download PDF with better page control
    function downloadReport() {
      const container = document.querySelector('.classlist_container');
      if (!container) {
        alert('No report content found.');
        return;
      }

      const modal = new bootstrap.Modal(document.getElementById('downloadModal'), {
        backdrop: 'static',
        keyboard: false
      });
      modal.show();

      const filename = 'Class_List_<?php
                                    echo htmlspecialchars(
                                      ($class['form_name'] ?? '') . '_' .
                                        ($stream_name ?: $group_name ?: '') .
                                        ($subject_name !== '—' ? '_' . $subject_name : '') .
                                        '_' . date('Y-m-d') .
                                        '.pdf'
                                    );
                                    ?>';

      const opt = {
        margin: [10, 8, 12, 8], // mm — top, right, bottom, left
        filename: filename,
        image: {
          type: 'jpeg',
          quality: 0.98
        },
        html2canvas: {
          scale: 2.2, // higher scale = sharper text
          useCORS: true,
          logging: false,
          windowWidth: 950 // match container width
        },
        jsPDF: {
          unit: 'mm',
          format: 'a4',
          orientation: 'portrait'
        },
        pagebreak: {
          mode: ['avoid-all', 'css', 'legacy'],
          avoid: ['tr', '.classlist_label', '.classlist_header']
        }
      };

      // Clone container to avoid modifying live DOM
      const clone = container.cloneNode(true);
      clone.querySelectorAll('.no-print').forEach(el => el.remove());

      html2pdf()
        .set(opt)
        .from(clone)
        .save()
        .then(() => {
          modal.hide();
        })
        .catch(err => {
          console.error('PDF generation failed:', err);
          modal.hide();
          alert('Failed to generate PDF. Try Print instead.');
        });
    }
  </script>

  <?php ob_end_flush(); ?>