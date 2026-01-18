<?php
session_start();
require './connection/db.php';

// ✅ Ensure logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id   = $_SESSION['user_id'];
$school_id = $_SESSION['school_id'];

// ✅ Fetch school details
$stmt = $conn->prepare("SELECT * FROM schools WHERE school_id = ?");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$school = $stmt->get_result()->fetch_assoc();

// ✅ Fetch current user role
$stmt = $conn->prepare("
    SELECT r.role_id, r.role_name 
    FROM users u
    JOIN roles r ON u.role_id = r.role_id
    WHERE u.user_id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$userRole = $stmt->get_result()->fetch_assoc();

// ✅ Admin flag
$isAdmin = (strtolower($userRole['role_name']) === 'admin');

// ✅ Fetch current user permissions
$userPermissions = [];
if (!$isAdmin) {
    $stmt = $conn->prepare("
        SELECT p.name 
        FROM role_permissions rp
        JOIN permissions p ON rp.permission_id = p.permission_id
        WHERE rp.role_id = ?
    ");
    $stmt->bind_param("i", $userRole['role_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $userPermissions[] = $row['name'];
    }
}

// ✅ Helper: check permission (Admin always has access)
function can($perm, $perms, $isAdmin = false) {
    return $isAdmin || in_array($perm, $perms);
}

// ✅ Fetch roles (only if allowed)
$roles = can('view_roles', $userPermissions, $isAdmin) 
    ? $conn->query("SELECT * FROM roles WHERE school_id = $school_id") 
    : null;

// ✅ Fetch users (only if allowed)
$users = can('view_users', $userPermissions, $isAdmin) 
    ? $conn->query("
        SELECT u.*, r.role_name 
        FROM users u
        JOIN roles r ON u.role_id = r.role_id
        WHERE u.school_id = $school_id
      ") 
    : null;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>School Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container-fluid">
    <div class="row">
        
        <!-- Sidebar -->
        <div class="col-md-3 bg-light vh-100 p-3">
            <div class="card text-center">
                <img src="<?= htmlspecialchars($school['logo'] ?? 'default-logo.png'); ?>" 
                     class="card-img-top mx-auto" style="max-width:120px;">
                <div class="card-body">
                    <h5 class="card-title"><?= htmlspecialchars($school['name']); ?></h5>
                    <p>
                        <?= htmlspecialchars($school['address']); ?><br>
                        <?= htmlspecialchars($school['email']); ?><br>
                        <?= htmlspecialchars($school['phone']); ?>
                    </p>
                    <?php if (can('edit_school', $userPermissions, $isAdmin)): ?>
                        <a href="edit_school.php?id=<?= $school['school_id']; ?>" 
                           class="btn btn-sm btn-primary">Edit</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="col-md-9 p-4">
            <h3>Dashboard</h3>

            <!-- Manage Roles -->
            <?php if ($roles): ?>
            <div class="card mb-4">
                <div class="card-header">Roles</div>
                <div class="card-body">
                    <?php if (can('add_roles', $userPermissions, $isAdmin)): ?>
                    <form method="post" action="add_role.php" class="row g-2">
                        <div class="col-md-6">
                            <input type="text" name="role_name" class="form-control" placeholder="New Role" required>
                            <input type="hidden" name="school_id" value="<?= $school_id; ?>">
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-success">Add Role</button>
                        </div>
                    </form>
                    <?php endif; ?>
                    <ul class="list-group mt-3">
                        <?php while($r = $roles->fetch_assoc()): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <?= htmlspecialchars($r['role_name']); ?>
                                <?php if (can('edit_roles', $userPermissions, $isAdmin)): ?>
                                    <a href="manage_permissions.php?role_id=<?= $r['role_id']; ?>" 
                                       class="btn btn-sm btn-outline-primary">Permissions</a>
                                <?php endif; ?>
                            </li>
                        <?php endwhile; ?>
                    </ul>
                </div>
            </div>
            <?php endif; ?>

            <!-- Manage Users -->
            <?php if ($users): ?>
            <div class="card">
                <div class="card-header">Users</div>
                <div class="card-body">
                    <?php if (can('add_users', $userPermissions, $isAdmin)): ?>
                        <a href="register_user.php" class="btn btn-success mb-3">Register User</a>
                    <?php endif; ?>
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($u = $users->fetch_assoc()): ?>
                                <tr>
                                    <td><?= htmlspecialchars($u['full_name']); ?></td>
                                    <td><?= htmlspecialchars($u['email']); ?></td>
                                    <td><?= htmlspecialchars($u['role_name']); ?></td>
                                    <td><?= htmlspecialchars($u['status']); ?></td>
                                    <td>
                                        <?php if (can('edit_users', $userPermissions, $isAdmin)): ?>
                                            <a href="edit_user.php?id=<?= $u['user_id']; ?>" 
                                               class="btn btn-sm btn-primary">Edit</a>
                                        <?php endif; ?>
                                        <?php if (can('delete_users', $userPermissions, $isAdmin)): ?>
                                            <a href="delete_user.php?id=<?= $u['user_id']; ?>" 
                                               class="btn btn-sm btn-danger"
                                               onclick="return confirm('Are you sure?');">Delete</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>
</body>
</html>
