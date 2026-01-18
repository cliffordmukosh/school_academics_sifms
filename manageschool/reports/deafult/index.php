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
    ORDER BY form_name
");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch school details for report header
$stmt = $conn->prepare("SELECT name, logo FROM schools WHERE school_id = ?");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$school = $stmt->get_result()->fetch_assoc();
$stmt->close();
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
    .table-container {
        max-width: 100%;
        overflow-x: auto;
    }
    th, td {
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
    .table th, .table td {
        padding: 2px 3px !important;
        vertical-align: middle;
        font-size: 9px;
    }
</style>

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
    </div>

    <!-- View Results Modal -->
    <div class="modal fade" id="resultsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title d-flex align-items-center">
                        <i class="bi bi-list-ul me-2"></i> Select Form, Term, Exam, and Stream
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="analysisForm">
                        <div class="row g-3 mb-3">
                            <div class="col-md-3">
                                <label class="form-label">Form</label>
                                <select class="form-select" id="analysisClassId" required>
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
                                <select class="form-select" id="analysisTerm" disabled required>
                                    <option value="">Select Term</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Exam</label>
                                <select class="form-select" id="analysisExamId" disabled required>
                                    <option value="">Select Exam</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Stream</label>
                                <select class="form-select" id="analysisStreamId" disabled required>
                                    <option value="">Select Stream</option>
                                </select>
                            </div>
                        </div>
                        <button type="button" class="btn btn-primary" id="loadAnalysisBtn">Load Analysis</button>
                    </form>
                    <div id="analysisTableContainer" class="table-container mt-4" style="display: none;">
                        <table class="table table-striped table-hover" id="analysisTable">
                            <thead class="table-dark">
                                <tr>
                                    <th>#</th>
                                    <th>Admission No</th>
                                    <th>Student Name</th>
                                    <th>Total Marks</th>
                                    <th>Average Marks</th>
                                    <th>Grade</th>
                                    <th>Mean Points</th>
                                    <th>Rank</th>
                                </tr>
                            </thead>
                            <tbody id="analysisTableBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function () {
    // Show results modal when View Results button is clicked
    $('#viewResultsBtn').on('click', function () {
        $('#resultsModal').modal('show');
    });

    // Handle Form Selection for analysis to load terms and streams
    $('#analysisClassId').on('change', function () {
        const classId = $(this).val();
        $('#analysisTerm').html('<option value="">Select Term</option>').prop('disabled', true);
        $('#analysisExamId').html('<option value="">Select Exam</option>').prop('disabled', true);
        $('#analysisStreamId').html('<option value="">Select Stream</option>').prop('disabled', true);
        $('#analysisTableContainer').hide();
        if (classId) {
            // Load terms
            $.post('reports/functions.php', { action: 'get_terms_for_class', class_id: classId }, function (response) {
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
            }, 'json').fail(function() {
                alert('Failed to load terms.');
            });

            // Load streams
            $.post('reports/functions.php', { action: 'get_streams', class_id: classId }, function (response) {
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
            }, 'json').fail(function() {
                alert('Failed to load streams.');
            });
        }
    });

    // Handle Term Selection to load exams
    $('#analysisTerm').on('change', function () {
        const classId = $('#analysisClassId').val();
        const term = $(this).val();
        $('#analysisExamId').html('<option value="">Select Exam</option>').prop('disabled', true);
        if (classId && term) {
            // Load exams for the selected class and term
            $.post('reports/functions.php', { action: 'get_exams_for_class', class_id: classId, term: term }, function (response) {
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
            }, 'json').fail(function() {
                alert('Failed to load exams.');
            });
        }
    });

    // Handle Load Analysis button
    $('#loadAnalysisBtn').on('click', function () {
        const classId = $('#analysisClassId').val();
        const term = $('#analysisTerm').val();
        const examId = $('#analysisExamId').val();
        const streamId = $('#analysisStreamId').val();
        if (!classId || !term || !examId || !streamId) {
            alert('Please select form, term, exam, and stream.');
            return;
        }
        $.post('reports/functions.php', { action: 'get_analysis', class_id: classId, term: term, exam_id: examId, stream_id: streamId }, function (response) {
            if (response.status === 'success') {
                if (response.students && response.students.length > 0) {
                    const rows = response.students.map((student, index) => `
                        <tr>
                            <td>${index + 1}</td>
                            <td>${student.admission_no}</td>
                            <td>${student.full_name}</td>
                            <td>${student.total_marks}</td>
                            <td>${student.average_marks.toFixed(2)}%</td>
                            <td>${student.grade}</td>
                            <td>${student.total_points.toFixed(2)}</td>
                            <td>${student.rank}</td>
                        </tr>
                    `).join('');
                    $('#analysisTableBody').html(rows);
                    $('#analysisTableContainer').show();
                } else {
                    alert('No results found for the selected form, term, exam, and stream.');
                }
            } else {
                alert('Error: ' + response.message);
            }
        }, 'json').fail(function() {
            alert('Failed to load analysis data. Please check your network or contact support.');
        });
    });
});
</script>

<?php include __DIR__ . '/../footer.php'; ?>