<?php
// subjects/index.php
// session_start();
include __DIR__ . '/../header.php';
include __DIR__ . '/../sidebar.php';
require __DIR__ . '/../../connection/db.php';

// Check if user is logged in and has appropriate permissions
if (!isset($_SESSION['user_id']) || !isset($_SESSION['school_id']) || !isset($_SESSION['role_id'])) {
  header("Location: ../index.php");
  exit;
}

// Fetch subjects for this school
// $stmt = $conn->prepare("SELECT subject_id, name, type FROM subjects WHERE school_id = ? ORDER BY name");
// $stmt->bind_param("i", $_SESSION['school_id']);
// $stmt->execute();
// $subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
// $totalSubjects = count($subjects);
// $stmt->close();

// Right after the session/role check, before any HTML

// Get total subject count safely
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM subjects WHERE school_id = ?");
$stmt->bind_param("i", $_SESSION['school_id']);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$totalSubjects = (int)$result['total'];
$stmt->close();
// Fetch classes for this school
$stmt = $conn->prepare("SELECT class_id, form_name FROM classes WHERE school_id = ? ORDER BY form_name");
$stmt->bind_param("i", $_SESSION['school_id']);
$stmt->execute();
$classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch class-subject assignments
$stmt = $conn->prepare("
    SELECT cs.class_subject_id, cs.class_id, cs.subject_id, cs.type, cs.use_papers, c.form_name, s.name
    FROM class_subjects cs
    JOIN classes c ON cs.class_id = c.class_id
    JOIN subjects s ON cs.subject_id = s.subject_id
    WHERE c.school_id = ?
    ORDER BY c.form_name, s.name
");
$stmt->bind_param("i", $_SESSION['school_id']);
$stmt->execute();
$class_subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Organize subjects by class for display
$classSubjects = [];
foreach ($classes as $class) {
  $classSubjects[$class['class_id']] = [
    'form_name' => $class['form_name'],
    'subjects' => []
  ];
}
foreach ($class_subjects as $cs) {
  $classSubjects[$cs['class_id']]['subjects'][] = [
    'class_subject_id' => $cs['class_subject_id'],
    'subject_id' => $cs['subject_id'],
    'name' => $cs['name'],
    'type' => $cs['type'],
    'use_papers' => $cs['use_papers']
  ];
}
?>
<div class="content">
  <div class="container-fluid">
    <div class="container py-4">
      <!-- Title with dynamic count -->
      <h3 class="mb-4 d-flex align-items-center">
        <i class="bi bi-book me-2"></i> Subject Management
        <span class="badge bg-primary ms-3 fs-6">Total Subjects: <?php echo $totalSubjects; ?></span>
      </h3>

      <!-- Subject Management Menu -->
      <div class="row g-4 mb-4">
        <!-- Add Subject -->
        <div class="col-md-3">
          <div class="card shadow-sm border-0 h-100 text-center">
            <div class="card-body d-flex flex-column justify-content-center">
              <i class="bi bi-plus-square display-5 text-primary"></i>
              <h5 class="mt-3">Add Subject</h5>
              <p class="text-muted">Create a new subject for the school.</p>
              <button class="btn btn-primary mt-auto" data-bs-toggle="modal" data-bs-target="#addSubjectModal">
                <i class="bi bi-plus-circle me-2"></i> Add Subject
              </button>
            </div>
          </div>
        </div>

        <!-- Manage Subjects -->
        <div class="col-md-3">
          <div class="card shadow-sm border-0 h-100 text-center">
            <div class="card-body d-flex flex-column justify-content-center">
              <i class="bi bi-gear display-5 text-warning"></i>
              <h5 class="mt-3">Manage Subjects</h5>
              <p class="text-muted">View, edit, or delete subjects.</p>
              <button class="btn btn-warning mt-auto" data-bs-toggle="modal" data-bs-target="#manageSubjectsModal">
                <i class="bi bi-gear me-2"></i> Manage Subjects
              </button>
            </div>
          </div>
        </div>

        <!-- Assign Subjects -->
        <div class="col-md-3">
          <div class="card shadow-sm border-0 h-100 text-center">
            <div class="card-body d-flex flex-column justify-content-center">
              <i class="bi bi-diagram-2 display-5 text-info"></i>
              <h5 class="mt-3">Assign Subjects</h5>
              <p class="text-muted">Assign subjects to a class as compulsory or elective.</p>
              <button class="btn btn-info text-white mt-auto" data-bs-toggle="modal" data-bs-target="#assignSubjectsModal">
                <i class="bi bi-plus-circle me-2"></i> Assign Subjects
              </button>
            </div>
          </div>
        </div>

        <!-- Manage Assignments -->
        <div class="col-md-3">
          <div class="card shadow-sm border-0 h-100 text-center">
            <div class="card-body d-flex flex-column justify-content-center">
              <i class="bi bi-gear display-5 text-secondary"></i>
              <h5 class="mt-3">Manage Assignments</h5>
              <p class="text-muted">View or remove subject assignments.</p>
              <button class="btn btn-secondary mt-auto" data-bs-toggle="modal" data-bs-target="#manageAssignmentsModal">
                <i class="bi bi-gear me-2"></i> Manage Assignments
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Class and Subject List -->
      <div class="card shadow-sm border-0">
        <div class="card-body">
          <h5 class="mb-3 d-flex align-items-center">
            <i class="bi bi-list-ul me-2"></i> Class and Subject List
          </h5>

          <!-- Class and Subject Accordion -->
          <div class="accordion" id="classAccordion">
            <?php foreach ($classSubjects as $class_id => $class): ?>
              <div class="accordion-item" data-class-id="<?php echo $class_id; ?>">
                <h2 class="accordion-header" id="classHeading<?php echo $class_id; ?>">
                  <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#classCollapse<?php echo $class_id; ?>">
                    <strong><?php echo htmlspecialchars($class['form_name']); ?></strong>
                    <span class="badge bg-info ms-3">Subjects: <?php echo count($class['subjects']); ?></span>
                  </button>
                </h2>
                <div id="classCollapse<?php echo $class_id; ?>" class="accordion-collapse collapse" data-bs-parent="#classAccordion">
                  <div class="accordion-body">
                    <?php if (count($class['subjects']) > 0): ?>
                      <table class="table table-striped table-hover align-middle">
                        <thead class="table-dark">
                          <tr>
                            <th>#</th>
                            <th>Subject Name</th>
                            <th>Type</th>
                            <th>Use Papers</th>
                            <th>Actions</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($class['subjects'] as $index => $subject): ?>
                            <tr data-class-subject-id="<?php echo $subject['class_subject_id']; ?>">
                              <td><?php echo $index + 1; ?></td>
                              <td><?php echo htmlspecialchars($subject['name']); ?></td>
                              <td><?php echo htmlspecialchars(ucfirst($subject['type'])); ?></td>
                              <td><?php echo $subject['use_papers'] ? 'Yes' : 'No'; ?></td>
                              <td>
                                <button class="btn btn-sm btn-danger remove-subject" data-class-id="<?php echo $class_id; ?>" data-subject-id="<?php echo $subject['subject_id']; ?>">
                                  <i class="bi bi-trash"></i> Remove
                                </button>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    <?php else: ?>
                      <p class="text-muted">No subjects assigned to this class.</p>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- Add Subject Modal -->
      <div class="modal fade" id="addSubjectModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title"><i class="bi bi-plus-square me-2"></i> Add New Subject</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <form id="addSubjectForm">
                <div class="mb-3">
                  <label class="form-label">Subject Name</label>
                  <input type="text" class="form-control" name="name" placeholder="e.g. Mathematics" required>
                </div>
                <div class="row g-3">
                  <div class="col-md-4">
                    <label class="form-label">Initial (e.g. MAT)</label>
                    <input type="text" class="form-control" name="subject_initial" maxlength="10" placeholder="MAT">
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Subject Code</label>
                    <input type="number" class="form-control" name="subject_code" placeholder="121">
                  </div>
                  <div class="col-md-4 d-flex align-items-end">
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" name="is_cbc" id="isCbcAdd" value="1">
                      <label class="form-check-label" for="isCbcAdd">
                        CBC Curriculum
                      </label>
                    </div>
                  </div>
                </div>
                <div class="mb-3 mt-3">
                  <label class="form-label">Type</label>
                  <select class="form-select" name="type" required>
                    <option value="">Select Type</option>
                    <option value="compulsory">Compulsory</option>
                    <option value="elective">Elective</option>
                  </select>
                </div>
                <button type="submit" class="btn btn-primary mt-2">Add Subject</button>
              </form>
            </div>
          </div>
        </div>
      </div>


      <!-- Manage Subjects Modal -->
      <div class="modal fade" id="manageSubjectsModal" tabindex="-1" aria-labelledby="manageSubjectsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
          <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-dark text-white">
              <h5 class="modal-title" id="manageSubjectsModalLabel">
                <i class="bi bi-gear me-2"></i> Manage Subjects
              </h5>
              <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
              <?php
              // Fetch all required fields for the table
              $stmt = $conn->prepare("
                    SELECT subject_id, name, type, subject_initial, subject_code, is_cbc 
                    FROM subjects 
                    WHERE school_id = ? 
                    ORDER BY name
                ");
              $stmt->bind_param("i", $_SESSION['school_id']);
              $stmt->execute();
              $manage_subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
              $stmt->close();

              // Total subjects (safe count)
              $totalSubjects = count($manage_subjects);
              ?>

              <?php if (empty($manage_subjects)): ?>
                <div class="alert alert-info text-center">
                  <i class="bi bi-info-circle me-2"></i>
                  No subjects found in your school yet. Add some using the "Add Subject" button.
                </div>
              <?php else: ?>
                <div class="table-responsive">
                  <table class="table table-striped table-hover table-bordered align-middle">
                    <thead class="table-dark">
                      <tr>
                        <th>#</th>
                        <th>Subject Name</th>
                        <th>Initial</th>
                        <th>Code</th>
                        <th>Curriculum</th>
                        <th>Type</th>
                        <th>Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($manage_subjects as $index => $subject):
                        $curriculum = $subject['is_cbc'] ? 'CBC' : '8-4-4';
                        $initial    = !empty($subject['subject_initial']) ? htmlspecialchars($subject['subject_initial']) : '-';
                        $code       = !empty($subject['subject_code']) ? htmlspecialchars($subject['subject_code']) : '-';
                        $type_text  = !empty($subject['type']) ? ucfirst($subject['type']) : '—';
                      ?>
                        <tr data-subject-id="<?php echo $subject['subject_id']; ?>">
                          <td><?php echo $index + 1; ?></td>
                          <td class="fw-bold"><?php echo htmlspecialchars($subject['name']); ?></td>
                          <td><code><?php echo $initial; ?></code></td>
                          <td><?php echo $code; ?></td>
                          <td>
                            <span class="badge <?php echo $subject['is_cbc'] ? 'bg-success' : 'bg-secondary'; ?> px-3 py-2">
                              <?php echo $curriculum; ?>
                            </span>
                          </td>
                          <td><?php echo htmlspecialchars($type_text); ?></td>
                          <td>
                            <div class="btn-group btn-group-sm" role="group">
                              <button class="btn btn-primary edit-subject"
                                data-subject-id="<?php echo $subject['subject_id']; ?>"
                                data-name="<?php echo htmlspecialchars($subject['name']); ?>"
                                data-type="<?php echo htmlspecialchars($subject['type'] ?: ''); ?>"
                                data-initial="<?php echo $initial; ?>"
                                data-code="<?php echo $code; ?>"
                                data-cbc="<?php echo $subject['is_cbc'] ? '1' : '0'; ?>">
                                <i class="bi bi-pencil"></i> Edit
                              </button>
                              <button class="btn btn-info text-white configure-papers"
                                data-subject-id="<?php echo $subject['subject_id']; ?>"
                                data-subject-name="<?php echo htmlspecialchars($subject['name']); ?>">
                                <i class="bi bi-file-earmark-text"></i> Papers
                              </button>
                              <button class="btn btn-danger delete-subject"
                                data-subject-id="<?php echo $subject['subject_id']; ?>">
                                <i class="bi bi-trash"></i> Delete
                              </button>
                            </div>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>
            <div class="modal-footer bg-light">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
              <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSubjectModal" data-bs-dismiss="modal">
                <i class="bi bi-plus-circle me-1"></i> Add New Subject
              </button>
            </div>
          </div>
        </div>
      </div>


      <!-- Edit Subject Modal -->
      <div class="modal fade" id="editSubjectModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title"><i class="bi bi-pencil me-2"></i> Edit Subject</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <form id="editSubjectForm">
                <input type="hidden" name="subject_id" id="editSubjectId">
                <div class="mb-3">
                  <label class="form-label">Subject Name</label>
                  <input type="text" class="form-control" name="name" id="editSubjectName" required>
                </div>
                <div class="row g-3">
                  <div class="col-md-4">
                    <label class="form-label">Initial</label>
                    <input type="text" class="form-control" name="subject_initial" id="editSubjectInitial" maxlength="10">
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Code</label>
                    <input type="number" class="form-control" name="subject_code" id="editSubjectCode">
                  </div>
                  <div class="col-md-4 d-flex align-items-end">
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" name="is_cbc" id="editIsCbc" value="1">
                      <label class="form-check-label" for="editIsCbc">CBC Curriculum</label>
                    </div>
                  </div>
                </div>
                <div class="mb-3 mt-3">
                  <label class="form-label">Type</label>
                  <select class="form-select" name="type" id="editSubjectType" required>
                    <option value="">Select Type</option>
                    <option value="compulsory">Compulsory</option>
                    <option value="elective">Elective</option>
                  </select>
                </div>
                <button type="submit" class="btn btn-primary mt-2">Save Changes</button>
              </form>
            </div>
          </div>
        </div>
      </div>

      <!-- Assign Subjects Modal -->
      <div class="modal fade" id="assignSubjectsModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title"><i class="bi bi-diagram-2 me-2"></i> Assign Subjects to Class</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <form id="selectClassForm">
                <div class="mb-3">
                  <label class="form-label">Class</label>
                  <select class="form-select" id="selectedClassId" required>
                    <option value="">Select Class</option>
                    <?php foreach ($classes as $class): ?>
                      <option value="<?php echo $class['class_id']; ?>">
                        <?php echo htmlspecialchars($class['form_name']); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div id="assignmentButtons" style="display: none;">
                  <button type="button" class="btn btn-primary mb-2 w-100 add-compulsory">Add Compulsory Subjects</button>
                  <button type="button" class="btn btn-secondary w-100 add-elective">Add Elective Subjects</button>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>

      <!-- Bulk Select Subjects Modal -->
      <div class="modal fade" id="bulkSelectModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title"><i class="bi bi-check-square me-2"></i> Select <span id="selectType"></span> Subjects</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <form id="bulkAssignForm">
                <input type="hidden" name="class_id" id="bulkClassId">
                <input type="hidden" name="type" id="bulkType">
                <div class="mb-3">
                  <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="bulkUsePapers" name="use_papers">
                    <label class="form-check-label" for="bulkUsePapers">Use Subject Papers for these assignments?</label>
                  </div>
                </div>
                <div id="subjectsCheckboxList">
                  <!-- Dynamically populated -->
                </div>
                <button type="submit" class="btn btn-primary">Assign Selected Subjects</button>
              </form>
            </div>
          </div>
        </div>
      </div>

      <!-- Manage Assignments Modal -->
      <div class="modal fade" id="manageAssignmentsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title"><i class="bi bi-gear me-2"></i> Manage Subject Assignments</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <table class="table table-striped table-hover">
                <thead class="table-dark">
                  <tr>
                    <th>Class</th>
                    <th>Subject</th>
                    <th>Type</th>
                    <th>Use Papers</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody id="assignmentsTableBody">
                  <?php foreach ($class_subjects as $cs): ?>
                    <tr data-class-subject-id="<?php echo $cs['class_subject_id']; ?>">
                      <td><?php echo htmlspecialchars($cs['form_name']); ?></td>
                      <td><?php echo htmlspecialchars($cs['name']); ?></td>
                      <td><?php echo htmlspecialchars(ucfirst($cs['type'])); ?></td>
                      <td><?php echo $cs['use_papers'] ? 'Yes' : 'No'; ?></td>
                      <td>
                        <button class="btn btn-sm btn-danger remove-subject" data-class-id="<?php echo $cs['class_id']; ?>" data-subject-id="<?php echo $cs['subject_id']; ?>">
                          <i class="bi bi-trash"></i> Remove
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

      <!-- Configure Papers Modal -->
      <div class="modal fade" id="configurePapersModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title"><i class="bi bi-file-earmark-text me-2"></i> Configure Papers for <span id="subjectName"></span></h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <button class="btn btn-primary mb-3 add-paper"><i class="bi bi-plus-circle me-2"></i> Add Paper</button>
              <table class="table table-striped table-hover">
                <thead class="table-dark">
                  <tr>
                    <th>Paper Name</th>
                    <th>Out Of (Max Score)</th>
                    <th>Contribution %</th>
                    <th>Description</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody id="papersTableBody">
                  <!-- Dynamically populated -->
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <!-- Add/Edit Paper Modal -->
      <div class="modal fade" id="editPaperModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title"><i class="bi bi-pencil me-2"></i> <span id="paperAction">Add</span> Paper</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <form id="paperForm">
                <input type="hidden" name="paper_id" id="paperId">
                <input type="hidden" name="subject_id" id="paperSubjectId">
                <div class="mb-3">
                  <label class="form-label">Paper Name</label>
                  <input type="text" class="form-control" name="paper_name" id="paperName" placeholder="e.g., Paper 1" required>
                </div>
                <div class="mb-3">
                  <label class="form-label">Out Of (Max Score)</label>
                  <input type="number" step="0.01" class="form-control" name="max_score" id="maxScore" required>
                </div>
                <div class="mb-3">
                  <label class="form-label">Contribution Percentage</label>
                  <input type="number" step="0.01" class="form-control" name="contribution_percentage" id="contribPercent" required>
                </div>
                <div class="mb-3">
                  <label class="form-label">Description</label>
                  <textarea class="form-control" name="description" id="paperDesc"></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Save Paper</button>
              </form>
            </div>
          </div>
        </div>
      </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
      $(document).ready(function() {
        let currentSubjectId = null;

        // Show buttons after selecting class
        $('#selectedClassId').on('change', function() {
          if ($(this).val()) {
            $('#assignmentButtons').show();
          } else {
            $('#assignmentButtons').hide();
          }
        });

        // Add Compulsory or Elective
        $(document).on('click', '.add-compulsory, .add-elective', function() {
          const classId = $('#selectedClassId').val();
          const type = $(this).hasClass('add-compulsory') ? 'compulsory' : 'elective';
          $('#bulkClassId').val(classId);
          $('#bulkType').val(type);
          $('#selectType').text(type.charAt(0).toUpperCase() + type.slice(1));
          loadAvailableSubjects(classId);
          $('#assignSubjectsModal').modal('hide');
          $('#bulkSelectModal').modal('show');
        });

        // Load available subjects
        function loadAvailableSubjects(classId) {
          ajaxDebug({
            url: 'subjects/functions.php',
            method: 'POST',
            data: {
              action: 'get_available_subjects',
              class_id: classId
            },
            dataType: 'json',
            onSuccess: function(json) {
              if (json.status === 'success') {
                const checkboxes = json.subjects.map(s => `
            <div class="form-check">
              <input type="checkbox" class="form-check-input" name="subject_ids[]" value="${s.subject_id}" id="subj${s.subject_id}">
              <label class="form-check-label" for="subj${s.subject_id}">${s.name} (${s.type})</label>
            </div>
          `).join('');
                $('#subjectsCheckboxList').html(checkboxes || '<p>No available subjects to assign.</p>');
              } else {
                alert(json.message);
              }
            }
          });
        }

        // Bulk Assign
        $('#bulkAssignForm').on('submit', function(e) {
          e.preventDefault();
          ajaxDebug({
            url: 'subjects/functions.php',
            method: 'POST',
            data: $(this).serialize() + '&action=bulk_assign_subjects',
            dataType: 'json',
            onSuccess: function(json) {
              alert(json.message);
              if (json.status === 'success') {
                $('#bulkSelectModal').modal('hide');
                refreshSubjectsAndAssignments();
              }
            }
          });
        });

        // Function to refresh subjects and assignments via AJAX
        function refreshSubjectsAndAssignments() {
          $.ajax({
            url: 'subjects/functions.php',
            method: 'POST',
            data: {
              action: 'get_subjects_and_assignments'
            },
            dataType: 'json',
            success: function(json) {
              console.log('✅ AJAX Response:', json);
              if (json.status === 'success') {
                // Refresh subjects table
                const subjectsRows = json.subjects.map(subject => `
            <tr data-subject-id="${subject.subject_id}">
              <td>${subject.name}</td>
              <td>${subject.type ? subject.type.charAt(0).toUpperCase() + subject.type.slice(1) : 'Not specified'}</td>
              <td>
                <button class="btn btn-sm btn-primary edit-subject" data-subject-id="${subject.subject_id}" data-name="${subject.name}" data-type="${subject.type || ''}">
                  <i class="bi bi-pencil"></i> Edit
                </button>
                <button class="btn btn-sm btn-info text-white configure-papers" data-subject-id="${subject.subject_id}" data-subject-name="${subject.name}">
                  <i class="bi bi-file-earmark-text"></i> Papers
                </button>
                <button class="btn btn-sm btn-danger delete-subject" data-subject-id="${subject.subject_id}">
                  <i class="bi bi-trash"></i> Delete
                </button>
              </td>
            </tr>
          `).join('');
                $('#subjectsTableBody').html(subjectsRows);

                // Refresh assignments table
                const assignmentsRows = json.assignments.map(cs => `
            <tr data-class-subject-id="${cs.class_subject_id}">
              <td>${cs.form_name}</td>
              <td>${cs.name}</td>
              <td>${cs.type.charAt(0).toUpperCase() + cs.type.slice(1)}</td>
              <td>${cs.use_papers ? 'Yes' : 'No'}</td>
              <td>
                <button class="btn btn-sm btn-danger remove-subject" data-class-id="${cs.class_id}" data-subject-id="${cs.subject_id}">
                  <i class="bi bi-trash"></i> Remove
                </button>
              </td>
            </tr>
          `).join('');
                $('#assignmentsTableBody').html(assignmentsRows);

                // Refresh class dropdowns
                const classOptions = json.classes.map(cls =>
                  `<option value="${cls.class_id}">${cls.form_name}</option>`
                ).join('');
                $('#selectedClassId').html(`<option value="">Select Class</option>${classOptions}`);

                // Refresh subject dropdowns if any

                // Refresh accordion
                const accordionHtml = json.classes.map(cls => {
                  const subjects = json.assignments.filter(cs => cs.class_id == cls.class_id);
                  const subjectsHtml = subjects.length > 0 ? `
              <table class="table table-striped table-hover align-middle">
                <thead class="table-dark">
                  <tr>
                    <th>#</th>
                    <th>Subject Name</th>
                    <th>Type</th>
                    <th>Use Papers</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  ${subjects.map((s, index) => `
                    <tr data-class-subject-id="${s.class_subject_id}">
                      <td>${index + 1}</td>
                      <td>${s.name}</td>
                      <td>${s.type.charAt(0).toUpperCase() + s.type.slice(1)}</td>
                      <td>${s.use_papers ? 'Yes' : 'No'}</td>
                      <td>
                        <button class="btn btn-sm btn-danger remove-subject" data-class-id="${cls.class_id}" data-subject-id="${s.subject_id}">
                          <i class="bi bi-trash"></i> Remove
                        </button>
                      </td>
                    </tr>
                  `).join('')}
                </tbody>
              </table>
            ` : `<p class="text-muted">No subjects assigned to this class.</p>`;
                  return `
              <div class="accordion-item" data-class-id="${cls.class_id}">
                <h2 class="accordion-header" id="classHeading${cls.class_id}">
                  <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#classCollapse${cls.class_id}">
                    <strong>${cls.form_name}</strong>
                    <span class="badge bg-info ms-3">Subjects: ${subjects.length}</span>
                  </button>
                </h2>
                <div id="classCollapse${cls.class_id}" class="accordion-collapse collapse" data-bs-parent="#classAccordion">
                  <div class="accordion-body">${subjectsHtml}</div>
                </div>
              </div>
            `;
                }).join('');
                $('#classAccordion').html(accordionHtml);
              } else {
                console.error('❌ Failed to refresh subjects and assignments:', json.message);
                alert(json.message);
              }
            },
            error: function(xhr, status, error) {
              console.error('❌ AJAX Error for get_subjects_and_assignments:', status, error, xhr.responseText);
              alert('An error occurred: ' + (xhr.responseText || 'Unknown error. Check console.'));
            }
          });
        }

        // Function to refresh papers for a subject
        function refreshPapers(subjectId) {
          ajaxDebug({
            url: 'subjects/functions.php',
            method: 'POST',
            data: {
              action: 'get_subject_papers',
              subject_id: subjectId
            },
            dataType: 'json',
            onSuccess: function(json) {
              if (json.status === 'success') {
                const rows = json.papers.map(p => `
            <tr data-paper-id="${p.paper_id}">
              <td>${p.paper_name}</td>
              <td>${p.max_score}</td>
              <td>${p.contribution_percentage}</td>
              <td>${p.description || ''}</td>
              <td>
                <button class="btn btn-sm btn-primary edit-paper" data-paper-id="${p.paper_id}" data-paper_name="${p.paper_name}" data-max_score="${p.max_score}" data-contribution_percentage="${p.contribution_percentage}" data-description="${p.description || ''}">
                  <i class="bi bi-pencil"></i> Edit
                </button>
                <button class="btn btn-sm btn-danger delete-paper" data-paper-id="${p.paper_id}">
                  <i class="bi bi-trash"></i> Delete
                </button>
              </td>
            </tr>
          `).join('');
                $('#papersTableBody').html(rows);
              } else {
                alert(json.message);
              }
            }
          });
        }

        // Configure Papers
        $(document).on('click', '.configure-papers', function() {
          currentSubjectId = $(this).data('subject-id');
          const subjectName = $(this).data('subject-name');
          $('#subjectName').text(subjectName);
          refreshPapers(currentSubjectId);
          $('#configurePapersModal').modal('show');
        });

        // Add Paper
        $(document).on('click', '.add-paper', function() {
          $('#paperAction').text('Add');
          $('#paperId').val('');
          $('#paperSubjectId').val(currentSubjectId);
          $('#paperName').val('');
          $('#maxScore').val('');
          $('#contribPercent').val('');
          $('#paperDesc').val('');
          $('#editPaperModal').modal('show');
        });

        // Edit Paper
        $(document).on('click', '.edit-paper', function() {
          $('#paperAction').text('Edit');
          $('#paperId').val($(this).data('paper-id'));
          $('#paperSubjectId').val(currentSubjectId);
          $('#paperName').val($(this).data('paper_name'));
          $('#maxScore').val($(this).data('max_score'));
          $('#contribPercent').val($(this).data('contribution_percentage'));
          $('#paperDesc').val($(this).data('description'));
          $('#editPaperModal').modal('show');
        });

        // Delete Paper
        $(document).on('click', '.delete-paper', function() {
          const paperId = $(this).data('paper-id');
          if (confirm('Are you sure you want to delete this paper?')) {
            ajaxDebug({
              url: 'subjects/functions.php',
              method: 'POST',
              data: {
                action: 'delete_paper',
                paper_id: paperId
              },
              dataType: 'json',
              onSuccess: function(json) {
                alert(json.message);
                if (json.status === 'success') {
                  refreshPapers(currentSubjectId);
                }
              }
            });
          }
        });

        // Submit Paper Form
        $('#paperForm').on('submit', function(e) {
          e.preventDefault();
          const action = $('#paperId').val() ? 'edit_paper' : 'add_paper';
          ajaxDebug({
            url: 'subjects/functions.php',
            method: 'POST',
            data: $(this).serialize() + '&action=' + action,
            dataType: 'json',
            onSuccess: function(json) {
              alert(json.message);
              if (json.status === 'success') {
                $('#editPaperModal').modal('hide');
                refreshPapers(currentSubjectId);
              }
            }
          });
        });

        // Edit Subject
        $(document).on('click', '.edit-subject', function() {
          const subjectId = $(this).data('subject-id');
          const name = $(this).data('name');
          const type = $(this).data('type');
          const initial = $(this).data('initial') || '';
          const code = $(this).data('code') || '';
          const isCbc = $(this).data('cbc') == 1;

          $('#editSubjectId').val(subjectId);
          $('#editSubjectName').val(name);
          $('#editSubjectType').val(type);
          $('#editSubjectInitial').val(initial);
          $('#editSubjectCode').val(code);
          $('#editIsCbc').prop('checked', isCbc);

          $('#editSubjectModal').modal('show');
        });


        // Delete Subject
        $(document).on('click', '.delete-subject', function() {
          const subjectId = $(this).data('subject-id');
          console.log('🗑️ Deleting subject:', subjectId);
          if (confirm('Are you sure you want to delete this subject? This will remove it from all classes and related records.')) {
            ajaxDebug({
              url: 'subjects/functions.php',
              method: 'POST',
              data: {
                action: 'delete_subject',
                subject_id: subjectId
              },
              dataType: 'json',
              onSuccess: function(json) {
                alert(json.message);
                if (json.status === 'success') {
                  $('#manageSubjectsModal').modal('hide');
                  refreshSubjectsAndAssignments();
                }
              }
            });
          }
        });

        // Remove Subject Assignment
        $(document).on('click', '.remove-subject', function() {
          const classId = $(this).data('class-id');
          const subjectId = $(this).data('subject-id');
          console.log('🗑️ Removing subject assignment:', {
            classId,
            subjectId
          });
          if (confirm('Are you sure you want to remove this subject from the class?')) {
            ajaxDebug({
              url: 'subjects/functions.php',
              method: 'POST',
              data: {
                action: 'remove_subject_assignment',
                class_id: classId,
                subject_id: subjectId
              },
              dataType: 'json',
              onSuccess: function(json) {
                alert(json.message);
                if (json.status === 'success') {
                  $('#manageAssignmentsModal').modal('hide');
                  refreshSubjectsAndAssignments();
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

        // Add Subject
        $('#addSubjectForm').on('submit', function(e) {
          e.preventDefault();
          ajaxDebug({
            url: 'subjects/functions.php',
            method: 'POST',
            data: $(this).serialize() + '&action=add_subject',
            dataType: 'json',
            onSuccess: function(json) {
              alert(json.message);
              if (json.status === 'success') {
                $('#addSubjectModal').modal('hide');
                refreshSubjectsAndAssignments();
              }
            }
          });
        });

        // Edit Subject
        $('#editSubjectForm').on('submit', function(e) {
          e.preventDefault();
          ajaxDebug({
            url: 'subjects/functions.php',
            method: 'POST',
            data: $(this).serialize() + '&action=edit_subject',
            dataType: 'json',
            onSuccess: function(json) {
              alert(json.message);
              if (json.status === 'success') {
                $('#editSubjectModal').modal('hide');
                $('#manageSubjectsModal').modal('show');
                refreshSubjectsAndAssignments();
              }
            }
          });
        });

        // Initial refresh
        refreshSubjectsAndAssignments();
      });
    </script>

    <?php include __DIR__ . '/../footer.php'; ?>