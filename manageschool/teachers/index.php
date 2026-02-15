<?php
// teachers/index.php
include '../header.php';
include '../sidebar.php';
require __DIR__ . '/../../connection/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['school_id']) || !isset($_SESSION['role_id'])) {
  header("Location: ../index.php");
  exit;
}

// Permission check function
function hasPermission($conn, $role_id, $permission_name, $school_id)
{
  $stmt = $conn->prepare("
        SELECT 1
        FROM role_permissions rp
        JOIN permissions p ON rp.permission_id = p.permission_id
        WHERE rp.role_id = ? AND p.name = ? AND (rp.school_id = ? OR p.is_global = TRUE)
    ");
  $stmt->bind_param("isi", $role_id, $permission_name, $school_id);
  $stmt->execute();
  $result = $stmt->get_result();
  $has_permission = $result->num_rows > 0;
  $stmt->close();
  return $has_permission;
}

$school_id = $_SESSION['school_id'];
$role_id   = $_SESSION['role_id'];

// Fetch teachers (users with role 'Teacher')
$teachers = [];
if (hasPermission($conn, $role_id, 'view_teachers', $school_id)) {
  $stmt = $conn->prepare("
        SELECT u.user_id, u.first_name, u.other_names, u.username, u.email, u.personal_email, 
               u.phone_number, u.gender, u.tsc_number, u.employee_number, u.status
        FROM users u
        JOIN roles r ON u.role_id = r.role_id
        WHERE u.school_id = ? AND r.role_name = 'Teacher' AND u.deleted_at IS NULL
        ORDER BY u.first_name, u.other_names
    ");
  $stmt->bind_param("i", $school_id);
  $stmt->execute();
  $teachers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
}

// Fetch teacher assignments (for initial display)
$teacher_assignments = [];
if (hasPermission($conn, $role_id, 'view_teachers', $school_id)) {
  $stmt = $conn->prepare("
        SELECT ts.teacher_subject_id, ts.user_id, s.name AS subject_name, c.form_name, 
               st.stream_name, ts.academic_year, ts.subject_id, ts.class_id, ts.stream_id
        FROM teacher_subjects ts
        LEFT JOIN subjects s ON ts.subject_id = s.subject_id
        LEFT JOIN classes c ON ts.class_id = c.class_id
        LEFT JOIN streams st ON ts.stream_id = st.stream_id
        WHERE ts.school_id = ? AND s.deleted_at IS NULL
        ORDER BY ts.user_id, s.name
    ");
  $stmt->bind_param("i", $school_id);
  $stmt->execute();
  $result = $stmt->get_result();
  while ($row = $result->fetch_assoc()) {
    $teacher_assignments[$row['user_id']][] = $row;
  }
  $stmt->close();
}

$total_teachers = count($teachers);
error_log("Teachers fetched: $total_teachers for school_id $school_id");
?>

<style>
  .badge.bg-success {
    font-size: 0.75em;
    vertical-align: middle;
  }

  .badge.bg-secondary {
    font-size: 0.75em;
    vertical-align: middle;
  }

  .badge.cbc {
    background-color: #198754 !important;
    color: white;
  }

  .badge {
    background-color: #6c757d !important;
    color: white;
  }

  .assignment-list li {
    margin-bottom: 4px;
  }
</style>

<div class="content">
  <div class="container-fluid py-4">
    <h3 class="mb-4"><i class="bi bi-person-badge me-2"></i> Teacher Management</h3>

    <!-- Action Cards -->
    <div class="row g-4 mb-4">
      <?php if (hasPermission($conn, $role_id, 'manage_teachers', $school_id)): ?>
        <div class="col-md-4 col-6">
          <div class="card shadow-sm border-0 h-100 text-center">
            <div class="card-body d-flex flex-column justify-content-center">
              <i class="bi bi-person-plus display-5 text-primary"></i>
              <h5 class="mt-3">Add Teacher</h5>
              <button class="btn btn-primary mt-auto" data-bs-toggle="modal" data-bs-target="#addTeacherModal">
                <i class="bi bi-plus-circle me-2"></i> Add Teacher
              </button>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <?php if (hasPermission($conn, $role_id, 'assign_subjects', $school_id)): ?>
        <div class="col-md-4 col-6">
          <div class="card shadow-sm border-0 h-100 text-center">
            <div class="card-body d-flex flex-column justify-content-center">
              <i class="bi bi-book display-5 text-success"></i>
              <h5 class="mt-3">Assign Subjects</h5>
              <button class="btn btn-success mt-auto" data-bs-toggle="modal" data-bs-target="#assignSubjectsModal">
                <i class="bi bi-bookmark-plus me-2"></i> Assign Subjects
              </button>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <div class="col-md-4 col-6">
        <div class="card shadow-sm border-0 h-100 text-center">
          <div class="card-body d-flex flex-column justify-content-center">
            <i class="bi bi-list-ul display-5 text-info"></i>
            <h5 class="mt-3">Total Teachers</h5>
            <p class="fs-4 fw-bold mb-0"><?php echo number_format($total_teachers); ?></p>
          </div>
        </div>
      </div>
    </div>

    <!-- Teacher List -->
    <div class="card shadow-sm border-0">
      <div class="card-body">
        <h5 class="mb-3"><i class="bi bi-list-ul me-2"></i> Teacher List</h5>

        <div class="row mb-3">
          <div class="col-md-4">
            <input type="text" id="searchInput" class="form-control" placeholder="Search by name or TSC number">
          </div>
          <div class="col-md-4">
            <select id="statusFilter" class="form-select">
              <option value="">All Statuses</option>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
        </div>

        <div class="table-responsive">
          <table class="table table-striped table-hover align-middle">
            <thead class="table-dark">
              <tr>
                <th>#</th>
                <th>Name</th>
                <th>Username</th>
                <th>TSC Number</th>
                <th>Status</th>
                <th>Assignments</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($teachers as $index => $teacher): ?>
                <tr data-teacher-id="<?= $teacher['user_id'] ?>">
                  <td><?= $index + 1 ?></td>
                  <td><?= htmlspecialchars($teacher['first_name'] . ' ' . ($teacher['other_names'] ?? '')) ?></td>
                  <td><?= htmlspecialchars($teacher['username']) ?></td>
                  <td><?= htmlspecialchars($teacher['tsc_number'] ?? 'N/A') ?></td>
                  <td>
                    <span class="badge <?= $teacher['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?>">
                      <?= htmlspecialchars($teacher['status']) ?>
                    </span>
                  </td>
                  <td>
                    <?php
                    $assignments = $teacher_assignments[$teacher['user_id']] ?? [];
                    if ($assignments):
                      echo '<ul class="list-unstyled assignment-list mb-0">';
                      foreach ($assignments as $ass):
                        $text = htmlspecialchars($ass['subject_name']);
                        if ($ass['form_name']) {
                          $text .= ' <small>(' . htmlspecialchars($ass['form_name']);
                          if ($ass['stream_name']) $text .= ' - ' . htmlspecialchars($ass['stream_name']);
                          $text .= ')</small>';
                        }
                        $text .= ' <small class="text-muted">[' . $ass['academic_year'] . ']</small>';
                        echo "<li>$text</li>";
                      endforeach;
                      echo '</ul>';
                    else:
                      echo '<small class="text-muted">No subjects assigned</small>';
                    endif;
                    ?>
                  </td>
                  <td>
                    <?php if (hasPermission($conn, $role_id, 'assign_subjects', $school_id)): ?>
                      <button class="btn btn-sm btn-outline-primary manage-subjects me-1"
                        data-teacher-id="<?= $teacher['user_id'] ?>"
                        data-teacher-name="<?= htmlspecialchars($teacher['first_name'] . ' ' . ($teacher['other_names'] ?? '')) ?>">
                        <i class="bi bi-journal-bookmark"></i> Manage
                      </button>
                    <?php endif; ?>

                    <?php if (hasPermission($conn, $role_id, 'manage_teachers', $school_id)): ?>
                      <button class="btn btn-sm btn-primary edit-teacher me-1"
                        data-teacher-id="<?= $teacher['user_id'] ?>"
                        data-first-name="<?= htmlspecialchars($teacher['first_name']) ?>"
                        data-other-names="<?= htmlspecialchars($teacher['other_names'] ?? '') ?>"
                        data-username="<?= htmlspecialchars($teacher['username']) ?>"
                        data-email="<?= htmlspecialchars($teacher['email'] ?? '') ?>"
                        data-personal-email="<?= htmlspecialchars($teacher['personal_email'] ?? '') ?>"
                        data-phone-number="<?= htmlspecialchars($teacher['phone_number'] ?? '') ?>"
                        data-gender="<?= htmlspecialchars($teacher['gender'] ?? '') ?>"
                        data-tsc-number="<?= htmlspecialchars($teacher['tsc_number'] ?? '') ?>"
                        data-employee-number="<?= htmlspecialchars($teacher['employee_number'] ?? '') ?>"
                        data-status="<?= htmlspecialchars($teacher['status']) ?>">
                        <i class="bi bi-pencil"></i> Edit
                      </button>

                      <button class="btn btn-sm btn-danger delete-teacher"
                        data-teacher-id="<?= $teacher['user_id'] ?>">
                        <i class="bi bi-trash"></i> Delete
                      </button>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Add Teacher Modal -->
    <?php if (hasPermission($conn, $role_id, 'manage_teachers', $school_id)): ?>
      <div class="modal fade" id="addTeacherModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i> Add Teacher</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <form id="addTeacherForm">
                <div class="row">
                  <div class="col-md-6 mb-3">
                    <label class="form-label">First Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="first_name" required>
                  </div>
                  <div class="col-md-6 mb-3">
                    <label class="form-label">Other Names</label>
                    <input type="text" class="form-control" name="other_names">
                  </div>
                  <div class="col-md-6 mb-3">
                    <label class="form-label">Username <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="username" required>
                  </div>
                  <div class="col-md-6 mb-3">
                    <label class="form-label">Password <span class="text-danger">*</span></label>
                    <input type="password" class="form-control" name="password" required>
                  </div>
                  <div class="col-md-6 mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" class="form-control" name="email">
                  </div>
                  <div class="col-md-6 mb-3">
                    <label class="form-label">Personal Email</label>
                    <input type="email" class="form-control" name="personal_email">
                  </div>
                  <div class="col-md-6 mb-3">
                    <label class="form-label">Phone Number</label>
                    <input type="text" class="form-control" name="phone_number">
                  </div>
                  <div class="col-md-6 mb-3">
                    <label class="form-label">Gender</label>
                    <select class="form-select" name="gender">
                      <option value="">Select Gender</option>
                      <option value="Male">Male</option>
                      <option value="Female">Female</option>
                    </select>
                  </div>
                  <div class="col-md-6 mb-3">
                    <label class="form-label">TSC Number</label>
                    <input type="text" class="form-control" name="tsc_number">
                  </div>
                  <div class="col-md-6 mb-3">
                    <label class="form-label">Employee Number</label>
                    <input type="text" class="form-control" name="employee_number">
                  </div>
                </div>
                <button type="submit" class="btn btn-primary">Add Teacher</button>
              </form>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <!-- Edit Teacher Modal -->
    <?php if (hasPermission($conn, $role_id, 'manage_teachers', $school_id)): ?>
      <div class="modal fade" id="editTeacherModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title"><i class="bi bi-pencil me-2"></i> Edit Teacher</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <form id="editTeacherForm">
                <input type="hidden" name="user_id" id="editTeacherId">
                <div class="row">
                  <div class="col-md-6 mb-3">
                    <label class="form-label">First Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="first_name" id="editFirstName" required>
                  </div>
                  <div class="col-md-6 mb-3">
                    <label class="form-label">Other Names</label>
                    <input type="text" class="form-control" name="other_names" id="editOtherNames">
                  </div>
                  <div class="col-md-6 mb-3">
                    <label class="form-label">Username <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="username" id="editUsername" required>
                  </div>
                  <div class="col-md-6 mb-3">
                    <label class="form-label">Password (leave blank to keep unchanged)</label>
                    <input type="password" class="form-control" name="password" id="editPassword">
                  </div>
                  <div class="col-md-6 mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" class="form-control" name="email" id="editEmail">
                  </div>
                  <div class="col-md-6 mb-3">
                    <label class="form-label">Personal Email</label>
                    <input type="email" class="form-control" name="personal_email" id="editPersonalEmail">
                  </div>
                  <div class="col-md-6 mb-3">
                    <label class="form-label">Phone Number</label>
                    <input type="text" class="form-control" name="phone_number" id="editPhoneNumber">
                  </div>
                  <div class="col-md-6 mb-3">
                    <label class="form-label">Gender</label>
                    <select class="form-select" name="gender" id="editGender">
                      <option value="">Select Gender</option>
                      <option value="Male">Male</option>
                      <option value="Female">Female</option>
                    </select>
                  </div>
                  <div class="col-md-6 mb-3">
                    <label class="form-label">TSC Number</label>
                    <input type="text" class="form-control" name="tsc_number" id="editTscNumber">
                  </div>
                  <div class="col-md-6 mb-3">
                    <label class="form-label">Employee Number</label>
                    <input type="text" class="form-control" name="employee_number" id="editEmployeeNumber">
                  </div>
                  <div class="col-md-6 mb-3">
                    <label class="form-label">Status <span class="text-danger">*</span></label>
                    <select class="form-select" name="status" id="editStatus" required>
                      <option value="active">Active</option>
                      <option value="inactive">Inactive</option>
                    </select>
                  </div>
                </div>
                <button type="submit" class="btn btn-primary">Save Changes</button>
              </form>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <!-- Assign Subjects Modal (global) -->
    <?php if (hasPermission($conn, $role_id, 'assign_subjects', $school_id)): ?>
      <div class="modal fade" id="assignSubjectsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title"><i class="bi bi-bookmark-plus me-2"></i> Assign Subject</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <form id="assignSubjectsForm">
                <div class="mb-3">
                  <label class="form-label">Select Teacher <span class="text-danger">*</span></label>
                  <select class="form-select" name="user_id" id="teacherSelect" required>
                    <option value="">Select Teacher</option>
                    <?php foreach ($teachers as $teacher): ?>
                      <option value="<?= $teacher['user_id'] ?>">
                        <?= htmlspecialchars($teacher['first_name'] . ' ' . ($teacher['other_names'] ?? '')) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="mb-3">
                  <label class="form-label">Select Subject <span class="text-danger">*</span></label>
                  <select class="form-select" name="subject_id" id="subjectSelect" required>
                    <option value="">Select Subject</option>
                  </select>
                </div>
                <div class="mb-3">
                  <label class="form-label">Class (optional)</label>
                  <select class="form-select" name="class_id" id="classSelect">
                    <option value="">— Any / None —</option>
                  </select>
                </div>
                <div class="mb-3">
                  <label class="form-label">Stream (optional)</label>
                  <select class="form-select" name="stream_id" id="streamSelect">
                    <option value="">— Select class first —</option>
                  </select>
                </div>
                <div class="mb-3">
                  <label class="form-label">Academic Year <span class="text-danger">*</span></label>
                  <input type="number" class="form-control" name="academic_year" value="<?= date('Y') ?>" required>
                </div>
                <button type="submit" class="btn btn-success">Assign Subject</button>
              </form>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <!-- Manage Teacher Subjects Modal (NEW - main feature) -->
    <?php if (hasPermission($conn, $role_id, 'assign_subjects', $school_id)): ?>
      <div class="modal fade" id="manageSubjectsModal" tabindex="-1" aria-labelledby="manageSubjectsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
          <div class="modal-content">
            <div class="modal-header bg-light">
              <h5 class="modal-title" id="manageSubjectsModalLabel">
                <i class="bi bi-journal-bookmark me-2"></i> Manage Subjects
              </h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <!-- Current Assignments -->
              <h6 class="mb-3"><i class="bi bi-list-ul me-2"></i> Current Assignments</h6>
              <div class="table-responsive mb-4">
                <table class="table table-sm table-bordered table-hover" id="currentAssignmentsTable">
                  <thead class="table-light">
                    <tr>
                      <th>Subject</th>
                      <th>Class</th>
                      <th>Stream</th>
                      <th>Year</th>
                      <th width="100">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr>
                      <td colspan="5" class="text-center py-4">Loading assignments...</td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <!-- Add New Assignment -->
              <h6 class="mb-3"><i class="bi bi-plus-circle me-2"></i> Add New Assignment</h6>
              <form id="addNewAssignmentForm" class="row g-3">
                <input type="hidden" name="user_id" id="manageTeacherId">

                <div class="col-md-4">
                  <label class="form-label">Subject <span class="text-danger">*</span></label>
                  <select class="form-select" name="subject_id" id="newSubjectSelect" required>
                    <option value="">Select subject...</option>
                  </select>
                </div>

                <div class="col-md-3">
                  <label class="form-label">Class (optional)</label>
                  <select class="form-select" name="class_id" id="newClassSelect">
                    <option value="">— Any / None —</option>
                  </select>
                </div>

                <div class="col-md-3">
                  <label class="form-label">Stream (optional)</label>
                  <select class="form-select" name="stream_id" id="newStreamSelect">
                    <option value="">— Select class first —</option>
                  </select>
                </div>

                <div class="col-md-2">
                  <label class="form-label">Year <span class="text-danger">*</span></label>
                  <input type="number" class="form-control" name="academic_year" value="<?= date('Y') ?>" min="2000" required>
                </div>

                <div class="col-12 mt-2">
                  <button type="submit" class="btn btn-success">
                    <i class="bi bi-plus-lg"></i> Add Assignment
                  </button>
                </div>
              </form>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>

  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
  // ────────────────────────────────────────────────
  // Document Ready
  // ────────────────────────────────────────────────
  $(document).ready(function() {

    // AJAX Helper
    function ajaxRequest(options) {
      $.ajax({
        url: 'teachers/functions.php',
        method: 'POST',
        data: options.data,
        dataType: 'json',
        success: function(res) {
          if (res.status === 'success') {
            if (options.success) options.success(res);
          } else {
            alert('Error: ' + (res.message || 'Unknown error'));
          }
        },
        error: function(xhr, status, err) {
          console.error('AJAX error:', status, err, xhr.responseText);
          alert('Request failed. Check console.');
        }
      });
    }

    // Load dropdown options (subjects/classes/streams)
    function refreshAssignOptions(prefix = '') {
      ajaxRequest({
        data: {
          action: 'get_assign_options'
        },
        success: function(json) {
          // Subjects with CBC/8-4-4 badge
          const subOpts = json.subjects.map(s => {
            const badge = s.is_cbc == 1 ?
              '<span class="badge bg-success ms-2">CBC</span>' :
              '<span class="badge bg-secondary ms-2">8-4-4</span>';
            return `<option value="${s.subject_id}">${s.name} ${badge}</option>`;
          }).join('');
          $(`#${prefix}SubjectSelect, #newSubjectSelect`).html(
            `<option value="">Select Subject</option>${subOpts}`
          );

          // Classes
          const clsOpts = json.classes.map(c =>
            `<option value="${c.class_id}">${c.form_name}</option>`
          ).join('');
          $(`#${prefix}ClassSelect, #newClassSelect`).html(
            `<option value="">— Any / None —</option>${clsOpts}`
          );

          // Streams
          const strOpts = json.streams.map(s =>
            `<option value="${s.stream_id}" data-class-id="${s.class_id}">${s.stream_name}</option>`
          ).join('');
          $(`#${prefix}StreamSelect, #newStreamSelect`).html(
            `<option value="">— Select class first —</option>${strOpts}`
          );
        }
      });
    }

    // Update streams when class changes
    $(document).on('change', '#newClassSelect', function() {
      const classId = $(this).val();
      const $stream = $('#newStreamSelect');
      if (!classId) {
        $stream.html('<option value="">— Select class first —</option>');
        return;
      }
      ajaxRequest({
        data: {
          action: 'get_streams_by_class',
          class_id: classId
        },
        success: function(json) {
          const opts = json.streams.map(s =>
            `<option value="${s.stream_id}">${s.stream_name}</option>`
          ).join('');
          $stream.html(`<option value="">— Optional —</option>${opts}`);
        }
      });
    });

    // ────────────────────────────────────────────────
    // Manage Subjects Modal (main feature)
    // ────────────────────────────────────────────────
    $(document).on('click', '.manage-subjects', function(e) {
      e.preventDefault();
      const teacherId = $(this).data('teacher-id');
      const teacherName = $(this).data('teacher-name');

      $('#manageTeacherId').val(teacherId);
      $('#manageSubjectsModalLabel').text(`Manage Subjects – ${teacherName}`);

      refreshAssignOptions();
      loadTeacherAssignments(teacherId);

      $('#manageSubjectsModal').modal('show');
    });

    function loadTeacherAssignments(teacherId) {
      ajaxRequest({
        data: {
          action: 'get_teacher_assignments',
          teacher_id: teacherId
        },
        success: function(res) {
          const $tbody = $('#currentAssignmentsTable tbody').empty();

          if (res.assignments && res.assignments.length > 0) {
            res.assignments.forEach(a => {
              const badge = a.is_cbc ?
                '<span class="badge bg-success ms-1">CBC</span>' :
                '<span class="badge bg-secondary ms-1">8-4-4</span>';

              $tbody.append(`
                            <tr>
                                <td>${a.subject_name} ${badge}</td>
                                <td>${a.form_name || '—'}</td>
                                <td>${a.stream_name || '—'}</td>
                                <td>${a.academic_year}</td>
                                <td>
                                    <button class="btn btn-sm btn-danger delete-assignment-btn"
                                            data-id="${a.teacher_subject_id}">
                                        <i class="bi bi-trash"></i> Remove
                                    </button>
                                </td>
                            </tr>
                        `);
            });
          } else {
            $tbody.html('<tr><td colspan="5" class="text-center py-4">No subjects assigned yet.</td></tr>');
          }
        }
      });
    }

    // Delete assignment from modal
    $(document).on('click', '.delete-assignment-btn', function() {
      const id = $(this).data('id');
      if (!confirm('Remove this assignment?')) return;

      ajaxRequest({
        data: {
          action: 'delete_assignment',
          teacher_subject_id: id
        },
        success: function(res) {
          if (res.status === 'success') {
            loadTeacherAssignments($('#manageTeacherId').val());
            alert('Assignment removed.');
          }
        }
      });
    });

    // Add new assignment
    $('#addNewAssignmentForm').on('submit', function(e) {
      e.preventDefault();
      const teacherId = $('#manageTeacherId').val();

      ajaxRequest({
        data: $(this).serialize() + '&action=assign_subject',
        success: function(res) {
          if (res.status === 'success') {
            alert(res.message || 'Assignment added successfully');
            $('#addNewAssignmentForm')[0].reset();
            loadTeacherAssignments(teacherId);
          }
        }
      });
    });

    // ────────────────────────────────────────────────
    // Legacy / other functionality (add/edit/delete teacher, global assign, search)
    // ────────────────────────────────────────────────

    // Add Teacher
    $('#addTeacherForm').on('submit', function(e) {
      e.preventDefault();
      ajaxRequest({
        data: $(this).serialize() + '&action=add_teacher',
        success: function(json) {
          alert(json.message);
          $('#addTeacherModal').modal('hide');
          location.reload();
        }
      });
    });

    // Edit Teacher
    $('.edit-teacher').on('click', function() {
      const data = $(this).data();
      $('#editTeacherId').val(data.teacherId);
      $('#editFirstName').val(data.firstName);
      $('#editOtherNames').val(data.otherNames);
      $('#editUsername').val(data.username);
      $('#editEmail').val(data.email);
      $('#editPersonalEmail').val(data.personalEmail);
      $('#editPhoneNumber').val(data.phoneNumber);
      $('#editGender').val(data.gender);
      $('#editTscNumber').val(data.tscNumber);
      $('#editEmployeeNumber').val(data.employeeNumber);
      $('#editStatus').val(data.status);
      $('#editTeacherModal').modal('show');
    });

    $('#editTeacherForm').on('submit', function(e) {
      e.preventDefault();
      ajaxRequest({
        data: $(this).serialize() + '&action=edit_teacher',
        success: function(json) {
          alert(json.message);
          $('#editTeacherModal').modal('hide');
          location.reload();
        }
      });
    });

    // Delete Teacher
    $('.delete-teacher').on('click', function() {
      if (!confirm('Delete this teacher? This action cannot be undone.')) return;
      ajaxRequest({
        data: {
          action: 'delete_teacher',
          user_id: $(this).data('teacher-id')
        },
        success: function(json) {
          alert(json.message);
          location.reload();
        }
      });
    });

    // Global Assign Subjects (single assignment)
    $('#assignSubjectsForm').on('submit', function(e) {
      e.preventDefault();
      ajaxRequest({
        data: $(this).serialize() + '&action=assign_subject',
        success: function(json) {
          alert(json.message);
          $('#assignSubjectsModal').modal('hide');
          $('#assignSubjectsForm')[0].reset();
          location.reload();
        }
      });
    });

    // Search & Filter
    $('#searchInput, #statusFilter').on('input change', function() {
      const text = $('#searchInput').val().toLowerCase().trim();
      const status = $('#statusFilter').val();

      $('tbody tr').each(function() {
        const $row = $(this);
        const name = $row.find('td:eq(1)').text().toLowerCase();
        const tsc = $row.find('td:eq(3)').text().toLowerCase();
        const stat = $row.find('td:eq(4) .badge').text().toLowerCase();

        const matchNameTsc = !text || name.includes(text) || tsc.includes(text);
        const matchStatus = !status || stat === status;

        $row.toggle(matchNameTsc && matchStatus);
      });
    });

    // Initialize dropdowns
    refreshAssignOptions();
  });
</script>

<?php include '../footer.php'; ?>