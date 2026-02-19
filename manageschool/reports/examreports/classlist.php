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
$subject_id = isset($_POST['subject_id']) && $_POST['subject_id'] !== ''
  ? (int)$_POST['subject_id']
  : 0;

// ────────────────────────────────────────────────
// Prepare fallbacks
// ────────────────────────────────────────────────
$subject_name = '—';
$teacher_name = '______ ___';

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
$stmt = $conn->prepare("SELECT stream_name FROM streams WHERE stream_id = ? AND class_id = ? AND school_id = ?");
$stmt->bind_param("iii", $stream_id, $class_id, $school_id);
$stmt->execute();
$stream = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ────────────────────────────────────────────────
// Fetch subject (only if selected)
// ────────────────────────────────────────────────
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

// ────────────────────────────────────────────────
// Fetch teacher – only if subject is selected
// ────────────────────────────────────────────────
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

// Fetch students (always – doesn't depend on subject)
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
?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<style>
  .classlist_container {
    max-width: 950px;
    margin: 10px auto;
    background: #fff;
    padding: 15px 20px;
    border-radius: 6px;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
    font-size: 15px;
    page-break-after: always;
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

  /* Modal styles */
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

  @media print {
    body {
      background: none;
      margin: 0;
      font-size: 14px;
    }

    .classlist_container {
      box-shadow: none;
      border-radius: 0;
      max-width: 100%;
      padding: 12mm 15mm 20mm 15mm;
      /* more bottom space helps avoid bad breaks */
      margin: 0;
    }

    .no-print {
      display: none !important;
    }

    .classlist_table {
      width: 100%;
      page-break-inside: auto;
    }

    .classlist_table thead {
      display: table-header-group;
      /* repeat header on every page */
    }

    .classlist_table tbody tr {
      page-break-inside: avoid;
      /* ← prevent splitting rows */
      break-inside: avoid;
      /* modern property */
      break-before: auto;
      break-after: auto;
    }

    .classlist_table td {
      font-size: 14px;
    }
  }

  @page {
    size: A4;
    margin: 12mm 10mm;
    /* slightly adjusted for better balance */
  }
</style>
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
    Class List: <?php
                echo htmlspecialchars(
                  ($class['form_name'] ?? '') . ' ' .
                    ($stream['stream_name'] ?? '') .
                    ($subject_name !== '—' ? ' - ' . $subject_name : '')
                );
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
    <button style="background-color: #20c997; border: none; padding: 6px 12px; border-radius: 5px; color: #fff; cursor: pointer; font-size: 12px;"
      onmouseover="this.style.backgroundColor='#1aa179'" onmouseout="this.style.backgroundColor='#20c997'"
      onclick="downloadReport()">
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
<div class="classlist_container">
  <div class="classlist_header">
    <img src="<?php echo htmlspecialchars($school_logo); ?>" alt="Logo" /><br />
    <h2 class="classlist_h2"><?php echo htmlspecialchars($school['name'] ?? 'HIGH SCHOOL'); ?></h2>
    <p class="mb-0 fw-bold">Class List</p>
    <p class="mb-0">
      <?php
      $current_year = date('Y');
      echo htmlspecialchars(
        ($class['form_name'] ?? '') . ' ' .
          ($stream['stream_name'] ?? '') .
          ($subject_name !== '—' ? ' - ' . $subject_name : '') .
          ' ' . $current_year
      );
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
      <?php foreach ($students as $index => $student): ?>
        <tr>
          <td><?php echo $index + 1; ?></td>
          <td><?php echo htmlspecialchars($student['admission_no']); ?></td>
          <td style="text-align:left;"><?php echo htmlspecialchars($student['full_name']); ?></td>
          <td></td>
          <td></td>
          <td></td>
          <td></td>
          <td></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<script>
  function printReport() {
    window.print();
  }

  function downloadReport() {
    const containers = document.querySelectorAll('.classlist_container');
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
      margin: 12,
      filename: 'Class_List_<?php echo htmlspecialchars(($class['form_name'] ?? '') . '_' . ($stream['stream_name'] ?? '') . ($subject_name !== '—' ? '_' . $subject_name : '') . '.pdf'); ?>',
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
      },
      pagebreak: {
        mode: ['avoid-all', 'css', 'legacy']
      } // ← improved page break handling
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
<?php ob_end_flush(); ?>