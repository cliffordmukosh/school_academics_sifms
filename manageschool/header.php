<?php
// header.php
session_start();
require __DIR__ . '/../connection/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: /schoolacademics/index.php");
    exit;
}

// Fetch school details
$school = [];
$stmt = $conn->prepare("SELECT name, logo FROM schools WHERE school_id = ?");
$stmt->bind_param("i", $_SESSION['school_id']);
$stmt->execute();
$result = $stmt->get_result();
$school = $result->fetch_assoc();
$stmt->close();

// Clean the stored logo value (in case "logos/" is already in DB)
$logoFile = basename($school['logo'] ?? '');

// Fallback if no school logo
if (!empty($logoFile)) {
    $school_logo = 'logos/' . htmlspecialchars($logoFile);
} else {
    $school_logo = 'logos/school-logo.png';
}

// Fetch user details
$user_name = htmlspecialchars($_SESSION['full_name'] . ' ' . ($_SESSION['other_names'] ?? ''));

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($school['name'] ?? 'School Management System'); ?></title>
    <!-- Base URL -->
    <base href="/online/schoolacademics/manageschool/">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
    <!-- html2pdf.js -->
    <script src="https://cdn.jsdelivr.net/npm/html2pdf.js@0.9.3/dist/html2pdf.bundle.min.js"></script>
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
            min-height: 100vh;
            margin: 0;
            background: linear-gradient(135deg, var(--light) 0%, rgba(255, 255, 255, 0.9) 100%);
            font-size: 15px;
        }

        .wrapper {
            display: flex;
            min-height: 100vh;
            flex-direction: column;
        }

        /* Sticky Header */
        .top-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1030;
            height: 60px;
            background: linear-gradient(90deg, var(--navy), var(--primary));
            color: var(--light);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            display: flex;
            align-items: center;
            padding: 0 1.5rem;
        }

        .header-logo img {
            height: 45px;
            margin-right: 12px;
            border-radius: 5px;
            transition: transform 0.3s ease;
        }
        .header-logo img:hover {
            transform: scale(1.1);
        }

        .header-logo h5 {
            font-weight: 600;
            letter-spacing: 1px;
            margin: 0;
        }

        .sidebar-toggle {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: var(--light);
            transition: background 0.3s ease, transform 0.3s ease;
        }
        .sidebar-toggle:hover {
            background: var(--teal);
            transform: rotate(90deg);
        }

        .user-greeting {
            font-size: 0.95rem;
            font-weight: 400;
            margin-right: 1rem;
        }

        .dropdown-menu {
            background: rgba(255, 255, 255, 0.95);
            border: none;
            border-radius: 10px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
            min-width: 200px;
            margin-top: 10px;
        }

        .dropdown-item {
            color: var(--navy);
            font-weight: 500;
            padding: 0.75rem 1.5rem;
            transition: background 0.3s ease, color 0.3s ease;
        }
        .dropdown-item:hover {
            background: var(--teal);
            color: var(--light);
        }

        .dropdown-item i {
            margin-right: 8px;
        }

        /* Main Content */
        .main {
            flex: 1;
            display: flex;
            margin-top: 60px; /* Match header height */
        }

        .sidebar {
            min-width: 250px;
            max-width: 250px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
            transition: margin-left 0.3s ease;
            padding: 1rem;
        }

        .sidebar.collapsed {
            margin-left: -250px;
        }

        .content {
            flex: 1;
            padding: 2rem;
            background: var(--light);
            transition: margin-left 0.3s ease;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .sidebar {
                position: fixed;
                height: calc(100vh - 60px);
                z-index: 1020;
                margin-left: -250px;
            }
            .sidebar.collapsed {
                margin-left: -250px;
            }
            .sidebar:not(.collapsed) {
                margin-left: 0;
            }
            .content {
                margin-left: 0;
            }
            .header-logo h5 {
                font-size: 1rem;
            }
            .user-greeting {
                font-size: 0.85rem;
            }
        }
    </style>
</head>
<body>
<div class="wrapper">
    <!-- Top Header -->
    <header class="top-header d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center header-logo">
            <button class="btn sidebar-toggle btn-sm me-3" id="sidebarToggle"><i class="bi bi-list"></i></button>
            <img src="<?php echo $school_logo; ?>" alt="School Logo">
            <h5><?php echo htmlspecialchars($school['name'] ?? 'My School System'); ?></h5>
        </div>
        <div class="d-flex align-items-center">
            <span class="user-greeting">Welcome, <?php echo $user_name; ?></span>
            <div class="dropdown">
                <a href="#" class="text-white" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-person-circle fs-4"></i>
                </a>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i> Profile</a></li>
                    <li><a class="dropdown-item" href="change_password.php"><i class="bi bi-key me-2"></i> Change Password</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
                </ul>
            </div>
        </div>
    </header>

    <div class="main">
        <!-- Sidebar and content will be included in other files -->
