<?php
session_start();
ob_start(); // Start output buffering to prevent unwanted output
require __DIR__ . '../../../../connection/db.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['school_id']) || !isset($_SESSION['role_id'])) {
    header("Location: ../../login.php");
    exit;
}

$school_id = $_SESSION['school_id'];
$class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
$stream_id = isset($_POST['stream_id']) ? (int)$_POST['stream_id'] : 0;
$subject_id = isset($_POST['subject_id']) ? (int)$_POST['subject_id'] : 0;

if (empty($class_id) || empty($stream_id) || empty($subject_id)) {
    die("Required parameters are missing.");
}

// Fetch school details
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
    // Normalize to correct logo directory
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

// Fetch subject details
$stmt = $conn->prepare("SELECT name FROM subjects WHERE subject_id = ? AND school_id = ?");
$stmt->bind_param("ii", $subject_id, $school_id);
$stmt->execute();
$subject = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Fetch teacher for the selected subject and class
$stmt = $conn->prepare("
    SELECT CONCAT(u.first_name, ' ', COALESCE(u.other_names, '')) AS teacher_name
    FROM teacher_subjects ts
    JOIN users u ON ts.user_id = u.user_id
    WHERE ts.subject_id = ? AND ts.class_id = ? AND ts.school_id = ? AND u.status = 'active' AND u.deleted_at IS NULL
    LIMIT 1
");
$stmt->bind_param("iii", $subject_id, $class_id, $school_id);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Fetch students
$stmt = $conn->prepare("
    SELECT admission_no, full_name
    FROM students
    WHERE stream_id = ? AND class_id = ? AND school_id = ? AND deleted_at IS NULL
    ORDER BY full_name
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
  .scoresheet_container {
    max-width: 900px;
    margin: 10px auto;
    background: #fff;
    padding: 12px 15px;
    border-radius: 6px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
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
  }

  .scoresheet_footer table td {
    padding: 2px 6px;
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
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
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
    .scoresheet_footer {
      position: relative;
      bottom: 0;
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

<!-- Sticky Header (hidden in print) -->
<div class="no-print" style="
    display: flex;
    align-items: center;
    justify-content: space-between;
    background-color: #1a1f71; /* navy */
    padding: 10px 20px;
    border-radius: 8px;
    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
    color: #fff;
    margin-bottom: 15px;
    position: sticky;  /* Make it sticky */
    top: 0;            /* Stick to top */
    z-index: 9999;     /* Stay above other content */
">
  <!-- Left: School Logo -->
  <div style="display: flex; align-items: center; gap: 10px;">
    <img src="<?php echo htmlspecialchars($school_logo); ?>" alt="School Logo" 
         style="height: 50px; width: auto; object-fit: contain; border-radius: 5px;">
    <span style="font-size: 18px; font-weight: bold;">
      <?php echo htmlspecialchars($school['name'] ?? 'KEILA HIGH SCHOOL'); ?>
    </span>
  </div>

  <!-- Center: Report Title -->
  <div style="flex: 1; text-align: center; font-weight: bold; font-size: 16px;">
    Score Sheet: <?php echo htmlspecialchars(($class['form_name'] ?? '') . ' ' . ($stream['stream_name'] ?? '') . ' - ' . ($subject['name'] ?? 'Selected Subject')); ?>
  </div>

  <!-- Right: Buttons -->
  <div style="display: flex; gap: 8px; align-items: center;">
    <button style="background-color: #ff6b6b; border: none; padding: 6px 12px; border-radius: 5px; color: #fff; cursor: pointer; font-size: 12px;"
            onmouseover="this.style.backgroundColor='#e55b5b'" 
            onmouseout="this.style.backgroundColor='#ff6b6b'" 
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

<div class="scoresheet_container">
  <!-- Header -->
  <div class="scoresheet_header">
    <img src="<?php echo htmlspecialchars($school_logo); ?>" alt="Logo" /><br />
    <h2 class="scoresheet_h2"><?php echo htmlspecialchars($school['name'] ?? 'KEILA HIGH SCHOOL'); ?></h2>
    <p class="mb-0 fw-bold">Score Sheet</p>
    <p class="mb-0"><?php echo htmlspecialchars(($class['form_name'] ?? '') . ' ' . ($stream['stream_name'] ?? '') . ' - ' . ($subject['name'] ?? 'Selected Subject')); ?></p>
  </div>

  <!-- Label -->
  <div class="scoresheet_label">
    TEACHER: <?php echo htmlspecialchars($teacher['teacher_name'] ?? 'N/A'); ?>
  </div>

  <!-- Exam Name Label -->
  <div class="scoresheet_exam_label">
    EXAM NAME: _______________________
  </div>

  <!-- Table -->
  <table class="table table-bordered table-sm text-center align-middle mb-2 scoresheet_table">
    <thead>
      <tr>
        <th>ADM NO</th>
        <th>NAME</th>
        <th>MARKS</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($students as $student): ?>
        <tr>
          <td><?php echo htmlspecialchars($student['admission_no']); ?></td>
          <td><?php echo htmlspecialchars($student['full_name']); ?></td>
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
    const containers = document.querySelectorAll('.scoresheet_container');
    if (containers.length === 0) {
        alert('No reports available to download.');
        return;
    }

    // Show the modal
    const modal = new bootstrap.Modal(document.getElementById('downloadModal'), {
        backdrop: 'static',
        keyboard: false
    });
    modal.show();

    const opt = {
        margin: 10,
        filename: 'Score_Sheet_<?php echo htmlspecialchars(($class['form_name'] ?? '') . '_' . ($stream['stream_name'] ?? '') . '_' . ($subject['name'] ?? 'Subject') . '.pdf'); ?>',
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

<?php ob_end_flush(); ?>