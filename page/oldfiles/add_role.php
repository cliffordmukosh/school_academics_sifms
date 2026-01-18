<?php
session_start();
require './connection/db.php';

if (!isset($_SESSION['user_id'], $_SESSION['school_id'])) {
    header("Location: login.php"); exit;
}

$school_id = (int)$_SESSION['school_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $role_name = trim($_POST['role_name'] ?? '');
    if ($role_name === '') {
        header("Location: dashboard.php?err=role_name"); exit;
    }

    // Insert role for this school
    $stmt = $conn->prepare("INSERT INTO roles (school_id, role_name) VALUES (?, ?)");
    $stmt->bind_param("is", $school_id, $role_name);
    if (!$stmt->execute()) {
        // likely duplicate role name per school
        header("Location: dashboard.php?err=role_dup"); exit;
    }
    $role_id = $stmt->insert_id;
    $stmt->close();

    // Optional: Attach permissions submitted alongside role creation (checkboxes named permissions[])
    if (!empty($_POST['permissions']) && is_array($_POST['permissions'])) {
        $ins = $conn->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id, school_id) VALUES (?, ?, ?)");
        foreach ($_POST['permissions'] as $pid) {
            $pid = (int)$pid;
            $ins->bind_param("iii", $role_id, $pid, $school_id);
            $ins->execute();
        }
        $ins->close();
    }

    header("Location: dashboard.php?ok=role_added"); exit;
}

header("Location: dashboard.php"); exit;
