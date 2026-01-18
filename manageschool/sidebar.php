<?php
// sidebar.php
?>
<!-- Sidebar -->
<!-- Sidebar -->
<div class="sidebar bg-white border-end p-3" id="sidebar">
  <h6 class="text-uppercase text-muted fw-bold mb-3">Menu</h6>
  <ul class="nav flex-column">
    <li class="nav-item mb-2">
      <a class="nav-link d-flex align-items-center text-dark load-page" href="#" style="color:black;">
        <i class="bi bi-speedometer2 me-2" style="font-weight:700; color:#ff5733;"></i> Dashboard
      </a>
    </li>
    <li class="nav-item mb-2">
      <a class="nav-link d-flex align-items-center text-dark load-page" href="admin/index.php">
        <i class="bi bi-shield-lock me-2" style="font-weight:700; color:#33c1ff;"></i> Admin
      </a>
    </li>
    <li class="nav-item mb-2">
      <a class="nav-link d-flex align-items-center text-dark load-page" href="students/index.php">
        <i class="bi bi-mortarboard me-2" style="font-weight:700; color:#8e44ad;"></i> Students
      </a>
    </li>
    <li class="nav-item mb-2">
      <a class="nav-link d-flex align-items-center text-dark load-page" href="subjects/index.php">
        <i class="bi bi-journal-text me-2" style="font-weight:700; color:#27ae60;"></i> Subjects
      </a>
    </li>
    <li class="nav-item mb-2">
      <a class="nav-link d-flex align-items-center text-dark load-page" href="teachers/index.php">
        <i class="bi bi-person-badge me-2" style="font-weight:700; color:#f1c40f;"></i> Teachers
      </a>
    </li>
    <li class="nav-item mb-2">
      <a class="nav-link d-flex align-items-center text-dark load-page" href="./classes/index.php">
        <i class="bi bi-diagram-3 me-2" style="font-weight:700; color:#e67e22;"></i> Classes
      </a>
    </li>
    <li class="nav-item mb-2">
      <a class="nav-link d-flex align-items-center text-dark load-page" href="exams/index.php">
        <i class="bi bi-pencil-square me-2" style="font-weight:700; color:#e74c3c;"></i> Exams
      </a>
    </li>
    <li class="nav-item mb-2">
      <a class="nav-link d-flex align-items-center text-dark load-page" href="reports/index.php">
        <i class="bi bi-graph-up me-2" style="font-weight:700; color:#3498db;"></i> Analysis & Reports
      </a>
    </li>

    <li class="nav-item mb-2">
      <a class="nav-link d-flex align-items-center text-dark load-page" href="messaging/index.php">
        <i class="bi bi-chat-dots me-2" style="font-weight:700; color:#3498db;"></i> Messaging
      </a>
    </li>
    <li class="nav-item mb-2">
      <a class="nav-link d-flex align-items-center text-dark load-page" href="cbcanalysis/index.php">
        <i class="bi bi-calendar-week me-2" style="font-weight:700; color:#6f42c1;"></i> CBC Analysis
      </a>
    </li>

    <li class="nav-item mb-2">
      <a class="nav-link d-flex align-items-center text-dark load-page" href="timetable/index.php">
        <i class="bi bi-calendar-week me-2" style="font-weight:700; color:#6f42c1;"></i> Timetable
      </a>
    </li>

    <li class="nav-item mb-2">
      <a class="nav-link d-flex align-items-center text-dark load-page" href="timetable/index.php">
        <i class="bi bi-calendar-week me-2" style="font-weight:700; color:#6f42c1;"></i>KCSE Analysis
      </a>
    </li>


  </ul>
</div>


<style>
  .sidebar {
    position: fixed;
    /* make it sticky */
    top: 56px;
    /* height of header */
    left: 0;
    bottom: 0;
    min-width: 220px;
    max-width: 220px;
    height: calc(100vh - 56px);
    /* full height minus header */
    overflow-y: auto;
    /* scroll inside if too many items */
    background: #fff;
    border-right: 1px solid #dee2e6;
    transition: all 0.3s;
    z-index: 1020;
    /* below header */
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
    /* no background */
    color: #0E66B2 !important;
    /* optional: text color on hover */
    border: 2px solid #0E66B2;
    /* solid border on hover */
  }


  .sidebar .nav-link.active {
    background-color: #0d6efd;
    color: #fff !important;
    font-weight: 500;
  }

  .sidebar.collapsed {
    margin-left: -220px;
  }

  /* content area should push right of sidebar */
  .content {
    margin-left: 220px;
    padding: 20px;
  }

  /* When sidebar is collapsed, content should expand full width */
  .sidebar.collapsed~.content {
    margin-left: 0;
  }
</style>