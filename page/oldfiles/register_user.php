<?php
session_start();
require './connection/db.php';

if (!isset($_SESSION['user_id'], $_SESSION['school_id'])) {
    header("Location: login.php"); exit;
}

$school_id = (int)$_SESSION['school_id'];

// Fetch roles for dropdown
$roles = $conn->prepare("SELECT role_id, role_name FROM roles WHERE school_id = ?");
$roles->bind_param("i", $school_id);
$roles->execute();
$rolesRes = $roles->get_result();
$roles->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role_id = (int)($_POST['role_id'] ?? 0);

    if ($full_name && $email && $password && $role_id) {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $conn->prepare("INSERT INTO users (school_id, role_id, full_name, email, password_hash) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iisss", $school_id, $role_id, $full_name, $email, $hash);
        if ($stmt->execute()) {
            header("Location: dashboard.php?ok=user_added"); exit;
        }
        $err = "Failed to add user (email may already exist for this school).";
        $stmt->close();
    } else {
        $err = "All fields are required.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Register User</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
  <div class="card shadow">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h5 class="mb-0">Register User</h5>
      <a href="dashboard.php" class="btn btn-sm btn-secondary">Back</a>
    </div>
    <div class="card-body">
      <?php if (!empty($err)): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>
      <form method="post">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Full Name</label>
            <input type="text" name="full_name" class="form-control" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Password</label>
            <input type="password" name="password" class="form-control" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Role</label>
            <select name="role_id" class="form-select" required>
              <option value="">-- Select Role --</option>
              <?php while ($r = $rolesRes->fetch_assoc()): ?>
                <option value="<?= $r['role_id'] ?>"><?= htmlspecialchars($r['role_name']) ?></option>
              <?php endwhile; ?>
            </select>
          </div>
        </div>
        <button class="btn btn-success mt-3">Create User</button>
      </form>
    </div>
  </div>
</div>
</body>
</html>
