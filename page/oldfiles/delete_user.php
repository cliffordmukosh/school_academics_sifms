<?php
session_start();
require './connection/db.php';

if (!isset($_SESSION['user_id'], $_SESSION['school_id'])) {
    header("Location: login.php"); exit;
}

$school_id = (int)$_SESSION['school_id'];
$user_id = (int)($_GET['id'] ?? 0);

// Prevent deleting yourself (optional)
if ($user_id === (int)$_SESSION['user_id']) {
    header("Location: dashboard.php?err=cannot_delete_self"); exit;
}

$stmt = $conn->prepare("DELETE FROM users WHERE user_id = ? AND school_id = ?");
$stmt->bind_param("ii", $user_id, $school_id);
$stmt->execute();
$stmt->close();

header("Location: dashboard.php?ok=user_deleted"); exit;
