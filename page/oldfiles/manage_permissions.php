<?php
session_start();
require './connection/db.php';

if (!isset($_SESSION['user_id'], $_SESSION['school_id'])) {
    header("Location: login.php"); exit;
}

$school_id = (int)$_SESSION['school_id'];
$role_id = (int)($_GET['role_id'] ?? 0);

// Verify role belongs to this school
$roleStmt = $conn->prepare("SELECT role_id, role_name FROM roles WHERE role_id = ? AND school_id = ?");
$roleStmt->bind_param("ii", $role_id, $school_id);
$roleStmt->execute();
$role = $roleStmt->get_result()->fetch_assoc();
$roleStmt->close();

if (!$role) { header("Location: dashboard.php?err=role_not_found"); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Clear existing permissions for this role+school
    $del = $conn->prepare("DELETE FROM role_permissions WHERE role_id = ? AND school_id = ?");
    $del->bind_param("ii", $role_id, $school_id);
    $del->execute();
    $del->close();

    // Insert selected permissions
    if (!empty($_POST['permissions']) && is_array($_POST['permissions'])) {
        $ins = $conn->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id, school_id) VALUES (?, ?, ?)");
        foreach ($_POST['permissions'] as $pid) {
            $pid = (int)$pid;
            $ins->bind_param("iii", $role_id, $pid, $school_id);
            $ins->execute();
        }
        $ins->close();
    }

    header("Location: manage_permissions.php?role_id={$role_id}&ok=saved"); exit;
}

// Fetch all permissions (global catalog)
$allPerms = $conn->query("SELECT permission_id, name, description FROM permissions ORDER BY name ASC");

// Fetch currently assigned permissions for this role in this school
$assigned = [];
$cur = $conn->prepare("SELECT permission_id FROM role_permissions WHERE role_id = ? AND school_id = ?");
$cur->bind_param("ii", $role_id, $school_id);
$cur->execute();
$res = $cur->get_result();
while ($row = $res->fetch_assoc()) $assigned[$row['permission_id']] = true;
$cur->close();

// Group by keyword
$groups = ['School' => [], 'Users' => [], 'Students' => []];
while ($p = $allPerms->fetch_assoc()) {
    if (str_contains($p['name'], 'school')) $groups['School'][] = $p;
    elseif (str_contains($p['name'], 'student')) $groups['Students'][] = $p;
    elseif (str_contains($p['name'], 'user')) $groups['Users'][] = $p;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Manage Permissions - <?= htmlspecialchars($role['role_name']) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
  <div class="card shadow">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h5 class="mb-0">Permissions for Role: <?= htmlspecialchars($role['role_name']) ?></h5>
      <a href="dashboard.php" class="btn btn-sm btn-secondary">Back</a>
    </div>
    <div class="card-body">
      <?php if (!empty($_GET['ok'])): ?>
        <div class="alert alert-success">Saved.</div>
      <?php endif; ?>
      <form method="post">
        <div class="row g-3">
          <?php foreach ($groups as $groupName => $perms): ?>
            <div class="col-md-4">
              <div class="border rounded p-3 h-100">
                <h6><?= $groupName ?></h6>
                <?php if (empty($perms)): ?>
                  <div class="text-muted small">No permissions</div>
                <?php else: ?>
                  <?php foreach ($perms as $perm): ?>
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" name="permissions[]"
                        value="<?= $perm['permission_id'] ?>" id="perm<?= $perm['permission_id'] ?>"
                        <?= isset($assigned[$perm['permission_id']]) ? 'checked' : '' ?>>
                      <label class="form-check-label" for="perm<?= $perm['permission_id'] ?>">
                        <?= htmlspecialchars($perm['description']) ?> <span class="text-muted">[<?= $perm['name'] ?>]</span>
                      </label>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <button class="btn btn-primary mt-3">Save Permissions</button>
      </form>
    </div>
  </div>
</div>
</body>
</html>
