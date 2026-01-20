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
$stmt = $conn->prepare("
    SELECT class_id, form_name 
    FROM classes 
    WHERE school_id = ? 
      AND is_cbc = 0         
    ORDER BY form_name
");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

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
                <i class="bi bi-bar-chart me-2"></i> Results Analysis
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
                <div class="col-md-3">
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
                </div>

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
            </div>
            <!-- Custom Group Results Card -->
            <div class="col-md-3">
                <div class="card shadow-sm border-0 h-100 text-center">
                    <div class="card-body d-flex flex-column justify-content-center">
                        <i class="bi bi-people-fill display-5 text-primary"></i>
                        <h5 class="mt-3">Custom Group Results</h5>
                        <p class="text-muted">View subject scores & ranks for students in a custom group.</p>
                        <button class="btn btn-primary mt-auto" id="customGroupResultsBtn">
                            <i class="bi bi-eye me-2"></i> View Group Report
                        </button>
                    </div>
                </div>
            </div>
            <!-- View Results Modal -->
            <div class="modal fade" id="resultsModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title d-flex align-items-center">
                                <i class="bi bi-list-ul me-2"></i> Select Form, Term, Exam, and Stream
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <form id="analysisForm" action="reports/examreports/meritlist.php" method="get">
                                <div class="row g-3 mb-3">
                                    <div class="col-md-3">
                                        <label class="form-label">Form</label>
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
                                        <label class="form-label">Term</label>
                                        <select class="form-select" id="analysisTerm" name="term" disabled required>
                                            <option value="">Select Term</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Exam</label>
                                        <select class="form-select" id="analysisExamId" name="exam_id" disabled required>
                                            <option value="">Select Exam</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Stream</label>
                                        <select class="form-select" id="analysisStreamId" name="stream_id" disabled required>
                                            <option value="">Select Stream</option>
                                            <option value="0">All Streams</option>
                                        </select>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary" id="loadAnalysisBtn">Load Merit List</button>
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
                            <form id="reportCardForm" action="reports/reportcard.php" method="post">
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
                            <form id="subjectAnalysisForm" action="reports/examreports/subjectanalysisreport_exam.php" method="post">
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
                            <form id="performanceForm" action="reports/examreports/countbyperfomance_perexam.php" method="post">
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
                            <form id="reportCardFormNew" action="reports/examreports/ExamReport.php" method="get">
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
                            <form id="schoolGradeAnalysisForm" action="reports/examreports/schoolanalysis_perexam.php" method="post">
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
                                <i class="bi bi-list-check me-2"></i> Select Form, Stream, and Subject
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <form id="classListForm" action="reports/examreports/classlist.php" method="post">
                                <div class="row g-3 mb-3">
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
                                    <div class="col-md-4">
                                        <label class="form-label">Stream</label>
                                        <select class="form-select" id="classListStreamId" name="stream_id" disabled required>
                                            <option value="">Select Stream</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Subject</label>
                                        <select class="form-select" id="classListSubjectId" name="subject_id" disabled required>
                                            <option value="">Select Subject</option>
                                        </select>
                                    </div>
                                </div>
                                <input type="hidden" name="school_id" value="<?php echo htmlspecialchars($school_id); ?>">
                                <button type="submit" class="btn btn-primary" id="generateClassListBtn">Generate Class List</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Score Sheet Modal -->
            <div class="modal fade" id="scoreSheetModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title d-flex align-items-center">
                                <i class="bi bi-file-earmark-text me-2"></i> Select Form, Stream, and Subject
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <form id="scoreSheetForm" action="reports/examreports/scoresheet.php" method="post">
                                <div class="row g-3 mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Form</label>
                                        <select class="form-select" id="scoreSheetClassId" name="class_id" required>
                                            <option value="">Select Form</option>
                                            <?php foreach ($classes as $class): ?>
                                                <option value="<?php echo $class['class_id']; ?>">
                                                    <?php echo htmlspecialchars($class['form_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Stream</label>
                                        <select class="form-select" id="scoreSheetStreamId" name="stream_id" disabled required>
                                            <option value="">Select Stream</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Subject</label>
                                        <select class="form-select" id="scoreSheetSubjectId" name="subject_id" disabled required>
                                            <option value="">Select Subject</option>
                                        </select>
                                    </div>
                                </div>
                                <input type="hidden" name="school_id" value="<?php echo htmlspecialchars($school_id); ?>">
                                <button type="submit" class="btn btn-primary" id="generateScoreSheetBtn">Generate Score Sheet</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- Modal: Select Group & Exam (then opens report directly) -->
<div class="modal fade" id="customGroupSelectModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-people me-2"></i> Select Group & Exam</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="groupReportForm">
                    <div class="mb-3">
                        <label class="form-label">Custom Group</label>
                        <select class="form-select" id="groupSelect" required>
                            <option value="">-- Select Group --</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Exam</label>
                        <select class="form-select" id="examSelect" required>
                            <option value="">-- Select Exam --</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Open Report</button>
                </form>
            </div>
        </div>
    </div>
</div>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    function ajaxDebug(options) {
        console.log('AJAX Request:', options.url, options.data || {});

        $.ajax({
            url: options.url,
            method: options.method || 'POST',
            data: options.data || {},
            dataType: 'json',
            success: function(response) {
                console.log('Response:', response);
                if (options.onSuccess) options.onSuccess(response);
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', status, error, xhr.responseText);
                alert('Request failed. Check console (F12) for details.');
            }
        });
    }
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
                $.post('reports/functions.php', {
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
                $.post('reports/functions.php', {
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
                $.post('reports/functions.php', {
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
        $('#loadAnalysisBtn').on('click', function(e) {
            const classId = $('#analysisClassId').val();
            const term = $('#analysisTerm').val();
            const examId = $('#analysisExamId').val();
            const streamId = $('#analysisStreamId').val();
            if (!classId || !term || !examId || !streamId) {
                e.preventDefault();
                alert('Please select form, term, exam, and stream.');
            }
            // Let the form submit naturally to meritlist.php
        });

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
                $.post('reports/functions.php', {
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
                $.post('reports/functions.php', {
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
                $.post('reports/functions.php', {
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
                $.post('reports/functions.php', {
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
                $.post('reports/functions.php', {
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
                $.post('reports/functions.php', {
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
                $.post('reports/functions.php', {
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
                $.post('reports/functions.php', {
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
                $.post('reports/functions.php', {
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
                $.post('reports/functions.php', {
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
                $.post('reports/functions.php', {
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
                $.post('reports/functions.php', {
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

            $.post('reports/functions.php', {
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
                $.post('reports/functions.php', {
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
                $.post('reports/functions.php', {
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
        $('#classListClassId').on('change', function() {
            const classId = $(this).val();
            $('#classListStreamId').html('<option value="">Select Stream</option>').prop('disabled', true);
            $('#classListSubjectId').html('<option value="">Select Subject</option>').prop('disabled', true);
            if (classId) {
                // Load streams
                $.post('reports/functions.php', {
                    action: 'get_streams',
                    class_id: classId
                }, function(response) {
                    console.log('Streams Response (Class List):', response);
                    if (response.status === 'success' && response.streams && response.streams.length > 0) {
                        response.streams.forEach(stream => {
                            $('#classListStreamId').append(`<option value="${stream.stream_id}">${stream.stream_name}</option>`);
                        });
                        $('#classListStreamId').prop('disabled', false);
                    } else {
                        alert('No streams found for the selected form.');
                    }
                }, 'json').fail(function(jqXHR, textStatus, errorThrown) {
                    console.error('Failed to load streams:', textStatus, errorThrown);
                    alert('Failed to load streams.');
                });

                // Load subjects
                $.post('reports/functions.php', {
                    action: 'get_subjects_for_class',
                    class_id: classId
                }, function(response) {
                    console.log('Subjects Response (Class List):', response);
                    if (response.status === 'success' && response.subjects && response.subjects.length > 0) {
                        response.subjects.forEach(subject => {
                            $('#classListSubjectId').append(`<option value="${subject.subject_id}">${subject.name}</option>`);
                        });
                        $('#classListSubjectId').prop('disabled', false);
                    } else {
                        alert('No subjects found for the selected form.');
                    }
                }, 'json').fail(function(jqXHR, textStatus, errorThrown) {
                    console.error('Failed to load subjects:', textStatus, errorThrown);
                    alert('Failed to load subjects.');
                });
            }
        });

        // Validate class list form before submission
        $('#classListForm').on('submit', function(e) {
            const classId = $('#classListClassId').val();
            const streamId = $('#classListStreamId').val();
            const subjectId = $('#classListSubjectId').val();
            if (!classId || !streamId || !subjectId) {
                e.preventDefault();
                alert('Please select form, stream, and subject.');
            }
        });

        // Handle Form Selection for score sheet
        $('#scoreSheetClassId').on('change', function() {
            const classId = $(this).val();
            $('#scoreSheetStreamId').html('<option value="">Select Stream</option>').prop('disabled', true);
            $('#scoreSheetSubjectId').html('<option value="">Select Subject</option>').prop('disabled', true);
            if (classId) {
                // Load streams
                $.post('reports/functions.php', {
                    action: 'get_streams',
                    class_id: classId
                }, function(response) {
                    console.log('Streams Response (Score Sheet):', response);
                    if (response.status === 'success' && response.streams && response.streams.length > 0) {
                        response.streams.forEach(stream => {
                            $('#scoreSheetStreamId').append(`<option value="${stream.stream_id}">${stream.stream_name}</option>`);
                        });
                        $('#scoreSheetStreamId').prop('disabled', false);
                    } else {
                        alert('No streams found for the selected form.');
                    }
                }, 'json').fail(function(jqXHR, textStatus, errorThrown) {
                    console.error('Failed to load streams:', textStatus, errorThrown);
                    alert('Failed to load streams.');
                });

                // Load subjects
                $.post('reports/functions.php', {
                    action: 'get_subjects_for_class',
                    class_id: classId
                }, function(response) {
                    console.log('Subjects Response (Score Sheet):', response);
                    if (response.status === 'success' && response.subjects && response.subjects.length > 0) {
                        response.subjects.forEach(subject => {
                            $('#scoreSheetSubjectId').append(`<option value="${subject.subject_id}">${subject.name}</option>`);
                        });
                        $('#scoreSheetSubjectId').prop('disabled', false);
                    } else {
                        alert('No subjects found for the selected form.');
                    }
                }, 'json').fail(function(jqXHR, textStatus, errorThrown) {
                    console.error('Failed to load subjects:', textStatus, errorThrown);
                    alert('Failed to load subjects.');
                });
            }
        });

        // Validate score sheet form before submission
        $('#scoreSheetForm').on('submit', function(e) {
            const classId = $('#scoreSheetClassId').val();
            const streamId = $('#scoreSheetStreamId').val();
            const subjectId = $('#scoreSheetSubjectId').val();
            if (!classId || !streamId || !subjectId) {
                e.preventDefault();
                alert('Please select form, stream, and subject.');
            }
        });
    });

    // Open selection modal
    $('#customGroupResultsBtn').on('click', function() {
        ajaxDebug({
            url: 'reports/functions.php',
            method: 'POST',
            data: {
                action: 'get_custom_groups_and_exams'
            },
            onSuccess: function(json) {
                // Groups
                const $group = $('#groupSelect').empty().append('<option value="">-- Select Group --</option>');
                if (json.status === 'success' && json.groups?.length) {
                    json.groups.forEach(g => {
                        $group.append(`<option value="${g.group_id}">${g.name}</option>`);
                    });
                } else {
                    alert('No custom groups found.');
                }

                // Exams
                const $exam = $('#examSelect').empty().append('<option value="">-- Select Exam --</option>');
                if (json.status === 'success' && json.exams?.length) {
                    json.exams.forEach(e => {
                        $exam.append(`<option value="${e.exam_id}">${e.exam_name} (${e.term})</option>`);
                    });
                } else {
                    alert('No exams found.');
                }

                $('#customGroupSelectModal').modal('show');
            }
        });
    });

    // Submit → open report directly in new tab
    $('#groupReportForm').on('submit', function(e) {
        e.preventDefault();

        const groupId = $('#groupSelect').val();
        const examId = $('#examSelect').val();

        if (!groupId || !examId) {
            alert('Please select a group and an exam.');
            return;
        }

        // Directly open the report file
        window.location.href = `reports/examreports/CustomGroupReport.php?group_id=${groupId}&exam_id=${examId}`;

        // Close selection modal
        $('#customGroupSelectModal').modal('hide');
    });
</script>

<?php include __DIR__ . '/../footer.php'; ?>