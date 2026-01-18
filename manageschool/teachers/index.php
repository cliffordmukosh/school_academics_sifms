<?php
// teachers/index.php
// session_start();
include '../header.php';
include '../sidebar.php';
require __DIR__ . '/../../connection/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['school_id']) || !isset($_SESSION['role_id'])) {
    header("Location: ../index.php");
    exit;
}

// Permission check function
function hasPermission($conn, $role_id, $permission_name, $school_id) {
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
$role_id = $_SESSION['role_id'];

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

// Fetch teacher assignments
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
            <h5 class="mt-3">View Teachers</h5>
            <p class="fs-4 fw-bold"><?php echo number_format($total_teachers); ?></p>
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
                <tr data-teacher-id="<?php echo $teacher['user_id']; ?>">
                  <td><?php echo $index + 1; ?></td>
                  <td><?php echo htmlspecialchars($teacher['first_name'] . ' ' . ($teacher['other_names'] ?? '')); ?></td>
                  <td><?php echo htmlspecialchars($teacher['username']); ?></td>
                  <td><?php echo htmlspecialchars($teacher['tsc_number'] ?? 'N/A'); ?></td>
                  <td>
                    <span class="badge <?php echo $teacher['status'] === 'active' ? 'bg-success' : 'bg-secondary'; ?>">
                      <?php echo htmlspecialchars($teacher['status']); ?>
                    </span>
                  </td>
                  <td>
                    <?php
                    $assignments = $teacher_assignments[$teacher['user_id']] ?? [];
                    if ($assignments):
                      echo '<ul class="list-unstyled mb-0">';
                      foreach ($assignments as $assignment):
                        $assignment_text = htmlspecialchars($assignment['subject_name']) . 
                          ($assignment['form_name'] ? ' (' . htmlspecialchars($assignment['form_name']) : '') .
                          ($assignment['stream_name'] ? ' - ' . htmlspecialchars($assignment['stream_name']) : '') .
                          ($assignment['form_name'] ? ')' : '') . 
                          ' [' . $assignment['academic_year'] . ']';
                        echo "<li>$assignment_text";
                        if (hasPermission($conn, $role_id, 'assign_subjects', $school_id)):
                          echo ' <a href="#" class="edit-assignment" 
                                 data-assignment-id="' . $assignment['teacher_subject_id'] . '"
                                 data-teacher-id="' . $teacher['user_id'] . '"
                                 data-subject-id="' . $assignment['subject_id'] . '"
                                 data-class-id="' . ($assignment['class_id'] ?? '') . '"
                                 data-stream-id="' . ($assignment['stream_id'] ?? '') . '"
                                 data-academic-year="' . $assignment['academic_year'] . '">
                                 <i class="bi bi-pencil text-primary"></i></a>';
                        endif;
                        echo '</li>';
                      endforeach;
                      echo '</ul>';
                    else:
                      echo 'No assignments';
                    endif;
                    ?>
                  </td>
                  <td>
                    <?php if (hasPermission($conn, $role_id, 'manage_teachers', $school_id)): ?>
                    <button class="btn btn-sm btn-primary edit-teacher" 
                            data-teacher-id="<?php echo $teacher['user_id']; ?>" 
                            data-first-name="<?php echo htmlspecialchars($teacher['first_name']); ?>" 
                            data-other-names="<?php echo htmlspecialchars($teacher['other_names'] ?? ''); ?>" 
                            data-username="<?php echo htmlspecialchars($teacher['username']); ?>" 
                            data-email="<?php echo htmlspecialchars($teacher['email'] ?? ''); ?>" 
                            data-personal-email="<?php echo htmlspecialchars($teacher['personal_email'] ?? ''); ?>" 
                            data-phone-number="<?php echo htmlspecialchars($teacher['phone_number'] ?? ''); ?>" 
                            data-gender="<?php echo htmlspecialchars($teacher['gender'] ?? ''); ?>" 
                            data-tsc-number="<?php echo htmlspecialchars($teacher['tsc_number'] ?? ''); ?>" 
                            data-employee-number="<?php echo htmlspecialchars($teacher['employee_number'] ?? ''); ?>" 
                            data-status="<?php echo htmlspecialchars($teacher['status']); ?>">
                      <i class="bi bi-pencil"></i> Edit
                    </button>
                    <button class="btn btn-sm btn-danger delete-teacher" data-teacher-id="<?php echo $teacher['user_id']; ?>">
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
                  <label class="form-label">First Name</label>
                  <input type="text" class="form-control" name="first_name" required>
                </div>
                <div class="col-md-6 mb-3">
                  <label class="form-label">Other Names</label>
                  <input type="text" class="form-control" name="other_names">
                </div>
                <div class="col-md-6 mb-3">
                  <label class="form-label">Username</label>
                  <input type="text" class="form-control" name="username" required>
                </div>
                <div class="col-md-6 mb-3">
                  <label class="form-label">Password</label>
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
                  <label class="form-label">First Name</label>
                  <input type="text" class="form-control" name="first_name" id="editFirstName" required>
                </div>
                <div class="col-md-6 mb-3">
                  <label class="form-label">Other Names</label>
                  <input type="text" class="form-control" name="other_names" id="editOtherNames">
                </div>
                <div class="col-md-6 mb-3">
                  <label class="form-label">Username</label>
                  <input type="text" class="form-control" name="username" id="editUsername" required>
                </div>
                <div class="col-md-6 mb-3">
                  <label class="form-label">Password (Leave blank to keep unchanged)</label>
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
                  <label class="form-label">Status</label>
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

    <!-- Assign Subjects Modal -->
    <?php if (hasPermission($conn, $role_id, 'assign_subjects', $school_id)): ?>
    <div class="modal fade" id="assignSubjectsModal" tabindex="-1">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title"><i class="bi bi-bookmark-plus me-2"></i> Assign Subjects</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <form id="assignSubjectsForm">
              <div class="mb-3">
                <label class="form-label">Select Teacher</label>
                <select class="form-select" name="user_id" id="teacherSelect" required>
                  <option value="">Select Teacher</option>
                  <?php foreach ($teachers as $teacher): ?>
                    <option value="<?php echo $teacher['user_id']; ?>">
                      <?php echo htmlspecialchars($teacher['first_name'] . ' ' . ($teacher['other_names'] ?? '')); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label">Select Subject</label>
                <select class="form-select" name="subject_id" id="subjectSelect" required>
                  <option value="">Select Subject</option>
                  <!-- Populated via AJAX -->
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label">Select Class (Optional)</label>
                <select class="form-select" name="class_id" id="classSelect">
                  <option value="">Select Class</option>
                  <!-- Populated via AJAX -->
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label">Select Stream (Optional)</label>
                <select class="form-select" name="stream_id" id="streamSelect">
                  <option value="">Select Stream</option>
                  <!-- Populated via AJAX -->
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label">Academic Year</label>
                <input type="number" class="form-control" name="academic_year" value="<?php echo date('Y'); ?>" required>
              </div>
              <button type="submit" class="btn btn-success">Assign Subject</button>
            </form>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Edit Assignment Modal -->
    <?php if (hasPermission($conn, $role_id, 'assign_subjects', $school_id)): ?>
    <div class="modal fade" id="editAssignmentModal" tabindex="-1">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title"><i class="bi bi-pencil me-2"></i> Edit Assignment</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <form id="editAssignmentForm">
              <input type="hidden" name="teacher_subject_id" id="editAssignmentId">
              <input type="hidden" name="user_id" id="editAssignmentTeacherId">
              <div class="mb-3">
                <label class="form-label">Teacher</label>
                <input type="text" class="form-control" id="editAssignmentTeacherName" disabled>
              </div>
              <div class="mb-3">
                <label class="form-label">Select Subject</label>
                <select class="form-select" name="subject_id" id="editSubjectSelect" required>
                  <option value="">Select Subject</option>
                  <!-- Populated via AJAX -->
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label">Select Class (Optional)</label>
                <select class="form-select" name="class_id" id="editClassSelect">
                  <option value="">Select Class</option>
                  <!-- Populated via AJAX -->
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label">Select Stream (Optional)</label>
                <select class="form-select" name="stream_id" id="editStreamSelect">
                  <option value="">Select Stream</option>
                  <!-- Populated via AJAX -->
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label">Academic Year</label>
                <input type="number" class="form-control" name="academic_year" id="editAcademicYear" required>
              </div>
              <button type="submit" class="btn btn-primary">Save Changes</button>
              <button type="button" class="btn btn-danger" id="deleteAssignment">Delete Assignment</button>
            </form>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function () {
  // AJAX Helper
  function ajaxRequest(options) {
    $.ajax({
      url: 'teachers/functions.php',
      method: 'POST',
      data: options.data,
      success: function (response) {
        try {
          const json = typeof response === 'string' ? JSON.parse(response) : response;
          if (json.status === 'success') {
            if (options.success) options.success(json);
          } else {
            alert('Error: ' + json.message);
          }
        } catch (e) {
          console.error('JSON parse error:', e, 'Response:', response);
          alert('Server error. Check console for details.');
        }
      },
      error: function (xhr, status, error) {
        console.error('AJAX error:', status, error, 'Response:', xhr.responseText);
        alert('Request failed. Check console for details.');
      }
    });
  }

  // Fetch options for assign/edit subjects
  function refreshAssignOptions(modalPrefix = '') {
    ajaxRequest({
      data: { action: 'get_assign_options' },
      success: function (json) {
        // Subjects
        const subjectOptions = json.subjects.map(s => 
          `<option value="${s.subject_id}">${s.name}</option>`
        ).join('');
        $(`#${modalPrefix}subjectSelect`).html(`<option value="">Select Subject</option>${subjectOptions}`);

        // Classes
        const classOptions = json.classes.map(c => 
          `<option value="${c.class_id}">${c.form_name}</option>`
        ).join('');
        $(`#${modalPrefix}classSelect`).html(`<option value="">Select Class</option>${classOptions}`);

        // Streams
        const streamOptions = json.streams.map(s => 
          `<option value="${s.stream_id}" data-class-id="${s.class_id}">${s.stream_name}</option>`
        ).join('');
        $(`#${modalPrefix}streamSelect`).html(`<option value="">Select Stream</option>${streamOptions}`);
      }
    });
  }

  // Update streams when class changes
  function updateStreams(classId, modalPrefix = '') {
    if (!classId) {
      $(`#${modalPrefix}streamSelect`).html('<option value="">Select Stream</option>');
      return;
    }
    ajaxRequest({
      data: { action: 'get_streams_by_class', class_id: classId },
      success: function (json) {
        const streamOptions = json.streams.map(s => 
          `<option value="${s.stream_id}">${s.stream_name}</option>`
        ).join('');
        $(`#${modalPrefix}streamSelect`).html(`<option value="">Select Stream</option>${streamOptions}`);
      }
    });
  }

  // Class select change handler
  $('#classSelect, #editClassSelect').on('change', function () {
    const modalPrefix = this.id === 'editClassSelect' ? 'edit' : '';
    updateStreams($(this).val(), modalPrefix);
  });

  // Add Teacher
  $('#addTeacherForm').on('submit', function (e) {
    e.preventDefault();
    ajaxRequest({
      data: $(this).serialize() + '&action=add_teacher',
      success: function (json) {
        alert(json.message);
        $('#addTeacherModal').modal('hide');
        window.location.reload();
      }
    });
  });

  // Edit Teacher
  $('.edit-teacher').on('click', function () {
    $('#editTeacherId').val($(this).data('teacher-id'));
    $('#editFirstName').val($(this).data('first-name'));
    $('#editOtherNames').val($(this).data('other-names'));
    $('#editUsername').val($(this).data('username'));
    $('#editEmail').val($(this).data('email'));
    $('#editPersonalEmail').val($(this).data('personal-email'));
    $('#editPhoneNumber').val($(this).data('phone-number'));
    $('#editGender').val($(this).data('gender'));
    $('#editTscNumber').val($(this).data('tsc-number'));
    $('#editEmployeeNumber').val($(this).data('employee-number'));
    $('#editStatus').val($(this).data('status'));
    $('#editTeacherModal').modal('show');
  });

  $('#editTeacherForm').on('submit', function (e) {
    e.preventDefault();
    ajaxRequest({
      data: $(this).serialize() + '&action=edit_teacher',
      success: function (json) {
        alert(json.message);
        $('#editTeacherModal').modal('hide');
        window.location.reload();
      }
    });
  });

  // Delete Teacher
  $('.delete-teacher').on('click', function () {
    if (confirm('Are you sure you want to delete this teacher?')) {
      ajaxRequest({
        data: { action: 'delete_teacher', user_id: $(this).data('teacher-id') },
        success: function (json) {
          alert(json.message);
          window.location.reload();
        }
      });
    }
  });

  // Assign Subjects
  $('#assignSubjectsForm').on('submit', function (e) {
    e.preventDefault();
    ajaxRequest({
      data: $(this).serialize() + '&action=assign_subject',
      success: function (json) {
        alert(json.message);
        $('#assignSubjectsModal').modal('hide');
        $('#assignSubjectsForm')[0].reset();
        refreshAssignOptions();
        window.location.reload();
      }
    });
  });

  // Edit Assignment
  $('.edit-assignment').on('click', function () {
    const assignmentId = $(this).data('assignment-id');
    const teacherId = $(this).data('teacher-id');
    const subjectId = $(this).data('subject-id');
    const classId = $(this).data('class-id');
    const streamId = $(this).data('stream-id');
    const academicYear = $(this).data('academic-year');
    const teacherName = $(this).closest('tr').find('td:eq(1)').text();

    $('#editAssignmentId').val(assignmentId);
    $('#editAssignmentTeacherId').val(teacherId);
    $('#editAssignmentTeacherName').val(teacherName);
    $('#editAcademicYear').val(academicYear);

    refreshAssignOptions('edit');
    $('#editAssignmentModal').modal('show');

    // Set values after options are loaded
    setTimeout(() => {
      $('#editSubjectSelect').val(subjectId);
      $('#editClassSelect').val(classId);
      updateStreams(classId, 'edit');
      setTimeout(() => {
        $('#editStreamSelect').val(streamId);
      }, 500);
    }, 500);
  });

  $('#editAssignmentForm').on('submit', function (e) {
    e.preventDefault();
    ajaxRequest({
      data: $(this).serialize() + '&action=edit_assignment',
      success: function (json) {
        alert(json.message);
        $('#editAssignmentModal').modal('hide');
        window.location.reload();
      }
    });
  });

  // Delete Assignment
  $('#deleteAssignment').on('click', function () {
    if (confirm('Are you sure you want to delete this assignment?')) {
      ajaxRequest({
        data: { action: 'delete_assignment', teacher_subject_id: $('#editAssignmentId').val() },
        success: function (json) {
          alert(json.message);
          $('#editAssignmentModal').modal('hide');
          window.location.reload();
        }
      });
    }
  });

  // Search and Filter
  $('#searchInput, #statusFilter').on('input change', function () {
    const searchText = $('#searchInput').val().toLowerCase();
    const status = $('#statusFilter').val();

    $('tbody tr').each(function () {
      const name = $(this).find('td:eq(1)').text().toLowerCase();
      const tscNumber = $(this).find('td:eq(3)').text().toLowerCase();
      const rowStatus = $(this).find('td:eq(4) .badge').text().toLowerCase();

      const matchesSearch = searchText === '' || name.includes(searchText) || tscNumber.includes(searchText);
      const matchesStatus = status === '' || rowStatus === status;

      $(this).toggle(matchesSearch && matchesStatus);
    });
  });

  // Initialize assign options
  refreshAssignOptions();
});
</script>

<?php include '../footer.php'; ?>