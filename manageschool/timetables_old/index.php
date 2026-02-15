<?php
require_once __DIR__ . '/../../apis/auth_middleware.php';
require_once __DIR__ . '/../header.php';
require_once __DIR__ . '/../sidebar.php';
?>

<style>
    .content {
        margin-left: 260px;
        padding: 24px;
    }
</style>

<div class="content">
    <div class="container-fluid">

        <div class="mb-4">
            <h3 class="fw-bold">
                <i class="bi bi-calendar-week me-2"></i> Timetables
            </h3>
            <p class="text-muted mb-0">
                View, edit, and print school timetables.
            </p>
        </div>

        <div class="row g-4">

            <div class="col-md-4">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-body text-center p-4">
                        <i class="bi bi-pencil-square display-5 text-primary mb-3"></i>
                        <h5 class="fw-semibold">Edit Timetable</h5>
                        <p class="text-muted">
                            Create and modify class timetables.
                        </p>
                        <a href="timetables/edit.php" class="btn btn-primary">
                            Manage
                        </a>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-body text-center p-4">
                        <i class="bi bi-person-badge display-5 text-success mb-3"></i>
                        <h5 class="fw-semibold">Teacher Timetables</h5>
                        <p class="text-muted">
                            View teacher schedules.
                        </p>
                        <a href="timetables/teacher_print.php" class="btn btn-success">
                            View
                        </a>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-body text-center p-4">
                        <i class="bi bi-book display-5 text-warning mb-3"></i>
                        <h5 class="fw-semibold">Subject Timetables</h5>
                        <p class="text-muted">
                            View subject allocations.
                        </p>
                        <a href="timetables/subject_print.php" class="btn btn-warning">
                            View
                        </a>
                    </div>
                </div>
            </div>

        </div>

    </div>
</div>

<?php require_once __DIR__ . '/../footer.php'; ?>
