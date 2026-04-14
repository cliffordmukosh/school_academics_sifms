<?php
// reports/examreports/schoolanalysis_perexam.php

// Allow both GET and POST for debugging / direct testing
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
  http_response_code(405);
  die("<div class='alert alert-danger text-center'>Method Not Allowed (only POST/GET supported).</div>");
}

session_start();
ob_start();
require __DIR__ . '/../../../connection/db.php';

// Security: require login
if (!isset($_SESSION['user_id']) || !isset($_SESSION['school_id'])) {
  header("Location: ../../login.php");
  exit;
}

$school_id = (int)$_SESSION['school_id'];

// ────────────────────────────────────────────────
// Get inputs (support both POST and GET)
$year      = (int)($_POST['year'] ?? $_GET['year'] ?? date('Y'));
$exam_id   = (int)($_POST['exam_id'] ?? $_GET['exam_id'] ?? 0);
$term      = trim($_POST['term'] ?? $_GET['term'] ?? '');
$class_ids_raw = $_POST['class_ids'] ?? $_GET['class_ids'] ?? [];
$class_ids = is_array($class_ids_raw) ? array_map('intval', $class_ids_raw) : [];

// Validation
if (!$year || $exam_id <= 0 || empty($class_ids)) {
  die("<div class='alert alert-danger text-center'>Year, Exam ID, and at least one Class are required.</div>");
}

// ────────────────────────────────────────────────
// School
$stmt = $conn->prepare("SELECT name, logo FROM schools WHERE school_id = ?");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$school = $stmt->get_result()->fetch_assoc() ?? ['name' => 'School', 'logo' => ''];
$stmt->close();

$school_logo = $school['logo'] ?: 'https://academics.sifms.co.ke/manageschool/logos/school-logo.png';
if (strpos($school_logo, 'http') !== 0) {
  $school_logo = 'https://academics.sifms.co.ke/manageschool/logos/' . basename($school_logo);
}

// ────────────────────────────────────────────────
// Exam name & term
$stmt = $conn->prepare("SELECT exam_name, term FROM exams WHERE exam_id = ? AND school_id = ?");
$stmt->bind_param("ii", $exam_id, $school_id);
$stmt->execute();
$exam = $stmt->get_result()->fetch_assoc() ?? ['exam_name' => 'Unknown Exam', 'term' => $term];
$stmt->close();

// ────────────────────────────────────────────────
// Main data fetch
$in_placeholders = implode(',', array_fill(0, count($class_ids), '?'));
$stmt = $conn->prepare("
    SELECT 
        ea.class_id, 
        c.form_name, 
        ea.stream_id, 
        COALESCE(s.stream_name, 'No Stream') AS stream_name,
        ea.mean_grade, 
        ea.mean_score, 
        st.gender
    FROM exam_aggregates ea
    JOIN classes c ON ea.class_id = c.class_id
    LEFT JOIN streams s ON ea.stream_id = s.stream_id
    JOIN students st ON ea.student_id = st.student_id
    WHERE ea.school_id = ? 
      AND ea.exam_id = ?
      AND ea.class_id IN ($in_placeholders)
      AND ea.mean_grade IS NOT NULL
    ORDER BY c.form_name, s.stream_name, ea.mean_grade DESC
");

$params = array_merge([$school_id, $exam_id], $class_ids);
$types  = 'ii' . str_repeat('i', count($class_ids));

$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (empty($rows)) {
  die("<div class='alert alert-warning text-center'>No confirmed results found for this exam and selected classes.</div>");
}

// ────────────────────────────────────────────────
// Aggregate in PHP
$class_data = [];
$grade_order = ['A', 'A-', 'B+', 'B', 'B-', 'C+', 'C', 'C-', 'D+', 'D', 'D-', 'E', 'X', 'Y'];

foreach ($rows as $r) {
  $cid   = $r['class_id'];
  $sid   = $r['stream_id'] ?? 0;
  $grade = $r['mean_grade'] ?: 'X';

  if (!isset($class_data[$cid])) {
    $class_data[$cid] = [
      'form_name' => $r['form_name'],
      'streams'   => [],
      'totals'    => ['entry' => 0, 'grades' => array_fill_keys($grade_order, 0)],
      'gender'    => [
        'Male'   => ['entry' => 0, 'grades' => array_fill_keys($grade_order, 0)],
        'Female' => ['entry' => 0, 'grades' => array_fill_keys($grade_order, 0)]
      ]
    ];
  }

  // Stream
  if (!isset($class_data[$cid]['streams'][$sid])) {
    $class_data[$cid]['streams'][$sid] = [
      'stream_name' => $r['stream_name'],
      'entry'       => 0,
      'grades'      => array_fill_keys($grade_order, 0)
    ];
  }
  $class_data[$cid]['streams'][$sid]['entry']++;
  $class_data[$cid]['streams'][$sid]['grades'][$grade]++;

  // Class total
  $class_data[$cid]['totals']['entry']++;
  $class_data[$cid]['totals']['grades'][$grade]++;

  // Gender
  $gender_key = ($r['gender'] === 'Male') ? 'Male' : 'Female';
  $class_data[$cid]['gender'][$gender_key]['entry']++;
  $class_data[$cid]['gender'][$gender_key]['grades'][$grade]++;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>School Mean Grade Summary</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

  <style>
    body {
      font-family: Arial, Helvetica, sans-serif;
      font-size: 12px;
      background: #f8f9fa;
      margin: 0;
    }

    .examreport_container {
      max-width: 1400px;
      margin: 10px auto;
      background: #fff;
      padding: 15px 20px;
      border-radius: 6px;
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
      page-break-after: always;
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
      margin-bottom: 12px;
      padding-bottom: 8px;
    }

    .examreport_header img {
      max-height: 90px;
      object-fit: contain;
      margin-bottom: 6px;
    }

    table {
      font-size: 11px;
      border-collapse: collapse;
      width: 100%;
      margin-bottom: 16px;
    }

    th {
      background: #e9f2ff !important;
      color: #0d47a1;
      text-align: center;
      padding: 6px 8px;
      border: 1px solid #dee2e6;
    }

    td {
      padding: 5px 7px;
      border: 1px solid #dee2e6;
      text-align: center;
      vertical-align: middle;
    }

    .totals {
      background: #f1f3f5;
      font-weight: bold;
    }

    .gender-table {
      margin-top: 20px;
    }

    .examreport_remarks {
      border: 1px solid #0d6efd;
      padding: 8px 12px;
      margin: 12px 0;
      background: #f1f8ff;
      border-left: 5px solid #0d6efd;
      border-radius: 4px;
      font-size: 12px;
    }

    .examreport_footer {
      margin-top: 20px;
      padding-top: 12px;
      border-top: 2px solid #0d6efd;
      font-size: 12px;
    }

    .no-print {
      position: sticky;
      top: 0;
      z-index: 999;
      background: #1a1f71;
      color: white;
      padding: 10px 20px;
      border-radius: 0 0 8px 8px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 15px;
      box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
    }

    .no-print img {
      height: 50px;
      border-radius: 5px;
    }

    .no-print button {
      background: #007bff;
      border: none;
      padding: 6px 14px;
      border-radius: 5px;
      color: white;
      font-weight: 500;
      margin-left: 8px;
    }

    .no-print button.btn-danger {
      background: #ff6b6b;
    }

    .no-print button.btn-success {
      background: #20c997;
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
      .no-print {
        display: none !important;
      }

      body {
        background: white;
        margin: 0;
      }

      .examreport_container {
        box-shadow: none;
        border-radius: 0;
        max-width: 100%;
        padding: 10mm;
        margin: 0;
      }

      @page {
        size: A4 landscape;
        margin: 12mm;
      }
    }
  </style>
</head>

<body>

  <!-- Sticky Header (non-printing) -->
  <div class="no-print">
    <div style="display:flex; align-items:center; gap:12px;">
      <img src="<?= htmlspecialchars($school_logo) ?>" alt="Logo">
      <span style="font-size:18px; font-weight:bold;"><?= htmlspecialchars($school['name']) ?></span>
    </div>
    <div style="flex:1; text-align:center; font-weight:bold; font-size:17px;">
      MEAN GRADE SUMMARY — <?= htmlspecialchars($exam['exam_name']) ?> (<?= htmlspecialchars($exam['term']) ?> • <?= $year ?>)
    </div>
    <div>
      <button class="btn btn-danger" onclick="history.back()">Back</button>
      <button onclick="window.print()">Print</button>
      <button class="btn btn-success" onclick="downloadReport()">Download PDF</button>
    </div>
  </div>

  <!-- Modal for PDF generation -->
  <div class="modal fade" id="downloadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-body text-center py-5">
          <div class="loader-spinner"></div>
          <p class="mt-3">Generating PDF, please wait...</p>
        </div>
      </div>
    </div>
  </div>

  <div class="examreport_container">
    <div class="examreport_header">
      <img src="<?= htmlspecialchars($school_logo) ?>" alt="School Logo">
      <h2 class="examreport_h2"><?= htmlspecialchars($school['name']) ?></h2>
      <p class="mb-1 fw-bold">MEAN GRADE SUMMARY</p>
      <p class="mb-0"><?= htmlspecialchars($exam['exam_name']) ?> • <?= htmlspecialchars($exam['term']) ?> • <?= $year ?></p>
    </div>

    <?php foreach ($class_data as $cid => $data): ?>
      <h5 class="mt-4 mb-2 text-primary fw-bold"><?= htmlspecialchars($data['form_name']) ?> Results</h5>

      <table class="table table-bordered table-sm">
        <thead>
          <tr>
            <th>Stream</th>
            <th>Entry</th>
            <?php foreach ($grade_order as $g): ?>
              <th><?= $g ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($data['streams'] as $s): ?>
            <tr>
              <td class="text-start"><?= htmlspecialchars($s['stream_name']) ?></td>
              <td><?= $s['entry'] ?></td>
              <?php foreach ($grade_order as $g): ?>
                <td><?= $s['grades'][$g] ?></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
          <tr class="totals">
            <td class="text-start">TOTAL</td>
            <td><?= $data['totals']['entry'] ?></td>
            <?php foreach ($grade_order as $g): ?>
              <td><?= $data['totals']['grades'][$g] ?></td>
            <?php endforeach; ?>
          </tr>
        </tbody>
      </table>

      <!-- Gender Analysis -->
      <div class="examreport_remarks">
        <strong>Gender Analysis — <?= htmlspecialchars($data['form_name']) ?></strong>
      </div>
      <table class="table table-bordered table-sm gender-table">
        <thead>
          <tr>
            <th>Gender</th>
            <th>Entry</th>
            <?php foreach ($grade_order as $g): ?>
              <th><?= $g ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach (['Male', 'Female'] as $gender): ?>
            <tr>
              <td class="text-start"><?= $gender ?></td>
              <td><?= $data['gender'][$gender]['entry'] ?></td>
              <?php foreach ($grade_order as $g): ?>
                <td><?= $data['gender'][$gender]['grades'][$g] ?></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endforeach; ?>

    <!-- Signature area -->
    <div class="examreport_footer">
      <table class="table table-borderless table-sm w-100">
        <tr>
          <td><strong>Prepared by:</strong> _______________________________ <strong>Date:</strong> _______________</td>
        </tr>
        <tr>
          <td><strong>Checked by Deputy Principal (Academics):</strong> _______________________________ <strong>Date:</strong> _______________</td>
        </tr>
        <tr>
          <td><strong>Approved by Principal:</strong> _______________________________ <strong>Date:</strong> _______________</td>
        </tr>
      </table>
    </div>
  </div>

  <script>
    function downloadReport() {
      const modal = new bootstrap.Modal(document.getElementById('downloadModal'), {
        backdrop: 'static',
        keyboard: false
      });
      modal.show();

      const opt = {
        margin: 12,
        filename: `School_Mean_Grade_Summary_<?= htmlspecialchars($exam['exam_name'] ?? 'Exam') ?>_<?= htmlspecialchars($exam['term'] ?? 'Term') ?>_<?= $year ?>.pdf`,
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
          orientation: 'landscape'
        }
      };

      html2pdf()
        .set(opt)
        .from(document.querySelector('.examreport_container'))
        .save()
        .then(() => modal.hide())
        .catch(err => {
          console.error(err);
          modal.hide();
          alert('PDF generation failed. Please try again.');
        });
    }
  </script>

</body>

</html>
<?php ob_end_flush(); ?>