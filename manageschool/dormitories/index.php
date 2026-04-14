<?php
// dormitories/index.php
include __DIR__ . '/../header.php';
include __DIR__ . '/../sidebar.php';
require __DIR__ . '/../../connection/db.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['school_id'])) {
    header("Location: ../../login.php");
    exit;
}

$school_id = $_SESSION['school_id'];
$current_year = date('Y');

// Fetch all dormitories for this school
$stmt = $conn->prepare("
    SELECT 
        dormitory_id, 
        name, 
        short_code,
        gender,
        capacity,
        description, 
        (SELECT COUNT(*) FROM student_dormitory_assignments sda 
         WHERE sda.dormitory_id = d.dormitory_id 
           AND sda.is_current = 1 
           AND sda.academic_year = ?) AS student_count
    FROM dormitories d
    WHERE d.school_id = ?
    ORDER BY name ASC
");
$stmt->bind_param("ii", $current_year, $school_id);
$stmt->execute();
$dormitories = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$totalDormitories = count($dormitories);
$stmt->close();

// Fetch students without current dormitory this year
$stmt = $conn->prepare("
    SELECT 
        s.student_id, 
        s.full_name, 
        s.admission_no, 
        s.gender,
        c.form_name, 
        st.stream_name
    FROM students s
    LEFT JOIN student_dormitory_assignments sda 
        ON s.student_id = sda.student_id 
       AND sda.is_current = 1 
       AND sda.academic_year = ?
    JOIN classes c ON s.class_id = c.class_id
    JOIN streams st ON s.stream_id = st.stream_id
    WHERE s.school_id = ? 
      AND sda.student_id IS NULL 
      AND s.deleted_at IS NULL
    ORDER BY s.admission_no ASC
    LIMIT 100
");
$stmt->bind_param("ii", $current_year, $school_id);
$stmt->execute();
$unassigned = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<div class="content">
    <div class="container-fluid">
        <div class="container py-4">
            <h3 class="mb-4 d-flex align-items-center">
                <i class="bi bi-building-fill-gear me-2" style="color:#6f42c1;"></i>
                Dormitory Management
                <span class="badge bg-primary text-white ms-3 fs-6">Total Dormitories: <?= $totalDormitories ?></span>
            </h3>

            <!-- Quick Stats Cards -->
            <div class="row g-4 mb-4">
                <div class="col-md-4">
                    <div class="card shadow-sm border-0 text-center">
                        <div class="card-body">
                            <i class="bi bi-building-add display-5 text-primary"></i>
                            <h5 class="mt-3">Create New Dormitory</h5>
                            <button class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#addDormitoryModal">
                                <i class="bi bi-plus-circle me-1"></i> Add Dormitory
                            </button>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card shadow-sm border-0 text-center">
                        <div class="card-body">
                            <i class="bi bi-people-fill display-5 text-success"></i>
                            <h5 class="mt-3">Assign Students</h5>
                            <button class="btn btn-success mt-2" data-bs-toggle="modal" data-bs-target="#assignDormitoryModal">
                                <i class="bi bi-person-plus me-1"></i> Assign
                            </button>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card shadow-sm border-0 text-center h-100">
                        <div class="card-body d-flex flex-column justify-content-center">
                            <i class="bi bi-list-ul display-5 text-info mb-2"></i>
                            <h5 class="mt-2 mb-3">Unassigned Students</h5>

                            <?php if (count($unassigned) > 0): ?>
                                <div class="row g-2 align-items-center justify-content-center mb-2">
                                    <div class="col-auto">
                                        <span class="badge bg-info fs-5 px-4 py-2">
                                            <?= count($unassigned) ?>
                                        </span>
                                    </div>
                                    <div class="col-auto">
                                        <button class="btn btn-outline-info"
                                            data-bs-toggle="modal"
                                            data-bs-target="#viewUnassignedModal">
                                            <i class="bi bi-eye me-1"></i> View
                                        </button>
                                    </div>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-0">No unassigned students this year</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Dormitories Table -->
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <h5 class="mb-3 d-flex align-items-center">
                        <i class="bi bi-building me-2"></i> All Dormitories
                    </h5>

                    <table class="table table-striped table-hover align-middle" id="dormitoriesTable">
                        <thead class="table-dark">
                            <tr>
                                <th>#</th>
                                <th>Dormitory Name</th>
                                <th>Code</th>
                                <th>Gender</th>
                                <th>Capacity</th>
                                <th>Students</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dormitories as $index => $dorm): ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td><?= htmlspecialchars($dorm['name']) ?></td>
                                    <td><?= htmlspecialchars($dorm['short_code'] ?: '-') ?></td>
                                    <td><?= htmlspecialchars($dorm['gender'] ?: 'Mixed') ?></td>
                                    <td><?= $dorm['capacity'] ?: '-' ?></td>
                                    <td>
                                        <span class="badge bg-info">
                                            <?= $dorm['student_count'] ?> students
                                        </span>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-primary me-1 view-dorm-students"
                                            data-dorm-id="<?= $dorm['dormitory_id'] ?>"
                                            data-dorm-name="<?= htmlspecialchars($dorm['name']) ?>">
                                            <i class="bi bi-eye"></i> View
                                        </button>
                                        <button class="btn btn-sm btn-warning me-1 edit-dorm"
                                            data-dorm-id="<?= $dorm['dormitory_id'] ?>"
                                            data-name="<?= htmlspecialchars($dorm['name']) ?>"
                                            data-code="<?= htmlspecialchars($dorm['short_code'] ?: '') ?>"
                                            data-gender="<?= htmlspecialchars($dorm['gender'] ?: 'Mixed') ?>"
                                            data-capacity="<?= $dorm['capacity'] ?: '' ?>"
                                            data-desc="<?= htmlspecialchars($dorm['description'] ?: '') ?>">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="btn btn-sm btn-danger delete-dorm"
                                            data-dorm-id="<?= $dorm['dormitory_id'] ?>">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Add Dormitory Modal -->
            <div class="modal fade" id="addDormitoryModal" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-building-add me-2"></i> Add New Dormitory</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <form id="addDormitoryForm">
                                <div class="mb-3">
                                    <label class="form-label">Dormitory Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="name" required placeholder="e.g. Amani House">
                                </div>
                                <!-- <div class="mb-3">
                                    <label class="form-label">Short Code (optional)</label>
                                    <input type="text" class="form-control" name="short_code" placeholder="e.g. AMN">
                                </div> -->
                                <div class="mb-3">
                                    <label class="form-label">Gender</label>
                                    <select class="form-select" name="gender">
                                        <!-- <option value="Mixed">Mixed</option>
                                        <option value="Boys">Boys</option> -->
                                        <option value="Girls">Girls</option>
                                    </select>
                                </div>
                                <!-- <div class="mb-3">
                                    <label class="form-label">Capacity (optional)</label>
                                    <input type="number" class="form-control" name="capacity" min="0" placeholder="e.g. 120">
                                </div> -->
                                <div class="mb-3">
                                    <label class="form-label">Description (optional)</label>
                                    <textarea class="form-control" name="description" rows="3" placeholder="e.g. New girls dormitory near dining hall"></textarea>
                                </div>
                                <button type="submit" class="btn btn-primary">Create Dormitory</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Edit Dormitory Modal -->
            <div class="modal fade" id="editDormitoryModal" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-pencil me-2"></i> Edit Dormitory</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <form id="editDormitoryForm">
                                <input type="hidden" name="dormitory_id">
                                <div class="mb-3">
                                    <label class="form-label">Dormitory Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="name" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Short Code (optional)</label>
                                    <input type="text" class="form-control" name="short_code">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Gender</label>
                                    <select class="form-select" name="gender">
                                        <option value="Mixed">Mixed</option>
                                        <option value="Boys">Boys</option>
                                        <option value="Girls">Girls</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Capacity (optional)</label>
                                    <input type="number" class="form-control" name="capacity" min="0">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Description (optional)</label>
                                    <textarea class="form-control" name="description" rows="3"></textarea>
                                </div>
                                <button type="submit" class="btn btn-primary">Update Dormitory</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Assign Dormitory Modal -->
            <div class="modal fade" id="assignDormitoryModal" tabindex="-1">
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i> Assign Students to Dormitory</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">

                            <ul class="nav nav-tabs mb-4" id="assignTabs" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="manual-tab" data-bs-toggle="tab" data-bs-target="#manual" type="button" role="tab">
                                        <i class="bi bi-check2-square me-1"></i> Manual Selection
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="excel-tab" data-bs-toggle="tab" data-bs-target="#excel" type="button" role="tab">
                                        <i class="bi bi-file-earmark-spreadsheet me-1"></i> Excel Upload
                                    </button>
                                </li>
                            </ul>

                            <div class="tab-content" id="assignTabContent">
                                <!-- Manual Tab -->
                                <div class="tab-pane fade show active" id="manual" role="tabpanel">
                                    <form id="assignDormitoryFormManual">
                                        <div class="row g-3 mb-3">
                                            <div class="col-md-6">
                                                <label class="form-label">Select Dormitory <span class="text-danger">*</span></label>
                                                <select class="form-select" name="dormitory_id" required>
                                                    <option value="">-- Choose Dormitory --</option>
                                                    <?php foreach ($dormitories as $d): ?>
                                                        <option value="<?= $d['dormitory_id'] ?>">
                                                            <?= htmlspecialchars($d['name']) ?> (<?= $d['student_count'] ?> students)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Search students (name / admission no)</label>
                                                <input type="text" id="studentSearchAssign" class="form-control" placeholder="Type to search...">
                                            </div>
                                        </div>

                                        <div class="border p-3 rounded" style="max-height: 320px; overflow-y: auto;">
                                            <div id="assignStudentsList" class="row g-2"></div>
                                        </div>

                                        <div class="d-flex justify-content-end mt-4">
                                            <button type="submit" class="btn btn-success px-4">
                                                <i class="bi bi-check-circle me-1"></i> Assign Selected
                                            </button>
                                        </div>
                                    </form>
                                </div>

                                <!-- Excel Tab -->
                                <div class="tab-pane fade" id="excel" role="tabpanel">
                                    <form id="assignDormitoryFormExcel" enctype="multipart/form-data">
                                        <div class="row g-3 mb-4">
                                            <div class="col-md-6">
                                                <label class="form-label">Select Dormitory <span class="text-danger">*</span></label>
                                                <select class="form-select" name="dormitory_id" id="excelDormSelect" required>
                                                    <option value="">-- Choose Dormitory --</option>
                                                    <?php foreach ($dormitories as $d): ?>
                                                        <option value="<?= $d['dormitory_id'] ?>">
                                                            <?= htmlspecialchars($d['name']) ?> (<?= $d['student_count'] ?> students)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Upload Excel File (.xlsx or .xls)</label>
                                                <input type="file" class="form-control" name="excel_file" id="excelFileInput" accept=".xlsx,.xls" required>
                                                <small class="text-muted mt-1 d-block">
                                                    Required columns: <strong>Name</strong> (A) and <strong>Admission Number</strong> or <strong>Admission</strong> (B)
                                                </small>
                                            </div>
                                        </div>

                                        <div class="alert alert-info small mb-4">
                                            <i class="bi bi-info-circle me-1"></i>
                                            Download <a href="dormitories/functions.php?action=download_dorm_template" class="alert-link" target="_blank">this template</a>
                                        </div>

                                        <div id="excelPreviewContainer" class="d-none mt-4">
                                            <h6 class="mb-3">Preview of Uploaded Students</h6>
                                            <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                                                <table class="table table-bordered table-sm" id="excelPreviewTable">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th>#</th>
                                                            <th>Name</th>
                                                            <th>Admission Number</th>
                                                            <th>Status</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody></tbody>
                                                </table>
                                            </div>
                                            <div class="mt-3">
                                                <small id="previewSummary" class="text-muted"></small>
                                            </div>
                                        </div>

                                        <div class="d-flex justify-content-end mt-4">
                                            <button type="button" class="btn btn-secondary me-2" id="clearPreviewBtn" style="display:none;">
                                                Clear Preview
                                            </button>
                                            <button type="submit" class="btn btn-primary px-4" id="confirmAssignExcelBtn" disabled>
                                                <i class="bi bi-check-circle me-1"></i> Confirm & Assign
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- View Dormitory Students Modal -->
            <div class="modal fade" id="viewDormStudentsModal" tabindex="-1">
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="bi bi-building-fill me-2"></i> Students in: <span id="viewDormName"></span>
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="border p-3 rounded bg-light" style="max-height: 400px; overflow-y: auto;">
                                <ul class="list-group list-group-flush" id="dormStudentsList"></ul>
                            </div>
                            <div id="noStudentsInDorm" class="text-center text-muted py-5 d-none">
                                <i class="bi bi-person-x display-4"></i>
                                <p class="mt-3">No students assigned to this dormitory yet.</p>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- View Unassigned Students Modal -->
<div class="modal fade" id="viewUnassignedModal" tabindex="-1" aria-labelledby="unassignedModalLabel">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="unassignedModalLabel">
                    <i class="bi bi-people me-2"></i> Unassigned Students (<?= count($unassigned) ?>)
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" id="unassignedSearchInput" class="form-control" placeholder="Search name or admission no...">
                    </div>
                </div>

                <div class="table-responsive" style="max-height: 500px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 6px;">
                    <table class="table table-hover table-bordered table-sm mb-0" id="unassignedStudentsTable">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th>#</th>
                                <th>Full Name</th>
                                <th>Admission No</th>
                                <th>Gender</th>
                                <th>Class / Stream</th>
                                <th style="width: 180px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($unassigned)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">
                                        No unassigned students found.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($unassigned as $index => $student): ?>
                                    <tr class="unassigned-row"
                                        data-name="<?= htmlspecialchars(strtolower($student['full_name'])) ?>"
                                        data-adm="<?= htmlspecialchars(strtolower($student['admission_no'])) ?>">
                                        <td><?= $index + 1 ?></td>
                                        <td><?= htmlspecialchars($student['full_name']) ?></td>
                                        <td><?= htmlspecialchars($student['admission_no']) ?></td>
                                        <td><?= htmlspecialchars($student['gender'] ?: '-') ?></td>
                                        <td><?= htmlspecialchars($student['form_name']) ?>
                                            <?= $student['stream_name'] ? ' - ' . htmlspecialchars($student['stream_name']) : '' ?>
                                        </td>
                                        <td>
                                            <div class="assign-single-container d-flex align-items-center gap-2">
                                                <select class="form-select form-select-sm dorm-select"
                                                    style="width: auto; display: none;">
                                                    <option value="">Select Dorm</option>
                                                    <?php foreach ($dormitories as $d): ?>
                                                        <option value="<?= $d['dormitory_id'] ?>">
                                                            <?= htmlspecialchars($d['name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button class="btn btn-sm btn-outline-success assign-single-btn"
                                                    data-student-id="<?= $student['student_id'] ?>"
                                                    data-student-name="<?= htmlspecialchars($student['full_name']) ?>">
                                                    <i class="bi bi-building me-1"></i> Assign to Dorm
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    // Almost identical to houses — just renamed variables, IDs, actions, icons
    $(document).ready(function() {

        function loadStudentsForAssign() {
            $.ajax({
                url: 'dormitories/functions.php',
                method: 'POST',
                data: {
                    action: 'get_unassigned_students'
                },
                success: function(json) {
                    if (json.status === 'success') {
                        const $list = $('#assignStudentsList').empty();
                        json.students.forEach(s => {
                            $list.append(`
                            <div class="col-md-6 col-lg-4">
                                <div class="form-check border rounded px-3 py-2">
                                    <input class="form-check-input" type="checkbox" name="student_ids[]" 
                                           value="${s.student_id}" id="assign_st_${s.student_id}">
                                    <label class="form-check-label" for="assign_st_${s.student_id}">
                                        ${s.full_name} <small class="text-muted">(${s.admission_no})</small>
                                    </label>
                                </div>
                            </div>
                        `);
                        });
                    } else {
                        $('#assignStudentsList').html('<p class="text-muted text-center py-4">No unassigned students found.</p>');
                    }
                },
                error: function() {
                    $('#assignStudentsList').html('<p class="text-danger text-center py-4">Failed to load students</p>');
                }
            });
        }

        $('#assignDormitoryModal').on('show.bs.modal', function() {
            loadStudentsForAssign();
        });

        $('#studentSearchAssign').on('input', function() {
            const term = $(this).val().toLowerCase().trim();
            $('#assignStudentsList .col-md-6').each(function() {
                const text = $(this).text().toLowerCase();
                $(this).toggle(text.includes(term));
            });
        });

        // Add Dormitory
        $('#addDormitoryForm').on('submit', function(e) {
            e.preventDefault();
            $.ajax({
                url: 'dormitories/functions.php',
                method: 'POST',
                data: $(this).serialize() + '&action=add_dormitory',
                success: function(json) {
                    alert(json.message);
                    if (json.status === 'success') {
                        $('#addDormitoryModal').modal('hide');
                        location.reload();
                    }
                }
            });
        });

        // Edit Dormitory
        $('.edit-dorm').on('click', function() {
            const id = $(this).data('dorm-id');
            const name = $(this).data('name');
            const code = $(this).data('code');
            const gender = $(this).data('gender');
            const capacity = $(this).data('capacity');
            const desc = $(this).data('desc');

            $('#editDormitoryForm [name="dormitory_id"]').val(id);
            $('#editDormitoryForm [name="name"]').val(name);
            $('#editDormitoryForm [name="short_code"]').val(code);
            $('#editDormitoryForm [name="gender"]').val(gender);
            $('#editDormitoryForm [name="capacity"]').val(capacity);
            $('#editDormitoryForm [name="description"]').val(desc);

            $('#editDormitoryModal').modal('show');
        });

        $('#editDormitoryForm').on('submit', function(e) {
            e.preventDefault();
            $.ajax({
                url: 'dormitories/functions.php',
                method: 'POST',
                data: $(this).serialize() + '&action=edit_dormitory',
                success: function(json) {
                    alert(json.message);
                    if (json.status === 'success') {
                        $('#editDormitoryModal').modal('hide');
                        location.reload();
                    }
                }
            });
        });

        // Delete Dormitory
        $('.delete-dorm').on('click', function() {
            if (!confirm('Delete this dormitory? Students will become unassigned.')) return;
            const id = $(this).data('dorm-id');
            $.ajax({
                url: 'dormitories/functions.php',
                method: 'POST',
                data: {
                    action: 'delete_dormitory',
                    dormitory_id: id
                },
                success: function(json) {
                    alert(json.message);
                    if (json.status === 'success') location.reload();
                }
            });
        });

        // View students in dormitory
        $('.view-dorm-students').on('click', function() {
            const id = $(this).data('dorm-id');
            const name = $(this).data('dorm-name');

            $('#viewDormName').text(name);

            $.ajax({
                url: 'dormitories/functions.php',
                method: 'POST',
                data: {
                    action: 'get_students_in_dorm',
                    dormitory_id: id
                },
                success: function(json) {
                    const $list = $('#dormStudentsList').empty();
                    const $noMsg = $('#noStudentsInDorm');

                    if (json.status === 'success' && json.students.length) {
                        json.students.forEach(s => {
                            $list.append(`
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    ${s.full_name}
                                    <small class="text-muted ms-2">(${s.admission_no})</small>
                                </div>
                                <button class="btn btn-sm btn-outline-danger remove-student-btn"
                                        data-student-id="${s.student_id}"
                                        data-student-name="${s.full_name}"
                                        data-dorm-id="${id}">
                                    <i class="bi bi-trash me-1"></i> Remove
                                </button>
                            </li>
                        `);
                        });
                        $noMsg.addClass('d-none');
                    } else {
                        $noMsg.removeClass('d-none');
                    }

                    $('#viewDormStudentsModal').modal('show');
                }
            });
        });

        // Remove student
        $(document).on('click', '.remove-student-btn', function() {
            const $btn = $(this);
            const studentId = $btn.data('student-id');
            const studentName = $btn.data('student-name');
            const dormId = $btn.data('dorm-id');

            if (!confirm(`Remove ${studentName} from this dormitory?`)) return;

            $.ajax({
                url: 'dormitories/functions.php',
                method: 'POST',
                data: {
                    action: 'remove_student_from_dorm',
                    student_id: studentId,
                    dormitory_id: dormId
                },
                success: function(json) {
                    alert(json.message);
                    if (json.status === 'success') {
                        $btn.closest('li').fadeOut(400, function() {
                            $(this).remove();
                            const $badge = $(`button[data-dorm-id="${dormId}"]`).closest('td').find('.badge.bg-info');
                            let count = parseInt($badge.text()) || 0;
                            if (count > 0) $badge.text(count - 1);
                            if ($('#dormStudentsList li').length === 0) {
                                $('#noStudentsInDorm').removeClass('d-none');
                            }
                        });
                    }
                },
                error: function() {
                    alert('Failed to remove student.');
                }
            });
        });

        // Manual assign submit
        $('#assignDormitoryFormManual').on('submit', function(e) {
            e.preventDefault();
            const dormId = $(this).find('[name="dormitory_id"]').val();
            const students = $(this).find('input[name="student_ids[]"]:checked').map(function() {
                return $(this).val();
            }).get();

            if (!dormId || students.length === 0) {
                alert('Select dormitory and at least one student.');
                return;
            }

            $.ajax({
                url: 'dormitories/functions.php',
                method: 'POST',
                data: {
                    action: 'assign_students_to_dorm',
                    dormitory_id: dormId,
                    student_ids: students
                },
                success: function(json) {
                    alert(json.message);
                    if (json.status === 'success') {
                        $('#assignDormitoryModal').modal('hide');
                        location.reload();
                    }
                },
                error: function() {
                    alert('Assignment failed.');
                }
            });
        });

        // ────────────────────────────────────────────────
        // Excel part (same logic, renamed actions & IDs)
        // ────────────────────────────────────────────────
        let validAdmissions = [];

        $('#excelFileInput').on('change', function(e) {
            const file = e.target.files[0];
            if (!file) return;

            const formData = new FormData();
            formData.append('excel_file', file);
            formData.append('action', 'preview_excel_students');

            $.ajax({
                url: 'dormitories/functions.php',
                method: 'POST',
                data: formData,
                contentType: false,
                processData: false,
                dataType: 'json',
                success: function(json) {
                    const $preview = $('#excelPreviewContainer').removeClass('d-none');
                    const $tableBody = $('#excelPreviewTable tbody').empty();
                    const $summary = $('#previewSummary');
                    const $confirmBtn = $('#confirmAssignExcelBtn').prop('disabled', true);
                    validAdmissions = [];

                    if (json.status !== 'success' || !json.students.length) {
                        $tableBody.html('<tr><td colspan="4" class="text-center text-danger py-4">No valid data or file error</td></tr>');
                        $summary.text('');
                        return;
                    }

                    let found = 0,
                        notFound = 0;

                    json.students.forEach((student, index) => {
                        const statusClass = student.exists ? 'text-success' : 'text-danger';
                        const statusText = student.exists ? 'Found' : 'Not Found';

                        $tableBody.append(`
                        <tr class="${student.exists ? '' : 'table-danger'}">
                            <td>${index + 1}</td>
                            <td>${student.name || '-'}</td>
                            <td>${student.admission_no}</td>
                            <td class="${statusClass}">${statusText}</td>
                        </tr>
                    `);

                        if (student.exists) {
                            found++;
                            validAdmissions.push(student.student_id);
                        } else {
                            notFound++;
                        }
                    });

                    $summary.html(`<strong>${found}</strong> found • <strong>${notFound}</strong> not found • Total: ${json.students.length}`);

                    if (found > 0 && $('#excelDormSelect').val()) {
                        $confirmBtn.prop('disabled', false);
                    }
                },
                error: function() {
                    alert('Failed to read Excel.');
                }
            });
        });

        $('#excelDormSelect').on('change', function() {
            $('#confirmAssignExcelBtn').prop('disabled', !(validAdmissions.length > 0 && this.value));
        });

        $('#clearPreviewBtn').on('click', function() {
            $('#excelFileInput').val('');
            $('#excelPreviewContainer').addClass('d-none');
            $('#excelPreviewTable tbody').empty();
            $('#previewSummary').text('');
            validAdmissions = [];
            $('#confirmAssignExcelBtn').prop('disabled', true);
        });

        $('#assignDormitoryFormExcel').on('submit', function(e) {
            e.preventDefault();
            if (validAdmissions.length === 0) {
                alert('No valid students.');
                return;
            }

            const dormId = $('#excelDormSelect').val();

            $.ajax({
                url: 'dormitories/functions.php',
                method: 'POST',
                data: {
                    action: 'assign_students_to_dorm',
                    dormitory_id: dormId,
                    student_ids: validAdmissions
                },
                success: function(json) {
                    alert(json.message);
                    if (json.status === 'success') {
                        $('#assignDormitoryModal').modal('hide');
                        location.reload();
                    }
                },
                error: function() {
                    alert('Assignment failed.');
                }
            });
        });

        // Single assign from unassigned modal
        $(document).on('click', '.assign-single-btn', function() {
            const $btn = $(this);
            const $container = $btn.closest('.assign-single-container');
            const $select = $container.find('.dorm-select');

            if ($select.is(':hidden')) {
                $select.show();
                $btn.text('Confirm');
                $btn.removeClass('btn-outline-success').addClass('btn-success');
                return;
            }

            const dormId = $select.val();
            const studentId = $btn.data('student-id');
            const studentName = $btn.data('student-name');

            if (!dormId) {
                alert('Select dormitory first.');
                return;
            }

            if (!confirm(`Assign ${studentName} to this dormitory?`)) return;

            $.ajax({
                url: 'dormitories/functions.php',
                method: 'POST',
                data: {
                    action: 'assign_students_to_dorm',
                    dormitory_id: dormId,
                    student_ids: [studentId]
                },
                success: function(json) {
                    alert(json.message);
                    if (json.status === 'success') {
                        $btn.closest('tr').fadeOut(400, function() {
                            $(this).remove();
                            let count = parseInt($('.badge.bg-info.fs-5').first().text()) || 0;
                            $('.badge.bg-info.fs-5').text(count - 1);
                            if ($('#unassignedStudentsTable tbody tr').length === 0) {
                                $('#unassignedStudentsTable tbody').html(`
                                <tr><td colspan="6" class="text-center text-muted py-4">No unassigned students found.</td></tr>
                            `);
                            }
                        });
                    }
                },
                error: function() {
                    alert('Failed to assign.');
                }
            });
        });

        $('#unassignedSearchInput').on('input', function() {
            const term = $(this).val().toLowerCase().trim();
            $('.unassigned-row').each(function() {
                const text = $(this).data('name') + ' ' + $(this).data('adm');
                $(this).toggle(text.includes(term));
            });
        });
    });
</script>

<?php include __DIR__ . '/../footer.php'; ?>