<?php
session_start();

// Check if user is staff
if (!isset($_SESSION['staff_id'])) {
    header("Location: index.php");
    exit;
}

// Database connection
require 'db.php';

// Check if event ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = "Invalid event ID.";
    header("Location: staff_view_event.php");
    exit;
}

$eventId = (int)$_GET['id'];

try {
    // Start transaction for data integrity
    $pdo->beginTransaction();
    
    // First, delete all registrations for this event
    $deleteRegistrations = $pdo->prepare("DELETE FROM registration WHERE EventID = ?");
    $deleteRegistrations->execute([$eventId]);
    
    // Then, delete the event itself
    $deleteEvent = $pdo->prepare("DELETE FROM event WHERE EventID = ?");
    $deleteEvent->execute([$eventId]);
    
    // Check if event was actually deleted
    if ($deleteEvent->rowCount() > 0) {
        // Commit the transaction
        $pdo->commit();
        $_SESSION['success'] = "Event has been completely deleted from the system.";
    } else {
        // Rollback if no event was found
        $pdo->rollback();
        $_SESSION['error'] = "Event not found or already deleted.";
    }
    
} catch (PDOException $e) {
    // Rollback transaction on error
    $pdo->rollback();
    $_SESSION['error'] = "Error deleting event: " . $e->getMessage();
}

// Redirect back to events page
header("Location: staff_view_event.php");
exit;
?>