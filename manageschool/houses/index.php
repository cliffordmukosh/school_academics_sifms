<?php
// houses/index.php
include __DIR__ . '/../header.php';
include __DIR__ . '/../sidebar.php';
require __DIR__ . '/../../connection/db.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['school_id'])) {
    header("Location: ../../login.php");
    exit;
}

$school_id = $_SESSION['school_id'];

// Fetch all houses for this school
$stmt = $conn->prepare("
    SELECT house_id, name, description, 
           (SELECT COUNT(*) FROM student_houses sh 
            WHERE sh.house_id = h.house_id AND sh.is_current = 1) AS student_count
    FROM houses h
    WHERE h.school_id = ?
    ORDER BY name ASC
");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$houses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$totalHouses = count($houses);
$stmt->close();

// Fetch students without house (for quick assignment view)
$stmt = $conn->prepare("
    SELECT s.student_id, s.full_name, s.admission_no, c.form_name, st.stream_name
    FROM students s
    LEFT JOIN student_houses sh ON s.student_id = sh.student_id AND sh.is_current = 1
    JOIN classes c ON s.class_id = c.class_id
    JOIN streams st ON s.stream_id = st.stream_id
    WHERE s.school_id = ? AND sh.student_id IS NULL AND s.deleted_at IS NULL
    ORDER BY s.admission_no ASC
    LIMIT 50
");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$unassigned = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<div class="content">
    <div class="container-fluid">
        <div class="container py-4">
            <h3 class="mb-4 d-flex align-items-center">
                <i class="bi bi-house-door-fill me-2" style="color:#fd7e14;"></i>
                House Management
                <span class="badge bg-warning text-dark ms-3 fs-6">Total Houses: <?= $totalHouses ?></span>
            </h3>

            <!-- Quick Stats Cards -->
            <div class="row g-4 mb-4">
                <div class="col-md-4">
                    <div class="card shadow-sm border-0 text-center">
                        <div class="card-body">
                            <i class="bi bi-house-add display-5 text-warning"></i>
                            <h5 class="mt-3">Create New House</h5>
                            <button class="btn btn-warning mt-2" data-bs-toggle="modal" data-bs-target="#addHouseModal">
                                <i class="bi bi-plus-circle me-1"></i> Add House
                            </button>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card shadow-sm border-0 text-center">
                        <div class="card-body">
                            <i class="bi bi-people-fill display-5 text-primary"></i>
                            <h5 class="mt-3">Assign Students</h5>
                            <button class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#assignHouseModal">
                                <i class="bi bi-person-plus me-1"></i> Assign
                            </button>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card shadow-sm border-0 text-center h-100">
                        <div class="card-body d-flex flex-column justify-content-center">
                            <i class="bi bi-list-ul display-5 text-success mb-2"></i>
                            <h5 class="mt-2 mb-3">Unassigned Students</h5>

                            <?php if (count($unassigned) > 0): ?>
                                <div class="row g-2 align-items-center justify-content-center mb-2">
                                    <!-- Left column: Count badge -->
                                    <div class="col-auto">
                                        <span class="badge bg-success fs-5 px-4 py-2">
                                            <?= count($unassigned) ?>
                                        </span>
                                    </div>
                                    <!-- Right column: View button -->
                                    <div class="col-auto">
                                        <button class="btn btn-outline-success"
                                            data-bs-toggle="modal"
                                            data-bs-target="#viewUnassignedModal">
                                            <i class="bi bi-eye me-1"></i> View
                                        </button>
                                    </div>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-0">No unassigned students</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Houses Table -->
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <h5 class="mb-3 d-flex align-items-center">
                        <i class="bi bi-house-door me-2"></i> All Houses
                    </h5>

                    <table class="table table-striped table-hover align-middle" id="housesTable">
                        <thead class="table-dark">
                            <tr>
                                <th>#</th>
                                <th>House Name</th>
                                <th>Description</th>
                                <th>Students</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($houses as $index => $house): ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td><?= htmlspecialchars($house['name']) ?></td>
                                    <td><?= htmlspecialchars($house['description'] ?: '-') ?></td>
                                    <td>
                                        <span class="badge bg-info">
                                            <?= $house['student_count'] ?> students
                                        </span>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-primary me-1 view-house-students"
                                            data-house-id="<?= $house['house_id'] ?>"
                                            data-house-name="<?= htmlspecialchars($house['name']) ?>">
                                            <i class="bi bi-eye"></i> View
                                        </button>
                                        <button class="btn btn-sm btn-warning me-1 edit-house"
                                            data-house-id="<?= $house['house_id'] ?>"
                                            data-name="<?= htmlspecialchars($house['name']) ?>"
                                            data-desc="<?= htmlspecialchars($house['description'] ?: '') ?>">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="btn btn-sm btn-danger delete-house"
                                            data-house-id="<?= $house['house_id'] ?>">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Add House Modal -->
            <div class="modal fade" id="addHouseModal" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-house-add me-2"></i> Add New House</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <form id="addHouseForm">
                                <div class="mb-3">
                                    <label class="form-label">House Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="name" required placeholder="e.g. Simba">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Description (optional)</label>
                                    <textarea class="form-control" name="description" rows="3" placeholder="e.g. Red house - strength & courage"></textarea>
                                </div>
                                <button type="submit" class="btn btn-warning">Create House</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Edit House Modal -->
            <div class="modal fade" id="editHouseModal" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-pencil me-2"></i> Edit House</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <form id="editHouseForm">
                                <input type="hidden" name="house_id">
                                <div class="mb-3">
                                    <label class="form-label">House Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="name" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Description (optional)</label>
                                    <textarea class="form-control" name="description" rows="3"></textarea>
                                </div>
                                <button type="submit" class="btn btn-warning">Update House</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Assign House Modal -->
            <div class="modal fade" id="assignHouseModal" tabindex="-1">
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i> Assign Students to House</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">

                            <!-- Tabs navigation -->
                            <ul class="nav nav-tabs mb-4" id="assignTabs" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="manual-tab" data-bs-toggle="tab"
                                        data-bs-target="#manual" type="button" role="tab">
                                        <i class="bi bi-check2-square me-1"></i> Manual Selection
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="excel-tab" data-bs-toggle="tab"
                                        data-bs-target="#excel" type="button" role="tab">
                                        <i class="bi bi-file-earmark-spreadsheet me-1"></i> Excel Upload
                                    </button>
                                </li>
                            </ul>

                            <!-- Tab content -->
                            <div class="tab-content" id="assignTabContent">

                                <!-- Tab 1: Manual -->
                                <div class="tab-pane fade show active" id="manual" role="tabpanel">
                                    <form id="assignHouseFormManual">
                                        <div class="row g-3 mb-3">
                                            <div class="col-md-6">
                                                <label class="form-label">Select House <span class="text-danger">*</span></label>
                                                <select class="form-select" name="house_id" required>
                                                    <option value="">-- Choose House --</option>
                                                    <?php foreach ($houses as $h): ?>
                                                        <option value="<?= $h['house_id'] ?>">
                                                            <?= htmlspecialchars($h['name']) ?> (<?= $h['student_count'] ?> students)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Search students (name / admission no)</label>
                                                <input type="text" id="studentSearchAssign" class="form-control"
                                                    placeholder="Type to search...">
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

                                <!-- Tab 2: Excel Upload -->
                                <div class="tab-pane fade" id="excel" role="tabpanel">
                                    <form id="assignHouseFormExcel" enctype="multipart/form-data">
                                        <div class="row g-3 mb-4">
                                            <div class="col-md-6">
                                                <label class="form-label">Select House <span class="text-danger">*</span></label>
                                                <select class="form-select" name="house_id" id="excelHouseSelect" required>
                                                    <option value="">-- Choose House --</option>
                                                    <?php foreach ($houses as $h): ?>
                                                        <option value="<?= $h['house_id'] ?>">
                                                            <?= htmlspecialchars($h['name']) ?> (<?= $h['student_count'] ?> students)
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
                                            Download <a href="houses/functions.php?action=download_house_template" class="alert-link" target="_blank">this template</a>
                                            (includes sample data with correct headers).
                                        </div>

                                        <!-- Preview Area -->
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

            <!-- View House Students Modal -->
            <div class="modal fade" id="viewHouseStudentsModal" tabindex="-1">
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="bi bi-house-door-fill me-2"></i> Students in: <span id="viewHouseName"></span>
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="border p-3 rounded bg-light" style="max-height: 400px; overflow-y: auto;">
                                <ul class="list-group list-group-flush" id="houseStudentsList"></ul>
                            </div>
                            <div id="noStudentsInHouse" class="text-center text-muted py-5 d-none">
                                <i class="bi bi-person-x display-4"></i>
                                <p class="mt-3">No students assigned to this house yet.</p>
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
                <!-- Search -->
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
                                <th>Class / Stream</th>
                                <th style="width: 180px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($unassigned)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">
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
                                        <td><?= htmlspecialchars($student['form_name']) ?>
                                            <?= $student['stream_name'] ? ' - ' . htmlspecialchars($student['stream_name']) : '' ?>
                                        </td>
                                        <td>
                                            <div class="assign-single-container d-flex align-items-center gap-2">
                                                <select class="form-select form-select-sm house-select"
                                                    style="width: auto; display: none;">
                                                    <option value="">Select House</option>
                                                    <?php foreach ($houses as $h): ?>
                                                        <option value="<?= $h['house_id'] ?>">
                                                            <?= htmlspecialchars($h['name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button class="btn btn-sm btn-outline-primary assign-single-btn"
                                                    data-student-id="<?= $student['student_id'] ?>"
                                                    data-student-name="<?= htmlspecialchars($student['full_name']) ?>">
                                                    <i class="bi bi-house-door me-1"></i> Assign to House
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
    $(document).ready(function() {

        // ────────────────────────────────────────────────
        // Load students for MANUAL assignment tab
        // ────────────────────────────────────────────────
        function loadStudentsForAssign() {
            $.ajax({
                url: 'houses/functions.php',
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

        // Reload students every time modal opens (important for manual tab)
        $('#assignHouseModal').on('show.bs.modal', function() {
            loadStudentsForAssign();
        });

        // ────────────────────────────────────────────────
        // Search filter for manual students list
        // ────────────────────────────────────────────────
        $('#studentSearchAssign').on('input', function() {
            const term = $(this).val().toLowerCase().trim();
            $('#assignStudentsList .col-md-6').each(function() {
                const text = $(this).text().toLowerCase();
                $(this).toggle(text.includes(term));
            });
        });

        // ────────────────────────────────────────────────
        // Add New House
        // ────────────────────────────────────────────────
        $('#addHouseForm').on('submit', function(e) {
            e.preventDefault();
            $.ajax({
                url: 'houses/functions.php',
                method: 'POST',
                data: $(this).serialize() + '&action=add_house',
                success: function(json) {
                    alert(json.message);
                    if (json.status === 'success') {
                        $('#addHouseModal').modal('hide');
                        location.reload();
                    }
                }
            });
        });

        // ────────────────────────────────────────────────
        // Edit House
        // ────────────────────────────────────────────────
        $('.edit-house').on('click', function() {
            const id = $(this).data('house-id');
            const name = $(this).data('name');
            const desc = $(this).data('desc');

            $('#editHouseForm [name="house_id"]').val(id);
            $('#editHouseForm [name="name"]').val(name);
            $('#editHouseForm [name="description"]').val(desc);

            $('#editHouseModal').modal('show');
        });

        $('#editHouseForm').on('submit', function(e) {
            e.preventDefault();
            $.ajax({
                url: 'houses/functions.php',
                method: 'POST',
                data: $(this).serialize() + '&action=edit_house',
                success: function(json) {
                    alert(json.message);
                    if (json.status === 'success') {
                        $('#editHouseModal').modal('hide');
                        location.reload();
                    }
                }
            });
        });

        // ────────────────────────────────────────────────
        // Delete House
        // ────────────────────────────────────────────────
        $('.delete-house').on('click', function() {
            if (!confirm('Delete this house? Students will become unassigned.')) return;
            const id = $(this).data('house-id');
            $.ajax({
                url: 'houses/functions.php',
                method: 'POST',
                data: {
                    action: 'delete_house',
                    house_id: id
                },
                success: function(json) {
                    alert(json.message);
                    if (json.status === 'success') location.reload();
                }
            });
        });

        // ────────────────────────────────────────────────
        // View students in a house
        // ────────────────────────────────────────────────
        $('.view-house-students').on('click', function() {
            const id = $(this).data('house-id');
            const name = $(this).data('house-name');

            $('#viewHouseName').text(name);

            $.ajax({
                url: 'houses/functions.php',
                method: 'POST',
                data: {
                    action: 'get_students_in_house',
                    house_id: id
                },
                success: function(json) {
                    const $list = $('#houseStudentsList').empty();
                    const $noMsg = $('#noStudentsInHouse');

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
                                    data-house-id="${id}">
                                <i class="bi bi-trash me-1"></i> Remove
                            </button>
                        </li>
                    `);
                        });
                        $noMsg.addClass('d-none');
                    } else {
                        $noMsg.removeClass('d-none');
                    }

                    $('#viewHouseStudentsModal').modal('show');
                }
            });
        });

        // ────────────────────────────────────────────────
        // Remove student from house (single student)
        // ────────────────────────────────────────────────
        $(document).on('click', '.remove-student-btn', function() {
            const $btn = $(this);
            const studentId = $btn.data('student-id');
            const studentName = $btn.data('student-name');
            const houseId = $btn.data('house-id');

            if (!confirm(`Remove ${studentName} from this house?`)) {
                return;
            }

            $.ajax({
                url: 'houses/functions.php',
                method: 'POST',
                data: {
                    action: 'remove_student_from_house',
                    student_id: studentId,
                    house_id: houseId
                },
                success: function(json) {
                    alert(json.message);
                    if (json.status === 'success') {
                        // Remove the row from the list
                        $btn.closest('li').fadeOut(400, function() {
                            $(this).remove();

                            // Update house student count in the main table (optional visual feedback)
                            const $badge = $(`button[data-house-id="${houseId}"]`).closest('td').find('.badge.bg-info');
                            let count = parseInt($badge.text()) || 0;
                            if (count > 0) {
                                $badge.text(count - 1);
                            }

                            // If list is now empty → show no students message
                            if ($('#houseStudentsList li').length === 0) {
                                $('#noStudentsInHouse').removeClass('d-none');
                            }
                        });
                    }
                },
                error: function() {
                    alert('Failed to remove student. Please try again.');
                }
            });
        });

        // ────────────────────────────────────────────────
        // MANUAL ASSIGNMENT - Submit selected students
        // ────────────────────────────────────────────────
        $('#assignHouseFormManual').on('submit', function(e) {
            e.preventDefault();

            const houseId = $(this).find('[name="house_id"]').val();
            const students = $(this).find('input[name="student_ids[]"]:checked').map(function() {
                return $(this).val();
            }).get();

            if (!houseId || students.length === 0) {
                alert('Please select a house and at least one student.');
                return;
            }

            $.ajax({
                url: 'houses/functions.php',
                method: 'POST',
                data: {
                    action: 'assign_students_to_house',
                    house_id: houseId,
                    student_ids: students
                },
                success: function(json) {
                    alert(json.message);
                    if (json.status === 'success') {
                        $('#assignHouseModal').modal('hide');
                        location.reload();
                    }
                },
                error: function() {
                    alert('Failed to assign students. Please try again.');
                }
            });
        });

        // ────────────────────────────────────────────────
        // EXCEL UPLOAD - Submit form
        // ────────────────────────────────────────────────
        $('#assignHouseFormExcel').on('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            formData.append('action', 'assign_students_via_excel');

            $.ajax({
                url: 'houses/functions.php',
                method: 'POST',
                data: formData,
                contentType: false,
                processData: false,
                dataType: 'json',
                success: function(json) {
                    alert(json.message);
                    if (json.status === 'success') {
                        $('#assignHouseModal').modal('hide');
                        location.reload();
                    }
                },
                error: function(xhr) {
                    let msg = 'Upload failed';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        msg += ': ' + xhr.responseJSON.message;
                    }
                    alert(msg);
                }
            });
        });
    });

    // ────────────────────────────────────────────────
    // EXCEL PREVIEW + VALIDATION
    // ────────────────────────────────────────────────
    let validAdmissions = []; // store only valid ones for final submit

    $('#excelFileInput').on('change', function(e) {
        const file = e.target.files[0];
        if (!file) return;

        const formData = new FormData();
        formData.append('excel_file', file);
        formData.append('action', 'preview_excel_students');

        $.ajax({
            url: 'houses/functions.php',
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
                    $tableBody.html('<tr><td colspan="4" class="text-center text-danger py-4">No valid data found or file error</td></tr>');
                    $summary.text('');
                    return;
                }

                let found = 0;
                let notFound = 0;

                json.students.forEach((student, index) => {
                    const statusClass = student.exists ? 'text-success' : 'text-danger bg-light-danger';
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

                $summary.html(`
                <strong>${found}</strong> students found • 
                <strong>${notFound}</strong> not found • 
                Total: ${json.students.length}
            `);

                if (found > 0 && $('#excelHouseSelect').val()) {
                    $confirmBtn.prop('disabled', false);
                }
            },
            error: function() {
                alert('Failed to read Excel file. Please check format.');
            }
        });
    });

    // Enable/disable confirm button when house is selected
    $('#excelHouseSelect').on('change', function() {
        const hasValid = validAdmissions.length > 0;
        $('#confirmAssignExcelBtn').prop('disabled', !hasValid || !this.value);
    });

    // Clear preview
    $('#clearPreviewBtn').on('click', function() {
        $('#excelFileInput').val('');
        $('#excelPreviewContainer').addClass('d-none');
        $('#excelPreviewTable tbody').empty();
        $('#previewSummary').text('');
        validAdmissions = [];
        $('#confirmAssignExcelBtn').prop('disabled', true);
    });

    // Confirm & Assign (only valid ones)
    $('#assignHouseFormExcel').on('submit', function(e) {
        e.preventDefault();

        if (validAdmissions.length === 0) {
            alert('No valid students to assign.');
            return;
        }

        const houseId = $('#excelHouseSelect').val();

        $.ajax({
            url: 'houses/functions.php',
            method: 'POST',
            data: {
                action: 'assign_students_to_house',
                house_id: houseId,
                student_ids: validAdmissions
            },
            success: function(json) {
                alert(json.message);
                if (json.status === 'success') {
                    $('#assignHouseModal').modal('hide');
                    location.reload();
                }
            },
            error: function() {
                alert('Assignment failed. Please try again.');
            }
        });
    });

    // Single student assign from unassigned modal
    $(document).on('click', '.assign-single-btn', function() {
        const $btn = $(this);
        const $container = $btn.closest('.assign-single-container');
        const $select = $container.find('.house-select');

        // Show dropdown if hidden
        if ($select.is(':hidden')) {
            $select.show();
            $btn.text('Confirm');
            $btn.removeClass('btn-outline-primary').addClass('btn-primary');
            return;
        }

        // Confirm assignment
        const houseId = $select.val();
        const studentId = $btn.data('student-id');
        const studentName = $btn.data('student-name');

        if (!houseId) {
            alert('Please select a house first.');
            return;
        }

        if (!confirm(`Assign ${studentName} to this house?`)) return;

        $.ajax({
            url: 'houses/functions.php',
            method: 'POST',
            data: {
                action: 'assign_students_to_house',
                house_id: houseId,
                student_ids: [studentId]
            },
            success: function(json) {
                alert(json.message);
                if (json.status === 'success') {
                    // Remove row from table
                    $btn.closest('tr').fadeOut(400, function() {
                        $(this).remove();

                        // Update count in card
                        let currentCount = parseInt($('.badge.bg-success.fs-5').first().text()) || 0;
                        $('.badge.bg-success.fs-5').text(currentCount - 1);

                        // If table empty → show message
                        if ($('#unassignedStudentsTable tbody tr').length === 0) {
                            $('#unassignedStudentsTable tbody').html(`
                            <tr><td colspan="5" class="text-center text-muted py-4">
                                No unassigned students found.
                            </td></tr>
                        `);
                        }
                    });
                }
            },
            error: function() {
                alert('Failed to assign student. Please try again.');
            }
        });
    });

    // Search in unassigned modal
    $('#unassignedSearchInput').on('input', function() {
        const term = $(this).val().toLowerCase().trim();
        $('.unassigned-row').each(function() {
            const text = $(this).data('name') + ' ' + $(this).data('adm');
            $(this).toggle(text.includes(term));
        });
    });
</script>

<?php include __DIR__ . '/../footer.php'; ?>