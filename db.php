<?php
// db.php - Connects to MySQL database via phpMyAdmin

$db_host = 'localhost';     // or 127.0.0.1
$db_user = 'root';          // default for XAMPP
$db_pass = '';              // empty for default XAMPP
$db_name = 'lifesaver';     // your database name

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Optional success message for testing only (comment this out in production)
echo "Connected to the lifesaver database successfully.";
?>
