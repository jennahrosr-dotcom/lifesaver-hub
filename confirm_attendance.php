<?php
session_start();

// Ensure staff is logged in
if (!isset($_SESSION['staff_id'])) {
    header("Location: staff_login.php");
    exit;
}

$pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$registrationId = $_GET['id'] ?? null;

if (!$registrationId || !is_numeric($registrationId)) {
    $_SESSION['error'] = "Invalid registration ID.";
    header("Location: staff_view_donation.php");
    exit;
}

// Check if the registration exists
$stmt = $pdo->prepare("SELECT * FROM registration WHERE RegistrationID = ?");
$stmt->execute([$registrationId]);
$registration = $stmt->fetch();

if (!$registration) {
    $_SESSION['error'] = "Registration not found.";
    header("Location: staff_view_donation.php");
    exit;
}

// Confirm attendance
$updateStmt = $pdo->prepare("
    UPDATE registration 
    SET AttendanceStatus = 'Present' 
    WHERE RegistrationID = ?
");
$updateStmt->execute([$registrationId]);

$_SESSION['success'] = "Attendance confirmed successfully.";
header("Location: staff_view_donation.php");
exit;
?>
