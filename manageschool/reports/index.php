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

        </div> <!-- ← this closes the first row (8 cards) -->

        <!-- SECOND ROW - School Mean + Custom Group (perfectly aligned) -->
        <div class="row g-4 mb-4">
            <!-- School Mean Grade Analysis -->
            <div class="col-md-3">
                <div class="card shadow-sm border-0 h-100 text-center">
                    <div class="card-body d-flex flex-column justify-content-center">
                        <i class="bi bi-graph-up display-5 text-primary"></i>
                        <h5 class="mt-3">School Mean Grade Analysis</h5>
                        <p class="text-muted">Overall grade distribution & mean point for the whole school (8-4-4 only).</p>
                        <button class="btn btn-primary mt-auto" id="schoolMeanBtn">
                            <i class="bi bi-bar-chart-fill me-2"></i> View School Mean
                        </button>
                    </div>
                </div>
            </div>

            <!-- Multi-Exam Stream Rank -->
            <div class="col-md-3">
                <div class="card shadow-sm border-0 h-100 text-center">
                    <div class="card-body d-flex flex-column justify-content-center">
                        <i class="bi bi-trophy-fill display-5 text-primary"></i>
                        <h5 class="mt-3">Class Rank</h5>
                        <p class="text-muted">Rank streams</p>
                        <button class="btn btn-primary mt-auto" id="multiExamStreamBtn">
                            <i class="bi bi-bar-chart-fill me-2"></i> View Exam Rank
                        </button>
                    </div>
                </div>
            </div>

            <!-- Form Rank Report -->
            <div class="col-md-3">
                <div class="card shadow-sm border-0 h-100 text-center">
                    <div class="card-body d-flex flex-column justify-content-center">
                        <i class="bi bi-trophy display-5 text-primary"></i>
                        <h5 class="mt-3">Class Rank Report</h5>
                        <p class="text-muted">Rank entire Forms across exams .</p>
                        <button class="btn btn-primary mt-auto" id="formRankBtn">
                            <i class="bi bi-bar-chart-fill me-2"></i> View Class Rank
                        </button>
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
        </div>

        <div class="row g-4 mb-4 justify-content-start">
            <!-- Class Subject Grade Analysis -->
            <div class="col-md-4">
                <div class="card shadow-sm border-0 h-100 text-center">
                    <div class="card-body d-flex flex-column justify-content-center">
                        <i class="bi bi-table display-5 text-primary"></i>
                        <h5 class="mt-3">Class Subject Grade Analysis</h5>
                        <p class="text-muted">Grade distribution per subject & stream in a class.</p>
                        <button class="btn btn-primary mt-auto" id="classSubjectGradeBtn">
                            <i class="bi bi-bar-chart-fill me-2"></i> View Class Subject Analysis
                        </button>
                    </div>
                </div>
            </div>

            <!-- House Rank Analysis -->
            <div class="col-md-4">
                <div class="card shadow-sm border-0 h-100 text-center">
                    <div class="card-body d-flex flex-column justify-content-center">
                        <i class="bi bi-house-door-fill display-5 text-primary"></i>
                        <h5 class="mt-3">House Rank Analysis</h5>
                        <p class="text-muted">Rank houses based on mean points across selected exams</p>
                        <button class="btn btn-primary mt-auto" id="houseRankBtn">
                            <i class="bi bi-trophy-fill me-2"></i> View House Rank
                        </button>
                    </div>
                </div>
            </div>

            <!-- Optional: empty space on the right (or add more cards later) -->
            <!-- <div class="col-md-4"></div> -->
        </div>


    </div>
    <div class="modal fade" id="resultsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title d-flex align-items-center">
                        <i class="bi bi-list-ul me-2"></i> Select Form, Term, Exam + Optional Group Filter
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="analysisForm" action="reports/examreports/meritlist.php" method="get">
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
                                <label class="form-label">Stream</label>
                                <select class="form-select" id="analysisStreamId" name="stream_id" disabled>
                                    <option value="">All Streams</option>
                                    <!-- Streams loaded dynamically -->
                                </select>
                            </div>
                        </div>

                        <!-- NEW Optional Grouping Filter Row -->
                        <div class="row g-3 mb-3 border-top pt-3">
                            <div class="col-12">
                                <label class="form-label fw-bold text-muted">
                                    <i class="bi bi-filter-circle me-1"></i> Filter View results for specific group
                                </label>
                            </div>

                            <!-- Dormitory -->
                            <div class="col-md-4">
                                <label class="form-label">Dormitory</label>
                                <select class="form-select" id="analysisDormitoryId" name="dormitory_id" disabled>
                                    <option value="">— Any / All dorms —</option>
                                    <!-- Populated dynamically -->
                                </select>
                            </div>

                            <!-- House -->
                            <div class="col-md-4">
                                <label class="form-label">House</label>
                                <select class="form-select" id="analysisHouseId" name="house_id" disabled>
                                    <option value="">— Any / All houses —</option>
                                    <!-- Populated dynamically -->
                                </select>
                            </div>

                            <!-- Note: Stream is already above – kept for consistency -->
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
                                <select class="form-select" id="schoolExamId" name="exam_id" disabled required>
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

    <!-- Class Subject Grade Analysis Modal (UPDATED ORDER) -->
    <div class="modal fade" id="classSubjectGradeModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background:#0d47a1;color:#fff;">
                    <h5 class="modal-title"><i class="bi bi-table me-2"></i> Class Subject Grade Analysis</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="classSubjectGradeForm" action="reports/examreports/classsubjectgradeanalysis.php" method="get">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Year <span class="text-danger">*</span></label>
                                <select class="form-select" id="csgYear" name="year" required>
                                    <option value="">Select Year</option>
                                    <?php foreach ($years as $y): ?>
                                        <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Term <span class="text-danger">*</span></label>
                                <select class="form-select" id="csgTerm" name="term" disabled required>
                                    <option value="">Select Term</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Class <span class="text-danger">*</span></label>
                                <select class="form-select" id="csgClassId" name="class_id" disabled required>
                                    <option value="">Select Class</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Exam <span class="text-danger">*</span></label>
                                <select class="form-select" id="csgExamId" name="exam_id" disabled required>
                                    <option value="">Select Exam</option>
                                </select>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 mt-3" id="generateClassSubjectGradeBtn" disabled>
                            Generate Class Subject Grade Analysis
                        </button>
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
                        <i class="bi bi-list-check me-2"></i> Generate Class List
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="classListForm" action="reports/examreports/classlist.php" method="post">
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Form <span class="text-danger">*</span></label>
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
                                <label class="form-label">Stream (optional)</label>
                                <select class="form-select" id="classListStreamId" name="stream_id" disabled>
                                    <option value="">— All Streams in Form —</option>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">Custom Group (optional)</label>
                                <select class="form-select" id="classListGroupId" name="group_id" disabled>
                                    <option value="">— Select Group (alternative to Stream) —</option>
                                </select>
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-12">
                                <label class="form-label">Subject (optional)</label>
                                <select class="form-select" id="classListSubjectId" name="subject_id" disabled>
                                    <option value="">— Any / No specific subject —</option>
                                </select>
                            </div>
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary" id="generateClassListBtn" disabled>
                                Generate Class List
                            </button>
                        </div>

                        <input type="hidden" name="school_id" value="<?php echo htmlspecialchars($school_id); ?>">
                    </form>

                    <small class="text-muted d-block mt-3">
                        Choose <strong>either Stream</strong> or <strong>Custom Group</strong> (not both).<br>
                        Subject is optional — leave blank for a general class list.
                    </small>
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
                        <i class="bi bi-file-earmark-text me-2"></i> Generate Score Sheet
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="scoreSheetForm" action="reports/examreports/scoresheet.php" method="post">
                        <div class="row g-3 mb-3">
                            <!-- Form (required) -->
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

                            <!-- Stream (optional) -->
                            <div class="col-md-4">
                                <label class="form-label">Stream (optional)</label>
                                <select class="form-select" id="scoreSheetStreamId" name="stream_id">
                                    <option value="">— All Streams in Form —</option>
                                    <!-- Filled dynamically -->
                                </select>
                            </div>

                            <!-- Custom Group (optional, alternative to Stream) -->
                            <div class="col-md-4">
                                <label class="form-label">Custom Group (optional)</label>
                                <select class="form-select" id="scoreSheetGroupId" name="group_id">
                                    <option value="">— Select Group (alternative to Stream) —</option>
                                    <!-- Filled dynamically -->
                                </select>
                            </div>
                        </div>

                        <!-- Subject (auto-filled when group selected, otherwise manual) -->
                        <div class="row g-3 mb-3">
                            <div class="col-md-12">
                                <label class="form-label">Subject <span id="subjectRequired" class="text-danger">*</span></label>
                                <select class="form-select" id="scoreSheetSubjectId" name="subject_id">
                                    <option value="">Select Subject</option>
                                    <!-- Filled dynamically -->
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
                        Subject is required unless a Custom Group is selected (then it auto-loads).
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>
</div>
</div>
<!-- Multi-Exam Stream Rank Modal -->
<div class="modal fade" id="multiExamStreamModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background:#0d47a1;color:#fff;">
                <h5 class="modal-title"><i class="bi bi-trophy-fill me-2"></i> Multi-Exam Stream Rank</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="multiExamStreamForm" action="reports/examreports/multiexamstreamrank.php" method="post">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label>Year <span class="text-danger">*</span></label>
                            <select class="form-select" id="multiYear" name="year" required>
                                <option value="">Select Year</option>
                                <?php foreach ($years as $y): ?>
                                    <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label>Term <span class="text-danger">*</span></label>
                            <select class="form-select" id="multiTerm" name="term" disabled required>
                                <option value="">Select Term</option>
                            </select>
                        </div>
                    </div>

                    <div id="examCheckboxes" class="mt-3" style="max-height:250px;overflow-y:auto;"></div>

                    <button type="submit" class="btn btn-primary w-100 mt-3" id="generateMultiRankBtn" disabled>
                        Generate Multi-Exam Stream Rank
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- School Mean Modal -->
<div class="modal fade" id="schoolMeanModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background-color: #0d47a1; color: #fff;">
                <h5 class="modal-title"><i class="bi bi-bar-chart-fill me-2"></i> School Mean Grade Analysis</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="schoolMeanForm" action="reports/examreports/schoolmeanreport.php" method="get">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Year <span class="text-danger">*</span></label>
                            <select class="form-select" id="meanYear" name="year" required>
                                <option value="">Select Year</option>
                                <?php foreach ($years as $y): ?>
                                    <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Exam <span class="text-danger">*</span></label>
                            <select class="form-select" id="meanExamId" name="exam_id" disabled required>
                                <option value="">Select Exam</option>
                            </select>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 mt-3" id="generateSchoolMeanBtn" disabled>
                        Generate School Mean Report
                    </button>
                </form>
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


<!-- Form Rank Modal -->
<div class="modal fade" id="formRankModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background:#0d47a1;color:#fff;">
                <h5 class="modal-title"><i class="bi bi-trophy me-2"></i> Form Rank Report</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formRankForm" action="reports/examreports/formrankreport.php" method="post">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Year <span class="text-danger">*</span></label>
                            <select class="form-select" id="formRankYear" name="year" required>
                                <option value="">Select Year</option>
                                <?php foreach ($years as $y): ?>
                                    <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Term <span class="text-danger">*</span></label>
                            <select class="form-select" id="formRankTerm" name="term" disabled required>
                                <option value="">Select Term</option>
                            </select>
                        </div>
                    </div>

                    <div id="formRankExamCheckboxes" class="mt-3" style="max-height:280px;overflow-y:auto;"></div>

                    <button type="submit" class="btn btn-primary w-100 mt-3" id="generateFormRankBtn" disabled>
                        Generate Form Rank Report
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- House Rank Modal -->
<div class="modal fade" id="houseRankModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background:#0d47a1;color:#fff;">
                <h5 class="modal-title">
                    <i class="bi bi-house-door-fill me-2"></i> House Rank Analysis
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="houseRankForm" action="reports/examreports/houserank.php" method="post">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Year <span class="text-danger">*</span></label>
                            <select class="form-select" id="houseYear" name="year" required>
                                <option value="">Select Year</option>
                                <?php foreach ($years as $y): ?>
                                    <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Term <span class="text-danger">*</span></label>
                            <select class="form-select" id="houseTerm" name="term" disabled required>
                                <option value="">Select Term</option>
                            </select>
                        </div>
                    </div>

                    <div id="houseExamCheckboxes" class="mt-3" style="max-height:250px;overflow-y:auto;"></div>

                    <button type="submit" class="btn btn-primary w-100 mt-3" id="generateHouseRankBtn" disabled>
                        Generate House Rank Report
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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

        $('#loadAnalysisBtn').on('click', function(e) {
            const classId = $('#analysisClassId').val();
            const term = $('#analysisTerm').val();
            const examId = $('#analysisExamId').val();

            if (!classId || !term || !examId) {
                e.preventDefault();
                alert('Please select Form, Term and Exam.');
                return;
            }

            // Optional: warn if more than one grouping filter is somehow selected
            const dormId = $('#analysisDormitoryId').val();
            const houseId = $('#analysisHouseId').val();
            const streamId = $('#analysisStreamId').val();

            const filters = [streamId, dormId, houseId].filter(v => v && v !== "");
            if (filters.length > 1) {
                if (!confirm("You selected more than one filter (stream/dorm/house).\nOnly one will be used.\nContinue anyway?")) {
                    e.preventDefault();
                    return;
                }
            }

            // Form will submit with whatever was chosen (stream can be empty)
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

        // Handle Transcript Year and Form Selection
        $('#transcriptYear, #transcriptClassId').on('change', function() {
            const year = $('#transcriptYear').val();
            const classId = $('#transcriptClassId').val();

            $('#transcriptTerm').html('<option value="">Select Term</option>').prop('disabled', true);
            $('#transcriptStreamId').html('<option value="">Select Stream</option>').prop('disabled', true);

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

                // Load streams (NO "All Streams" option)
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
                            $('#schoolExamId').append(`
                        <option value="${exam.exam_id}" data-term="${exam.term}">
                            ${exam.exam_name}
                        </option>
                    `);
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
            const examId = $(this).val(); // Now correct: numeric exam_id
            const term = $(this).find('option:selected').data('term');

            $('#schoolTerm').html(`<option value="${term}">${term}</option>`).prop('disabled', false);
            $('#classCheckboxes').html('');

            if (year && examId && term) {
                // Load classes using exam_id (better!)
                $.post('reports/functions.php', {
                    action: 'get_classes_by_exam',
                    year: year,
                    exam_id: examId, // ← send exam_id
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
                        $('#classCheckboxes').html('<p class="text-muted">No classes found.</p>');
                        alert('No classes found for the selected exam, year, and term.');
                    }
                }, 'json').fail(function(jqXHR, textStatus, errorThrown) {
                    console.error('Failed to load classes:', textStatus, errorThrown);
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

        // ─── Class List modal logic ────────────────────────────────────────────
        $('#classListClassId').on('change', function() {
            const classId = $(this).val();

            // Reset & disable
            $('#classListStreamId, #classListGroupId, #classListSubjectId')
                .html('<option value="">Loading...</option>')
                .prop('disabled', true);

            $('#generateClassListBtn').prop('disabled', !classId);

            if (!classId) return;

            // 1. Load Streams
            $.post('reports/functions.php', {
                action: 'get_streams',
                class_id: classId
            }, function(res) {
                let html = '<option value="">— All Streams in Form —</option>';
                if (res.status === 'success' && res.streams?.length) {
                    res.streams.forEach(s => {
                        html += `<option value="${s.stream_id}">${s.stream_name}</option>`;
                    });
                }
                $('#classListStreamId').html(html).prop('disabled', false);
            }, 'json');

            // 2. Load Custom Groups
            $.post('reports/functions.php', {
                action: 'get_custom_groups_for_class',
                class_id: classId
            }, function(res) {
                let html = '<option value="">— Select Group (alternative to Stream) —</option>';
                if (res.status === 'success' && res.groups?.length) {
                    res.groups.forEach(g => {
                        html += `<option value="${g.group_id}">${g.name}</option>`;
                    });
                }
                $('#classListGroupId').html(html).prop('disabled', false);
            }, 'json');

            // 3. Load Subjects
            $.post('reports/functions.php', {
                action: 'get_subjects_for_class',
                class_id: classId
            }, function(res) {
                let html = '<option value="">— Any / No specific subject —</option>';
                if (res.status === 'success' && res.subjects?.length) {
                    res.subjects.forEach(s => {
                        html += `<option value="${s.subject_id}">${s.name}</option>`;
                    });
                }
                $('#classListSubjectId').html(html).prop('disabled', false);
            }, 'json');
        });

        // Mutual exclusivity: Stream ↔ Custom Group
        $('#classListStreamId, #classListGroupId').on('change', function() {
            const $this = $(this);
            const other = $this.is('#classListStreamId') ? '#classListGroupId' : '#classListStreamId';

            if ($this.val()) {
                $(other).prop('disabled', true).val('');
            } else {
                $(other).prop('disabled', false);
            }
        });

        // Class List form validation
        $('#classListForm').on('submit', function(e) {
            const classId = $('#classListClassId').val();
            const streamId = $('#classListStreamId').val();
            const groupId = $('#classListGroupId').val();

            if (!classId) {
                e.preventDefault();
                alert('Please select a Form.');
                return;
            }

            if (streamId && groupId) {
                if (!confirm("Both Stream and Custom Group are selected.\nOnly one will be used.\nContinue?")) {
                    e.preventDefault();
                    return;
                }
            }
        });

        // ─── Score Sheet modal logic ───────────────────────────────────────────


        // ─── NEW Score Sheet modal logic (supports Stream OR Custom Group) ────────
        $('#scoreSheetBtn').on('click', function() {
            $('#scoreSheetModal').modal('show');
        });

        $('#scoreSheetClassId').on('change', function() {
            const classId = $(this).val();

            // Reset everything
            $('#scoreSheetStreamId, #scoreSheetGroupId, #scoreSheetSubjectId')
                .html('<option value="">Loading...</option>')
                .prop('disabled', true);

            $('#generateScoreSheetBtn').prop('disabled', true);
            $('#subjectRequired').text('*');
            $('#groupSubjectNote').addClass('d-none');

            if (!classId) return;

            // 1. Load Streams
            $.post('reports/functions.php', {
                action: 'get_streams',
                class_id: classId
            }, function(res) {
                let html = '<option value="">— All Streams in Form —</option>';
                if (res.status === 'success' && res.streams?.length) {
                    res.streams.forEach(s => {
                        html += `<option value="${s.stream_id}">${s.stream_name}</option>`;
                    });
                }
                $('#scoreSheetStreamId').html(html).prop('disabled', false);
            }, 'json');

            // 2. Load Custom Groups for this class
            $.post('reports/functions.php', {
                action: 'get_custom_groups_for_class',
                class_id: classId
            }, function(res) {
                let html = '<option value="">— Select Group (alternative to Stream) —</option>';
                if (res.status === 'success' && res.groups?.length) {
                    res.groups.forEach(g => {
                        html += `<option value="${g.group_id}">${g.name}</option>`;
                    });
                }
                $('#scoreSheetGroupId').html(html).prop('disabled', false);
            }, 'json');

            // 3. Load all Subjects for this class (manual selection fallback)
            $.post('reports/functions.php', {
                action: 'get_subjects_for_class',
                class_id: classId
            }, function(res) {
                let html = '<option value="">Select Subject</option>';
                if (res.status === 'success' && res.subjects?.length) {
                    res.subjects.forEach(s => {
                        html += `<option value="${s.subject_id}">${s.name}</option>`;
                    });
                }
                $('#scoreSheetSubjectId').html(html).prop('disabled', false);
            }, 'json');
        });

        // Mutual exclusivity: Stream ↔ Custom Group
        $('#scoreSheetStreamId, #scoreSheetGroupId').on('change', function() {
            const $this = $(this);
            const other = $this.is('#scoreSheetStreamId') ? '#scoreSheetGroupId' : '#scoreSheetStreamId';

            if ($this.val()) {
                // Disable + clear the other field
                $(other).prop('disabled', true).val('');

                // If Custom Group was selected → lock subject & auto-load group subjects
                if ($this.is('#scoreSheetGroupId')) {
                    $('#scoreSheetSubjectId').prop('disabled', true);
                    $('#subjectRequired').text(''); // remove red *
                    $('#groupSubjectNote').removeClass('d-none');

                    // Fetch group's subjects
                    $.post('reports/functions.php', {
                            action: 'get_subjects_for_group',
                            group_id: $this.val()
                        }, function(res) {
                            if (res.status === 'success' && res.subjects?.length) {

                                // Always include the placeholder option first
                                let html = '<option value="">Select Subject</option>';

                                if (res.subjects.length === 1) {
                                    // ── SINGLE SUBJECT CASE ── auto-select and FORCE the value
                                    const subj = res.subjects[0];
                                    html += `
                    <option value="${subj.subject_id}" selected="selected">
                        ${subj.name} (from group)
                    </option>
                `;

                                    // Replace content → THEN force selection (this is what was missing)
                                    $('#scoreSheetSubjectId')
                                        .html(html)
                                        .val(subj.subject_id) // ← Critical: force select this value
                                        .trigger('change'); // ← Let other code know it changed

                                    // Extra safety for browsers that ignore selected attribute
                                    $('#scoreSheetSubjectId option[value="' + subj.subject_id + '"]')
                                        .prop('selected', true);
                                } else {
                                    // ── MULTIPLE SUBJECTS ── let user choose
                                    res.subjects.forEach(s => {
                                        html += `<option value="${s.subject_id}">${s.name}</option>`;
                                    });
                                    $('#scoreSheetSubjectId')
                                        .html(html)
                                        .val(''); // no auto-selection when multiple
                                }

                                // Always re-check button readiness after changing the subject dropdown
                                checkScoreSheetReady();
                            } else {
                                // No subjects found
                                $('#scoreSheetSubjectId')
                                    .html('<option value="">No subjects found in group</option>')
                                    .val('');
                                checkScoreSheetReady();
                            }
                        }, 'json')
                        .fail(function(jqXHR, textStatus, errorThrown) {
                            console.error('Failed to load group subjects:', textStatus, errorThrown);
                            $('#scoreSheetSubjectId')
                                .html('<option value="">Error loading subjects</option>')
                                .val('');
                            checkScoreSheetReady();
                        });
                }
            } else {
                // Cleared → re-enable the other field
                $(other).prop('disabled', false);

                // If group was cleared → unlock subject
                if ($this.is('#scoreSheetGroupId')) {
                    $('#scoreSheetSubjectId').prop('disabled', false);
                    $('#subjectRequired').text('*');
                    $('#groupSubjectNote').addClass('d-none');
                }
            }

            // Enable Generate button only if valid
            checkScoreSheetReady();
        });

        // When subject changes manually, re-check
        $('#scoreSheetSubjectId').on('change', checkScoreSheetReady);

        /**
         * Checks whether all required fields are filled to enable the "Generate Score Sheet" button
         * Handles both normal (stream + manual subject) and custom group modes (locked subject)
         */
        function checkScoreSheetReady() {
            const classVal = $('#scoreSheetClassId').val() || '';
            const streamVal = $('#scoreSheetStreamId').val() || '';
            const groupVal = $('#scoreSheetGroupId').val() || '';
            let subjectVal = $('#scoreSheetSubjectId').val();

            const isSubjectDisabled = $('#scoreSheetSubjectId').prop('disabled');

            // ────────────────────────────────────────────────────────────────
            // Debug: always log raw state (very helpful when troubleshooting)
            // ────────────────────────────────────────────────────────────────
            console.log('[ScoreSheet Ready Check] Raw values:', {
                classId: classVal,
                streamId: streamVal,
                groupId: groupVal,
                subjectVal: subjectVal,
                subjectDisabled: isSubjectDisabled,
                selectedText: $('#scoreSheetSubjectId option:selected').text() || '(none)'
            });

            // ────────────────────────────────────────────────────────────────
            // GROUP MODE: subject dropdown is disabled / locked
            // ────────────────────────────────────────────────────────────────
            if (isSubjectDisabled && groupVal) {
                const $selected = $('#scoreSheetSubjectId option:selected');
                const numOptions = $('#scoreSheetSubjectId option').length;

                let isValidSubject = false;

                // Most common & safest case: group has exactly 1 subject → auto-selected
                if (numOptions === 1 && $selected.length === 1) {
                    const val = $selected.val();
                    isValidSubject = (val && val !== '' && val !== '0');
                }
                // Fallback: multiple subjects, but one is actually selected
                else if ($selected.length === 1) {
                    const val = $selected.val();
                    isValidSubject = (val && val !== '' && val !== '0');
                }
                // Rare bad case: no valid selection at all
                else {
                    isValidSubject = false;
                }

                // Force subjectVal to something truthy when we consider it valid
                if (isValidSubject) {
                    subjectVal = $selected.val() || 'GROUP_SUBJECT_VALID';
                } else {
                    subjectVal = '';
                }

                console.log('[ScoreSheet Group Mode]', {
                    numOptions,
                    selectedValue: $selected.val() || '(empty)',
                    selectedText: $selected.text() || '(no text)',
                    consideredValid: isValidSubject,
                    finalSubjectVal: subjectVal
                });
            }

            // ────────────────────────────────────────────────────────────────
            // Normal mode: subject must be manually selected
            // ────────────────────────────────────────────────────────────────
            else if (!isSubjectDisabled) {
                // Nothing special — just use whatever .val() gives us
                // (empty string / null / undefined → false)
            }

            // ────────────────────────────────────────────────────────────────
            // Final readiness decision
            // ────────────────────────────────────────────────────────────────
            const hasClass = !!classVal;
            const hasEither = !!(streamVal || groupVal);
            const hasSubject = !!subjectVal; // now reliable even in group mode

            const isReady = hasClass && hasEither && hasSubject;

            console.log('[ScoreSheet Final Decision]', {
                hasClass,
                hasEither,
                hasSubject,
                isReady,
                buttonWillBe: isReady ? 'ENABLED' : 'DISABLED'
            });

            // Apply to button
            $('#generateScoreSheetBtn').prop('disabled', !isReady);
        }
        // ─── Custom Group Report modal open ────────────────────────────────────
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

        // ─── Analysis modal: load dorms/houses when class changes ──────────────
        $('#analysisClassId').on('change', function() {
            const classId = $(this).val();

            // Reset & disable all optional filters
            $('#analysisStreamId, #analysisDormitoryId, #analysisHouseId')
                .html('<option value="">Loading...</option>')
                .prop('disabled', true);

            if (!classId) return;

            // 1. Streams (already existing)
            $.post('reports/functions.php', {
                action: 'get_streams',
                class_id: classId
            }, function(res) {
                if (res.status === 'success' && res.streams?.length) {
                    let opts = '<option value="">All Streams</option>';
                    res.streams.forEach(s => {
                        opts += `<option value="${s.stream_id}">${s.stream_name}</option>`;
                    });
                    $('#analysisStreamId').html(opts).prop('disabled', false);
                } else {
                    $('#analysisStreamId').html('<option value="">No streams</option>');
                }
            }, 'json');

            // 2. Dormitories
            $.post('reports/functions.php', {
                action: 'get_dormitories',
                class_id: classId
            }, function(res) {
                if (res.status === 'success' && res.dormitories?.length) {
                    let opts = '<option value="">— Any / All dorms —</option>';
                    res.dormitories.forEach(d => {
                        opts += `<option value="${d.dormitory_id}">${d.name}</option>`;
                    });
                    $('#analysisDormitoryId').html(opts).prop('disabled', false);
                } else {
                    $('#analysisDormitoryId').html('<option value="">No dormitories</option>');
                }
            }, 'json');

            // 3. Houses
            $.post('reports/functions.php', {
                action: 'get_houses'
            }, function(res) {
                if (res.status === 'success' && res.houses?.length) {
                    let opts = '<option value="">— Any / All houses —</option>';
                    res.houses.forEach(h => {
                        opts += `<option value="${h.house_id}">${h.name}</option>`;
                    });
                    $('#analysisHouseId').html(opts).prop('disabled', false);
                } else {
                    $('#analysisHouseId').html('<option value="">No houses defined</option>');
                }
            }, 'json');
        });

        // Mutual exclusivity for analysis filters (stream/dorm/house)
        $('#analysisStreamId, #analysisDormitoryId, #analysisHouseId').on('change', function() {
            const $this = $(this);
            const val = $this.val();

            if (val) {
                // Disable the other two
                $('#analysisStreamId, #analysisDormitoryId, #analysisHouseId')
                    .not($this)
                    .prop('disabled', true)
                    .val('');
            } else {
                // Re-enable others if this one is cleared
                $('#analysisStreamId, #analysisDormitoryId, #analysisHouseId')
                    .prop('disabled', false);
            }
        });

        // ─── Custom Group Report submit ────────────────────────────────────────
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

        // Show School Mean modal
        $('#schoolMeanBtn').on('click', function() {
            $('#schoolMeanModal').modal('show');
        });

        // Load exams when year changes (reuses existing AJAX)
        $('#meanYear').on('change', function() {
            const year = $(this).val();
            $('#meanExamId').html('<option value="">Select Exam</option>').prop('disabled', true);
            $('#generateSchoolMeanBtn').prop('disabled', true);

            if (year) {
                $.post('reports/functions.php', {
                    action: 'get_exams_by_year',
                    year: year
                }, function(res) {
                    if (res.status === 'success' && res.exams.length) {
                        res.exams.forEach(ex => {
                            $('#meanExamId').append(`<option value="${ex.exam_id}">${ex.exam_name} (${ex.term})</option>`);
                        });
                        $('#meanExamId').prop('disabled', false);
                    }
                }, 'json');
            }
        });

        // Enable button when exam is selected
        $('#meanExamId').on('change', function() {
            $('#generateSchoolMeanBtn').prop('disabled', !$(this).val());
        });
        // Show Multi-Exam Stream modal
        $('#multiExamStreamBtn').on('click', function() {
            $('#multiExamStreamModal').modal('show');
        });

        // Load terms when year changes
        $('#multiYear').on('change', function() {
            const year = $(this).val();
            $('#multiTerm').html('<option value="">Select Term</option>').prop('disabled', true);
            $('#examCheckboxes').html('');
            $('#generateMultiRankBtn').prop('disabled', true);

            if (year) {
                $.post('reports/functions.php', {
                    action: 'get_terms_for_year',
                    year: year
                }, function(res) {
                    if (res.status === 'success') {
                        res.terms.forEach(t => $('#multiTerm').append(`<option value="${t}">${t}</option>`));
                        $('#multiTerm').prop('disabled', false);
                    }
                }, 'json');
            }
        });

        // Load exam checkboxes when term changes
        $('#multiTerm').on('change', function() {
            const year = $('#multiYear').val();
            const term = $(this).val();
            $('#examCheckboxes').html('');
            $('#generateMultiRankBtn').prop('disabled', true);

            if (year && term) {
                $.post('reports/functions.php', {
                    action: 'get_exams_for_term_and_year',
                    year: year,
                    term: term
                }, function(res) {
                    if (res.status === 'success' && res.exams.length) {
                        let html = '<label class="form-label">Select Exams</label><div class="row">';
                        res.exams.forEach(ex => {
                            html += `
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="exam_ids[]" value="${ex.exam_id}" id="exam_${ex.exam_id}">
                                <label class="form-check-label" for="exam_${ex.exam_id}">${ex.exam_name}</label>
                            </div>
                        </div>`;
                        });
                        html += '</div>';
                        $('#examCheckboxes').html(html);
                        $('#generateMultiRankBtn').prop('disabled', false);
                    } else {
                        $('#examCheckboxes').html('<p class="text-muted">No exams found.</p>');
                    }
                }, 'json');
            }
        });

        // Show Form Rank modal
        $('#formRankBtn').on('click', function() {
            $('#formRankModal').modal('show');
        });

        // Load terms when year changes
        $('#formRankYear').on('change', function() {
            const year = $(this).val();
            $('#formRankTerm').html('<option value="">Select Term</option>').prop('disabled', true);
            $('#formRankExamCheckboxes').html('');
            $('#generateFormRankBtn').prop('disabled', true);

            if (year) {
                $.post('reports/functions.php', {
                    action: 'get_terms_for_year',
                    year: year
                }, function(res) {
                    if (res.status === 'success') {
                        res.terms.forEach(t => $('#formRankTerm').append(`<option value="${t}">${t}</option>`));
                        $('#formRankTerm').prop('disabled', false);
                    }
                }, 'json');
            }
        });

        // Load exam checkboxes when term changes
        $('#formRankTerm').on('change', function() {
            const year = $('#formRankYear').val();
            const term = $(this).val();
            $('#formRankExamCheckboxes').html('');
            $('#generateFormRankBtn').prop('disabled', true);

            if (year && term) {
                $.post('reports/functions.php', {
                    action: 'get_exams_for_term_and_year',
                    year: year,
                    term: term
                }, function(res) {
                    if (res.status === 'success' && res.exams.length) {
                        let html = '<label class="form-label fw-bold">Select Exams to Include</label><div class="row g-2">';
                        res.exams.forEach(ex => {
                            html += `
                    <div class="col-md-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="exam_ids[]" value="${ex.exam_id}" id="frmex_${ex.exam_id}">
                            <label class="form-check-label" for="frmex_${ex.exam_id}">${ex.exam_name}</label>
                        </div>
                    </div>`;
                        });
                        html += '</div>';
                        $('#formRankExamCheckboxes').html(html);
                        $('#generateFormRankBtn').prop('disabled', false);
                    }
                }, 'json');
            }
        });
        // Show modal
        $('#classSubjectGradeBtn').on('click', function() {
            $('#classSubjectGradeModal').modal('show');
        });

        // Year → Load Terms
        $('#csgYear').on('change', function() {
            const year = $(this).val();
            $('#csgTerm').html('<option value="">Select Term</option>').prop('disabled', true);
            $('#csgClassId, #csgExamId').html('<option value="">Select ...</option>').prop('disabled', true);
            $('#generateClassSubjectGradeBtn').prop('disabled', true);

            if (year) {
                $.post('reports/functions.php', {
                    action: 'get_terms_for_year',
                    year: year
                }, function(res) {
                    if (res.status === 'success') {
                        res.terms.forEach(t => $('#csgTerm').append(`<option value="${t}">${t}</option>`));
                        $('#csgTerm').prop('disabled', false);
                    }
                }, 'json');
            }
        });

        // Term → Load Classes
        $('#csgTerm').on('change', function() {
            const year = $('#csgYear').val();
            const term = $(this).val();
            $('#csgClassId').html('<option value="">Select Class</option>').prop('disabled', true);
            $('#csgExamId').prop('disabled', true);
            $('#generateClassSubjectGradeBtn').prop('disabled', true);

            if (year && term) {
                $.post('reports/functions.php', {
                    action: 'get_classes_for_term_and_year',
                    year: year,
                    term: term
                }, function(res) {
                    if (res.status === 'success') {
                        res.classes.forEach(cls => {
                            $('#csgClassId').append(`<option value="${cls.class_id}">${cls.form_name}</option>`);
                        });
                        $('#csgClassId').prop('disabled', false);
                    }
                }, 'json');
            }
        });

        // Class → Load Exams (only for this class)
        $('#csgClassId').on('change', function() {
            const year = $('#csgYear').val();
            const term = $('#csgTerm').val();
            const classId = $(this).val();
            $('#csgExamId').html('<option value="">Select Exam</option>').prop('disabled', true);
            $('#generateClassSubjectGradeBtn').prop('disabled', true);

            if (year && term && classId) {
                $.post('reports/functions.php', {
                    action: 'get_exams_for_class_term_year',
                    year: year,
                    term: term,
                    class_id: classId
                }, function(res) {
                    if (res.status === 'success' && res.exams.length) {
                        res.exams.forEach(ex => {
                            $('#csgExamId').append(`<option value="${ex.exam_id}">${ex.exam_name}</option>`);
                        });
                        $('#csgExamId').prop('disabled', false);
                    }
                }, 'json');
            }
        });

        // Enable button when Exam is selected
        $('#csgExamId').on('change', function() {
            $('#generateClassSubjectGradeBtn').prop('disabled', !$(this).val());
        });

        // Show House Rank modal
        $('#houseRankBtn').on('click', function() {
            $('#houseRankModal').modal('show');
        });

        // Year → load terms
        $('#houseYear').on('change', function() {
            const year = $(this).val();
            $('#houseTerm').html('<option value="">Select Term</option>').prop('disabled', true);
            $('#houseExamCheckboxes').html('');
            $('#generateHouseRankBtn').prop('disabled', true);

            if (year) {
                $.post('reports/functions.php', {
                    action: 'get_terms_for_year',
                    year: year
                }, function(res) {
                    if (res.status === 'success' && res.terms?.length) {
                        res.terms.forEach(t => {
                            $('#houseTerm').append(`<option value="${t}">${t}</option>`);
                        });
                        $('#houseTerm').prop('disabled', false);
                    }
                }, 'json');
            }
        });

        // Term → load exam checkboxes
        $('#houseTerm').on('change', function() {
            const year = $('#houseYear').val();
            const term = $(this).val();
            $('#houseExamCheckboxes').html('');
            $('#generateHouseRankBtn').prop('disabled', true);

            if (year && term) {
                $.post('reports/functions.php', {
                    action: 'get_exams_for_term_and_year',
                    year: year,
                    term: term
                }, function(res) {
                    if (res.status === 'success' && res.exams?.length) {
                        let html = '<label class="form-label fw-bold">Select Exams to Include</label><div class="row g-2">';
                        res.exams.forEach(ex => {
                            html += `
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="exam_ids[]" value="${ex.exam_id}" id="house_ex_${ex.exam_id}">
                                <label class="form-check-label" for="house_ex_${ex.exam_id}">${ex.exam_name}</label>
                            </div>
                        </div>`;
                        });
                        html += '</div>';
                        $('#houseExamCheckboxes').html(html);
                        $('#generateHouseRankBtn').prop('disabled', false);
                    } else {
                        $('#houseExamCheckboxes').html('<p class="text-muted">No closed exams found.</p>');
                    }
                }, 'json');
            }
        });
    }); // End of $(document).ready()
</script>

<?php include __DIR__ . '/../footer.php'; ?>