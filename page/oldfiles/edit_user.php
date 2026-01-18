<?php
session_start();
require './connection/db.php';

if (!isset($_SESSION['user_id'], $_SESSION['school_id'])) {
    header("Location: login.php"); exit;
}

$school_id = (int)$_SESSION['school_id'];
$user_id = (int)($_GET['id'] ?? 0);

// Load user (scoped)
$uStmt = $conn->prepare("
  SELECT user_id, full_name, email, role_id 
  FROM users WHERE user_id = ? AND school_id = ?
");
$uStmt->bind_param("ii", $user_id, $school_id);
$uStmt->execute();
$user = $uStmt->get_result()->fetch_assoc();
$uStmt->close();

if (!$user) { header("Location: dashboard.php?err=user_not_found"); exit; }

// Roles for dropdown
$rStmt = $conn->prepare("SELECT role_id, role_name FROM roles WHERE school_id = ?");
$rStmt->bind_param("i", $school_id);
$rStmt->execute();
$roles = $rStmt->get_result();
$rStmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role_id = (int)($_POST['role_id'] ?? 0);
    $new_password = $_POST['new_password'] ?? '';

    if ($full_name && $email && $role_id) {
        if ($new_password !== '') {
            $hash = password_hash($new_password, PASSWORD_BCRYPT);
            $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, role_id = ?, password_hash = ? WHERE user_id = ? AND school_id = ?");
            $stmt->bind_param("ssi sii", $full_name, $email, $role_id, $hash, $user_id, $school_id);
        } else {
            $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, role_id = ? WHERE user_id = ? AND school_id = ?");
            $stmt->bind_param("ssiii", $full_name, $email, $role_id, $user_id, $school_id);
        }
        if ($stmt->execute()) {
            $stmt->close();
            header("Location: dashboard.php?ok=user_updated"); exit;
        }
        $err = "Update failed (email may already be in use for this school).";
        $stmt->close();
    } else {
        $err = "All fields except password are required.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Edit User</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
  <div class="card shadow">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h5 class="mb-0">Edit User</h5>
      <a href="dashboard.php" class="btn btn-sm btn-secondary">Back</a>
    </div>
    <div class="card-body">
      <?php if (!empty($err)): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>
      <form method="post">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Full Name</label>
            <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($user['full_name']) ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Role</label>
            <select name="role_id" class="form-select" required>
              <?php while ($r = $roles->fetch_assoc()): ?>
                <option value="<?= $r['role_id'] ?>" <?= $r['role_id'] == $user['role_id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($r['role_name']) ?>
                </option>
              <?php endwhile; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">New Password (optional)</label>
            <input type="password" name="new_password" class="form-control">
          </div>
        </div>
        <button class="btn btn-primary mt-3">Save Changes</button>
      </form>
    </div>
  </div>
</di
