<?php
session_start();

if (!isset($_SESSION['staff_id'])) {
    header("Location: staff_login.php");
    exit;
}

$pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

// Email configuration
$email_config = array(
    'smtp_host' => 'smtp.gmail.com',
    'smtp_port' => 587,
    'smtp_username' => 'jennahrosr@gmail.com',
    'smtp_password' => 'ckgsjiiizwoitino',
    'from_email' => 'jennahrosr@gmail.com',
    'from_name' => 'LifeSaver Hub - Event Reminders'
);

// Set timezone
date_default_timezone_set('Asia/Kuala_Lumpur');

// Handle reminder email sending
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_reminder']) && isset($_POST['event_id'])) {
    $event_id = (int)$_POST['event_id'];
    $reminder_result = sendEventReminders($pdo, $event_id, $email_config);
    
    // Redirect with result
    if ($reminder_result['success']) {
        header("Location: staff_view_event.php?reminder_sent=success&emails_count=" . $reminder_result['emails_sent'] . "&event_title=" . urlencode($reminder_result['event_title']));
    } else {
        header("Location: staff_view_event.php?reminder_sent=error&message=" . urlencode($reminder_result['message']));
    }
    exit;
}

// Function to send reminder email using PHPMailer
function sendReminderEmail($student_data, $event_data, $email_config) {
    try {
        // Try to load PHPMailer
        $phpmailerPaths = array(
            __DIR__ . '/PHPMailer/src/PHPMailer.php',
            __DIR__ . '/vendor/phpmailer/phpmailer/src/PHPMailer.php',
            'PHPMailer/src/PHPMailer.php',
            './PHPMailer/src/PHPMailer.php',
            '../PHPMailer/src/PHPMailer.php'
        );
        
        $phpmailerFound = false;
        foreach ($phpmailerPaths as $path) {
            if (file_exists($path)) {
                require_once $path;
                require_once dirname($path) . '/SMTP.php';
                require_once dirname($path) . '/Exception.php';
                $phpmailerFound = true;
                break;
            }
        }
        
        if (!$phpmailerFound) {
            return sendFallbackEmail($student_data, $event_data, $email_config);
        }
        
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        // SMTP configuration
        $mail->isSMTP();
        $mail->Host = $email_config['smtp_host'];
        $mail->SMTPAuth = true;
        $mail->Username = $email_config['smtp_username'];
        $mail->Password = $email_config['smtp_password'];
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $email_config['smtp_port'];
        $mail->Timeout = 30;
        $mail->SMTPKeepAlive = false;
        $mail->SMTPAutoTLS = true;
        
        // SSL options
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
        $mail->setFrom($email_config['from_email'], $email_config['from_name']);
        $mail->addAddress($student_data['StudentEmail'], $student_data['StudentName']);
        $mail->addReplyTo($email_config['from_email'], 'LifeSaver Support');
        
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';
        
        $subject = generateEmailSubject($event_data);
        $htmlContent = generateEmailHTML($student_data, $event_data);
        $plainContent = generateEmailPlain($student_data, $event_data);
        
        $mail->Subject = $subject;
        $mail->Body = $htmlContent;
        $mail->AltBody = $plainContent;
        
        return $mail->send();
        
    } catch (Exception $e) {
        return sendFallbackEmail($student_data, $event_data, $email_config);
    }
}

// Fallback email function using PHP mail()
function sendFallbackEmail($student_data, $event_data, $email_config) {
    try {
        $to = $student_data['StudentEmail'];
        $subject = generateEmailSubject($event_data);
        $message = generateEmailHTML($student_data, $event_data);
        
        $headers = array();
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-type: text/html; charset=UTF-8';
        $headers[] = 'From: ' . $email_config['from_name'] . ' <' . $email_config['from_email'] . '>';
        $headers[] = 'Reply-To: ' . $email_config['from_email'];
        $headers[] = 'X-Mailer: PHP/' . phpversion();
        
        return mail($to, $subject, $message, implode("\r\n", $headers));
        
    } catch (Exception $e) {
        return false;
    }
}

// Generate email subject
function generateEmailSubject($event_data) {
    $event_title = htmlspecialchars($event_data['EventTitle']);
    $event_date = date('M j, Y', strtotime($event_data['EventDate']));
    
    return "Event Reminder: $event_title - $event_date";
}

// Generate HTML email content
function generateEmailHTML($student_data, $event_data) {
    $student_name = htmlspecialchars($student_data['StudentName']);
    $event_title = htmlspecialchars($event_data['EventTitle']);
    $event_date = date('l, d F Y', strtotime($event_data['EventDate']));
    $event_venue = htmlspecialchars($event_data['EventVenue'] ?: 'Venue TBD');
    $current_date = date('d F Y, H:i A');
    
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <style>
            body { 
                font-family: Arial, sans-serif; 
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
            .reminder-badge { 
                background: linear-gradient(135deg, #667eea, #764ba2); 
                color: white;
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
            .footer { 
                background: #f8f9fa;
                padding: 30px; 
                text-align: center; 
                color: #6c757d; 
                font-size: 14px; 
            }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>📅 EVENT REMINDER</h1>
                <h2>Blood Donation Event</h2>
            </div>
            
            <div class='content'>
                <p>Dear <strong>$student_name</strong>,</p>
                
                <div class='reminder-badge'>
                    This is a reminder that you're registered for the blood donation event: <strong>$event_title</strong>
                </div>
                
                <div class='event-details'>
                    <h3>📋 Event Details</h3>
                    <p><strong>Event:</strong> $event_title</p>
                    <p><strong>Date:</strong> $event_date</p>
                    <p><strong>Venue:</strong> $event_venue</p>
                    <p><strong>Status:</strong> Please Confirm Your Attendance</p>
                </div>
                
                <p>Please make sure to attend the event as scheduled. Your participation helps save lives!</p>
                
                <p>If you have any questions or need to update your registration, please contact our support team.</p>
                
                <p>Thank you for your commitment to saving lives through blood donation! 🩸❤️</p>
            </div>
            
            <div class='footer'>
                <p><strong>LifeSaver Hub - Blood Donation Management System</strong></p>
                <p>Reminder sent: $current_date</p>
                <p>This is an automated reminder email.</p>
            </div>
        </div>
    </body>
    </html>";
}

// Generate plain text email content
function generateEmailPlain($student_data, $event_data) {
    $student_name = $student_data['StudentName'];
    $event_title = $event_data['EventTitle'];
    $event_date = date('l, d F Y', strtotime($event_data['EventDate']));
    $event_venue = $event_data['EventVenue'] ?: 'Venue TBD';
    $current_date = date('d F Y, H:i A');
    
    return "
BLOOD DONATION EVENT REMINDER

Dear $student_name,

This is a reminder that you're registered for the blood donation event: $event_title

EVENT DETAILS:
Event: $event_title
Date: $event_date
Venue: $event_venue
Status: Please Confirm Your Attendance

Please make sure to attend the event as scheduled. Your participation helps save lives!

If you have any questions or need to update your registration, please contact our support team.

Thank you for your commitment to saving lives through blood donation!

---
LifeSaver Hub - Blood Donation Management System
Reminder sent: $current_date
This is an automated reminder email.
";
}

// Main function to send event reminders
function sendEventReminders($pdo, $event_id, $email_config) {
    try {
        $total_emails_sent = 0;
        $failed_emails = 0;
        
        // Get event details
        $stmt = $pdo->prepare("SELECT * FROM event WHERE EventID = ?");
        $stmt->execute(array($event_id));
        $event = $stmt->fetch();
        
        if (!$event) {
            return array(
                'success' => false,
                'message' => 'Event not found',
                'emails_sent' => 0
            );
        }
        
        // Find students who are registered for this event with RegistrationStatus = 'Registered'
        $stmt = $pdo->prepare("
            SELECT s.*, r.RegistrationStatus, r.RegistrationDate
            FROM student s
            INNER JOIN registration r ON s.StudentID = r.StudentID
            WHERE r.EventID = ? 
            AND r.RegistrationStatus = 'Registered'
            AND s.StudentEmail IS NOT NULL 
            AND s.StudentEmail != ''
            ORDER BY s.StudentName ASC
        ");
        $stmt->execute(array($event_id));
        $students = $stmt->fetchAll();
        
        if (empty($students)) {
            return array(
                'success' => true,
                'message' => 'No registered students found for this event',
                'emails_sent' => 0,
                'event_title' => $event['EventTitle']
            );
        }
        
        // Send emails immediately to all registered students
        foreach ($students as $student) {
            if (sendReminderEmail($student, $event, $email_config)) {
                $total_emails_sent++;
            } else {
                $failed_emails++;
            }
        }
        
        return array(
            'success' => true,
            'message' => "Reminder emails sent to $total_emails_sent students",
            'emails_sent' => $total_emails_sent,
            'failed_emails' => $failed_emails,
            'total_students' => count($students),
            'event_title' => $event['EventTitle']
        );
        
    } catch (Exception $e) {
        return array(
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage(),
            'emails_sent' => 0
        );
    }
}

// Fetch staff data
$stmt = $pdo->prepare("SELECT * FROM staff WHERE StaffID = ?");
$stmt->execute([$_SESSION['staff_id']]);
$staff = $stmt->fetch();

// IMPROVED AUTO-UPDATE EVENT STATUS LOGIC
// Get current date and time for accurate comparison
$currentDateTime = new DateTime();
$currentDate = $currentDateTime->format('Y-m-d');
$currentTime = $currentDateTime->format('H:i:s');

// Update events that have passed their date to 'Completed'
// Only update if they are currently 'Upcoming' or 'Ongoing'
$updateCompletedStmt = $pdo->prepare("
    UPDATE event 
    SET EventStatus = 'Completed' 
    WHERE EventDate < ? 
    AND EventStatus IN ('Upcoming', 'Ongoing')
");
$updateCompletedStmt->execute([$currentDate]);

// Update events that are happening today to 'Ongoing'
// Only update if they are currently 'Upcoming'
$updateOngoingStmt = $pdo->prepare("
    UPDATE event 
    SET EventStatus = 'Ongoing' 
    WHERE EventDate = ? 
    AND EventStatus = 'Upcoming'
");
$updateOngoingStmt->execute([$currentDate]);

// Log the updates for debugging
$completedUpdates = $updateCompletedStmt->rowCount();
$ongoingUpdates = $updateOngoingStmt->rowCount();

if ($completedUpdates > 0 || $ongoingUpdates > 0) {
    error_log("Event status auto-update: {$completedUpdates} events marked as completed, {$ongoingUpdates} events marked as ongoing");
}

$isStaff = isset($_SESSION['staff_id']);
$isStudent = isset($_SESSION['student_id']);

if (!$isStaff && !$isStudent) {
    header("Location: index.php");
    exit;
}

// Build WHERE clause and parameters
$where = [];
$params = [];

// Always exclude deleted events for students
if ($isStudent) {
    $where[] = "EventStatus != 'Deleted'";
}

// Search functionality
if (!empty($_GET['search'])) {
    $searchTerm = '%' . $_GET['search'] . '%';
    $where[] = "(EventTitle LIKE ? OR EventDescription LIKE ? OR EventVenue LIKE ?)";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
}

// Date filter
if (!empty($_GET['filter_date'])) {
    $where[] = "EventDate = ?";
    $params[] = $_GET['filter_date'];
}

// Status filter - Fixed logic
if (!empty($_GET['filter_status'])) {
    $where[] = "EventStatus = ?";
    $params[] = $_GET['filter_status'];
}

// Month filter
if (!empty($_GET['filter_month'])) {
    $where[] = "DATE_FORMAT(EventDate, '%Y-%m') = ?";
    $params[] = $_GET['filter_month'];
}

// Build the query
if ($isStudent) {
    $studentId = $_SESSION['student_id'];
    $sql = "SELECT e.*, 
            (SELECT COUNT(*) FROM registration r WHERE r.EventID = e.EventID AND r.StudentID = ? AND r.RegistrationStatus != 'Cancelled') as IsRegistered,
            (SELECT COUNT(*) FROM registration r WHERE r.EventID = e.EventID AND r.RegistrationStatus != 'Cancelled') as TotalRegistered
            FROM event e";
    if ($where) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }
    $sql .= " ORDER BY EventDate DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$studentId], $params));
} else {
    // For staff - show all events including deleted ones
    $sql = "SELECT e.*, 
            (SELECT COUNT(*) FROM registration r WHERE r.EventID = e.EventID AND r.RegistrationStatus != 'Cancelled') as TotalRegistered
            FROM event e";
    if ($where) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }
    $sql .= " ORDER BY EventDate DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

$events = $stmt->fetchAll();

// Get event statistics
$statsQuery = $pdo->query("
    SELECT 
        EventStatus,
        COUNT(*) as count
    FROM event 
    GROUP BY EventStatus
");
$eventStats = $statsQuery->fetchAll();

// Convert to associative array for easier access
$stats = [];
foreach ($eventStats as $stat) {
    $stats[$stat['EventStatus']] = $stat['count'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Events - LifeSaver Hub</title>
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

        /* Enhanced Sidebar - Matching theme */
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
        }

        .page-header h1 {
            font-size: 3rem;
            font-weight: 900;
            color: #2d3748;
            margin-bottom: 12px;
            text-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .page-header .subtitle {
            color: #4a5568;
            font-size: 18px;
            font-weight: 400;
        }

        /* Statistics Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 32px;
            text-align: center;
            border: 1px solid rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            border-radius: 20px 20px 0 0;
        }

        .stat-card.upcoming::before {
            background: linear-gradient(90deg, #667eea, #764ba2);
        }

        .stat-card.completed::before {
            background: linear-gradient(90deg, #10b981, #059669);
        }

        .stat-card.ongoing::before {
            background: linear-gradient(90deg, #f59e0b, #d97706);
        }

        .stat-card.total::before {
            background: linear-gradient(90deg, #6366f1, #8b5cf6);
        }

        .stat-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 20px 40px rgba(102, 126, 234, 0.15);
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 900;
            color: #2d3748;
            margin-bottom: 8px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .stat-label {
            font-size: 14px;
            color: #4a5568;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }

        /* Filter Container */
        .filter-container {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 32px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .filter-container h3 {
            margin-top: 0;
            color: #2d3748;
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 24px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 8px;
            font-size: 14px;
        }

        input, select {
            padding: 16px 20px;
            border: 2px solid rgba(102, 126, 234, 0.15);
            border-radius: 12px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(10px);
        }

        input:focus, select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            background: rgba(255, 255, 255, 0.95);
        }

        .btn {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            padding: 18px 32px;
            font-weight: 700;
            border-radius: 12px;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            position: relative;
            overflow: hidden;
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

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #4a5568, #2d3748);
        }

        .btn-secondary:hover {
            box-shadow: 0 8px 25px rgba(74, 85, 104, 0.3);
        }

        /* Events Container */
        .events-container {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .table-header {
            padding: 32px;
            border-bottom: 1px solid rgba(102, 126, 234, 0.1);
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.02), rgba(139, 92, 246, 0.02));
        }

        .table-title {
            font-size: 24px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 8px;
        }

        .table-subtitle {
            color: #4a5568;
            font-size: 16px;
        }

        .table-wrapper {
            overflow-x: auto;
            width: 100%;
        }

        .events-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }

        .events-table th {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 20px 16px;
            text-align: left;
            font-weight: 700;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .events-table td {
            padding: 20px 16px;
            border-bottom: 1px solid rgba(102, 126, 234, 0.1);
            vertical-align: middle;
        }

        .events-table tr {
            transition: all 0.3s ease;
        }

        .events-table tr:hover {
            background: rgba(102, 126, 234, 0.02);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.1);
        }

        /* Event Details */
        .event-id {
            font-weight: 700;
            color: #667eea;
            font-size: 18px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .event-title {
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 8px;
            font-size: 16px;
            line-height: 1.4;
        }

        .event-description {
            color: #4a5568;
            font-size: 14px;
            line-height: 1.5;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            max-height: 2.8em;
        }

        .event-date {
            font-weight: 600;
            color: #2d3748;
            font-size: 14px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .event-venue {
            color: #4a5568;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .registration-count {
            display: inline-flex;
            align-items: center;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(139, 92, 246, 0.1));
            color: #667eea;
            padding: 10px 16px;
            border-radius: 20px;
            font-weight: 700;
            font-size: 16px;
            border: 2px solid rgba(102, 126, 234, 0.2);
        }

        .registration-count i {
            margin-right: 8px;
        }

        /* Status Badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 10px 20px;
            border-radius: 25px;
            font-weight: 700;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border: 2px solid;
        }

        .status-badge.upcoming {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(139, 92, 246, 0.1));
            color: #667eea;
            border-color: rgba(102, 126, 234, 0.3);
        }

        .status-badge.completed {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(5, 150, 105, 0.1));
            color: #10b981;
            border-color: rgba(16, 185, 129, 0.3);
        }

        .status-badge.ongoing {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(217, 119, 6, 0.1));
            color: #f59e0b;
            border-color: rgba(245, 158, 11, 0.3);
            animation: pulse-ongoing 2s infinite;
        }

        @keyframes pulse-ongoing {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }

        .status-badge.deleted {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.1), rgba(220, 38, 38, 0.1));
            color: #ef4444;
            border-color: rgba(239, 68, 68, 0.3);
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .action-btn {
            padding: 10px 16px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            font-size: 12px;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: none;
            cursor: pointer;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .action-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.3s;
        }

        .action-btn:hover::before {
            left: 100%;
        }

        .action-btn i {
            margin-right: 6px;
            font-size: 11px;
        }

        .btn-view {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }

        .btn-view:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.4);
        }

        .btn-edit {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
        }

        .btn-edit:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(245, 158, 11, 0.4);
        }

        .btn-delete {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }

        .btn-delete:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(239, 68, 68, 0.4);
        }

        .btn-email {
            background: linear-gradient(135deg, #06b6d4, #0891b2);
            color: white;
        }

        .btn-email:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(6, 182, 212, 0.4);
        }

        .btn-remind {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            color: white;
        }

        .btn-remind:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(139, 92, 246, 0.4);
        }

        .btn-register {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }

        .btn-register:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(16, 185, 129, 0.4);
        }

        .btn-registered {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            cursor: default;
        }

        .btn-disabled {
            background: linear-gradient(135deg, #9ca3af, #6b7280) !important;
            color: #fff !important;
            cursor: not-allowed !important;
            opacity: 0.6;
        }

        .btn-disabled:hover {
            transform: none !important;
            box-shadow: none !important;
        }

        .btn-disabled::before {
            display: none;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 80px 40px;
            color: #4a5568;
        }

        .empty-state i {
            font-size: 80px;
            color: #e2e8f0;
            margin-bottom: 24px;
            opacity: 0.8;
        }

        .empty-state h3 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 16px;
            color: #2d3748;
        }

        .empty-state p {
            font-size: 18px;
            color: #4a5568;
            margin-bottom: 24px;
        }

        .empty-state .btn {
            margin-top: 16px;
        }

        /* Auto-refresh indicator */
        .auto-refresh-indicator {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            z-index: 1000;
            opacity: 0;
            transform: translateY(20px);
            transition: all 0.3s ease;
        }

        .auto-refresh-indicator.show {
            opacity: 1;
            transform: translateY(0);
        }

        .manual-refresh-btn {
            position: fixed;
            bottom: 70px;
            right: 20px;
            z-index: 1000;
            font-size: 12px;
            padding: 8px 16px;
            border-radius: 20px;
        }

        /* Mobile menu button */
        .mobile-menu-btn {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1001;
            background: rgba(102, 126, 234, 0.9);
            border: none;
            color: white;
            padding: 12px;
            border-radius: 12px;
            cursor: pointer;
            backdrop-filter: blur(20px);
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
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .page-header h1 {
                font-size: 2rem;
            }

            .events-table {
                min-width: 800px;
            }

            .events-table th,
            .events-table td {
                padding: 16px 12px;
            }

            .action-btn {
                font-size: 11px;
                padding: 8px 12px;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .page-header {
                padding: 32px 24px;
            }
            
            .filter-container, .events-container {
                padding: 24px;
            }

            .events-table th,
            .events-table td {
                padding: 12px 8px;
            }

            .action-btn {
                font-size: 10px;
                padding: 6px 10px;
            }
        }

        /* Scrollbar Styling */
        ::-webkit-scrollbar {
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: rgba(102, 126, 234, 0.1);
        }

        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #5a67d8, #6b46c1);
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

        /* Notification styles */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 16px 24px;
            border-radius: 12px;
            color: white;
            font-weight: 600;
            z-index: 10000;
            transform: translateX(400px);
            transition: all 0.3s ease;
            max-width: 350px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.2);
        }

        .notification.success {
            background: linear-gradient(135deg, #10b981, #059669);
        }

        .notification.error {
            background: linear-gradient(135deg, #ef4444, #dc2626);
        }

        .notification.info {
            background: linear-gradient(135deg, #06b6d4, #0891b2);
        }

        .notification.show {
            transform: translateX(0);
        }
    </style>
</head>
<body>
    <!-- Mobile menu button -->
    <button class="mobile-menu-btn" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Auto-refresh indicator -->
    <div id="autoRefreshIndicator" class="auto-refresh-indicator">
        <i class="fas fa-sync-alt"></i> Auto-updating statuses...
    </div>

    <div class="app-container">
        <!-- Enhanced Sidebar - Matching theme -->
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
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-header-content">
                    <h1>View Events</h1>
                    <div class="subtitle">Manage and monitor all blood donation events in one place</div>
                </div>
            </div>

            <!-- Statistics Grid -->
            <div class="stats-grid">
                <div class="stat-card total">
                    <div class="stat-number"><?= count($events) ?></div>
                    <div class="stat-label">Total Events</div>
                </div>
                <div class="stat-card upcoming">
                    <div class="stat-number"><?= $stats['Upcoming'] ?? 0 ?></div>
                    <div class="stat-label">Upcoming Events</div>
                </div>
                <div class="stat-card ongoing">
                    <div class="stat-number"><?= $stats['Ongoing'] ?? 0 ?></div>
                    <div class="stat-label">Ongoing Events</div>
                </div>
                <div class="stat-card completed">
                    <div class="stat-number"><?= $stats['Completed'] ?? 0 ?></div>
                    <div class="stat-label">Completed Events</div>
                </div>
            </div>

            <!-- Filter Container -->
            <div class="filter-container">
                <h3>🔍 Filter & Search Events</h3>
                <form method="GET" class="filter-grid">
                    <div class="form-group">
                        <label for="search">Search Events</label>
                        <input type="text" name="search" id="search" placeholder="Search title, description, venue..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="filter_date">Filter by Date</label>
                        <input type="date" name="filter_date" id="filter_date" value="<?= htmlspecialchars($_GET['filter_date'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="filter_month">Filter by Month</label>
                        <input type="month" name="filter_month" id="filter_month" value="<?= htmlspecialchars($_GET['filter_month'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="filter_status">Filter by Status</label>
                        <select name="filter_status" id="filter_status">
                            <option value="">All Statuses</option>
                            <option value="Upcoming" <?= (($_GET['filter_status'] ?? '') === 'Upcoming') ? 'selected' : '' ?>>Upcoming</option>
                            <option value="Ongoing" <?= (($_GET['filter_status'] ?? '') === 'Ongoing') ? 'selected' : '' ?>>Ongoing</option>
                            <option value="Completed" <?= (($_GET['filter_status'] ?? '') === 'Completed') ? 'selected' : '' ?>>Completed</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <button type="submit" class="btn">
                            <i class="fas fa-filter"></i>
                            Apply Filters
                        </button>
                    </div>
                    
                    <div class="form-group">
                        <a href="staff_view_event.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i>
                            Clear All
                        </a>
                    </div>
                </form>
            </div>

            <!-- Events Container -->
            <div class="events-container">
                <div class="table-header">
                    <h3 class="table-title">All Events</h3>
                    <p class="table-subtitle">
                        <?= count($events) ?> event<?= count($events) !== 1 ? 's' : '' ?> found
                        <?php if (!empty($_GET['search']) || !empty($_GET['filter_date']) || !empty($_GET['filter_status']) || !empty($_GET['filter_month'])): ?>
                            (filtered)
                        <?php endif; ?>
                    </p>
                </div>

                <?php if (empty($events)): ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-times"></i>
                        <h3>No Events Found</h3>
                        <p>
                            <?php if (!empty($_GET['search']) || !empty($_GET['filter_date']) || !empty($_GET['filter_status']) || !empty($_GET['filter_month'])): ?>
                                There are no events matching your current filters.
                            <?php else: ?>
                                There are no events in the system yet.
                            <?php endif; ?>
                        </p>
                        <?php if (!empty($_GET['search']) || !empty($_GET['filter_date']) || !empty($_GET['filter_status']) || !empty($_GET['filter_month'])): ?>
                            <a href="staff_view_event.php" class="btn">
                                <i class="fas fa-times"></i>
                                Clear Filters
                            </a>
                        <?php else: ?>
                            <a href="create_event.php" class="btn">
                                <i class="fas fa-plus"></i>
                                Create New Event
                            </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="table-wrapper">
                        <table class="events-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Event Details</th>
                                    <th>Date & Venue</th>
                                    <th>Registrations</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($events as $event): ?>
                                    <?php 
                                    // Better logic to check if event is completed/past
                                    $eventDate = new DateTime($event['EventDate']);
                                    $today = new DateTime();
                                    $today->setTime(0, 0, 0); // Set to beginning of day for accurate comparison
                                    
                                    // Event is only completed if it's explicitly marked as completed OR if the date is in the past
                                    $isCompleted = ($event['EventStatus'] === 'Completed' || $eventDate < $today);
                                    $isOngoing = ($event['EventStatus'] === 'Ongoing' || $eventDate->format('Y-m-d') === $today->format('Y-m-d'));
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="event-id">#<?= $event['EventID'] ?></div>
                                        </td>
                                        <td>
                                            <div class="event-title"><?= htmlspecialchars($event['EventTitle']) ?></div>
                                            <div class="event-description" title="<?= htmlspecialchars($event['EventDescription']) ?>">
                                                <?= htmlspecialchars($event['EventDescription']) ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="event-date">
                                                <i class="fas fa-calendar"></i>
                                                <?= date('M d, Y', strtotime($event['EventDate'])) ?>
                                            </div>
                                            <div class="event-venue">
                                                <i class="fas fa-map-marker-alt"></i>
                                                <?= htmlspecialchars($event['EventVenue']) ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="registration-count">
                                                <i class="fas fa-users"></i>
                                                <?= $event['TotalRegistered'] ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="status-badge <?= strtolower($event['EventStatus']) ?>">
                                                <?= $event['EventStatus'] ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <?php if ($isStaff): ?>
                                                    <!-- Remind Button -->
                                                    <form method="POST" action="" style="display: inline;">
                                                        <input type="hidden" name="event_id" value="<?= $event['EventID'] ?>">
                                                        <input type="hidden" name="send_reminder" value="1">
                                                        <button type="submit" 
                                                                class="action-btn btn-remind"
                                                                onclick="return confirmReminder('<?= htmlspecialchars(addslashes($event['EventTitle'])) ?>', <?= $event['TotalRegistered'] ?>)"
                                                                title="Send reminder emails to registered students">
                                                            <i class="fas fa-bell"></i>
                                                            Remind Students
                                                        </button>
                                                    </form>
                                                    
                                                    <!-- Edit button - allow editing for upcoming and ongoing events -->
                                                    <?php if (!$isCompleted && ($event['EventStatus'] === 'Upcoming' || $event['EventStatus'] === 'Ongoing')): ?>
                                                        <a href="update_event.php?id=<?= $event['EventID'] ?>" 
                                                           class="action-btn btn-edit"
                                                           title="Edit event">
                                                            <i class="fas fa-edit"></i>
                                                            Edit Event
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="action-btn btn-disabled" 
                                                              title="Cannot edit completed/past events">
                                                            <i class="fas fa-edit"></i>
                                                            Edit Event
                                                        </span>
                                                    <?php endif; ?>
                                                    
                                                    <!-- Delete button - always available for staff -->
                                                    <a href="delete_event.php?id=<?= $event['EventID'] ?>" 
                                                       class="action-btn btn-delete"
                                                       onclick="return confirm('Are you sure you want to delete this event? This action cannot be undone.')"
                                                       title="Delete event">
                                                        <i class="fas fa-trash-alt"></i>
                                                        Delete Event
                                                    </a>
                                                <?php else: ?>
                                                    <!-- Student view - allow registration for upcoming and ongoing events -->
                                                    <?php if (!$isCompleted && ($event['EventStatus'] === 'Upcoming' || $event['EventStatus'] === 'Ongoing')): ?>
                                                        <?php if ($event['IsRegistered'] > 0): ?>
                                                            <span class="action-btn btn-registered"
                                                                  title="You are registered for this event">
                                                                <i class="fas fa-check"></i>
                                                                Registered
                                                            </span>
                                                        <?php else: ?>
                                                            <a href="register_event.php?id=<?= $event['EventID'] ?>" 
                                                               class="action-btn btn-register"
                                                               title="Register for this event">
                                                                <i class="fas fa-user-plus"></i>
                                                                Register Now
                                                            </a>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <!-- Show message for completed events -->
                                                        <span class="action-btn btn-disabled" 
                                                              title="Event has ended">
                                                            <i class="fas fa-clock"></i>
                                                            Event Ended
                                                        </span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        // Confirmation function for reminder emails
        function confirmReminder(eventTitle, registeredCount) {
            const message = `Send reminder emails for "${eventTitle}"?\n\n` +
                           `This will send reminder emails to students with RegistrationStatus = 'Registered'.\n\n` +
                           `Event: ${eventTitle}\n` +
                           `Total Registrations: ${registeredCount}\n\n` +
                           `Click OK to send reminder emails now.`;
            
            return confirm(message);
        }

        // Enhanced auto-update functionality
        let autoUpdateInterval;
        let lastUpdateTime = Date.now();

        // Function to check and update event statuses
        function checkEventStatuses() {
            const now = new Date();
            const currentDate = now.toISOString().split('T')[0];
            
            // Show auto-refresh indicator
            showAutoRefreshIndicator();
            
            // Make AJAX call to update statuses
            fetch('update_event_status.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    current_date: currentDate,
                    current_time: now.toTimeString().split(' ')[0]
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.updated > 0) {
                    // Refresh the page to show updated statuses
                    window.location.reload();
                } else {
                    hideAutoRefreshIndicator();
                }
            })
            .catch(error => {
                console.error('Error updating event statuses:', error);
                hideAutoRefreshIndicator();
            });
        }

        // Show auto-refresh indicator
        function showAutoRefreshIndicator() {
            const indicator = document.getElementById('autoRefreshIndicator');
            indicator.classList.add('show');
        }

        // Hide auto-refresh indicator
        function hideAutoRefreshIndicator() {
            const indicator = document.getElementById('autoRefreshIndicator');
            indicator.classList.remove('show');
        }

        // Auto-update every 60 seconds (1 minute)
        function startAutoUpdate() {
            autoUpdateInterval = setInterval(checkEventStatuses, 60000);
        }

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

        // Auto-hide mobile menu on resize
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth > 968) {
                sidebar.classList.remove('open');
            }
        });

        // Enhanced JavaScript functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Start auto-update
            startAutoUpdate();
            
            // Check immediately on page load
            setTimeout(checkEventStatuses, 2000);
            
            // Update last update time display
            updateLastUpdateTime();
            
            console.log('🔄 Auto-update enabled: Checking event statuses every 60 seconds');
            
            // Add entrance animations
            const elements = document.querySelectorAll('.page-header, .stat-card, .filter-container, .events-container');
            elements.forEach((el, index) => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    el.style.transition = 'all 0.6s ease';
                    el.style.opacity = '1';
                    el.style.transform = 'translateY(0)';
                }, index * 100);
            });

            // Enhanced form submission with AJAX for reminder system - REMOVED (now handled by page reload)
            
            // Add success message display if coming from various actions
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('reminder_sent') === 'success') {
                const emailsCount = urlParams.get('emails_count') || 0;
                const eventTitle = urlParams.get('event_title') || 'Unknown Event';
                showNotification(`✅ Reminder emails sent successfully to ${emailsCount} students for "${eventTitle}"!`, 'success');
            } else if (urlParams.get('reminder_sent') === 'error') {
                const errorMessage = urlParams.get('message') || 'Failed to send reminder emails';
                showNotification(`❌ ${errorMessage}`, 'error');
            } else if (urlParams.get('email_sent') === 'success') {
                showNotification('Email notifications sent successfully!', 'success');
            } else if (urlParams.get('email_sent') === 'error') {
                showNotification('Failed to send email notifications.', 'error');
            } else if (urlParams.get('created') === 'success') {
                showNotification('Event created successfully!', 'success');
            } else if (urlParams.get('updated') === 'success') {
                showNotification('Event updated successfully!', 'success');
            } else if (urlParams.get('deleted') === 'success') {
                showNotification('Event deleted successfully!', 'success');
            }

            // Console logging for debugging
            console.log('📅 View Events Page Loaded');
            console.log('📊 Total Events:', <?= count($events) ?>);
            console.log('🔜 Upcoming Events:', <?= $stats['Upcoming'] ?? 0 ?>);
            console.log('⏳ Ongoing Events:', <?= $stats['Ongoing'] ?? 0 ?>);
            console.log('✅ Completed Events:', <?= $stats['Completed'] ?? 0 ?>);
            console.log('🎯 Enhanced View Events Interface Active!');
        });

        // Update last update time
        function updateLastUpdateTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString();
            console.log(`📅 Last status check: ${timeString}`);
        }

        // Clear interval when page is about to unload
        window.addEventListener('beforeunload', function() {
            if (autoUpdateInterval) {
                clearInterval(autoUpdateInterval);
            }
        });

        // Page visibility API to pause/resume updates when tab is not visible
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                // Page is hidden, clear interval
                if (autoUpdateInterval) {
                    clearInterval(autoUpdateInterval);
                }
            } else {
                // Page is visible, restart auto-update
                startAutoUpdate();
                // Check immediately when tab becomes visible
                checkEventStatuses();
            }
        });

        // Manual refresh button functionality
        function manualRefresh() {
            showAutoRefreshIndicator();
            checkEventStatuses();
        }

        // Show notification function
        function showNotification(message, type) {
            // Remove existing notifications
            const existingNotifications = document.querySelectorAll('.notification');
            existingNotifications.forEach(notif => notif.remove());

            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.textContent = message;
            
            document.body.appendChild(notification);
            
            // Show notification
            setTimeout(() => {
                notification.classList.add('show');
            }, 100);
            
            // Hide notification after 5 seconds
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => {
                    if (document.body.contains(notification)) {
                        document.body.removeChild(notification);
                    }
                }, 300);
            }, 5000);
        }

        // Keyboard shortcuts for accessibility
        document.addEventListener('keydown', function(e) {
            // Alt+S for search
            if (e.altKey && e.key === 's') {
                e.preventDefault();
                document.getElementById('search').focus();
            }
            
            // Alt+C for create event
            if (e.altKey && e.key === 'c') {
                e.preventDefault();
                window.location.href = 'create_event.php';
            }
            
            // Alt+D for dashboard
            if (e.altKey && e.key === 'd') {
                e.preventDefault();
                window.location.href = 'staff_dashboard.php';
            }

            // Alt+R for manual refresh
            if (e.altKey && e.key === 'r') {
                e.preventDefault();
                manualRefresh();
            }
        });

        // Add focus management for accessibility
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Tab') {
                document.body.classList.add('keyboard-navigation');
            }
        });

        document.addEventListener('mousedown', function() {
            document.body.classList.remove('keyboard-navigation');
        });

        // Add CSS for keyboard navigation
        const keyboardStyle = document.createElement('style');
        keyboardStyle.textContent = `
            .keyboard-navigation .nav-item:focus,
            .keyboard-navigation .btn:focus,
            .keyboard-navigation .action-btn:focus,
            .keyboard-navigation input:focus,
            .keyboard-navigation select:focus {
                outline: 3px solid #667eea;
                outline-offset: 2px;
            }
        `;
        document.head.appendChild(keyboardStyle);

        // Performance monitoring
        window.addEventListener('load', function() {
            const loadTime = performance.now();
            console.log(`⚡ View Events Page loaded in ${Math.round(loadTime)}ms`);
            
            // Show load complete notification after everything is ready
            setTimeout(() => {
                const notification = document.createElement('div');
                notification.style.cssText = `
                    position: fixed; 
                    top: 20px; 
                    right: 20px; 
                    background: linear-gradient(135deg, #667eea, #764ba2); 
                    color: white; 
                    padding: 12px 20px; 
                    border-radius: 10px; 
                    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
                    z-index: 10000; 
                    font-weight: 600;
                    font-size: 14px;
                    opacity: 0;
                    transform: translateX(100%);
                    transition: all 0.3s ease;
                `;
                notification.innerHTML = '✅ View Events Ready!';
                document.body.appendChild(notification);
                
                // Show notification
                setTimeout(() => {
                    notification.style.opacity = '1';
                    notification.style.transform = 'translateX(0)';
                }, 100);
                
                // Hide notification
                setTimeout(() => {
                    notification.style.opacity = '0';
                    notification.style.transform = 'translateX(100%)';
                    setTimeout(() => notification.remove(), 300);
                }, 2000);
            }, 500);
        });

        // Advanced search functionality
        document.getElementById('search').addEventListener('input', function() {
            // Optional: Add live search functionality here
            // This could make AJAX calls to filter results in real-time
        });

        // Form auto-submit when filters change (optional)
        document.querySelectorAll('#filter_date, #filter_month, #filter_status').forEach(filter => {
            filter.addEventListener('change', function() {
                // Uncomment the line below to enable auto-submit on filter change
                // this.form.submit();
            });
        });

        // Add visual feedback for completed events
        document.addEventListener('DOMContentLoaded', function() {
            const completedRows = document.querySelectorAll('.events-table tbody tr');
            completedRows.forEach(row => {
                const statusBadge = row.querySelector('.status-badge');
                if (statusBadge && statusBadge.classList.contains('completed')) {
                    // Add subtle styling to indicate the event is completed
                    row.style.opacity = '0.9';
                    row.style.background = 'rgba(16, 185, 129, 0.02)';
                }
            });
        });

        // Add confirmation dialogs for important actions
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('btn-delete') || e.target.closest('.btn-delete')) {
                const confirmDelete = confirm('⚠️ Are you sure you want to delete this event?\n\nThis action cannot be undone and will:\n• Remove the event permanently\n• Cancel all registrations\n• Delete associated data\n\nClick OK to confirm deletion.');
                if (!confirmDelete) {
                    e.preventDefault();
                    e.stopPropagation();
                    return false;
                }
            }
        });

        // Add tooltip functionality for action buttons
        document.querySelectorAll('.action-btn').forEach(btn => {
            btn.addEventListener('mouseenter', function() {
                const title = this.getAttribute('title');
                if (title && !this.classList.contains('btn-disabled')) {
                    // Create and show tooltip (optional enhancement)
                    // Implementation can be added here if needed
                }
            });
        });

        // Monitor network connectivity and show warning if offline
        window.addEventListener('online', function() {
            showNotification('✅ Connection restored!', 'success');
        });

        window.addEventListener('offline', function() {
            showNotification('⚠️ You are currently offline. Some features may not work.', 'error');
        });

        // Auto-scroll to event if ID is in URL hash
        window.addEventListener('load', function() {
            if (window.location.hash) {
                const eventId = window.location.hash.replace('#', '');
                const eventRow = document.querySelector(`[data-event-id="${eventId}"]`);
                if (eventRow) {
                    eventRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    eventRow.style.backgroundColor = 'rgba(102, 126, 234, 0.1)';
                    setTimeout(() => {
                        eventRow.style.backgroundColor = '';
                    }, 3000);
                }
            }
        });

        // Add real-time clock for current time display
        function updateCurrentTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString();
            const dateString = now.toLocaleDateString();
            
            // Update page title with current time
            document.title = `View Events - ${timeString} - LifeSaver Hub`;
            
            // Log current time every minute for debugging
            if (now.getSeconds() === 0) {
                console.log(`🕐 Current time: ${dateString} ${timeString}`);
            }
        }

        // Update time every second
        setInterval(updateCurrentTime, 1000);

        // Initial time update
        updateCurrentTime();

        // Add event status change animations
        function animateStatusChange(element, newStatus) {
            element.style.transform = 'scale(1.1)';
            element.style.transition = 'all 0.3s ease';
            
            setTimeout(() => {
                element.className = `status-badge ${newStatus.toLowerCase()}`;
                element.textContent = newStatus;
                element.style.transform = 'scale(1)';
            }, 150);
        }

        // Check for status updates and animate changes
        function checkAndAnimateStatusUpdates() {
            const statusBadges = document.querySelectorAll('.status-badge');
            statusBadges.forEach(badge => {
                const currentStatus = badge.textContent.trim();
                // This would compare with server data to detect changes
                // Implementation depends on your specific requirements
            });
        }

        // Call status update check every 30 seconds
        setInterval(checkAndAnimateStatusUpdates, 30000);
    </script>
</body>
</html>