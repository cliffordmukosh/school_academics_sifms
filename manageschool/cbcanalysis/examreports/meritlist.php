<?php
// reports/examreports/meritlist.php
session_start();
require __DIR__ . '/../../../connection/db.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['school_id']) || !isset($_SESSION['role_id'])) {
  header("Location: ../../../login.php");
  exit;
}

$school_id = $_SESSION['school_id'];

// Get parameters from GET
$class_id   = isset($_GET['class_id'])  ? (int)$_GET['class_id']  : 0;
$term       = isset($_GET['term'])      ? trim($_GET['term'])     : '';
$exam_id    = isset($_GET['exam_id'])   ? (int)$_GET['exam_id']   : 0;
$stream_id  = isset($_GET['stream_id']) ? (int)$_GET['stream_id'] : 0;

if (empty($class_id) || empty($term) || empty($exam_id)) {
  die('Invalid parameters. Please select Form, Term, Exam, and Stream.');
}

// Fetch school details
$stmt = $conn->prepare("SELECT name, logo FROM schools WHERE school_id = ?");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$school = $stmt->get_result()->fetch_assoc();
$stmt->close();

$school_logo = $school['logo'] ?? 'https://academics.sifms.co.ke/manageschool/logos/school-logo.png';
if (strpos($school_logo, 'http') !== 0) {
  $school_logo = 'https://academics.sifms.co.ke/manageschool/logos/' . basename($school_logo);
}

// Fetch class name
$stmt = $conn->prepare("SELECT form_name FROM classes WHERE class_id = ? AND school_id = ?");
$stmt->bind_param("ii", $class_id, $school_id);
$stmt->execute();
$class = $stmt->get_result()->fetch_assoc();
$class_name = $class ? $class['form_name'] : 'Unknown Form';
$stmt->close();

// Fetch exam details + grading_system_id
$stmt = $conn->prepare("SELECT exam_name, YEAR(created_at) AS year, grading_system_id FROM exams WHERE exam_id = ? AND school_id = ?");
$stmt->bind_param("ii", $exam_id, $school_id);
$stmt->execute();
$exam = $stmt->get_result()->fetch_assoc();
$exam_name = $exam ? $exam['exam_name'] : 'Unknown Exam';
$year = $exam ? $exam['year'] : date('Y');
$grading_system_id = $exam ? (int)$exam['grading_system_id'] : 0;
$stmt->close();

// Fetch stream name
$stream_name = 'All Streams';
if ($stream_id !== 0) {
  $stmt = $conn->prepare("SELECT stream_name FROM streams WHERE stream_id = ? AND school_id = ?");
  $stmt->bind_param("ii", $stream_id, $school_id);
  $stmt->execute();
  $stream = $stmt->get_result()->fetch_assoc();
  $stream_name = $stream ? $stream['stream_name'] : 'Unknown Stream';
  $stmt->close();
}

// Get all students in the class/stream
$students_query = "
    SELECT s.student_id, s.admission_no, s.full_name, st.stream_name
    FROM students s
    LEFT JOIN streams st ON s.stream_id = st.stream_id
    WHERE s.class_id = ? AND s.school_id = ? AND s.deleted_at IS NULL
";
$types = "ii";
$params = [$class_id, $school_id];

if ($stream_id !== 0) {
  $students_query .= " AND s.stream_id = ?";
  $types .= "i";
  $params[] = $stream_id;
}

$students_query .= " ORDER BY s.full_name";
$stmt = $conn->prepare($students_query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get all confirmed results for this exam
$student_ids = array_column($students, 'student_id');
if (empty($student_ids)) {
  $results = [];
} else {
  $placeholders = implode(',', array_fill(0, count($student_ids), '?'));
  $stmt = $conn->prepare("
        SELECT r.student_id, r.subject_id, r.score
        FROM results r
        WHERE r.exam_id = ? 
          AND r.student_id IN ($placeholders)
          AND r.status = 'confirmed'
          AND r.score IS NOT NULL
          AND r.deleted_at IS NULL
    ");
  $types = "i" . str_repeat("i", count($student_ids));
  $params = array_merge([$exam_id], $student_ids);
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
}

// Group results by student → subject → score
$student_scores = [];
foreach ($results as $r) {
  $student_scores[$r['student_id']][$r['subject_id']] = $r['score'];
}

// Fetch grading rules
$grading_rules = [];
if ($grading_system_id > 0) {
  $stmt = $conn->prepare("
        SELECT min_score, max_score, points, grade
        FROM grading_rules
        WHERE grading_system_id = ?
        ORDER BY points DESC
    ");
  $stmt->bind_param("i", $grading_system_id);
  $stmt->execute();
  $grading_rules = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
}

// Calculate aggregates per student
$aggregates = [];
foreach ($students as $student) {
  $student_id = $student['student_id'];
  $scores = $student_scores[$student_id] ?? [];

  $total_marks = 0.0;
  $total_points = 0;

  foreach ($scores as $subject_id => $score) {
    $total_marks += (float)$score;

    $points = 0;
    foreach ($grading_rules as $rule) {
      if ($score >= $rule['min_score'] && $score <= $rule['max_score']) {
        $points = (int)$rule['points'];
        break;
      }
    }
    $total_points += $points;
  }

  $aggregates[] = [
    'admission_no' => $student['admission_no'],
    'full_name'    => $student['full_name'],
    'stream_name'  => $student['stream_name'],
    'total_marks'  => $total_marks,
    'total_points' => $total_points,
  ];
}

// Sort by total_points DESC (merit order)
usort($aggregates, function ($a, $b) {
  return $b['total_points'] <=> $a['total_points'];
});
?>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

<style>
  .meritlist_container {
    max-width: 820px;
    margin: 10px auto;
    background: #fff;
    padding: 12px 15px;
    border-radius: 6px;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
    font-size: 12px;
  }

  .meritlist_h2 {
    font-size: 16px;
    margin-bottom: 2px;
    font-weight: bold;
    color: #0d6efd;
  }

  .meritlist_header {
    text-align: center;
    border-bottom: 2px solid #0d6efd;
    margin-bottom: 6px;
    padding-bottom: 4px;
  }

  .meritlist_header img {
    width: 100px;
    height: 100px;
    object-fit: contain;
    margin-bottom: 4px;
  }

  .meritlist_table th {
    background: #e9f2ff !important;
    color: #0d47a1;
    text-align: center;
  }

  .meritlist_table th,
  .meritlist_table td {
    padding: 3px 5px !important;
    vertical-align: middle;
    font-size: 11px;
    border: 1px solid #dee2e6;
  }

  .footer-signatures {
    display: flex;
    justify-content: space-between;
    margin-top: 20px;
    font-size: 10px;
  }

  .footer-signatures div {
    width: 45%;
    text-align: center;
  }

  .signature-line {
    display: inline-block;
    width: 100px;
    border-bottom: 1px solid #000;
    margin: 0 5px;
  }

  .title {
    font-weight: bold;
    margin-top: 5px;
    font-size: 10px;
  }

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
      font-size: 12px;
    }

    .meritlist_container {
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
    size: A4;
    margin: 10mm;
  }
</style>

<!-- Sticky Header (hidden in print) -->
<div class="no-print" style="display: flex; align-items: center; justify-content: space-between; background-color: #1a1f71; padding: 10px 20px; border-radius: 8px; box-shadow: 0 4px 8px rgba(0,0,0,0.2); color: #fff; margin-bottom: 15px; position: sticky; top: 0; z-index: 9999;">
  <div style="display: flex; align-items: center; gap: 10px;">
    <img src="<?php echo htmlspecialchars($school_logo); ?>" alt="School Logo" style="height: 50px; width: auto; object-fit: contain; border-radius: 5px;">
    <span style="font-size: 18px; font-weight: bold;"><?php echo htmlspecialchars($school['name'] ?? 'KEILA HIGH SCHOOL'); ?></span>
  </div>
  <div style="flex: 1; text-align: center; font-weight: bold; font-size: 16px;">
    Merit List: <?php echo htmlspecialchars($class_name . ' - ' . $stream_name . ' - ' . $exam_name . ' (' . $term . ' ' . $year . ')'); ?>
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

<div class="meritlist_container">
  <!-- Header -->
  <div class="meritlist_header">
    <img src="<?php echo htmlspecialchars($school_logo); ?>" alt="Logo" /><br />
    <h2 class="meritlist_h2"><?php echo htmlspecialchars($school['name'] ?? 'KEILA HIGH SCHOOL'); ?></h2>
    <p class="mb-0 fw-bold">Merit List</p>
    <p class="mb-0"><?php echo htmlspecialchars($class_name . ' - ' . $stream_name . ' - ' . $exam_name . ' (' . $term . ' ' . $year . ')'); ?></p>
  </div>

  <!-- Merit List Table -->
  <table class="table table-bordered table-sm text-center align-middle mb-2 meritlist_table">
    <thead>
      <tr>
        <th>#</th>
        <th>Adm No</th>
        <th>Student Name</th>
        <?php if ($stream_id === 0): ?>
          <th>Stream</th>
        <?php endif; ?>
        <th>Total Marks</th>
        <th>Total Points</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($aggregates)): ?>
        <tr>
          <td colspan="<?php echo ($stream_id === 0 ? 6 : 5); ?>" class="text-center">No data available</td>
        </tr>
      <?php else: ?>
        <?php foreach ($aggregates as $index => $agg): ?>
          <tr>
            <td><?php echo $index + 1; ?></td>
            <td><?php echo htmlspecialchars($agg['admission_no']); ?></td>
            <td><?php echo htmlspecialchars($agg['full_name']); ?></td>
            <?php if ($stream_id === 0): ?>
              <td><?php echo htmlspecialchars($agg['stream_name']); ?></td>
            <?php endif; ?>
            <td><?php echo number_format($agg['total_marks'], 2); ?></td>
            <td><?php echo number_format($agg['total_points'], 0); ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>

  <!-- Footer Signatures -->
  <div class="footer-signatures">
    <div>
      <p><strong>PREPARED BY</strong></p>
      <p>SIGN: <span class="signature-line"></span> DATED: <span class="signature-line"></span></p>
      <p class="title">DEPUTY PRINCIPAL (ACADEMICS)</p>
    </div>
    <div>
      <p><strong>APPROVED BY</strong></p>
      <p>SIGN: <span class="signature-line"></span> DATED: <span class="signature-line"></span></p>
      <p class="title">PRINCIPAL</p>
    </div>
  </div>
</div>

<script>
  function printReport() {
    window.print();
  }

  function downloadReport() {
    const container = document.querySelector('.meritlist_container');
    if (!container) {
      alert('No report available to download.');
      return;
    }

    const modal = new bootstrap.Modal(document.getElementById('downloadModal'), {
      backdrop: 'static',
      keyboard: false
    });
    modal.show();

    const opt = {
      margin: 10,
      filename: '<?php echo htmlspecialchars($class_name . '_' . $stream_name . '_' . $exam_name . '_' . $term . '_' . $year); ?>.pdf',
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

    const clone = container.cloneNode(true);
    clone.querySelectorAll('.no-print').forEach(el => el.remove());

    html2pdf().set(opt).from(clone).save().then(() => {
      clone.remove();
      modal.hide();
    }).catch(err => {
      console.error('PDF generation failed:', err);
      modal.hide();
      alert('Failed to generate PDF. Please try again.');
    });
  }
</script>