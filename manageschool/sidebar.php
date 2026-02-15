<?php
// sidebar.php

// session_start();
require __DIR__ . '/../connection/db.php';

// Default values
$role_name = 'Unknown';
$school_id = $_SESSION['school_id'] ?? null;
$role_id   = $_SESSION['role_id']   ?? null;
$user_id   = $_SESSION['user_id']   ?? null;

$is_teacher = false;
$allow_timetable_for_this_teacher = false;

$special_users = [15, 17];

// Fetch role name
if ($school_id && $role_id && isset($conn) && $conn instanceof mysqli) {
  $stmt = $conn->prepare("
        SELECT role_name 
        FROM roles 
        WHERE school_id = ? AND role_id = ?
        LIMIT 1
    ");

  if ($stmt) {
    $stmt->bind_param("ii", $school_id, $role_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
      $role_name = trim($row['role_name']);
      $is_teacher = (strtolower($role_name) === 'teacher');

      // Special rule: school 3 + teacher + user 15 or 17 → allow Timetable
      if ($is_teacher && $school_id == 3 && in_array($user_id, $special_users)) {
        $allow_timetable_for_this_teacher = true;
      }
    }

    $stmt->close();
  }
}

// Determine which menus should be enabled for teachers
$enable_dashboard = true;
$enable_exams     = true;
$enable_timetable = $allow_timetable_for_this_teacher;

// For teachers: most items are disabled
$teacher_disabled_class = $is_teacher ? ' disabled-menu-item' : '';
?>

<!-- Sidebar -->
<div class="sidebar bg-white border-end p-3" id="sidebar">
  <h6 class="text-uppercase text-muted fw-bold mb-3">Menu</h6>
  <ul class="nav flex-column">

    <!-- Dashboard – always enabled -->
    <li class="nav-item mb-2">
      <a class="nav-link d-flex align-items-center text-dark load-page" href="#" style="color:black;">
        <i class="bi bi-speedometer2 me-2" style="font-weight:700; color:#ff5733;"></i> Dashboard
      </a>
    </li>

    <!-- Admin -->
    <li class="nav-item mb-2<?= $teacher_disabled_class ?>">
      <a class="nav-link d-flex align-items-center text-dark load-page" href="admin/index.php">
        <i class="bi bi-shield-lock me-2" style="font-weight:700; color:#33c1ff;"></i> Admin
      </a>
    </li>

    <!-- Students -->
    <li class="nav-item mb-2<?= $teacher_disabled_class ?>">
      <a class="nav-link d-flex align-items-center text-dark load-page" href="students/index.php">
        <i class="bi bi-mortarboard me-2" style="font-weight:700; color:#8e44ad;"></i> Students
      </a>
    </li>

    <!-- Subjects -->
    <li class="nav-item mb-2<?= $teacher_disabled_class ?>">
      <a class="nav-link d-flex align-items-center text-dark load-page" href="subjects/index.php">
        <i class="bi bi-journal-text me-2" style="font-weight:700; color:#27ae60;"></i> Subjects
      </a>
    </li>

    <!-- Teachers -->
    <li class="nav-item mb-2<?= $teacher_disabled_class ?>">
      <a class="nav-link d-flex align-items-center text-dark load-page" href="teachers/index.php">
        <i class="bi bi-person-badge me-2" style="font-weight:700; color:#f1c40f;"></i> Teachers
      </a>
    </li>

    <!-- Classes -->
    <li class="nav-item mb-2<?= $teacher_disabled_class ?>">
      <a class="nav-link d-flex align-items-center text-dark load-page" href="./classes/index.php">
        <i class="bi bi-diagram-3 me-2" style="font-weight:700; color:#e67e22;"></i> Classes
      </a>
    </li>

    <!-- Exams – always enabled for teachers -->
    <li class="nav-item mb-2">
      <a class="nav-link d-flex align-items-center text-dark load-page" href="exams/index.php">
        <i class="bi bi-pencil-square me-2" style="font-weight:700; color:#e74c3c;"></i> Exams
      </a>
    </li>

    <!-- Analysis & Reports -->
    <li class="nav-item mb-2<?= $teacher_disabled_class ?>">
      <a class="nav-link d-flex align-items-center text-dark load-page" href="reports/index.php">
        <i class="bi bi-graph-up me-2" style="font-weight:700; color:#3498db;"></i> Analysis & Reports
      </a>
    </li>

    <!-- Messaging -->
    <li class="nav-item mb-2<?= $teacher_disabled_class ?>">
      <a class="nav-link d-flex align-items-center text-dark load-page" href="messaging/index.php">
        <i class="bi bi-chat-dots me-2" style="font-weight:700; color:#3498db;"></i> Messaging
      </a>
    </li>

    <!-- CBC Analysis -->
    <li class="nav-item mb-2<?= $teacher_disabled_class ?>">
      <a class="nav-link d-flex align-items-center text-dark load-page" href="cbcanalysis/index.php">
        <i class="bi bi-grid-1x2 me-2" style="font-weight:700; color:#6f42c1;"></i>
        CBC Analysis
      </a>
    </li>

    <!-- Timetable – enabled only for special teachers -->
    <li class="nav-item mb-2<?= $teacher_disabled_class && !$enable_timetable ? ' disabled-menu-item' : '' ?>">
      <a class="nav-link d-flex align-items-center text-dark load-page" href="timetables/index.php">
        <i class="bi bi-calendar-check me-2" style="font-weight:700; color:#6f42c1;"></i>
        Timetable
      </a>
    </li>

    <!-- Support -->
    <li class="nav-item mb-2<?= $teacher_disabled_class ?>">
      <a class="nav-link d-flex align-items-center text-dark load-page" href="support/index.php">
        <i class="bi bi-headset me-2" style="font-weight:700; color:#6f42c1;"></i>
        Support
      </a>
    </li>

  </ul>

  <!-- Optional debug (remove in production) -->
  <!--
  <div class="mt-4 pt-3 small text-muted border-top">
    User: <?= htmlspecialchars($user_id ?? '—') ?><br>
    School: <?= htmlspecialchars($school_id ?? '—') ?><br>
    Role: <?= htmlspecialchars($role_name) ?><br>
    Timetable enabled: <?= $enable_timetable ? 'Yes' : 'No' ?>
  </div>
  -->

</div>

<style>
  .sidebar {
    position: fixed;
    top: 56px;
    left: 0;
    bottom: 0;
    min-width: 220px;
    max-width: 220px;
    height: calc(100vh - 56px);
    overflow-y: auto;
    background: #fff;
    border-right: 1px solid #dee2e6;
    transition: all 0.3s;
    z-index: 1020;
  }

  .sidebar .nav-link {
    font-size: 0.95rem;
    padding: 8px 12px;
    border-radius: 6px;
    transition: all 0.2s;
  }

  .sidebar .nav-link i {
    font-size: 1.1rem;
  }

  .sidebar .nav-link:hover {
    background-color: transparent;
    color: #0E66B2 !important;
    border: 2px solid #0E66B2;
  }

  .sidebar .nav-link.active {
    background-color: #0d6efd;
    color: #fff !important;
    font-weight: 500;
  }

  /* Disabled menu items for teachers */
  .disabled-menu-item .nav-link,
  .disabled-menu-item .nav-link:hover {
    color: #adb5bd !important;
    pointer-events: none;
    cursor: not-allowed;
    opacity: 0.6;
    border: none !important;
  }

  .sidebar.collapsed {
    margin-left: -220px;
  }

  .content {
    margin-left: 220px;
    padding: 20px;
  }

  .sidebar.collapsed~.content {
    margin-left: 0;
  }
</style>