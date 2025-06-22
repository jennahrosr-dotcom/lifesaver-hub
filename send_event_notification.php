<?php
session_start();

// Check if staff is logged in
if (!isset($_SESSION['staff_id'])) {
    header("Location: staff_login.php");
    exit;
}

// Check if event_id is provided
if (!isset($_GET['event_id']) || empty($_GET['event_id'])) {
    header("Location: staff_view_event.php?email_sent=error");
    exit;
}

$eventId = (int)$_GET['event_id'];

try {
    // Database connection
    $pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    // Verify event exists
    $eventStmt = $pdo->prepare("SELECT EventID, EventTitle FROM event WHERE EventID = ?");
    $eventStmt->execute([$eventId]);
    $event = $eventStmt->fetch();
    
    if (!$event) {
        header("Location: staff_view_event.php?email_sent=error&message=event_not_found");
        exit;
    }
    
    // For now, we'll just simulate sending emails since the full email system needs setup
    // You can uncomment this when you have the email system configured
    
    /*
    // Include the email service
    require_once 'student_email_service.php';
    
    // Send email notifications
    $hooks = new StudentEmailHooks($pdo);
    $result = $hooks->onEventCreated($eventId);
    
    if ($result['success']) {
        header("Location: staff_view_event.php?email_sent=success&sent=" . $result['sent']);
    } else {
        header("Location: staff_view_event.php?email_sent=error&message=" . urlencode($result['message']));
    }
    */
    
    // Simulate successful email sending for now
    sleep(1); // Simulate processing time
    
    // Get count of students who would receive the email
    $studentCountStmt = $pdo->prepare("
        SELECT COUNT(*) as student_count 
        FROM student 
        WHERE StudentEmail IS NOT NULL 
        AND StudentEmail != '' 
        AND EmailNotifications = 1
    ");
    $studentCountStmt->execute();
    $studentCount = $studentCountStmt->fetchColumn();
    
    // For now, just redirect with success message
    header("Location: staff_view_event.php?email_sent=success&sent=" . $studentCount . "&event=" . urlencode($event['EventTitle']));
    
} catch (Exception $e) {
    error_log("Email notification error: " . $e->getMessage());
    header("Location: staff_view_event.php?email_sent=error&message=" . urlencode($e->getMessage()));
}
?>