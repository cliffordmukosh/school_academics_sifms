<?php
// session_start();
include __DIR__ . '/../header.php'; 
include __DIR__ . '/../sidebar.php';
require __DIR__ . '/../../connection/db.php';
require __DIR__ . '/../../vendor/autoload.php'; // For PhpSpreadsheet

use PhpOffice\PhpSpreadsheet\IOFactory;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Check if user is logged in, has appropriate permissions, and belongs to the school
if (!isset($_SESSION['user_id']) || !isset($_SESSION['school_id']) || !isset($_SESSION['role_name'])) {
    header("Location: ../../login.php");
    exit;
}

// Fetch classes for this school
$stmt = $conn->prepare("SELECT class_id, form_name FROM classes WHERE school_id = ?");
$stmt->bind_param("i", $_SESSION['school_id']);
$stmt->execute();
$classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$totalClasses = count($classes);
$stmt->close();

// Fetch streams for this school
$stmt = $conn->prepare("SELECT stream_id, stream_name, class_id FROM streams WHERE school_id = ?");
$stmt->bind_param("i", $_SESSION['school_id']);
$stmt->execute();
$streams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$totalStreams = count($streams);
$stmt->close();

// Fetch students for this school
$stmt = $conn->prepare("
    SELECT s.student_id, s.full_name, s.admission_no, s.gender, c.form_name, st.stream_name
    FROM students s
    JOIN classes c ON s.class_id = c.class_id
    JOIN streams st ON s.stream_id = st.stream_id
    WHERE s.school_id = ? AND s.deleted_at IS NULL
");
$stmt->bind_param("i", $_SESSION['school_id']);
$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totalStudents = count($students);
?>
<div class="content">
  <div class="container-fluid">
<div class="container py-4">
  <!-- Title with dynamic count -->
  <h3 class="mb-4 d-flex align-items-center">
    <i class="bi bi-people me-2"></i> Student Management
    <span class="badge bg-primary ms-3 fs-6">Total: <?php echo $totalStudents; ?></span>
  </h3>

  <!-- Student Management Menu -->
  <div class="row g-4 mb-4">
    <!-- Streams Card -->
    <div class="col-md-4">
      <div class="card shadow-sm border-0 h-100 text-center">
        <div class="card-body d-flex flex-column justify-content-center">
          <i class="bi bi-diagram-2 display-5 text-info"></i>
          <h5 class="mt-3">Streams</h5>
          <p class="text-muted">Total Classes: <?php echo $totalClasses; ?><br>Total Streams: <?php echo $totalStreams; ?></p>
          <button class="btn btn-info text-white mt-auto" data-bs-toggle="modal" data-bs-target="#addStreamModal">
            <i class="bi bi-plus-circle me-2"></i> Create Stream
          </button>
        </div>
      </div>
    </div>

    <!-- Change Class -->
    <div class="col-md-4">
      <div class="card shadow-sm border-0 h-100 text-center">
        <div class="card-body d-flex flex-column justify-content-center">
          <i class="bi bi-arrow-repeat display-5 text-primary"></i>
          <h5 class="mt-3">Change Class</h5>
          <p class="text-muted">Move students between different classes.</p>
          <button class="btn btn-primary mt-auto" data-bs-toggle="modal" data-bs-target="#changeClassModal">
            <i class="bi bi-box-arrow-in-right me-2"></i> Manage
          </button>
        </div>
      </div>
    </div>

    <!-- Change Stream -->
    <div class="col-md-4">
      <div class="card shadow-sm border-0 h-100 text-center">
        <div class="card-body d-flex flex-column justify-content-center">
          <i class="bi bi-diagram-3 display-5 text-success"></i>
          <h5 class="mt-3">Change Stream</h5>
          <p class="text-muted">Assign or transfer students to streams.</p>
          <button class="btn btn-success mt-auto" data-bs-toggle="modal" data-bs-target="#changeStreamModal">
            <i class="bi bi-box-arrow-in-right me-2"></i> Manage
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Student List -->
  <div class="card shadow-sm border-0">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0 d-flex align-items-center">
          <i class="bi bi-list-ul me-2"></i> Student List
        </h5>
        <div>
          <button class="btn btn-sm btn-success me-2" data-bs-toggle="modal" data-bs-target="#addStudentModal">
            <i class="bi bi-person-plus me-1"></i> Add Student
          </button>
          <button class="btn btn-sm btn-info text-white" data-bs-toggle="modal" data-bs-target="#importExcelModal">
            <i class="bi bi-file-earmark-excel me-1"></i> Import from Excel
          </button>
        </div>
      </div>

      <!-- Search and Filter -->
      <div class="row mb-3">
        <div class="col-md-4">
          <input type="text" id="searchInput" class="form-control" placeholder="Search by name or admission number">
        </div>
        <div class="col-md-4">
          <select id="classFilter" class="form-select">
            <option value="">All Classes</option>
            <?php foreach ($classes as $class): ?>
              <option value="<?php echo htmlspecialchars($class['form_name']); ?>">
                <?php echo htmlspecialchars($class['form_name']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <select id="streamFilter" class="form-select">
            <option value="">All Streams</option>
            <?php foreach ($streams as $stream): ?>
              <option value="<?php echo htmlspecialchars($stream['stream_name']); ?>">
                <?php echo htmlspecialchars($stream['stream_name']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <table class="table table-striped table-hover align-middle" id="studentTable">
        <thead class="table-dark">
          <tr>
            <th>#</th>
            <th>Name</th>
            <th>Admission No</th>
            <th>Class</th>
            <th>Stream</th>
            <th>Gender</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($students as $index => $student): ?>
            <tr>
              <td><?php echo $index + 1; ?></td>
              <td><?php echo htmlspecialchars($student['full_name']); ?></td>
              <td><?php echo htmlspecialchars($student['admission_no']); ?></td>
              <td><?php echo htmlspecialchars($student['form_name']); ?></td>
              <td><?php echo htmlspecialchars($student['stream_name']); ?></td>
              <td><?php echo htmlspecialchars($student['gender']); ?></td>
              <td>
                <button class="btn btn-sm btn-primary me-1 change-class" data-student-id="<?php echo $student['student_id']; ?>" data-bs-toggle="modal" data-bs-target="#changeClassModal">
                  <i class="bi bi-arrow-repeat"></i>
                </button>
                <button class="btn btn-sm btn-success me-1 change-stream" data-student-id="<?php echo $student['student_id']; ?>" data-bs-toggle="modal" data-bs-target="#changeStreamModal">
                  <i class="bi bi-diagram-3"></i>
                </button>
                <button class="btn btn-sm btn-warning me-1 edit-student" data-student-id="<?php echo $student['student_id']; ?>" data-bs-toggle="modal" data-bs-target="#editStudentModal">
                    <i class="bi bi-pencil"></i>
                </button>
                <button class="btn btn-sm btn-danger delete-student" data-student-id="<?php echo $student['student_id']; ?>">
                  <i class="bi bi-trash"></i>
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<!-- Add Student Modal -->
<div class="modal fade" id="addStudentModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i> Add Student</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="addStudentForm">
                    <ul class="nav nav-tabs mb-3" id="studentTabs" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active" id="key-identity-tab" data-bs-toggle="tab" href="#key-identity" role="tab">Personal Information</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link disabled" id="educational-tab" data-bs-toggle="tab" href="#educational" role="tab">Educational Information</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link disabled" id="guardian-tab" data-bs-toggle="tab" href="#guardian" role="tab">Guardian Information</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link disabled" id="location-tab" data-bs-toggle="tab" href="#location" role="tab">Location Information</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link disabled" id="additional-tab" data-bs-toggle="tab" href="#additional" role="tab">Additional Information</a>
                        </li>
                    </ul>
                    <div class="tab-content">
                        <!-- Personal Information (Key Identity) -->
                        <div class="tab-pane fade show active" id="key-identity" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Admission Number <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="admission_no" placeholder="Enter admission number" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="full_name" placeholder="Enter student name" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Gender <span class="text-danger">*</span></label>
                                    <select class="form-select" name="gender" required>
                                        <option value="">Select Gender</option>
                                        <option value="Male">Male</option>
                                        <option value="Female">Female</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Date of Birth</label>
                                    <input type="date" class="form-control" name="dob">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Profile Picture</label>
                                    <input type="file" class="form-control" name="profile_picture" accept="image/*">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Class <span class="text-danger">*</span></label>
                                    <select class="form-select" name="class_id" id="classSelect" required>
                                        <option value="">Select Class</option>
                                        <?php foreach ($classes as $class): ?>
                                            <option value="<?php echo $class['class_id']; ?>">
                                                <?php echo htmlspecialchars($class['form_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Stream <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <select class="form-select" name="stream_id" id="streamSelect" required>
                                            <option value="">Select Stream</option>
                                        </select>
                                        <button type="button" class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#addStreamModal" id="addStreamFromStudent">
                                            <i class="bi bi-plus-circle"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Primary Phone</label>
                                    <input type="text" class="form-control" name="primary_phone" placeholder="Enter primary phone">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Secondary Phone</label>
                                    <input type="text" class="form-control" name="secondary_phone" placeholder="Enter secondary phone">
                                </div>
                            </div>
                            <button type="button" class="btn btn-success" id="saveKeyDetails">Save Key Details</button>
                            <button type="button" class="btn btn-primary" id="nextToEducational">Next</button>
                        </div>
                        <!-- Educational Information -->
                        <div class="tab-pane fade" id="educational" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Date of Admission</label>
                                    <input type="date" class="form-control" name="date_of_admission">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Enrollment Form</label>
                                    <input type="file" class="form-control" name="enrollment_form" accept=".pdf,.doc,.docx">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Entry Position</label>
                                    <input type="text" class="form-control" name="entry_position" placeholder="e.g., Top 10, 1st">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">KCPE Index</label>
                                    <input type="text" class="form-control" name="kcpe_index" placeholder="Enter KCPE index">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">KCPE Score</label>
                                    <input type="number" class="form-control" name="kcpe_score" placeholder="Enter KCPE score">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">KCPE Grade</label>
                                    <input type="text" class="form-control" name="kcpe_grade" placeholder="Enter KCPE grade">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">KCPE Year</label>
                                    <input type="number" class="form-control" name="kcpe_year" placeholder="Enter KCPE year">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Examination Index Number</label>
                                    <input type="text" class="form-control" name="index_number" placeholder="Enter index number">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Previous School</label>
                                    <input type="text" class="form-control" name="previous_school" placeholder="Enter previous school">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Primary School</label>
                                    <input type="text" class="form-control" name="primary_school" placeholder="Enter primary school">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">UPI</label>
                                    <input type="text" class="form-control" name="upi" placeholder="Enter UPI">
                                </div>
                            </div>
                            <button type="button" class="btn btn-primary" id="nextToGuardian">Next</button>
                        </div>
                        <!-- Guardian Information -->
                        <div class="tab-pane fade" id="guardian" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Guardian Name</label>
                                    <input type="text" class="form-control" name="guardian_name" placeholder="Enter guardian name">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Guardian Relation</label>
                                    <input type="text" class="form-control" name="guardian_relation" placeholder="e.g., Father, Mother">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Guardian Primary Phone</label>
                                    <input type="text" class="form-control" name="primary_phone_2" placeholder="Enter guardian primary phone">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Guardian Secondary Phone</label>
                                    <input type="text" class="form-control" name="secondary_phone_2" placeholder="Enter guardian secondary phone">
                                </div>
                            </div>
                            <button type="button" class="btn btn-primary" id="nextToLocation">Next</button>
                        </div>
                        <!-- Location Information -->
                        <div class="tab-pane fade" id="location" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Birth Certificate Number</label>
                                    <input type="text" class="form-control" name="birth_cert_number" placeholder="Enter birth certificate number">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Nationality</label>
                                    <input type="text" class="form-control" name="nationality" placeholder="Enter nationality">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Place of Birth</label>
                                    <input type="text" class="form-control" name="place_of_birth" placeholder="Enter place of birth">
                                </div>
                            </div>
                            <button type="button" class="btn btn-primary" id="nextToAdditional">Next</button>
                        </div>
                        <!-- Additional Information -->
                        <div class="tab-pane fade" id="additional" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">NHIF Number</label>
                                    <input type="text" class="form-control" name="nhif" placeholder="Enter NHIF number">
                                </div>
                                <div class="col-md-12 mb-3">
                                    <label class="form-label">General Comments</label>
                                    <textarea class="form-control" name="general_comments" placeholder="Enter general comments"></textarea>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-success" id="saveAllDetails">Save All</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

  <!-- Import Excel Modal -->
<!-- Import Excel Modal -->
<div class="modal fade" id="importExcelModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-file-earmark-excel me-2"></i> Import Students from Excel</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <form id="importExcelForm" enctype="multipart/form-data">
            <div class="mb-3">
              <label class="form-label">Upload Excel File (.xlsx)</label>
              <input type="file" name="excel_file" class="form-control" accept=".xlsx, .xls" required>
            </div>
            <div class="mb-3">
              <p><strong>Note:</strong> Ensure <code>class_name</code> (e.g., 1 or Form 1) and <code>stream_name</code> (e.g., East) match existing records in the database. The <code>stream_name</code> must correspond to the specified <code>class_name</code>.</p>
              <button type="button" class="btn btn-sm btn-outline-primary" id="downloadTemplate">
                <i class="bi bi-download me-1"></i> Download Excel Template
              </button>
            </div>
            <button type="submit" class="btn btn-info text-white">Upload & Import</button>
          </form>
        </div>
      </div>
    </div>
</div>

  <!-- Change Class Modal -->
  <div class="modal fade" id="changeClassModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-arrow-repeat me-2"></i> Change Class</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <form id="changeClassForm">
            <div class="mb-3">
              <label class="form-label">Select Student</label>
              <select class="form-select" name="student_id" id="studentClassSelect" required>
                <option value="">Select Student</option>
                <?php foreach ($students as $student): ?>
                  <option value="<?php echo $student['student_id']; ?>">
                    <?php echo htmlspecialchars($student['full_name'] . ' (' . $student['admission_no'] . ')'); ?>
                  </option>
                <?php endforeach; ?>
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
              <select class="form-select" name="stream_id" id="newStreamSelect" required>
                <option value="">Select Stream</option>
                <!-- Populated dynamically by JavaScript -->
              </select>
            </div>
            <button type="submit" class="btn btn-primary">Update</button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <!-- Change Stream Modal -->
  <div class="modal fade" id="changeStreamModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-diagram-3 me-2"></i> Change Stream</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <form id="changeStreamForm">
            <div class="mb-3">
              <label class="form-label">Select Student</label>
              <select class="form-select" name="student_id" id="studentStreamSelect" required>
                <option value="">Select Student</option>
                <?php foreach ($students as $student): ?>
                  <option value="<?php echo $student['student_id']; ?>">
                    <?php echo htmlspecialchars($student['full_name'] . ' (' . $student['admission_no'] . ')'); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">New Stream</label>
              <select class="form-select" name="stream_id" id="streamChangeSelect" required>
                <option value="">Select Stream</option>
                <?php foreach ($streams as $stream): ?>
                  <option value="<?php echo $stream['stream_id']; ?>">
                    <?php echo htmlspecialchars($stream['stream_name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#addStreamModal" id="addStreamButton">
              <i class="bi bi-plus-circle me-1"></i> Add New Stream
            </button>
            <button type="submit" class="btn btn-success mt-3">Update</button>
          </form>
        </div>
      </div>
    </div>
  </div>
  
<!-- Edit Student Modal -->
<div class="modal fade" id="editStudentModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i> Edit Student</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="editStudentForm" enctype="multipart/form-data">
                    <input type="hidden" name="student_id">
                    <ul class="nav nav-tabs mb-3" id="editStudentTabs" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active" id="edit-key-identity-tab" data-bs-toggle="tab" href="#edit-key-identity" role="tab">Personal Information</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" id="edit-educational-tab" data-bs-toggle="tab" href="#edit-educational" role="tab">Educational Information</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" id="edit-guardian-tab" data-bs-toggle="tab" href="#edit-guardian" role="tab">Guardian Information</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" id="edit-location-tab" data-bs-toggle="tab" href="#edit-location" role="tab">Location Information</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" id="edit-additional-tab" data-bs-toggle="tab" href="#edit-additional" role="tab">Additional Information</a>
                        </li>
                    </ul>
                    <div class="tab-content">
                        <!-- Personal Information (Key Identity) -->
                        <div class="tab-pane fade show active" id="edit-key-identity" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Admission Number <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="admission_no" required>
                                    <div class="invalid-feedback">This field is required.</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="full_name" required>
                                    <div class="invalid-feedback">This field is required.</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Gender <span class="text-danger">*</span></label>
                                    <select class="form-select" name="gender" required>
                                        <option value="">Select Gender</option>
                                        <option value="Male">Male</option>
                                        <option value="Female">Female</option>
                                    </select>
                                    <div class="invalid-feedback">This field is required.</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Date of Birth</label>
                                    <input type="date" class="form-control" name="dob">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Profile Picture</label>
                                    <input type="file" class="form-control" name="profile_picture" accept="image/*">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Class <span class="text-danger">*</span></label>
                                    <select class="form-select" name="class_id" id="editClassSelect" required>
                                        <option value="">Select Class</option>
                                        <?php foreach ($classes as $class): ?>
                                            <option value="<?php echo $class['class_id']; ?>">
                                                <?php echo htmlspecialchars($class['form_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="invalid-feedback">This field is required.</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Stream <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <select class="form-select" name="stream_id" id="editStreamSelect" required>
                                            <option value="">Select Stream</option>
                                        </select>
                                        <button type="button" class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#addStreamModal" id="editAddStreamFromStudent">
                                            <i class="bi bi-plus-circle"></i>
                                        </button>
                                    </div>
                                    <div class="invalid-feedback">This field is required.</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Primary Phone</label>
                                    <input type="text" class="form-control" name="primary_phone">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Secondary Phone</label>
                                    <input type="text" class="form-control" name="secondary_phone">
                                </div>
                            </div>
                            <button type="button" class="btn btn-primary" id="editNextToEducational">Next</button>
                        </div>
                        <!-- Educational Information -->
                        <div class="tab-pane fade" id="edit-educational" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Date of Admission</label>
                                    <input type="date" class="form-control" name="date_of_admission">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Enrollment Form</label>
                                    <input type="file" class="form-control" name="enrollment_form" accept=".pdf,.doc,.docx">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Entry Position</label>
                                    <input type="text" class="form-control" name="entry_position">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">KCPE Index</label>
                                    <input type="text" class="form-control" name="kcpe_index">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">KCPE Score</label>
                                    <input type="number" class="form-control" name="kcpe_score">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">KCPE Grade</label>
                                    <input type="text" class="form-control" name="kcpe_grade">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">KCPE Year</label>
                                    <input type="number" class="form-control" name="kcpe_year">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Examination Index Number</label>
                                    <input type="text" class="form-control" name="index_number">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Previous School</label>
                                    <input type="text" class="form-control" name="previous_school">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Primary School</label>
                                    <input type="text" class="form-control" name="primary_school">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">UPI</label>
                                    <input type="text" class="form-control" name="upi">
                                </div>
                            </div>
                            <button type="button" class="btn btn-primary" id="editNextToGuardian">Next</button>
                        </div>
                        <!-- Guardian Information -->
                        <div class="tab-pane fade" id="edit-guardian" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Guardian Name</label>
                                    <input type="text" class="form-control" name="guardian_name">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Guardian Relation</label>
                                    <input type="text" class="form-control" name="guardian_relation">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Guardian Primary Phone</label>
                                    <input type="text" class="form-control" name="primary_phone_2">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Guardian Secondary Phone</label>
                                    <input type="text" class="form-control" name="secondary_phone_2">
                                </div>
                            </div>
                            <button type="button" class="btn btn-primary" id="editNextToLocation">Next</button>
                        </div>
                        <!-- Location Information -->
                        <div class="tab-pane fade" id="edit-location" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Birth Certificate Number</label>
                                    <input type="text" class="form-control" name="birth_cert_number">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Nationality</label>
                                    <input type="text" class="form-control" name="nationality">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Place of Birth</label>
                                    <input type="text" class="form-control" name="place_of_birth">
                                </div>
                            </div>
                            <button type="button" class="btn btn-primary" id="editNextToAdditional">Next</button>
                        </div>
                        <!-- Additional Information -->
                        <div class="tab-pane fade" id="edit-additional" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">NHIF Number</label>
                                    <input type="text" class="form-control" name="nhif">
                                </div>
                                <div class="col-md-12 mb-3">
                                    <label class="form-label">General Comments</label>
                                    <textarea class="form-control" name="general_comments"></textarea>
                                </div>
                            </div>
                            <button type="button" class="btn btn-success" id="saveEditDetails">Save Changes</button>
                        </div>
                    </div>
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
          <h5 class="modal-title"><i class="bi bi-diagram-3 me-2"></i> Create Stream</h5>
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
              <input type="text" class="form-control" name="stream_name" placeholder="Enter stream name" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Description (Optional)</label>
              <textarea class="form-control" name="description" placeholder="Enter stream description"></textarea>
            </div>
            <button type="submit" class="btn btn-success">Create Stream</button>
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
    const streams = <?php echo json_encode($streams); ?>;
    console.log('🔍 Initial Streams Data:', streams);

    // Function to filter streams based on class_id
    function updateStreamOptions(classId, streamSelect) {
        console.log('🔄 Updating Stream Options for class_id:', classId);
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
        console.log(`✅ Found ${streamCount} streams for class_id ${classId}`);
        if (streamCount === 0) {
            console.warn('⚠️ No streams found for class_id:', classId);
        }
    }

    // Validate Key Identity fields
    function validateKeyIdentity() {
        const requiredFields = ['admission_no', 'full_name', 'gender', 'class_id', 'stream_id'];
        let isValid = true;
        requiredFields.forEach(function (field) {
            const value = $(`#addStudentForm [name="${field}"]`).val();
            if (!value) {
                isValid = false;
                $(`#addStudentForm [name="${field}"]`).addClass('is-invalid');
            } else {
                $(`#addStudentForm [name="${field}"]`).removeClass('is-invalid');
            }
        });
        return isValid;
    }

    // Enable/disable tabs based on validation
    function updateTabAccess() {
        const isValid = validateKeyIdentity();
        $('#educational-tab, #guardian-tab, #location-tab, #additional-tab').toggleClass('disabled', !isValid);
        if (!isValid) {
            $('#nextToEducational').prop('disabled', true);
        } else {
            $('#nextToEducational').prop('disabled', false);
        }
    }

    // Update streams when class changes
    $('#classSelect').on('change', function () {
        const classId = $(this).val();
        console.log('🎯 Class Selected in Add Student:', classId);
        updateStreamOptions(classId, $('#streamSelect'));
        updateTabAccess();
    });

    // Validate Key Identity on input change
    $('#addStudentForm input, #addStudentForm select').on('change input', function () {
        updateTabAccess();
    });

    // Next button handlers
    $('#nextToEducational').on('click', function () {
        if (validateKeyIdentity()) {
            $('#educational-tab').tab('show');
        } else {
            alert('Please fill all required fields in Personal Information.');
        }
    });
    $('#nextToGuardian').on('click', function () {
        $('#guardian-tab').tab('show');
    });
    $('#nextToLocation').on('click', function () {
        $('#location-tab').tab('show');
    });
    $('#nextToAdditional').on('click', function () {
        $('#additional-tab').tab('show');
    });

    // AJAX Helper
    

    // Save Key Details
    $('#saveKeyDetails').on('click', function () {
        if (!validateKeyIdentity()) {
            alert('Please fill all required fields in Personal Information.');
            return;
        }
        const formData = new FormData();
        formData.append('action', 'add_student_key_details');
        formData.append('admission_no', $('#addStudentForm [name="admission_no"]').val());
        formData.append('full_name', $('#addStudentForm [name="full_name"]').val());
        formData.append('gender', $('#addStudentForm [name="gender"]').val());
        formData.append('class_id', $('#addStudentForm [name="class_id"]').val());
        formData.append('stream_id', $('#addStudentForm [name="stream_id"]').val());
        formData.append('dob', $('#addStudentForm [name="dob"]').val());
        formData.append('primary_phone', $('#addStudentForm [name="primary_phone"]').val());
        formData.append('secondary_phone', $('#addStudentForm [name="secondary_phone"]').val());
        const profilePicture = $('#addStudentForm [name="profile_picture"]')[0].files[0];
        if (profilePicture) {
            formData.append('profile_picture', profilePicture);
        }

        ajaxDebug({
            url: 'students/functions.php',
            method: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            onSuccess: function (json) {
                alert(json.message);
                if (json.status === 'success') {
                    $('#addStudentModal').modal('hide');
                    window.location.reload();
                }
            }
        });
    });

// Edit Student
$('.edit-student').on('click', function () {
    const studentId = $(this).data('student-id');
    console.log('✏️ Editing Student:', studentId);
    ajaxDebug({
        url: 'students/functions.php',
        method: 'POST',
        data: { action: 'get_student', student_id: studentId },
        onSuccess: function (json) {
            if (json.status === 'success') {
                const student = json.student;
                $('#editStudentForm [name="student_id"]').val(student.student_id);
                $('#editStudentForm [name="admission_no"]').val(student.admission_no);
                $('#editStudentForm [name="full_name"]').val(student.full_name);
                $('#editStudentForm [name="gender"]').val(student.gender);
                $('#editStudentForm [name="dob"]').val(student.dob);
                $('#editStudentForm [name="class_id"]').val(student.class_id).trigger('change');
                $('#editStudentForm [name="stream_id"]').val(student.stream_id);
                $('#editStudentForm [name="primary_phone"]').val(student.primary_phone);
                $('#editStudentForm [name="secondary_phone"]').val(student.secondary_phone);
                $('#editStudentForm [name="date_of_admission"]').val(student.date_of_admission);
                $('#editStudentForm [name="entry_position"]').val(student.entry_position);
                $('#editStudentForm [name="kcpe_index"]').val(student.kcpe_index);
                $('#editStudentForm [name="kcpe_score"]').val(student.kcpe_score);
                $('#editStudentForm [name="kcpe_grade"]').val(student.kcpe_grade);
                $('#editStudentForm [name="kcpe_year"]').val(student.kcpe_year);
                $('#editStudentForm [name="index_number"]').val(student.index_number);
                $('#editStudentForm [name="previous_school"]').val(student.previous_school);
                $('#editStudentForm [name="primary_school"]').val(student.primary_school);
                $('#editStudentForm [name="upi"]').val(student.upi);
                $('#editStudentForm [name="guardian_name"]').val(student.guardian_name);
                $('#editStudentForm [name="guardian_relation"]').val(student.guardian_relation);
                $('#editStudentForm [name="primary_phone_2"]').val(student.primary_phone_2);
                $('#editStudentForm [name="secondary_phone_2"]').val(student.secondary_phone_2);
                $('#editStudentForm [name="birth_cert_number"]').val(student.birth_cert_number);
                $('#editStudentForm [name="nationality"]').val(student.nationality);
                $('#editStudentForm [name="place_of_birth"]').val(student.place_of_birth);
                $('#editStudentForm [name="nhif"]').val(student.nhif);
                $('#editStudentForm [name="general_comments"]').val(student.general_comments);
                // Enable all tabs for editing
                $('#edit-educational-tab, #edit-guardian-tab, #edit-location-tab, #edit-additional-tab').removeClass('disabled');
            } else {
                alert(json.message);
            }
        }
    });
});

// Update streams for Edit modal
$('#editClassSelect').on('change', function () {
    const classId = $(this).val();
    console.log('🎯 Class Selected in Edit Student:', classId);
    updateStreamOptions(classId, $('#editStreamSelect'));
});

// Next button handlers for Edit modal
$('#editNextToEducational').on('click', function () {
    if (validateKeyIdentity('#editStudentForm')) {
        $('#edit-educational-tab').tab('show');
    } else {
        alert('Please fill all required fields in Personal Information.');
    }
});
$('#editNextToGuardian').on('click', function () {
    $('#edit-guardian-tab').tab('show');
});
$('#editNextToLocation').on('click', function () {
    $('#edit-location-tab').tab('show');
});
$('#editNextToAdditional').on('click', function () {
    $('#edit-additional-tab').tab('show');
});

// Save Edited Details
$('#saveEditDetails').on('click', function (e) {
    e.preventDefault();
    if (!validateKeyIdentity('#editStudentForm')) {
        alert('Please fill all required fields in Personal Information.');
        $('#edit-key-identity-tab').tab('show');
        return;
    }
    const kcpeScore = $('#editStudentForm [name="kcpe_score"]').val();
    const kcpeYear = $('#editStudentForm [name="kcpe_year"]').val();
    if (kcpeScore && (kcpeScore < 0 || kcpeScore > 500)) {
        alert('KCPE score must be between 0 and 500');
        $('#edit-educational-tab').tab('show');
        return;
    }
    if (kcpeYear && (kcpeYear < 1900 || kcpeYear > new Date().getFullYear())) {
        alert('KCPE year must be a valid year');
        $('#edit-educational-tab').tab('show');
        return;
    }
    const formData = new FormData($('#editStudentForm')[0]);
    formData.append('action', 'edit_student');
    const profilePicture = $('#editStudentForm [name="profile_picture"]')[0].files[0];
    if (profilePicture) {
        formData.append('profile_picture', profilePicture);
    }
    const enrollmentForm = $('#editStudentForm [name="enrollment_form"]')[0].files[0];
    if (enrollmentForm) {
        formData.append('enrollment_form', enrollmentForm);
    }

    ajaxDebug({
        url: 'students/functions.php',
        method: 'POST',
        data: formData,
        contentType: false,
        processData: false,
        onSuccess: function (json) {
            alert(json.message);
            if (json.status === 'success') {
                $('#editStudentModal').modal('hide');
                window.location.reload();
            }
        }
    });
});

// Update validateKeyIdentity to accept form ID
function validateKeyIdentity(formId = '#addStudentForm') {
    const requiredFields = ['admission_no', 'full_name', 'gender', 'class_id', 'stream_id'];
    let isValid = true;
    requiredFields.forEach(function (field) {
        const value = $(`${formId} [name="${field}"]`).val();
        if (!value) {
            isValid = false;
            $(`${formId} [name="${field}"]`).addClass('is-invalid');
        } else {
            $(`${formId} [name="${field}"]`).removeClass('is-invalid');
        }
    });
    return isValid;
}
    // Save All Details
$('#saveAllDetails').on('click', function (e) {
    e.preventDefault(); // Prevent default form submission
    if (!validateKeyIdentity()) {
        alert('Please fill all required fields in Personal Information.');
        $('#key-identity-tab').tab('show');
        return;
    }
    const kcpeScore = $('#addStudentForm [name="kcpe_score"]').val();
    const kcpeYear = $('#addStudentForm [name="kcpe_year"]').val();
    if (kcpeScore && (kcpeScore < 0 || kcpeScore > 500)) {
        alert('KCPE score must be between 0 and 500');
        $('#educational-tab').tab('show');
        return;
    }
    if (kcpeYear && (kcpeYear < 1900 || kcpeYear > new Date().getFullYear())) {
        alert('KCPE year must be a valid year');
        $('#educational-tab').tab('show');
        return;
    }
    const formData = new FormData($('#addStudentForm')[0]);
    formData.append('action', 'add_student');
    const profilePicture = $('#addStudentForm [name="profile_picture"]')[0].files[0];
    if (profilePicture) {
        formData.append('profile_picture', profilePicture);
    }
    const enrollmentForm = $('#addStudentForm [name="enrollment_form"]')[0].files[0];
    if (enrollmentForm) {
        formData.append('enrollment_form', enrollmentForm);
    }

    ajaxDebug({
        url: 'students/functions.php',
        method: 'POST',
        data: formData,
        contentType: false,
        processData: false,
        onSuccess: function (json) {
            alert(json.message);
            if (json.status === 'success') {
                $('#addStudentModal').modal('hide');
                window.location.reload();
            }
        }
    });
});

    // Existing JavaScript for other functionality (e.g., Change Class, Change Stream, Delete Student)
    // Update streams when class changes in Change Class modal
    $('#newClassSelect').on('change', function () {
        const classId = $(this).val();
        console.log('🎯 Class Selected in Change Class:', classId);
        updateStreamOptions(classId, $('#newStreamSelect'));
    });

    // Pre-select student in Change Class/Stream modals
    $('.change-class, .change-stream').on('click', function () {
        const studentId = $(this).data('student-id');
        console.log('👤 Student Selected for Change:', studentId);
        $('#studentClassSelect, #studentStreamSelect').val(studentId);
    });

    // Pre-select class in Add Stream modal when opened from Change Stream
    $('#addStreamButton').on('click', function () {
        const studentId = $('#studentStreamSelect').val();
        console.log('➕ Opening Add Stream from Change Stream, student_id:', studentId);
        if (studentId) {
            ajaxDebug({
                url: 'students/functions.php',
                method: 'POST',
                data: { action: 'get_student_class', student_id: studentId },
                onSuccess: function (json) {
                    if (json.status === 'success') {
                        $('#streamClassSelect').val(json.class_id);
                        console.log('✅ Set streamClassSelect to:', json.class_id);
                    } else {
                        console.error('❌ Failed to get student class:', json.message);
                    }
                }
            });
        }
    });

    // Pre-select class in Add Stream modal when opened from Add Student
    $('#addStreamFromStudent').on('click', function () {
        const classId = $('#classSelect').val();
        console.log('➕ Opening Add Stream from Add Student, class_id:', classId);
        if (classId) {
            $('#streamClassSelect').val(classId);
            console.log('✅ Set streamClassSelect to:', classId);
        } else {
            console.warn('⚠️ No class selected in Add Student modal');
        }
    });

    // Search and Filter Table
    $('#searchInput, #classFilter, #streamFilter').on('input change', function () {
        const searchText = $('#searchInput').val().toLowerCase();
        const classFilter = $('#classFilter').val();
        const streamFilter = $('#streamFilter').val();

        $('#studentTable tbody tr').each(function () {
            const name = $(this).find('td:eq(1)').text().toLowerCase();
            const admissionNo = $(this).find('td:eq(2)').text().toLowerCase();
            const className = $(this).find('td:eq(3)').text();
            const streamName = $(this).find('td:eq(4)').text();

            const matchesSearch = searchText === '' || name.includes(searchText) || admissionNo.includes(searchText);
            const matchesClass = classFilter === '' || className === classFilter;
            const matchesStream = streamFilter === '' || streamName === streamFilter;

            $(this).toggle(matchesSearch && matchesClass && matchesStream);
        });
    });

    // Existing handlers for Change Class, Change Stream, Add Stream, Delete Student
    $('#changeClassForm').on('submit', function (e) {
        e.preventDefault();
        ajaxDebug({
            url: 'students/functions.php',
            method: 'POST',
            data: $(this).serialize() + '&action=change_class',
            onSuccess: function (json) {
                alert(json.message);
                if (json.status === 'success') {
                    $('#changeClassModal').modal('hide');
                    window.location.reload();
                }
            }
        });
    });

    $('#changeStreamForm').on('submit', function (e) {
        e.preventDefault();
        ajaxDebug({
            url: 'students/functions.php',
            method: 'POST',
            data: $(this).serialize() + '&action=change_stream',
            onSuccess: function (json) {
                alert(json.message);
                if (json.status === 'success') {
                    $('#changeStreamModal').modal('hide');
                    window.location.reload();
                }
            }
        });
    });

    $('#addStreamForm').on('submit', function (e) {
        e.preventDefault();
        ajaxDebug({
            url: 'students/functions.php',
            method: 'POST',
            data: $(this).serialize() + '&action=add_stream',
            onSuccess: function (json) {
                alert(json.message);
                if (json.status === 'success') {
                    $('#addStreamModal').modal('hide');
                    $('#addStudentModal').modal('show');
                    window.location.reload();
                }
            }
        });
    });

    $('.delete-student').on('click', function () {
        if (!confirm('Delete this student?')) return;
        const studentId = $(this).data('student-id');
        ajaxDebug({
            url: 'students/functions.php',
            method: 'POST',
            data: { action: 'delete_student', student_id: studentId },
            onSuccess: function (json) {
                alert(json.message);
                if (json.status === 'success') {
                    window.location.reload();
                }
            }
        });
    });
});

$('#importExcelForm').on('submit', function (e) {
    e.preventDefault();
    const formData = new FormData(this);
    formData.append('action', 'import_students_excel');
    ajaxDebug({
        url: 'students/functions.php',
        method: 'POST',
        data: formData,
        contentType: false,
        processData: false,
        onSuccess: function (json) {
            alert(json.message);
            if (json.status === 'success') {
                $('#importExcelModal').modal('hide');
                window.location.reload();
            }
        }
    });
});

// Fetch Excel format description dynamically
$('#importExcelModal').on('show.bs.modal', function () {
    ajaxDebug({
        url: 'students/functions.php',
        method: 'POST',
        data: { action: 'get_excel_format' },
        onSuccess: function (json) {
            if (json.status === 'success') {
                let formatHtml = '<ul>';
                json.fields.forEach(function (field) {
                    formatHtml += `<li><strong>${field.name}</strong>: ${field.description} (${field.required ? 'required' : 'optional'})</li>`;
                });
                formatHtml += '</ul>';
                $('#excelFormatDescription').html(formatHtml);
            } else {
                $('#excelFormatDescription').html('<p class="text-danger">Failed to load format: ' + json.message + '</p>');
            }
        }
    });
});

// Handle Excel template download
$('#downloadTemplate').on('click', function () {
    window.location.href = 'students/functions.php?action=download_excel_template';
});

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
</script>

<?php include __DIR__ . '/../footer.php'; ?>