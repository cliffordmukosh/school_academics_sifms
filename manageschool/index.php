<?php
// session_start();
include 'header.php';
include 'sidebar.php';
require __DIR__ . '/../connection/db.php';

// Check if user is logged in and has appropriate permissions
if (!isset($_SESSION['user_id']) || !isset($_SESSION['school_id']) || !isset($_SESSION['role_id'])) {
    header("Location: ../login.php");
    exit;
}

// Function to check permissions
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

// Initialize counts
$students_count = 0;
$teachers_count = 0;
$classes_count = 0;
$exams_count = 0;

// Fetch Students count (only if user has view_students permission)
if (hasPermission($conn, $role_id, 'view_students', $school_id)) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS count FROM students WHERE school_id = ? AND deleted_at IS NULL");
    $stmt->bind_param("i", $school_id);
    $stmt->execute();
    $students_count = $stmt->get_result()->fetch_assoc()['count'];
    $stmt->close();
}

// Fetch Teachers count (only if user has view_teachers permission)
if (hasPermission($conn, $role_id, 'view_teachers', $school_id)) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS count 
        FROM users u
        JOIN roles r ON u.role_id = r.role_id
        WHERE u.school_id = ? AND r.role_name = 'Teacher' AND u.deleted_at IS NULL
    ");
    $stmt->bind_param("i", $school_id);
    $stmt->execute();
    $teachers_count = $stmt->get_result()->fetch_assoc()['count'];
    $stmt->close();
}

// Fetch Classes count (only if user has view_classes permission)
if (hasPermission($conn, $role_id, 'view_classes', $school_id)) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS count FROM classes WHERE school_id = ?");
    $stmt->bind_param("i", $school_id);
    $stmt->execute();
    $classes_count = $stmt->get_result()->fetch_assoc()['count'];
    $stmt->close();
}

// Fetch Exams count (only if user has view_exams permission)
if (hasPermission($conn, $role_id, 'view_exams', $school_id)) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS count FROM exams WHERE school_id = ? AND deleted_at IS NULL");
    $stmt->bind_param("i", $school_id);
    $stmt->execute();
    $exams_count = $stmt->get_result()->fetch_assoc()['count'];
    $stmt->close();
}

// Debug counts
error_log("Dashboard counts for school_id $school_id: Students=$students_count, Teachers=$teachers_count, Classes=$classes_count, Exams=$exams_count");
?>

<div class="content">
  <div class="container-fluid">
    <h3 class="mb-4"><i class="bi bi-speedometer2 me-2"></i> Dashboard</h3>

    <div class="row g-4">
      <!-- Students -->
      <?php if (hasPermission($conn, $role_id, 'view_students', $school_id)): ?>
      <div class="col-md-3 col-6">
        <div class="card shadow-sm border-0 text-center">
          <div class="card-body">
            <i class="bi bi-people display-5 text-primary"></i>
            <h5 class="mt-3">Students</h5>
            <p class="fs-4 fw-bold"><?php echo number_format($students_count); ?></p>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Teachers -->
      <?php if (hasPermission($conn, $role_id, 'view_teachers', $school_id)): ?>
      <div class="col-md-3 col-6">
        <div class="card shadow-sm border-0 text-center">
          <div class="card-body">
            <i class="bi bi-person-badge display-5 text-success"></i>
            <h5 class="mt-3">Teachers</h5>
            <p class="fs-4 fw-bold"><?php echo number_format($teachers_count); ?></p>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Classes -->
      <?php if (hasPermission($conn, $role_id, 'view_classes', $school_id)): ?>
      <div class="col-md-3 col-6">
        <div class="card shadow-sm border-0 text-center">
          <div class="card-body">
            <i class="bi bi-journal-bookmark display-5 text-warning"></i>
            <h5 class="mt-3">Classes</h5>
            <p class="fs-4 fw-bold"><?php echo number_format($classes_count); ?></p>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Exams -->
      <?php if (hasPermission($conn, $role_id, 'view_exams', $school_id)): ?>
      <div class="col-md-3 col-6">
        <div class="card shadow-sm border-0 text-center">
          <div class="card-body">
            <i class="bi bi-pencil-square display-5 text-danger"></i>
            <h5 class="mt-3">Exams</h5>
            <p class="fs-4 fw-bold"><?php echo number_format($exams_count); ?></p>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php include 'footer.php'; ?>