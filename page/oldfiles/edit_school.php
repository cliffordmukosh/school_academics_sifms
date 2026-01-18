<?php
session_start();
require './connection/db.php';

if (!isset($_SESSION['user_id'], $_SESSION['school_id'])) {
    header("Location: login.php"); exit;
}

$school_id = (int)$_SESSION['school_id'];

// Load school
$stmt = $conn->prepare("SELECT * FROM schools WHERE school_id = ?");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$school = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$school) { header("Location: dashboard.php?err=school_not_found"); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    $logoPath = $school['logo'];

    if (!empty($_FILES['logo']['name'])) {
        $dir = "uploads/logos/";
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $logoPath = $dir . time() . "_" . basename($_FILES["logo"]["name"]);
        move_uploaded_file($_FILES["logo"]["tmp_name"], $logoPath);
    }

    $upd = $conn->prepare("UPDATE schools SET name = ?, address = ?, email = ?, phone = ?, logo = ? WHERE school_id = ?");
    $upd->bind_param("sssssi", $name, $address, $email, $phone, $logoPath, $school_id);
    if ($upd->execute()) {
        $upd->close();
        header("Location: dashboard.php?ok=school_updated"); exit;
    }
    $upd->close();
    $err = "Failed to update school.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Edit School</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
  <div class="card shadow">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h5 class="mb-0">Edit School</h5>
      <a href="dashboard.php" class="btn btn-sm btn-secondary">Back</a>
    </div>
    <div class="card-body">
      <?php if (!empty($err)): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>
      <form method="post" enctype="multipart/form-data">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Name</label>
            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($school['name']) ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($school['email']) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Phone</label>
            <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($school['phone']) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Address</label>
            <input type="text" name="address" class="form-control" value="<?= htmlspecialchars($school['address']) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Logo</label>
            <input type="file" name="logo" class="form-control">
            <?php if (!empty($school['logo'])): ?>
              <div class="mt-2">
                <img src="<?= htmlspecialchars($school['logo']) ?>" alt="Logo" style="max-height:80px;">
              </div>
            <?php endif; ?>
          </div>
        </div>
        <button class="btn btn-primary mt-3">Save</button>
      </form>
    </div>
  </div>
</div>
</body>
</html>
