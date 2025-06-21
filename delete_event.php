<?php
session_start();

if (!isset($_SESSION['staff_id'])) {
    header("Location: staff_login.php");
    exit;
}

$pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$staffId = $_SESSION['staff_id'];

// Check if request is POST and has required data
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['event_id']) || !isset($_POST['action'])) {
    $_SESSION['error'] = "Invalid request.";
    header("Location: staff_manage_events.php");
    exit;
}

$eventId = (int)$_POST['event_id'];
$action = $_POST['action'];

// Validate event exists
$stmt = $pdo->prepare("SELECT * FROM event WHERE EventID = ?");
$stmt->execute([$eventId]);
$event = $stmt->fetch();

if (!$event) {
    $_SESSION['error'] = "Event not found.";
    header("Location: staff_manage_events.php");
    exit;
}

try {
    if ($action === 'delete') {
        // Check if event can be deleted (not already deleted)
        if ($event['EventStatus'] === 'Deleted') {
            $_SESSION['error'] = "Event is already deleted.";
            header("Location: staff_view_event.php?id=" . $eventId);
            exit;
        }
        
        // Check if event has confirmed registrations
        $regStmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM registration 
            WHERE EventID = ? AND RegistrationStatus = 'Confirmed'
        ");
        $regStmt->execute([$eventId]);
        $confirmedCount = $regStmt->fetch()['count'];
        
        // For events with confirmed registrations, ask for confirmation
        if ($confirmedCount > 0 && !isset($_POST['force_delete'])) {
            // Store the event data in session for confirmation page
            $_SESSION['delete_confirmation'] = [
                'event_id' => $eventId,
                'event_title' => $event['EventTitle'],
                'confirmed_registrations' => $confirmedCount
            ];
            header("Location: confirm_delete_event.php");
            exit;
        }
        
        // Soft delete: update EventStatus to 'Deleted'
        $deleteStmt = $pdo->prepare("UPDATE event SET EventStatus = 'Deleted' WHERE EventID = ?");
        $deleteStmt->execute([$eventId]);
        
        $_SESSION['success'] = "Event has been deleted successfully.";
        
        // If there were registrations, also update their status
        if ($confirmedCount > 0) {
            $updateRegStmt = $pdo->prepare("
                UPDATE registration 
                SET RegistrationStatus = 'Cancelled', 
                    CancellationReason = 'Event was deleted by staff' 
                WHERE EventID = ? AND RegistrationStatus != 'Cancelled'
            ");
            $updateRegStmt->execute([$eventId]);
            $_SESSION['success'] .= " All existing registrations have been cancelled.";
        }
        
    } elseif ($action === 'restore') {
        // Check if event can be restored (must be deleted)
        if ($event['EventStatus'] !== 'Deleted') {
            $_SESSION['error'] = "Only deleted events can be restored.";
            header("Location: staff_view_event.php?id=" . $eventId);
            exit;
        }
        
        // Determine appropriate status based on event date
        $eventDate = new DateTime($event['EventDate']);
        $today = new DateTime();
        
        $newStatus = 'Upcoming';
        if ($eventDate->format('Y-m-d') === $today->format('Y-m-d')) {
            $newStatus = 'Ongoing';
        } elseif ($eventDate < $today) {
            $newStatus = 'Completed';
        }
        
        // Restore event
        $restoreStmt = $pdo->prepare("UPDATE event SET EventStatus = ? WHERE EventID = ?");
        $restoreStmt->execute([$newStatus, $eventId]);
        
        $_SESSION['success'] = "Event has been restored successfully with status: " . $newStatus . ".";
        
    } elseif ($action === 'permanent_delete') {
        // This is for actual permanent deletion (use with extreme caution)
        if (!isset($_POST['confirm_permanent']) || $_POST['confirm_permanent'] !== 'yes') {
            $_SESSION['error'] = "Permanent deletion requires confirmation.";
            header("Location: staff_view_event.php?id=" . $eventId);
            exit;
        }
        
        // Check if event is deleted first
        if ($event['EventStatus'] !== 'Deleted') {
            $_SESSION['error'] = "Event must be deleted before permanent removal.";
            header("Location: staff_view_event.php?id=" . $eventId);
            exit;
        }
        
        $pdo->beginTransaction();
        
        try {
            // Delete all registrations for this event
            $deleteRegStmt = $pdo->prepare("DELETE FROM registration WHERE EventID = ?");
            $deleteRegStmt->execute([$eventId]);
            
            // Delete the event itself
            $deleteEventStmt = $pdo->prepare("DELETE FROM event WHERE EventID = ?");
            $deleteEventStmt->execute([$eventId]);
            
            $pdo->commit();
            
            $_SESSION['success'] = "Event and all related data have been permanently deleted.";
            header("Location: staff_manage_events.php");
            exit;
            
        } catch (Exception $e) {
            $pdo->rollback();
            throw $e;
        }
        
    } else {
        throw new Exception("Invalid action specified.");
    }
    
} catch (Exception $e) {
    $_SESSION['error'] = "An error occurred: " . $e->getMessage();
}

// Redirect back to event view or manage events
if (isset($_POST['redirect_to']) && $_POST['redirect_to'] === 'manage') {
    header("Location: staff_manage_events.php");
} else {
    header("Location: staff_view_event.php?id=" . $eventId);
}
exit;
?>