<?php
// reports/index.php
include __DIR__ . '/../header.php';
include __DIR__ . '/../sidebar.php';
require __DIR__ . '/../../connection/db.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['school_id']) || !isset($_SESSION['role_id'])) {
    header("Location: ../../login.php");
    exit;
}

$school_id = $_SESSION['school_id'];

// Fetch classes
// Fetch classes  ← replace this entire block
$stmt = $conn->prepare("
    SELECT class_id, form_name
    FROM classes
    WHERE school_id = ?
      AND is_cbc = 1          -- ← add this line
    ORDER BY form_name
");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();;

// Fetch years from exams (extracted from created_at)
$stmt = $conn->prepare("
    SELECT DISTINCT YEAR(created_at) AS year
    FROM exams
    WHERE school_id = ? AND status = 'closed'
    AND EXISTS (SELECT 1 FROM results r WHERE r.exam_id = exams.exam_id AND r.status = 'confirmed')
    ORDER BY year DESC
");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$years = $stmt->get_result()->fetch_all(MYSQLI_NUM);
$years = array_column($years, 0);
$stmt->close();

// Fetch school details for report header
$stmt = $conn->prepare("SELECT name, logo FROM schools WHERE school_id = ?");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$school = $stmt->get_result()->fetch_assoc();
$stmt->close();
?>

<style>
    .table-container {
        max-width: 100%;
        overflow-x: auto;
    }

    th,
    td {
        min-width: 80px;
        text-align: center;
        padding: 4px;
        border: 1px solid #000;
    }

    .table th {
        background: #e9f2ff !important;
        color: #0d47a1;
        text-align: center;
    }

    .table th,
    .table td {
        padding: 2px 3px !important;
        vertical-align: middle;
        font-size: 9px;
    }

    .btn-custom {
        border-radius: 20px;
        padding: 4px 10px;
        font-size: 11px;
        margin-left: 5px;
    }
</style>

<div class="content">
    <div class="container-fluid">
        <div class="container py-4">
            <h3 class="mb-4 d-flex align-items-center">
                <i class="bi bi-bar-chart me-2"></i>Competency-Based Curriculum Examination Analysis
            </h3>

            <!-- Analysis Menu -->
            <div class="row g-4 mb-4">
                <!-- View Results -->
                <div class="col-md-3">
                    <div class="card shadow-sm border-0 h-100 text-center">
                        <div class="card-body d-flex flex-column justify-content-center">
                            <i class="bi bi-bar-chart display-5 text-primary"></i>
                            <h5 class="mt-3">View Results</h5>
                            <p class="text-muted">Analyze exam results, grades, and rankings.</p>
                            <button class="btn btn-primary mt-auto" id="viewResultsBtn">
                                <i class="bi bi-eye me-2"></i> View Results
                            </button>
                        </div>
                    </div>
                </div>
                <!-- Report Card (New) -->
                <div class="col-md-3">
                    <div class="card shadow-sm border-0 h-100 text-center">
                        <div class="card-body d-flex flex-column justify-content-center">
                            <i class="bi bi-file-earmark-text display-5 text-primary"></i>
                            <h5 class="mt-3">Report Card</h5>
                            <p class="text-muted">Generate detailed student report cards with grades and remarks.</p>
                            <button class="btn btn-primary mt-auto" id="reportCardBtn">
                                <i class="bi bi-eye me-2"></i> Generate Report Card
                            </button>
                        </div>
                    </div>
                </div>
                <!-- Transcript Download (New) -->
                <div class="col-md-3">
                    <div class="card shadow-sm border-0 h-100 text-center">
                        <div class="card-body d-flex flex-column justify-content-center">
                            <i class="bi bi-download display-5 text-primary"></i>
                            <h5 class="mt-3">Transcript Download</h5>
                            <p class="text-muted">Generate and download termly transcript with aggregates.</p>
                            <button class="btn btn-primary mt-auto" id="transcriptDownloadBtn">
                                <i class="bi bi-download me-2"></i> Generate Transcript
                            </button>
                        </div>
                    </div>
                </div>
                <!-- Subject Analysis -->
                <div class="col-md-3">
                    <div class="card shadow-sm border-0 h-100 text-center">
                        <div class="card-body d-flex flex-column justify-content-center">
                            <i class="bi bi-book display-5 text-primary"></i>
                            <h5 class="mt-3">Subject Analysis</h5>
                            <p class="text-muted">Analyze subject grades per exam.</p>
                            <button class="btn btn-primary mt-auto" id="subjectAnalysisBtn">
                                <i class="bi bi-graph-up me-2"></i> View Subject Analysis
                            </button>
                        </div>
                    </div>
                </div>
                <!-- Top/Bottom Students -->
                <!-- <div class="col-md-3">
                    <div class="card shadow-sm border-0 h-100 text-center">
                        <div class="card-body d-flex flex-column justify-content-center">
                            <i class="bi bi-trophy display-5 text-primary"></i>
                            <h5 class="mt-3">Top/Bottom Students</h5>
                            <p class="text-muted">View top or bottom students per exam.</p>
                            <button class="btn btn-primary mt-auto" id="performanceBtn">
                                <i class="bi bi-star me-2"></i> View Performance
                            </button>
                        </div>
                    </div>
                </div> -->

                <!-- School Grade Analysis -->
                <div class="col-md-3">
                    <div class="card shadow-sm border-0 h-100 text-center">
                        <div class="card-body d-flex flex-column justify-content-center">
                            <i class="bi bi-table display-5 text-primary"></i>
                            <h5 class="mt-3">School Grade Analysis</h5>
                            <p class="text-muted">View grade distribution by class and stream.</p>
                            <button class="btn btn-primary mt-auto" id="schoolGradeAnalysisBtn">
                                <i class="bi bi-bar-chart-fill me-2"></i> View Grade Analysis
                            </button>
                        </div>
                    </div>
                </div>
                <!-- Class List -->
                <div class="col-md-3">
                    <div class="card shadow-sm border-0 h-100 text-center">
                        <div class="card-body d-flex flex-column justify-content-center">
                            <i class="bi bi-list-ul display-5 text-primary"></i>
                            <h5 class="mt-3">Class List</h5>
                            <p class="text-muted">Generate class lists for a stream and subject.</p>
                            <button class="btn btn-primary mt-auto" id="classListBtn">
                                <i class="bi bi-list-check me-2"></i> Generate Class List
                            </button>
                        </div>
                    </div>
                </div>
                <!-- Score Sheet -->
                <div class="col-md-3">
                    <div class="card shadow-sm border-0 h-100 text-center">
                        <div class="card-body d-flex flex-column justify-content-center">
                            <i class="bi bi-table display-5 text-primary"></i>
                            <h5 class="mt-3">Score Sheet</h5>
                            <p class="text-muted">Generate score sheets for a stream and subject.</p>
                            <button class="btn btn-primary mt-auto" id="scoreSheetBtn">
                                <i class="bi bi-file-earmark-text me-2"></i> Generate Score Sheet
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Custom Group Performance (New for CBC) -->
                <div class="col-md-3">
                    <div class="card shadow-sm border-0 h-100 text-center">
                        <div class="card-body d-flex flex-column justify-content-center">
                            <i class="bi bi-people-fill display-5 text-primary"></i>
                            <h5 class="mt-3">Custom Group Performance</h5>
                            <p class="text-muted">View subject grades & aggregates for students in a custom group (CBC only).</p>
                            <button class="btn btn-primary mt-auto" id="customGroupPerformanceBtn">
                                <i class="bi bi-graph-up-arrow me-2"></i> View Group Performance
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- View Results Modal (CBC) - with Dorm & House filters -->
            <div class="modal fade" id="resultsModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-xl">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title d-flex align-items-center">
                                <i class="bi bi-list-ul me-2"></i> Select Form, Term, Exam + Optional Filters
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <form id="analysisForm" action="cbcanalysis/examreports/meritlist.php" method="get">
                                <!-- Required fields -->
                                <div class="row g-3 mb-3">
                                    <div class="col-md-3">
                                        <label class="form-label">Form <span class="text-danger">*</span></label>
                                        <select class="form-select" id="analysisClassId" name="class_id" required>
                                            <option value="">Select Form</option>
                                            <?php foreach ($classes as $class): ?>
                                                <option value="<?php echo $class['class_id']; ?>">
                                                    <?php echo htmlspecialchars($class['form_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Term <span class="text-danger">*</span></label>
                                        <select class="form-select" id="analysisTerm" name="term" disabled required>
                                            <option value="">Select Term</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Exam <span class="text-danger">*</span></label>
                                        <select class="form-select" id="analysisExamId" name="exam_id" disabled required>
                                            <option value="">Select Exam</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Stream (optional)</label>
                                        <select class="form-select" id="analysisStreamId" name="stream_id" disabled>
                                            <option value="">— All Streams —</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Optional Filters: Dormitory + House -->
                                <div class="row g-3 mb-3 border-top pt-3">
                                    <div class="col-12">
                                        <label class="form-label fw-bold text-muted">
                                            <i class="bi bi-filter-circle me-1"></i> Optional: Filter by Dormitory or House
                                        </label>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Dormitory</label>
                                        <select class="form-select" id="analysisDormitoryId" name="dormitory_id" disabled>
                                            <option value="">— Any / All dorms —</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">House</label>
                                        <select class="form-select" id="analysisHouseId" name="house_id" disabled>
                                            <option value="">— Any / All houses —</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="alert alert-info small mt-2">
                                    <strong>Note:</strong> You can leave Stream, Dormitory, and House empty for whole form results.<br>
                                    Select only one filter (stream/dorm/house) for best results — multiple selections may be combined.
                                </div>

                                <button type="submit" class="btn btn-primary w-100 mt-3" id="loadAnalysisBtn">
                                    <i class="bi bi-list-ol me-2"></i> Load Merit List
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Report Cards Modal -->
            <div class="modal fade" id="reportCardsModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-xl">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title d-flex align-items-center">
                                <i class="bi bi-file-earmark-text me-2"></i> Select Form, Term, and Stream
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <form id="reportCardForm" action="cbcanalysis/reportcard.php" method="post">
                                <div class="row g-3 mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Form</label>
                                        <select class="form-select" id="reportClassId" name="class_id" required>
                                            <option value="">Select Form</option>
                                            <?php foreach ($classes as $class): ?>
                                                <option value="<?php echo $class['class_id']; ?>">
                                                    <?php echo htmlspecialchars($class['form_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Term</label>
                                        <select class="form-select" id="reportTerm" name="term" disabled required>
                                            <option value="">Select Term</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Stream</label>
                                        <select class="form-select" id="reportStreamId" name="stream_id" disabled required>
                                            <option value="">Select Stream</option>
                                        </select>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary" id="generateReportBtn">Generate Report Cards</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Subject Analysis Modal -->
            <div class="modal fade" id="subjectAnalysisModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-xl">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title d-flex align-items-center">
                                <i class="bi bi-graph-up me-2"></i> Select Form, Term, Exam, and Stream
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <form id="subjectAnalysisForm" action="cbcanalysis/examreports/subjectanalysisreport_exam.php" method="post">
                                <div class="row g-3 mb-3">
                                    <div class="col-md-3">
                                        <label class="form-label">Form</label>
                                        <select class="form-select" id="subjectClassId" name="class_id" required>
                                            <option value="">Select Form</option>
                                            <?php foreach ($classes as $class): ?>
                                                <option value="<?php echo $class['class_id']; ?>">
                                                    <?php echo htmlspecialchars($class['form_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Term</label>
                                        <select class="form-select" id="subjectTerm" name="term" disabled required>
                                            <option value="">Select Term</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Exam</label>
                                        <select class="form-select" id="subjectExamId" name="exam_id" disabled required>
                                            <option value="">Select Exam</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Stream (Optional)</label>
                                        <select class="form-select" id="subjectStreamId" name="stream_id" disabled>
                                            <option value="">All Streams</option>
                                        </select>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary" id="generateSubjectAnalysisBtn">Generate Subject Analysis</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Top/Bottom Students Modal -->
            <div class="modal fade" id="performanceModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-xl">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title d-flex align-items-center">
                                <i class="bi bi-trophy me-2"></i> Select Form, Term, Exam, Stream, and Performance
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <form id="performanceForm" action="cbcanalysis/examreports/countbyperfomance_perexam.php" method="post">
                                <div class="row g-3 mb-3">
                                    <div class="col-md-2">
                                        <label class="form-label">Form</label>
                                        <select class="form-select" id="performanceClassId" name="class_id" required>
                                            <option value="">Select Form</option>
                                            <?php foreach ($classes as $class): ?>
                                                <option value="<?php echo $class['class_id']; ?>">
                                                    <?php echo htmlspecialchars($class['form_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Term</label>
                                        <select class="form-select" id="performanceTerm" name="term" disabled required>
                                            <option value="">Select Term</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Exam</label>
                                        <select class="form-select" id="performanceExamId" name="exam_id" disabled required>
                                            <option value="">Select Exam</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Stream (Optional)</label>
                                        <select class="form-select" id="performanceStreamId" name="stream_id" disabled>
                                            <option value="">All Streams</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Performance</label>
                                        <select class="form-select" id="performanceType" name="performance_type" required>
                                            <option value="">Select Type</option>
                                            <option value="top">Top Students</option>
                                            <option value="bottom">Bottom Students</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Number of Students</label>
                                        <input type="number" class="form-control" id="performanceCount" name="student_count" min="1" required>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary" id="generatePerformanceBtn">Generate Performance Report</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Report Card Modal (New) -->
            <div class="modal fade" id="reportCardModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title d-flex align-items-center">
                                <i class="bi bi-file-earmark-text me-2"></i> Select Year, Form, Exam, and Stream
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <form id="reportCardFormNew" action="cbcanalysis/examreports/ExamReport.php" method="get">
                                <div class="row g-3 mb-3">
                                    <div class="col-md-3">
                                        <label class="form-label">Year</label>
                                        <select class="form-select" id="reportYear" name="year" required>
                                            <option value="">Select Year</option>
                                            <?php foreach ($years as $year): ?>
                                                <option value="<?php echo htmlspecialchars($year); ?>"><?php echo htmlspecialchars($year); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Form</label>
                                        <select class="form-select" id="reportClassIdNew" name="class_id" required>
                                            <option value="">Select Form</option>
                                            <?php foreach ($classes as $class): ?>
                                                <option value="<?php echo $class['class_id']; ?>">
                                                    <?php echo htmlspecialchars($class['form_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Exam</label>
                                        <select class="form-select" id="reportExamId" name="exam_id" disabled required>
                                            <option value="">Select Exam</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Stream</label>
                                        <select class="form-select" id="reportStreamIdNew" name="stream_id" disabled required>
                                            <option value="">Select Stream</option>
                                            <option value="0">All Streams</option>
                                        </select>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary" id="loadReportCardBtn">Generate Report Card</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Transcript Download Modal (New) -->
            <div class="modal fade" id="transcriptDownloadModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title d-flex align-items-center">
                                <i class="bi bi-download me-2"></i> Select Year, Term, Form, and Stream
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <form id="transcriptDownloadForm">
                                <div class="row g-3 mb-3">
                                    <div class="col-md-3">
                                        <label class="form-label">Year</label>
                                        <select class="form-select" id="transcriptYear" name="year" required>
                                            <option value="">Select Year</option>
                                            <?php foreach ($years as $year): ?>
                                                <option value="<?php echo htmlspecialchars($year); ?>"><?php echo htmlspecialchars($year); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Form</label>
                                        <select class="form-select" id="transcriptClassId" name="class_id" required>
                                            <option value="">Select Form</option>
                                            <?php foreach ($classes as $class): ?>
                                                <option value="<?php echo $class['class_id']; ?>">
                                                    <?php echo htmlspecialchars($class['form_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label">Term</label>
                                        <select class="form-select" id="transcriptTerm" name="term" disabled required>
                                            <option value="">Select Term</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Stream</label>
                                        <select class="form-select" id="transcriptStreamId" name="stream_id" disabled required>
                                            <option value="">Select Stream</option>
                                            <option value="0">All Streams</option>
                                        </select>
                                    </div>
                                </div>
                                <button type="button" class="btn btn-primary" id="generateTranscriptBtn">Generate Transcript</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- School Grade Analysis Modal -->
            <div class="modal fade" id="schoolGradeAnalysisModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header" style="background-color: #0d47a1; color: #fff;">
                            <h5 class="modal-title d-flex align-items-center">
                                <i class="bi bi-bar-chart-fill me-2"></i> Select Year, Exam, and Classes
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <form id="schoolGradeAnalysisForm" action="cbcanalysis/examreports/schoolanalysis_perexam.php" method="post">
                                <div class="row g-3 mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Year</label>
                                        <select class="form-select" id="schoolYear" name="year" required>
                                            <option value="">Select Year</option>
                                            <?php foreach ($years as $year): ?>
                                                <option value="<?php echo htmlspecialchars($year); ?>"><?php echo htmlspecialchars($year); ?></option>
                                            <?php endforeach; ?>
                                            <?php
                                            // Fallback: Add current and previous 5 years
                                            $current_year = date('Y');
                                            for ($y = $current_year; $y >= $current_year - 5; $y--) {
                                                if (!in_array($y, $years)) {
                                                    echo "<option value='$y'>$y</option>";
                                                }
                                            }
                                            ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Exam</label>
                                        <select class="form-select" id="schoolExamId" name="exam_name" disabled required>
                                            <option value="">Select Exam</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Term</label>
                                        <select class="form-select" id="schoolTerm" name="term" disabled required>
                                            <option value="">Select Term</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Classes</label>
                                    <div id="classCheckboxes" style="max-height: 300px; overflow-y: auto;">
                                        <!-- Classes will be populated dynamically -->
                                    </div>
                                </div>
                                <input type="hidden" name="school_id" value="<?php echo htmlspecialchars($school_id); ?>">
                                <button type="submit" class="btn btn-primary" id="generateSchoolGradeAnalysisBtn">Generate Grade Analysis</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Class List Modal -->
            <div class="modal fade" id="classListModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title d-flex align-items-center">
                                <i class="bi bi-list-check me-2"></i> Select Form, Stream or Custom Group
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <form id="classListForm" action="cbcanalysis/examreports/classlist.php" method="post">
                                <div class="row g-3 mb-3">

                                    <!-- Form -->
                                    <div class="col-md-4">
                                        <label class="form-label">Form</label>
                                        <select class="form-select" id="classListClassId" name="class_id" required>
                                            <option value="">Select Form</option>
                                            <?php foreach ($classes as $class): ?>
                                                <option value="<?php echo $class['class_id']; ?>">
                                                    <?php echo htmlspecialchars($class['form_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <!-- Stream -->
                                    <div class="col-md-4">
                                        <label class="form-label">Stream</label>
                                        <select class="form-select" id="classListStreamId" name="stream_id" disabled>
                                            <option value="">Select Stream (optional)</option>
                                        </select>
                                    </div>

                                    <!-- Custom Group -->
                                    <div class="col-md-4">
                                        <label class="form-label">Custom Group</label>
                                        <select class="form-select" id="classListGroupId" name="group_id" disabled>
                                            <option value="">Select Custom Group (optional)</option>
                                        </select>
                                    </div>

                                </div>

                                <!-- Note to user -->
                                <div class="alert alert-info small mb-3">
                                    Select <strong>either</strong> a Stream <strong>or</strong> a Custom Group (not both).
                                    If Custom Group is chosen, subjects from that group will be used.
                                </div>

                                <input type="hidden" name="school_id" value="<?php echo htmlspecialchars($school_id); ?>">
                                <button type="submit" class="btn btn-primary" id="generateClassListBtn" disabled>
                                    Generate Class List
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Score Sheet Modal - CBC version with Stream OR Group support -->
            <div class="modal fade" id="scoreSheetModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title d-flex align-items-center">
                                <i class="bi bi-file-earmark-text me-2"></i> Generate Score Sheet (CBC)
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <form id="scoreSheetForm" action="cbcanalysis/examreports/scoresheet.php" method="post">
                                <div class="row g-3 mb-3">
                                    <!-- Form -->
                                    <div class="col-md-4">
                                        <label class="form-label">Form <span class="text-danger">*</span></label>
                                        <select class="form-select" id="scoreSheetClassId" name="class_id" required>
                                            <option value="">Select Form</option>
                                            <?php foreach ($classes as $class): ?>
                                                <option value="<?php echo $class['class_id']; ?>">
                                                    <?php echo htmlspecialchars($class['form_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <!-- Stream -->
                                    <div class="col-md-4">
                                        <label class="form-label">Stream (optional)</label>
                                        <select class="form-select" id="scoreSheetStreamId" name="stream_id">
                                            <option value="">— All Streams in Form —</option>
                                            <!-- filled by JS -->
                                        </select>
                                    </div>
                                    <!-- Custom Group -->
                                    <div class="col-md-4">
                                        <label class="form-label">Custom Group (optional)</label>
                                        <select class="form-select" id="scoreSheetGroupId" name="group_id">
                                            <option value="">— Select Group (alternative) —</option>
                                            <!-- filled by JS -->
                                        </select>
                                    </div>
                                </div>

                                <div class="row g-3 mb-3">
                                    <div class="col-md-12">
                                        <label class="form-label">
                                            Subject <span id="subjectRequired" class="text-danger">*</span>
                                        </label>
                                        <select class="form-select" id="scoreSheetSubjectId" name="subject_id">
                                            <option value="">Select Subject</option>
                                            <!-- filled by JS -->
                                        </select>
                                        <small id="groupSubjectNote" class="form-text text-muted d-none">
                                            Subject is locked because a Custom Group is selected.
                                        </small>
                                    </div>
                                </div>

                                <input type="hidden" name="school_id" value="<?php echo htmlspecialchars($school_id); ?>">

                                <button type="submit" class="btn btn-primary w-100" id="generateScoreSheetBtn" disabled>
                                    Generate Score Sheet
                                </button>
                            </form>

                            <small class="text-muted d-block mt-3">
                                Choose <strong>either Stream</strong> or <strong>Custom Group</strong> (not both).<br>
                                Subject required unless group selected (auto-loads if group has 1 subject).
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Custom Group Performance Modal (CBC) -->
<div class="modal fade" id="customGroupPerformanceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title d-flex align-items-center">
                    <i class="bi bi-people me-2"></i> Custom Group Performance Analysis (CBC)
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="customGroupPerformanceForm" action="cbcanalysis/examreports/CustomGroupPerformance.php" method="post">
                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label class="form-label">Year <span class="text-danger">*</span></label>
                            <select class="form-select" id="cgpYear" name="year" required>
                                <option value="">Select Year</option>
                                <?php foreach ($years as $year): ?>
                                    <option value="<?php echo htmlspecialchars($year); ?>"><?php echo htmlspecialchars($year); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Form <span class="text-danger">*</span></label>
                            <select class="form-select" id="cgpClassId" name="class_id" required>
                                <option value="">Select Form</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo $class['class_id']; ?>">
                                        <?php echo htmlspecialchars($class['form_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Exam <span class="text-danger">*</span></label>
                            <select class="form-select" id="cgpExamId" name="exam_id" disabled required>
                                <option value="">Select Exam</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Custom Group <span class="text-danger">*</span></label>
                            <select class="form-select" id="cgpGroupId" name="group_id" disabled required>
                                <option value="">Select Group</option>
                            </select>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100" id="generateCustomGroupBtn" disabled>
                        Generate Group Performance Report
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    $(document).ready(function() {
        // Show results modal
        $('#viewResultsBtn').on('click', function() {
            console.log('View Results button clicked');
            $('#resultsModal').modal('show');
        });

        // Show report cards modal
        $('#generateReportCardsBtn').on('click', function() {
            console.log('Report Cards button clicked');
            $('#reportCardsModal').modal('show');
        });

        // Show subject analysis modal
        $('#subjectAnalysisBtn').on('click', function() {
            console.log('Subject Analysis button clicked');
            $('#subjectAnalysisModal').modal('show');
        });

        // Show performance modal
        $('#performanceBtn').on('click', function() {
            console.log('Performance button clicked');
            $('#performanceModal').modal('show');
        });

        // Show report card modal (new)
        $('#reportCardBtn').on('click', function() {
            console.log('Report Card button clicked');
            try {
                $('#reportCardModal').modal('show');
            } catch (e) {
                console.error('Error opening reportCardModal:', e);
                alert('Error: Could not open Report Card modal. Check console for details.');
            }
        });

        // Show transcript download modal (new)
        $('#transcriptDownloadBtn').on('click', function() {
            console.log('Transcript Download button clicked');
            $('#transcriptDownloadModal').modal('show');
        });

        // Show school grade analysis modal
        $('#schoolGradeAnalysisBtn').on('click', function() {
            console.log('School Grade Analysis button clicked');
            $('#schoolGradeAnalysisModal').modal('show');
        });

        // Show class list modal
        $('#classListBtn').on('click', function() {
            console.log('Class List button clicked');
            $('#classListModal').modal('show');
        });

        // Show score sheet modal
        $('#scoreSheetBtn').on('click', function() {
            console.log('Score Sheet button clicked');
            $('#scoreSheetModal').modal('show');
        });

        // Handle Form Selection for analysis
        $('#analysisClassId').on('change', function() {
            const classId = $(this).val();
            $('#analysisTerm').html('<option value="">Select Term</option>').prop('disabled', true);
            $('#analysisExamId').html('<option value="">Select Exam</option>').prop('disabled', true);
            $('#analysisStreamId').html('<option value="">Select Stream</option><option value="0">All Streams</option>').prop('disabled', true);
            if (classId) {
                // Load terms
                $.post('cbcanalysis/functions.php', {
                    action: 'get_terms_for_class',
                    class_id: classId
                }, function(response) {
                    console.log('Terms Response:', response);
                    if (response.status === 'success') {
                        if (response.terms && response.terms.length > 0) {
                            response.terms.forEach(term => {
                                $('#analysisTerm').append(`<option value="${term}">${term}</option>`);
                            });
                            $('#analysisTerm').prop('disabled', false);
                        } else {
                            alert('No terms found for the selected form.');
                        }
                    } else {
                        alert('Error: ' + response.message);
                    }
                }, 'json').fail(function(jqXHR, textStatus, errorThrown) {
                    console.error('Failed to load terms:', textStatus, errorThrown);
                    alert('Failed to load terms.');
                });

                // Load streams
                $.post('cbcanalysis/functions.php', {
                    action: 'get_streams',
                    class_id: classId
                }, function(response) {
                    console.log('Streams Response:', response);
                    if (response.status === 'success') {
                        if (response.streams && response.streams.length > 0) {
                            response.streams.forEach(stream => {
                                $('#analysisStreamId').append(`<option value="${stream.stream_id}">${stream.stream_name}</option>`);
                            });
                            $('#analysisStreamId').prop('disabled', false);
                        } else {
                            alert('No streams found for the selected form.');
                        }
                    } else {
                        alert('Error: ' + response.message);
                    }
                }, 'json').fail(function(jqXHR, textStatus, errorThrown) {
                    console.error('Failed to load streams:', textStatus, errorThrown);
                    alert('Failed to load streams.');
                });
            }
        });

        // Handle Term Selection for analysis
        $('#analysisTerm').on('change', function() {
            const classId = $('#analysisClassId').val();
            const term = $(this).val();
            $('#analysisExamId').html('<option value="">Select Exam</option>').prop('disabled', true);
            if (classId && term) {
                $.post('cbcanalysis/functions.php', {
                    action: 'get_exams_for_class',
                    class_id: classId,
                    term: term
                }, function(response) {
                    console.log('Exams Response:', response);
                    if (response.status === 'success') {
                        if (response.exams && response.exams.length > 0) {
                            response.exams.forEach(exam => {
                                $('#analysisExamId').append(`<option value="${exam.exam_id}">${exam.exam_name}</option>`);
                            });
                            $('#analysisExamId').prop('disabled', false);
                        } else {
                            alert('No exams found for the selected form and term.');
                        }
                    } else {
                        alert('Error: ' + response.message);
                    }
                }, 'json').fail(function(jqXHR, textStatus, errorThrown) {
                    console.error('Failed to load exams:', textStatus, errorThrown);
                    alert('Failed to load exams.');
                });
            }
        });

        // Handle Load Analysis button
        // Handle Load Analysis button


        // Print button functionality
        $('#printAnalysisBtn').on('click', function() {
            window.print();
        });

        // Download button functionality
        $('#downloadAnalysisBtn').on('click', function() {
            const element = document.getElementById('analysisTableContainer');
            const formName = $('#analysisClassId option:selected').text();
            const term = $('#analysisTerm').val();
            const examName = $('#analysisExamId option:selected').text();
            const streamName = $('#analysisStreamId option:selected').text();
            const filename = `Results_${formName}_${term}_${examName}_${streamName}.pdf`;
            html2pdf()
                .from(element)
                .set({
                    margin: 1,
                    filename: filename,
                    html2canvas: {
                        scale: 2
                    },
                    jsPDF: {
                        orientation: 'landscape'
                    }
                })
                .save();
        });

        // Handle Form Selection for report cards
        $('#reportClassId').on('change', function() {
            const classId = $(this).val();
            $('#reportTerm').html('<option value="">Select Term</option>').prop('disabled', true);
            $('#reportStreamId').html('<option value="">Select Stream</option>').prop('disabled', true);
            if (classId) {
                // Load terms
                $.post('cbcanalysis/functions.php', {
                    action: 'get_terms_for_class',
                    class_id: classId
                }, function(response) {
                    console.log('Terms Response (Report Cards):', response);
                    if (response.status === 'success') {
                        if (response.terms && response.terms.length > 0) {
                            response.terms.forEach(term => {
                                $('#reportTerm').append(`<option value="${term}">${term}</option>`);
                            });
                            $('#reportTerm').prop('disabled', false);
                        } else {
                            alert('No terms found for the selected form.');
                        }
                    } else {
                        alert('Error: ' + response.message);
                    }
                }, 'json').fail(function(jqXHR, textStatus, errorThrown) {
                    console.error('Failed to load terms:', textStatus, errorThrown);
                    alert('Failed to load terms.');
                });

                // Load streams
                $.post('cbcanalysis/functions.php', {
                    action: 'get_streams',
                    class_id: classId
                }, function(response) {
                    console.log('Streams Response (Report Cards):', response);
                    if (response.status === 'success') {
                        if (response.streams && response.streams.length > 0) {
                            response.streams.forEach(stream => {
                                $('#reportStreamId').append(`<option value="${stream.stream_id}">${stream.stream_name}</option>`);
                            });
                            $('#reportStreamId').prop('disabled', false);
                        } else {
                            alert('No streams found for the selected form.');
                        }
                    } else {
                        alert('Error: ' + response.message);
                    }
                }, 'json').fail(function(jqXHR, textStatus, errorThrown) {
                    console.error('Failed to load streams:', textStatus, errorThrown);
                    alert('Failed to load streams.');
                });
            }
        });

        // Handle Form Selection for subject analysis
        $('#subjectClassId').on('change', function() {
            const classId = $(this).val();
            $('#subjectTerm').html('<option value="">Select Term</option>').prop('disabled', true);
            $('#subjectExamId').html('<option value="">Select Exam</option>').prop('disabled', true);
            $('#subjectStreamId').html('<option value="">All Streams</option>').prop('disabled', true);
            if (classId) {
                // Load terms
                $.post('cbcanalysis/functions.php', {
                    action: 'get_terms_for_class',
                    class_id: classId
                }, function(response) {
                    console.log('Terms Response (Subject Analysis):', response);
                    if (response.status === 'success') {
                        if (response.terms && response.terms.length > 0) {
                            response.terms.forEach(term => {
                                $('#subjectTerm').append(`<option value="${term}">${term}</option>`);
                            });
                            $('#subjectTerm').prop('disabled', false);
                        } else {
                            alert('No terms found for the selected form.');
                        }
                    } else {
                        alert('Error: ' + response.message);
                    }
                }, 'json').fail(function(jqXHR, textStatus, errorThrown) {
                    console.error('Failed to load terms:', textStatus, errorThrown);
                    alert('Failed to load terms.');
                });

                // Load streams
                $.post('cbcanalysis/functions.php', {
                    action: 'get_streams',
                    class_id: classId
                }, function(response) {
                    console.log('Streams Response (Subject Analysis):', response);
                    if (response.status === 'success') {
                        if (response.streams && response.streams.length > 0) {
                            response.streams.forEach(stream => {
                                $('#subjectStreamId').append(`<option value="${stream.stream_id}">${stream.stream_name}</option>`);
                            });
                            $('#subjectStreamId').prop('disabled', false);
                        } else {
                            alert('No streams found for the selected form.');
                        }
                    } else {
                        alert('Error: ' + response.message);
                    }
                }, 'json').fail(function(jqXHR, textStatus, errorThrown) {
                    console.error('Failed to load streams:', textStatus, errorThrown);
                    alert('Failed to load streams.');
                });
            }
        });

        // Handle Term Selection for subject analysis
        $('#subjectTerm').on('change', function() {
            const classId = $('#subjectClassId').val();
            const term = $(this).val();
            $('#subjectExamId').html('<option value="">Select Exam</option>').prop('disabled', true);
            if (classId && term) {
                $.post('cbcanalysis/functions.php', {
                    action: 'get_exams_for_class',
                    class_id: classId,
                    term: term
                }, function(response) {
                    console.log('Exams Response (Subject Analysis):', response);
                    if (response.status === 'success') {
                        if (response.exams && response.exams.length > 0) {
                            response.exams.forEach(exam => {
                                $('#subjectExamId').append(`<option value="${exam.exam_id}">${exam.exam_name}</option>`);
                            });
                            $('#subjectExamId').prop('disabled', false);
                        } else {
                            alert('No exams found for the selected form and term.');
                        }
                    } else {
                        alert('Error: ' + response.message);
                    }
                }, 'json').fail(function(jqXHR, textStatus, errorThrown) {
                    console.error('Failed to load exams:', textStatus, errorThrown);
                    alert('Failed to load exams.');
                });
            }
        });

        // Handle Form Selection for performance
        $('#performanceClassId').on('change', function() {
            const classId = $(this).val();
            $('#performanceTerm').html('<option value="">Select Term</option>').prop('disabled', true);
            $('#performanceExamId').html('<option value="">Select Exam</option>').prop('disabled', true);
            $('#performanceStreamId').html('<option value="">All Streams</option>').prop('disabled', true);
            if (classId) {
                // Load terms
                $.post('cbcanalysis/functions.php', {
                    action: 'get_terms_for_class',
                    class_id: classId
                }, function(response) {
                    console.log('Terms Response (Performance):', response);
                    if (response.status === 'success') {
                        if (response.terms && response.terms.length > 0) {
                            response.terms.forEach(term => {
                                $('#performanceTerm').append(`<option value="${term}">${term}</option>`);
                            });
                            $('#performanceTerm').prop('disabled', false);
                        } else {
                            alert('No terms found for the selected form.');
                        }
                    } else {
                        alert('Error: ' + response.message);
                    }
                }, 'json').fail(function(jqXHR, textStatus, errorThrown) {
                    console.error('Failed to load terms:', textStatus, errorThrown);
                    alert('Failed to load terms.');
                });

                // Load streams
                $.post('cbcanalysis/functions.php', {
                    action: 'get_streams',
                    class_id: classId
                }, function(response) {
                    console.log('Streams Response (Performance):', response);
                    if (response.status === 'success') {
                        if (response.streams && response.streams.length > 0) {
                            response.streams.forEach(stream => {
                                $('#performanceStreamId').append(`<option value="${stream.stream_id}">${stream.stream_name}</option>`);
                            });
                            $('#performanceStreamId').prop('disabled', false);
                        } else {
                            alert('No streams found for the selected form.');
                        }
                    } else {
                        alert('Error: ' + response.message);
                    }
                }, 'json').fail(function(jqXHR, textStatus, errorThrown) {
                    console.error('Failed to load streams:', textStatus, errorThrown);
                    alert('Failed to load streams.');
                });
            }
        });

        // Handle Term Selection for performance
        $('#performanceTerm').on('change', function() {
            const classId = $('#performanceClassId').val();
            const term = $(this).val();
            $('#performanceExamId').html('<option value="">Select Exam</option>').prop('disabled', true);
            if (classId && term) {
                $.post('cbcanalysis/functions.php', {
                    action: 'get_exams_for_class',
                    class_id: classId,
                    term: term
                }, function(response) {
                    console.log('Exams Response (Performance):', response);
                    if (response.status === 'success') {
                        if (response.exams && response.exams.length > 0) {
                            response.exams.forEach(exam => {
                                $('#performanceExamId').append(`<option value="${exam.exam_id}">${exam.exam_name}</option>`);
                            });
                            $('#performanceExamId').prop('disabled', false);
                        } else {
                            alert('No exams found for the selected form and term.');
                        }
                    } else {
                        alert('Error: ' + response.message);
                    }
                }, 'json').fail(function(jqXHR, textStatus, errorThrown) {
                    console.error('Failed to load exams:', textStatus, errorThrown);
                    alert('Failed to load exams.');
                });
            }
        });

        // Handle Report Card Year and Form Selection (New)
        $('#reportYear, #reportClassIdNew').on('change', function() {
            const year = $('#reportYear').val();
            const classId = $('#reportClassIdNew').val();
            $('#reportExamId').html('<option value="">Select Exam</option>').prop('disabled', true);
            $('#reportStreamIdNew').html('<option value="">Select Stream</option><option value="0">All Streams</option>').prop('disabled', true);
            if (year && classId) {
                // Load exams
                $.post('cbcanalysis/functions.php', {
                    action: 'get_exams_for_class_and_year',
                    class_id: classId,
                    year: year
                }, function(response) {
                    console.log('Exams Response (Report Card):', response);
                    if (response.status === 'success') {
                        if (response.exams && response.exams.length > 0) {
                            response.exams.forEach(exam => {
                                $('#reportExamId').append(`<option value="${exam.exam_id}">${exam.exam_name}</option>`);
                            });
                            $('#reportExamId').prop('disabled', false);
                        } else {
                            alert('No closed and confirmed exams found for the selected form and year.');
                        }
                    } else {
                        alert('Error: ' + response.message);
                    }
                }, 'json').fail(function(jqXHR, textStatus, errorThrown) {
                    console.error('Failed to load exams:', textStatus, errorThrown);
                    alert('Failed to load exams.');
                });

                // Load streams
                $.post('cbcanalysis/functions.php', {
                    action: 'get_streams',
                    class_id: classId
                }, function(response) {
                    console.log('Streams Response (Report Card):', response);
                    if (response.status === 'success') {
                        if (response.streams && response.streams.length > 0) {
                            response.streams.forEach(stream => {
                                $('#reportStreamIdNew').append(`<option value="${stream.stream_id}">${stream.stream_name}</option>`);
                            });
                            $('#reportStreamIdNew').prop('disabled', false);
                        } else {
                            alert('No streams found for the selected form.');
                        }
                    } else {
                        alert('Error: ' + response.message);
                    }
                }, 'json').fail(function(jqXHR, textStatus, errorThrown) {
                    console.error('Failed to load streams:', textStatus, errorThrown);
                    alert('Failed to load streams.');
                });
            }
        });

        // Handle Transcript Year and Form Selection (New)
        $('#transcriptYear, #transcriptClassId').on('change', function() {
            const year = $('#transcriptYear').val();
            const classId = $('#transcriptClassId').val();
            $('#transcriptTerm').html('<option value="">Select Term</option>').prop('disabled', true);
            $('#transcriptStreamId').html('<option value="">Select Stream</option><option value="0">All Streams</option>').prop('disabled', true);
            if (year && classId) {
                // Load terms
                $.post('cbcanalysis/functions.php', {
                    action: 'get_terms_for_class_and_year',
                    class_id: classId,
                    year: year
                }, function(response) {
                    console.log('Terms Response (Transcript):', response);
                    if (response.status === 'success') {
                        if (response.terms && response.terms.length > 0) {
                            response.terms.forEach(term => {
                                $('#transcriptTerm').append(`<option value="${term}">${term}</option>`);
                            });
                            $('#transcriptTerm').prop('disabled', false);
                        } else {
                            alert('No terms found for the selected form and year.');
                        }
                    } else {
                        alert('Error: ' + response.message);
                    }
                }, 'json').fail(function(jqXHR, textStatus, errorThrown) {
                    console.error('Failed to load terms:', textStatus, errorThrown);
                    alert('Failed to load terms.');
                });

                // Load streams
                $.post('cbcanalysis/functions.php', {
                    action: 'get_streams',
                    class_id: classId
                }, function(response) {
                    console.log('Streams Response (Transcript):', response);
                    if (response.status === 'success') {
                        if (response.streams && response.streams.length > 0) {
                            response.streams.forEach(stream => {
                                $('#transcriptStreamId').append(`<option value="${stream.stream_id}">${stream.stream_name}</option>`);
                            });
                            $('#transcriptStreamId').prop('disabled', false);
                        } else {
                            alert('No streams found for the selected form.');
                        }
                    } else {
                        alert('Error: ' + response.message);
                    }
                }, 'json').fail(function(jqXHR, textStatus, errorThrown) {
                    console.error('Failed to load streams:', textStatus, errorThrown);
                    alert('Failed to load streams.');
                });
            }
        });

        // Handle Transcript Generation (New)
        $('#generateTranscriptBtn').on('click', function() {
            const year = $('#transcriptYear').val();
            const term = $('#transcriptTerm').val();
            const classId = $('#transcriptClassId').val();
            const streamId = $('#transcriptStreamId').val();

            if (!year || !term || !classId || !streamId) {
                alert('Please select Year, Term, Form, and Stream.');
                return;
            }

            $.post('cbcanalysis/functions.php', {
                action: 'generate_transcript',
                year: year,
                term: term,
                class_id: classId,
                stream_id: streamId,
                school_id: <?php echo $school_id; ?>
            }, function(response) {
                console.log('Transcript Generation Response:', response);
                if (response.status === 'success') {
                    window.location.href = response.download_url;
                } else {
                    alert('Error: ' + response.message);
                }
            }, 'json').fail(function(jqXHR, textStatus, errorThrown) {
                console.error('Failed to generate transcript:', textStatus, errorThrown);
                alert('Failed to generate transcript.');
            });
        });

        // Handle Year Selection for School Grade Analysis
        $('#schoolYear').on('change', function() {
            const year = $(this).val();
            $('#schoolExamId').html('<option value="">Select Exam</option>').prop('disabled', true);
            $('#schoolTerm').html('<option value="">Select Term</option>').prop('disabled', true);
            $('#classCheckboxes').html('');
            if (year) {
                // Load exams for the selected year
                $.post('cbcanalysis/functions.php', {
                    action: 'get_exams_by_year',
                    year: year
                }, function(response) {
                    console.log('Exams Response (School Grade Analysis):', response);
                    if (response.status === 'success' && response.exams && response.exams.length > 0) {
                        response.exams.forEach(exam => {
                            $('#schoolExamId').append(`<option value="${exam.exam_name}" data-term="${exam.term}">${exam.exam_name}</option>`);
                        });
                        $('#schoolExamId').prop('disabled', false);
                    } else {
                        alert('No exams found for the selected year.');
                    }
                }, 'json').fail(function(jqXHR, textStatus, errorThrown) {
                    console.error('Failed to load exams (School Grade Analysis):', textStatus, errorThrown);
                    alert('Failed to load exams.');
                });
            }
        });

        // Handle Exam Selection for School Grade Analysis
        $('#schoolExamId').on('change', function() {
            const year = $('#schoolYear').val();
            const examName = $(this).val();
            const term = $(this).find('option:selected').data('term');
            $('#schoolTerm').html(`<option value="${term}">${term}</option>`).prop('disabled', false);
            $('#classCheckboxes').html('');
            if (year && examName && term) {
                // Load classes for the selected exam, year, and term
                $.post('cbcanalysis/functions.php', {
                    action: 'get_classes_by_exam',
                    year: year,
                    exam_name: examName,
                    term: term
                }, function(response) {
                    console.log('Classes Response (School Grade Analysis):', response);
                    if (response.status === 'success' && response.classes && response.classes.length > 0) {
                        const classOptions = response.classes.map(cls => `
                        <div class="form-check mb-2">
                            <input class="form-check-input class-checkbox" type="checkbox" name="class_ids[]" value="${cls.class_id}" id="class_${cls.class_id}">
                            <label class="form-check-label" for="class_${cls.class_id}">${cls.form_name}</label>
                        </div>
                    `).join('');
                        $('#classCheckboxes').html(classOptions);
                    } else {
                        alert('No classes found for the selected exam, year, and term.');
                    }
                }, 'json').fail(function(jqXHR, textStatus, errorThrown) {
                    console.error('Failed to load classes (School Grade Analysis):', textStatus, errorThrown);
                    alert('Failed to load classes.');
                });
            }
        });

        // Validate school grade analysis form before submission
        $('#schoolGradeAnalysisForm').on('submit', function(e) {
            const year = $('#schoolYear').val();
            const examName = $('#schoolExamId').val();
            const term = $('#schoolTerm').val();
            const classIds = $('input[name="class_ids[]"]:checked').length;
            if (!year || !examName || !term || classIds === 0) {
                e.preventDefault();
                alert('Please select year, exam, term, and at least one class.');
            }
        });

        // Handle Form Selection for class list
        // ────────────────────────────────────────────────
        // Class List modal – new logic (Form → Stream OR Custom Group)
        // ────────────────────────────────────────────────

        $('#classListClassId').on('change', function() {
            const classId = $(this).val();

            // Reset everything
            $('#classListStreamId')
                .html('<option value="">Select Stream (optional)</option>')
                .prop('disabled', true);
            $('#classListGroupId')
                .html('<option value="">Select Custom Group (optional)</option>')
                .prop('disabled', true);
            $('#generateClassListBtn').prop('disabled', true);

            if (!classId) return;

            // ────────────── Streams ──────────────
            $.post('cbcanalysis/functions.php', {
                action: 'get_streams',
                class_id: classId
            }, function(res) {
                console.log('Streams response:', res); // ← debug
                if (res.status === 'success' && res.streams?.length > 0) {
                    let html = '<option value="">Select Stream (optional)</option>';
                    res.streams.forEach(s => {
                        html += `<option value="${s.stream_id}">${s.stream_name}</option>`;
                    });
                    $('#classListStreamId').html(html).prop('disabled', false);
                } else {
                    $('#classListStreamId').html('<option value="">No streams found</option>').prop('disabled', true);
                }
                updateGenerateButton();
            }, 'json').fail(function(jqXHR, textStatus) {
                console.error('Streams AJAX failed:', textStatus);
                $('#classListStreamId').html('<option value="">Error loading streams</option>').prop('disabled', true);
                updateGenerateButton();
            });

            // ────────────── Custom Groups ──────────────
            $.post('cbcanalysis/functions.php', {
                action: 'get_custom_groups_for_class',
                class_id: classId
            }, function(res) {
                console.log('Custom Groups response:', res); // ← VERY IMPORTANT debug line

                if (res.status === 'success') {
                    if (res.groups?.length > 0) {
                        let html = '<option value="">Select Custom Group (optional)</option>';
                        res.groups.forEach(g => {
                            html += `<option value="${g.group_id}">${g.name}</option>`;
                        });
                        $('#classListGroupId').html(html).prop('disabled', false);
                    } else {
                        $('#classListGroupId').html('<option value="">No custom groups found for this form</option>').prop('disabled', true);
                    }
                } else {
                    console.warn('Custom groups failed:', res.message || 'Unknown error');
                    $('#classListGroupId').html('<option value="">Error loading groups</option>').prop('disabled', true);
                }
                updateGenerateButton();
            }, 'json').fail(function(jqXHR, textStatus, errorThrown) {
                console.error('Custom Groups AJAX failed:', textStatus, errorThrown);
                $('#classListGroupId').html('<option value="">Failed to load groups</option>').prop('disabled', true);
                updateGenerateButton();
            });
        });
        // Enable Generate button only when Form + (Stream OR Group) is selected
        function updateGenerateButton() {
            const hasForm = $('#classListClassId').val() !== '';
            const hasStream = $('#classListStreamId').val() !== '';
            const hasGroup = $('#classListGroupId').val() !== '';

            const valid = hasForm && (hasStream || hasGroup) && !(hasStream && hasGroup);

            $('#generateClassListBtn').prop('disabled', !valid);

            // Optional visual feedback
            if (hasStream && hasGroup) {
                $('#classListForm .alert').removeClass('alert-info').addClass('alert-warning')
                    .text('Please select either Stream or Custom Group — not both.');
            } else {
                $('#classListForm .alert').removeClass('alert-warning').addClass('alert-info')
                    .html('Select <strong>either</strong> a Stream <strong>or</strong> a Custom Group (not both).');
            }
        }

        $('#classListStreamId, #classListGroupId').on('change', updateGenerateButton);

        // Form validation (extra safety)
        $('#classListForm').on('submit', function(e) {
            const classId = $('#classListClassId').val();
            const streamId = $('#classListStreamId').val();
            const groupId = $('#classListGroupId').val();

            if (!classId || (!streamId && !groupId) || (streamId && groupId)) {
                e.preventDefault();
                alert('Please select a Form and exactly one of: Stream or Custom Group.');
            }
        });

        // ─── Score Sheet (CBC) – supports Stream OR Custom Group ───────────────────────────────
        $('#scoreSheetClassId').on('change', function() {
            const classId = $(this).val() || '';

            // Reset everything
            $('#scoreSheetStreamId, #scoreSheetGroupId, #scoreSheetSubjectId')
                .html('<option value="">Loading...</option>')
                .prop('disabled', true);
            $('#generateScoreSheetBtn').prop('disabled', true);
            $('#subjectRequired').text('*');
            $('#groupSubjectNote').addClass('d-none');

            if (!classId) return;

            // Load Streams
            $.post('cbcanalysis/functions.php', {
                action: 'get_streams',
                class_id: classId
            }, function(res) {
                let html = '<option value="">— All Streams in Form —</option>';
                if (res.status === 'success' && res.streams?.length) {
                    res.streams.forEach(s => html += `<option value="${s.stream_id}">${s.stream_name}</option>`);
                }
                $('#scoreSheetStreamId').html(html).prop('disabled', false);
                checkScoreSheetReady();
            }, 'json');

            // Load Custom Groups
            $.post('cbcanalysis/functions.php', {
                action: 'get_custom_groups_for_class',
                class_id: classId
            }, function(res) {
                let html = '<option value="">— Select Group (alternative) —</option>';
                if (res.status === 'success' && res.groups?.length) {
                    res.groups.forEach(g => html += `<option value="${g.group_id}">${g.name}</option>`);
                }
                $('#scoreSheetGroupId').html(html).prop('disabled', false);
                checkScoreSheetReady();
            }, 'json');

            // Load Subjects (manual fallback)
            $.post('cbcanalysis/functions.php', {
                action: 'get_subjects_for_class',
                class_id: classId
            }, function(res) {
                let html = '<option value="">Select Subject</option>';
                if (res.status === 'success' && res.subjects?.length) {
                    res.subjects.forEach(s => html += `<option value="${s.subject_id}">${s.name}</option>`);
                }
                $('#scoreSheetSubjectId').html(html).prop('disabled', false);
                checkScoreSheetReady();
            }, 'json');
        });

        // When Stream or Group changes → mutual exclusivity + group auto-subject
        $('#scoreSheetStreamId, #scoreSheetGroupId').on('change', function() {
            const $this = $(this);
            const other = $this.is('#scoreSheetStreamId') ? '#scoreSheetGroupId' : '#scoreSheetStreamId';

            if ($this.val()) {
                $(other).prop('disabled', true).val('');

                // Group selected → lock subject + load group's subjects
                if ($this.is('#scoreSheetGroupId')) {
                    $('#scoreSheetSubjectId').prop('disabled', true);
                    $('#subjectRequired').text('');
                    $('#groupSubjectNote').removeClass('d-none');

                    $.post('cbcanalysis/functions.php', {
                        action: 'get_subjects_for_group',
                        group_id: $this.val()
                    }, function(res) {
                        if (res.status !== 'success' || !res.subjects?.length) {
                            $('#scoreSheetSubjectId').html('<option>No subjects in group</option>');
                            checkScoreSheetReady();
                            return;
                        }

                        let html = '<option value="">Select Subject</option>';
                        res.subjects.forEach(s => {
                            html += `<option value="${s.subject_id}">${s.name}</option>`;
                        });
                        $('#scoreSheetSubjectId').html(html);

                        // Auto-select if exactly ONE subject in group
                        if (res.subjects.length === 1) {
                            const singleId = res.subjects[0].subject_id;
                            $('#scoreSheetSubjectId').val(singleId).trigger('change');
                        }

                        checkScoreSheetReady();
                    }, 'json');
                }
            } else {
                // Cleared → re-enable the other
                $(other).prop('disabled', false);
                if ($this.is('#scoreSheetGroupId')) {
                    $('#scoreSheetSubjectId').prop('disabled', false);
                    $('#subjectRequired').text('*');
                    $('#groupSubjectNote').addClass('d-none');
                }
            }

            checkScoreSheetReady();
        });

        // Subject change also triggers check
        $('#scoreSheetSubjectId').on('change', checkScoreSheetReady);

        // ─── Button enabled only when valid ────────────────────────────────────────
        function checkScoreSheetReady() {
            const classId = $('#scoreSheetClassId').val()?.trim() || '';
            const streamId = $('#scoreSheetStreamId').val()?.trim() || '';
            const groupId = $('#scoreSheetGroupId').val()?.trim() || '';
            const subjectId = $('#scoreSheetSubjectId').val()?.trim() || '';

            const subjDisabled = $('#scoreSheetSubjectId').prop('disabled');

            const usingGroup = !!groupId;
            const usingStream = !!streamId && !usingGroup;

            let subjectOk = false;

            if (usingGroup && subjDisabled) {
                // Group mode: subject should be pre-filled
                if (subjectId && subjectId !== '' && subjectId !== '0') {
                    subjectOk = true;
                } else {
                    const selectedOpt = $('#scoreSheetSubjectId option:selected');
                    if (selectedOpt.length === 1) {
                        const v = selectedOpt.val()?.trim();
                        subjectOk = (v && v !== '' && v !== '0');
                    }
                }
            } else {
                // Stream mode: must manually pick subject
                subjectOk = (subjectId && subjectId !== '' && subjectId !== '0');
            }

            const isReady = !!classId && (usingStream || usingGroup) && subjectOk;

            $('#generateScoreSheetBtn')
                .prop('disabled', !isReady)
                .toggleClass('btn-success', isReady)
                .toggleClass('btn-secondary', !isReady);
        }
    });

    // Show Custom Group Performance modal
    $('#customGroupPerformanceBtn').on('click', function() {
        $('#customGroupPerformanceModal').modal('show');
    });

    // Handle Year + Form change → load Exams → load Groups
    $('#cgpYear, #cgpClassId').on('change', function() {
        const year = $('#cgpYear').val();
        const classId = $('#cgpClassId').val();

        $('#cgpExamId').html('<option value="">Loading...</option>').prop('disabled', true);
        $('#cgpGroupId').html('<option value="">Loading...</option>').prop('disabled', true);
        $('#generateCustomGroupBtn').prop('disabled', true);

        if (!year || !classId) return;

        // Load exams (similar to report card logic)
        $.post('cbcanalysis/functions.php', {
            action: 'get_exams_for_class_and_year',
            class_id: classId,
            year: year
        }, function(response) {
            if (response.status === 'success' && response.exams?.length > 0) {
                $('#cgpExamId').html('<option value="">Select Exam</option>');
                response.exams.forEach(exam => {
                    $('#cgpExamId').append(`<option value="${exam.exam_id}">${exam.exam_name}</option>`);
                });
                $('#cgpExamId').prop('disabled', false);
            } else {
                $('#cgpExamId').html('<option value="">No exams found</option>');
            }
        }, 'json');

        // Load custom groups for this class
        $.post('cbcanalysis/functions.php', {
            action: 'get_custom_groups_for_class',
            class_id: classId
        }, function(res) {
            if (res.status === 'success' && res.groups?.length > 0) {
                $('#cgpGroupId').html('<option value="">Select Group</option>');
                res.groups.forEach(g => {
                    $('#cgpGroupId').append(`<option value="${g.group_id}">${g.name}</option>`);
                });
                $('#cgpGroupId').prop('disabled', false);
            } else {
                $('#cgpGroupId').html('<option value="">No groups found</option>');
            }
        }, 'json');
    });

    // Enable Generate button only when all required fields are selected
    $('#cgpYear, #cgpClassId, #cgpExamId, #cgpGroupId').on('change', function() {
        const allFilled = $('#cgpYear').val() && $('#cgpClassId').val() && $('#cgpExamId').val() && $('#cgpGroupId').val();
        $('#generateCustomGroupBtn').prop('disabled', !allFilled);
    });

    // ────────────────────────────────────────────────
    // Load Dormitories & Houses when Form is selected (CBC View Results)
    // ────────────────────────────────────────────────
    $('#analysisClassId').on('change', function() {
        const classId = $(this).val();

        // Reset & disable optional filters
        $('#analysisStreamId, #analysisDormitoryId, #analysisHouseId')
            .html('<option value="">Loading...</option>')
            .prop('disabled', true);

        if (!classId) return;

        // 1. Streams (already existing)
        $.post('cbcanalysis/functions.php', {
            action: 'get_streams',
            class_id: classId
        }, function(res) {
            let html = '<option value="">— All Streams —</option>';
            if (res.status === 'success' && res.streams?.length) {
                res.streams.forEach(s => {
                    html += `<option value="${s.stream_id}">${s.stream_name}</option>`;
                });
            }
            $('#analysisStreamId').html(html).prop('disabled', false);
        }, 'json');

        // 2. Dormitories (new - class-specific or school-wide)
        $.post('cbcanalysis/functions.php', {
            action: 'get_dormitories',
            class_id: classId // optional - remove if dorms are school-wide
        }, function(res) {
            let html = '<option value="">— Any / All dorms —</option>';
            if (res.status === 'success' && res.dormitories?.length) {
                res.dormitories.forEach(d => {
                    html += `<option value="${d.dormitory_id}">${d.name}</option>`;
                });
            }
            $('#analysisDormitoryId').html(html).prop('disabled', false);
        }, 'json');

        // 3. Houses (new - school-wide)
        $.post('cbcanalysis/functions.php', {
            action: 'get_houses'
        }, function(res) {
            let html = '<option value="">— Any / All houses —</option>';
            if (res.status === 'success' && res.houses?.length) {
                res.houses.forEach(h => {
                    html += `<option value="${h.house_id}">${h.name}</option>`;
                });
            }
            $('#analysisHouseId').html(html).prop('disabled', false);
        }, 'json');
    });

    // ────────────────────────────────────────────────
    // Mutual exclusivity: Stream ↔ Dorm ↔ House
    // ────────────────────────────────────────────────
    $('#analysisStreamId, #analysisDormitoryId, #analysisHouseId').on('change', function() {
        const $this = $(this);
        const val = $this.val();
        const others = '#analysisStreamId, #analysisDormitoryId, #analysisHouseId';

        if (val) {
            $(others).not($this).prop('disabled', true).val('');
        } else {
            $(others).prop('disabled', false);
        }
    });

    // ────────────────────────────────────────────────
    // Warn if multiple filters are somehow selected
    // ────────────────────────────────────────────────
    $('#loadAnalysisBtn').on('click', function(e) {
        const streamId = $('#analysisStreamId').val();
        const dormId = $('#analysisDormitoryId').val();
        const houseId = $('#analysisHouseId').val();

        const filters = [streamId, dormId, houseId].filter(v => v && v !== '');
        if (filters.length > 1) {
            if (!confirm("You selected multiple filters (stream/dorm/house).\nOnly one will be prioritized.\nContinue anyway?")) {
                e.preventDefault();
                return false;
            }
        }
    });
</script>

<?php include __DIR__ . '/../footer.php'; ?>