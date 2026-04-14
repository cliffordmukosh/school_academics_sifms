<?php
$host = "localhost";   
$user = "root";        
$pass = "";
$port = 3309;
$db   = "demoschooldb";
// $db   = "test_schoolsys";
// $db   = "schoolacademics";


$conn = new mysqli($host, $user, $pass, $db, $port);

// Check connection
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}
?>
