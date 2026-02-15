<?php
include __DIR__ . '/../header.php';
include __DIR__ . '/../sidebar.php';
require __DIR__ . '/../../connection/db.php';
require __DIR__ . '/../../vendor/autoload.php'; // For PhpSpreadsheet

use PhpOffice\PhpSpreadsheet\IOFactory;

// Check if user is logged in, has 'Admin' role, and belongs to the school
if (!isset($_SESSION['user_id']) || $_SESSION['role_name'] !== 'Admin' || !isset($_SESSION['school_id'])) {
  header("Location: ../index.php");
  exit;
}

// Fetch roles for this school
$stmt = $conn->prepare("SELECT role_id, role_name FROM roles WHERE school_id = ?");
$stmt->bind_param("i", $_SESSION['school_id']);
$stmt->execute();
$roles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch permissions (all available permissions)
$stmt = $conn->prepare("SELECT permission_id, name FROM permissions");
$stmt->execute();
$permissions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch users for this school
$stmt = $conn->prepare("
    SELECT u.user_id, u.first_name, u.other_names, u.username, u.email, u.personal_email, r.role_name
    FROM users u
    JOIN roles r ON u.role_id = r.role_id
    WHERE u.school_id = ? AND u.status = 'active'
");
$stmt->bind_param("i", $_SESSION['school_id']);
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch school name for username formatting
$stmt = $conn->prepare("SELECT name FROM schools WHERE school_id = ?");
$stmt->bind_param("i", $_SESSION['school_id']);
$stmt->execute();
$school = $stmt->get_result()->fetch_assoc();
$school_name = $school['name'];
$stmt->close();

// Fetch current school settings for the current year
$current_year = date('Y');
$stmt = $conn->prepare("
    SELECT setting_id, term_name, academic_year, closing_date, next_opening_date, next_term_fees, principal_name
    FROM school_settings
    WHERE school_id = ? AND academic_year = ?
    ORDER BY term_name
");
$stmt->bind_param("ii", $_SESSION['school_id'], $current_year);
$stmt->execute();
$settings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch available terms and years for Upload Fees modal
$stmt = $conn->prepare("
    SELECT setting_id, term_name, academic_year
    FROM school_settings
    WHERE school_id = ?
    ORDER BY academic_year DESC, term_name
");
$stmt->bind_param("i", $_SESSION['school_id']);
$stmt->execute();
$available_settings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch school details for Update School Details modal
$stmt = $conn->prepare("SELECT name, address, email, phone, logo FROM schools WHERE school_id = ?");
$stmt->bind_param("i", $_SESSION['school_id']);
$stmt->execute();
$school_details = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Counts
$totalRoles = count($roles);
$totalPermissions = count($permissions);
$totalUsers = count($users);
$totalSettings = count($settings);
?>

<style>
  /* Custom styles for mobile responsiveness */
  .content {
    max-width: 100%;
    /* Prevent content from exceeding viewport */
    overflow-x: hidden;
    /* Avoid horizontal scroll */
  }

  .card {
    width: 100%;
    /* Ensure cards don't exceed parent width */
  }

  .table-responsive {
    min-width: 0;
    /* Prevent table from forcing wider layout */
  }

  .table {
    width: 100%;
    /* Ensure table fits container */
    table-layout: auto;
    /* Allow columns to adjust dynamically */
  }

  .table th,
  .table td {
    white-space: nowrap;
    /* Prevent wrapping unless necessary */
    overflow: hidden;
    text-overflow: ellipsis;
    /* Truncate long text */
    max-width: 200px;
    /* Cap column width */
  }

  .table th:nth-child(2),
  .table td:nth-child(2) {
    /* Name column */
    max-width: 150px;
  }

  .table th:nth-child(3),
  .table td:nth-child(3) {
    /* Username column */
    max-width: 120px;
  }

  .table th:nth-child(4),
  .table td:nth-child(4) {
    /* Email column */
    max-width: 180px;
  }

  .table th:nth-child(5),
  .table td:nth-child(5) {
    /* Personal Email column */
    max-width: 180px;
  }

  .table th:nth-child(6),
  .table td:nth-child(6) {
    /* Role column */
    max-width: 100px;
  }

  .table th:nth-child(7),
  .table td:nth-child(7) {
    /* Actions column */
    max-width: 120px;
  }

  /* Mobile-specific styles */
  @media (max-width: 767.98px) {
    .content {
      padding: 1rem;
      /* Consistent with header.php */
    }

    .container {
      padding-left: 0.5rem;
      padding-right: 0.5rem;
      /* Reduce padding for mobile */
    }

    .card {
      margin-bottom: 1rem;
    }

    .card-body {
      padding: 1rem;
    }

    .card h5 {
      font-size: 1rem;
    }

    .card .display-5 {
      font-size: 2rem !important;
    }

    .card .fs-4 {
      font-size: 1.5rem !important;
    }

    .btn-sm {
      font-size: 0.75rem;
      padding: 0.25rem 0.5rem;
    }

    .table {
      font-size: 0.85rem;
    }

    .table th,
    .table td {
      padding: 0.5rem;
      max-width: 100px;
      /* Tighter constraints on mobile */
    }

    .table th:nth-child(4),
    .table td:nth-child(4),
    /* Email */
    .table th:nth-child(5),
    .table td:nth-child(5) {
      /* Personal Email */
      max-width: 120px;
    }

    .table-responsive {
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
    }
  }

  /* Very small screens */
  @media (max-width: 576px) {

    .table th,
    .table td {
      max-width: 80px;
    }

    .table th:nth-child(4),
    .table td:nth-child(4),
    .table th:nth-child(5),
    .table td:nth-child(5) {
      max-width: 100px;
    }

    .modal-dialog {
      margin: 0.5rem;
    }

    .modal-content {
      font-size: 0.9rem;
    }

    .modal-header h5 {
      font-size: 1.1rem;
    }
  }
</style>

<div class="content">
  <div class="container py-3">
    <!-- Title -->
    <h3 class="mb-4 d-flex align-items-center">
      <i class="bi bi-shield-lock me-2"></i> Admin Management
    </h3>

    <!-- Cards -->
    <div class="row mb-4 g-4">
      <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 text-center">
          <div class="card-body">
            <i class="bi bi-people-fill display-5 text-primary"></i>
            <h5 class="mt-3">Roles</h5>
            <p class="fs-4 fw-bold"><?php echo number_format($totalRoles); ?></p>
            <div class="mt-3">
              <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addRoleModal">
                <i class="bi bi-plus-circle me-1"></i> Add Role
              </button>
            </div>
          </div>
        </div>
      </div>

      <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 text-center">
          <div class="card-body">
            <i class="bi bi-key display-5 text-success"></i>
            <h5 class="mt-3">Permissions</h5>
            <p class="fs-4 fw-bold"><?php echo number_format($totalPermissions); ?></p>
            <div class="mt-3">
              <button class="btn btn-sm btn-outline-success me-2" data-bs-toggle="modal" data-bs-target="#assignPermissionsToRoleModal">
                <i class="bi bi-plus-circle me-1"></i> Assign Permissions
              </button>
              <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#assignRolesToUserModal">
                <i class="bi bi-person-plus me-1"></i> Assign Roles
              </button>
            </div>
          </div>
        </div>
      </div>

      <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 text-center">
          <div class="card-body">
            <i class="bi bi-person-lines-fill display-5 text-warning"></i>
            <h5 class="mt-3">Users</h5>
            <p class="fs-4 fw-bold"><?php echo number_format($totalUsers); ?></p>
            <div class="mt-3">
              <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#addUserModal">
                <i class="bi bi-plus-circle me-1"></i> Add User
              </button>
            </div>
          </div>
        </div>
      </div>

      <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 text-center position-relative">
          <div class="card-body">
            <button class="btn btn-sm btn-outline-secondary position-absolute top-0 end-0 mt-2 me-2" data-bs-toggle="modal" data-bs-target="#updateSchoolDetailsModal">
              <i class="bi bi-pencil-square me-1"></i> Update School
            </button>
            <i class="bi bi-gear-fill display-5 text-info"></i>
            <h5 class="mt-3">School Settings</h5>
            <p class="fs-4 fw-bold"><?php echo number_format($totalSettings); ?></p>
            <div class="mt-3">
              <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#manageSettingsModal">
                <i class="bi bi-pencil-square me-1"></i> Manage Settings
              </button>
              <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#uploadFeesModal">
                <i class="bi bi-upload me-1"></i> Upload Fees Balance
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Users Table -->
    <div class="card shadow-sm border-0">
      <div class="card-body">
        <h5 class="mb-3"><i class="bi bi-people me-2"></i> All Users</h5>
        <div class="table-responsive">
          <table class="table table-hover align-middle">
            <thead class="table-dark">
              <tr>
                <th>#</th>
                <th>Name</th>
                <th>Username</th>
                <th>Email</th>
                <th>Personal Email</th>
                <th>Role</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($users as $index => $user): ?>
                <tr>
                  <td><?php echo $index + 1; ?></td>
                  <td><?php echo htmlspecialchars($user['first_name'] . ' ' . ($user['other_names'] ?? '')); ?></td>
                  <td><?php echo htmlspecialchars($user['username']); ?></td>
                  <td><?php echo htmlspecialchars($user['email']); ?></td>
                  <td><?php echo htmlspecialchars($user['personal_email'] ?? ''); ?></td>
                  <td><span class="badge bg-info text-dark"><?php echo htmlspecialchars($user['role_name']); ?></span></td>
                  <td>
                    <button class="btn btn-sm btn-info text-white" data-bs-toggle="modal" data-bs-target="#viewUserModal<?php echo $user['user_id']; ?>"><i class="bi bi-eye"></i></button>
                    <button class="btn btn-sm btn-warning text-white" data-bs-toggle="modal" data-bs-target="#editUserModal<?php echo $user['user_id']; ?>"><i class="bi bi-pencil-square"></i></button>
                    <button class="btn btn-sm btn-secondary text-white reset-password-btn"
                      data-user-id="<?php echo $user['user_id']; ?>"
                      data-user-name="<?php echo htmlspecialchars($user['first_name'] . ' ' . ($user['other_names'] ?? '')); ?>">
                      <i class="bi bi-key"></i>
                    </button>
                    <button class="btn btn-sm btn-danger delete-user" data-user-id="<?php echo $user['user_id']; ?>"><i class="bi bi-trash"></i></button>
                  </td>
                </tr>

                <!-- View User Modal -->
                <div class="modal fade" id="viewUserModal<?php echo $user['user_id']; ?>" tabindex="-1">
                  <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                      <div class="modal-header bg-info text-white">
                        <h5 class="modal-title"><i class="bi bi-eye me-2"></i> View User</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                      </div>
                      <div class="modal-body text-center">
                        <h5><?php echo htmlspecialchars($user['first_name'] . ' ' . ($user['other_names'] ?? '')); ?></h5>
                        <p>Username: <?php echo htmlspecialchars($user['username']); ?></p>
                        <p>Email: <?php echo htmlspecialchars($user['email']); ?></p>
                        <p>Personal Email: <?php echo htmlspecialchars($user['personal_email'] ?? ''); ?></p>
                        <p><span class="badge bg-primary"><?php echo htmlspecialchars($user['role_name']); ?></span></p>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Edit User Modal -->
                <div class="modal fade" id="editUserModal<?php echo $user['user_id']; ?>" tabindex="-1">
                  <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                      <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i> Edit User</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                      </div>
                      <div class="modal-body">
                        <form class="edit-user-form" data-user-id="<?php echo $user['user_id']; ?>">
                          <div class="mb-3">
                            <label class="form-label">First Name</label>
                            <input type="text" class="form-control" name="first_name" value="<?php echo htmlspecialchars($user['first_name']); ?>" required>
                          </div>
                          <div class="mb-3">
                            <label class="form-label">Other Names</label>
                            <input type="text" class="form-control" name="other_names" value="<?php echo htmlspecialchars($user['other_names'] ?? ''); ?>">
                          </div>
                          <div class="mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control" name="username" value="<?php echo htmlspecialchars(explode('@', $user['username'])[0]); ?>" required>
                          </div>
                          <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                          </div>
                          <div class="mb-3">
                            <label class="form-label">Personal Email</label>
                            <input type="email" class="form-control" name="personal_email" value="<?php echo htmlspecialchars($user['personal_email'] ?? ''); ?>">
                          </div>
                          <div class="mb-3">
                            <label class="form-label">Role</label>
                            <select class="form-select" name="role_id" required>
                              <?php foreach ($roles as $role): ?>
                                <option value="<?php echo $role['role_id']; ?>" <?php echo ($user['role_name'] == $role['role_name']) ? "selected" : ""; ?>>
                                  <?php echo htmlspecialchars($role['role_name']); ?>
                                </option>
                              <?php endforeach; ?>
                            </select>
                          </div>
                          <button type="submit" class="btn btn-success">Update</button>
                        </form>
                      </div>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Add Role Modal -->
<div class="modal fade" id="addRoleModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i> Add Role</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="addRoleForm">
          <div class="mb-3">
            <label class="form-label">Role Name</label>
            <input type="text" class="form-control" name="role_name" placeholder="Enter role name" required>
          </div>
          <button type="submit" class="btn btn-primary">Add Role</button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Assign Permissions to Role Modal -->
<div class="modal fade" id="assignPermissionsToRoleModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i> Assign Permissions to Role</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="assignPermissionsToRoleForm">
          <div class="mb-3">
            <label class="form-label">Role</label>
            <select class="form-select" name="role_id" required>
              <option value="">Select Role</option>
              <?php foreach ($roles as $role): ?>
                <option value="<?php echo $role['role_id']; ?>"><?php echo htmlspecialchars($role['role_name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Permissions</label>
            <div class="row">
              <?php foreach ($permissions as $permission): ?>
                <div class="col-6 col-md-6">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="permission_ids[]" value="<?php echo $permission['permission_id']; ?>">
                    <label class="form-check-label"><?php echo htmlspecialchars($permission['name']); ?></label>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
          <button type="submit" class="btn btn-success">Assign Permissions</button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Assign Roles to User Modal -->
<div class="modal fade" id="assignRolesToUserModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i> Assign Role to User</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="assignRolesToUserForm">
          <div class="mb-3">
            <label class="form-label">User</label>
            <select class="form-select" name="user_id" required>
              <option value="">Select User</option>
              <?php foreach ($users as $user): ?>
                <option value="<?php echo $user['user_id']; ?>">
                  <?php echo htmlspecialchars($user['first_name'] . ' ' . ($user['other_names'] ?? '') . ' (' . $user['username'] . ')'); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Role</label>
            <div class="row">
              <?php foreach ($roles as $role): ?>
                <div class="col-6 col-md-6">
                  <div class="form-check">
                    <input class="form-check-input" type="radio" name="role_id" value="<?php echo $role['role_id']; ?>" required>
                    <label class="form-check-label"><?php echo htmlspecialchars($role['role_name']); ?></label>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
          <button type="submit" class="btn btn-success">Assign Role</button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i> Add User</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="addUserForm">
          <div class="mb-3">
            <label class="form-label">First Name</label>
            <input type="text" class="form-control" name="first_name" placeholder="Enter first name" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Other Names</label>
            <input type="text" class="form-control" name="other_names" placeholder="Enter other names (optional)">
          </div>
          <div class="mb-3">
            <label class="form-label">Username (will be suffixed with @<?php echo htmlspecialchars(preg_replace('/[^A-Za-z0-9]/', '', strtolower($school_name))); ?>)</label>
            <input type="text" class="form-control" name="username" placeholder="Enter username" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Email</label>
            <input type="email" class="form-control" name="email" placeholder="Enter system email" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Personal Email</label>
            <input type="email" class="form-control" name="personal_email" placeholder="Enter personal email (optional)">
          </div>
          <div class="mb-3">
            <label class="form-label">Password</label>
            <input type="password" class="form-control" name="password" placeholder="Enter password" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Confirm Password</label>
            <input type="password" class="form-control" name="confirm_password" placeholder="Confirm password" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Role</label>
            <select class="form-select" name="role_id" required>
              <?php foreach ($roles as $role): ?>
                <option value="<?php echo $role['role_id']; ?>"><?php echo htmlspecialchars($role['role_name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn btn-warning">Add User</button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Manage School Settings Modal -->
<div class="modal fade" id="manageSettingsModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-gear-fill me-2"></i> Manage School Settings</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="manageSettingsForm">
          <ul class="nav nav-tabs mb-3">
            <li class="nav-item">
              <a class="nav-link active" data-bs-toggle="tab" href="#general-settings">General Settings</a>
            </li>
            <li class="nav-item">
              <a class="nav-link" data-bs-toggle="tab" href="#term-details">Term Details</a>
            </li>
          </ul>
          <div class="tab-content">
            <div class="tab-pane fade show active" id="general-settings">
              <div class="mb-3">
                <label class="form-label">Principal's Name</label>
                <input type="text" class="form-control" name="principal_name" placeholder="Enter principal's name" value="<?php echo htmlspecialchars($settings[0]['principal_name'] ?? ''); ?>">
              </div>
              <div class="mb-3">
                <label class="form-label">Academic Year</label>
                <input type="number" class="form-control" name="academic_year" placeholder="e.g., 2025" value="<?php echo htmlspecialchars($settings[0]['academic_year'] ?? date('Y')); ?>" required>
              </div>
            </div>
            <div class="tab-pane fade" id="term-details">
              <div class="mb-3">
                <label class="form-label">Term Name</label>
                <select class="form-select" name="term_name" required>
                  <option value="">Select Term</option>
                  <option value="Term 1">Term 1</option>
                  <option value="Term 2">Term 2</option>
                  <option value="Term 3">Term 3</option>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label">Closing Date</label>
                <input type="date" class="form-control" name="closing_date">
              </div>
              <div class="mb-3">
                <label class="form-label">Next Opening Date</label>
                <input type="date" class="form-control" name="next_opening_date">
              </div>
              <div class="mb-3">
                <label class="form-label">Next Term Fees</label>
                <input type="number" step="0.01" class="form-control" name="next_term_fees" placeholder="e.g., 5000.00">
              </div>
            </div>
          </div>
          <button type="submit" class="btn btn-info">Save Settings</button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Update School Details Modal -->
<div class="modal fade" id="updateSchoolDetailsModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-building me-2"></i> Update School Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="updateSchoolDetailsForm" enctype="multipart/form-data">
          <div class="mb-3">
            <label class="form-label">School Name</label>
            <input type="text" class="form-control" name="name" value="<?php echo htmlspecialchars($school_details['name']); ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Address</label>
            <input type="text" class="form-control" name="address" value="<?php echo htmlspecialchars($school_details['address'] ?? ''); ?>">
          </div>
          <div class="mb-3">
            <label class="form-label">Email</label>
            <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($school_details['email'] ?? ''); ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Phone</label>
            <input type="text" class="form-control" name="phone" value="<?php echo htmlspecialchars($school_details['phone'] ?? ''); ?>">
          </div>
          <div class="mb-3">
            <label class="form-label">Logo (Image file, optional)</label>
            <input type="file" class="form-control" name="logo" accept="image/*">
            <?php if (!empty($school_details['logo'])): ?>
              <small class="form-text text-muted">Current logo: <?php echo htmlspecialchars(basename($school_details['logo'])); ?></small>
            <?php endif; ?>
          </div>
          <button type="submit" class="btn btn-primary">Update School Details</button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Upload Fees Modal -->
<div class="modal fade" id="uploadFeesModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-upload me-2"></i> Upload Student Fees</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="uploadFeesForm" enctype="multipart/form-data">
          <div class="mb-3">
            <label class="form-label">Term and Year</label>
            <select class="form-select" name="setting_id" required>
              <option value="">Select Term and Year</option>
              <?php foreach ($available_settings as $setting): ?>
                <option value="<?php echo $setting['setting_id']; ?>">
                  <?php echo htmlspecialchars($setting['term_name'] . ' ' . $setting['academic_year']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Excel File (Columns: admission_no, fees_balance)</label>
            <input type="file" class="form-control" name="fees_file" accept=".xlsx,.xls" required>
          </div>
          <div class="mb-3">
            <a href="admin/functions.php?action=download_fees_template" class="btn btn-sm btn-outline-primary">
              <i class="bi bi-download me-1"></i> Download Excel Template
            </a>
          </div>
          <button type="submit" class="btn btn-info">Upload Fees</button>
        </form>
      </div>
    </div>
  </div>
</div>


<!-- Reset Password Modal (shared for all users) -->
<div class="modal fade" id="resetPasswordModal" tabindex="-1" aria-labelledby="resetPasswordModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-secondary text-white">
        <h5 class="modal-title" id="resetPasswordModalLabel">
          <i class="bi bi-key me-2"></i> Reset Password
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p><strong>For user:</strong> <span id="resetUserName"></span></p>
        <form id="resetPasswordForm">
          <input type="hidden" name="target_user_id" id="resetUserId">

          <div class="mb-3">
            <label class="form-label">New Password</label>
            <input type="password" class="form-control" name="new_password" id="reset_new_password"
              placeholder="Enter new password" required minlength="6">
          </div>
          <div class="mb-3">
            <label class="form-label">Confirm New Password</label>
            <input type="password" class="form-control" name="confirm_password" id="reset_confirm_password"
              placeholder="Confirm new password" required>
          </div>
          <div class="d-grid">
            <button type="submit" class="btn btn-secondary">
              <i class="bi bi-check-circle me-2"></i> Reset Password
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
  $(document).ready(function() {
    function ajaxDebug(options) {
      console.log("🔹 Sending AJAX:", options);
      $.ajax({
        ...options,
        success: function(response, status, xhr) {
          console.log("✅ Raw Response:", response);
          try {
            const json = typeof response === 'string' ? JSON.parse(response) : response;
            console.log("✅ Parsed JSON:", json);
            if (options.onSuccess) options.onSuccess(json);
          } catch (e) {
            console.error("❌ JSON parse failed:", e);
            console.log("🔴 Response text:", response);
            alert("Server returned invalid JSON. Check console.");
          }
        },
        error: function(xhr, status, error) {
          console.error("❌ AJAX Error:", status, error);
          console.log("🔴 Response text:", xhr.responseText);
          try {
            const json = JSON.parse(xhr.responseText);
            alert(json.message || "An error occurred. Check console.");
          } catch (e) {
            alert("An error occurred. Check console.");
          }
        }
      });
    }

    // Add Role
    $('#addRoleForm').on('submit', function(e) {
      e.preventDefault();
      ajaxDebug({
        url: 'admin/functions.php',
        method: 'POST',
        data: $(this).serialize() + '&action=add_role',
        onSuccess: function(json) {
          alert(json.message);
          if (json.status === 'success') {
            $('#addRoleModal').modal('hide');
            window.location.reload();
          }
        }
      });
    });

    // Assign Permissions to Role
    $('#assignPermissionsToRoleForm').on('submit', function(e) {
      e.preventDefault();
      ajaxDebug({
        url: 'admin/functions.php',
        method: 'POST',
        data: $(this).serialize() + '&action=assign_permissions_to_role',
        onSuccess: function(json) {
          alert(json.message);
          if (json.status === 'success') {
            $('#assignPermissionsToRoleModal').modal('hide');
            window.location.reload();
          }
        }
      });
    });

    // Assign Role to User
    $('#assignRolesToUserForm').on('submit', function(e) {
      e.preventDefault();
      ajaxDebug({
        url: 'admin/functions.php',
        method: 'POST',
        data: $(this).serialize() + '&action=assign_roles_to_user',
        onSuccess: function(json) {
          alert(json.message);
          if (json.status === 'success') {
            $('#assignRolesToUserModal').modal('hide');
            window.location.reload();
          }
        }
      });
    });

    // Add User
    $('#addUserForm').on('submit', function(e) {
      e.preventDefault();
      const email = $(this).find('input[name="email"]').val();
      const personalEmail = $(this).find('input[name="personal_email"]').val();
      if (email && !email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
        alert('Please enter a valid system email address');
        return;
      }
      if (personalEmail && !personalEmail.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
        alert('Please enter a valid personal email address');
        return;
      }
      ajaxDebug({
        url: 'admin/functions.php',
        method: 'POST',
        data: $(this).serialize() + '&action=add_user',
        onSuccess: function(json) {
          alert(json.message);
          if (json.status === 'success') {
            $('#addUserModal').modal('hide');
            window.location.reload();
          }
        }
      });
    });

    // Edit User
    $('.edit-user-form').on('submit', function(e) {
      e.preventDefault();
      const userId = $(this).data('user-id');
      const email = $(this).find('input[name="email"]').val();
      const personalEmail = $(this).find('input[name="personal_email"]').val();
      if (email && !email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
        alert('Please enter a valid system email address');
        return;
      }
      if (personalEmail && !personalEmail.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
        alert('Please enter a valid personal email address');
        return;
      }
      ajaxDebug({
        url: 'admin/functions.php',
        method: 'POST',
        data: $(this).serialize() + '&action=edit_user&user_id=' + userId,
        onSuccess: function(json) {
          alert(json.message);
          if (json.status === 'success') {
            $('#editUserModal' + userId).modal('hide');
            window.location.reload();
          }
        }
      });
    });

    // Delete User
    $('.delete-user').on('click', function() {
      if (!confirm('Delete this user?')) return;
      const userId = $(this).data('user-id');
      ajaxDebug({
        url: 'admin/functions.php',
        method: 'POST',
        data: {
          action: 'delete_user',
          user_id: userId
        },
        onSuccess: function(json) {
          alert(json.message);
          if (json.status === 'success') {
            window.location.reload();
          }
        }
      });
    });

    // Manage School Settings
    $('#manageSettingsForm').on('submit', function(e) {
      e.preventDefault();
      ajaxDebug({
        url: 'admin/functions.php',
        method: 'POST',
        data: $(this).serialize() + '&action=manage_settings',
        onSuccess: function(json) {
          alert(json.message);
          if (json.status === 'success') {
            $('#manageSettingsModal').modal('hide');
            window.location.reload();
          }
        }
      });
    });

    // Update School Details
    $('#updateSchoolDetailsForm').on('submit', function(e) {
      e.preventDefault();
      const formData = new FormData(this);
      formData.append('action', 'update_school_details');
      ajaxDebug({
        url: 'admin/functions.php',
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        onSuccess: function(json) {
          alert(json.message);
          if (json.status === 'success') {
            $('#updateSchoolDetailsModal').modal('hide');
            window.location.reload();
          }
        }
      });
    });

    // Upload Fees
    $('#uploadFeesForm').on('submit', function(e) {
      e.preventDefault();
      const formData = new FormData(this);
      formData.append('action', 'upload_fees');
      ajaxDebug({
        url: 'admin/functions.php',
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        onSuccess: function(json) {
          alert(json.message);
          if (json.status === 'success') {
            $('#uploadFeesModal').modal('hide');
            window.location.reload();
          }
        }
      });
    });


    // Reset Password - Open modal
    $(document).on('click', '.reset-password-btn', function() {
      const userId = $(this).data('user-id');
      const userName = $(this).data('user-name');

      $('#resetUserId').val(userId);
      $('#resetUserName').text(userName);
      $('#reset_new_password').val('');
      $('#reset_confirm_password').val('');

      $('#resetPasswordModal').modal('show');
    });

    // Reset Password - Form submit
    $('#resetPasswordForm').on('submit', function(e) {
      e.preventDefault();

      const newPass = $('#reset_new_password').val();
      const confirmPass = $('#reset_confirm_password').val();

      if (newPass !== confirmPass) {
        alert('Passwords do not match!');
        return;
      }

      if (newPass.length < 6) {
        alert('Password must be at least 6 characters long!');
        return;
      }

      ajaxDebug({
        url: 'admin/functions.php',
        method: 'POST',
        data: $(this).serialize() + '&action=reset_user_password',
        onSuccess: function(json) {
          alert(json.message);
          if (json.status === 'success') {
            $('#resetPasswordModal').modal('hide');
            $('#resetPasswordForm')[0].reset();
          }
        }
      });
    });

  });
</script>

<?php include __DIR__ . '/../footer.php'; ?>