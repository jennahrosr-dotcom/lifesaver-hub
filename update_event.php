<?php
session_start();

if (!isset($_SESSION['staff_id'])) {
    header("Location: staff_login.php");
    exit;
}

require 'db.php';

$staffId = $_SESSION['staff_id'];
$success = '';
$error = '';

// Get staff information
$stmt = $pdo->prepare("SELECT * FROM staff WHERE StaffID = ?");
$stmt->execute([$staffId]);
$staff = $stmt->fetch();

// Check if event ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error'] = "Event ID is required.";
    header("Location: staff_view_event.php");
    exit;
}

$eventId = (int)$_GET['id'];

// Get event details
$stmt = $pdo->prepare("SELECT * FROM event WHERE EventID = ?");
$stmt->execute([$eventId]);
$event = $stmt->fetch();

if (!$event) {
    $_SESSION['error'] = "Event not found.";
    header("Location: staff_view_event.php");
    exit;
}

// Check if event can be edited (not deleted)
if ($event['EventStatus'] === 'Deleted') {
    $_SESSION['error'] = "Cannot edit a deleted event. Please restore it first.";
    header("Location: staff_view_event.php?id=" . $eventId);
    exit;
}

// XAMPP-optimized email function for event updates
function sendEventUpdateEmailXAMPP($student, $eventId, $title, $description, $date, $day, $venue, $status, $changesSummary) {
    try {
        // Check if PHPMailer is available
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            $phpmailerPaths = [
                __DIR__ . '/PHPMailer/src/PHPMailer.php',
                __DIR__ . '/vendor/phpmailer/phpmailer/src/PHPMailer.php',
                'PHPMailer/src/PHPMailer.php',
                './PHPMailer/src/PHPMailer.php',
                '../PHPMailer/src/PHPMailer.php'
            ];
            
            $phpmailerFound = false;
            foreach ($phpmailerPaths as $path) {
                if (file_exists($path)) {
                    require_once $path;
                    require_once dirname($path) . '/SMTP.php';
                    require_once dirname($path) . '/Exception.php';
                    $phpmailerFound = true;
                    error_log("✅ PHPMailer loaded from: " . $path);
                    break;
                }
            }
            
            if (!$phpmailerFound) {
                error_log("❌ PHPMailer not found in any expected locations");
                return false;
            }
        }
        
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        // XAMPP-optimized SMTP configuration
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'jennahrosr@gmail.com';
        $mail->Password = 'ckgsjiiizwoitino';
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        
        // XAMPP-friendly settings
        $mail->Timeout = 30;
        $mail->SMTPKeepAlive = false;
        $mail->SMTPAutoTLS = true;
        
        // Windows/XAMPP SSL options
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
                'cafile' => false,
                'capath' => false,
                'disable_compression' => true
            )
        );
        
        // Email settings
        $mail->setFrom('jennahrosr@gmail.com', 'LifeSaver Hub - Event Update');
        $mail->addAddress($student['StudentEmail'], $student['StudentName']);
        $mail->addReplyTo('jennahrosr@gmail.com', 'LifeSaver Support');
        
        // Content
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';
        
        $subject = "🔄 EVENT UPDATE: " . $title;
        $htmlContent = generateEventUpdateEmailHTML($student, $eventId, $title, $description, $date, $day, $venue, $status, $changesSummary);
        $plainContent = generateEventUpdateEmailPlain($student, $eventId, $title, $description, $date, $day, $venue, $status, $changesSummary);
        
        $mail->Subject = $subject;
        $mail->Body = $htmlContent;
        $mail->AltBody = $plainContent;
        
        $result = $mail->send();
        
        if ($result) {
            error_log("✅ Event Update Email sent successfully to: " . $student['StudentEmail']);
            return true;
        } else {
            error_log("❌ Event Update Email send failed to: " . $student['StudentEmail']);
            return false;
        }
        
    } catch (Exception $e) {
        error_log("❌ Event Update Email error for " . $student['StudentEmail'] . ": " . $e->getMessage());
        return false;
    }
}

// Function to send event update notifications to all students
function sendEventUpdateNotifications($pdo, $eventId, $title, $description, $date, $day, $venue, $status, $changesSummary) {
    try {
        // Enhanced notification with better formatting
        $notificationTitle = "🔄 Event Update: " . $title;
        $notificationMessage = "📝 An event you might be interested in has been updated!\n\n" .
                             "🎯 Event: " . $title . "\n" .
                             "📅 Date: " . $date . " (" . $day . ")\n" .
                             "📍 Venue: " . $venue . "\n" .
                             "📊 Status: " . $status . "\n\n" .
                             "📋 Description:\n" . $description . "\n\n" .
                             "🔍 What Changed:\n" . $changesSummary . "\n\n" .
                             "💡 Please check the updated details if you plan to participate.\n\n" .
                             "❤️ Thank you for your continued support!";
        
        // Get all students with valid email addresses
        $studentsQuery = $pdo->query("
            SELECT StudentID, StudentName, StudentEmail 
            FROM student 
            WHERE StudentEmail IS NOT NULL 
            AND StudentEmail != '' 
            AND StudentEmail != 'NULL'
            AND StudentEmail NOT LIKE '%NULL%'
            ORDER BY StudentID
        ");
        $students = $studentsQuery->fetchAll();
        
        if (empty($students)) {
            error_log("No students found in database for event update notifications");
            return ['notifications' => 0, 'emails' => 0];
        }
        
        // Use correct column names that match notification table
        $insertNotification = $pdo->prepare("
            INSERT INTO notification 
            (StudentID, NotificationTitle, NotificationMessage, NotificationType, Priority, EventID, CreatedDate, NotificationIsRead) 
            VALUES (?, ?, ?, 'Event Update', 'High', ?, NOW(), 0)
        ");
        
        $notificationCount = 0;
        $emailCount = 0;
        
        foreach ($students as $student) {
            try {
                // Create in-app notification
                $insertNotification->execute([
                    $student['StudentID'], 
                    $notificationTitle, 
                    $notificationMessage, 
                    $eventId
                ]);
                $notificationCount++;
                
                // Send XAMPP-optimized email notification
                $emailSent = sendEventUpdateEmailXAMPP($student, $eventId, $title, $description, $date, $day, $venue, $status, $changesSummary);
                if ($emailSent) {
                    $emailCount++;
                }
                
                // XAMPP-friendly delay
                usleep(500000); // 0.5 seconds
                
            } catch (Exception $e) {
                error_log("Failed to create update notification for StudentID " . $student['StudentID'] . ": " . $e->getMessage());
            }
        }
        
        error_log("Successfully created $notificationCount update notifications and sent $emailCount emails for event: $title");
        return ['notifications' => $notificationCount, 'emails' => $emailCount];
        
    } catch (Exception $e) {
        error_log("Event update notification creation failed: " . $e->getMessage());
        return ['notifications' => 0, 'emails' => 0];
    }
}

/**
 * Generate HTML email content for event update
 */
function generateEventUpdateEmailHTML($student, $eventId, $title, $description, $date, $day, $venue, $status, $changesSummary) {
    $eventDate = date('l, d F Y', strtotime($date));
    $updateTime = date('d F Y \a\t g:i A');
    
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <style>
            body { 
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
                line-height: 1.6; 
                color: #333; 
                margin: 0; 
                padding: 20px; 
                background-color: #f5f5f5;
            }
            .container { 
                max-width: 600px; 
                margin: 0 auto; 
                background: #ffffff; 
                border-radius: 15px;
                overflow: hidden;
                box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            }
            .header { 
                background: linear-gradient(135deg, #667eea, #764ba2); 
                color: white; 
                padding: 40px 30px; 
                text-align: center; 
            }
            .header h1 {
                font-size: 28px;
                margin: 0 0 10px 0;
                font-weight: 700;
            }
            .content { 
                padding: 40px 30px; 
            }
            .update-highlight { 
                background: linear-gradient(135deg, #fef3c7, #fde68a); 
                border: 2px solid #f59e0b;
                color: #92400e;
                padding: 25px; 
                text-align: center; 
                font-size: 18px; 
                font-weight: bold; 
                margin: 30px 0; 
                border-radius: 10px;
            }
            .event-details { 
                background: #f8f9fa; 
                padding: 25px; 
                margin: 25px 0; 
                border-radius: 10px; 
                border-left: 5px solid #667eea; 
            }
            .event-table {
                width: 100%;
                border-collapse: collapse;
                margin: 20px 0;
            }
            .event-table th,
            .event-table td {
                padding: 12px;
                text-align: left;
                border-bottom: 1px solid #ddd;
            }
            .event-table th {
                background-color: #f8f9fa;
                font-weight: 600;
                color: #667eea;
            }
            .changes-section {
                background: #e3f2fd;
                padding: 25px;
                border-radius: 10px;
                margin: 25px 0;
                border-left: 5px solid #2196f3;
            }
            .changes-section h4 {
                color: #1565c0;
                margin-bottom: 15px;
            }
            .status-badge {
                padding: 8px 16px;
                border-radius: 20px;
                font-size: 12px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                display: inline-block;
            }
            .status-upcoming { 
                background: rgba(34, 197, 94, 0.15); 
                color: #166534; 
                border: 1px solid rgba(34, 197, 94, 0.3);
            }
            .status-ongoing { 
                background: rgba(245, 158, 11, 0.15); 
                color: #92400e; 
                border: 1px solid rgba(245, 158, 11, 0.3);
            }
            .status-completed { 
                background: rgba(107, 114, 128, 0.15); 
                color: #374151; 
                border: 1px solid rgba(107, 114, 128, 0.3);
            }
            .status-cancelled { 
                background: rgba(239, 68, 68, 0.15); 
                color: #991b1b; 
                border: 1px solid rgba(239, 68, 68, 0.3);
            }
            .footer { 
                background: #f8f9fa;
                padding: 30px; 
                text-align: center; 
                color: #6c757d; 
                font-size: 14px; 
            }
            .important-info {
                background: #fef3c7;
                border: 1px solid #fbbf24;
                padding: 20px;
                border-radius: 8px;
                margin: 20px 0;
                color: #92400e;
            }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <div style='font-size: 60px; margin-bottom: 20px;'>🔄</div>
                <h1>EVENT UPDATE NOTIFICATION</h1>
                <h2>Important Changes to Your Event</h2>
            </div>
            
            <div class='content'>
                <p>Dear <strong>" . htmlspecialchars($student['StudentName']) . "</strong>,</p>
                
                <p>We wanted to inform you that the following blood donation event has been updated with new information:</p>
                
                <div class='update-highlight'>
                    🎯 " . htmlspecialchars($title) . "
                </div>
                
                <div class='event-details'>
                    <h3>📋 Updated Event Information</h3>
                    <table class='event-table'>
                        <tr>
                            <th>📅 Date & Day</th>
                            <td>{$eventDate}</td>
                        </tr>
                        <tr>
                            <th>📍 Venue</th>
                            <td>" . htmlspecialchars($venue) . "</td>
                        </tr>
                        <tr>
                            <th>📊 Status</th>
                            <td><span class='status-badge status-" . strtolower($status) . "'>" . htmlspecialchars($status) . "</span></td>
                        </tr>
                        <tr>
                            <th>📝 Description</th>
                            <td>" . htmlspecialchars($description) . "</td>
                        </tr>
                        <tr>
                            <th>🆔 Event ID</th>
                            <td>#{$eventId}</td>
                        </tr>
                    </table>
                </div>
                
                <div class='changes-section'>
                    <h4>🔍 What Changed:</h4>
                    <p>" . nl2br(htmlspecialchars($changesSummary)) . "</p>
                </div>
                
                <div class='important-info'>
                    <h4>⚠️ Important Notes:</h4>
                    <ul style='text-align: left; margin: 10px 0; padding-left: 20px;'>
                        <li>Please review the updated event details carefully</li>
                        <li>If you have already registered, your registration remains valid</li>
                        <li>Contact us if you have any questions about these changes</li>
                        <li>Make sure to adjust your schedule if the date or venue has changed</li>
                    </ul>
                </div>
                
                <div style='background: linear-gradient(135deg, #d4edda, #c3e6cb); padding: 20px; border-radius: 10px; margin: 25px 0; text-align: center;'>
                    <h4 style='color: #155724; margin-bottom: 10px;'>📞 Need Help?</h4>
                    <p style='color: #155724; margin: 0;'>
                        Call us: <strong>03-8883-1200</strong><br>
                        Email: <strong>jennahrosr@gmail.com</strong><br>
                        We're here to help with any questions about these changes!
                    </p>
                </div>
                
                <div style='text-align: center; margin: 30px 0;'>
                    <p><strong>📞 Contact Information:</strong></p>
                    <p style='font-size: 18px; color: #667eea;'><strong>Phone: 03-8883-1200</strong></p>
                    <p>Email: jennahrosr@gmail.com</p>
                </div>
            </div>
            
            <div class='footer'>
                <p><strong>Thank you for your understanding and continued support!</strong></p>
                <p><strong>LifeSaver Hub - Blood Donation Management</strong></p>
                <p><strong>Ministry of Health Malaysia - Blood Donation Unit</strong></p>
                <p style='margin-top: 15px; font-size: 12px;'>
                    Update sent: {$updateTime} | Event ID: #{$eventId}
                </p>
            </div>
        </div>
    </body>
    </html>";
}

/**
 * Generate plain text email content for event update
 */
function generateEventUpdateEmailPlain($student, $eventId, $title, $description, $date, $day, $venue, $status, $changesSummary) {
    $eventDate = date('l, d F Y', strtotime($date));
    $updateTime = date('d F Y \a\t g:i A');
    
    return "
EVENT UPDATE NOTIFICATION

Dear " . $student['StudentName'] . ",

We wanted to inform you that the following blood donation event has been updated:

EVENT: " . $title . "

UPDATED EVENT DETAILS:
📅 Date: {$eventDate}
📍 Venue: " . $venue . "
📊 Status: " . $status . "
📝 Description: " . $description . "
🆔 Event ID: #{$eventId}

WHAT CHANGED:
" . $changesSummary . "

IMPORTANT NOTES:
✅ Please review the updated event details carefully
✅ If you have already registered, your registration remains valid
✅ Contact us if you have any questions about these changes
✅ Make sure to adjust your schedule if the date or venue has changed

CONTACT INFORMATION:
📞 Phone: 03-8883-1200
📧 Email: jennahrosr@gmail.com

We're here to help with any questions about these changes!

Thank you for your understanding and continued support!

LifeSaver Hub - Blood Donation Management
Ministry of Health Malaysia - Blood Donation Unit
Update sent: {$updateTime} | Event ID: #{$eventId}
";
}

// Function to automatically determine event status based on date
function getAutoEventStatus($eventDate) {
    $today = date('Y-m-d');
    $eventDateTime = new DateTime($eventDate);
    $todayDateTime = new DateTime($today);
    
    if ($eventDateTime < $todayDateTime) {
        return 'Completed';
    } elseif ($eventDateTime == $todayDateTime) {
        return 'Ongoing';
    } else {
        return 'Upcoming';
    }
}

// Function to detect changes between old and new event data
function detectEventChanges($oldData, $newData) {
    $changes = [];
    
    if ($oldData['EventTitle'] !== $newData['EventTitle']) {
        $changes[] = "• Title changed from '" . $oldData['EventTitle'] . "' to '" . $newData['EventTitle'] . "'";
    }
    
    if ($oldData['EventDate'] !== $newData['EventDate']) {
        $changes[] = "• Date changed from " . date('F j, Y', strtotime($oldData['EventDate'])) . 
                    " to " . date('F j, Y', strtotime($newData['EventDate']));
    }
    
    if ($oldData['EventVenue'] !== $newData['EventVenue']) {
        $changes[] = "• Venue changed from '" . $oldData['EventVenue'] . "' to '" . $newData['EventVenue'] . "'";
    }
    
    if ($oldData['EventStatus'] !== $newData['EventStatus']) {
        $changes[] = "• Status changed from '" . $oldData['EventStatus'] . "' to '" . $newData['EventStatus'] . "'";
    }
    
    if ($oldData['EventDescription'] !== $newData['EventDescription']) {
        $changes[] = "• Description has been updated with new information";
    }
    
    if ($oldData['EventDay'] !== $newData['EventDay']) {
        $changes[] = "• Day changed from " . $oldData['EventDay'] . " to " . $newData['EventDay'];
    }
    
    return empty($changes) ? "Minor updates were made to the event details." : implode("\n", $changes);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Store original event data for comparison
        $originalEvent = $event;
        
        // Get form data
        $eventTitle = trim($_POST['event_title']);
        $eventDescription = trim($_POST['event_description']);
        $eventDate = $_POST['event_date'];
        $eventDay = trim($_POST['event_day']);
        $eventVenue = trim($_POST['event_venue']);
        $manualStatus = $_POST['event_status']; // Manual status selection
        $autoUpdateStatus = isset($_POST['auto_update_status']) && $_POST['auto_update_status'] === '1';
        $sendNotifications = isset($_POST['send_notifications']) && $_POST['send_notifications'] === '1';
        
        // Validation
        if (empty($eventTitle)) {
            throw new Exception("Event title is required.");
        }
        
        if (empty($eventDescription)) {
            throw new Exception("Event description is required.");
        }
        
        if (empty($eventDate)) {
            throw new Exception("Event date is required.");
        }
        
        if (empty($eventVenue)) {
            throw new Exception("Event venue is required.");
        }
        
        // Determine final event status
        if ($autoUpdateStatus) {
            // Auto-determine status based on date
            $finalStatus = getAutoEventStatus($eventDate);
            $statusUpdateMessage = " (Status automatically updated to '{$finalStatus}' based on event date)";
        } else {
            // Use manually selected status
            $finalStatus = $manualStatus;
            $statusUpdateMessage = "";
            
            // But still validate date constraints for non-completed events
            if ($finalStatus !== 'Completed' && $finalStatus !== 'Cancelled' && $eventDate < date('Y-m-d')) {
                throw new Exception("Event date cannot be in the past for '{$finalStatus}' events. Either change the date or set status to 'Completed'.");
            }
        }
        
        // Prepare new event data
        $newEventData = [
            'EventTitle' => $eventTitle,
            'EventDescription' => $eventDescription,
            'EventDate' => $eventDate,
            'EventDay' => $eventDay,
            'EventVenue' => $eventVenue,
            'EventStatus' => $finalStatus
        ];
        
        // Check if there are any actual changes
        $hasChanges = false;
        foreach ($newEventData as $key => $value) {
            if ($originalEvent[$key] !== $value) {
                $hasChanges = true;
                break;
            }
        }
        
        if (!$hasChanges) {
            throw new Exception("No changes detected. Please modify at least one field to update the event.");
        }
        
        // Begin transaction for data integrity
        $pdo->beginTransaction();
        
        // Update event
        $updateStmt = $pdo->prepare("
            UPDATE event SET 
            EventTitle = ?, 
            EventDescription = ?, 
            EventDate = ?, 
            EventDay = ?, 
            EventVenue = ?, 
            EventStatus = ?
            WHERE EventID = ?
        ");
        
        $result = $updateStmt->execute([
            $eventTitle, $eventDescription, $eventDate, $eventDay, 
            $eventVenue, $finalStatus, $eventId
        ]);
        
        if ($result) {
            // Send notifications if requested
            $emailNotificationResult = "";
            if ($sendNotifications) {
                $changesSummary = detectEventChanges($originalEvent, $newEventData);
                $notificationResults = sendEventUpdateNotifications($pdo, $eventId, $eventTitle, $eventDescription, $eventDate, $eventDay, $eventVenue, $finalStatus, $changesSummary);
                
                if ($notificationResults['notifications'] > 0 && $notificationResults['emails'] > 0) {
                    $emailNotificationResult = " " . $notificationResults['notifications'] . " students were notified and " . $notificationResults['emails'] . " emails were sent.";
                } elseif ($notificationResults['notifications'] > 0) {
                    $emailNotificationResult = " " . $notificationResults['notifications'] . " students were notified in-app (email delivery may have issues).";
                } else {
                    $emailNotificationResult = " No students were found to notify.";
                }
            }
            
            // Commit transaction
            $pdo->commit();
            
            $successMessage = "Event updated successfully!{$statusUpdateMessage}{$emailNotificationResult}";
            $_SESSION['success'] = $successMessage;
            header("Location: staff_view_event.php?id=" . $eventId);
            exit;
        } else {
            throw new Exception("Failed to update event.");
        }
        
    } catch (Exception $e) {
        // Rollback transaction on error
        if ($pdo->inTransaction()) {
            $pdo->rollback();
        }
        $error = $e->getMessage();
    }
}

// If form was submitted and there was an error, use POST data, otherwise use database data
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($error)) {
    $formData = [
        'EventTitle' => $_POST['event_title'],
        'EventDescription' => $_POST['event_description'],
        'EventDate' => $_POST['event_date'],
        'EventDay' => $_POST['event_day'],
        'EventVenue' => $_POST['event_venue'],
        'EventStatus' => $_POST['event_status']
    ];
} else {
    $formData = $event;
}

// Get suggested auto status for current date
$suggestedStatus = getAutoEventStatus($formData['EventDate']);

// Get student count for notification info
try {
    $studentCountQuery = $pdo->query("
        SELECT COUNT(*) as student_count 
        FROM student 
        WHERE StudentEmail IS NOT NULL 
        AND StudentEmail != '' 
        AND StudentEmail != 'NULL'
        AND StudentEmail NOT LIKE '%NULL%'
    ");
    $studentCount = $studentCountQuery->fetch()['student_count'];
} catch (Exception $e) {
    $studentCount = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Event - <?php echo htmlspecialchars($event['EventTitle']); ?> - LifeSaver Hub</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #f8f9fa 0%, #e3f2fd 50%, #f3e5f5 100%);
            min-height: 100vh;
            color: #2d3748;
            position: relative;
            overflow-x: hidden;
        }

        /* Animated background elements */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: 
                radial-gradient(circle at 15% 25%, rgba(102, 126, 234, 0.08) 0%, transparent 50%),
                radial-gradient(circle at 85% 75%, rgba(16, 185, 129, 0.08) 0%, transparent 50%),
                radial-gradient(circle at 50% 50%, rgba(139, 92, 246, 0.05) 0%, transparent 50%);
            z-index: -1;
            animation: pulse 20s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 0.3; transform: scale(1); }
            50% { opacity: 0.6; transform: scale(1.05); }
        }

        .app-container {
            display: flex;
            min-height: 100vh;
        }

        /* Enhanced Sidebar */
        .sidebar {
            width: 320px;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.98) 0%, rgba(248, 250, 252, 0.95) 100%);
            backdrop-filter: blur(25px);
            border-right: 1px solid rgba(102, 126, 234, 0.15);
            padding: 0;
            position: fixed;
            height: 100vh;
            overflow-y: hidden;
            z-index: 1000;
            box-shadow: 4px 0 25px rgba(102, 126, 234, 0.12);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            flex-direction: column;
        }

        .sidebar-header {
            padding: 20px 24px;
            border-bottom: 1px solid rgba(102, 126, 234, 0.12);
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.08), rgba(139, 92, 246, 0.08));
            position: relative;
            overflow: hidden;
            flex-shrink: 0;
        }

        .sidebar-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            transform: rotate(-45deg);
            animation: shimmer 3s infinite;
        }

        @keyframes shimmer {
            0% { transform: translateX(-100%) translateY(-100%) rotate(-45deg); }
            100% { transform: translateX(100%) translateY(100%) rotate(-45deg); }
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 16px;
            color: #2d3748;
            text-decoration: none;
            font-size: 26px;
            font-weight: 900;
            letter-spacing: -0.5px;
            transition: all 0.3s ease;
            position: relative;
            z-index: 1;
            padding: 8px 12px;
            border-radius: 16px;
        }

        .logo:hover {
            transform: translateY(-2px);
            color: #667eea;
            background: rgba(255, 255, 255, 0.8);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.2);
        }

        .logo img {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            object-fit: cover;
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.3);
            transition: all 0.3s ease;
            border: 2px solid rgba(255, 255, 255, 0.9);
        }

        .logo:hover img {
            transform: scale(1.1) rotate(5deg);
            box-shadow: 0 8px 30px rgba(102, 126, 234, 0.4);
        }

        .sidebar-nav {
            padding: 16px 0;
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .nav-section {
            margin-bottom: 20px;
        }

        .nav-section:last-child {
            margin-bottom: 0;
        }

        .nav-section-title {
            padding: 0 24px 8px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #667eea;
            position: relative;
        }

        .nav-section-title::after {
            content: '';
            position: absolute;
            bottom: 4px;
            left: 24px;
            width: 30px;
            height: 2px;
            background: linear-gradient(90deg, #667eea, #764ba2);
            border-radius: 1px;
        }

        .nav-item {
            display: flex;
            align-items: center;
            color: #4a5568;
            text-decoration: none;
            padding: 12px 24px;
            font-weight: 600;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border-left: 4px solid transparent;
            position: relative;
            margin: 2px 12px;
            border-radius: 12px;
            overflow: hidden;
        }

        .nav-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(139, 92, 246, 0.1));
            border-radius: 12px;
            opacity: 0;
            transition: all 0.3s ease;
            transform: translateX(-100%);
        }

        .nav-item::after {
            content: '';
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 0;
            height: 2px;
            background: linear-gradient(90deg, #667eea, #764ba2);
            transition: width 0.3s ease;
            border-radius: 1px;
        }

        .nav-item:hover::before, .nav-item.active::before {
            opacity: 1;
            transform: translateX(0);
        }

        .nav-item:hover::after, .nav-item.active::after {
            width: 24px;
        }

        .nav-item:hover, .nav-item.active {
            color: #2d3748;
            transform: translateX(6px);
            border-left-color: #667eea;
            background: rgba(255, 255, 255, 0.6);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.15);
        }

        .nav-item i {
            width: 20px;
            margin-right: 12px;
            font-size: 16px;
            position: relative;
            z-index: 1;
            transition: all 0.3s ease;
        }

        .nav-item:hover i, .nav-item.active i {
            color: #667eea;
            transform: scale(1.1);
        }

        .nav-item span {
            position: relative;
            z-index: 1;
            font-size: 14px;
        }

        .user-profile {
            flex-shrink: 0;
            padding: 16px 24px;
            background: linear-gradient(135deg, rgba(248, 249, 250, 0.98), rgba(255, 255, 255, 0.98));
            border-top: 1px solid rgba(102, 126, 234, 0.15);
            backdrop-filter: blur(20px);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #2d3748;
            padding: 10px 12px;
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.8);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.1);
            transition: all 0.3s ease;
        }

        .user-info:hover {
            background: rgba(255, 255, 255, 0.95);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.15);
            transform: translateY(-2px);
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 16px;
            color: white;
            box-shadow: 0 6px 18px rgba(102, 126, 234, 0.3);
            transition: all 0.3s ease;
            border: 2px solid rgba(255, 255, 255, 0.9);
        }

        .user-info:hover .user-avatar {
            transform: scale(1.05);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
        }

        .user-details h4 {
            font-weight: 700;
            margin-bottom: 2px;
            color: #1a202c;
            font-size: 14px;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        }

        .user-details p {
            font-size: 11px;
            color: #4a5568;
            font-weight: 500;
            opacity: 0.8;
        }

        /* Mobile menu button */
        .mobile-menu-btn {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1001;
            background: rgba(30, 41, 59, 0.9);
            border: none;
            color: white;
            padding: 12px;
            border-radius: 12px;
            cursor: pointer;
            backdrop-filter: blur(20px);
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 320px;
            padding: 32px;
            background: rgba(248, 249, 250, 0.3);
        }

        .page-header {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 48px;
            margin-bottom: 32px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(0, 0, 0, 0.05);
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.08), rgba(139, 92, 246, 0.08));
            opacity: 0.5;
        }

        .page-header-content {
            position: relative;
            z-index: 1;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .page-header h1 {
            font-size: 2.5rem;
            font-weight: 900;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 20px;
            text-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            margin: 0;
        }

        .page-header h1 i {
            background: linear-gradient(135deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-size: 2rem;
        }

        .page-header p {
            color: #4a5568;
            font-size: 16px;
            margin-top: 10px;
            font-weight: 500;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            padding: 15px 30px;
            border: none;
            border-radius: 50px;
            font-weight: 700;
            font-size: 14px;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            text-align: center;
            white-space: nowrap;
            position: relative;
            overflow: hidden;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-family: inherit;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }

        .btn:hover::before {
            left: 100%;
        }

        .btn-outline {
            background: rgba(102, 126, 234, 0.1);
            color: #667eea;
            border: 2px solid rgba(102, 126, 234, 0.2);
        }

        .btn-outline:hover {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
            border-color: transparent;
        }

        .btn-success {
            background: linear-gradient(135deg, #10ac84, #00d2d3);
            color: white;
            box-shadow: 0 8px 25px rgba(16, 172, 132, 0.4);
        }

        .btn-success:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 15px 35px rgba(16, 172, 132, 0.6);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
        }

        .btn-secondary:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 15px 35px rgba(102, 126, 234, 0.6);
        }

        .edit-form {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 40px;
            margin-bottom: 30px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(0, 0, 0, 0.05);
            position: relative;
            overflow: hidden;
        }

        .edit-form::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea, #764ba2);
            border-radius: 24px 24px 0 0;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            font-weight: 700;
            color: #2d3748;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 10px;
        }

        .form-group label i {
            margin-right: 8px;
            opacity: 0.8;
            color: #667eea;
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 15px 20px;
            border: 2px solid rgba(102, 126, 234, 0.2);
            border-radius: 15px;
            font-size: 16px;
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
            color: #2d3748;
            font-weight: 500;
            font-family: inherit;
        }

        .form-group input::placeholder,
        .form-group textarea::placeholder {
            color: rgba(45, 55, 72, 0.6);
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
            background: rgba(255, 255, 255, 0.95);
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
            transform: scale(1.02);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 120px;
        }

        .form-group select option {
            background: rgba(255, 255, 255, 0.95);
            color: #2d3748;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .form-actions {
            display: flex;
            gap: 20px;
            justify-content: flex-end;
            margin-top: 40px;
            padding-top: 30px;
            border-top: 1px solid rgba(102, 126, 234, 0.2);
        }

        .alert {
            padding: 20px 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 15px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
        }

        .alert-danger {
            background: linear-gradient(135deg, rgba(220, 53, 69, 0.9), rgba(255, 107, 107, 0.9));
            color: white;
            border-left: 4px solid #dc3545;
        }

        .alert-success {
            background: linear-gradient(135deg, rgba(16, 172, 132, 0.9), rgba(0, 210, 211, 0.9));
            color: white;
            border-left: 4px solid #10ac84;
        }

        .form-help {
            font-size: 12px;
            color: rgba(45, 55, 72, 0.7);
            margin-top: 5px;
            font-style: italic;
        }

        /* New styles for enhanced features */
        .status-suggestion {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(139, 92, 246, 0.1));
            border: 2px solid rgba(102, 126, 234, 0.3);
            border-radius: 12px;
            padding: 15px;
            margin-top: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .status-suggestion.hidden {
            display: none;
        }

        .status-suggestion i {
            color: #667eea;
            font-size: 16px;
        }

        .status-suggestion-text {
            flex: 1;
            font-size: 14px;
            color: #4a5568;
            font-weight: 500;
        }

        .auto-status-toggle {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
            padding: 12px;
            background: rgba(255, 255, 255, 0.8);
            border-radius: 10px;
            border: 1px solid rgba(102, 126, 234, 0.2);
        }

        .toggle-switch {
            position: relative;
            width: 50px;
            height: 24px;
            background: #ddd;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .toggle-switch.active {
            background: linear-gradient(135deg, #667eea, #764ba2);
        }

        .toggle-switch::before {
            content: '';
            position: absolute;
            top: 2px;
            left: 2px;
            width: 20px;
            height: 20px;
            background: white;
            border-radius: 50%;
            transition: all 0.3s ease;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
        }

        .toggle-switch.active::before {
            transform: translateX(26px);
        }

        .notification-options {
            background: rgba(255, 255, 255, 0.9);
            border: 2px solid rgba(102, 126, 234, 0.2);
            border-radius: 15px;
            padding: 20px;
            margin-top: 20px;
        }

        .notification-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
            font-weight: 700;
            color: #2d3748;
        }

        .notification-header i {
            color: #667eea;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .checkbox-group:hover {
            background: rgba(102, 126, 234, 0.05);
        }

        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .checkbox-group label {
            cursor: pointer;
            margin: 0;
            font-weight: 500;
            color: #4a5568;
        }

        .form-section {
            margin-bottom: 30px;
            padding: 25px;
            background: rgba(255, 255, 255, 0.6);
            border-radius: 15px;
            border: 1px solid rgba(102, 126, 234, 0.1);
        }

        .section-title {
            font-size: 18px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: #667eea;
        }

        .email-status {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 24px;
            margin-bottom: 32px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(102, 126, 234, 0.15);
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .email-status::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #10b981, #059669);
            border-radius: 20px 20px 0 0;
        }

        .email-status.warning::before {
            background: linear-gradient(90deg, #f59e0b, #d97706);
        }

        .email-status.error::before {
            background: linear-gradient(90deg, #ef4444, #dc2626);
        }

        .email-status h4 {
            margin: 0 0 10px 0;
            color: #155724;
            font-size: 18px;
            font-weight: 700;
        }

        .email-status.warning h4 {
            color: #92400e;
        }

        .email-status.error h4 {
            color: #991b1b;
        }

        /* Auto-generated day styling */
        .auto-generated {
            background: rgba(102, 126, 234, 0.1) !important;
            color: rgba(45, 55, 72, 0.8) !important;
            font-style: italic;
        }

        .auto-indicator {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(102, 126, 234, 0.6);
            font-size: 12px;
            pointer-events: none;
        }

        .input-container {
            position: relative;
        }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .sidebar {
                width: 300px;
            }
            .main-content {
                margin-left: 300px;
            }
        }

        @media (max-width: 968px) {
            .mobile-menu-btn {
                display: block;
            }
            
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }
            
            .sidebar.open {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
                padding: 24px 16px;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .page-header h1 {
                font-size: 2rem;
            }
        }

        @media (max-width: 480px) {
            .page-header {
                padding: 25px;
            }
            
            .edit-form {
                padding: 20px;
            }
        }

        /* Scrollbar Styling */
        ::-webkit-scrollbar {
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: rgba(15, 23, 42, 0.3);
        }

        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #764ba2, #667eea);
        }

        /* Animation for loading */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .fade-in {
            animation: fadeInUp 0.6s ease-out forwards;
        }
    </style>
</head>
<body>
    <!-- Mobile menu button -->
    <button class="mobile-menu-btn" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>

    <div class="app-container">
        <!-- Enhanced Sidebar -->
        <nav class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <a href="staff_dashboard.php" class="logo">
                    <img src="images/logo.jpg" alt="LifeSaver Hub Logo">
                    <span>LifeSaver Hub</span>
                </a>
            </div>
            
            <div class="sidebar-nav">
                <div class="nav-sections-container">
                    <div class="nav-section">
                        <div class="nav-section-title">Main Menu</div>
                        <a href="staff_dashboard.php" class="nav-item"> 
                            <i class="fas fa-home"></i>
                            <span>Dashboard</span>
                        </a>
                        <a href="create_event.php" class="nav-item">
                            <i class="fas fa-calendar-plus"></i>
                            <span>Create Event</span>
                        </a>
                        <a href="staff_view_event.php" class="nav-item active"> 
                            <i class="fas fa-calendar-alt"></i>
                            <span>View Events</span>
                        </a>
                        <a href="staff_view_donation.php" class="nav-item"> 
                            <i class="fas fa-tint"></i>
                            <span>Donations</span>
                        </a>
                        <a href="create_reward.php" class="nav-item"> 
                            <i class="fas fa-gift"></i>
                            <span>Rewards</span>
                        </a>
                        <a href="generate_report.php" class="nav-item"> 
                            <i class="fas fa-chart-line"></i>
                            <span>Report</span> 
                        </a>
                    </div>
                    <div class="nav-section">
                        <div class="nav-section-title">Account</div>
                        <a href="staff_account.php" class="nav-item"> 
                            <i class="fas fa-user"></i>
                            <span>Profile</span>
                        </a>
                        <a href="logout.php" class="nav-item">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Logout</span>
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="user-profile">
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($staff['StaffName'], 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <h4><?php echo htmlspecialchars($staff['StaffName']); ?></h4>
                        <p>Staff ID: <?php echo htmlspecialchars($_SESSION['staff_id']); ?></p>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Email Status Alert -->
            <?php if ($studentCount > 0): ?>
                <div class="email-status">
                    <h4>📧 XAMPP Email System Ready</h4>
                    <p>When you update this event, <strong><?= $studentCount ?> students</strong> will receive both in-app notifications and email alerts!</p>
                </div>
            <?php else: ?>
                <div class="email-status warning">
                    <h4>⚠️ Limited Notification Reach</h4>
                    <p>No students with email addresses found - only in-app notifications will be sent.</p>
                </div>
            <?php endif; ?>

            <!-- Error Messages -->
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <!-- Success Messages -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>

            <!-- Page Header -->
            <div class="page-header">
                <div class="page-header-content">
                    <div>
                        <h1>
                            <i class="fas fa-edit"></i>
                            Edit Event
                        </h1>
                        <p>Update event details with smart status management and email notifications</p>
                    </div>
                    <a href="staff_view_event.php?id=<?php echo $eventId; ?>" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i>
                        Back to Event
                    </a>
                </div>
            </div>

            <!-- Enhanced Edit Form -->
            <div class="edit-form">
                <form method="POST" action="">
                    <!-- Basic Event Information Section -->
                    <div class="form-section">
                        <div class="section-title">
                            <i class="fas fa-info-circle"></i>
                            Basic Event Information
                        </div>

                        <div class="form-group">
                            <label for="event_title">
                                <i class="fas fa-calendar-alt"></i>
                                Event Title
                            </label>
                            <input type="text" id="event_title" name="event_title" 
                                   value="<?php echo htmlspecialchars($formData['EventTitle']); ?>" 
                                   required maxlength="100" placeholder="Enter event title">
                            <div class="form-help">Choose a clear and descriptive title for your event</div>
                        </div>

                        <div class="form-group">
                            <label for="event_description">
                                <i class="fas fa-align-left"></i>
                                Event Description
                            </label>
                            <textarea id="event_description" name="event_description" required maxlength="100"
                                      placeholder="Describe the event, its purpose, and what participants can expect"><?php echo htmlspecialchars($formData['EventDescription']); ?></textarea>
                            <div class="form-help">Provide detailed information about the event</div>
                        </div>
                    </div>

                    <!-- Date and Status Management Section -->
                    <div class="form-section">
                        <div class="section-title">
                            <i class="fas fa-calendar-check"></i>
                            Date & Status Management
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="event_date">
                                    <i class="fas fa-calendar-day"></i>
                                    Event Date
                                </label>
                                <input type="date" id="event_date" name="event_date" 
                                       value="<?php echo htmlspecialchars($formData['EventDate']); ?>" required>
                                <div class="form-help">Select the date for the event</div>
                            </div>

                            <div class="form-group">
                                <label for="event_day">
                                    <i class="fas fa-clock"></i>
                                    Day of Week
                                </label>
                                <div class="input-container">
                                    <input type="text" id="event_day" name="event_day" 
                                           value="<?php echo htmlspecialchars($formData['EventDay']); ?>" 
                                           maxlength="15" placeholder="Will be auto-generated" readonly>
                                    <span class="auto-indicator">
                                        <i class="fas fa-magic"></i> Auto
                                    </span>
                                </div>
                                <div class="form-help">Automatically generated from the selected date</div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="event_status">
                                <i class="fas fa-info-circle"></i>
                                Event Status
                            </label>
                            <select id="event_status" name="event_status" required>
                                <option value="Upcoming" <?php echo ($formData['EventStatus'] === 'Upcoming') ? 'selected' : ''; ?>>Upcoming</option>
                                <option value="Ongoing" <?php echo ($formData['EventStatus'] === 'Ongoing') ? 'selected' : ''; ?>>Ongoing</option>
                                <option value="Completed" <?php echo ($formData['EventStatus'] === 'Completed') ? 'selected' : ''; ?>>Completed</option>
                                <option value="Cancelled" <?php echo ($formData['EventStatus'] === 'Cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                            </select>

                            <!-- Auto Status Update Toggle -->
                            <div class="auto-status-toggle">
                                <span class="toggle-switch" id="autoStatusToggle" onclick="toggleAutoStatus()"></span>
                                <label for="auto_update_status">Automatically update status based on event date</label>
                                <input type="hidden" id="auto_update_status" name="auto_update_status" value="0">
                            </div>

                            <!-- Status Suggestion -->
                            <div class="status-suggestion" id="statusSuggestion">
                                <i class="fas fa-lightbulb"></i>
                                <span class="status-suggestion-text" id="suggestionText">
                                    Based on the selected date, the status should be: <strong><?php echo $suggestedStatus; ?></strong>
                                </span>
                            </div>

                            <div class="form-help">Update the current status of the event or enable auto-update</div>
                        </div>
                    </div>

                    <!-- Venue Information Section -->
                    <div class="form-section">
                        <div class="section-title">
                            <i class="fas fa-map-marker-alt"></i>
                            Venue Information
                        </div>

                        <div class="form-group">
                            <label for="event_venue">
                                <i class="fas fa-map-marker-alt"></i>
                                Event Venue
                            </label>
                            <input type="text" id="event_venue" name="event_venue" 
                                   value="<?php echo htmlspecialchars($formData['EventVenue']); ?>" 
                                   required maxlength="100" 
                                   placeholder="Enter the venue or address where the event will be held">
                            <div class="form-help">Specify the location where the event will take place</div>
                        </div>
                    </div>

                    <!-- Notification Settings Section -->
                    <div class="form-section">
                        <div class="notification-options">
                            <div class="notification-header">
                                <i class="fas fa-envelope"></i>
                                Email Notification Settings
                            </div>
                            
                            <div class="checkbox-group">
                                <input type="checkbox" id="send_notifications" name="send_notifications" value="1" checked>
                                <label for="send_notifications">
                                    Send email notifications to all students about this update
                                </label>
                            </div>
                            
                            <div class="form-help">
                                <i class="fas fa-info-circle"></i>
                                Notifications will be sent to all students in the system.
                                The email will include details about what has changed.
                                <?php if ($studentCount > 0): ?>
                                    <strong><?= $studentCount ?> students</strong> will receive email notifications.
                                <?php else: ?>
                                    <strong>No students with email addresses found</strong> - only in-app notifications will be sent.
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Form Actions -->
                    <div class="form-actions">
                        <a href="staff_view_event.php?id=<?php echo $eventId; ?>" class="btn btn-secondary">
                            <i class="fas fa-times"></i>
                            Cancel
                        </a>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save"></i>
                            Update Event
                        </button>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <script>
        // Enhanced JavaScript functionality
        function toggleAutoStatus() {
            const toggle = document.getElementById('autoStatusToggle');
            const hiddenInput = document.getElementById('auto_update_status');
            const statusSelect = document.getElementById('event_status');
            
            toggle.classList.toggle('active');
            const isActive = toggle.classList.contains('active');
            
            hiddenInput.value = isActive ? '1' : '0';
            statusSelect.disabled = isActive;
            
            if (isActive) {
                updateStatusFromDate();
                statusSelect.style.opacity = '0.6';
            } else {
                statusSelect.style.opacity = '1';
            }
        }

        function updateStatusFromDate() {
            const dateInput = document.getElementById('event_date');
            const statusSelect = document.getElementById('event_status');
            const suggestionDiv = document.getElementById('statusSuggestion');
            const suggestionText = document.getElementById('suggestionText');
            const autoToggle = document.getElementById('autoStatusToggle');
            
            if (!dateInput.value) return;
            
            const eventDate = new Date(dateInput.value);
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            eventDate.setHours(0, 0, 0, 0);
            
            let suggestedStatus;
            let statusColor;
            
            if (eventDate < today) {
                suggestedStatus = 'Completed';
                statusColor = '#28a745';
            } else if (eventDate.getTime() === today.getTime()) {
                suggestedStatus = 'Ongoing';
                statusColor = '#ffc107';
            } else {
                suggestedStatus = 'Upcoming';
                statusColor = '#007bff';
            }
            
            // Update suggestion display
            suggestionText.innerHTML = `Based on the selected date, the status should be: <strong style="color: ${statusColor}">${suggestedStatus}</strong>`;
            
            // If auto-update is enabled, update the select
            if (autoToggle.classList.contains('active')) {
                statusSelect.value = suggestedStatus;
            }
        }

        function generateDayFromDate() {
            const dateInput = document.getElementById('event_date');
            const dayInput = document.getElementById('event_day');
            
            if (dateInput.value) {
                const date = new Date(dateInput.value);
                const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                dayInput.value = days[date.getDay()];
                
                // Also update status suggestion
                updateStatusFromDate();
            }
        }

        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            // Set up event listeners
            document.getElementById('event_date').addEventListener('change', generateDayFromDate);
            document.getElementById('event_date').addEventListener('change', updateStatusFromDate);
            
            // Initialize day and status on page load
            if (document.getElementById('event_date').value) {
                generateDayFromDate();
                updateStatusFromDate();
            }

            // Auto-hide alerts
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                setTimeout(function() {
                    alert.style.opacity = '0';
                    setTimeout(function() {
                        alert.remove();
                    }, 300);
                }, 5000);
            });

            // Form validation with notification confirmation
            document.querySelector('form').addEventListener('submit', function(e) {
                const dateInput = document.getElementById('event_date');
                const statusSelect = document.getElementById('event_status');
                const autoUpdate = document.getElementById('auto_update_status');
                const sendNotifications = document.getElementById('send_notifications');
                const eventTitle = document.getElementById('event_title').value.trim();
                
                // Basic validation
                if (!eventTitle) {
                    alert('Please enter an event title.');
                    e.preventDefault();
                    return false;
                }
                
                // Date validation for non-completed events
                if (autoUpdate.value !== '1') {
                    const eventDate = new Date(dateInput.value);
                    const today = new Date();
                    today.setHours(0, 0, 0, 0);
                    eventDate.setHours(0, 0, 0, 0);
                    
                    if (statusSelect.value !== 'Completed' && statusSelect.value !== 'Cancelled' && eventDate < today) {
                        if (!confirm('The event date is in the past but status is not set to Completed or Cancelled. Do you want to continue?')) {
                            e.preventDefault();
                            return false;
                        }
                    }
                }
                
                // Notification confirmation
                if (sendNotifications.checked) {
                    const studentCount = <?= $studentCount ?>;
                    let confirmMessage = '';
                    
                    if (studentCount > 0) {
                        confirmMessage = `🔄 UPDATE EVENT & SEND NOTIFICATIONS\n\n` +
                                       `Event: "${eventTitle}"\n` +
                                       `Date: ${dateInput.value}\n\n` +
                                       `📧 NOTIFICATION STATUS:\n` +
                                       `• ${studentCount} students will receive EMAIL notifications\n` +
                                       `• ${studentCount} students will receive IN-APP notifications\n\n` +
                                       `📝 Students will be notified about what has changed.\n\n` +
                                       `Are you sure you want to update this event and send notifications?`;
                    } else {
                        confirmMessage = `🔄 UPDATE EVENT (LIMITED NOTIFICATIONS)\n\n` +
                                       `Event: "${eventTitle}"\n` +
                                       `Date: ${dateInput.value}\n\n` +
                                       `📧 NOTIFICATION STATUS:\n` +
                                       `• Students will receive IN-APP notifications only\n` +
                                       `• No email notifications (no email addresses found)\n\n` +
                                       `Are you sure you want to update this event?`;
                    }
                    
                    if (!confirm(confirmMessage)) {
                        e.preventDefault();
                        return false;
                    }
                }
                
                // Show loading state
                const submitBtn = document.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
                submitBtn.disabled = true;
                
                // Re-enable after 10 seconds as fallback
                setTimeout(() => {
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }, 10000);
            });

            // Add entrance animations
            const elements = document.querySelectorAll('.page-header, .form-section');
            elements.forEach((el, index) => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    el.style.transition = 'all 0.6s ease';
                    el.style.opacity = '1';
                    el.style.transform = 'translateY(0)';
                }, index * 100);
            });

            // Notification toggle functionality
            const notificationCheckbox = document.getElementById('send_notifications');
            const notificationOptions = document.querySelector('.notification-options');
            
            notificationCheckbox.addEventListener('change', function() {
                if (this.checked) {
                    notificationOptions.style.borderColor = 'rgba(102, 126, 234, 0.4)';
                    notificationOptions.style.background = 'rgba(255, 255, 255, 0.95)';
                } else {
                    notificationOptions.style.borderColor = 'rgba(102, 126, 234, 0.2)';
                    notificationOptions.style.background = 'rgba(255, 255, 255, 0.7)';
                }
            });

            console.log('✅ Enhanced Event Update Page Loaded Successfully');
        });

        // Mobile sidebar toggle
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('open');
        }

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const menuBtn = document.querySelector('.mobile-menu-btn');
            
            if (window.innerWidth <= 968 && 
                !sidebar.contains(event.target) && 
                !menuBtn.contains(event.target) && 
                sidebar.classList.contains('open')) {
                sidebar.classList.remove('open');
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+S to save
            if (e.ctrlKey && e.key === 's') {
                e.preventDefault();
                document.querySelector('form').submit();
            }
            
            // Escape to cancel
            if (e.key === 'Escape') {
                window.location.href = 'staff_view_event.php?id=<?php echo $eventId; ?>';
            }
            
            // Ctrl+N to toggle notifications
            if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                const checkbox = document.getElementById('send_notifications');
                checkbox.checked = !checkbox.checked;
                checkbox.dispatchEvent(new Event('change'));
            }
            
            // Ctrl+A to toggle auto status
            if (e.ctrlKey && e.key === 'a') {
                e.preventDefault();
                toggleAutoStatus();
            }
        });

        // Real-time form validation feedback
        document.querySelectorAll('input[required], textarea[required], select[required]').forEach(field => {
            field.addEventListener('blur', function() {
                if (this.value.trim() === '') {
                    this.style.borderColor = '#dc3545';
                    this.style.boxShadow = '0 0 0 3px rgba(220, 53, 69, 0.1)';
                } else {
                    this.style.borderColor = '#28a745';
                    this.style.boxShadow = '0 0 0 3px rgba(40, 167, 69, 0.1)';
                    
                    // Reset to normal after 2 seconds
                    setTimeout(() => {
                        this.style.borderColor = '';
                        this.style.boxShadow = '';
                    }, 2000);
                }
            });
        });

        // Character count for text areas
        document.querySelectorAll('textarea[maxlength], input[maxlength]').forEach(field => {
            const maxLength = field.getAttribute('maxlength');
            if (maxLength) {
                const counter = document.createElement('div');
                counter.className = 'character-counter';
                counter.style.cssText = `
                    position: absolute;
                    right: 10px;
                    bottom: 10px;
                    font-size: 12px;
                    color: #666;
                    background: rgba(255, 255, 255, 0.9);
                    padding: 2px 6px;
                    border-radius: 4px;
                `;
                
                const container = field.parentElement;
                if (container.style.position !== 'relative') {
                    container.style.position = 'relative';
                }
                container.appendChild(counter);
                
                function updateCounter() {
                    const remaining = maxLength - field.value.length;
                    counter.textContent = `${remaining} remaining`;
                    
                    if (remaining < 10) {
                        counter.style.color = '#dc3545';
                    } else if (remaining < 20) {
                        counter.style.color = '#ffc107';
                    } else {
                        counter.style.color = '#666';
                    }
                }
                
                field.addEventListener('input', updateCounter);
                updateCounter(); // Initial count
            }
        });
    </script>
</body>
</html>