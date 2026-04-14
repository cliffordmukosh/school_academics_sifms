<?php
// reports/examreports/subjectanalysisreport_exam.php
session_start();
ob_start();
require __DIR__ . '../../../../connection/db.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['school_id']) || !isset($_SESSION['role_id'])) {
  header("Location: ../../login.php");
  exit;
}

$school_id = $_SESSION['school_id'];

// Fetch POST parameters
$year      = isset($_POST['year']) ? $_POST['year'] : '2025';
$class_id  = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
$term      = isset($_POST['term']) ? $_POST['term'] : '';
$exam_id   = isset($_POST['exam_id']) ? (int)$_POST['exam_id'] : 0;
$stream_id = isset($_POST['stream_id']) && $_POST['stream_id'] !== '' ? (int)$_POST['stream_id'] : null;

// Fetch school details
$stmt = $conn->prepare("SELECT name, logo FROM schools WHERE school_id = ?");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$school = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Process school logo
$school_logo = $school['logo'] ?? '';
if (empty($school_logo)) {
  $school_logo = 'https://academics.sifms.co.ke/manageschool/logos/school-logo.png';
} elseif (strpos($school_logo, 'http') !== 0) {
  $school_logo = 'https://academics.sifms.co.ke/manageschool/logos/' . basename($school_logo);
}

// Fetch class and stream details
$class_name = '';
$stream_name = 'All Streams';
if ($class_id) {
  $stmt = $conn->prepare("SELECT form_name FROM classes WHERE class_id = ? AND school_id = ?");
  $stmt->bind_param("ii", $class_id, $school_id);
  $stmt->execute();
  $class = $stmt->get_result()->fetch_assoc();
  $class_name = $class['form_name'] ?? '';
  $stmt->close();
}
if ($stream_id) {
  $stmt = $conn->prepare("SELECT stream_name FROM streams WHERE stream_id = ? AND school_id = ?");
  $stmt->bind_param("ii", $stream_id, $school_id);
  $stmt->execute();
  $stream = $stmt->get_result()->fetch_assoc();
  $stream_name = $stream['stream_name'] ?? 'All Streams';
  $stmt->close();
}
$full_term = $class_name && $term ? "$class_name $term" : $term;

// Fetch exam details
$exam_name = '';
if ($exam_id) {
  $stmt = $conn->prepare("SELECT exam_name FROM exams WHERE exam_id = ? AND school_id = ?");
  $stmt->bind_param("ii", $exam_id, $school_id);
  $stmt->execute();
  $exam = $stmt->get_result()->fetch_assoc();
  $exam_name = $exam['exam_name'] ?? '';
  $stmt->close();
}

// Fetch all streams for the class
$streams = [];
if (!$stream_id) {
  $stmt = $conn->prepare("SELECT stream_id, stream_name FROM streams WHERE class_id = ? AND school_id = ?");
  $stmt->bind_param("ii", $class_id, $school_id);
  $stmt->execute();
  $streams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
}

// Points map
$points_map = [
  'A' => 12,
  'A-' => 11,
  'B+' => 10,
  'B' => 9,
  'B-' => 8,
  'C+' => 7,
  'C' => 6,
  'C-' => 5,
  'D+' => 4,
  'D' => 3,
  'D-' => 2,
  'E' => 1
];

// Fetch subjects for the exam
$subjects = [];
$stmt = $conn->prepare("
    SELECT es.subject_id, s.name 
    FROM exam_subjects es
    JOIN subjects s ON es.subject_id = s.subject_id
    WHERE es.exam_id = ?
");
$stmt->bind_param("i", $exam_id);
$stmt->execute();
$subjects_result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($subjects_result as $subj) {
  $subjects[$subj['subject_id']] = ['name' => $subj['name']];
}

// Initialize arrays
$grade_distribution = [];
$mean_points = [];
$subject_entry = [];        // ← NEW: Real entry count per subject per stream
$grades = ['A', 'A-', 'B+', 'B', 'B-', 'C+', 'C', 'C-', 'D+', 'D', 'D-', 'E'];

foreach ($subjects as $subject_id => $subject) {
  // Initialize
  if ($stream_id) {
    $grade_distribution[$subject_id][$stream_id] = array_fill_keys($grades, 0);
    $mean_points[$subject_id][$stream_id] = ['sum' => 0, 'count' => 0];
    $subject_entry[$subject_id][$stream_id] = 0;
  } else {
    foreach ($streams as $stream) {
      $grade_distribution[$subject_id][$stream['stream_id']] = array_fill_keys($grades, 0);
      $mean_points[$subject_id][$stream['stream_id']] = ['sum' => 0, 'count' => 0];
      $subject_entry[$subject_id][$stream['stream_id']] = 0;
    }
    $grade_distribution[$subject_id]['total'] = array_fill_keys($grades, 0);
    $mean_points[$subject_id]['total'] = ['sum' => 0, 'count' => 0];
    $subject_entry[$subject_id]['total'] = 0;
  }

  // === PER STREAM ===
  $query_streams = $stream_id
    ? [['stream_id' => $stream_id, 'stream_name' => $stream_name]]
    : $streams;

  foreach ($query_streams as $str) {
    $str_id = $str['stream_id'];

    $stmt = $conn->prepare("
            SELECT esa.subject_grade, COUNT(*) AS cnt
            FROM exam_subject_aggregates esa
            JOIN students s ON esa.student_id = s.student_id
            WHERE esa.exam_id = ? AND esa.subject_id = ? AND s.stream_id = ?
            GROUP BY esa.subject_grade
        ");
    $stmt->bind_param("iii", $exam_id, $subject_id, $str_id);
    $stmt->execute();
    $gc = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $entry = 0;
    $sum_points = 0;

    foreach ($gc as $g) {
      $grade = $g['subject_grade'];
      $cnt = (int)$g['cnt'];

      if (in_array($grade, $grades)) {
        $grade_distribution[$subject_id][$str_id][$grade] = $cnt;
        $entry += $cnt;
        $sum_points += $cnt * ($points_map[$grade] ?? 0);
      }
    }

    $subject_entry[$subject_id][$str_id] = $entry;

    if ($entry > 0) {
      $mean_points[$subject_id][$str_id]['sum'] = $sum_points;
      $mean_points[$subject_id][$str_id]['count'] = $entry;
    }
  }

  // === FORM TOTAL ===
  if (!$stream_id) {
    $stmt = $conn->prepare("
            SELECT esa.subject_grade, COUNT(*) AS cnt
            FROM exam_subject_aggregates esa
            JOIN students s ON esa.student_id = s.student_id
            WHERE esa.exam_id = ? AND esa.subject_id = ? AND s.class_id = ?
            GROUP BY esa.subject_grade
        ");
    $stmt->bind_param("iii", $exam_id, $subject_id, $class_id);
    $stmt->execute();
    $form_gc = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $form_entry = 0;
    $form_sum = 0;

    foreach ($form_gc as $g) {
      $grade = $g['subject_grade'];
      $cnt = (int)$g['cnt'];

      if (in_array($grade, $grades)) {
        $grade_distribution[$subject_id]['total'][$grade] = $cnt;
        $form_entry += $cnt;
        $form_sum += $cnt * ($points_map[$grade] ?? 0);
      }
    }

    $subject_entry[$subject_id]['total'] = $form_entry;

    if ($form_entry > 0) {
      $mean_points[$subject_id]['total']['sum'] = $form_sum;
      $mean_points[$subject_id]['total']['count'] = $form_entry;
    }
  }
}
?>

<!-- HTML + UI -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

<style>
  .analysisreport_container {
    max-width: 1200px;
    margin: 10px auto;
    background: #fff;
    padding: 12px 15px;
    border-radius: 6px;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
    font-size: 12px;
    page-break-after: always;
    font-family: Arial, Helvetica, sans-serif;
  }

  .analysisreport_h2 {
    font-size: 16px;
    margin-bottom: 2px;
    font-weight: bold;
    color: #0d6efd;
  }

  .analysisreport_header {
    text-align: center;
    border-bottom: 2px solid #0d6efd;
    margin-bottom: 6px;
    padding-bottom: 4px;
  }

  .analysisreport_header img {
    width: 100px;
    height: 100px;
    object-fit: contain;
    margin-bottom: 4px;
  }

  .analysisreport_table th {
    background: #e9f2ff !important;
    color: #0d47a1;
    text-align: center;
  }

  .analysisreport_table th,
  .analysisreport_table td {
    padding: 3px 5px !important;
    vertical-align: middle;
    font-size: 11px;
    border: 1px solid #dee2e6;
  }

  .analysisreport_title {
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

  .analysisreport_footer {
    margin-top: 10px;
    font-size: 12px;
    border-top: 2px solid #0d6efd;
    padding-top: 5px;
  }

  .analysisreport_footer table td {
    padding: 2px 6px;
  }

  .red-text {
    color: red;
    font-weight: bold;
  }

  @media print {
    body {
      background: none;
      margin: 0;
      font-size: 12px;
    }

    .analysisreport_container {
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

<!-- Sticky Header -->
<div class="no-print" style="display: flex; align-items: center; justify-content: space-between; background-color: #1a1f71; padding: 10px 20px; border-radius: 8px; box-shadow: 0 4px 8px rgba(0,0,0,0.2); color: #fff; margin-bottom: 15px; position: sticky; top: 0; z-index: 9999;">
  <div style="display: flex; align-items: center; gap: 10px;">
    <img src="<?php echo htmlspecialchars($school_logo); ?>" alt="School Logo" style="height: 50px; width: auto; object-fit: contain; border-radius: 5px;">
    <span style="font-size: 18px; font-weight: bold;"><?php echo htmlspecialchars($school['name'] ?? 'KEILA HIGH SCHOOL'); ?></span>
  </div>
  <div style="flex: 1; text-align: center; font-weight: bold; font-size: 16px;">
    Grade Analysis: <?php echo htmlspecialchars($exam_name); ?> (<?php echo htmlspecialchars($full_term); ?> - <?php echo $year; ?>)
  </div>
  <div style="display: flex; gap: 8px; align-items: center;">
    <button style="background-color: #ff6b6b; border: none; padding: 6px 12px; border-radius: 5px; color: #fff; cursor: pointer; font-size: 12px;" onclick="if (document.referrer) { window.location = document.referrer; } else { history.back(); location.reload(); }">← Back</button>
    <button style="background-color: #007bff; border: none; padding: 6px 12px; border-radius: 5px; color: #fff; cursor: pointer; font-size: 12px;" onclick="printReport()">Print Report</button>
    <button style="background-color: #20c997; border: none; padding: 6px 12px; border-radius: 5px; color: #fff; cursor: pointer; font-size: 12px;" onclick="downloadReport()">Download Report</button>
  </div>
</div>

<div class="analysisreport_container">
  <div class="analysisreport_header">
    <img src="<?php echo htmlspecialchars($school_logo); ?>" alt="Logo" /><br />
    <h2 class="analysisreport_h2"><?php echo htmlspecialchars($school['name'] ?? 'KEILA HIGH SCHOOL'); ?></h2>
    <p class="mb-0 fw-bold">GRADE ANALYSIS</p>
    <p class="mb-0"><?php echo htmlspecialchars($full_term . ' Year ' . $year); ?></p>
    <p class="mb-0"><strong>STREAM:</strong> <?php echo htmlspecialchars($stream_name); ?></p>
    <p class="mb-0"><strong>EXAM NAME:</strong> <?php echo htmlspecialchars($exam_name); ?></p>
  </div>

  <div class="analysisreport_title">SUBJECT GRADE ANALYSIS (Examination Analysis)</div>

  <div class="table-responsive">
    <table class="table table-bordered table-sm text-center align-middle mb-2 analysisreport_table">
      <thead>
        <tr>
          <th>Subject</th>
          <th>Stream</th>
          <?php foreach ($grades as $grade): ?>
            <th><?php echo $grade; ?></th>
          <?php endforeach; ?>
          <th>StudCnt</th>
          <th>M.S</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($subjects as $subject_id => $subject): ?>
          <?php
          $display_streams = $stream_id
            ? [['stream_id' => $stream_id, 'stream_name' => $stream_name]]
            : array_merge($streams, [['stream_id' => 'total', 'stream_name' => 'Total']]);
          $rowspan = count($display_streams);
          ?>
          <?php foreach ($display_streams as $index => $stream): ?>
            <tr>
              <?php if ($index === 0): ?>
                <td rowspan="<?php echo $rowspan; ?>"><?php echo htmlspecialchars($subject['name']); ?></td>
              <?php endif; ?>
              <td><?php echo htmlspecialchars($stream['stream_name']); ?></td>
              <?php foreach ($grades as $grade): ?>
                <td><?php echo $grade_distribution[$subject_id][$stream['stream_id']][$grade] ?? 0; ?></td>
              <?php endforeach; ?>
              <td>
                <?php
                $current_id = $stream['stream_id'];
                echo $subject_entry[$subject_id][$current_id] ?? 0;
                ?>
              </td>
              <td <?php echo $stream['stream_id'] === 'total' ? 'class="red-text"' : ''; ?>>
                <?php
                $mean = ($mean_points[$subject_id][$stream['stream_id']]['count'] > 0)
                  ? round($mean_points[$subject_id][$stream['stream_id']]['sum'] / $mean_points[$subject_id][$stream['stream_id']]['count'], 3)
                  : 0;
                echo number_format($mean, 3);
                ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <footer class="analysisreport_footer">
    <table class="table table-borderless table-sm mb-1 w-100">
      <tr>
        <td><strong>Generated on:</strong> <?php echo date('Y-m-d H:i:s'); ?></td>
        <td><strong>Page:</strong> 1 of 1</td>
      </tr>
    </table>
  </footer>
</div>

<script>
  function printReport() {
    window.print();
  }

  function downloadReport() {
    const containers = document.querySelectorAll('.analysisreport_container');
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
      filename: 'Grade_Analysis_Report_<?php echo str_replace(' ', '_', htmlspecialchars($exam_name)); ?>_<?php echo $year; ?>.pdf',
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