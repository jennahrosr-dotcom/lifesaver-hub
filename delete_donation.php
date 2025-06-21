<?php
session_start();

// Check if donor is logged in
if (!isset($_SESSION['donor_id'])) {
    header("Location: donor_login.php");
    exit;
}

$pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$donorId = $_SESSION['donor_id'];
$registrationId = $_GET['id'] ?? null;

if (!$registrationId || !is_numeric($registrationId)) {
    $_SESSION['error'] = "Invalid registration ID.";
    header("Location: student_view_donation.php");
    exit;
}

// Check if the registration belongs to the logged-in donor
$stmt = $pdo->prepare("SELECT * FROM registration WHERE RegistrationID = ? AND StudentID = ?");
$stmt->execute([$registrationId, $donorId]);
$registration = $stmt->fetch();

if (!$registration) {
    $_SESSION['error'] = "Registration not found or unauthorized access.";
    header("Location: student_view_donation.php");
    exit;
}

// Perform soft delete (update status to Cancelled)
$delete = $pdo->prepare("UPDATE registration SET RegistrationStatus = 'Cancelled' WHERE RegistrationID = ? AND StudentID = ?");
$delete->execute([$registrationId, $donorId]);

$_SESSION['success'] = "Your registration has been cancelled successfully.";
header("Location: student_view_donation.php");
exit;
?>
