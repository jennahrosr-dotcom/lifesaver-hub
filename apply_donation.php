<?php
session_start();

// Check if student is logged in
if (!isset($_SESSION['donor_id'])) {
    header("Location: donor_login.php");
    exit;
}

$pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$donorId = $_SESSION['donor_id'];

// Get donor information
$stmt = $pdo->prepare("SELECT * FROM donor WHERE DonorID = ?");
$stmt->execute([$donorId]);
$donor = $stmt->fetch();

if (!$donor) {
    session_destroy();
    header("Location: donor_login.php");
    exit;
}

// Check if event ID is provided
$eventId = null;
if (isset($_GET['event_id']) && !empty($_GET['event_id'])) {
    $eventId = (int)$_GET['event_id'];
    
    // Verify event exists and is active
    $eventStmt = $pdo->prepare("SELECT * FROM event WHERE EventID = ? AND EventStatus IN ('Upcoming', 'Ongoing')");
    $eventStmt->execute([$eventId]);
    $event = $eventStmt->fetch();
    
    if (!$event) {
        $_SESSION['error'] = "Event not found or no longer available.";
        header("Location: events.php");
        exit;
    }
} else {
    $_SESSION['error'] = "Event ID is required.";
    header("Location: events.php");
    exit;
}

// Check if user has passed health questionnaire
if (!isset($_SESSION['questionnaire_passed'])) {
    // Check if user has existing eligible health questionnaire for this event
    $healthCheckStmt = $pdo->prepare("
        SELECT hq.* FROM healthquestion hq 
        JOIN registration r ON hq.RegistrationID = r.RegistrationID 
        WHERE r.DonorID = ? AND r.EventID = ? AND hq.HealthStatus = 'Eligible'
        ORDER BY hq.HealthDate DESC LIMIT 1
    ");
    $healthCheckStmt->execute([$donorId, $eventId]);
    $healthRecord = $healthCheckStmt->fetch();
    
    if (!$healthRecord) {
        $_SESSION['error'] = "Please complete the health questionnaire first.";
        header("Location: health_questionnaire.php?event_id=" . $eventId);
        exit;
    }
}

// Check if user is already registered for this event
$existingRegStmt = $pdo->prepare("
    SELECT * FROM registration 
    WHERE DonorID = ? AND EventID = ? AND RegistrationStatus != 'Cancelled'
    ORDER BY RegistrationDate DESC LIMIT 1
");
$existingRegStmt->execute([$donorId, $eventId]);
$existingRegistration = $existingRegStmt->fetch();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Get form data
        $attendanceChoice = $_POST['attendance_choice'];
        $additionalNotes = trim($_POST['additional_notes'] ?? '');
        
        if ($existingRegistration) {
            // Update existing registration
            $updateStmt = $pdo->prepare("
                UPDATE registration SET 
                RegistrationStatus = 'Confirmed',
                AttendanceStatus = ?,
                RegistrationDate = NOW()
                WHERE RegistrationID = ?
            ");
            $updateStmt->execute([$attendanceChoice, $existingRegistration['RegistrationID']]);
            $registrationId = $existingRegistration['RegistrationID'];
        } else {
            // Create new registration
            $insertStmt = $pdo->prepare("
                INSERT INTO registration (DonorID, EventID, RegistrationDate, RegistrationStatus, AttendanceStatus) 
                VALUES (?, ?, NOW(), 'Confirmed', ?)
            ");
            $insertStmt->execute([$donorId, $eventId, $attendanceChoice]);
            $registrationId = $pdo->lastInsertId();
        }
        
        // Create notification for successful registration
        try {
            $notificationStmt = $pdo->prepare("
                INSERT INTO notification (DonorID, EventID, NotificationType, NotificationTitle, NotificationMessage, CreatedDate, IsRead) 
                VALUES (?, ?, 'Registration', ?, ?, NOW(), 0)
            ");
            
            $notificationTitle = "Registration Confirmed for " . $event['EventTitle'];
            $notificationMessage = "Your registration for the blood donation event '" . $event['EventTitle'] . "' has been confirmed. Event Date: " . date('F j, Y', strtotime($event['EventDate'])) . " at " . $event['EventVenue'] . ".";
            
            $notificationStmt->execute([
                $donorId, 
                $eventId, 
                $notificationTitle, 
                $notificationMessage
            ]);
        } catch (Exception $notifError) {
            // Continue even if notification fails
        }
        
        $_SESSION['success'] = "Your blood donation registration has been confirmed successfully!";
        $_SESSION['registration_id'] = $registrationId;
        header("Location: registration_success.php?event_id=" . $eventId);
        exit;
        
    } catch (Exception $e) {
        $_SESSION['error'] = "Error processing registration: " . $e->getMessage();
    }
}

// Get event statistics
$statsStmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_registered,
        SUM(CASE WHEN RegistrationStatus = 'Confirmed' THEN 1 ELSE 0 END) as confirmed_count,
        SUM(CASE WHEN AttendanceStatus = 'Present' THEN 1 ELSE 0 END) as attended_count
    FROM registration 
    WHERE EventID = ? AND RegistrationStatus != 'Cancelled'
");
$statsStmt->execute([$eventId]);
$eventStats = $statsStmt->fetch();

// Clear questionnaire passed session
unset($_SESSION['questionnaire_passed']);

// Format event date
$eventDate = new DateTime($event['EventDate']);
$today = new DateTime();
$isToday = $eventDate->format('Y-m-d') === $today->format('Y-m-d');
$isTomorrow = $eventDate->format('Y-m-d') === $today->modify('+1 day')->format('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apply for Blood Donation - <?php echo htmlspecialchars($event['EventTitle']); ?> - LifeSaver Hub</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%);
            min-height: 100vh;
            color: #333;
            position: relative;
            overflow-x: hidden;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: 
                radial-gradient(circle at 15% 85%, rgba(255, 107, 107, 0.4) 0%, transparent 50%),
                radial-gradient(circle at 85% 15%, rgba(102, 126, 234, 0.4) 0%, transparent 50%),
                radial-gradient(circle at 50% 50%, rgba(240, 147, 251, 0.3) 0%, transparent 50%),
                radial-gradient(circle at 30% 40%, rgba(118, 75, 162, 0.3) 0%, transparent 50%);
            z-index: -1;
            animation: float 25s ease-in-out infinite;
        }

        body::after {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: 
                linear-gradient(45deg, transparent 40%, rgba(255, 255, 255, 0.1) 50%, transparent 60%),
                linear-gradient(-45deg, transparent 40%, rgba(255, 255, 255, 0.05) 50%, transparent 60%);
            z-index: -1;
            animation: shimmerMove 15s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            33% { transform: translateY(-20px) rotate(1deg); }
            66% { transform: translateY(-10px) rotate(-1deg); }
        }

        @keyframes shimmerMove {
            0%, 100% { transform: translateX(-100px) translateY(-100px); }
            50% { transform: translateX(100px) translateY(100px); }
        }

        .navbar {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(25px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            padding: 15px 0;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .navbar-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .navbar-brand {
            display: flex;
            align-items: center;
            gap: 15px;
            color: white;
            text-decoration: none;
            font-size: 24px;
            font-weight: 700;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        .navbar-brand::before {
            content: '🩸';
            font-size: 2rem;
            animation: pulse 2s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
            color: white;
            background: rgba(255, 255, 255, 0.1);
            padding: 10px 20px;
            border-radius: 25px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 30px;
        }

        .page-header {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(25px);
            border-radius: 25px;
            padding: 40px;
            margin-bottom: 30px;
            box-shadow: 0 15px 50px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
            position: relative;
            overflow: hidden;
            text-align: center;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
            animation: shimmer 8s linear infinite;
        }

        @keyframes shimmer {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .page-header-content {
            position: relative;
            z-index: 1;
        }

        .page-header h1 {
            font-size: 3rem;
            font-weight: 800;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 20px;
            text-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
            margin: 0 0 15px 0;
        }

        .page-header h1 i {
            background: linear-gradient(135deg, #f093fb, #00d2d3);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-size: 2.5rem;
            animation: bounce 2s ease-in-out infinite;
        }

        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% { transform: translateY(0); }
            40% { transform: translateY(-10px); }
            60% { transform: translateY(-5px); }
        }

        .page-header p {
            color: rgba(255, 255, 255, 0.9);
            font-size: 18px;
            font-weight: 500;
            margin-bottom: 20px;
        }

        .success-badge {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: linear-gradient(135deg, #10ac84, #00d2d3);
            color: white;
            padding: 12px 25px;
            border-radius: 25px;
            font-weight: 700;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
            box-shadow: 0 8px 25px rgba(16, 172, 132, 0.4);
            margin-bottom: 20px;
        }

        .event-info {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(25px);
            border-radius: 25px;
            padding: 35px;
            margin-bottom: 30px;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .event-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .event-title {
            font-size: 2rem;
            font-weight: 800;
            color: white;
            margin-bottom: 15px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        .event-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 25px;
            margin-bottom: 25px;
        }

        .detail-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 20px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 15px;
            transition: all 0.3s ease;
        }

        .detail-item:hover {
            background: rgba(255, 255, 255, 0.15);
            transform: translateY(-2px);
        }

        .detail-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
            flex-shrink: 0;
        }

        .detail-content h4 {
            color: white;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 5px;
            font-weight: 700;
        }

        .detail-content p {
            color: rgba(255, 255, 255, 0.9);
            font-size: 16px;
            font-weight: 500;
        }

        .event-stats {
            display: flex;
            justify-content: center;
            gap: 30px;
            padding: 20px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 15px;
        }

        .stat-item {
            text-align: center;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 800;
            color: white;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        .stat-label {
            color: rgba(255, 255, 255, 0.8);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }

        .registration-form {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(25px);
            border-radius: 25px;
            padding: 40px;
            margin-bottom: 30px;
            box-shadow: 0 15px 50px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .form-section-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: white;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
            padding-bottom: 15px;
            border-bottom: 2px solid rgba(255, 255, 255, 0.2);
        }

        .form-section-title i {
            background: linear-gradient(135deg, #f093fb, #00d2d3);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            font-weight: 700;
            color: white;
            font-size: 16px;
            margin-bottom: 15px;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
        }

        .radio-group {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .radio-option {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            padding: 20px;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }

        .radio-option:hover {
            background: rgba(255, 255, 255, 0.12);
            transform: translateY(-2px);
        }

        .radio-option.selected {
            background: rgba(255, 255, 255, 0.15);
            border-color: #00d2d3;
            box-shadow: 0 8px 25px rgba(0, 210, 211, 0.3);
        }

        .radio-option input[type="radio"] {
            appearance: none;
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255, 255, 255, 0.4);
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            cursor: pointer;
            position: relative;
            transition: all 0.3s ease;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .radio-option input[type="radio"]:checked {
            border-color: #00d2d3;
            background: linear-gradient(135deg, #00d2d3, #667eea);
        }

        .radio-option input[type="radio"]:checked::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 8px;
            height: 8px;
            background: white;
            border-radius: 50%;
            transform: translate(-50%, -50%);
        }

        .radio-content h4 {
            color: white;
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .radio-content p {
            color: rgba(255, 255, 255, 0.8);
            font-size: 14px;
            line-height: 1.5;
        }

        .form-group textarea {
            width: 100%;
            padding: 15px 20px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 15px;
            font-size: 16px;
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
            color: white;
            font-weight: 500;
            font-family: inherit;
            resize: vertical;
            min-height: 120px;
        }

        .form-group textarea::placeholder {
            color: rgba(255, 255, 255, 0.6);
        }

        .form-group textarea:focus {
            outline: none;
            border-color: rgba(255, 255, 255, 0.6);
            background: rgba(255, 255, 255, 0.3);
            box-shadow: 0 0 0 4px rgba(255, 255, 255, 0.1);
            transform: scale(1.02);
        }

        .form-actions {
            display: flex;
            gap: 20px;
            justify-content: center;
            margin-top: 40px;
            padding-top: 30px;
            border-top: 1px solid rgba(255, 255, 255, 0.2);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            padding: 18px 35px;
            border: none;
            border-radius: 50px;
            font-weight: 700;
            font-size: 16px;
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
            min-width: 200px;
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

        .btn-primary {
            background: linear-gradient(135deg, #10ac84, #00d2d3);
            color: white;
            box-shadow: 0 10px 30px rgba(16, 172, 132, 0.4);
        }

        .btn-primary:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 18px 40px rgba(16, 172, 132, 0.6);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 2px solid rgba(255, 255, 255, 0.4);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.3);
            border-color: rgba(255, 255, 255, 0.6);
            transform: translateY(-3px) scale(1.05);
        }

        .existing-registration {
            background: linear-gradient(135deg, rgba(255, 193, 7, 0.2), rgba(255, 152, 0, 0.1));
            border: 2px solid rgba(255, 193, 7, 0.5);
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
            text-align: center;
        }

        .existing-registration h3 {
            color: #ffc107;
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .existing-registration p {
            color: rgba(255, 255, 255, 0.9);
            font-size: 16px;
            line-height: 1.5;
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
            background: linear-gradient(135deg, rgba(255, 107, 107, 0.9), rgba(238, 90, 36, 0.9));
            color: white;
            border-left: 4px solid #ff6b6b;
        }

        @media (max-width: 768px) {
            .container {
                padding: 20px 15px;
            }
            
            .navbar-content {
                padding: 0 15px;
                flex-direction: column;
                gap: 15px;
            }
            
            .page-header {
                padding: 30px 20px;
            }
            
            .page-header h1 {
                font-size: 2rem;
                flex-direction: column;
            }
            
            .event-details {
                grid-template-columns: 1fr;
            }
            
            .radio-group {
                grid-template-columns: 1fr;
            }
            
            .form-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar">
        <div class="navbar-content">
            <a href="donor_dashboard.php" class="navbar-brand">LifeSaver Hub</a>
            <div class="user-info">
                <i class="fas fa-user-circle"></i>
                <span><?php echo htmlspecialchars($donor['DonorName']); ?></span>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container">
        <!-- Success Message -->
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-content">
                <div class="success-badge">
                    <i class="fas fa-check-circle"></i>
                    Health Questionnaire Passed
                </div>
                <h1>
                    <i class="fas fa-hand-holding-heart"></i>
                    Complete Your Donation Registration
                </h1>
                <p>You're eligible to donate! Complete your registration for this blood donation event.</p>
            </div>
        </div>

        <!-- Event Information -->
        <div class="event-info">
            <div class="event-header">
                <h2 class="event-title"><?php echo htmlspecialchars($event['EventTitle']); ?></h2>
            </div>
            
            <div class="event-details">
                <div class="detail-item">
                    <div class="detail-icon">
                        <i class="fas fa-info-circle"></i>
                    </div>
                    <div class="detail-content">
                        <h4>Event Status</h4>
                        <p><?php echo htmlspecialchars($event['EventStatus']); ?></p>
                    </div>
                </div>
            </div>
            
            <div class="event-stats">
                <div class="stat-item">
                    <div class="stat-number"><?php echo number_format($eventStats['total_registered']); ?></div>
                    <div class="stat-label">Total Registered</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?php echo number_format($eventStats['confirmed_count']); ?></div>
                    <div class="stat-label">Confirmed</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?php echo number_format($eventStats['attended_count']); ?></div>
                    <div class="stat-label">Attended</div>
                </div>
            </div>
        </div>

        <?php if ($existingRegistration && $existingRegistration['RegistrationStatus'] == 'Confirmed'): ?>
            <!-- Existing Registration Notice -->
            <div class="existing-registration">
                <h3>
                    <i class="fas fa-info-circle"></i>
                    You're Already Registered
                </h3>
                <p>
                    You have already registered for this event on <?php echo date('F j, Y g:i A', strtotime($existingRegistration['RegistrationDate'])); ?>.
                    Your registration status is: <strong><?php echo htmlspecialchars($existingRegistration['RegistrationStatus']); ?></strong>
                    <?php if (!empty($existingRegistration['AttendanceStatus'])): ?>
                        | Attendance Status: <strong><?php echo htmlspecialchars($existingRegistration['AttendanceStatus']); ?></strong>
                    <?php endif; ?>
                </p>
                <p style="margin-top: 15px;">
                    You can update your attendance preference below or go back to view other events.
                </p>
            </div>
        <?php endif; ?>

        <!-- Registration Form -->
        <div class="registration-form">
            <form method="POST" action="" id="donationRegistrationForm">
                <h3 class="form-section-title">
                    <i class="fas fa-clipboard-check"></i>
                    Registration Details
                </h3>
                
                <div class="form-group">
                    <label for="attendance_choice">
                        <i class="fas fa-calendar-check" style="margin-right: 8px; color: #00d2d3;"></i>
                        Will you be attending this blood donation event?
                        <span style="color: #ff6b6b; margin-left: 5px;">*</span>
                    </label>
                    <div class="radio-group">
                        <div class="radio-option" onclick="selectOption(this, 'Present')">
                            <input type="radio" id="attendance_yes" name="attendance_choice" value="Present" required>
                            <div class="radio-content">
                                <h4>Yes, I will attend</h4>
                                <p>I confirm my attendance and commitment to donate blood at this event. I understand the importance of showing up as scheduled.</p>
                            </div>
                        </div>
                        
                        <div class="radio-option" onclick="selectOption(this, 'Tentative')">
                            <input type="radio" id="attendance_tentative" name="attendance_choice" value="Tentative" required>
                            <div class="radio-content">
                                <h4>Tentative attendance</h4>
                                <p>I would like to register but my attendance is tentative due to potential scheduling conflicts or other commitments.</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="additional_notes">
                        <i class="fas fa-sticky-note" style="margin-right: 8px; color: #00d2d3;"></i>
                        Additional Notes (Optional)
                    </label>
                    <textarea 
                        id="additional_notes" 
                        name="additional_notes" 
                        placeholder="Any additional information, special requirements, or notes you'd like to share with the organizers..."
                        maxlength="500"></textarea>
                    <div style="color: rgba(255, 255, 255, 0.7); font-size: 12px; margin-top: 5px; text-align: right;">
                        <span id="charCount">0</span>/500 characters
                    </div>
                </div>

                <!-- Important Information -->
                <div style="background: rgba(255, 255, 255, 0.1); border-radius: 15px; padding: 25px; margin: 30px 0; border-left: 4px solid #00d2d3;">
                    <h4 style="color: white; font-size: 16px; margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-info-circle" style="color: #00d2d3;"></i>
                        Important Reminders
                    </h4>
                    <ul style="color: rgba(255, 255, 255, 0.9); line-height: 1.6; margin-left: 20px;">
                        <li style="margin-bottom: 8px;">Arrive at least 15 minutes before your scheduled time</li>
                        <li style="margin-bottom: 8px;">Bring a valid government-issued ID</li>
                        <li style="margin-bottom: 8px;">Eat a healthy meal and stay hydrated before donating</li>
                        <li style="margin-bottom: 8px;">Get adequate rest the night before</li>
                        <li style="margin-bottom: 8px;">Avoid alcohol 24 hours before donation</li>
                        <li>Notify organizers immediately if you cannot attend</li>
                    </ul>
                </div>

                <!-- Form Actions -->
                <div class="form-actions">
                    <a href="events.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i>
                        Back to Events
                    </a>
                    <button type="submit" class="btn btn-primary" id="submitBtn" disabled>
                        <i class="fas fa-heart"></i>
                        <?php echo $existingRegistration ? 'Update Registration' : 'Register to Donate'; ?>
                    </button>
                </div>
            </form>
        </div>

        <!-- Contact Information -->
        <div style="background: rgba(255, 255, 255, 0.1); border-radius: 20px; padding: 25px; text-align: center;">
            <h4 style="color: white; margin-bottom: 15px; display: flex; align-items: center; justify-content: center; gap: 10px;">
                <i class="fas fa-question-circle" style="color: #00d2d3;"></i>
                Need Help?
            </h4>
            <p style="color: rgba(255, 255, 255, 0.8); line-height: 1.5;">
                If you have any questions about the donation process or need to make changes to your registration, 
                please contact the event organizers or visit our help center.
            </p>
        </div>
    </div>

    <script>
        // Character counter for textarea
        const textarea = document.getElementById('additional_notes');
        const charCount = document.getElementById('charCount');
        
        textarea.addEventListener('input', function() {
            const currentLength = this.value.length;
            charCount.textContent = currentLength;
            
            if (currentLength > 450) {
                charCount.style.color = '#ff6b6b';
            } else {
                charCount.style.color = 'rgba(255, 255, 255, 0.7)';
            }
        });

        // Radio option selection
        function selectOption(element, value) {
            // Remove selected class from all options
            document.querySelectorAll('.radio-option').forEach(option => {
                option.classList.remove('selected');
            });
            
            // Add selected class to clicked option
            element.classList.add('selected');
            
            // Check the radio button
            const radio = element.querySelector('input[type="radio"]');
            radio.checked = true;
            
            // Enable submit button
            checkFormValidity();
        }

        // Form validation
        function checkFormValidity() {
            const attendanceSelected = document.querySelector('input[name="attendance_choice"]:checked');
            const submitBtn = document.getElementById('submitBtn');
            
            if (attendanceSelected) {
                submitBtn.disabled = false;
                submitBtn.style.opacity = '1';
            } else {
                submitBtn.disabled = true;
                submitBtn.style.opacity = '0.6';
            }
        }

        // Form submission handling
        document.getElementById('donationRegistrationForm').addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('submitBtn');
            const attendanceSelected = document.querySelector('input[name="attendance_choice"]:checked');
            
            if (!attendanceSelected) {
                e.preventDefault();
                alert('Please select your attendance preference.');
                return false;
            }
            
            // Show loading state
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            submitBtn.disabled = true;
        });

        // Initialize form state
        document.addEventListener('DOMContentLoaded', function() {
            // Check if any radio is already selected (for edit mode)
            const selectedRadio = document.querySelector('input[name="attendance_choice"]:checked');
            if (selectedRadio) {
                const parentOption = selectedRadio.closest('.radio-option');
                if (parentOption) {
                    parentOption.classList.add('selected');
                }
                checkFormValidity();
            }
            
            // Add event listeners to radio buttons
            document.querySelectorAll('input[name="attendance_choice"]').forEach(radio => {
                radio.addEventListener('change', checkFormValidity);
            });
        });

        // Add smooth hover effects
        document.querySelectorAll('.radio-option').forEach(option => {
            option.addEventListener('mouseenter', function() {
                if (!this.classList.contains('selected')) {
                    this.style.transform = 'translateY(-2px) scale(1.02)';
                }
            });
            
            option.addEventListener('mouseleave', function() {
                if (!this.classList.contains('selected')) {
                    this.style.transform = 'translateY(0) scale(1)';
                }
            });
        });

        // Confirmation dialog for form submission
        document.getElementById('donationRegistrationForm').addEventListener('submit', function(e) {
            const attendanceChoice = document.querySelector('input[name="attendance_choice"]:checked');
            const isUpdate = <?php echo $existingRegistration ? 'true' : 'false'; ?>;
            
            if (attendanceChoice) {
                const choice = attendanceChoice.value;
                const action = isUpdate ? 'update' : 'complete';
                
                if (choice === 'Present') {
                    const confirmed = confirm(`Are you sure you want to ${action} your registration? By confirming, you commit to attending this blood donation event.`);
                    if (!confirmed) {
                        e.preventDefault();
                        // Reset submit button
                        const submitBtn = document.getElementById('submitBtn');
                        submitBtn.innerHTML = `<i class="fas fa-heart"></i> ${isUpdate ? 'Update Registration' : 'Register to Donate'}`;
                        submitBtn.disabled = false;
                        return false;
                    }
                }
            }
        });
    </script>
</body>
</html>">
                    <div class="detail-icon">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div class="detail-content">
                        <h4>Event Date</h4>
                        <p>
                            <?php echo $eventDate->format('l, F j, Y'); ?>
                            <?php if ($isToday): ?>
                                <span style="color: #feca57; font-weight: 700;"> (Today!)</span>
                            <?php elseif ($isTomorrow): ?>
                                <span style="color: #00d2d3; font-weight: 700;"> (Tomorrow)</span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                
                <div class="detail-item">
                    <div class="detail-icon">
                        <i class="fas fa-map-marker-alt"></i>
                    </div>
                    <div class="detail-content">
                        <h4>Venue</h4>
                        <p><?php echo htmlspecialchars($event['EventVenue']); ?></p>
                    </div>
                </div>
                
                <?php if (!empty($event['EventDay'])): ?>
                <div class="detail-item">
                    <div class="detail-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="detail-content">
                        <h4>Time</h4>
                        <p><?php echo htmlspecialchars($event['EventDay']); ?></p>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="detail-item