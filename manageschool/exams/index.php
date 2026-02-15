<?php
// exams/index.php
include __DIR__ . '/../header.php';
include __DIR__ . '/../sidebar.php';
require __DIR__ . '/../../connection/db.php';
require __DIR__ . '/../../vendor/autoload.php'; // For PhpSpreadsheet

use PhpOffice\PhpSpreadsheet\IOFactory;

// Check if user is logged in and has appropriate permissions
if (!isset($_SESSION['user_id']) || !isset($_SESSION['school_id']) || !isset($_SESSION['role_id'])) {
  header("Location: ../../login.php");
  exit;
}

// Fetch classes for this school
$stmt = $conn->prepare("SELECT class_id, form_name FROM classes WHERE school_id = ? ORDER BY form_name");
$stmt->bind_param("i", $_SESSION['school_id']);
$stmt->execute();
$classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch streams for this school
$stmt = $conn->prepare("SELECT stream_id, stream_name, class_id FROM streams WHERE school_id = ? ORDER BY stream_name");
$stmt->bind_param("i", $_SESSION['school_id']);
$stmt->execute();
$streams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch grading systems (default + school-specific)
// Fetch ONLY grading systems that belong to the CURRENT school
// Fetch ONLY grading systems created by THIS school
// → No global default, no other schools' systems, no leaking defaults
$stmt = $conn->prepare("
    SELECT 
        gs.grading_system_id,
        gs.name,
        gs.is_default,
        gs.created_at,
        IFNULL(MAX(gr.is_cbc), 0) AS is_cbc
    FROM grading_systems gs
    LEFT JOIN grading_rules gr 
        ON gs.grading_system_id = gr.grading_system_id
    WHERE gs.school_id = ?
      AND gs.school_id IS NOT NULL         
    GROUP BY gs.grading_system_id, gs.name, gs.is_default, gs.created_at
    ORDER BY gs.created_at DESC, gs.name ASC
");

$stmt->bind_param("i", $_SESSION['school_id']);
$stmt->execute();
$grading_systems = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch exams for this school
$stmt = $conn->prepare("
    SELECT e.exam_id, e.exam_name, e.term, e.status, c.form_name, g.name AS grading_system_name
    FROM exams e
    JOIN classes c ON e.class_id = c.class_id
    JOIN grading_systems g ON e.grading_system_id = g.grading_system_id
    WHERE e.school_id = ?
    ORDER BY e.created_at DESC
");
$stmt->bind_param("i", $_SESSION['school_id']);
$stmt->execute();
$exams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$totalExams = count($exams);
$stmt->close();

// Define available terms and statuses
$terms = ['Term 1', 'Term 2', 'Term 3'];
$statuses = ['draft', 'active', 'closed'];
?>
<style>
  .sticky-column {
    position: sticky;
    left: 0;
    background: white;
    z-index: 10;
    box-shadow: 2px 0 5px -2px rgba(0, 0, 0, 0.1);
  }

  .table-container {
    max-width: 100%;
    overflow-x: auto;
  }

  th,
  td {
    min-width: 100px;
    text-align: center;
  }

  .filter-container {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
    margin-bottom: 1rem;
  }

  .editable-cell:hover {
    background-color: #f0f0f0;
    cursor: pointer;
  }

  .editable-cell input {
    width: 100%;
    border: none;
    background: transparent;
    text-align: center;
    padding: 0;
  }

  .editable-cell input:focus {
    outline: none;
    border-bottom: 1px solid #007bff;
  }
</style>

<div class="content">
  <div class="container-fluid">
    <div class="container py-4">
      <!-- Title with dynamic count -->
      <h3 class="mb-4 d-flex align-items-center">
        <i class="bi bi-file-text me-2"></i> Exam Management
        <span class="badge bg-primary ms-3 fs-6">Total Exams: <?php echo $totalExams; ?></span>
      </h3>

      <!-- Exam Management Menu -->
      <div class="row g-4 mb-4">
        <!-- Create Exam -->
        <div class="col-md-3">
          <div class="card shadow-sm border-0 h-100 text-center">
            <div class="card-body d-flex flex-column justify-content-center">
              <i class="bi bi-plus-square display-5 text-primary"></i>
              <h5 class="mt-3">Create Exam</h5>
              <p class="text-muted">Create a new exam for a class and term.</p>
              <button class="btn btn-primary mt-auto" data-bs-toggle="modal" data-bs-target="#createExamModal">
                <i class="bi bi-plus-circle me-2"></i> Create Exam
              </button>
            </div>
          </div>
        </div>

        <!-- Upload Results -->
        <div class="col-md-3">
          <div class="card shadow-sm border-0 h-100 text-center">
            <div class="card-body d-flex flex-column justify-content-center">
              <i class="bi bi-upload display-5 text-info"></i>
              <h5 class="mt-3">Upload Results</h5>
              <p class="text-muted">Enter or upload exam results per paper.</p>
              <button class="btn btn-info text-white mt-auto" data-bs-toggle="modal" data-bs-target="#uploadResultsModal">
                <i class="bi bi-upload me-2"></i> Upload and Edit Results
              </button>
            </div>
          </div>
        </div>

        <!-- Manage Exams -->
        <div class="col-md-3">
          <div class="card shadow-sm border-0 h-100 text-center">
            <div class="card-body d-flex flex-column justify-content-center">
              <i class="bi bi-gear display-5 text-warning"></i>
              <h5 class="mt-3">Manage Exams</h5>
              <p class="text-muted">View, edit, or delete exams.</p>
              <button class="btn btn-warning mt-auto" data-bs-toggle="modal" data-bs-target="#manageExamsModal">
                <i class="bi bi-gear me-2"></i> Manage Exams
              </button>
            </div>
          </div>
        </div>

        <!-- Manage Grading Systems -->
        <div class="col-md-3">
          <div class="card shadow-sm border-0 h-100 text-center">
            <div class="card-body d-flex flex-column justify-content-center">
              <i class="bi bi-table display-5 text-secondary"></i>
              <h5 class="mt-3">Manage Grading</h5>
              <p class="text-muted">View default or create new grading systems.</p>
              <button class="btn btn-secondary mt-auto" data-bs-toggle="modal" data-bs-target="#manageGradingModal">
                <i class="bi bi-table me-2"></i> Manage Grading
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Exam List -->
      <div class="card shadow-sm border-0">
        <div class="card-body">
          <h5 class="mb-3 d-flex align-items-center">
            <i class="bi bi-list-ul me-2"></i> Exam List
          </h5>
          <table class="table table-striped table-hover">
            <thead class="table-dark">
              <tr>
                <th>#</th>
                <th>Exam Name</th>
                <th>Class</th>
                <th>Term</th>
                <th>Status</th>
                <th>Grading System</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="examsTableBody">
              <?php foreach ($exams as $index => $exam): ?>
                <tr data-exam-id="<?php echo $exam['exam_id']; ?>">
                  <td><?php echo $index + 1; ?></td>
                  <td><?php echo htmlspecialchars($exam['exam_name']); ?></td>
                  <td><?php echo htmlspecialchars($exam['form_name']); ?></td>
                  <td><?php echo htmlspecialchars($exam['term']); ?></td>
                  <td><?php echo htmlspecialchars(ucfirst($exam['status'])); ?></td>
                  <td><?php echo htmlspecialchars($exam['grading_system_name']); ?></td>
                  <td>
                    <button class="btn btn-sm btn-primary view-subjects" data-exam-id="<?php echo $exam['exam_id']; ?>">
                      <i class="bi bi-eye"></i> View Subjects
                    </button>
                    <button class="btn btn-sm btn-primary edit-exam" data-exam-id="<?php echo $exam['exam_id']; ?>">
                      <i class="bi bi-pencil"></i> Edit
                    </button>
                    <button class="btn btn-sm btn-danger delete-exam" data-exam-id="<?php echo $exam['exam_id']; ?>">
                      <i class="bi bi-trash"></i> Delete
                    </button>
                    <button class="btn btn-sm btn-info publish-exam" data-exam-id="<?php echo $exam['exam_id']; ?>">
                      <i class="bi bi-check-circle"></i> Publish
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Create Exam Modal -->
      <div class="modal fade" id="createExamModal" tabindex="-1">
        <div class="modal-dialog modal-md">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title"><i class="bi bi-plus-square me-2"></i> Create Exam</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <form id="createExamForm">
                <div class="mb-3">
                  <label class="form-label">Exam Name</label>
                  <input type="text" class="form-control" name="exam_name" placeholder="Enter exam name" required>
                </div>
                <div class="mb-3">
                  <label class="form-label">Classes</label>
                  <div class="input-group">
                    <input type="text" class="form-control" id="examClassDisplay" readonly placeholder="Select classes">
                    <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#selectClassesModal">Select Classes</button>
                  </div>
                  <input type="hidden" name="class_ids" id="examClassIds">
                  <small class="form-text text-muted">Click the button to select multiple classes.</small>
                </div>
                <div class="mb-3">
                  <label class="form-label">Term</label>
                  <select class="form-select" name="term" required>
                    <option value="">Select Term</option>
                    <?php foreach ($terms as $term): ?>
                      <option value="<?php echo $term; ?>"><?php echo $term; ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="mb-3">
                  <label class="form-label">Grading System</label>
                  <select class="form-select" name="grading_system_id" required>
                    <option value="">Select Grading System</option>
                    <?php foreach ($grading_systems as $gs): ?>
                      <option value="<?php echo $gs['grading_system_id']; ?>">
                        <?php echo htmlspecialchars($gs['name'] . ($gs['is_default'] ? ' (Default)' : '')); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <button type="submit" class="btn btn-primary">Create Exam</button>
              </form>
            </div>
          </div>
        </div>
      </div>

      <!-- Upload Results Modal -->
      <div class="modal fade" id="uploadResultsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title"><i class="bi bi-upload me-2"></i> Enter Exam Results</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <ul class="nav nav-tabs mb-3" id="uploadTabs" role="tablist">
                <li class="nav-item">
                  <a class="nav-link active" id="manual-tab" data-bs-toggle="tab" href="#manual" role="tab">Manual Entry</a>
                </li>
                <li class="nav-item">
                  <a class="nav-link" id="excel-tab" data-bs-toggle="tab" href="#excel" role="tab">Excel Upload</a>
                </li>
              </ul>
              <div class="tab-content">
                <!-- Manual Entry Tab -->
                <div class="tab-pane fade show active" id="manual" role="tabpanel">
                  <form id="manualResultsForm">
                    <input type="hidden" name="action" value="upload_results_manually">
                    <div class="mb-3">
                      <label class="form-label">Exam</label>
                      <select class="form-select" name="exam_id" id="manualExamId" required>
                        <option value="">Select Exam</option>
                        <?php foreach ($exams as $exam): ?>
                          <option value="<?php echo $exam['exam_id']; ?>">
                            <?php echo htmlspecialchars($exam['exam_name'] . ' (' . $exam['form_name'] . ', ' . $exam['term'] . ', ' . ucfirst($exam['status']) . ')'); ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="mb-3" id="manualSubjectDiv" style="display: none;">
                      <label class="form-label">Subjects</label>
                      <div class="input-group">
                        <input type="text" class="form-control" id="manualSubjectDisplay" readonly placeholder="Select subjects">
                        <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#selectSubjectsModal">Select Subjects</button>
                      </div>
                      <input type="hidden" name="subject_ids" id="manualSubjectIds">
                      <small class="form-text text-muted">Click the button to select multiple subjects.</small>
                    </div>
                    <div class="mb-3">
                      <label class="form-label">Scope</label>
                      <select class="form-select" name="scope" id="manualScope" required>
                        <option value="">Select Scope</option>
                        <option value="class">Entire Class</option>
                        <option value="stream">Stream</option>
                        <option value="student">Single Student</option>
                      </select>
                    </div>
                    <div class="mb-3" id="manualClassDiv" style="display: none;">
                      <label class="form-label">Class</label>
                      <select class="form-select" name="class_id" id="manualClassId" disabled>
                        <option value="">Select Class</option>
                      </select>
                    </div>
                    <div class="mb-3" id="manualStreamDiv" style="display: none;">
                      <label class="form-label">Stream</label>
                      <select class="form-select" name="stream_id" id="manualStreamId">
                        <option value="">Select Stream</option>
                      </select>
                    </div>
                    <div class="mb-3" id="manualStudentDiv" style="display: none;">
                      <label class="form-label">Student</label>
                      <select class="form-select" name="student_id" id="manualStudentId">
                        <option value="">Select Student</option>
                      </select>
                    </div>
                    <div id="manualResultsEntry" class="mb-3" style="display: none;">
                      <!-- Dynamic result entry table will be populated here -->
                    </div>
                    <button type="submit" class="btn btn-primary">Submit Results</button>
                  </form>
                </div>


                <!-- Excel Upload Tab -->
                <div class="tab-pane fade" id="excel" role="tabpanel">
                  <form id="excelResultsForm" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_results_excel">
                    <div class="mb-3">
                      <label class="form-label">Exam</label>
                      <select class="form-select" name="exam_id" id="excelExamId" required>
                        <option value="">Select Exam</option>
                        <?php foreach ($exams as $exam): ?>
                          <option value="<?php echo $exam['exam_id']; ?>">
                            <?php echo htmlspecialchars($exam['exam_name'] . ' (' . $exam['form_name'] . ', ' . $exam['term'] . ', ' . ucfirst($exam['status']) . ')'); ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="mb-3" id="excelSubjectDiv" style="display: none;">
                      <label class="form-label">Subjects</label>
                      <div class="input-group">
                        <input type="text" class="form-control" id="excelSubjectDisplay" readonly placeholder="Select subjects">
                        <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#selectSubjectsModal">Select Subjects</button>
                      </div>
                      <input type="hidden" name="subject_ids" id="excelSubjectIds">
                      <small class="form-text text-muted">Click the button to select multiple subjects.</small>
                    </div>
                    <div class="mb-3">
                      <label class="form-label">Scope</label>
                      <select class="form-select" name="scope" id="excelScope" required>
                        <option value="">Select Scope</option>
                        <option value="class">Entire Class</option>
                        <option value="stream">Specific Stream</option>
                        <option value="student">Single Student</option>
                      </select>
                    </div>
                    <div class="mb-3" id="excelClassDiv" style="display: none;">
                      <label class="form-label">Class</label>
                      <select class="form-select" name="class_id" id="excelClassId" disabled>
                        <option value="">Select Class</option>
                      </select>
                    </div>
                    <div class="mb-3" id="excelStreamDiv" style="display: none;">
                      <label class="form-label">Stream</label>
                      <select class="form-select" name="stream_id" id="excelStreamId">
                        <option value="">Select Stream</option>
                      </select>
                    </div>
                    <div class="mb-3" id="excelStudentDiv" style="display: none;">
                      <label class="form-label">Student</label>
                      <select class="form-select" name="student_id" id="excelStudentId">
                        <option value="">Select Student</option>
                      </select>
                      <div class="mt-2">
                        <label class="form-label">Or Enter Admission Number</label>
                        <input type="text" class="form-control" name="admission_no" id="excelAdmissionNo" placeholder="Enter admission number">
                      </div>
                    </div>
                    <div class="mb-3">
                      <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="includeStudents" name="include_students">
                        <label class="form-check-label" for="includeStudents">Include student list in Excel template</label>
                      </div>
                    </div>
                    <div class="mb-3" id="excelSubjectsInfo" style="display: none;">
                      <label class="form-label">Papers for Selected Subject</label>
                      <p id="examSubjectsList" class="form-text"></p>
                      <p class="form-text"><strong>Excel File Header Format:</strong> <span id="excelHeaderFormat"></span></p>
                      <a href="#" id="downloadExcelTemplate" class="btn btn-sm btn-outline-success mt-2" style="display: none;">
                        <i class="bi bi-download me-2"></i> Download Excel Template
                      </a>
                    </div>
                    <div class="mb-3">
                      <label class="form-label">Upload Excel File</label>
                      <input type="file" class="form-control" name="excel_file" accept=".xlsx, .xls" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Upload Excel</button>
                  </form>
                </div>

              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Publish Results Modal -->
    <div class="modal fade" id="publishResultsModal" tabindex="-1">
      <div class="modal-dialog modal-sm">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Publish Results</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <p>Results published successfully!</p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Select Subjects Modal -->
    <div class="modal fade" id="selectSubjectsModal" tabindex="-1">
      <div class="modal-dialog modal-md">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title"><i class="bi bi-book me-2"></i> Select Subjects</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
              <label class="form-label">Subjects</label>
              <div id="subjectCheckboxes" style="max-height: 300px; overflow-y: auto;">
                <!-- Checkboxes populated via JavaScript -->
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary" id="confirmSubjectsBtn">Confirm Selection</button>
          </div>
        </div>
      </div>
    </div>


    <!-- Manage Exams Modal -->
    <div class="modal fade" id="manageExamsModal" tabindex="-1">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title"><i class="bi bi-gear me-2"></i> Manage Exams</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <table class="table table-striped table-hover">
              <thead class="table-dark">
                <tr>
                  <th>#</th>
                  <th>Exam Name</th>
                  <th>Class</th>
                  <th>Term</th>
                  <th>Status</th>
                  <th>Grading System</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody id="examsTableBodyModal">
                <?php foreach ($exams as $exam): ?>
                  <tr data-exam-id="<?php echo $exam['exam_id']; ?>">
                    <td><?php echo htmlspecialchars($exam['exam_id']); ?></td>
                    <td><?php echo htmlspecialchars($exam['exam_name']); ?></td>
                    <td><?php echo htmlspecialchars($exam['form_name']); ?></td>
                    <td><?php echo htmlspecialchars($exam['term']); ?></td>
                    <td><?php echo htmlspecialchars(ucfirst($exam['status'])); ?></td>
                    <td><?php echo htmlspecialchars($exam['grading_system_name']); ?></td>
                    <td>
                      <button class="btn btn-sm btn-primary view-subjects" data-exam-id="<?php echo $exam['exam_id']; ?>">
                        <i class="bi bi-eye"></i> View Subjects
                      </button>
                      <button class="btn btn-sm btn-primary edit-exam" data-exam-id="<?php echo $exam['exam_id']; ?>">
                        <i class="bi bi-pencil"></i> Edit
                      </button>
                      <button class="btn btn-sm btn-danger delete-exam" data-exam-id="<?php echo $exam['exam_id']; ?>">
                        <i class="bi bi-trash"></i> Delete
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>


    <!-- Select Classes Modal -->
    <!-- Select Classes Modal -->
    <div class="modal fade" id="selectClassesModal" tabindex="-1">
      <div class="modal-dialog modal-md">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title"><i class="bi bi-building me-2"></i>Select Classes</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
              <label class="form-label">Classes</label>
              <div id="classCheckboxes" style="max-height: 300px; overflow-y: auto;">
                <?php foreach ($classes as $class): ?>
                  <div class="form-check mb-2">
                    <div class="d-flex align-items-center">
                      <input class="form-check-input class-checkbox" type="checkbox" value="<?php echo $class['class_id']; ?>" id="class_<?php echo $class['class_id']; ?>" data-name="<?php echo htmlspecialchars($class['form_name']); ?>">
                      <label class="form-check-label me-3" for="class_<?php echo $class['class_id']; ?>"><?php echo htmlspecialchars($class['form_name']); ?></label>
                      <input type="number" class="form-control form-control-sm w-50 min-subjects-input" name="min_subjects_<?php echo $class['class_id']; ?>" placeholder="Enter minimum subjects for grading" min="1" style="display: none;" data-class-id="<?php echo $class['class_id']; ?>">
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary" id="confirmClassesBtn">Confirm Selection</button>
          </div>
        </div>
      </div>
    </div>



    <!-- Manage Grading Systems Modal -->
    <div class="modal fade" id="manageGradingModal" tabindex="-1">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title"><i class="bi bi-table me-2"></i> Manage Grading Systems</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <button class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#createGradingModal">
              <i class="bi bi-plus-circle me-2"></i> Create New Grading System
            </button>

            <?php if (empty($grading_systems)): ?>
              <div class="alert alert-info text-center py-4">
                <i class="bi bi-info-circle me-2"></i>
                You don't have any custom grading systems yet.<br>
                <small>Create your first one using the button above.</small>
              </div>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table table-striped table-hover table-bordered">
                  <thead class="table-dark">
                    <tr>
                      <th>Name</th>
                      <th>Curriculum</th>
                      <th>Default</th>
                      <th>Actions</th>
                    </tr>
                  </thead>
                  <tbody id="gradingSystemsTableBody">
                    <?php foreach ($grading_systems as $gs): ?>
                      <tr data-grading-system-id="<?php echo $gs['grading_system_id']; ?>">
                        <td><?php echo htmlspecialchars($gs['name']); ?></td>
                        <td>
                          <?php
                          $isCBC = (int)$gs['is_cbc'] === 1;
                          $curriculum = $isCBC ? 'CBC' : '8-4-4';
                          $badgeClass = $isCBC ? 'bg-success' : 'bg-primary';
                          ?>
                          <span class="badge <?php echo $badgeClass; ?> px-3 py-2 fs-6">
                            <?php echo $curriculum; ?>
                          </span>
                        </td>
                        <td>
                          <?php echo $gs['is_default']
                            ? '<span class="badge bg-warning">Yes</span>'
                            : 'No'; ?>
                        </td>
                        <td>
                          <button class="btn btn-sm btn-primary view-grading-rules me-1"
                            data-grading-system-id="<?php echo $gs['grading_system_id']; ?>">
                            <i class="bi bi-eye"></i> View Rules
                          </button>
                          <?php if (!$gs['is_default']): ?>
                            <button class="btn btn-sm btn-danger delete-grading-system"
                              data-grading-system-id="<?php echo $gs['grading_system_id']; ?>">
                              <i class="bi bi-trash"></i> Delete
                            </button>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Create Grading System Modal -->
    <div class="modal fade" id="createGradingModal" tabindex="-1">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title"><i class="bi bi-plus-square me-2"></i> Create Grading System</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <form id="createGradingForm">
              <div class="mb-3">
                <label class="form-label">Grading System Name</label>
                <input type="text" class="form-control" name="name" placeholder="e.g., Custom Grading 2025" required>
              </div>
              <div class="mb-3">
                <label class="form-label">Grading Rules</label>
                <div id="gradingRulesContainer">
                  <!-- Initial rule row -->
                  <div class="grading-rule-row mb-3">
                    <div class="row g-2">
                      <div class="col-md-2">
                        <input type="text" class="form-control" name="rules[0][grade]" placeholder="Grade (e.g., A)" required>
                      </div>
                      <div class="col-md-2">
                        <input type="number" class="form-control" name="rules[0][min_score]" placeholder="Min Score" min="0" max="100" step="0.01" required>
                      </div>
                      <div class="col-md-2">
                        <input type="number" class="form-control" name="rules[0][max_score]" placeholder="Max Score" min="0" max="100" step="0.01" required>
                      </div>
                      <div class="col-md-2">
                        <input type="number" class="form-control" name="rules[0][points]" placeholder="Points" min="0" required>
                      </div>
                      <div class="col-md-3">
                        <input type="text" class="form-control" name="rules[0][description]" placeholder="Description (optional)">
                      </div>
                      <div class="col-md-1">
                        <button type="button" class="btn btn-danger btn-sm remove-rule"><i class="bi bi-trash"></i></button>
                      </div>
                    </div>
                  </div>
                </div>
                <button type="button" class="btn btn-secondary btn-sm" id="addRuleBtn"><i class="bi bi-plus-circle me-2"></i> Add Rule</button>
                <small class="form-text text-muted">Add one or more grading rules. Each rule requires a grade, min score, max score, and points. Description is optional.</small>
              </div>
              <button type="submit" class="btn btn-primary">Create Grading System</button>
            </form>
          </div>
        </div>
      </div>
    </div>

    <!-- View Grading Rules Modal -->
    <div class="modal fade" id="viewGradingRulesModal" tabindex="-1">
      <div class="modal-dialog modal-md">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title"><i class="bi bi-table me-2"></i> Grading Rules</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <table class="table table-striped table-hover">
              <thead class="table-dark">
                <tr>
                  <th>Grade</th>
                  <th>Min Score</th>
                  <th>Max Score</th>
                  <th>Points</th>
                  <th>Description</th>
                </tr>
              </thead>
              <tbody id="gradingRulesTableBody">
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- View Exam Results Modal -->
    <div class="modal fade" id="viewSubjectsModal" tabindex="-1">
      <div class="modal-dialog modal-xl">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title"><i class="bi bi-table me-2"></i> Exam Results</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="filter-container mb-3 d-flex gap-3 flex-wrap">
              <!-- Stream Filter -->
              <select id="streamSelect" class="form-select w-auto">
                <option value="">All Streams</option>
                <!-- Populated dynamically -->
              </select>

              <!-- Subject Filter -->
              <select id="subjectSelect" class="form-select w-auto">
                <option value="">All Subjects</option>
                <!-- Populated dynamically -->
              </select>

              <!-- Admission No Filter -->
              <input id="admissionNoFilter" type="text" class="form-control w-auto"
                placeholder="Filter by Admission No">
            </div>

            <div class="table-container">
              <table class="table table-striped table-hover" id="resultsTable">
                <thead class="table-dark">
                  <tr id="resultsTableHeader">
                    <th class="sticky-column">Admission No</th>
                    <th class="sticky-column">Student Name</th>
                    <!-- Dynamic subject/paper columns added here -->
                  </tr>
                </thead>
                <tbody id="resultsTableBody"></tbody>
              </table>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Publish Confirmation Modal -->
    <div class="modal fade" id="publishConfirmModal" tabindex="-1">
      <div class="modal-dialog modal-md">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i> Confirm Publish</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <p id="publishConfirmMessage"></p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary" id="publishConfirmBtn">Confirm</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Second Publish Confirmation Modal -->
    <div class="modal fade" id="publishSecondConfirmModal" tabindex="-1">
      <div class="modal-dialog modal-md">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i> Final Confirmation</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <p>Are you sure you want to proceed? This is your final confirmation to publish the results.</p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary" id="publishSecondConfirmBtn">Confirm Publish</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
  $(document).ready(function() {
    // Track active tab for subject selection
    let activeTab = 'manual';
    let allStreams = [];
    let currentExamId = null;

    // Function to refresh exams and grading systems
    function refreshData() {
      $.ajax({
        url: 'exams/functions.php',
        method: 'POST',
        data: {
          action: 'get_exams'
        },
        dataType: 'json',
        success: function(json) {
          if (json.status === 'success') {
            // Refresh exams table
            const examsRows = json.exams.map((exam, index) => `
            <tr data-exam-id="${exam.exam_id}">
              <td>${index + 1}</td>
              <td>${exam.exam_name}</td>
              <td>${exam.form_name}</td>
              <td>${exam.term}</td>
              <td>${exam.status.charAt(0).toUpperCase() + exam.status.slice(1)}</td>
              <td>${exam.grading_system_name}</td>
              <td>
                <button class="btn btn-sm btn-primary view-subjects" data-exam-id="${exam.exam_id}">
                  <i class="bi bi-eye"></i> View Results
                </button>
                <button class="btn btn-sm btn-danger delete-exam" data-exam-id="${exam.exam_id}">
                  <i class="bi bi-trash"></i> Delete
                </button>
                <button class="btn btn-sm btn-info publish-exam" data-exam-id="${exam.exam_id}">
                  <i class="bi bi-check-circle"></i> Publish
                </button>
              </td>
            </tr>
          `).join('');
            $('#examsTableBody').html(examsRows);
            $('#examsTableBodyModal').html(examsRows);

            // Refresh exam dropdowns
            const examOptions = json.exams.map(exam => `
            <option value="${exam.exam_id}">
              ${exam.exam_name} (${exam.form_name}, ${exam.term}, ${exam.status.charAt(0).toUpperCase() + exam.status.slice(1)})
            </option>
          `).join('');
            $('#manualExamId, #excelExamId').html(`<option value="">Select Exam</option>${examOptions}`);
          } else {
            alert(json.message);
          }
        },
        error: function(xhr, status, error) {
          console.error('❌ AJAX Error for get_exams:', status, error, xhr.responseText);
          alert('An error occurred: ' + (xhr.responseText || 'Unknown error. Check console.'));
        }
      });

      // Refresh grading systems
      // Refresh grading systems
      $.ajax({
        url: 'exams/functions.php',
        method: 'POST',
        data: {
          action: 'get_grading_systems'
        },
        dataType: 'json',
        success: function(json) {
          if (json.status === 'success') {
            const gradingRows = json.grading_systems.map(gs => {
              const isCBC = parseInt(gs.is_cbc) === 1;
              const curriculumText = isCBC ? 'CBC' : '8-4-4';
              const badgeClass = isCBC ? 'bg-success' : 'bg-primary';

              return `
                <tr data-grading-system-id="${gs.grading_system_id}">
                    <td>${gs.name}</td>
                    <td>
                        <span class="badge ${badgeClass} px-3 py-2 fs-6">
                            ${curriculumText}
                        </span>
                    </td>
                    <td>
                        ${gs.is_default ? 
                            '<span class="badge bg-warning">Yes</span>' : 
                            'No'}
                    </td>
                    <td>
                        <button class="btn btn-sm btn-primary view-grading-rules me-1"
                            data-grading-system-id="${gs.grading_system_id}">
                            <i class="bi bi-eye"></i> View Rules
                        </button>
                        ${gs.is_default ? '' : `
                            <button class="btn btn-sm btn-danger delete-grading-system"
                                data-grading-system-id="${gs.grading_system_id}">
                                <i class="bi bi-trash"></i> Delete
                            </button>
                        `}
                    </td>
                </tr>
            `
            }).join('');

            $('#gradingSystemsTableBody').html(gradingRows);

            // Also refresh the dropdown in Create Exam modal
            const gradingOptions = json.grading_systems.map(gs => {
              const label = gs.name + (gs.is_default ? ' (Default)' : '');
              return `<option value="${gs.grading_system_id}">${label}</option>`;
            }).join('');

            $('#createExamForm select[name="grading_system_id"]').html(
              `<option value="">Select Grading System</option>${gradingOptions}`
            );
          } else {
            console.warn('Failed to load grading systems:', json.message);
          }

        },
        error: function(xhr) {
          console.error('get_grading_systems failed:', xhr.responseText);
          alert('Error loading grading systems');
        }
      });
    }


    // Fetch subjects for selected class in Edit Exam
    $('#editExamClassId').on('change', function() {
      const classId = $(this).val();
      if (classId) {
        $.post('exams/functions.php', {
          action: 'get_class_subjects_with_papers',
          class_id: classId
        }, function(response) {
          if (response.status === 'success') {
            // Subjects fetched for backend use during form submission
          } else {
            alert(response.message);
          }
        }, 'json');
      }
    });


    // Handle Create Exam
    // Handle Create Exam
    $('#createExamForm').on('submit', function(e) {
      e.preventDefault();
      const classIds = $('#examClassIds').val();
      const classData = $('#examClassIds').data('class-data');

      if (!classIds || !classData) {
        alert('Please select at least one class.');
        return;
      }

      const classArray = JSON.parse(classData); // Parse the class data including min_subjects
      const classIdArray = classIds.split(',');

      // Function to fetch subjects for a single class
      function fetchSubjectsForClass(classId) {
        return new Promise((resolve, reject) => {
          $.post('exams/functions.php', {
            action: 'get_class_subjects_with_papers',
            class_id: classId
          }, function(response) {
            if (response.status === 'success') {
              const classInfo = classArray.find(c => c.id === classId);
              resolve({
                class_id: classId,
                min_subjects: classInfo.min_subjects,
                subjects: response.subjects
              });
            } else {
              reject(response.message);
            }
          }, 'json').fail(function(xhr) {
            reject('Failed to fetch subjects for class ID ' + classId + ': ' + (xhr.responseText || 'Unknown error'));
          });
        });
      }

      // Fetch subjects for all selected classes
      Promise.all(classIdArray.map(classId => fetchSubjectsForClass(classId)))
        .then(results => {
          const formData = $('#createExamForm').serializeArray();
          formData.push({
            name: 'action',
            value: 'create_exam'
          });
          formData.push({
            name: 'class_data',
            value: JSON.stringify(results)
          }); // Send class data with min_subjects

          ajaxDebug({
            url: 'exams/functions.php',
            method: 'POST',
            data: formData,
            dataType: 'json',
            onSuccess: function(json) {
              alert(json.message);
              if (json.status === 'success') {
                $('#createExamModal').modal('hide');
                $('#createExamForm')[0].reset();
                $('#examClassDisplay, #examClassIds').val('').removeData('class-data');
                $('.min-subjects-input').val('').hide();
                $('.class-checkbox').prop('checked', false);
                refreshData();
              }
            }
          });
        })
        .catch(error => {
          alert('Error: ' + error);
        });
    });
    // Handle Edit Exam
    $(document).on('click', '.edit-exam', function() {
      const examId = $(this).data('exam-id');
      $.ajax({
        url: 'exams/functions.php',
        method: 'POST',
        data: {
          action: 'get_exam_details',
          exam_id: examId
        },
        dataType: 'json',
        success: function(json) {
          if (json.status === 'success') {
            $('#editExamId').val(json.exam.exam_id);
            $('#editExamName').val(json.exam.exam_name);
            $('#editExamClassId').val(json.exam.class_id);
            $('#editExamTerm').val(json.exam.term);
            $('#editExamStatus').val(json.exam.status);
            $('#editExamGradingSystem').val(json.exam.grading_system_id);
            $('#editExamMinSubjects').val(json.exam.min_subjects || 1);
            // $('#editExamModal').modal('show');
          } else {
            alert(json.message);
          }
        }
      });
    });


    // Handle Delete Exam
    $(document).on('click', '.delete-exam', function() {
      const examId = $(this).data('exam-id');
      if (confirm('Are you sure you want to delete this exam? This will remove all associated results.')) {
        ajaxDebug({
          url: 'exams/functions.php',
          method: 'POST',
          data: {
            action: 'delete_exam',
            exam_id: examId
          },
          dataType: 'json',
          onSuccess: function(json) {
            alert(json.message);
            if (json.status === 'success') {
              $('#manageExamsModal').modal('hide');
              refreshData();
            }
          }
        });
      }
    });

    // Handle Publish Exam
    // Handle Publish Exam
    $(document).on('click', '.publish-exam', function() {
      const examId = $(this).data('exam-id');
      console.log('🔵 Publish button clicked for exam ID:', examId);
      $('.modal').modal('hide');

      $.ajax({
        url: 'exams/functions.php',
        method: 'POST',
        data: {
          action: 'publish_results',
          exam_id: examId
        },
        dataType: 'json',
        success: function(response) {
          console.log('✅ Publish AJAX response:', response);
          if (response.status === 'confirmation_required') {
            // Show first confirmation modal
            $('#publishConfirmMessage').text(response.message);
            $('#publishConfirmBtn').text('Confirm');
            $('#publishConfirmModal').modal('show');

            // Handle first confirm button click
            $('#publishConfirmBtn').off('click').on('click', function() {
              $('#publishConfirmModal').modal('hide');
              console.log('✅ First confirmation accepted');
              // Show second confirmation modal
              $('#publishSecondConfirmModal').modal('show');

              // Handle second confirm button click
              $('#publishSecondConfirmBtn').off('click').on('click', function() {
                $('#publishSecondConfirmModal').modal('hide');
                console.log('✅ Second confirmation accepted, sending publish request');
                // Send confirmed publish request
                $.post('exams/functions.php', {
                  action: 'publish_results',
                  exam_id: examId,
                  confirm: 1
                }, function(publishResponse) {
                  console.log('✅ Confirmed publish AJAX response:', publishResponse);
                  if (publishResponse.status === 'success') {
                    $('#publishResultsModal').modal('show');
                    refreshData();
                  } else {
                    alert('Error: ' + publishResponse.message);
                  }
                }, 'json').fail(function(xhr, status, error) {
                  console.error('❌ Confirmed publish AJAX error:', status, error, xhr.responseText);
                  alert('An error occurred while publishing: ' + (xhr.responseText || 'Unknown error'));
                });
              });
            });
          } else if (response.status === 'success') {
            console.log('✅ Results published successfully');
            $('#publishResultsModal').modal('show');
            refreshData();
          } else {
            console.error('❌ Publish error:', response.message);
            alert('Error: ' + response.message);
          }
        },
        error: function(xhr, status, error) {
          console.error('❌ Publish AJAX error:', status, error, xhr.responseText);
          alert('An error occurred: ' + (xhr.responseText || 'Unknown error. Check console.'));
        }
      });
    });

    let allSubjects = [];
    let allStudents = [];

    function loadExamResults(examId) {
      currentExamId = examId;
      console.log('Loading results for exam:', examId);

      $.ajax({
        url: 'exams/functions.php',
        method: 'POST',
        data: {
          action: 'get_exam_subjects_with_results',
          exam_id: examId
        },
        dataType: 'json',
        cache: false,
        success: function(response) {
          console.log('✅ AJAX Response:', response);

          if (response.status === 'success') {
            allSubjects = response.subjects || [];
            allStudents = response.students || [];

            // Populate subject dropdown
            $('#subjectSelect')
              .empty()
              .append('<option value="">All Subjects</option>');
            allSubjects.forEach(subject => {
              $('#subjectSelect').append(
                `<option value="${subject.subject_id}">${subject.name}</option>`
              );
            });

            // Populate stream dropdown using class_id from response
            if (response.class_id) {
              $.post('exams/functions.php', {
                action: 'get_streams',
                class_id: response.class_id
              }, function(streamRes) {
                if (streamRes.status === 'success') {
                  allStreams = streamRes.streams || [];
                  $('#streamSelect')
                    .empty()
                    .append('<option value="">All Streams</option>');
                  allStreams.forEach(stream => {
                    $('#streamSelect').append(
                      `<option value="${stream.stream_id}">${stream.stream_name}</option>`
                    );
                  });
                } else {
                  console.warn('Failed to load streams:', streamRes.message);
                }
              }, 'json');
            }

            // Initial render
            applyFilters();
          } else {
            alert(response.message || 'Error loading exam results');
          }
        },
        error: function(xhr, status, error) {
          console.error('❌ AJAX Error:', status, error, xhr.responseText);
          alert('Failed to fetch exam results');
        }
      });
    }

    function applyFilters() {
      const streamId = $('#streamSelect').val();
      const subjectId = $('#subjectSelect').val();
      const admFilter = $('#admissionNoFilter').val().trim().toLowerCase();

      let filteredStudents = allStudents;

      // Stream filter
      if (streamId) {
        filteredStudents = filteredStudents.filter(s =>
          s.stream_id != null && String(s.stream_id) === String(streamId)
        );
      }

      // Admission number filter – SAFER VERSION
      if (admFilter) {
        filteredStudents = filteredStudents.filter(s => {
          const adm = String(s.admission_no ?? '').toLowerCase();
          return adm.includes(admFilter);
        });
      }

      renderResultsTable(subjectId, filteredStudents);
    }

    // Updated render function that accepts subject filter + pre-filtered students
    function renderResultsTable(subjectId = '', studentsToShow = allStudents) {
      const $header = $('#resultsTableHeader');
      const $body = $('#resultsTableBody');

      $header.find('th:not(.sticky-column)').remove();
      $body.empty();

      const subjectsToRender = subjectId ?
        allSubjects.filter(s => String(s.subject_id) === String(subjectId)) :
        allSubjects;

      // Build dynamic headers
      subjectsToRender.forEach(subject => {
        if (subject.use_papers && subject.papers?.length > 0) {
          subject.papers.forEach(paper => {
            $header.append(
              `<th>${subject.name} - ${paper.paper_name} (Max: ${paper.max_score})</th>`
            );
          });
        } else {
          $header.append(`<th>${subject.name} (Max: 100)</th>`);
        }
      });

      // Build rows
      studentsToShow.forEach(student => {
        let row = `
            <tr data-student-id="${student.student_id}">
                <td class="sticky-column">${student.admission_no}</td>
                <td class="sticky-column">${student.full_name}</td>
        `;

        subjectsToRender.forEach(subject => {
          const results = student.results?.[subject.subject_id] || {};

          if (subject.use_papers && subject.papers?.length > 0) {
            subject.papers.forEach(paper => {
              const score = results[paper.paper_id]?.score ?? '-';
              row += `
                        <td class="editable-cell"
                            data-student-id="${student.student_id}"
                            data-subject-id="${subject.subject_id}"
                            data-paper-id="${paper.paper_id}"
                            data-max-score="${paper.max_score}">
                            ${score}
                        </td>`;
            });
          } else {
            const score = results['null']?.score ?? '-';
            row += `
                    <td class="editable-cell"
                        data-student-id="${student.student_id}"
                        data-subject-id="${subject.subject_id}"
                        data-paper-id="null"
                        data-max-score="100">
                        ${score}
                    </td>`;
          }
        });

        row += '</tr>';
        $body.append(row);
      });
    }
    // Function to render table headers and body
    function renderTable(subjectId = '', admissionNo = '') {
      const $headerRow = $('#resultsTableHeader');
      const $body = $('#resultsTableBody');
      $headerRow.find('th:not(.sticky-column)').remove();
      $body.empty();
      const filteredSubjects = subjectId ?
        allSubjects.filter(subject => subject.subject_id == subjectId) :
        allSubjects;
      console.log('🔍 Filtered subjects:', JSON.stringify(filteredSubjects, null, 2));
      filteredSubjects.forEach(subject => {
        if (!subject.name) {
          console.warn(`⚠️ Subject ID ${subject.subject_id} has no name, skipping`);
          return;
        }
        if (subject.use_papers && subject.papers && subject.papers.length > 0) {
          subject.papers.forEach(paper => {
            $headerRow.append(
              `<th>${subject.name} - ${paper.paper_name} (Max: ${paper.max_score})</th>`
            );
          });
        } else {
          $headerRow.append(`<th>${subject.name} (Max: 100)</th>`);
        }
      });
      const filteredStudents = admissionNo ?
        allStudents.filter(student => student.admission_no.toLowerCase().includes(admissionNo.toLowerCase())) :
        allStudents;
      filteredStudents.forEach(student => {
        let rowHtml = `
            <tr data-student-id="${student.student_id}">
                <td class="sticky-column">${student.admission_no}</td>
                <td class="sticky-column">${student.full_name}</td>
        `;
        filteredSubjects.forEach(subject => {
          if (!subject.name) return;
          const results = student.results[subject.subject_id] || {};
          if (subject.use_papers && subject.papers && subject.papers.length > 0) {
            subject.papers.forEach(paper => {
              const score = results[paper.paper_id]?.score ?? '-';
              rowHtml += `
                        <td class="editable-cell" 
                            data-student-id="${student.student_id}" 
                            data-subject-id="${subject.subject_id}" 
                            data-paper-id="${paper.paper_id}" 
                            data-max-score="${paper.max_score}">
                            ${score}
                        </td>`;
            });
          } else {
            const score = results['null']?.score ?? '-';
            console.log(`Debug: Student ${student.full_name} (ID ${student.student_id}), Subject ${subject.name} (ID ${subject.subject_id}), use_papers=0, score: ${score}, raw results:`, results);
            rowHtml += `
                    <td class="editable-cell" 
                        data-student-id="${student.student_id}" 
                        data-subject-id="${subject.subject_id}" 
                        data-paper-id="null" 
                        data-max-score="100">
                        ${score}
                    </td>`;
          }
        });
        rowHtml += '</tr>';
        $body.append(rowHtml);
      });
    }
    $(document).on('click', '.view-subjects', function() {
      const examId = $(this).data('exam-id');
      $('#viewSubjectsModal').data('exam-id', examId);
      $('#streamSelect, #subjectSelect').val('');
      $('#admissionNoFilter').val('');
      loadExamResults(examId);
      $('#viewSubjectsModal').modal('show');
    });

    $('#streamSelect, #subjectSelect').on('change', applyFilters);
    $('#admissionNoFilter').on('input', applyFilters);

    // Reset filters when modal is shown
    $('#viewSubjectsModal').on('shown.bs.modal', function() {
      $('#streamSelect, #subjectSelect').val('');
      $('#admissionNoFilter').val('');
      applyFilters();
    });
    // Handle editing result cells
    $(document).on('click', '.editable-cell', function() {
      const $cell = $(this);
      if ($cell.find('input').length) return; // Already editing

      const currentScore = $cell.text() === '-' ? '' : $cell.text();
      const maxScore = $cell.data('max-score');
      $cell.html(`<input type="number" value="${currentScore}" min="0" max="${maxScore}" step="0.01" class="form-control">`);
      const $input = $cell.find('input');
      $input.focus();

      $input.on('blur keypress', function(e) {
        if (e.type === 'keypress' && e.which !== 13) return; // Save on Enter or blur
        const newScore = $input.val();
        const studentId = $cell.data('student-id');
        const subjectId = $cell.data('subject-id');
        const paperId = $cell.data('paper-id') === 'null' ? null : $cell.data('paper-id');
        const examId = $('#viewSubjectsModal').data('exam-id');

        // Validate score
        if (newScore !== '' && (isNaN(newScore) || newScore < 0 || newScore > maxScore)) {
          alert(`Score must be between 0 and ${maxScore}`);
          $cell.text(currentScore || '-');
          return;
        }

        // Save result via AJAX
        $.ajax({
          url: 'exams/functions.php',
          method: 'POST',
          data: {
            action: 'save_exam_result',
            exam_id: examId,
            student_id: studentId,
            subject_id: subjectId,
            paper_id: paperId,
            score: newScore
          },
          dataType: 'json',
          success: function(json) {
            console.log('✅ Save result response:', json);
            if (json.status === 'success') {
              $cell.text(newScore || '-');
            } else {
              alert(json.message);
              $cell.text(currentScore || '-');
            }
          },
          error: function(xhr, status, error) {
            console.error('❌ AJAX Error for save_exam_result:', status, error, xhr.responseText);
            alert('Failed to save result: ' + xhr.responseText);
            $cell.text(currentScore || '-');
          }
        });
      });
    });

    // Handle Edit Results from View Subjects
    $(document).on('click', '.edit-results', function() {
      const examId = $(this).data('exam-id');
      const subjectId = $(this).data('subject-id');
      $.ajax({
        url: 'exams/functions.php',
        method: 'POST',
        data: {
          action: 'get_exam_subjects_with_papers',
          exam_id: examId,
          subject_id: subjectId
        },
        dataType: 'json',
        success: function(json) {
          if (json.status === 'success') {
            const subject = json.subjects[0];
            $('#manualExamId').val(examId).trigger('change');
            setTimeout(() => {
              $('#manualSubjectDisplay').val(subject.name);
              $('#manualSubjectIds').val(subject.subject_id);
              $('#manualScope').val('class').prop('disabled', false).trigger('change');
              $('#manualClassId').val(json.class_id);
              generateManualResultEntry(examId, 'class', json.class_id, null, null, subject.subject_id);
              $('#viewSubjectsModal').modal('hide');
              $('#uploadResultsModal').modal('show');
            }, 500); // Wait for exam subjects to load
          } else {
            alert(json.message);
          }
        }
      });
    });

    // Manual Entry: Handle Exam Change
    $('#manualExamId').on('change', function() {
      const examId = $(this).val();
      activeTab = 'manual';
      $('#manualSubjectDiv, #manualClassDiv, #manualStreamDiv, #manualStudentDiv, #manualResultsEntry').hide();
      $('#manualSubjectDisplay, #manualSubjectIds').val('');
      $('#manualClassId, #manualStreamId, #manualStudentId').empty().append('<option value="">Select</option>');
      $('#manualResultsEntry').empty();
      $('#manualScope').val('').prop('disabled', true);
      if (examId) {
        $.post('exams/functions.php', {
          action: 'get_exam_subjects_with_papers',
          exam_id: examId
        }, function(response) {
          if (response.status === 'success') {
            $('#manualClassId').html(`<option value="${response.class_id}">${response.form_name}</option>`).prop('disabled', true);
            $('#manualClassDiv').show();
            const subjectCheckboxes = $('#subjectCheckboxes');
            subjectCheckboxes.empty();
            response.subjects.forEach(subject => {
              subjectCheckboxes.append(`
            <div class="form-check">
              <input class="form-check-input subject-checkbox" type="checkbox" value="${subject.subject_id}" id="subject_${subject.subject_id}" data-name="${subject.name}">
              <label class="form-check-label" for="subject_${subject.subject_id}">${subject.name}</label>
            </div>
          `);
            });
            $('#manualSubjectDiv').show();
            $('#confirmSubjectsBtn').off('click').on('click', function() {
              const selectedSubjects = $('.subject-checkbox:checked').map(function() {
                return {
                  id: $(this).val(),
                  name: $(this).data('name')
                };
              }).get();
              const subjectIds = selectedSubjects.map(s => s.id).join(',');
              const subjectNames = selectedSubjects.map(s => s.name).join(', ');
              if (selectedSubjects.length === 0) {
                alert('Please select at least one subject.');
                return;
              }
              $('#manualSubjectDisplay').val(subjectNames);
              $('#manualSubjectIds').val(subjectIds);
              $('#manualScope').prop('disabled', false);
              $('#selectSubjectsModal').modal('hide');
              $('#uploadResultsModal').modal('show');
              if ($('#manualScope').val()) {
                generateManualResultEntry(examId, $('#manualScope').val(), response.class_id, $('#manualStreamId').val() || null, $('#manualStudentId').val() || null, subjectIds);
              }
            });
          } else {
            alert(response.message);
          }
        }, 'json');
      }
    });

    // Excel Upload: Handle Exam Change
    $('#excelExamId').on('change', function() {
      const examId = $(this).val();
      activeTab = 'excel';
      $('#excelSubjectDiv, #excelClassDiv, #excelStreamDiv, #excelStudentDiv, #excelSubjectsInfo').hide();
      $('#excelSubjectDisplay, #excelSubjectIds, #excelAdmissionNo').val('');
      $('#excelClassId, #excelStreamId, #excelStudentId').empty().append('<option value="">Select</option>');
      $('#examSubjectsList, #excelHeaderFormat').empty();
      $('#downloadExcelTemplate').hide();
      $('#excelScope').val('').prop('disabled', true);
      $('#includeStudents').prop('checked', false);
      if (examId) {
        $.post('exams/functions.php', {
          action: 'get_exam_subjects_with_papers',
          exam_id: examId
        }, function(response) {
          if (response.status === 'success') {
            $('#excelClassId').html(`<option value="${response.class_id}">${response.form_name}</option>`).prop('disabled', true);
            $('#excelClassDiv').show();
            $('#excelScope').prop('disabled', false);
            const subjectCheckboxes = $('#subjectCheckboxes');
            subjectCheckboxes.empty();
            response.subjects.forEach(subject => {
              subjectCheckboxes.append(`
            <div class="form-check">
              <input class="form-check-input subject-checkbox" type="checkbox" value="${subject.subject_id}" id="subject_${subject.subject_id}" data-name="${subject.name}">
              <label class="form-check-label" for="subject_${subject.subject_id}">${subject.name}</label>
            </div>
          `);
            });
            $('#excelSubjectDiv').show();
          } else {
            alert(response.message);
          }
        }, 'json');
      }
    });

    // Confirm Subjects in Child Modal
    $('#confirmSubjectsBtn').on('click', function() {
      const selectedSubjects = $('.subject-checkbox:checked').map(function() {
        return {
          id: $(this).val(),
          name: $(this).data('name')
        };
      }).get();
      const subjectIds = selectedSubjects.map(s => s.id).join(',');
      const subjectNames = selectedSubjects.map(s => s.name).join(', ');

      if (selectedSubjects.length === 0) {
        alert('Please select at least one subject.');
        return;
      }

      if (activeTab === 'manual') {
        $('#manualSubjectDisplay').val(subjectNames);
        $('#manualSubjectIds').val(subjectIds);
        $('#manualScope').prop('disabled', false);
      } else if (activeTab === 'excel') {
        $('#excelSubjectDisplay').val(subjectNames);
        $('#excelSubjectIds').val(subjectIds);
        $('#excelScope').prop('disabled', false);
        // Update Excel header format and show download link
        $.post('exams/functions.php', {
          action: 'get_exam_subjects_with_papers',
          exam_id: $('#excelExamId').val(),
          subject_ids: subjectIds
        }, function(response) {
          if (response.status === 'success') {
            let subjectsList = response.subjects.map(subject => {
              return subject.use_papers && subject.papers.length > 0 ?
                `${subject.name} (${subject.papers.map(p => p.paper_name).join(', ')})` :
                subject.name;
            }).join(', ');
            let headerFormat = 'Admission No';
            response.subjects.forEach(subject => {
              if (subject.use_papers && subject.papers.length > 0) {
                subject.papers.forEach(p => {
                  headerFormat += `, ${subject.name}-${p.paper_name}`;
                });
              } else {
                headerFormat += `, ${subject.name}`;
              }
            });
            $('#examSubjectsList').text(subjectsList);
            $('#excelHeaderFormat').text(headerFormat);
            $('#excelSubjectsInfo').show();
            // Show and configure download link
            $('#downloadExcelTemplate').show().attr('href', `exams/functions.php?action=generate_excel_template&exam_id=${$('#excelExamId').val()}&subject_ids=${encodeURIComponent(subjectIds)}`);
          } else {
            alert(response.message);
          }
        }, 'json');
      }

      // Keep parent modal open
      $('#selectSubjectsModal').modal('hide');
      $('#uploadResultsModal').modal('show');
    });

    // Manual Entry: Handle Scope Change
    $('#manualScope').on('change', function() {
      const scope = $(this).val();
      const examId = $('#manualExamId').val();
      const subjectIds = $('#manualSubjectIds').val();
      $('#manualStreamDiv, #manualStudentDiv, #manualResultsEntry').hide();
      $('#manualStreamId, #manualStudentId').empty().append('<option value="">Select</option>');
      $('#manualResultsEntry').empty();
      if (scope && examId && subjectIds) {
        const classId = $('#manualClassId').val();
        if (scope === 'stream') {
          $.post('exams/functions.php', {
            action: 'get_streams',
            class_id: classId
          }, function(response) {
            if (response.status === 'success') {
              const streamSelect = $('#manualStreamId');
              streamSelect.empty().append('<option value="">Select Stream</option>');
              response.streams.forEach(stream => {
                streamSelect.append(`<option value="${stream.stream_id}">${stream.stream_name}</option>`);
              });
              $('#manualStreamDiv').show();
            } else {
              alert(response.message);
            }
          }, 'json');
        } else if (scope === 'student') {
          $.post('exams/functions.php', {
            action: 'get_students',
            class_id: classId
          }, function(response) {
            if (response.status === 'success') {
              const studentSelect = $('#manualStudentId');
              studentSelect.empty().append('<option value="">Select Student</option>');
              response.students.forEach(student => {
                studentSelect.append(`<option value="${student.student_id}">${student.full_name}</option>`);
              });
              $('#manualStudentDiv').show();
            } else {
              alert(response.message);
            }
          }, 'json');
        } else if (scope === 'class') {
          generateManualResultEntry(examId, scope, classId, null, null, subjectIds);
        }
      }
    });

    // Manual Entry: Populate students when stream changes
    $('#manualStreamId').on('change', function() {
      const streamId = $(this).val();
      const examId = $('#manualExamId').val();
      const scope = $('#manualScope').val();
      const subjectIds = $('#manualSubjectIds').val();
      $('#manualResultsEntry').hide().empty();
      if (streamId && examId && subjectIds) {
        generateManualResultEntry(examId, scope, $('#manualClassId').val(), streamId, null, subjectIds);
      }
    });

    // Manual Entry: Populate students when student changes
    $('#manualStudentId').on('change', function() {
      const studentId = $(this).val();
      const examId = $('#manualExamId').val();
      const scope = $('#manualScope').val();
      const subjectIds = $('#manualSubjectIds').val();
      $('#manualResultsEntry').hide().empty();
      if (studentId && examId && subjectIds) {
        generateManualResultEntry(examId, scope, $('#manualClassId').val(), null, studentId, subjectIds);
      }
    });

    // Manual Entry: Generate result entry table with papers for multiple subjects
    function generateManualResultEntry(examId, scope, classId, streamId = null, studentId = null, subjectIds = '') {
      if (!examId || !scope || (scope === 'class' && !classId) || (scope === 'stream' && !streamId) || (scope === 'student' && !studentId) || !subjectIds) {
        $('#manualResultsEntry').hide().empty();
        return;
      }
      $.post('exams/functions.php', {
        action: 'get_exam_subjects_with_papers',
        exam_id: examId,
        subject_ids: subjectIds
      }, function(response) {
        if (response.status === 'success') {
          const subjects = response.subjects;
          const data = {
            action: 'get_students',
            exam_id: examId
          };
          if (scope === 'class') data.class_id = classId;
          if (scope === 'stream') data.stream_id = streamId;
          if (scope === 'student') data.student_id = studentId;
          $.post('exams/functions.php', data, function(res) {
            if (res.status === 'success') {
              let table = `<table class="table table-bordered table-hover"><thead class="table-dark"><tr><th>Student</th>`;
              const subjectColumns = [];
              subjects.forEach(subject => {
                let papers = subject.use_papers && subject.papers.length > 0 ? subject.papers : [{
                  paper_id: 'null',
                  paper_name: '',
                  max_score: 100
                }];
                papers.forEach(p => {
                  const columnHeader = p.paper_name ? `${subject.name} - ${p.paper_name} (out of ${p.max_score})` : `${subject.name} (out of 100)`;
                  table += `<th>${columnHeader}</th>`;
                  subjectColumns.push({
                    subject_id: subject.subject_id,
                    paper_id: p.paper_id === 'null' ? 'null' : p.paper_id,
                    max_score: p.max_score || 100
                  });
                });
              });
              table += `</tr></thead><tbody>`;
              res.students.forEach(student => {
                table += `<tr><td>${student.full_name}</td>`;
                subjectColumns.forEach(col => {
                  const value = student.results?.[col.subject_id]?.[col.paper_id]?.score || '';
                  table += `<td><input type="number" class="form-control score-input" name="results[${student.student_id}][${col.subject_id}][${col.paper_id}]" min="0" max="${col.max_score}" step="0.01" value="${value}" placeholder="Score"></td>`;
                });
                table += `</tr>`;
              });
              table += '</tbody></table>';
              $('#manualResultsEntry').html(table).show();
            } else {
              alert(res.message);
            }
          }, 'json');
        } else {
          alert(response.message);
        }
      }, 'json');
    }

    // Manual Entry: Form Submission
    $('#manualResultsForm').on('submit', function(e) {
      e.preventDefault();
      const formData = $(this).serializeArray();
      $.post('exams/functions.php', formData, function(response) {
        if (response.status === 'success') {
          $('#uploadResultsModal').modal('hide');
          alert(response.message);
          refreshData();
        } else {
          alert('Error: ' + response.message);
        }
      }, 'json');
    });

    // Excel Upload: Handle Scope Change
    $('#excelScope').on('change', function() {
      const scope = $(this).val();
      const examId = $('#excelExamId').val();
      $('#excelStreamDiv, #excelStudentDiv').hide();
      $('#excelStreamId, #excelStudentId, #excelAdmissionNo').val('').empty().append('<option value="">Select</option>');
      if (scope && examId) {
        const classId = $('#excelClassId').val();
        if (scope === 'stream') {
          $.post('exams/functions.php', {
            action: 'get_streams',
            class_id: classId
          }, function(response) {
            if (response.status === 'success') {
              const streamSelect = $('#excelStreamId');
              streamSelect.empty().append('<option value="">Select Stream</option>');
              response.streams.forEach(stream => {
                streamSelect.append(`<option value="${stream.stream_id}">${stream.stream_name}</option>`);
              });
              $('#excelStreamDiv').show();
            } else {
              alert(response.message);
            }
          }, 'json');
        } else if (scope === 'student') {
          $.post('exams/functions.php', {
            action: 'get_students',
            class_id: classId
          }, function(response) {
            if (response.status === 'success') {
              const studentSelect = $('#excelStudentId');
              studentSelect.empty().append('<option value="">Select Student</option>');
              response.students.forEach(student => {
                studentSelect.append(`<option value="${student.student_id}" data-admission-no="${student.admission_no}">${student.full_name}</option>`);
              });
              $('#excelStudentDiv').show();
            } else {
              alert(response.message);
            }
          }, 'json');
        }
        updateDownloadLink();
      }
    });

    // Excel Upload: Handle Stream, Student, Admission No, or Checkbox Change
    $('#excelStreamId, #excelStudentId, #excelAdmissionNo, #includeStudents').on('change', updateDownloadLink);

    // Confirm Subjects in Child Modal
    $('#confirmSubjectsBtn').on('click', function() {
      const selectedSubjects = $('.subject-checkbox:checked').map(function() {
        return {
          id: $(this).val(),
          name: $(this).data('name')
        };
      }).get();
      const subjectIds = selectedSubjects.map(s => s.id).join(',');
      const subjectNames = selectedSubjects.map(s => s.name).join(',');

      if (selectedSubjects.length === 0) {
        alert('Please select at least one subject.');
        return;
      }

      if (activeTab === 'manual') {
        $('#manualSubjectDisplay').val(subjectNames);
        $('#manualSubjectIds').val(subjectIds);
        $('#manualScope').prop('disabled', false);
      } else if (activeTab === 'excel') {
        $('#excelSubjectDisplay').val(subjectNames);
        $('#excelSubjectIds').val(subjectIds);
        $('#excelScope').prop('disabled', false);
        $.post('exams/functions.php', {
          action: 'get_exam_subjects_with_papers',
          exam_id: $('#excelExamId').val(),
          subject_ids: subjectIds
        }, function(response) {
          if (response.status === 'success') {
            let subjectsList = response.subjects.map(subject => {
              return subject.use_papers && subject.papers.length > 0 ?
                `${subject.name} (${subject.papers.map(p => p.paper_name).join(', ')})` :
                subject.name;
            }).join(', ');
            let headerFormat = 'Admission No';
            response.subjects.forEach(subject => {
              if (subject.use_papers && subject.papers.length > 0) {
                subject.papers.forEach(p => {
                  headerFormat += `, ${subject.name}-${p.paper_name}`;
                });
              } else {
                headerFormat += `, ${subject.name}`;
              }
            });
            $('#examSubjectsList').text(subjectsList);
            $('#excelHeaderFormat').text(headerFormat);
            $('#excelSubjectsInfo').show();
            updateDownloadLink();
          } else {
            alert(response.message);
          }
        }, 'json');
      }

      $('#selectSubjectsModal').modal('hide');
      $('#uploadResultsModal').modal('show');
    });
    // Function to update download link
    function updateDownloadLink() {
      const examId = $('#excelExamId').val();
      const subjectIds = $('#excelSubjectIds').val();
      const scope = $('#excelScope').val();
      const classId = $('#excelClassId').val();
      const streamId = $('#excelStreamId').val();
      const studentId = $('#excelStudentId').val();
      const admissionNo = $('#excelAdmissionNo').val();
      const includeStudents = $('#includeStudents').is(':checked');

      if (examId && subjectIds && scope && classId) {
        let url = `exams/functions.php?action=generate_excel_template&exam_id=${examId}&subject_ids=${encodeURIComponent(subjectIds)}&scope=${scope}&class_id=${classId}&include_students=${includeStudents ? 1 : 0}`;
        if (scope === 'stream' && streamId) {
          url += `&stream_id=${streamId}`;
        } else if (scope === 'student') {
          if (admissionNo) {
            url += `&admission_no=${encodeURIComponent(admissionNo)}`;
          } else if (studentId) {
            const selectedOption = $('#excelStudentId option:selected');
            const admissionNoFromSelect = selectedOption.data('admission-no') || '';
            url += `&admission_no=${encodeURIComponent(admissionNoFromSelect)}`;
          }
        }
        $('#downloadExcelTemplate').attr('href', url).show();
      } else {
        $('#downloadExcelTemplate').hide();
      }
    }

    // Excel Upload: Form Submission
    $('#excelResultsForm').on('submit', function(e) {
      e.preventDefault();
      const formData = new FormData(this);
      formData.append('exam_id', $('#excelExamId').val());
      if ($('#excelScope').val() === 'student') {
        formData.append('student_id', $('#excelStudentId').val());
      }
      console.log('Sending AJAX:', Object.fromEntries(formData));
      $.ajax({
        url: 'exams/functions.php',
        method: 'POST',
        data: formData,
        contentType: false,
        processData: false,
        dataType: 'json',
        success: function(response) {
          console.log('✅ AJAX Response:', response);
          if (response.status === 'success') {
            $('#uploadResultsModal').modal('hide');
            alert(response.message);
            refreshData();
          } else {
            alert('Error: ' + response.message);
          }
        },
        error: function(xhr, status, error) {
          console.error('❌ AJAX Error:', status, error, xhr.responseText);
          alert('An error occurred: ' + (xhr.responseText || 'Unknown error. Check console.'));
        }
      });
    });

    // Create Grading System
    $('#createGradingForm').on('submit', function(e) {
      e.preventDefault();
      ajaxDebug({
        url: 'exams/functions.php',
        method: 'POST',
        data: $(this).serialize() + '&action=create_grading_system',
        dataType: 'json',
        onSuccess: function(json) {
          alert(json.message);
          if (json.status === 'success') {
            $('#createGradingModal').modal('hide');
            $('#manageGradingModal').modal('show');
            refreshData();
          }
        }
      });
    });

    // Dynamic Grading Rules
    let ruleIndex = 1;
    $('#addRuleBtn').on('click', function() {
      const newRow = `
      <div class="grading-rule-row mb-3">
        <div class="row g-2">
          <div class="col-md-2">
            <input type="text" class="form-control" name="rules[${ruleIndex}][grade]" placeholder="Grade (e.g., A)" required>
          </div>
          <div class="col-md-2">
            <input type="number" class="form-control" name="rules[${ruleIndex}][min_score]" placeholder="Min Score" min="0" max="100" step="0.01" required>
          </div>
          <div class="col-md-2">
            <input type="number" class="form-control" name="rules[${ruleIndex}][max_score]" placeholder="Max Score" min="0" max="100" step="0.01" required>
          </div>
          <div class="col-md-2">
            <input type="number" class="form-control" name="rules[${ruleIndex}][points]" placeholder="Points" min="0" required>
          </div>
          <div class="col-md-3">
            <input type="text" class="form-control" name="rules[${ruleIndex}][description]" placeholder="Description (optional)">
          </div>
          <div class="col-md-1">
            <button type="button" class="btn btn-danger btn-sm remove-rule"><i class="bi bi-trash"></i></button>
          </div>
        </div>
      </div>`;
      $('#gradingRulesContainer').append(newRow);
      ruleIndex++;
    });

    $(document).on('click', '.remove-rule', function() {
      if ($('.grading-rule-row').length > 1) {
        $(this).closest('.grading-rule-row').remove();
      } else {
        alert('At least one grading rule is required.');
      }
    });

    // View Grading Rules
    $(document).on('click', '.view-grading-rules', function() {
      const gradingSystemId = $(this).data('grading-system-id');
      $.ajax({
        url: 'exams/functions.php',
        method: 'POST',
        data: {
          action: 'get_grading_rules',
          grading_system_id: gradingSystemId
        },
        dataType: 'json',
        success: function(json) {
          if (json.status === 'success') {
            const rulesRows = json.rules.map(rule => `
            <tr>
              <td>${rule.grade}</td>
              <td>${rule.min_score}</td>
              <td>${rule.max_score}</td>
              <td>${rule.points}</td>
              <td>${rule.description || ''}</td>
            </tr>
          `).join('');
            $('#gradingRulesTableBody').html(rulesRows);
            $('#viewGradingRulesModal').modal('show');
          } else {
            alert(json.message);
          }
        }
      });
    });

    // Delete Grading System
    $(document).on('click', '.delete-grading-system', function() {
      const gradingSystemId = $(this).data('grading-system-id');
      if (confirm('Are you sure you want to delete this grading system?')) {
        ajaxDebug({
          url: 'exams/functions.php',
          method: 'POST',
          data: {
            action: 'delete_grading_system',
            grading_system_id: gradingSystemId
          },
          dataType: 'json',
          onSuccess: function(json) {
            alert(json.message);
            if (json.status === 'success') {
              refreshData();
            }
          }
        });
      }
    });

    // AJAX Helper
    function ajaxDebug(options) {
      console.log('🔹 Sending AJAX:', options);
      $.ajax({
        ...options,
        success: function(json, status, xhr) {
          console.log('✅ AJAX Response:', json);
          if (options.onSuccess) options.onSuccess(json);
        },
        error: function(xhr, status, error) {
          console.error('❌ AJAX Error:', status, error);
          console.log('🔴 Response text:', xhr.responseText);
          try {
            const json = JSON.parse(xhr.responseText);
            alert(json.message || 'An error occurred. Check console.');
          } catch (e) {
            alert('An error occurred: ' + (xhr.responseText || 'Unknown error. Check console.'));
          }
        }
      });
    }

    // Initial data load
    refreshData();
  });


  // Confirm Classes in Select Classes Modal
  $('#confirmClassesBtn').on('click', function() {
    const selectedClasses = $('.class-checkbox:checked').map(function() {
      const classId = $(this).val();
      const minSubjectsInput = $(`input[name="min_subjects_${classId}"]`).val();
      return {
        id: classId,
        name: $(this).data('name'),
        min_subjects: minSubjectsInput ? parseInt(minSubjectsInput) : null // Set to null if empty
      };
    }).get();

    if (selectedClasses.length === 0) {
      alert('Please select at least one class.');
      return;
    }

    // Validate that each selected class has a valid min_subjects value
    for (const cls of selectedClasses) {
      if (!cls.min_subjects || cls.min_subjects < 1) {
        alert(`Please enter a valid minimum number of subjects (at least 1) for ${cls.name}.`);
        return;
      }
    }

    const classIds = selectedClasses.map(c => c.id).join(',');
    const classNames = selectedClasses.map(c => c.name).join(', ');
    const classData = JSON.stringify(selectedClasses); // Include min_subjects in the data

    $('#examClassDisplay').val(classNames);
    $('#examClassIds').val(classIds);
    $('#examClassIds').data('class-data', classData); // Store full class data including min_subjects
    $('#selectClassesModal').modal('hide');
    $('#createExamModal').modal('show');
  });

  // Show/hide min subjects input based on checkbox state
  $(document).on('change', '.class-checkbox', function() {
    const $minSubjectsInput = $(this).closest('.form-check').find('.min-subjects-input');
    if ($(this).is(':checked')) {
      $minSubjectsInput.show();
    } else {
      $minSubjectsInput.hide().val('');
    }
  });
</script>

<?php include __DIR__ . '/../footer.php'; ?>