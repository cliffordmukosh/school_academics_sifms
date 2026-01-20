<?php
include './connection/db.php';

$error = null;
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $school_name   = trim($_POST['school_name'] ?? '');
    $school_email  = trim($_POST['school_email'] ?? '');
    $school_phone  = trim($_POST['school_phone'] ?? '');
    $address       = trim($_POST['address'] ?? '');
    $user_name     = trim($_POST['user_name'] ?? '');
    $user_email    = trim($_POST['user_email'] ?? '');
    $username      = trim($_POST['username'] ?? '');
    $user_password = $_POST['user_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (
        empty($school_name) || empty($school_email) || empty($user_name) || empty($username) ||
        empty($user_email) || empty($user_password) || empty($confirm_password)
    ) {
        $error = "Please fill all required fields!";
    } elseif ($user_password !== $confirm_password) {
        $error = "Passwords do not match!";
    } else {
        $formatted_username = $username . '@' . preg_replace('/[^A-Za-z0-9]/', '', strtolower($school_name));

        $logo = null;
        if (!empty($_FILES['logo']['name'])) {
            $target_dir = __DIR__ . "/manageschool/logos/";
            $public_dir = "manageschool/logos/";

            if (!is_dir($target_dir)) {
                mkdir($target_dir, 0777, true);
            }

            $filename = time() . "_" . basename($_FILES["logo"]["name"]);
            if (move_uploaded_file($_FILES["logo"]["tmp_name"], $target_dir . $filename)) {
                $logo = $public_dir . $filename;
            }
        }

        $conn->begin_transaction();

        try {
            // 1. Insert school
            $stmt = $conn->prepare("INSERT INTO schools (name, address, email, phone, logo) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss", $school_name, $address, $school_email, $school_phone, $logo);
            $stmt->execute();
            $school_id = $stmt->insert_id;
            $stmt->close();

            // 2. Default roles
            $default_roles = ['Admin', 'Teacher'];
            $role_ids = [];
            $stmt = $conn->prepare("INSERT INTO roles (school_id, role_name) VALUES (?, ?)");
            foreach ($default_roles as $role_name) {
                $stmt->bind_param("is", $school_id, $role_name);
                $stmt->execute();
                $role_ids[$role_name] = $stmt->insert_id;
            }
            $stmt->close();

            // 3. Assign all permissions to Admin
            $stmt = $conn->prepare("SELECT permission_id FROM permissions");
            $stmt->execute();
            $permissions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $stmt = $conn->prepare("INSERT INTO role_permissions (role_id, permission_id, school_id) VALUES (?, ?, ?)");
            foreach ($permissions as $perm) {
                $stmt->bind_param("iii", $role_ids['Admin'], $perm['permission_id'], $school_id);
                $stmt->execute();
            }
            $stmt->close();

            // 4. Create first admin user
            $password_hash = password_hash($user_password, PASSWORD_BCRYPT);
            $stmt = $conn->prepare("INSERT INTO users (school_id, role_id, first_name, username, email, password_hash) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iissss", $school_id, $role_ids['Admin'], $user_name, $formatted_username, $user_email, $password_hash);
            $stmt->execute();
            $stmt->close();


            // 5. Default classes with CBC flag (Grade 10 & 11 = CBC, Form 3 & 4 = 8-4-4)
            $default_classes = [
                ['Grade 10', 'First year of secondary school', 1],
                ['Grade 11', 'Second year of secondary school', 1],
                ['Form 3',   'Third year of secondary school',  0],
                ['Form 4',   'Fourth year of secondary school', 0],
            ];

            $stmt = $conn->prepare("
    INSERT INTO classes 
    (school_id, form_name, description, is_cbc) 
    VALUES (?, ?, ?, ?)
");

            foreach ($default_classes as $class) {
                $stmt->bind_param("issi", $school_id, $class[0], $class[1], $class[2]);
                $stmt->execute();
            }
            $stmt->close();

            // 6. Default subjects (both curricula - transition friendly)
            $default_subjects = [
                // 8-4-4 / non-CBC
                ['Mathematics',               'compulsory', 'MAT', 121, 0],
                ['English',                   'compulsory', 'ENG', 101, 0],
                ['Kiswahili',                 'compulsory', 'KIS', 102, 0],
                ['Biology',                   'compulsory', 'BIO', 231, 0],
                ['Physics',                   'compulsory', 'PHY', 232, 0],
                ['Chemistry',                 'compulsory', 'CHE', 233, 0],
                ['History and Government',    'elective',   'HIS', 311, 0],
                ['Geography',                 'elective',   'GEO', 312, 0],
                ['C.R.E.',                    'elective',   'CRE', 313, 0],
                ['Business Studies',          'elective',   'BST', 565, 0],
                ['Computer Studies',          'elective',   'COMP', 451, 0],
                ['Agriculture',               'elective',   'AGR', 443, 0],


                // CBC / KJSEA
                ['English',                   'compulsory', 'ENG', 901, 1],
                ['Kiswahili',                 'compulsory', 'KIS', 902, 1],
                ['Mathematics',               'compulsory', 'MAT', 903, 1],
                ['Integrated Science',        'elective',   'ISC', 905, 1],
                ['Agriculture',               'elective',   'AGR', 906, 1],
                ['Social Studies',            'elective',   'SST', 907, 1],
                ['Islamic Religious Education', 'elective',   'IRE', 909, 1],
                ['Creative Arts & Sports',    'elective',   'CAS', 911, 1],
                ['Pre-technical Studies',     'elective',   'PTS', 912, 1],
            ];

            $stmt = $conn->prepare("
                INSERT INTO subjects 
                (school_id, name, type, subject_initial, subject_code, is_cbc, is_global) 
                VALUES (?, ?, ?, ?, ?, ?, FALSE)
            ");

            foreach ($default_subjects as $sub) {
                $stmt->bind_param("isssii", $school_id, $sub[0], $sub[1], $sub[2], $sub[3], $sub[4]);
                $stmt->execute();
            }
            $stmt->close();

            // 7. Seed ONLY TWO grading systems

            // 1. Default 8-4-4 Grading (set as default)
            $stmt = $conn->prepare("SELECT grading_system_id FROM grading_systems WHERE school_id = ? AND name = 'Default 8-4-4 Grading' LIMIT 1");
            $stmt->bind_param("i", $school_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $default_grading_id = $result->num_rows > 0 ? $result->fetch_assoc()['grading_system_id'] : null;
            $stmt->close();

            if (!$default_grading_id) {
                $stmt = $conn->prepare("INSERT INTO grading_systems (school_id, name, is_default) VALUES (?, 'Default 8-4-4 Grading', TRUE)");
                $stmt->bind_param("i", $school_id);
                $stmt->execute();
                $default_grading_id = $stmt->insert_id;
                $stmt->close();

                $default_grades = [
                    ['A',   80, 100, 12, 'Excellent', 0],
                    ['A-',  75,  79, 11, 'Very Good', 0],
                    ['B+',  70,  74, 10, 'Good',      0],
                    ['B',   65,  69,  9, 'Above Average', 0],
                    ['B-',  60,  64,  8, 'Good',      0],
                    ['C+',  55,  59,  7, 'Average',   0],
                    ['C',   50,  54,  6, 'Fair',      0],
                    ['C-',  45,  49,  5, 'Below Average', 0],
                    ['D+',  40,  44,  4, 'Weak',      0],
                    ['D',   35,  39,  3, 'Poor',      0],
                    ['D-',  30,  34,  2, 'Very Poor', 0],
                    ['E',    0,  29,  1, 'Fail',      0],
                ];

                $stmt = $conn->prepare("
                    INSERT IGNORE INTO grading_rules 
                    (grading_system_id, grade, min_score, max_score, points, description, is_cbc)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");

                foreach ($default_grades as $g) {
                    $stmt->bind_param("isdddsi", $default_grading_id, $g[0], $g[1], $g[2], $g[3], $g[4], $g[5]);
                    $stmt->execute();
                }
                $stmt->close();
            }

            // 2. New KJSEA Grading System (CBC by KNEC) - exact levels you requested
            $stmt = $conn->prepare("SELECT grading_system_id FROM grading_systems WHERE school_id = ? AND name = 'New KJSEA Grading System (CBC)' LIMIT 1");
            $stmt->bind_param("i", $school_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $cbc_grading_id = $result->num_rows > 0 ? $result->fetch_assoc()['grading_system_id'] : null;
            $stmt->close();

            if (!$cbc_grading_id) {
                $stmt = $conn->prepare("INSERT INTO grading_systems (school_id, name, is_default) VALUES (?, 'New KJSEA Grading System (CBC)', FALSE)");
                $stmt->bind_param("i", $school_id);
                $stmt->execute();
                $cbc_grading_id = $stmt->insert_id;
                $stmt->close();

                $cbc_grades = [
                    ['EE1', 90, 100, 8, 'Exceptional',           1],
                    ['EE2', 75,  89, 7, 'Very Good',             1],
                    ['ME1', 58,  74, 6, 'Good',                  1],
                    ['ME2', 41,  57, 5, 'Fair',                  1],
                    ['AE1', 31,  40, 4, 'Needs Improvement',     1],
                    ['AE2', 21,  30, 3, 'Below Average',         1],
                    ['BE1', 11,  20, 2, 'Well Below Average',    1],
                    ['BE2',  1,  10, 1, 'Minimal',               1],
                ];

                $stmt = $conn->prepare("
                    INSERT IGNORE INTO grading_rules 
                    (grading_system_id, grade, min_score, max_score, points, description, is_cbc)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");

                foreach ($cbc_grades as $g) {
                    $stmt->bind_param("isdddsi", $cbc_grading_id, $g[0], $g[1], $g[2], $g[3], $g[4], $g[5]);
                    $stmt->execute();
                }
                $stmt->close();
            }

            // 8. Subject papers (only for 8-4-4 subjects)
            $papers = [
                [$school_id, 'Mathematics',               'Paper 1', 100, 50],
                [$school_id, 'Mathematics',               'Paper 2', 100, 50],
                [$school_id, 'English',                   'Paper 1',  60, 30],
                [$school_id, 'English',                   'Paper 2',  80, 40],
                [$school_id, 'English',                   'Paper 3',  80, 30],
                [$school_id, 'Kiswahili',                 'Paper 1',  40, 20],
                [$school_id, 'Kiswahili',                 'Paper 2',  80, 40],
                [$school_id, 'Kiswahili',                 'Paper 3',  80, 40],
                [$school_id, 'Biology',                   'Paper 1',  80, 40],
                [$school_id, 'Biology',                   'Paper 2',  80, 40],
                [$school_id, 'Biology',                   'Paper 3',  40, 20],
                [$school_id, 'Physics',                   'Paper 1',  80, 40],
                [$school_id, 'Physics',                   'Paper 2',  80, 40],
                [$school_id, 'Physics',                   'Paper 3',  40, 20],
                [$school_id, 'Chemistry',                 'Paper 1',  80, 40],
                [$school_id, 'Chemistry',                 'Paper 2',  80, 40],
                [$school_id, 'Chemistry',                 'Paper 3',  40, 20],
                [$school_id, 'History and Government',    'Paper 1', 100, 50],
                [$school_id, 'History and Government',    'Paper 2', 100, 50],
                [$school_id, 'Geography',                 'Paper 1', 100, 50],
                [$school_id, 'Geography',                 'Paper 2', 100, 50],
                [$school_id, 'C.R.E.',                    'Paper 1', 100, 50],
                [$school_id, 'C.R.E.',                    'Paper 2', 100, 50],
                [$school_id, 'Agriculture',               'Paper 1',  90, 50],
                [$school_id, 'Agriculture',               'Paper 2',  90, 50],
                [$school_id, 'Business Studies',          'Paper 1', 100, 50],
                [$school_id, 'Business Studies',          'Paper 2', 100, 50],
                [$school_id, 'Computer Studies',          'Paper 1', 100, 50],
                [$school_id, 'Computer Studies',          'Paper 2', 100, 50],
            ];

            $stmt = $conn->prepare("
                INSERT INTO subject_papers 
                (school_id, subject_id, paper_name, max_score, contribution_percentage) 
                VALUES (?, 
                    (SELECT subject_id FROM subjects WHERE school_id = ? AND name = ? LIMIT 1), 
                    ?, ?, ?
                )
            ");

            foreach ($papers as $paper) {
                $stmt->bind_param("iisssd", $paper[0], $paper[0], $paper[1], $paper[2], $paper[3], $paper[4]);
                $stmt->execute();
            }
            $stmt->close();

            $conn->commit();
            $success = true;
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Error registering school: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register School - SIFMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #007bff;
            --teal: #20c997;
            --navy: #1a1f71;
            --coral: #ff6b6b;
            --dark: #212529;
            --light: #f8f9fa;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, var(--primary) 0%, var(--teal) 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            margin: 0;
        }

        .header {
            background: linear-gradient(90deg, var(--navy), var(--primary));
            color: var(--light);
            padding: 0.5rem 0;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        .header .container {
            max-width: 100%;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header .logo-container {
            display: flex;
            align-items: center;
        }

        .header img {
            height: 28px;
            margin-right: 0.5rem;
            transition: transform 0.3s ease;
        }

        .header img:hover {
            transform: scale(1.1);
        }

        .header h1 {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 600;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }

        .apps-link {
            transition: transform 0.3s ease, color 0.3s ease;
        }

        .apps-link:hover {
            color: var(--teal);
            transform: scale(1.2);
        }

        .apps-link:active {
            color: var(--coral);
            transform: scale(0.9);
        }

        .container {
            max-width: 800px;
            margin: 1.5rem auto;
            flex: 1;
        }

        .card {
            background: rgba(255, 255, 255, 0.95);
            border: none;
            border-radius: 15px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
            padding: 1.5rem;
            transition: transform 0.3s ease;
        }

        .card:hover {
            transform: translateY(-5px);
        }

        .card h2 {
            color: var(--navy);
            font-weight: 600;
            margin-bottom: 1.2rem;
            font-size: 1.5rem;
        }

        .card h5 {
            color: var(--navy);
            font-weight: 500;
            margin-bottom: 1rem;
            font-size: 1.1rem;
        }

        .form-control {
            border-radius: 8px;
            border: 1px solid var(--primary);
            padding: 0.6rem;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }

        .form-control:focus {
            border-color: var(--teal);
            box-shadow: 0 0 8px rgba(32, 201, 151, 0.3);
        }

        .form-label {
            color: var(--navy);
            font-weight: 500;
            font-size: 0.9rem;
        }

        .btn-success {
            background: linear-gradient(90deg, var(--primary), var(--teal));
            border: none;
            border-radius: 8px;
            padding: 0.6rem 1.5rem;
            font-weight: 500;
            font-size: 1rem;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 123, 255, 0.3);
        }

        .alert-danger {
            background: var(--coral);
            color: var(--light);
            border: none;
            border-radius: 8px;
            padding: 0.6rem;
            margin-bottom: 1.2rem;
            font-size: 0.9rem;
        }

        .footer {
            background: var(--navy);
            color: var(--light);
            padding: 0.5rem 0;
        }

        .footer .container {
            max-width: 800px;
            font-size: 0.8rem;
            text-align: center;
        }

        .modal-content {
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: none;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }

        .modal-header {
            background: var(--primary);
            color: var(--light);
            border-top-left-radius: 10px;
            border-top-right-radius: 10px;
        }

        .modal-body {
            color: var(--navy);
            font-size: 0.9rem;
        }

        .modal-footer .btn-primary {
            background: var(--teal);
            border: none;
            border-radius: 8px;
            font-size: 0.9rem;
            transition: transform 0.3s ease;
        }

        .modal-footer .btn-primary:hover {
            transform: translateY(-2px);
            background: var(--primary);
        }

        @media (max-width: 576px) {
            .header .container {
                flex-direction: column;
                align-items: center;
                position: relative;
            }

            .header h1 {
                position: static;
                transform: none;
                font-size: 1rem;
                margin-top: 0.5rem;
            }

            .header img {
                height: 24px;
            }

            .container {
                margin: 1rem;
                padding: 0 0.5rem;
            }

            .card {
                padding: 1rem;
            }

            .card h2 {
                font-size: 1.3rem;
            }

            .card h5 {
                font-size: 1rem;
            }
        }
    </style>
</head>

<body>
    <!-- Header -->
    <header class="header position-relative">
        <div class="container d-flex justify-content-between align-items-center px-3">
            <div class="logo-container">
                <img src="logo.png" alt="SIFMS Logo" class="me-2">
                <span class="fw-bold">SIFMS</span>
                <a href="https://sifms.co.ke/services/index.php" class="apps-link text-white fs-5 ms-2" title="Our Services">
                    <i class="bi bi-grid-3x3-gap-fill"></i>
                </a>
            </div>
            <h1>Academics</h1>
        </div>
    </header>

    <!-- Main Content -->
    <div class="container">
        <div class="card">
            <h2 class="text-center">Register Your School</h2>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="p-2">
                            <h5>School Details</h5>
                            <div class="mb-3">
                                <label class="form-label">School Name *</label>
                                <input type="text" name="school_name" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Address</label>
                                <input type="text" name="address" class="form-control">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">School Email *</label>
                                <input type="email" name="school_email" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Phone</label>
                                <input type="text" name="school_phone" class="form-control">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Logo</label>
                                <input type="file" name="logo" class="form-control">
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="p-2">
                            <h5>Your Information</h5>
                            <div class="mb-3">
                                <label class="form-label">Full Name *</label>
                                <input type="text" name="user_name" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Username *</label>
                                <input type="text" name="username" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Email *</label>
                                <input type="email" name="user_email" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Password *</label>
                                <input type="password" name="user_password" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Confirm Password *</label>
                                <input type="password" name="confirm_password" class="form-control" required>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="text-center mt-4">
                    <button type="submit" class="btn btn-success px-5">Register School</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Success Modal -->
    <div class="modal fade" id="successModal" tabindex="-1" aria-labelledby="successModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="successModalLabel">Registration Successful</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Your school has been registered successfully.<br>
                        Thank you for choosing SIFMS.<br>
                        <a href="index.php">Click here to login</a>
                    </p>
                </div>
                <div class="modal-footer">
                    <a href="index.php" class="btn btn-primary">Go to Login</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Error Modal -->
    <div class="modal fade" id="errorModal" tabindex="-1" aria-labelledby="errorModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="errorModalLabel">Registration Error</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p id="errorMessageText"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer mt-auto">
        <div class="container">
            <p class="text-center mb-0">© <?php echo date('Y'); ?> School Management System. All rights reserved.</p>
        </div>
    </footer>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>

    <!-- Modal trigger script -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            <?php if ($success): ?>
                var successModal = new bootstrap.Modal(document.getElementById('successModal'));
                successModal.show();
            <?php endif; ?>

            <?php if ($error): ?>
                document.getElementById('errorMessageText').innerText = <?php echo json_encode($error); ?>;
                var errorModal = new bootstrap.Modal(document.getElementById('errorModal'));
                errorModal.show();
            <?php endif; ?>
        });
    </script>
</body>

</html>