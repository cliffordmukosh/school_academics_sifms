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
$year = isset($_POST['year']) ? $_POST['year'] : '2025';
$class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
$term = isset($_POST['term']) ? $_POST['term'] : '';
$exam_id = isset($_POST['exam_id']) ? (int)$_POST['exam_id'] : 0;
$stream_id = isset($_POST['stream_id']) && $_POST['stream_id'] !== '' ? (int)$_POST['stream_id'] : null;

// Fetch school details
$stmt = $conn->prepare("SELECT name, logo FROM schools WHERE school_id = ?");
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
$grading_system_id = 1;
if ($exam_id) {
    $stmt = $conn->prepare("SELECT exam_name, grading_system_id FROM exams WHERE exam_id = ? AND school_id = ?");
    $stmt->bind_param("ii", $exam_id, $school_id);
    $stmt->execute();
    $exam = $stmt->get_result()->fetch_assoc();
    $exam_name = $exam['exam_name'] ?? '';
    $grading_system_id = $exam['grading_system_id'] ?? 1;
    $stmt->close();
}

// Fetch all streams for the class (for comparison)
$streams = [];
if (!$stream_id) {
    $stmt = $conn->prepare("SELECT stream_id, stream_name FROM streams WHERE class_id = ? AND school_id = ?");
    $stmt->bind_param("ii", $class_id, $school_id);
    $stmt->execute();
    $streams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Function to get grade and points for a score
function getGradeAndPoints($conn, $score, $grading_system_id) {
    $stmt = $conn->prepare("
        SELECT grade, points
        FROM grading_rules
        WHERE grading_system_id = ? AND ? >= min_score AND ? <= max_score
        ORDER BY ABS(? - (min_score + max_score)/2) ASC LIMIT 1
    ");
    $stmt->bind_param("iddd", $grading_system_id, $score, $score, $score);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$result && $score > 0) {
        $fallback = $conn->prepare("
            SELECT grade, points
            FROM grading_rules
            WHERE grading_system_id = ? AND grade NOT IN ('X', 'Y')
            ORDER BY min_score ASC LIMIT 1
        ");
        $fallback->bind_param("i", $grading_system_id);
        $fallback->execute();
        $fallback_result = $fallback->get_result()->fetch_assoc();
        $fallback->close();
        return $fallback_result ? ['grade' => $fallback_result['grade'], 'points' => $fallback_result['points']] : ['grade' => 'E', 'points' => 1];
    }
    return [
        'grade' => $result ? $result['grade'] : ($score > 0 ? 'E' : 'N/A'),
        'points' => $result ? $result['points'] : ($score > 0 ? 1 : 0)
    ];
}

// Fetch subjects for the exam
$subjects = [];
$stmt = $conn->prepare("
    SELECT es.subject_id, s.name, es.use_papers
    FROM exam_subjects es
    JOIN subjects s ON es.subject_id = s.subject_id
    WHERE es.exam_id = ?
");
$stmt->bind_param("i", $exam_id);
$stmt->execute();
$subjects_result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
foreach ($subjects_result as $subj) {
    $subjects[$subj['subject_id']] = [
        'name' => $subj['name'],
        'use_papers' => $subj['use_papers']
    ];
}

// Fetch subject papers
$subject_papers = [];
foreach ($subjects as $subject_id => $subject) {
    if ($subject['use_papers']) {
        $stmt = $conn->prepare("
            SELECT esp.paper_id, esp.max_score, sp.contribution_percentage
            FROM exam_subjects_papers esp
            JOIN subject_papers sp ON esp.paper_id = sp.paper_id
            WHERE esp.exam_id = ? AND esp.subject_id = ?
        ");
        $stmt->bind_param("ii", $exam_id, $subject_id);
        $stmt->execute();
        $subject_papers[$subject_id] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } else {
        $subject_papers[$subject_id] = [['paper_id' => null, 'max_score' => 100, 'contribution_percentage' => 100]];
    }
}

// Fetch results
$results = [];
$student_counts = [];
if ($exam_id) {
    $query = "
        SELECT r.student_id, r.subject_id, r.paper_id, r.score, s.stream_id
        FROM results r
        JOIN students s ON r.student_id = s.student_id
        WHERE r.exam_id = ? AND r.status = 'confirmed' AND r.score IS NOT NULL
        AND s.class_id = ? AND s.school_id = ? AND s.deleted_at IS NULL
    ";
    if ($stream_id) {
        $query .= " AND s.stream_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("iiii", $exam_id, $class_id, $school_id, $stream_id);
    } else {
        $stmt = $conn->prepare($query);
        $stmt->bind_param("iii", $exam_id, $class_id, $school_id);
    }
    $stmt->execute();
    $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Count students per stream and total
    $student_ids = [];
    foreach ($results as $result) {
        $student_id = $result['student_id'];
        $stream_id_result = $result['stream_id'];
        if (!isset($student_ids[$student_id])) {
            $student_ids[$student_id] = $stream_id_result;
            if ($stream_id) {
                $student_counts[$stream_id] = ($student_counts[$stream_id] ?? 0) + 1;
            } else {
                $student_counts[$stream_id_result] = ($student_counts[$stream_id_result] ?? 0) + 1;
            }
        }
    }
    $total_student_count = count($student_ids);
}

// Calculate grade distribution and mean points per subject
$grade_distribution = [];
$mean_points = [];
$grades = ['A', 'A-', 'B+', 'B', 'B-', 'C+', 'C', 'C-', 'D+', 'D', 'D-', 'E'];

foreach ($subjects as $subject_id => $subject) {
    // Initialize grade distribution for each stream and total
    if ($stream_id) {
        $grade_distribution[$subject_id][$stream_id] = array_fill_keys($grades, 0);
        $mean_points[$subject_id][$stream_id] = ['sum' => 0, 'count' => 0];
    } else {
        foreach ($streams as $stream) {
            $grade_distribution[$subject_id][$stream['stream_id']] = array_fill_keys($grades, 0);
            $mean_points[$subject_id][$stream['stream_id']] = ['sum' => 0, 'count' => 0];
        }
        $grade_distribution[$subject_id]['total'] = array_fill_keys($grades, 0);
        $mean_points[$subject_id]['total'] = ['sum' => 0, 'count' => 0];
    }

    // Group results by student
    $student_scores = [];
    foreach ($results as $result) {
        if ($result['subject_id'] == $subject_id) {
            $student_id = $result['student_id'];
            if (!isset($student_scores[$student_id])) {
                $student_scores[$student_id] = ['scores' => [], 'stream_id' => $result['stream_id']];
            }
            $student_scores[$student_id]['scores'][] = $result;
        }
    }

    // Calculate subject score and points per student
    foreach ($student_scores as $student_id => $data) {
        $scores = $data['scores'];
        $student_stream_id = $data['stream_id'];
        $subject_score = 0;
        if ($subject['use_papers']) {
            foreach ($subject_papers[$subject_id] as $paper) {
                $paper_id = $paper['paper_id'];
                $max_score = $paper['max_score'] ?? 100;
                $contribution = $paper['contribution_percentage'] ?? 100;
                $paper_score = null;
                foreach ($scores as $score) {
                    if ($score['paper_id'] == $paper_id) {
                        $paper_score = $score['score'];
                        break;
                    }
                }
                if ($paper_score !== null && $max_score > 0) {
                    $subject_score += ($paper_score / $max_score) * ($contribution / 100) * 100;
                }
            }
        } else {
            foreach ($scores as $score) {
                if ($score['paper_id'] === null) {
                    $subject_score = $score['score'] ?? 0;
                    break;
                }
            }
        }
        if ($subject_score > 0) {
            $grade_info = getGradeAndPoints($conn, $subject_score, $grading_system_id);
            $grade = $grade_info['grade'];
            $points = $grade_info['points'];
            if (in_array($grade, $grades)) {
                if ($stream_id) {
                    $grade_distribution[$subject_id][$stream_id][$grade]++;
                    $mean_points[$subject_id][$stream_id]['sum'] += $points;
                    $mean_points[$subject_id][$stream_id]['count']++;
                } else {
                    $grade_distribution[$subject_id][$student_stream_id][$grade]++;
                    $grade_distribution[$subject_id]['total'][$grade]++;
                    $mean_points[$subject_id][$student_stream_id]['sum'] += $points;
                    $mean_points[$subject_id][$student_stream_id]['count']++;
                    $mean_points[$subject_id]['total']['sum'] += $points;
                    $mean_points[$subject_id]['total']['count']++;
                }
            }
        }
    }
}
?>
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
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
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
    .analysisreport_container {
      box-shadow: none;
      border-radius: 0;
      max-width: 100%;
      padding: 10mm;
      margin: 0;
    }
    .analysisreport_footer {
      position: relative;
      bottom: 0;
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
    Grade Analysis: <?php echo htmlspecialchars($exam_name); ?> (<?php echo htmlspecialchars($full_term); ?> - <?php echo $year; ?>)
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

<div class="analysisreport_container">
  <!-- Header -->
  <div class="analysisreport_header">
    <img src="<?php echo htmlspecialchars($school_logo); ?>" alt="Logo" /><br />
    <h2 class="analysisreport_h2"><?php echo htmlspecialchars($school['name'] ?? 'KEILA HIGH SCHOOL'); ?></h2>
    <p class="mb-0 fw-bold">GRADE ANALYSIS</p>
    <p class="mb-0"><?php echo htmlspecialchars($full_term . ' Year ' . $year); ?></p>
    <p class="mb-0"><strong>STREAM:</strong> <?php echo htmlspecialchars($stream_name); ?></p>
    <p class="mb-0"><strong>EXAM NAME:</strong> <?php echo htmlspecialchars($exam_name); ?></p>
  </div>

  <!-- Report Title -->
  <div class="analysisreport_title">
    SUBJECT GRADE ANALYSIS (Examination Analysis)
  </div>

  <!-- Table -->
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
          $display_streams = $stream_id ? [[
              'stream_id' => $stream_id,
              'stream_name' => $stream_name
          ]] : array_merge($streams, [['stream_id' => 'total', 'stream_name' => 'Total']]);
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
              <td><?php echo $stream['stream_id'] === 'total' ? $total_student_count : ($student_counts[$stream['stream_id']] ?? 0); ?></td>
              <td <?php echo $stream['stream_id'] === 'total' ? 'class="red-text"' : ''; ?>>
                <?php
                $mean = ($mean_points[$subject_id][$stream['stream_id']]['count'] > 0)
                    ? $mean_points[$subject_id][$stream['stream_id']]['sum'] / $mean_points[$subject_id][$stream['stream_id']]['count']
                    : 0;
                echo number_format($mean, 4);
                ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Footer -->
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

    // Show the modal
    const modal = new bootstrap.Modal(document.getElementById('downloadModal'), {
        backdrop: 'static',
        keyboard: false
    });
    modal.show();

    const opt = {
        margin: 10,
        filename: 'Grade_Analysis_Report_<?php echo str_replace(' ', '_', htmlspecialchars($exam_name)); ?>_<?php echo $year; ?>.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2, useCORS: true },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'landscape' }
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