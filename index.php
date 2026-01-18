<?php
session_start();
require './connection/db.php';

// If already logged in → redirect
if (isset($_SESSION['user_id'])) {
    header("Location: ./manageschool/index.php");
    exit;
}

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login']);
    $password = $_POST['password'];

    // Fetch user by username or email
    $stmt = $conn->prepare("
        SELECT u.user_id, u.school_id, u.first_name, u.email, u.username, u.password_hash, u.status, 
               r.role_id, r.role_name
        FROM users u
        LEFT JOIN roles r ON u.role_id = r.role_id
        WHERE u.email = ? OR u.username = ?
        LIMIT 1
    ");
    $stmt->bind_param("ss", $login, $login);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if ($user && password_verify($password, $user['password_hash'])) {
        if ($user['status'] !== 'active') {
            $error = "Your account is not active. Please contact admin.";
        } else {
            // Load permissions
            $perm_stmt = $conn->prepare("
                SELECT p.name
                FROM role_permissions rp
                JOIN permissions p ON rp.permission_id = p.permission_id
                WHERE rp.role_id = ?
            ");
            $perm_stmt->bind_param("i", $user['role_id']);
            $perm_stmt->execute();
            $perm_result = $perm_stmt->get_result();

            $permissions = [];
            while ($row = $perm_result->fetch_assoc()) {
                $permissions[] = $row['name'];
            }
            $perm_stmt->close();

            // Store session
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['school_id'] = $user['school_id'];
            $_SESSION['full_name'] = $user['first_name'];
            $_SESSION['role_id'] = $user['role_id'];
            $_SESSION['role_name'] = $user['role_name'];
            $_SESSION['permissions'] = $permissions;

            header("Location: ./manageschool/index.php");
            exit;
        }
    } else {
        $error = "Invalid username/email or password.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Login - SIFMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #007bff; /* Bootstrap primary */
            --teal: #20c997; /* Modern teal */
            --navy: #1a1f71; /* Dark navy */
            --coral: #ff6b6b; /* Vibrant coral */
            --dark: #212529; /* Dark background */
            --light: #f8f9fa; /* Light background */
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
            background-color: var(--navy);
            color: var(--light);
            padding: 1.5rem 0;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
        }

        .header h1 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 600;
            letter-spacing: 1px;
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

        .login-container {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }

        .card {
            background: rgba(255, 255, 255, 0.95);
            border: none;
            border-radius: 15px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
            padding: 2.5rem;
            max-width: 450px;
            width: 100%;
            transition: transform 0.3s ease;
        }
        .card:hover {
            transform: translateY(-5px);
        }

        .card h2 {
            color: var(--navy);
            font-weight: 600;
            margin-bottom: 1.5rem;
        }

        .form-control {
            border-radius: 8px;
            border: 1px solid var(--primary);
            padding: 0.75rem;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }
        .form-control:focus {
            border-color: var(--teal);
            box-shadow: 0 0 8px rgba(32, 201, 151, 0.3);
        }

        .btn-primary {
            background: linear-gradient(90deg, var(--primary), var(--teal));
            border: none;
            border-radius: 8px;
            padding: 0.75rem;
            font-weight: 500;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 123, 255, 0.3);
        }

        .btn-outline-secondary {
            border-color: var(--dark);
            color: var(--dark);
            border-radius: 8px;
            padding: 0.75rem;
            font-weight: 500;
            transition: background-color 0.3s ease, color 0.3s ease, transform 0.3s ease;
        }
        .btn-outline-secondary:hover {
            background-color: var(--coral);
            color: var(--light);
            transform: translateY(-2px);
        }

        .alert-danger {
            background-color: rgba(255, 107, 107, 0.9);
            color: var(--light);
            border: none;
            border-radius: 8px;
            padding: 0.75rem;
            margin-bottom: 1.5rem;
        }

        .footer {
            background-color: var(--navy);
            color: var(--light);
            padding: 1rem 0;
            text-align: center;
            font-size: 0.9rem;
        }

        .form-label {
            color: var(--navy);
            font-weight: 500;
        }

        @media (max-width: 576px) {
            .header h1 {
                position: static;
                font-size: 1rem;
                text-align: left;
                margin: 0 0 0 0.5rem;
                white-space: normal;
                transform: none;
            }

            .card {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
<!-- Header -->
<header class="header position-relative">
    <div class="container-fluid d-flex justify-content-between align-items-center px-3">
        <!-- Far Left: Logo + SIFMS + Services link -->
        <div class="d-flex align-items-center">
            <img src="logo.png" alt="SIFMS Logo" style="height: 40px;" class="me-2">
            <span class="fw-bold me-3">SIFMS</span>
            <a href="https://sifms.co.ke/services/index.php" class="apps-link text-white fs-5 ms-1" title="Our Services">
                <i class="bi bi-grid-3x3-gap-fill"></i>
            </a>
        </div>
        <!-- Center: System Name -->
        <h1 class="position-absolute top-50 start-50 translate-middle text-center mb-0">
            Academics
        </h1>
    </div>
</header>

<!-- Login Form -->
<div class="login-container">
    <div class="card">
        <h2 class="text-center">School Login</h2>
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger text-center"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="post" novalidate>
            <div class="mb-3">
                <label class="form-label">Username or Email</label>
                <input type="text" name="login" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <div class="row g-2">
                <div class="col-6">
                    <button type="submit" class="btn btn-primary w-100 d-flex align-items-center justify-content-center">
                        <i class="bi bi-box-arrow-in-right me-2"></i> Login
                    </button>
                </div>
                <div class="col-6">
                    <a href="register.php" class="btn btn-outline-secondary w-100 d-flex align-items-center justify-content-center">
                        <i class="bi bi-person-plus me-2"></i> Register
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Footer -->
<footer class="footer">
    <div class="container">
        <p>&copy; <?php echo date('Y'); ?> School Management System. All rights reserved.</p>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>