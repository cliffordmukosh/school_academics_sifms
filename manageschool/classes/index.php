<?php
//classes/index.php

include __DIR__ . '/../header.php'; 
include __DIR__ . '/../sidebar.php';
require __DIR__ . '/../../connection/db.php';

// Check if user is logged in and has appropriate permissions
if (!isset($_SESSION['user_id']) || !isset($_SESSION['school_id']) || !isset($_SESSION['role_id'])) {
    header("Location: ../../login.php");
    exit;
}

// Fetch classes for this school
$stmt = $conn->prepare("SELECT class_id, form_name, description FROM classes WHERE school_id = ? ORDER BY form_name");
$stmt->bind_param("i", $_SESSION['school_id']);
$stmt->execute();
$classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$totalClasses = count($classes);
$stmt->close();

// Fetch streams for this school, including description to prevent undefined key warning
$stmt = $conn->prepare("SELECT stream_id, stream_name, class_id, description FROM streams WHERE school_id = ? ORDER BY stream_name");
$stmt->bind_param("i", $_SESSION['school_id']);
$stmt->execute();
$streams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$totalStreams = count($streams);
$stmt->close();

// Debug streams query
error_log("Streams query returned " . count($streams) . " rows for school_id " . $_SESSION['school_id']);

// Fetch students per stream
$stmt = $conn->prepare("
    SELECT s.student_id, s.full_name, s.admission_no, s.gender, c.class_id, c.form_name, st.stream_id, st.stream_name
    FROM students s
    JOIN classes c ON s.class_id = c.class_id
    JOIN streams st ON s.stream_id = st.stream_id
    WHERE s.school_id = ? AND s.deleted_at IS NULL
    ORDER BY c.form_name, st.stream_name, s.full_name
");
$stmt->bind_param("i", $_SESSION['school_id']);
$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Organize students by class and stream
$classStreamStudents = [];
foreach ($classes as $class) {
    $classStreamStudents[$class['class_id']] = [
        'form_name' => $class['form_name'],
        'streams' => []
    ];
}
foreach ($streams as $stream) {
    $classStreamStudents[$stream['class_id']]['streams'][$stream['stream_id']] = [
        'stream_name' => $stream['stream_name'],
        'students' => []
    ];
}
foreach ($students as $student) {
    $classStreamStudents[$student['class_id']]['streams'][$student['stream_id']]['students'][] = $student;
}

// Fetch class supervisors for the current academic year
$current_year = date('Y');
$stmt = $conn->prepare("
    SELECT cs.class_id, CONCAT(u.first_name, ' ', COALESCE(u.other_names, '')) AS supervisor_name
    FROM class_supervisors cs
    JOIN users u ON cs.user_id = u.user_id
    WHERE cs.school_id = ? AND cs.academic_year = ?
");
$stmt->bind_param("ii", $_SESSION['school_id'], $current_year);
$stmt->execute();
$supervisors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Create an array to map class_id to supervisor name
$supervisor_map = [];
foreach ($supervisors as $supervisor) {
    $supervisor_map[$supervisor['class_id']] = $supervisor['supervisor_name'];
}

// Fetch class teachers for the current academic year
$stmt = $conn->prepare("
    SELECT ct.stream_id, CONCAT(u.first_name, ' ', COALESCE(u.other_names, '')) AS teacher_name
    FROM class_teachers ct
    JOIN users u ON ct.user_id = u.user_id
    WHERE ct.school_id = ? AND ct.academic_year = ?
");
$stmt->bind_param("ii", $_SESSION['school_id'], $current_year);
$stmt->execute();
$teachers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Create an array to map stream_id to teacher name
$teacher_map = [];
foreach ($teachers as $teacher) {
    $teacher_map[$teacher['stream_id']] = $teacher['teacher_name'];
}

?>
<div class="content">
  <div class="container-fluid">

<div class="container py-4">
  <!-- Title with dynamic count -->
  <h3 class="mb-4 d-flex align-items-center">
    <i class="bi bi-book me-2"></i> Class Management
    <span class="badge bg-primary ms-3 fs-6">Total Classes: <?php echo $totalClasses; ?></span>
  </h3>

  <!-- Class Management Menu -->
  <div class="row g-4 mb-4">
    <!-- Add Class -->
    <div class="col-md-3">
      <div class="card shadow-sm border-0 h-100 text-center">
        <div class="card-body d-flex flex-column justify-content-center">
          <i class="bi bi-plus-square display-5 text-primary"></i>
          <h5 class="mt-3">Add Class</h5>
          <p class="text-muted">Create a new class for the school.</p>
          <button class="btn btn-primary mt-auto" data-bs-toggle="modal" data-bs-target="#addClassModal">
            <i class="bi bi-plus-circle me-2"></i> Add Class
          </button>
        </div>
      </div>
    </div>

    <!-- Manage Classes -->
    <div class="col-md-3">
      <div class="card shadow-sm border-0 h-100 text-center">
        <div class="card-body d-flex flex-column justify-content-center">
          <i class="bi bi-gear display-5 text-warning"></i>
          <h5 class="mt-3">Manage Classes</h5>
          <p class="text-muted">View, edit, or delete classes.</p>
          <button class="btn btn-warning mt-auto" data-bs-toggle="modal" data-bs-target="#manageClassesModal">
            <i class="bi bi-gear me-2"></i> Manage Classes
          </button>
        </div>
      </div>
    </div>

    <!-- Add Stream -->
    <div class="col-md-3">
      <div class="card shadow-sm border-0 h-100 text-center">
        <div class="card-body d-flex flex-column justify-content-center">
          <i class="bi bi-diagram-2 display-5 text-info"></i>
          <h5 class="mt-3">Add Stream</h5>
          <p class="text-muted">Create a new stream for a class.</p>
          <button class="btn btn-info text-white mt-auto" data-bs-toggle="modal" data-bs-target="#addStreamModal">
            <i class="bi bi-plus-circle me-2"></i> Add Stream
          </button>
        </div>
      </div>
    </div>

    <!-- Manage Streams -->
    <div class="col-md-3">
      <div class="card shadow-sm border-0 h-100 text-center">
        <div class="card-body d-flex flex-column justify-content-center">
          <i class="bi bi-gear display-5 text-secondary"></i>
          <h5 class="mt-3">Manage Streams</h5>
          <p class="text-muted">View, edit, or delete streams.</p>
          <button class="btn btn-secondary mt-auto" data-bs-toggle="modal" data-bs-target="#manageStreamsModal">
            <i class="bi bi-gear me-2"></i> Manage Streams
          </button>
        </div>
      </div>
    </div>

    <!-- Move Students -->
    <div class="col-md-3">
      <div class="card shadow-sm border-0 h-100 text-center">
        <div class="card-body d-flex flex-column justify-content-center">
          <i class="bi bi-arrow-repeat display-5 text-success"></i>
          <h5 class="mt-3">Move Students</h5>
          <p class="text-muted">Transfer students between classes and streams.</p>
          <button class="btn btn-success mt-auto" data-bs-toggle="modal" data-bs-target="#moveStudentModal">
            <i class="bi bi-box-arrow-in-right me-2"></i> Move Students
          </button>
        </div>
      </div>
    </div>

    <!-- Assign Class Supervisor -->
    <div class="col-md-3">
      <div class="card shadow-sm border-0 h-100 text-center">
        <div class="card-body d-flex flex-column justify-content-center">
          <i class="bi bi-person-check display-5 text-primary"></i>
          <h5 class="mt-3">Assign Class Supervisor</h5>
          <p class="text-muted">Assign a supervisor to a class.</p>
          <button class="btn btn-primary mt-auto" data-bs-toggle="modal" data-bs-target="#assignClassSupervisorModal">
            <i class="bi bi-person-plus me-2"></i> Assign Supervisor
          </button>
        </div>
      </div>
    </div>

    <!-- Assign Class Teacher -->
    <div class="col-md-3">
      <div class="card shadow-sm border-0 h-100 text-center">
        <div class="card-body d-flex flex-column justify-content-center">
          <i class="bi bi-person-check display-5 text-info"></i>
          <h5 class="mt-3">Assign Class Teacher</h5>
          <p class="text-muted">Assign a teacher to a stream.</p>
          <button class="btn btn-info text-white mt-auto" data-bs-toggle="modal" data-bs-target="#assignClassTeacherModal">
            <i class="bi bi-person-plus me-2"></i> Assign Teacher
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Class and Stream List -->
  <div class="card shadow-sm border-0">
    <div class="card-body">
      <h5 class="mb-3 d-flex align-items-center">
        <i class="bi bi-list-ul me-2"></i> Class and Stream List
      </h5>

      <!-- Search and Filter -->
      <div class="row mb-3">
        <div class="col-md-4">
          <input type="text" id="searchInput" class="form-control" placeholder="Search by student name or admission number">
        </div>
        <div class="col-md-4">
          <select id="classFilter" class="form-select">
            <option value="">All Classes</option>
            <?php foreach ($classes as $class): ?>
              <option value="<?php echo htmlspecialchars($class['class_id']); ?>">
                <?php echo htmlspecialchars($class['form_name']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <select id="streamFilter" class="form-select">
            <option value="">All Streams</option>
            <!-- Populated dynamically by JavaScript -->
          </select>
        </div>
      </div>

      <!-- Class and Stream Accordion -->
      <div class="accordion" id="classAccordion">
        <?php foreach ($classStreamStudents as $class_id => $class): ?>
          <div class="accordion-item" data-class-id="<?php echo $class_id; ?>">
            <h2 class="accordion-header" id="classHeading<?php echo $class_id; ?>">
              <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#classCollapse<?php echo $class_id; ?>">
                <strong>
                  <?php echo htmlspecialchars($class['form_name']); ?>
                  <?php if (isset($supervisor_map[$class_id])): ?>
                    <span class="text-muted ms-2">(Class Supervisor: <?php echo htmlspecialchars($supervisor_map[$class_id]); ?>)</span>
                  <?php endif; ?>
                </strong>
                <span class="badge bg-info ms-3">Streams: <?php echo count($class['streams']); ?></span>
              </button>
            </h2>
            <div id="classCollapse<?php echo $class_id; ?>" class="accordion-collapse collapse" data-bs-parent="#classAccordion">
              <div class="accordion-body">
                <?php foreach ($class['streams'] as $stream_id => $stream): ?>
                  <h6 class="mb-3" data-stream-id="<?php echo $stream_id; ?>">
                    <?php echo htmlspecialchars($stream['stream_name']); ?>
                    <?php if (isset($teacher_map[$stream_id])): ?>
                      <span class="text-muted ms-2">(Class Teacher: <?php echo htmlspecialchars($teacher_map[$stream_id]); ?>)</span>
                    <?php endif; ?>
                    <span class="badge bg-secondary ms-2">Students: <?php echo count($stream['students']); ?></span>
                  </h6>
                  <?php if (count($stream['students']) > 0): ?>
                    <table class="table table-striped table-hover align-middle">
                      <thead class="table-dark">
                        <tr>
                          <th>#</th>
                          <th>Name</th>
                          <th>Admission No</th>
                          <th>Gender</th>
                          <th>Actions</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($stream['students'] as $index => $student): ?>
                          <tr data-student-id="<?php echo $student['student_id']; ?>">
                            <td><?php echo $index + 1; ?></td>
                            <td><?php echo htmlspecialchars($student['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($student['admission_no']); ?></td>
                            <td><?php echo htmlspecialchars($student['gender']); ?></td>
                            <td>
                              <button class="btn btn-sm btn-success move-student" data-class-id="<?php echo $class_id; ?>" data-stream-id="<?php echo $stream_id; ?>" data-bs-toggle="modal" data-bs-target="#moveStudentModal">
                                <i class="bi bi-box-arrow-in-right"></i> Move
                              </button>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  <?php else: ?>
                    <p class="text-muted">No students in this stream.</p>
                  <?php endif; ?>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
</div>

  <!-- Add Class Modal -->
  <div class="modal fade" id="addClassModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-plus-square me-2"></i> Add Class</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <form id="addClassForm">
            <div class="mb-3">
              <label class="form-label">Class Name</label>
              <input type="text" class="form-control" name="form_name" placeholder="e.g., Form 4" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Description (Optional)</label>
              <textarea class="form-control" name="description" placeholder="Enter class description"></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Add Class</button>
          </form>
        </div>
      </div>
    </div>
  </div>

   <!-- Assign Class Supervisor Modal -->
  <div class="modal fade" id="assignClassSupervisorModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-person-check me-2"></i> Assign Class Supervisor</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <form id="assignClassSupervisorForm">
            <div class="mb-3">
              <label class="form-label">Class</label>
              <select class="form-select" name="class_id" id="supervisorClassSelect" required>
                <option value="">Select Class</option>
                <?php foreach ($classes as $class): ?>
                  <option value="<?php echo $class['class_id']; ?>">
                    <?php echo htmlspecialchars($class['form_name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">Teacher</label>
              <select class="form-select" name="user_id" id="supervisorTeacherSelect" required>
                <option value="">Select Teacher</option>
                <!-- Populated dynamically by JavaScript -->
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">Academic Year</label>
              <input type="number" class="form-control" name="academic_year" value="<?php echo date('Y'); ?>" placeholder="e.g., 2025" min="2000" max="2099" required>
            </div>
            <button type="submit" class="btn btn-primary">Assign Supervisor</button>
          </form>
        </div>
      </div>
    </div>
</div>

  <!-- Assign Class Teacher Modal -->
  <div class="modal fade" id="assignClassTeacherModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-person-check me-2"></i> Assign Class Teacher</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <form id="assignClassTeacherForm">
            <div class="mb-3">
              <label class="form-label">Class</label>
              <select class="form-select" name="class_id" id="teacherClassSelect" required>
                <option value="">Select Class</option>
                <?php foreach ($classes as $class): ?>
                  <option value="<?php echo $class['class_id']; ?>">
                    <?php echo htmlspecialchars($class['form_name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">Stream</label>
              <select class="form-select" name="stream_id" id="teacherStreamSelect" required>
                <option value="">Select Stream</option>
                <!-- Populated dynamically by JavaScript -->
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">Teacher</label>
              <select class="form-select" name="user_id" id="classTeacherSelect" required>
                <option value="">Select Teacher</option>
                <!-- Populated dynamically by JavaScript -->
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">Academic Year</label>
              <input type="number" class="form-control" name="academic_year" value="<?php echo date('Y'); ?>" placeholder="e.g., 2025" min="2000" max="2099" required>
            </div>
            <button type="submit" class="btn btn-info text-white">Assign Teacher</button>
          </form>
        </div>
      </div>
    </div>
</div>

  <!-- Manage Classes Modal -->
  <div class="modal fade" id="manageClassesModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-gear me-2"></i> Manage Classes</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <table class="table table-striped table-hover">
            <thead class="table-dark">
              <tr>
                <th>Class Name</th>
                <th>Description</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="classesTableBody">
              <?php foreach ($classes as $class): ?>
                <tr data-class-id="<?php echo $class['class_id']; ?>">
                  <td><?php echo htmlspecialchars($class['form_name']); ?></td>
                  <td><?php echo htmlspecialchars($class['description'] ?: 'No description'); ?></td>
                  <td>
                    <button class="btn btn-sm btn-primary edit-class" data-class-id="<?php echo $class['class_id']; ?>" data-form-name="<?php echo htmlspecialchars($class['form_name']); ?>" data-description="<?php echo htmlspecialchars($class['description'] ?: ''); ?>">
                      <i class="bi bi-pencil"></i> Edit
                    </button>
                    <button class="btn btn-sm btn-danger delete-class" data-class-id="<?php echo $class['class_id']; ?>">
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

  <!-- Edit Class Modal -->
  <div class="modal fade" id="editClassModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-pencil me-2"></i> Edit Class</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <form id="editClassForm">
            <input type="hidden" name="class_id" id="editClassId">
            <div class="mb-3">
              <label class="form-label">Class Name</label>
              <input type="text" class="form-control" name="form_name" id="editClassName" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Description (Optional)</label>
              <textarea class="form-control" name="description" id="editClassDescription"></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Save Changes</button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <!-- Add Stream Modal -->
  <div class="modal fade" id="addStreamModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-diagram-2 me-2"></i> Add Stream</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <form id="addStreamForm">
            <div class="mb-3">
              <label class="form-label">Class</label>
              <select class="form-select" name="class_id" id="streamClassSelect" required>
                <option value="">Select Class</option>
                <?php foreach ($classes as $class): ?>
                  <option value="<?php echo $class['class_id']; ?>">
                    <?php echo htmlspecialchars($class['form_name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">Stream Name</label>
              <input type="text" class="form-control" name="stream_name" placeholder="e.g., X" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Description (Optional)</label>
              <textarea class="form-control" name="description" placeholder="Enter stream description"></textarea>
            </div>
            <button type="submit" class="btn btn-info text-white">Add Stream</button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <!-- Manage Streams Modal -->
  <div class="modal fade" id="manageStreamsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-gear me-2"></i> Manage Streams</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <table class="table table-striped table-hover">
            <thead class="table-dark">
              <tr>
                <th>Class</th>
                <th>Stream Name</th>
                <th>Description</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="streamsTableBody">
              <?php foreach ($streams as $stream): ?>
                <?php
                $class = array_filter($classes, function($c) use ($stream) { return $c['class_id'] == $stream['class_id']; });
                $class_name = !empty($class) ? reset($class)['form_name'] : 'Unknown';
                ?>
                <tr data-stream-id="<?php echo $stream['stream_id']; ?>">
                  <td><?php echo htmlspecialchars($class_name); ?></td>
                  <td><?php echo htmlspecialchars($stream['stream_name']); ?></td>
                  <td><?php echo htmlspecialchars($stream['description'] ?: 'No description'); ?></td>
                  <td>
                    <button class="btn btn-sm btn-primary edit-stream" data-stream-id="<?php echo $stream['stream_id']; ?>" data-class-id="<?php echo $stream['class_id']; ?>" data-stream-name="<?php echo htmlspecialchars($stream['stream_name']); ?>" data-description="<?php echo htmlspecialchars($stream['description'] ?: ''); ?>">
                      <i class="bi bi-pencil"></i> Edit
                    </button>
                    <button class="btn btn-sm btn-danger delete-stream" data-stream-id="<?php echo $stream['stream_id']; ?>">
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

  <!-- Edit Stream Modal -->
  <div class="modal fade" id="editStreamModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-pencil me-2"></i> Edit Stream</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <form id="editStreamForm">
            <input type="hidden" name="stream_id" id="editStreamId">
            <div class="mb-3">
              <label class="form-label">Class</label>
              <select class="form-select" name="class_id" id="editStreamClassId" required>
                <option value="">Select Class</option>
                <?php foreach ($classes as $class): ?>
                  <option value="<?php echo $class['class_id']; ?>">
                    <?php echo htmlspecialchars($class['form_name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">Stream Name</label>
              <input type="text" class="form-control" name="stream_name" id="editStreamName" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Description (Optional)</label>
              <textarea class="form-control" name="description" id="editStreamDescription"></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Save Changes</button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <!-- Move Student Modal -->
  <div class="modal fade" id="moveStudentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-box-arrow-in-right me-2"></i> Move Students</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <form id="moveStudentForm">
            <div class="mb-3">
              <label class="form-label">Source Class</label>
              <select class="form-select" name="source_class_id" id="sourceClassSelect" required>
                <option value="">Select Source Class</option>
                <?php foreach ($classes as $class): ?>
                  <option value="<?php echo $class['class_id']; ?>">
                    <?php echo htmlspecialchars($class['form_name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">Source Stream</label>
              <select class="form-select" name="source_stream_id" id="sourceStreamSelect" required>
                <option value="">Select Source Stream</option>
                <!-- Populated dynamically by JavaScript -->
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">New Class</label>
              <select class="form-select" name="class_id" id="newClassSelect" required>
                <option value="">Select Class</option>
                <?php foreach ($classes as $class): ?>
                  <option value="<?php echo $class['class_id']; ?>">
                    <?php echo htmlspecialchars($class['form_name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">New Stream</label>
              <div class="input-group">
                <select class="form-select" name="stream_id" id="newStreamSelect" required>
                  <option value="">Select Stream</option>
                  <!-- Populated dynamically by JavaScript -->
                </select>
                <button type="button" class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#addStreamModal" id="addStreamFromMove">
                  <i class="bi bi-plus-circle"></i>
                </button>
              </div>
            </div>
            <button type="submit" class="btn btn-success">Move Students</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

</div>
</div>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function () {
  // Log initial streams data
  let streams = <?php echo json_encode($streams); ?>;
  console.log('🔍 Initial Streams Data:', streams);

  // Function to filter streams based on class_id
  function updateStreamOptions(classId, streamSelect, selectId) {
    console.log(`🔄 Updating Stream Options for ${selectId} with class_id:`, classId);
    streamSelect.empty().append('<option value="">Select Stream</option>');
    let streamCount = 0;
    streams.forEach(function (stream) {
      if (!classId || stream.class_id == classId) {
        streamSelect.append(
          `<option value="${stream.stream_id}">${stream.stream_name}</option>`
        );
        streamCount++;
      }
    });
    console.log(`✅ Found ${streamCount} streams for ${selectId} with class_id ${classId}`);
    if (streamCount === 0) {
      console.warn(`⚠️ No streams found for ${selectId} with class_id:`, classId);
    }
  }

  // Function to refresh classes and streams via AJAX
  function refreshClassesAndStreams() {
    $.ajax({
      url: 'classes/functions.php',
      method: 'POST',
      data: { action: 'get_classes_and_streams' },
      success: function (response) {
        console.log('🔍 Get Classes and Streams Response:', response);
        try {
          const json = JSON.parse(response);
          if (json.status === 'success') {
            // Update streams
            streams.length = 0;
            json.streams.forEach(stream => streams.push(stream));
            console.log('✅ Updated Streams Data:', streams);

            // Refresh dropdowns
            const sourceClassId = $('#sourceClassSelect').val();
            const newClassId = $('#newClassSelect').val();
            const filterClassId = $('#classFilter').val();
            if (sourceClassId) {
              updateStreamOptions(sourceClassId, $('#sourceStreamSelect'), 'sourceStreamSelect');
            }
            if (newClassId) {
              updateStreamOptions(newClassId, $('#newStreamSelect'), 'newStreamSelect');
            }
            if (filterClassId) {
              updateStreamOptions(filterClassId, $('#streamFilter'), 'streamFilter');
            }

            // Refresh classes dropdowns
            const classOptions = json.classes.map(cls => 
              `<option value="${cls.class_id}">${cls.form_name}</option>`
            ).join('');
            $('#sourceClassSelect, #newClassSelect, #streamClassSelect, #editStreamClassId').each(function () {
              const currentVal = $(this).val();
              $(this).html(`<option value="">Select Class</option>${classOptions}`);
              if (currentVal) $(this).val(currentVal);
            });

            // Refresh classes table
            const classesRows = json.classes.map(cls => `
              <tr data-class-id="${cls.class_id}">
                <td>${cls.form_name}</td>
                <td>${cls.description || 'No description'}</td>
                <td>
                  <button class="btn btn-sm btn-primary edit-class" data-class-id="${cls.class_id}" data-form-name="${cls.form_name}" data-description="${cls.description || ''}">
                    <i class="bi bi-pencil"></i> Edit
                  </button>
                  <button class="btn btn-sm btn-danger delete-class" data-class-id="${cls.class_id}">
                    <i class="bi bi-trash"></i> Delete
                  </button>
                </td>
              </tr>
            `).join('');
            $('#classesTableBody').html(classesRows);

            // Refresh streams table
            const streamsRows = json.streams.map(stream => {
              const className = json.classes.find(cls => cls.class_id == stream.class_id)?.form_name || 'Unknown';
              return `
                <tr data-stream-id="${stream.stream_id}">
                  <td>${className}</td>
                  <td>${stream.stream_name}</td>
                  <td>${stream.description || 'No description'}</td>
                  <td>
                    <button class="btn btn-sm btn-primary edit-stream" data-stream-id="${stream.stream_id}" data-class-id="${stream.class_id}" data-stream-name="${stream.stream_name}" data-description="${stream.description || ''}">
                      <i class="bi bi-pencil"></i> Edit
                    </button>
                    <button class="btn btn-sm btn-danger delete-stream" data-stream-id="${stream.stream_id}">
                      <i class="bi bi-trash"></i> Delete
                    </button>
                  </td>
                </tr>
              `;
            }).join('');
            $('#streamsTableBody').html(streamsRows);
          } else {
            console.error('❌ Failed to refresh classes and streams:', json.message);
          }
        } catch (e) {
          console.error('❌ JSON parse error:', e);
          console.log('🔴 Response text:', response);
        }
      },
      error: function (xhr, status, error) {
        console.error('❌ AJAX Error for get_classes_and_streams:', status, error, xhr.responseText);
      }
    });
  }

  // Update streams when source class changes
  $('#sourceClassSelect').on('change', function () {
    const classId = $(this).val();
    console.log('🎯 Source Class Selected:', classId);
    updateStreamOptions(classId, $('#sourceStreamSelect'), 'sourceStreamSelect');
  });

  // Update streams when new class changes
  $('#newClassSelect').on('change', function () {
    const classId = $(this).val();
    console.log('🎯 New Class Selected in Move Student:', classId);
    updateStreamOptions(classId, $('#newStreamSelect'), 'newStreamSelect');
  });

  // Update streams when class filter changes
  $('#classFilter').on('change', function () {
    const classId = $(this).val();
    console.log('🎯 Class Filter Selected:', classId);
    updateStreamOptions(classId, $('#streamFilter'), 'streamFilter');
  });

  // Pre-select source class and stream in Move Student modal
  $('.move-student').on('click', function () {
    const classId = $(this).data('class-id');
    const streamId = $(this).data('stream-id');
    console.log('👤 Move button clicked for class_id:', classId, 'stream_id:', streamId);
    $('#sourceClassSelect').val(classId);
    updateStreamOptions(classId, $('#sourceStreamSelect'), 'sourceStreamSelect');
    $('#sourceStreamSelect').val(streamId);
  });

  // Pre-select class in Add Stream modal when opened from Move Student
  $('#addStreamFromMove').on('click', function () {
    const classId = $('#newClassSelect').val();
    console.log('➕ Opening Add Stream from Move Student, class_id:', classId);
    if (classId) {
      $('#streamClassSelect').val(classId);
      console.log('✅ Set streamClassSelect to:', classId);
    } else {
      console.warn('⚠️ No class selected in Move Student modal');
    }
  });

  // Edit Class
  $(document).on('click', '.edit-class', function () {
    const classId = $(this).data('class-id');
    const formName = $(this).data('form-name');
    const description = $(this).data('description');
    console.log('✏️ Editing class:', classId);
    $('#editClassId').val(classId);
    $('#editClassName').val(formName);
    $('#editClassDescription').val(description);
    $('#editClassModal').modal('show');
  });

  // Delete Class
  $(document).on('click', '.delete-class', function () {
    const classId = $(this).data('class-id');
    console.log('🗑️ Deleting class:', classId);
    if (confirm('Are you sure you want to delete this class? This will also delete associated streams and move students to a default class if necessary.')) {
      ajaxDebug({
        url: 'classes/functions.php',
        method: 'POST',
        data: { action: 'delete_class', class_id: classId },
        onSuccess: function (json) {
          alert(json.message);
          if (json.status === 'success') {
            $('#manageClassesModal').modal('hide');
            refreshClassesAndStreams();
            window.location.reload(); // Reload to update accordion
          }
        }
      });
    }
  });

  // Edit Stream
  $(document).on('click', '.edit-stream', function () {
    const streamId = $(this).data('stream-id');
    const classId = $(this).data('class-id');
    const streamName = $(this).data('stream-name');
    const description = $(this).data('description');
    console.log('✏️ Editing stream:', streamId);
    $('#editStreamId').val(streamId);
    $('#editStreamClassId').val(classId);
    $('#editStreamName').val(streamName);
    $('#editStreamDescription').val(description);
    $('#editStreamModal').modal('show');
  });

  // Delete Stream
  $(document).on('click', '.delete-stream', function () {
    const streamId = $(this).data('stream-id');
    console.log('🗑️ Deleting stream:', streamId);
    if (confirm('Are you sure you want to delete this stream? Students will be moved to a default stream if necessary.')) {
      ajaxDebug({
        url: 'classes/functions.php',
        method: 'POST',
        data: { action: 'delete_stream', stream_id: streamId },
        onSuccess: function (json) {
          alert(json.message);
          if (json.status === 'success') {
            $('#manageStreamsModal').modal('hide');
            refreshClassesAndStreams();
            window.location.reload(); // Reload to update accordion
          }
        }
      });
    }
  });

  // Refresh stream options after adding a stream
  $('#addStreamModal').on('hidden.bs.modal', function () {
    const sourceClassId = $('#sourceClassSelect').val();
    const newClassId = $('#newClassSelect').val();
    if (sourceClassId || newClassId) {
      refreshClassesAndStreams();
    }
  });

  // Search and Filter Students
  $('#searchInput, #classFilter, #streamFilter').on('input change', function () {
    const searchText = $('#searchInput').val().toLowerCase();
    const classId = $('#classFilter').val();
    const streamId = $('#streamFilter').val();

    $('.accordion-item').each(function () {
      const $accordionItem = $(this);
      const classIdAttr = $accordionItem.data('class-id');
      const classVisible = !classId || classIdAttr == classId;

      let hasVisibleStudents = false;
      $accordionItem.find('h6').each(function () {
        const $streamHeader = $(this);
        const streamIdAttr = $streamHeader.data('stream-id');
        const streamVisible = !streamId || streamIdAttr == streamId;

        let streamHasVisibleStudents = false;
        $streamHeader.next('table').find('tbody tr').each(function () {
          const $row = $(this);
          const name = $row.find('td:eq(1)').text().toLowerCase();
          const admissionNo = $row.find('td:eq(2)').text().toLowerCase();

          const matchesSearch = searchText === '' || name.includes(searchText) || admissionNo.includes(searchText);
          const rowVisible = matchesSearch && classVisible && streamVisible;
          $row.toggle(rowVisible);
          if (rowVisible) streamHasVisibleStudents = true;
        });

        $streamHeader.toggle(streamVisible && streamHasVisibleStudents);
        if (streamVisible && streamHasVisibleStudents) hasVisibleStudents = true;
      });

      $accordionItem.toggle(classVisible && hasVisibleStudents);
    });
  });

  // AJAX Helper
  function ajaxDebug(options) {
    console.log('🔹 Sending AJAX:', options);
    $.ajax({
      ...options,
      success: function (response, status, xhr) {
        console.log('✅ Raw Response:', response);
        try {
          const json = typeof response === 'string' ? JSON.parse(response) : response;
          console.log('✅ Parsed JSON:', json);
          if (options.onSuccess) options.onSuccess(json);
        } catch (e) {
          console.error('❌ JSON parse failed:', e);
          console.log('🔴 Response text:', response);
          alert('Server returned invalid JSON. Check console.');
        }
      },
      error: function (xhr, status, error) {
        console.error('❌ AJAX Error:', status, error);
        console.log('🔴 Response text:', xhr.responseText);
        try {
          const json = JSON.parse(xhr.responseText);
          alert(json.message || 'An error occurred. Check console.');
        } catch (e) {
          alert('An error occurred. Check console.');
        }
      }
    });
  }

  // Add Class
  $('#addClassForm').on('submit', function (e) {
    e.preventDefault();
    ajaxDebug({
      url: 'classes/functions.php',
      method: 'POST',
      data: $(this).serialize() + '&action=add_class',
      onSuccess: function (json) {
        alert(json.message);
        if (json.status === 'success') {
          $('#addClassModal').modal('hide');
          refreshClassesAndStreams();
          window.location.reload();
        }
      }
    });
  });

  // Edit Class
  $('#editClassForm').on('submit', function (e) {
    e.preventDefault();
    ajaxDebug({
      url: 'classes/functions.php',
      method: 'POST',
      data: $(this).serialize() + '&action=edit_class',
      onSuccess: function (json) {
        alert(json.message);
        if (json.status === 'success') {
          $('#editClassModal').modal('hide');
          $('#manageClassesModal').modal('show');
          refreshClassesAndStreams();
          window.location.reload();
        }
      }
    });
  });

  // Add Stream
  $('#addStreamForm').on('submit', function (e) {
    e.preventDefault();
    ajaxDebug({
      url: 'classes/functions.php',
      method: 'POST',
      data: $(this).serialize() + '&action=add_stream',
      onSuccess: function (json) {
        alert(json.message);
        if (json.status === 'success') {
          $('#addStreamModal').modal('hide');
          $('#moveStudentModal').modal('show');
          refreshClassesAndStreams();
        }
      }
    });
  });

  // Edit Stream
  $('#editStreamForm').on('submit', function (e) {
    e.preventDefault();
    ajaxDebug({
      url: 'classes/functions.php',
      method: 'POST',
      data: $(this).serialize() + '&action=edit_stream',
      onSuccess: function (json) {
        alert(json.message);
        if (json.status === 'success') {
          $('#editStreamModal').modal('hide');
          $('#manageStreamsModal').modal('show');
          refreshClassesAndStreams();
          window.location.reload();
        }
      }
    });
  });

  // Move Students
  $('#moveStudentForm').on('submit', function (e) {
    e.preventDefault();
    ajaxDebug({
      url: 'classes/functions.php',
      method: 'POST',
      data: $(this).serialize() + '&action=move_students',
      onSuccess: function (json) {
        alert(json.message);
        if (json.status === 'success') {
          $('#moveStudentModal').modal('hide');
          window.location.reload();
        }
      }
    });
  });

  // Fetch teachers for dropdowns
function updateTeacherOptions(teacherSelect) {
    console.log('🔄 Updating Teacher Options for:', teacherSelect.attr('id'));
    $.ajax({
        url: 'classes/functions.php',
        method: 'POST',
        data: { action: 'get_teachers' },
        success: function (response) {
            console.log('🔍 Raw Get Teachers Response:', response);
            console.log('🔍 Response Type:', typeof response);
            try {
                const json = typeof response === 'string' ? JSON.parse(response) : response;
                console.log('✅ Parsed JSON:', json);
                if (json.status === 'success') {
                    teacherSelect.empty().append('<option value="">Select Teacher</option>');
                    json.teachers.forEach(function (teacher) {
                        teacherSelect.append(
                            `<option value="${teacher.user_id}">${teacher.full_name}</option>`
                        );
                    });
                    console.log(`✅ Loaded ${json.teachers.length} teachers`);
                } else {
                    console.error('❌ Failed to load teachers:', json.message);
                    alert(json.message);
                }
            } catch (e) {
                console.error('❌ JSON parse error:', e);
                console.log('🔴 Raw Response:', response);
                alert('Failed to load teachers. Raw response: ' + response);
            }
        },
        error: function (xhr, status, error) {
            console.error('❌ AJAX Error for get_teachers:', status, error);
            console.log('🔴 Full Response:', xhr.responseText);
            alert('Failed to load teachers. Raw response: ' + xhr.responseText);
        }
    });
}

  // Update teacher dropdowns when modals are shown
  $('#assignClassSupervisorModal').on('show.bs.modal', function () {
    updateTeacherOptions($('#supervisorTeacherSelect'));
  });

  $('#assignClassTeacherModal').on('show.bs.modal', function () {
    updateTeacherOptions($('#classTeacherSelect'));
    const classId = $('#teacherClassSelect').val();
    updateStreamOptions(classId, $('#teacherStreamSelect'), 'teacherStreamSelect');
  });

  // Update streams when class changes in Assign Class Teacher modal
  $('#teacherClassSelect').on('change', function () {
    const classId = $(this).val();
    console.log('🎯 Teacher Class Selected:', classId);
    updateStreamOptions(classId, $('#teacherStreamSelect'), 'teacherStreamSelect');
  });

  // Assign Class Supervisor
 $('#assignClassSupervisorForm').on('submit', function (e) {
    e.preventDefault();
    ajaxDebug({
        url: 'classes/functions.php',
        method: 'POST',
        data: $(this).serialize() + '&action=assign_class_supervisor',
        onSuccess: function (json) {
            alert(json.message);
            if (json.status === 'success') {
                $('#assignClassSupervisorModal').modal('hide');
                window.location.reload(); // Refresh the entire page
            }
        }
    });
});

  // Assign Class Teacher
$('#assignClassTeacherForm').on('submit', function (e) {
    e.preventDefault();
    ajaxDebug({
        url: 'classes/functions.php',
        method: 'POST',
        data: $(this).serialize() + '&action=assign_class_teacher',
        onSuccess: function (json) {
            alert(json.message);
            if (json.status === 'success') {
                $('#assignClassTeacherModal').modal('hide');
                window.location.reload(); // Refresh the entire page
            }
        }
    });
});

  
  // Initialize stream filter for class filter
  updateStreamOptions($('#classFilter').val(), $('#streamFilter'), 'streamFilter');
});
</script>

<?php include __DIR__ . '/../footer.php'; ?>