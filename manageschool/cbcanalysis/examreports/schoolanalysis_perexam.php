<?php
// reports/examreports/schoolanalysis_perexam.php
session_start();
ob_start();
require __DIR__ . '../../../../connection/db.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['school_id']) || !isset($_SESSION['role_id'])) {
  header("Location: ../../login.php");
  exit;
}

// Check permissions
$role_id = $_SESSION['role_id'];
$school_id = $_SESSION['school_id'];
$stmt = $conn->prepare("
    SELECT p.name 
    FROM role_permissions rp 
    JOIN permissions p ON rp.permission_id = p.permission_id 
    WHERE rp.role_id = ? AND rp.school_id = ? AND p.name = 'view_exam_aggregates'
");
$stmt->bind_param("ii", $role_id, $school_id);
$stmt->execute();
$has_permission = $stmt->get_result()->num_rows > 0;
$stmt->close();

if (!$has_permission) {
  header("Location: ../../unauthorized.php");
  exit;
}

// Initialize variables
$error_message = '';
$school = ['name' => 'N/A', 'logo' => null];
$exam_name = '';
$term = '';
$year = date('Y');
$class_data = [];

// Get form inputs
$year = isset($_POST['year']) ? (int)$_POST['year'] : date('Y');
$exam_name = isset($_POST['exam_name']) ? trim($_POST['exam_name']) : '';
$term = isset($_POST['term']) ? trim($_POST['term']) : '';
$class_ids = isset($_POST['class_ids']) && is_array($_POST['class_ids']) ? array_map('intval', $_POST['class_ids']) : [];

// Validate inputs
if (!$year || !$exam_name || !$term || empty($class_ids)) {
  $error_message = "Error: Year, exam name, term, and at least one class are required.";
}

// Fetch school details
if (!$error_message) {
  $stmt = $conn->prepare("SELECT name, logo FROM schools WHERE school_id = ?");
  $stmt->bind_param("i", $school_id);
  $stmt->execute();
  $school = $stmt->get_result()->fetch_assoc() ?? ['name' => 'N/A', 'logo' => null];
  $stmt->close();
}

// Process school logo path
$school_logo = $school['logo'] ?? '';
if (empty($school_logo)) {
  $school_logo = 'https://academics.sifms.co.ke/manageschool/logos/school-logo.png';
} elseif (strpos($school_logo, 'http') !== 0) {
  $school_logo = 'https://academics.sifms.co.ke/manageschool/logos/' . basename($school_logo);
}

// Fetch exams and validate classes
$exams = [];
if (!$error_message) {
  $in_clause = implode(',', array_fill(0, count($class_ids), '?'));
  $stmt = $conn->prepare("
        SELECT exam_id, exam_name, class_id, term, YEAR(created_at) AS year, grading_system_id, min_subjects
        FROM exams 
        WHERE school_id = ? AND exam_name = ? AND term = ? AND YEAR(created_at) = ? 
        AND class_id IN ($in_clause) AND status = 'closed'
    ");
  $params = array_merge([$school_id, $exam_name, $term, $year], $class_ids);
  $types = 'isss' . str_repeat('i', count($class_ids));
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $exams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();

  if (empty($exams)) {
    $error_message = "Error: No closed exams found for the selected exam name, term, year, and classes.";
  }
}

// ========================
//     CBC GRADING LOGIC
// ========================
function getCBCGradeAndPoints($value, $use_points = false)
{
  if (!is_numeric($value) || $value < 0) {
    return ['grade' => '-', 'points' => 0];
  }

  if ($use_points) {
    // Mean points to CBC level
    if ($value >= 7.0)  return ['grade' => 'EE1', 'points' => 8];
    if ($value >= 6.0)  return ['grade' => 'EE2', 'points' => 7];
    if ($value >= 5.0)  return ['grade' => 'ME1', 'points' => 6];
    if ($value >= 4.0)  return ['grade' => 'ME2', 'points' => 5];
    if ($value >= 3.0)  return ['grade' => 'AE1', 'points' => 4];
    if ($value >= 2.0)  return ['grade' => 'AE2', 'points' => 3];
    if ($value >= 1.0)  return ['grade' => 'BE1', 'points' => 2];
    return ['grade' => 'BE2', 'points' => 1];
  }

  // Individual subject score → CBC level
  if ($value >= 90) return ['grade' => 'EE1', 'points' => 8];
  if ($value >= 75) return ['grade' => 'EE2', 'points' => 7];
  if ($value >= 58) return ['grade' => 'ME1', 'points' => 6];
  if ($value >= 41) return ['grade' => 'ME2', 'points' => 5];
  if ($value >= 31) return ['grade' => 'AE1', 'points' => 4];
  if ($value >= 21) return ['grade' => 'AE2', 'points' => 3];
  if ($value >= 11) return ['grade' => 'BE1', 'points' => 2];
  return ['grade' => 'BE2', 'points' => 1];
}

// CBC grade order for tables
$cbc_grades = ['EE1', 'EE2', 'ME1', 'ME2', 'AE1', 'AE2', 'BE1', 'BE2', 'X', 'Y'];

// Process grade analysis for each class
if (!$error_message) {
  foreach ($class_ids as $class_id) {
    $grade_data = [
      'form_name' => 'N/A',
      'streams' => [],
      'gender_analysis' => [
        'Male' => [
          'entry' => 0,
          'grades' => array_fill_keys($cbc_grades, 0),
          'mean_score' => 0,
          'grade' => ''
        ],
        'Female' => [
          'entry' => 0,
          'grades' => array_fill_keys($cbc_grades, 0),
          'mean_score' => 0,
          'grade' => ''
        ]
      ],
      'totals' => [
        'entry' => 0,
        'grades' => array_fill_keys($cbc_grades, 0),
        'mean_score' => 0,
        'grade' => ''
      ]
    ];

    // Get class/form name
    $stmt = $conn->prepare("SELECT form_name FROM classes WHERE class_id = ? AND school_id = ?");
    $stmt->bind_param("ii", $class_id, $school_id);
    $stmt->execute();
    $class = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $grade_data['form_name'] = $class['form_name'] ?? 'Unknown Form';

    // Find matching exam
    $exam = null;
    foreach ($exams as $e) {
      if ($e['class_id'] == $class_id) {
        $exam = $e;
        break;
      }
    }
    if (!$exam) continue;

    // Get streams
    $stmt = $conn->prepare("SELECT stream_id, stream_name FROM streams WHERE school_id = ? AND class_id = ?");
    $stmt->bind_param("ii", $school_id, $class_id);
    $stmt->execute();
    $streams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Get subjects
    $stmt = $conn->prepare("
            SELECT es.subject_id, es.use_papers, cs.type
            FROM exam_subjects es
            JOIN class_subjects cs ON es.subject_id = cs.subject_id AND cs.class_id = ?
            WHERE es.exam_id = ?
        ");
    $stmt->bind_param("ii", $class_id, $exam['exam_id']);
    $stmt->execute();
    $subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $subject_papers = [];
    foreach ($subjects as $subject) {
      if ($subject['use_papers']) {
        $stmt = $conn->prepare("
                    SELECT esp.paper_id, sp.paper_name, esp.max_score, sp.contribution_percentage
                    FROM exam_subjects_papers esp
                    JOIN subject_papers sp ON esp.paper_id = sp.paper_id
                    WHERE esp.exam_id = ? AND esp.subject_id = ?
                ");
        $stmt->bind_param("ii", $exam['exam_id'], $subject['subject_id']);
        $stmt->execute();
        $papers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Normalize percentages
        $total_perc = array_sum(array_column($papers, 'contribution_percentage'));
        if ($total_perc == 0 || count($papers) == 0) {
          $eq = count($papers) > 0 ? 100 / count($papers) : 100;
          foreach ($papers as &$p) $p['contribution_percentage'] = $eq;
        } elseif ($total_perc != 100) {
          $scale = 100 / $total_perc;
          foreach ($papers as &$p) $p['contribution_percentage'] *= $scale;
        }
        $subject_papers[$subject['subject_id']] = $papers;
      } else {
        $subject_papers[$subject['subject_id']] = [['paper_id' => null, 'max_score' => 100, 'contribution_percentage' => 100]];
      }
    }

    $total_class_points = 0;
    $total_class_students = 0;
    $male_points = $female_points = 0;
    $male_students = $female_students = 0;

    foreach ($streams as $stream) {
      $stream_id = $stream['stream_id'];
      $stream_data = [
        'stream_name' => $stream['stream_name'],
        'entry' => 0,
        'grades' => array_fill_keys($cbc_grades, 0),
        'mean_score' => 0,
        'grade' => ''
      ];

      $stmt = $conn->prepare("
                SELECT student_id, admission_no, full_name, gender
                FROM students
                WHERE stream_id = ? AND class_id = ? AND school_id = ? AND deleted_at IS NULL
            ");
      $stmt->bind_param("iii", $stream_id, $class_id, $school_id);
      $stmt->execute();
      $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
      $stmt->close();

      $stream_data['entry'] = count($students);
      if ($stream_data['entry'] == 0) {
        $grade_data['streams'][$stream_id] = $stream_data;
        continue;
      }

      $stmt = $conn->prepare("
                SELECT r.student_id, r.subject_id, r.paper_id, r.score
                FROM results r
                WHERE r.exam_id = ? AND r.student_id IN (
                    SELECT student_id FROM students WHERE stream_id = ? AND class_id = ? AND school_id = ? AND deleted_at IS NULL
                ) AND r.status = 'confirmed' AND r.score IS NOT NULL AND r.deleted_at IS NULL
            ");
      $stmt->bind_param("iiii", $exam['exam_id'], $stream_id, $class_id, $school_id);
      $stmt->execute();
      $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
      $stmt->close();

      $student_results = [];
      foreach ($results as $r) {
        $sid = $r['student_id'];
        $subj = $r['subject_id'];
        $paper = $r['paper_id'];
        $student_results[$sid][$subj][] = ['paper_id' => $paper, 'score' => $r['score']];
      }

      $min_subjects = $exam['min_subjects'] ?? 7;
      $valid_students = 0;
      $stream_total_points = 0;

      foreach ($students as $student) {
        $sid = $student['student_id'];
        $gender = $student['gender'] ?: 'Unknown';

        $processed = [];
        foreach ($subjects as $subj) {
          $subj_id = $subj['subject_id'];
          $papers = $subject_papers[$subj_id] ?? [];
          $subj_score = 0;

          if ($subj['use_papers']) {
            foreach ($papers as $p) {
              $pid = $p['paper_id'];
              $max = $p['max_score'] ?? 100;
              $contrib = $p['contribution_percentage'] ?? 100;
              $paper_score = null;

              if (isset($student_results[$sid][$subj_id])) {
                foreach ($student_results[$sid][$subj_id] as $res) {
                  if ($res['paper_id'] == $pid) {
                    $paper_score = $res['score'];
                    break;
                  }
                }
              }
              if ($paper_score !== null && $max > 0) {
                $subj_score += ($paper_score / $max) * ($contrib / 100) * 100;
              }
            }
          } else {
            if (isset($student_results[$sid][$subj_id])) {
              foreach ($student_results[$sid][$subj_id] as $res) {
                if ($res['paper_id'] === null) {
                  $subj_score = $res['score'] ?? 0;
                  break;
                }
              }
            }
          }

          $grade_info = getCBCGradeAndPoints($subj_score);
          $processed[] = [
            'subject_id' => $subj_id,
            'type' => $subj['type'],
            'score' => $subj_score,
            'points' => $grade_info['points']
          ];
        }

        $selected = array_filter($processed, fn($s) => $s['score'] > 0);

        if (!empty($selected) && $subjects[0]['use_papers'] ?? false) {
          $compulsory = array_filter($selected, fn($s) => $s['type'] === 'compulsory');
          $elective = array_filter($selected, fn($s) => $s['type'] === 'elective');

          usort($elective, fn($a, $b) => $b['points'] <=> $a['points']);
          $top_electives = array_slice($elective, 0, 2);
          $compulsory = array_slice($compulsory, 0, 5);

          $selected = array_merge($compulsory, $top_electives);
        }

        $total_points = array_sum(array_column($selected, 'points'));
        $count = count($selected);

        if ($count < $min_subjects) {
          $stream_data['grades']['X']++;
          if ($gender !== 'Unknown') $grade_data['gender_analysis'][$gender]['grades']['X']++;
          continue;
        }

        $mean_points = $total_points / $min_subjects;
        $overall = getCBCGradeAndPoints($mean_points, true);

        $stream_data['grades'][$overall['grade']]++;
        if ($gender !== 'Unknown') {
          $grade_data['gender_analysis'][$gender]['grades'][$overall['grade']]++;
        }

        $stream_total_points += $mean_points;
        $valid_students++;
        $total_class_points += $mean_points;
        $total_class_students++;

        if ($gender === 'Male') {
          $male_points += $mean_points;
          $male_students++;
        } elseif ($gender === 'Female') {
          $female_points += $mean_points;
          $female_students++;
        }
      }

      if ($valid_students > 0) {
        $stream_data['mean_score'] = round($stream_total_points / $valid_students, 2);
        $grade = getCBCGradeAndPoints($stream_data['mean_score'], true);
        $stream_data['grade'] = $grade['grade'];
      }

      $grade_data['streams'][$stream_id] = $stream_data;
    }

    // Calculate totals
    $grade_data['totals']['entry'] = array_sum(array_column($grade_data['streams'], 'entry'));

    foreach ($cbc_grades as $g) {
      $grade_data['totals']['grades'][$g] = 0;
      foreach ($grade_data['streams'] as $s) {
        $grade_data['totals']['grades'][$g] += $s['grades'][$g] ?? 0;
      }
    }

    if ($total_class_students > 0) {
      $grade_data['totals']['mean_score'] = round($total_class_points / $total_class_students, 2);
      $tot_grade = getCBCGradeAndPoints($grade_data['totals']['mean_score'], true);
      $grade_data['totals']['grade'] = $tot_grade['grade'];
    }

    // Gender totals
    if ($male_students > 0) {
      $grade_data['gender_analysis']['Male']['entry'] = $male_students;
      $grade_data['gender_analysis']['Male']['mean_score'] = round($male_points / $male_students, 2);
      $m_grade = getCBCGradeAndPoints($grade_data['gender_analysis']['Male']['mean_score'], true);
      $grade_data['gender_analysis']['Male']['grade'] = $m_grade['grade'];
    }

    if ($female_students > 0) {
      $grade_data['gender_analysis']['Female']['entry'] = $female_students;
      $grade_data['gender_analysis']['Female']['mean_score'] = round($female_points / $female_students, 2);
      $f_grade = getCBCGradeAndPoints($grade_data['gender_analysis']['Female']['mean_score'], true);
      $grade_data['gender_analysis']['Female']['grade'] = $f_grade['grade'];
    }

    $class_data[$class_id] = $grade_data;
  }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title>Mean Grade Summary - CBC</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
  <style>
    body {
      font-family: Arial, Helvetica, sans-serif;
      font-size: 12px;
    }

    .schoolanalysis_container {
      max-width: 1400px;
      margin: 0 auto;
      background: #fff;
      padding: 15px;
    }

    .schoolanalysis_header {
      text-align: center;
      border-bottom: 2px solid #0d6efd;
      padding-bottom: 8px;
      margin-bottom: 10px;
    }

    .schoolanalysis_title {
      background: #f1f8ff;
      border: 1px solid #0d6efd;
      border-left: 5px solid #0d6efd;
      padding: 8px;
      font-weight: bold;
      text-align: center;
    }

    table.schoolanalysis_table th {
      background: #e9f2ff;
      color: #0d47a1;
      text-align: center;
    }

    table.schoolanalysis_table td,
    table.schoolanalysis_table th {
      padding: 4px 6px;
      border: 1px solid #dee2e6;
      font-size: 11px;
    }

    .no-print {
      background: #1a1f71;
      color: white;
      padding: 10px 20px;
      border-radius: 8px;
      margin-bottom: 15px;
      position: sticky;
      top: 0;
      z-index: 9999;
      display: flex;
      justify-content: space-between;
      align-items: center;
      box-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
    }

    @media print {
      .no-print {
        display: none !important;
      }

      body {
        margin: 0;
        font-size: 11px;
      }

      .schoolanalysis_container {
        padding: 10mm;
        margin: 0;
        box-shadow: none;
      }
    }

    @page {
      size: A4 landscape;
      margin: 10mm;
    }
  </style>
</head>

<body>

  <!-- Sticky Header (not printed) -->
  <div class="no-print">
    <div style="display:flex; align-items:center; gap:12px;">
      <img src="<?= htmlspecialchars($school_logo) ?>" alt="Logo" style="height:50px; border-radius:5px;">
      <span style="font-weight:bold; font-size:18px;"><?= htmlspecialchars($school['name'] ?? 'KEILA HIGH SCHOOL') ?></span>
    </div>
    <div style="flex:1; text-align:center; font-weight:bold; font-size:16px;">
      Mean Grade Summary: <?= htmlspecialchars($exam_name) ?> (<?= htmlspecialchars($term) ?> - <?= $year ?>)
    </div>
    <div style="display:flex; gap:10px;">
      <button class="btn btn-danger btn-sm" onclick="history.back()">
        <i class="bi bi-arrow-left"></i> Back
      </button>
      <button class="btn btn-primary btn-sm" onclick="window.print()">
        <i class="bi bi-printer"></i> Print
      </button>
      <button class="btn btn-success btn-sm" onclick="downloadReport()">
        <i class="bi bi-download"></i> Download PDF
      </button>
    </div>
  </div>

  <div class="schoolanalysis_container">
    <!-- Main Header -->
    <div class="schoolanalysis_header">
      <img src="<?= htmlspecialchars($school_logo) ?>" alt="Logo" style="width:100px;height:100px;object-fit:contain;" /><br>
      <h2 style="color:#0d6efd;"><?= htmlspecialchars($school['name'] ?? 'KEILA HIGH SCHOOL') ?></h2>
      <p class="fw-bold mb-0">MEAN GRADE SUMMARY – CBC</p>
      <p class="mb-0"><?= htmlspecialchars("$exam_name • $term • Year $year") ?></p>
    </div>

    <?php if ($error_message): ?>
      <div class="alert alert-danger text-center p-3 my-4"><?= htmlspecialchars($error_message) ?></div>
    <?php elseif (empty($class_data)): ?>
      <div class="alert alert-warning text-center p-3 my-4">No data available for selected exam/classes</div>
    <?php else: ?>
      <?php foreach ($class_data as $cid => $data): ?>
        <div class="class-section mb-5" style="page-break-inside:avoid;">
          <div class="schoolanalysis_title mb-3">
            <?= htmlspecialchars($data['form_name']) ?> RESULTS
          </div>

          <div class="table-responsive">
            <table class="table table-bordered table-sm text-center schoolanalysis_table">
              <thead>
                <tr>
                  <th>Stream</th>
                  <th>Entry</th>
                  <?php foreach ($cbc_grades as $g): ?>
                    <th><?= $g ?></th>
                  <?php endforeach; ?>
                  <th>Grade</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($data['streams'] as $stream): ?>
                  <tr>
                    <td><?= htmlspecialchars($stream['stream_name']) ?></td>
                    <td><?= $stream['entry'] ?></td>
                    <?php foreach ($cbc_grades as $g): ?>
                      <td><?= $stream['grades'][$g] ?? 0 ?></td>
                    <?php endforeach; ?>
                    <td><?= $stream['grade'] ?: '-' ?></td>
                  </tr>
                <?php endforeach; ?>

                <tr class="fw-bold" style="background:#e8ecef;">
                  <td>TOTAL</td>
                  <td><?= $data['totals']['entry'] ?></td>
                  <?php foreach ($cbc_grades as $g): ?>
                    <td><?= $data['totals']['grades'][$g] ?? 0 ?></td>
                  <?php endforeach; ?>
                  <td><?= $data['totals']['grade'] ?: '-' ?></td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Gender Analysis -->
          <div class="schoolanalysis_title mt-4 mb-3">
            Gender Analysis - <?= htmlspecialchars($data['form_name']) ?>
          </div>

          <div class="table-responsive">
            <table class="table table-bordered table-sm text-center schoolanalysis_table">
              <thead>
                <tr>
                  <th>Gender</th>
                  <th>Entry</th>
                  <?php foreach ($cbc_grades as $g): ?>
                    <th><?= $g ?></th>
                  <?php endforeach; ?>
                  <th>Grade</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach (['Male', 'Female'] as $gender): ?>
                  <tr>
                    <td><?= $gender ?></td>
                    <td><?= $data['gender_analysis'][$gender]['entry'] ?></td>
                    <?php foreach ($cbc_grades as $g): ?>
                      <td><?= $data['gender_analysis'][$gender]['grades'][$g] ?? 0 ?></td>
                    <?php endforeach; ?>
                    <td><?= $data['gender_analysis'][$gender]['grade'] ?: '-' ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

    <!-- Signatures -->
    <div class="schoolanalysis_footer mt-5 pt-4" style="border-top:2px solid #0d6efd;">
      <div style="display:flex; justify-content:space-between; font-size:12px;">
        <div style="width:45%; text-align:center;">
          <p><b>PREPARED BY</b></p>
          <p style="border-top:1px solid #666; margin-top:40px;">DEPUTY PRINCIPAL (ACADEMICS)</p>
        </div>
        <div style="width:45%; text-align:center;">
          <p><b>APPROVED BY</b></p>
          <p style="border-top:1px solid #666; margin-top:40px;">PRINCIPAL</p>
        </div>
      </div>
    </div>
  </div>

  <script>
    function downloadReport() {
      const element = document.querySelector('.schoolanalysis_container');
      const opt = {
        margin: 10,
        filename: `Mean_Grade_Summary_CBC_<?= addslashes(str_replace(" ", "_", $exam_name)) ?>_<?= addslashes($term) ?>_<?= $year ?>.pdf`,
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
      html2pdf().set(opt).from(element).save();
    }
  </script>

</body>

</html>

<?php ob_end_flush(); ?>