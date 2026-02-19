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
                    <div class="card shadow-sm border-0 text-center">
                        <div class="card-body">
                            <i class="bi bi-list-ul display-5 text-success"></i>
                            <h5 class="mt-3">Unassigned Students</h5>
                            <span class="badge bg-success fs-5"><?= count($unassigned) ?></span>
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
                            <form id="assignHouseForm">
                                <div class="row g-3">
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
                                        <label class="form-label">Students (search by name / adm no)</label>
                                        <input type="text" id="studentSearchAssign" class="form-control" placeholder="Type to search...">
                                    </div>
                                </div>

                                <div class="mt-3 border p-3 rounded" style="max-height: 320px; overflow-y: auto;">
                                    <div id="assignStudentsList" class="row g-2"></div>
                                </div>

                                <div class="d-flex justify-content-end mt-4">
                                    <button type="submit" class="btn btn-success px-4">Assign Selected</button>
                                </div>
                            </form>
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

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    $(document).ready(function() {

        // Load students for assignment modal
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
                    }
                }
            });
        }

        $('#assignHouseModal').on('show.bs.modal', loadStudentsForAssign);

        // Search in assign modal
        $('#studentSearchAssign').on('input', function() {
            const term = $(this).val().toLowerCase().trim();
            $('#assignStudentsList .col-md-6').each(function() {
                const text = $(this).text().toLowerCase();
                $(this).toggle(text.includes(term));
            });
        });

        // Add House
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

        // Edit House
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

        // Delete House
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

        // View students in house
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
                                ${s.full_name}
                                <small class="text-muted">${s.admission_no}</small>
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

        // Assign students form submit
        $('#assignHouseForm').on('submit', function(e) {
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
                }
            });
        });
    });
</script>

<?php include __DIR__ . '/../footer.php'; ?>