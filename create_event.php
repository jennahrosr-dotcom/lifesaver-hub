<?php
session_start();

if (!isset($_SESSION['staff_id'])) {
    header("Location: staff_login.php");
    exit;
}

require 'db.php';

// Fetch staff data
$stmt = $pdo->prepare("SELECT * FROM staff WHERE StaffID = ?");
$stmt->execute([$_SESSION['staff_id']]);
$staff = $stmt->fetch();

$errors = [];
$success = '';
$staffId = $_SESSION['staff_id'];

// XAMPP-optimized email function
function sendNewEventEmailXAMPP($student, $eventId, $title, $description, $date, $day, $venue) {
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
        $mail->setFrom('jennahrosr@gmail.com', 'LifeSaver Hub - New Event Alert');
        $mail->addAddress($student['StudentEmail'], $student['StudentName']);
        $mail->addReplyTo('jennahrosr@gmail.com', 'LifeSaver Support');
        
        // Content
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';
        
        $subject = "🩸 NEW Blood Donation Event: " . $title;
        $htmlContent = generateNewEventEmailHTML($student, $eventId, $title, $description, $date, $day, $venue);
        $plainContent = generateNewEventEmailPlain($student, $eventId, $title, $description, $date, $day, $venue);
        
        $mail->Subject = $subject;
        $mail->Body = $htmlContent;
        $mail->AltBody = $plainContent;
        
        $result = $mail->send();
        
        if ($result) {
            error_log("✅ XAMPP Email sent successfully to: " . $student['StudentEmail']);
            return true;
        } else {
            error_log("❌ XAMPP Email send failed to: " . $student['StudentEmail']);
            return false;
        }
        
    } catch (Exception $e) {
        error_log("❌ XAMPP Email error for " . $student['StudentEmail'] . ": " . $e->getMessage());
        return false;
    }
}

// FIXED: Updated notification function to match existing database structure
function createEventNotificationForAllStudentsXAMPP($pdo, $eventId, $title, $description, $date, $day, $venue) {
    try {
        // Enhanced notification with better formatting
        $notificationTitle = "🩸 New Blood Donation Event: " . $title;
        $notificationMessage = "🌟 A new blood donation event has been scheduled!\n\n" .
                             "🎯 Event: " . $title . "\n" .
                             "📅 Date: " . $date . " (" . $day . ")\n" .
                             "📍 Venue: " . $venue . "\n\n" .
                             "📋 Description:\n" . $description . "\n\n" .
                             "🤝 Your participation can save lives!\n" .
                             "💡 Register now to participate in this life-saving event.\n\n" .
                             "❤️ Every donation counts - be someone's hero today!";
        
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
            error_log("No students found in database for event notifications");
            return ['notifications' => 0, 'emails' => 0];
        }
        
        // FIXED: Use correct column names that match your notification table
        $insertNotification = $pdo->prepare("
            INSERT INTO notification 
            (StudentID, NotificationTitle, NotificationMessage, NotificationType, Priority, EventID, CreatedDate, NotificationIsRead) 
            VALUES (?, ?, ?, 'Event', 'High', ?, NOW(), 0)
        ");
        
        $notificationCount = 0;
        $emailCount = 0;
        
        foreach ($students as $student) {
            try {
                // Create in-app notification using existing table structure
                $insertNotification->execute([
                    $student['StudentID'], 
                    $notificationTitle, 
                    $notificationMessage, 
                    $eventId
                ]);
                $notificationCount++;
                
                // Send XAMPP-optimized email notification
                $emailSent = sendNewEventEmailXAMPP($student, $eventId, $title, $description, $date, $day, $venue);
                if ($emailSent) {
                    $emailCount++;
                }
                
                // XAMPP-friendly delay
                usleep(500000); // 0.5 seconds
                
            } catch (Exception $e) {
                error_log("Failed to create notification for StudentID " . $student['StudentID'] . ": " . $e->getMessage());
            }
        }
        
        error_log("Successfully created $notificationCount notifications and sent $emailCount emails for event: $title");
        return ['notifications' => $notificationCount, 'emails' => $emailCount];
        
    } catch (Exception $e) {
        error_log("Notification creation failed: " . $e->getMessage());
        return ['notifications' => 0, 'emails' => 0];
    }
}

/**
 * Generate HTML email content for new event
 */
function generateNewEventEmailHTML($student, $eventId, $title, $description, $date, $day, $venue) {
    $eventDate = date('l, d F Y', strtotime($date));
    
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
                background: linear-gradient(135deg, #d62828, #a61c1c); 
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
            .event-highlight { 
                background: linear-gradient(135deg, #fff3cd, #ffeaa7); 
                border: 2px solid #ffc107;
                color: #856404;
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
                border-left: 5px solid #d62828; 
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
                color: #d62828;
            }
            .cta-section {
                background: linear-gradient(135deg, #d4edda, #c3e6cb);
                padding: 25px;
                border-radius: 10px;
                margin: 25px 0;
                text-align: center;
            }
            .footer { 
                background: #f8f9fa;
                padding: 30px; 
                text-align: center; 
                color: #6c757d; 
                font-size: 14px; 
            }
            .important-info {
                background: #e7f3ff;
                border: 1px solid #bee5eb;
                padding: 20px;
                border-radius: 8px;
                margin: 20px 0;
                color: #0c5460;
            }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <div style='font-size: 60px; margin-bottom: 20px;'>🩸</div>
                <h1>NEW BLOOD DONATION EVENT</h1>
                <h2>Your Chance to Save Lives!</h2>
            </div>
            
            <div class='content'>
                <p>Dear <strong>" . htmlspecialchars($student['StudentName']) . "</strong>,</p>
                
                <p>We're excited to announce a new blood donation event that needs your support!</p>
                
                <div class='event-highlight'>
                    🌟 " . htmlspecialchars($title) . "
                </div>
                
                <div class='event-details'>
                    <h3>📋 Event Information</h3>
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
                            <th>📝 Description</th>
                            <td>" . htmlspecialchars($description) . "</td>
                        </tr>
                        <tr>
                            <th>🆔 Event ID</th>
                            <td>#{$eventId}</td>
                        </tr>
                    </table>
                </div>
                
                <div class='important-info'>
                    <h4>🩺 Donation Requirements</h4>
                    <ul style='text-align: left; margin: 10px 0; padding-left: 20px;'>
                        <li>Age: 18-65 years old</li>
                        <li>Weight: Minimum 45kg</li>
                        <li>Be in good health</li>
                        <li>Bring IC or valid identification</li>
                        <li>Have eaten within 4 hours before donation</li>
                        <li>Well-rested and hydrated</li>
                    </ul>
                </div>
                
                <div class='cta-section'>
                    <h3 style='color: #155724; margin-bottom: 15px;'>🦸‍♀️ Ready to Be a Hero? 🦸‍♂️</h3>
                    <p style='color: #155724; font-weight: 600; margin-bottom: 20px;'>Your donation can save up to 3 lives!</p>
                </div>
                
                <div style='background: linear-gradient(135deg, #fff3cd, #ffeaa7); padding: 20px; border-radius: 10px; margin: 25px 0; text-align: center;'>
                    <h4 style='color: #856404; margin-bottom: 10px;'>❓ Have Questions?</h4>
                    <p style='color: #856404; margin: 0;'>
                        Call us: <strong>03-8883-1200</strong><br>
                        Email: <strong>jennahrosr@gmail.com</strong><br>
                        We're here to help you through the process!
                    </p>
                </div>
                
                <div style='text-align: center; padding: 25px; background: linear-gradient(135deg, #d4edda, #c3e6cb); border-radius: 10px; margin: 25px 0;'>
                    <h3 style='color: #155724; margin-bottom: 15px;'>💝 Why Donate Blood?</h3>
                    <ul style='color: #155724; text-align: left; margin: 0; padding-left: 20px;'>
                        <li>Help patients with medical conditions</li>
                        <li>Support emergency and trauma cases</li>
                        <li>Assist with surgical procedures</li>
                        <li>Make a real difference in your community</li>
                    </ul>
                </div>
                
                <div style='text-align: center; margin: 30px 0;'>
                    <p><strong>📞 Contact Information:</strong></p>
                    <p style='font-size: 18px; color: #d62828;'><strong>Phone: 03-8883-1200</strong></p>
                    <p>Email: jennahrosr@gmail.com</p>
                </div>
            </div>
            
            <div class='footer'>
                <p><strong>Thank you for being part of our life-saving mission!</strong></p>
                <p><strong>LifeSaver Hub - Blood Donation Management</strong></p>
                <p><strong>Ministry of Health Malaysia - Blood Donation Unit</strong></p>
                <p style='margin-top: 15px; font-size: 12px;'>
                    Email sent: " . date('d F Y \a\t g:i A') . " | Event ID: #{$eventId}
                </p>
            </div>
        </div>
    </body>
    </html>";
}

/**
 * Generate plain text email content for new event
 */
function generateNewEventEmailPlain($student, $eventId, $title, $description, $date, $day, $venue) {
    $eventDate = date('l, d F Y', strtotime($date));
    
    return "
NEW BLOOD DONATION EVENT ANNOUNCEMENT

Dear " . $student['StudentName'] . ",

We're excited to announce a new blood donation event that needs your support!

EVENT: " . $title . "

EVENT DETAILS:
📅 Date: {$eventDate}
📍 Venue: " . $venue . "
📝 Description: " . $description . "
🆔 Event ID: #{$eventId}

DONATION REQUIREMENTS:
✅ Age: 18-65 years old
✅ Weight: Minimum 45kg
✅ Be in good health
✅ Bring IC or valid identification
✅ Have eaten within 4 hours before donation
✅ Well-rested and hydrated

WHY DONATE BLOOD?
💝 Help patients with medical conditions
💝 Support emergency and trauma cases
💝 Assist with surgical procedures
💝 Make a real difference in your community

READY TO BE A HERO?
Your donation can save up to 3 lives!

CONTACT INFORMATION:
📞 Phone: 03-8883-1200
📧 Email: jennahrosr@gmail.com

We're here to help you through the process!

Thank you for being part of our life-saving mission!

LifeSaver Hub - Blood Donation Management
Ministry of Health Malaysia - Blood Donation Unit
Email sent: " . date('d F Y \a\t g:i A') . " | Event ID: #{$eventId}
";
}

// Enhanced event status calculation
function calculateEventStatus($date) {
    $today = date('Y-m-d');
    $eventDate = date('Y-m-d', strtotime($date));
    
    if ($eventDate > $today) {
        return 'Upcoming';
    } elseif ($eventDate === $today) {
        return 'Ongoing';
    } else {
        return 'Past';
    }
}

// Handle event creation with enhanced validation and email notifications
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_event'])) {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $date = $_POST['date'];
    $day = $_POST['day'];
    $venue = trim($_POST['venue']);

    // Enhanced validation
    $validationErrors = [];
    
    if (!$title) $validationErrors[] = "Event title is required.";
    if (!$description) $validationErrors[] = "Event description is required.";
    if (!$date) $validationErrors[] = "Event date is required.";
    if (!$venue) $validationErrors[] = "Event venue is required.";
    
    if ($date && strtotime($date) < strtotime(date('Y-m-d'))) {
        $validationErrors[] = "Event date cannot be in the past.";
    }

    // Auto-calculate day if not provided
    if (!$day && $date) {
        $day = date('l', strtotime($date));
    }

    if (empty($validationErrors)) {
        $status = calculateEventStatus($date);

        try {
            // Begin transaction for data integrity
            $pdo->beginTransaction();

            // Insert the event using your existing table structure
            $stmt = $pdo->prepare("INSERT INTO event (EventTitle, EventDescription, EventDate, EventDay, EventVenue, EventStatus, StaffID) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$title, $description, $date, $day, $venue, $status, $staffId]);
            
            // Get the newly created event ID
            $eventId = $pdo->lastInsertId();
            
            // Create notifications AND send emails for all students (XAMPP optimized)
            $results = createEventNotificationForAllStudentsXAMPP($pdo, $eventId, $title, $description, $date, $day, $venue);
            
            // Commit transaction
            $pdo->commit();
            
            if ($results['notifications'] > 0 && $results['emails'] > 0) {
                $success = "✅ Event created successfully! " . $results['notifications'] . " students have been notified and " . $results['emails'] . " emails have been sent about the new blood donation event.";
            } elseif ($results['notifications'] > 0 && $results['emails'] == 0) {
                $success = "✅ Event created successfully! " . $results['notifications'] . " students have been notified in-app, but no emails were sent (check email configuration).";
            } elseif ($results['notifications'] == 0) {
                $success = "⚠️ Event created successfully, but no students were found in the database to notify.";
            } else {
                $success = "⚠️ Event created successfully, but there was an issue sending notifications to students.";
            }
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $pdo->rollback();
            $errors[] = "❌ Error creating event: " . $e->getMessage();
        }
    } else {
        $errors = $validationErrors;
    }
}

// Enhanced filtering with search functionality
$where = [];
$params = [];

if (!empty($_GET['search'])) {
    $searchTerm = '%' . $_GET['search'] . '%';
    $where[] = "(EventTitle LIKE ? OR EventDescription LIKE ? OR EventVenue LIKE ?)";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
}

if (!empty($_GET['filter_date'])) {
    $where[] = "EventDate = ?";
    $params[] = $_GET['filter_date'];
}

if (!empty($_GET['filter_status'])) {
    $where[] = "EventStatus = ?";
    $params[] = $_GET['filter_status'];
}

if (!empty($_GET['filter_month'])) {
    $where[] = "DATE_FORMAT(EventDate, '%Y-%m') = ?";
    $params[] = $_GET['filter_month'];
}

$sql = "SELECT * FROM event";
if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY EventDate DESC, EventTitle ASC";

$events = $pdo->prepare($sql);
$events->execute($params);
$eventList = $events->fetchAll();

// Get event statistics using existing table
$statsQuery = $pdo->query("
    SELECT 
        EventStatus,
        COUNT(*) as count
    FROM event 
    GROUP BY EventStatus
");
$eventStats = $statsQuery->fetchAll();

// FIXED: Get student count using proper validation for existing database
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
    
    $totalStudentQuery = $pdo->query("SELECT COUNT(*) as total_count FROM student");
    $totalStudentCount = $totalStudentQuery->fetch()['total_count'];
} catch (Exception $e) {
    $studentCount = 0;
    $totalStudentCount = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Event - LifeSaver Hub</title>
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

        /* Enhanced Sidebar - Matching staff_dashboard.php */
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

        /* Logo image styling */
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
            background: linear-gradient(90deg, #667eea, #764ba2);
            border-radius: 20px 20px 0 0;
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

        .alert {
            padding: 20px 24px;
            border-radius: 16px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
            backdrop-filter: blur(20px);
        }

        .alert-success {
            background: rgba(34, 197, 94, 0.1);
            color: #166534;
            border: 1px solid rgba(34, 197, 94, 0.2);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            color: #991b1b;
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        .form-container {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 40px;
            margin-bottom: 32px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .form-container h3 {
            margin-top: 0;
            color: #2d3748;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 24px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 24px;
        }

        .form-group {
            margin-bottom: 24px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2d3748;
            font-size: 14px;
        }

        input, textarea, select {
            width: 100%;
            padding: 16px 20px;
            border: 2px solid rgba(102, 126, 234, 0.15);
            border-radius: 12px;
            box-sizing: border-box;
            font-size: 16px;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(10px);
        }

        input:focus, textarea:focus, select:focus {
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

        .table-container {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 20px;
            text-align: left;
            border-bottom: 1px solid rgba(102, 126, 234, 0.1);
        }

        th {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            font-weight: 700;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        tr:hover {
            background-color: rgba(102, 126, 234, 0.05);
        }

        .status-badge {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
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
        
        .status-past { 
            background: rgba(239, 68, 68, 0.15); 
            color: #991b1b; 
            border: 1px solid rgba(239, 68, 68, 0.3);
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
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .page-header h1 {
                font-size: 2rem;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .page-header {
                padding: 32px 24px;
            }
            
            .form-container {
                padding: 24px;
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
    </style>
</head>
<body>
    <!-- Mobile menu button -->
    <button class="mobile-menu-btn" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>

    <div class="app-container">
        <!-- Enhanced Sidebar - Matching staff_dashboard.php -->
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
                        <a href="create_event.php" class="nav-item active">
                            <i class="fas fa-calendar-plus"></i>
                            <span>Create Event</span>
                        </a>
                        <a href="staff_view_event.php" class="nav-item"> 
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
                    <h1>Create Event</h1>
                    <div class="subtitle">Schedule new blood donation events and automatically notify all students via in-app notifications and email</div>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?= $studentCount ?></div>
                    <div class="stat-label">📧 Students with Email</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= $totalStudentCount ?></div>
                    <div class="stat-label">👥 Total Students</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= count($eventList) ?></div>
                    <div class="stat-label">📅 Total Events</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= count(array_filter($eventList, function($event) { return $event['EventStatus'] === 'Upcoming'; })) ?></div>
                    <div class="stat-label">🔜 Upcoming Events</div>
                </div>
            </div>

            <!-- Email Status Alert -->
            <?php if ($studentCount > 0): ?>
                <div class="email-status">
                    <h4>📧 XAMPP Email System Ready</h4>
                    <p>When you create an event, <strong><?= $studentCount ?> students</strong> will receive both in-app notifications and email alerts via XAMPP-optimized delivery!</p>
                </div>
            <?php elseif ($totalStudentCount > 0): ?>
                <div class="email-status warning">
                    <h4>⚠️ Limited Email Reach</h4>
                    <p>Only students with email addresses will receive email notifications. <strong><?= $totalStudentCount - $studentCount ?> students</strong> don't have email addresses.</p>
                </div>
            <?php else: ?>
                <div class="email-status error">
                    <h4>❌ No Students Found</h4>
                    <p>No students in database - no notifications will be sent when creating events.</p>
                </div>
            <?php endif; ?>

            <!-- Success/Error Messages -->
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <span>✅</span>
                    <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>

            <?php foreach ($errors as $error): ?>
                <div class="alert alert-error">
                    <span>❌</span>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endforeach; ?>

            <!-- Event Creation Form -->
            <div class="form-container">
                <h3>📢 Create New Event</h3>
                <form method="POST" id="eventForm">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="title">Event Title *</label>
                            <input type="text" name="title" id="title" placeholder="e.g., Blood Donation Drive 2025" required>
                        </div>
                        <div class="form-group">
                            <label for="venue">Event Venue *</label>
                            <input type="text" name="venue" id="venue" placeholder="e.g., University Main Hall, Room 101" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="date">Event Date *</label>
                            <input type="date" name="date" id="date" required>
                        </div>
                        <div class="form-group">
                            <label for="day">Day of Week *</label>
                            <select name="day" id="day" required>
                                <option value="">Auto-select based on date</option>
                                <option value="Sunday">Sunday</option>
                                <option value="Monday">Monday</option>
                                <option value="Tuesday">Tuesday</option>
                                <option value="Wednesday">Wednesday</option>
                                <option value="Thursday">Thursday</option>
                                <option value="Friday">Friday</option>
                                <option value="Saturday">Saturday</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="description">Event Description *</label>
                        <textarea name="description" id="description" placeholder="Describe the event details, requirements, eligibility criteria, and what participants can expect..." rows="4" required></textarea>
                    </div>

                    <button type="submit" name="add_event" class="btn">
                        <i class="fas fa-plus"></i>
                        Create Event & Send Notifications
                    </button>
                </form>
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
                            <option value="Past" <?= (($_GET['filter_status'] ?? '') === 'Past') ? 'selected' : '' ?>>Past</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <button type="submit" class="btn btn-secondary">
                            <i class="fas fa-filter"></i>
                            Apply Filters
                        </button>
                    </div>
                    
                    <div class="form-group">
                        <a href="create_event.php" class="btn btn-secondary" style="text-decoration: none; text-align: center;">
                            <i class="fas fa-times"></i>
                            Clear All
                        </a>
                    </div>
                </form>
            </div>

            <!-- Events Table -->
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>📅 Event Details</th>
                            <th>📍 Venue & Date</th>
                            <th>📊 Status</th>
                            <th>👤 Created By</th>
                            <th>⚡ Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($eventList): foreach ($eventList as $event): ?>
                        <tr>
                            <td>
                                <strong style="color: #2d3748;"><?= htmlspecialchars($event['EventTitle']) ?></strong><br>
                                <small style="color: #4a5568;"><?= htmlspecialchars(substr($event['EventDescription'], 0, 80)) ?>...</small>
                            </td>
                            <td>
                                <strong style="color: #2d3748;"><?= htmlspecialchars($event['EventVenue']) ?></strong><br>
                                <small style="color: #4a5568;"><?= htmlspecialchars($event['EventDate']) ?> (<?= htmlspecialchars($event['EventDay']) ?>)</small>
                            </td>
                            <td>
                                <span class="status-badge status-<?= strtolower($event['EventStatus']) ?>">
                                    <?= htmlspecialchars($event['EventStatus']) ?>
                                </span>
                            </td>
                            <td>
                                <span style="color: #4a5568;">Staff ID: <?= htmlspecialchars($event['StaffID']) ?></span>
                            </td>
                            <td>
                                <a href="staff_view_event.php?id=<?= $event['EventID'] ?>" style="color: #667eea; text-decoration: none; margin-right: 12px;">
                                    <i class="fas fa-eye"></i> View
                                </a><br>
                                <a href="update_event.php?id=<?= $event['EventID'] ?>" style="color: #667eea; text-decoration: none;">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: #4a5568; padding: 60px 20px;">
                                <div style="font-size: 48px; margin-bottom: 16px;">📭</div>
                                <strong>No events found matching your criteria</strong><br>
                                <small>Try adjusting your filters or create a new event above</small>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>

    <script>
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
            const dateInput = document.getElementById('date');
            const daySelect = document.getElementById('day');
            const eventForm = document.getElementById('eventForm');

            // Set minimum date to today
            const today = new Date();
            dateInput.min = today.toISOString().split('T')[0];

            // Auto-fill day when date is selected
            dateInput.addEventListener('change', function() {
                if (this.value) {
                    const date = new Date(this.value);
                    const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                    daySelect.value = days[date.getDay()];
                }
            });

            // Enhanced form validation with email confirmation
            eventForm.addEventListener('submit', function(e) {
                const title = document.getElementById('title').value.trim();
                const description = document.getElementById('description').value.trim();
                const date = document.getElementById('date').value;
                const venue = document.getElementById('venue').value.trim();

                if (!title || !description || !date || !venue) {
                    e.preventDefault();
                    alert('Please fill in all required fields.');
                    return false;
                }

                // Get student count for confirmation
                const studentCount = <?= $studentCount ?>;
                const totalStudentCount = <?= $totalStudentCount ?>;
                
                let confirmMessage = '';
                
                if (studentCount > 0) {
                    confirmMessage = `🩸 CREATE EVENT & SEND NOTIFICATIONS\n\n` +
                                   `Event: "${title}"\n` +
                                   `Date: ${date}\n` +
                                   `Venue: ${venue}\n\n` +
                                   `📧 NOTIFICATION STATUS:\n` +
                                   `• ${studentCount} students will receive EMAIL notifications\n` +
                                   `• ${studentCount} students will receive IN-APP notifications\n\n` +
                                   `✅ Using XAMPP-optimized email delivery system\n\n` +
                                   `Are you sure you want to create this event and send notifications?`;
                } else if (totalStudentCount > 0) {
                    confirmMessage = `⚠️ CREATE EVENT (LIMITED NOTIFICATIONS)\n\n` +
                                   `Event: "${title}"\n` +
                                   `Date: ${date}\n` +
                                   `Venue: ${venue}\n\n` +
                                   `📧 NOTIFICATION STATUS:\n` +
                                   `• ${totalStudentCount} students will receive IN-APP notifications\n` +
                                   `• 0 students will receive EMAIL notifications (no email addresses)\n\n` +
                                   `Are you sure you want to create this event?`;
                } else {
                    confirmMessage = `❌ CREATE EVENT (NO NOTIFICATIONS)\n\n` +
                                   `Event: "${title}"\n` +
                                   `Date: ${date}\n` +
                                   `Venue: ${venue}\n\n` +
                                   `⚠️ WARNING: No students are in the database.\n` +
                                   `No notifications will be sent.\n\n` +
                                   `Are you sure you want to create this event?`;
                }

                if (!confirm(confirmMessage)) {
                    e.preventDefault();
                    return false;
                }
            });

            // Add entrance animations
            const elements = document.querySelectorAll('.page-header, .stat-card, .form-container, .filter-container, .table-container');
            elements.forEach((el, index) => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    el.style.transition = 'all 0.6s ease';
                    el.style.opacity = '1';
                    el.style.transform = 'translateY(0)';
                }, index * 100);
            });

            // Add hover effects to stat cards
            document.querySelectorAll('.stat-card').forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-8px) scale(1.05)';
                });
                
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0) scale(1)';
                });
            });

            // Add click animations to buttons
            document.querySelectorAll('.btn').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    // Create ripple effect
                    const ripple = document.createElement('span');
                    const rect = this.getBoundingClientRect();
                    const size = Math.max(rect.width, rect.height);
                    const x = e.clientX - rect.left - size / 2;
                    const y = e.clientY - rect.top - size / 2;
                    
                    ripple.style.cssText = `
                        position: absolute;
                        width: ${size}px;
                        height: ${size}px;
                        left: ${x}px;
                        top: ${y}px;
                        background: rgba(255, 255, 255, 0.3);
                        border-radius: 50%;
                        transform: scale(0);
                        animation: ripple 0.6s linear;
                        pointer-events: none;
                    `;
                    
                    this.appendChild(ripple);
                    
                    setTimeout(() => {
                        ripple.remove();
                    }, 600);
                });
            });

            // Add ripple animation CSS
            const style = document.createElement('style');
            style.textContent = `
                @keyframes ripple {
                    to {
                        transform: scale(2);
                        opacity: 0;
                    }
                }
                .btn {
                    position: relative;
                    overflow: hidden;
                }
            `;
            document.head.appendChild(style);

            // Console logging for debugging
            console.log('📅 Create Event Page Loaded');
            console.log('📊 Students with Email:', <?= $studentCount ?>);
            console.log('👥 Total Students:', <?= $totalStudentCount ?>);
            console.log('📋 Total Events:', <?= count($eventList) ?>);
            console.log('🎯 Enhanced Create Event Interface Active!');
        });

        // Keyboard shortcuts for accessibility
        document.addEventListener('keydown', function(e) {
            // Alt+N for new event (focus on title input)
            if (e.altKey && e.key === 'n') {
                e.preventDefault();
                document.getElementById('title').focus();
            }
            
            // Alt+S for search
            if (e.altKey && e.key === 's') {
                e.preventDefault();
                document.getElementById('search').focus();
            }
            
            // Alt+D for dashboard
            if (e.altKey && e.key === 'd') {
                e.preventDefault();
                window.location.href = 'staff_dashboard.php';
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
            .keyboard-navigation input:focus,
            .keyboard-navigation textarea:focus,
            .keyboard-navigation select:focus {
                outline: 3px solid #667eea;
                outline-offset: 2px;
            }
        `;
        document.head.appendChild(keyboardStyle);

        // Performance monitoring
        window.addEventListener('load', function() {
            const loadTime = performance.now();
            console.log(`⚡ Create Event Page loaded in ${Math.round(loadTime)}ms`);
            
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
                notification.innerHTML = '✅ Create Event Ready!';
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
    </script>
</body>
</html>