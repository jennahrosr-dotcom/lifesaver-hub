<?php
session_start();
if (!isset($_SESSION['student_id'])) {
    header("Location: index.php");
    exit;
}

require 'PHPMailer/PHPMailer-master/src/PHPMailer.php';
require 'PHPMailer/PHPMailer-master/src/SMTP.php';
require 'PHPMailer/PHPMailer-master/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$studentId = $_SESSION['student_id'];

// Get student information
$studentStmt = $pdo->prepare("SELECT StudentName, StudentEmail FROM student WHERE StudentID = ?");
$studentStmt->execute([$studentId]);
$student = $studentStmt->fetch();

if (!$student) {
    die("Student not found.");
}

$studentEmail = $student['StudentEmail'];
$studentName = $student['StudentName'];

// Handle response from notification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['notification_id'], $_POST['response'])) {
    $notificationId = $_POST['notification_id'];
    $response = $_POST['response'];

    // Create a student-specific response by creating a new notification record for this student
    // This allows multiple students to respond to the same event notification
    
    // First, get the original notification details
    $originalNotification = $pdo->prepare("SELECT * FROM notification WHERE NotificationID = ?");
    $originalNotification->execute([$notificationId]);
    $origNotif = $originalNotification->fetch();
    
    if ($origNotif) {
        // Check if this student already has a response to this notification
        $existingResponse = $pdo->prepare("
            SELECT COUNT(*) FROM notification n1
            JOIN registration r1 ON r1.RegistrationID = n1.RegistrationID
            JOIN registration r2 ON r2.EventID = r1.EventID
            WHERE r2.RegistrationID = ? AND r1.StudentID = ? AND n1.NotificationIsRead != '0' AND n1.NotificationIsRead != ''
        ");
        $existingResponse->execute([$origNotif['RegistrationID'], $studentId]);
        
        if ($existingResponse->fetchColumn() == 0) {
            // Create a student-specific registration for tracking their response
            $studentRegStmt = $pdo->prepare("
                INSERT INTO registration (StudentID, EventID, RegistrationDate, RegistrationStatus, AttendanceStatus) 
                SELECT ?, EventID, NOW(), 'Response', 'N/A' 
                FROM registration 
                WHERE RegistrationID = ?
            ");
            $studentRegStmt->execute([$studentId, $origNotif['RegistrationID']]);
            $studentRegistrationId = $pdo->lastInsertId();
            
            // Create a response notification for this student
            $responseNotification = $pdo->prepare("
                INSERT INTO notification (NotificationTitle, NotificationMessage, NotificationDate, NotificationIsRead, RegistrationID) 
                VALUES (?, ?, NOW(), ?, ?)
            ");
            $responseTitle = "[RESPONSE] " . $origNotif['NotificationTitle'];
            $responseMessage = "Your response: " . $response . "\n\n" . $origNotif['NotificationMessage'];
            $responseNotification->execute([$responseTitle, $responseMessage, $response, $studentRegistrationId]);
        }
    }

    // If user selects "Interested", create a registration
    if ($response === 'Interested') {
        // Get event ID from the original notification
        $eventStmt = $pdo->prepare("
            SELECT r.EventID FROM registration r 
            WHERE r.RegistrationID = ?
        ");
        $eventStmt->execute([$origNotif['RegistrationID']]);
        $eventData = $eventStmt->fetch();
        
        if ($eventData) {
            // Check if student is already registered for this event (excluding response registrations)
            $checkStmt = $pdo->prepare("
                SELECT COUNT(*) FROM registration 
                WHERE StudentID = ? AND EventID = ? AND RegistrationStatus != 'Response'
            ");
            $checkStmt->execute([$studentId, $eventData['EventID']]);
            
            if ($checkStmt->fetchColumn() == 0) {
                // Create new registration for the interested student
                $newRegStmt = $pdo->prepare("INSERT INTO registration (StudentID, EventID, RegistrationDate, RegistrationStatus, AttendanceStatus) VALUES (?, ?, NOW(), 'Confirmed', 'Pending')");
                $newRegStmt->execute([$studentId, $eventData['EventID']]);
                
                echo "<div style='background:#d4edda; color:#155724; padding:15px; border-radius:8px; margin:15px 0; border-left:4px solid #28a745;'>✅ <strong>Registration Successful!</strong><br>You have been registered for this event. Check your registrations in the View Events section.</div>";
            } else {
                echo "<div style='background:#fff3cd; color:#856404; padding:15px; border-radius:8px; margin:15px 0; border-left:4px solid #ffc107;'>ℹ️ <strong>Already Registered</strong><br>You are already registered for this event.</div>";
            }
        }
    }

    // If user wants reminder, send email
    if ($response === 'Remind Me') {
        // Get event details from notification message or linked registration
        $notificationStmt = $pdo->prepare("SELECT RegistrationID, NotificationTitle, NotificationMessage FROM notification WHERE NotificationID = ?");
        $notificationStmt->execute([$notificationId]);
        $notificationData = $notificationStmt->fetch();
        
        $event = null;
        
        if ($notificationData && $notificationData['RegistrationID']) {
            // Get event from registration
            $eventStmt = $pdo->prepare("SELECT e.EventTitle, e.EventDate, e.EventVenue 
                                        FROM event e 
                                        JOIN registration r ON r.EventID = e.EventID 
                                        WHERE r.RegistrationID = ?");
            $eventStmt->execute([$notificationData['RegistrationID']]);
            $event = $eventStmt->fetch();
        } else {
            // Try to find event from notification title
            $title = str_replace("New Blood Donation Event: ", "", $notificationData['NotificationTitle']);
            $eventStmt = $pdo->prepare("SELECT EventTitle, EventDate, EventVenue FROM event WHERE EventTitle = ? ORDER BY EventDate DESC LIMIT 1");
            $eventStmt->execute([$title]);
            $event = $eventStmt->fetch();
        }

        if ($event) {
            $eventDate = new DateTime($event['EventDate']);
            $now = new DateTime();
            $hoursRemaining = ($eventDate->getTimestamp() - $now->getTimestamp()) / 3600;

            // Send reminder email if event is within 48 hours
            if ($hoursRemaining <= 48 && $hoursRemaining > 0) {
                try {
                    $mail = new PHPMailer(true);
                    $mail->isSMTP();
                    $mail->Host = 'smtp.gmail.com'; // Replace with actual SMTP host
                    $mail->SMTPAuth = true;
                    $mail->Username = 'your_email@gmail.com'; // Replace with actual email
                    $mail->Password = 'your_app_password'; // Replace with actual password
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port = 587;

                    $mail->setFrom('lifesaverhub@gmail.com', 'LifeSaver Hub');
                    $mail->addAddress($studentEmail, $studentName);
                    $mail->isHTML(true);
                    $mail->Subject = '⏰ Reminder: Upcoming Blood Donation Event';
                    $mail->Body = "
                        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                            <h2 style='color: #1d3557;'>🩸 Blood Donation Event Reminder</h2>
                            <p>Hi <strong>$studentName</strong>,</p>
                            <p>This is a friendly reminder about the upcoming blood donation event:</p>
                            <div style='background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                                <h3 style='color: #d62828; margin-top: 0;'>📅 Event Details:</h3>
                                <ul style='list-style: none; padding: 0;'>
                                    <li><strong>🎯 Event:</strong> {$event['EventTitle']}</li>
                                    <li><strong>📆 Date:</strong> {$event['EventDate']}</li>
                                    <li><strong>📍 Venue:</strong> {$event['EventVenue']}</li>
                                </ul>
                            </div>
                            <p style='color: #1d3557;'>Thank you for being a hero and helping save lives! Every donation can save up to 3 lives.</p>
                            <p style='color: #666;'>Best regards,<br><strong>LifeSaver Hub Team</strong></p>
                        </div>
                    ";
                    $mail->send();
                    echo "<div style='background:#d1ecf1; color:#0c5460; padding:15px; border-radius:8px; margin:15px 0; border-left:4px solid #17a2b8;'>📧 <strong>Reminder Sent!</strong><br>A reminder email has been sent to your email address.</div>";
                } catch (Exception $e) {
                    echo "<div style='background:#f8d7da; color:#721c24; padding:15px; border-radius:8px; margin:15px 0; border-left:4px solid #dc3545;'>❌ <strong>Email Failed</strong><br>Failed to send reminder email. Please check your email settings.</div>";
                }
            } else {
                echo "<div style='background:#fff3cd; color:#856404; padding:15px; border-radius:8px; margin:15px 0; border-left:4px solid #ffc107;'>⏰ <strong>Reminder Scheduled</strong><br>You will receive a reminder email closer to the event date (within 48 hours).</div>";
            }
        }
    }

    if ($response === 'Not Interested') {
        echo "<div style='background:#f8d7da; color:#721c24; padding:15px; border-radius:8px; margin:15px 0; border-left:4px solid #dc3545;'>❌ <strong>Response Recorded</strong><br>You have marked this event as 'Not Interested'. You can always change your mind later.</div>";
    }
}

// Get all registrations for this student to find their notifications
$studentRegistrations = $pdo->prepare("SELECT RegistrationID FROM registration WHERE StudentID = ?");
$studentRegistrations->execute([$studentId]);
$registrationIds = $studentRegistrations->fetchAll(PDO::FETCH_COLUMN);

// Also get notifications that might not be linked to registrations (for new events)
$allNotifications = [];

if (!empty($registrationIds)) {
    // Get notifications linked to student's registrations
    $placeholders = str_repeat('?,', count($registrationIds) - 1) . '?';
    $notifications = $pdo->prepare("SELECT * FROM notification WHERE RegistrationID IN ($placeholders) ORDER BY NotificationDate DESC");
    $notifications->execute($registrationIds);
    $allNotifications = array_merge($allNotifications, $notifications->fetchAll());
}

// Get general notifications (that might not be linked to specific registrations)
// We'll simulate this by getting recent notifications and filtering by title patterns
$generalNotifications = $pdo->prepare("SELECT * FROM notification WHERE NotificationTitle LIKE '%Blood Donation Event%' OR NotificationTitle LIKE '%Event%' ORDER BY NotificationDate DESC LIMIT 50");
$generalNotifications->execute();
$generalNotifs = $generalNotifications->fetchAll();

// Merge and remove duplicates
foreach ($generalNotifs as $notif) {
    $exists = false;
    foreach ($allNotifications as $existing) {
        if ($existing['NotificationID'] == $notif['NotificationID']) {
            $exists = true;
            break;
        }
    }
    if (!$exists) {
        $allNotifications[] = $notif;
    }
}

// Sort by date (newest first)
usort($allNotifications, function($a, $b) {
    return strtotime($b['NotificationDate']) - strtotime($a['NotificationDate']);
});

$rows = $allNotifications;
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Notifications - LifeSaver Hub</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f4f6fa 0%, #e9ecef 100%);
            margin: 0;
            padding: 0;
        }
        .sidebar {
            height: 100vh;
            width: 250px;
            position: fixed;
            top: 0;
            left: 0;
            background: linear-gradient(180deg, #1d3557 0%, #457b9d 100%);
            padding-top: 30px;
            color: white;
            box-shadow: 3px 0 10px rgba(0,0,0,0.1);
        }
        .sidebar h2 {
            text-align: center;
            margin-bottom: 30px;
            font-size: 24px;
            font-weight: 300;
        }
        .sidebar a {
            display: block;
            color: white;
            padding: 15px 20px;
            text-decoration: none;
            font-size: 16px;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
        }
        .sidebar a:hover {
            background-color: rgba(255,255,255,0.1);
            border-left-color: #f1faee;
            padding-left: 25px;
        }
        .sidebar a.active {
            background-color: rgba(255,255,255,0.2);
            border-left-color: #f1faee;
        }
        .main {
            margin-left: 270px;
            padding: 30px;
            min-height: 100vh;
            width: calc(100% - 270px);
            box-sizing: border-box;
            overflow-x: auto; /* Handle horizontal overflow */
        }
        h1 {
            color: #1d3557;
            font-size: 2.5em;
            margin-bottom: 30px;
            margin-top: 0;
            text-align: center;
            font-weight: 300;
            padding-top: 10px; /* Extra padding to prevent hiding */
        }
        .notification {
            background: white;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            border-left: 5px solid #1d3557;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            word-wrap: break-word; /* Prevent text overflow */
            overflow-wrap: break-word; /* Better text wrapping */
        }
        .notification:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
        }
        .notification.responded {
            border-left-color: #28a745;
            background: linear-gradient(135deg, #f8fff9 0%, #ffffff 100%);
        }
        .notification.expired {
            border-left-color: #dc3545;
            background: linear-gradient(135deg, #fff8f8 0%, #ffffff 100%);
            opacity: 0.8;
        }
        .notification h3 {
            color: #1d3557;
            margin-top: 0;
            margin-bottom: 15px;
            font-size: 1.3em;
            font-weight: 600;
        }
        .notification p {
            color: #495057;
            line-height: 1.6;
            margin-bottom: 15px;
        }
        form {
            margin-top: 20px;
        }
        button {
            padding: 12px 20px;
            margin-right: 10px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .interested { 
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(0, 123, 255, 0.3);
        }
        .interested:hover { 
            background: linear-gradient(135deg, #0056b3 0%, #003d82 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 123, 255, 0.4);
        }
        .remind { 
            background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
        }
        .remind:hover { 
            background: linear-gradient(135deg, #1e7e34 0%, #155724 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
        }
        .not { 
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(220, 53, 69, 0.3);
        }
        .not:hover { 
            background: linear-gradient(135deg, #c82333 0%, #a71e2a 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(220, 53, 69, 0.4);
        }
        .status {
            font-style: italic;
            margin-top: 15px;
            padding: 10px 15px;
            border-radius: 8px;
            font-weight: 500;
        }
        .status.interested {
            background: linear-gradient(135deg, #cce7ff 0%, #e3f2fd 100%);
            color: #0056b3;
            border-left: 4px solid #007bff;
        }
        .status.remind-me {
            background: linear-gradient(135deg, #d4edda 0%, #e8f5e8 100%);
            color: #1e7e34;
            border-left: 4px solid #28a745;
        }
        .status.not-interested {
            background: linear-gradient(135deg, #f8d7da 0%, #fae2e4 100%);
            color: #a71e2a;
            border-left: 4px solid #dc3545;
        }
        .view-link {
            display: inline-block;
            margin-top: 12px;
            color: #1d3557;
            font-weight: 600;
            text-decoration: none;
            padding: 8px 15px;
            border-radius: 6px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            transition: all 0.3s ease;
        }
        .view-link:hover {
            background: linear-gradient(135deg, #e9ecef 0%, #dee2e6 100%);
            transform: translateY(-1px);
        }
        .event-details {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
            border: 1px solid #e9ecef;
        }
        .event-details strong {
            color: #1d3557;
        }
        .notification-meta {
            font-size: 0.9em;
            color: #6c757d;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .notification-date {
            background: #e9ecef;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8em;
        }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }
        .empty-state h3 {
            color: #495057;
            margin-bottom: 10px;
        }
        .stats-bar {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 30px;
            margin-top: 10px; /* Add some top margin */
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            display: flex;
            justify-content: space-around;
            text-align: center;
            flex-wrap: wrap; /* Allow wrapping on smaller screens */
        }
        .stat {
            flex: 1;
        }
        .stat-number {
            font-size: 2em;
            font-weight: bold;
            color: #1d3557;
        }
        .stat-label {
            color: #6c757d;
            font-size: 0.9em;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        /* Responsive design */
        @media (max-width: 768px) {
            .sidebar {
                width: 200px;
            }
            .main {
                margin-left: 220px;
                width: calc(100% - 220px);
                padding: 20px;
            }
            h1 {
                font-size: 2em;
            }
            .stats-bar {
                flex-direction: column;
                gap: 15px;
            }
            .stat {
                margin-bottom: 10px;
            }
        }
        
        @media (max-width: 480px) {
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
            }
            .main {
                margin-left: 0;
                width: 100%;
                padding: 15px;
            }
            h1 {
                font-size: 1.8em;
            }
        }
    </style>
</head>
<body>

<div class="sidebar">
    <h2>LifeSaver Hub</h2>
    <a href="student_account.php">My Account</a>
    <a href="dashboard.php">Dashboard</a>
    <a href="view_event.php">View Events</a>
    <a href="health_questionnaire.php">Health Questions</a>
    <a href="notifications.php" class="active">Notifications</a>
    <a href="view_donation.php">View Donations</a>
    <a href="update_donation.php">Update Donation</a>
    <a href="delete_donation.php">Delete Donation</a>
    <a href="donation_history.php">Donation History</a>
    <a href="view_rewards.php">My Rewards</a>
    <a href="logout.php">Logout</a>
</div>

<div class="main">
    <h1>📬 Your Event Notifications</h1>

    <?php 
    // Calculate statistics
    $totalNotifications = count($rows);
    $respondedCount = 0;
    $interestedCount = 0;
    $pendingCount = 0;
    
    foreach ($rows as $notification) {
        if (!empty($notification['NotificationIsRead']) && $notification['NotificationIsRead'] !== '0') {
            $respondedCount++;
            if ($notification['NotificationIsRead'] === 'Interested') {
                $interestedCount++;
            }
        } else {
            $pendingCount++;
        }
    }
    ?>

    <?php if ($totalNotifications > 0): ?>
    <div class="stats-bar">
        <div class="stat">
            <div class="stat-number"><?= $totalNotifications ?></div>
            <div class="stat-label">Total Notifications</div>
        </div>
        <div class="stat">
            <div class="stat-number"><?= $interestedCount ?></div>
            <div class="stat-label">Events Interested</div>
        </div>
        <div class="stat">
            <div class="stat-number"><?= $pendingCount ?></div>
            <div class="stat-label">Pending Response</div>
        </div>
        <div class="stat">
            <div class="stat-number"><?= $respondedCount ?></div>
            <div class="stat-label">Total Responded</div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (empty($rows)): ?>
        <div class="notification empty-state">
            <h3>📭 No Notifications Yet</h3>
            <p>You haven't received any event notifications yet. When staff create new blood donation events, you'll see them here!</p>
            <p><strong>What happens next?</strong></p>
            <ul style="list-style: none; padding: 0; color: #6c757d;">
                <li>🩸 Staff will post new blood donation events</li>
                <li>📨 You'll receive notifications about upcoming events</li>
                <li>✅ You can express interest and get automatically registered</li>
                <li>⏰ Set reminders for events you want to attend</li>
            </ul>
        </div>
    <?php endif; ?>

    <?php foreach ($rows as $n): ?>
        <?php
            // Determine if notification is about a past event
            $isPast = false;
            $hasResponded = false;
            $studentResponse = '';
            
            // Check if this student has responded to this notification
            $responseCheck = $pdo->prepare("
                SELECT n2.NotificationIsRead 
                FROM notification n1
                JOIN registration r1 ON r1.RegistrationID = n1.RegistrationID
                JOIN registration r2 ON r2.EventID = r1.EventID
                JOIN notification n2 ON n2.RegistrationID = r2.RegistrationID
                WHERE n1.NotificationID = ? AND r2.StudentID = ? AND n2.NotificationTitle LIKE '[RESPONSE]%'
                LIMIT 1
            ");
            $responseCheck->execute([$n['NotificationID'], $studentId]);
            $responseResult = $responseCheck->fetch();
            
            if ($responseResult) {
                $hasResponded = true;
                $studentResponse = $responseResult['NotificationIsRead'];
            }
            
            // Try to extract date from notification message or find linked event
            if ($n['RegistrationID']) {
                $eventCheckStmt = $pdo->prepare("SELECT e.EventDate, e.EventStatus FROM event e JOIN registration r ON r.EventID = e.EventID WHERE r.RegistrationID = ?");
                $eventCheckStmt->execute([$n['RegistrationID']]);
                $eventCheck = $eventCheckStmt->fetch();
                if ($eventCheck) {
                    $isPast = ($eventCheck['EventStatus'] === 'Past' || strtotime($eventCheck['EventDate']) < strtotime(date('Y-m-d')));
                }
            }
            
            $notificationClass = '';
            if ($hasResponded) {
                $notificationClass = 'responded';
            } elseif ($isPast) {
                $notificationClass = 'expired';
            }
        ?>
        
        <div class="notification <?= $notificationClass ?>">
            <div class="notification-meta">
                <span><strong>📬 Event Notification</strong></span>
                <span class="notification-date"><?= date('M j, Y g:i A', strtotime($n['NotificationDate'])) ?></span>
            </div>
            
            <h3><?= htmlspecialchars($n['NotificationTitle']) ?></h3>
            <p><?= nl2br(htmlspecialchars($n['NotificationMessage'])) ?></p>
            
            <a href="view_event.php" class="view-link">🔍 View All Events</a>

            <?php if ($hasResponded): ?>
                <div class="status <?= strtolower(str_replace(' ', '-', $studentResponse)) ?>">
                    <?php 
                    $responseIcon = '';
                    switch($studentResponse) {
                        case 'Interested': $responseIcon = '✅'; break;
                        case 'Remind Me': $responseIcon = '⏰'; break;
                        case 'Not Interested': $responseIcon = '❌'; break;
                        default: $responseIcon = '📝'; break;
                    }
                    ?>
                    <?= $responseIcon ?> <strong>Your Response:</strong> <?= htmlspecialchars($studentResponse) ?>
                    <?php if ($studentResponse === 'Interested'): ?>
                        <br><small>You have been registered for this event!</small>
                    <?php elseif ($studentResponse === 'Remind Me'): ?>
                        <br><small>You will receive a reminder email before the event.</small>
                    <?php endif; ?>
                </div>
            <?php elseif ($isPast): ?>
                <div class="status" style="color: #6c757d; background: #f8f9fa; border-left: 4px solid #dee2e6;">
                    ⏰ <strong>Event Expired:</strong> This event has already passed.
                </div>
            <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="notification_id" value="<?= $n['NotificationID'] ?>">
                    <button name="response" value="Interested" class="interested">✓ I'm Interested</button>
                    <button name="response" value="Remind Me" class="remind">⏰ Remind Me</button>
                    <button name="response" value="Not Interested" class="not">✗ Not Interested</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <?php if (!empty($rows)): ?>
    <div style="text-align: center; margin-top: 40px; padding: 20px; color: #6c757d;">
        <p><strong>💡 Pro Tip:</strong> Clicking "Interested" will automatically register you for the event!</p>
        <p>📧 "Remind Me" will send you an email reminder within 48 hours of the event.</p>
    </div>
    <?php endif; ?>
</div>

<script>
// Auto-refresh page every 30 seconds to check for new notifications
setInterval(function() {
    // Only refresh if user hasn't interacted recently
    if (document.visibilityState === 'visible') {
        const lastActivity = sessionStorage.getItem('lastActivity');
        const now = new Date().getTime();
        if (!lastActivity || (now - parseInt(lastActivity)) > 30000) {
            // Soft refresh - only if no form submissions in progress
            if (!document.querySelector('form button:disabled')) {
                window.location.reload();
            }
        }
    }
}, 30000);

// Track user activity
document.addEventListener('click', function() {
    sessionStorage.setItem('lastActivity', new Date().getTime());
});

// Disable button after click to prevent double submission
document.querySelectorAll('form button').forEach(button => {
    button.addEventListener('click', function() {
        const form = this.closest('form');
        setTimeout(() => {
            form.querySelectorAll('button').forEach(btn => {
                btn.disabled = true;
                btn.style.opacity = '0.6';
                btn.innerHTML = btn.innerHTML.includes('✓') ? '✓ Processing...' : 
                               btn.innerHTML.includes('⏰') ? '⏰ Processing...' : 
                               '❌ Processing...';
            });
        }, 100);
    });
});
</script>

</body>
</html>