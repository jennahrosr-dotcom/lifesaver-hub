<?php
session_start();

if (!isset($_SESSION['student_id'])) {
    header("Location: index.php");
    exit;
}

require 'db.php';

$studentId = $_SESSION['student_id'];

// Handle event registration and unregistration
$success = '';
$error = '';

// Enhanced date validation function - Fixed to use proper timezone
function isEventPast($eventDate) {
    // Set timezone to ensure consistent date comparison
    date_default_timezone_set('Asia/Kuala_Lumpur'); // Adjust to your timezone
    
    $eventTimestamp = strtotime($eventDate . ' 23:59:59'); // End of event day
    $currentTimestamp = time(); // Current timestamp
    
    return $currentTimestamp > $eventTimestamp;
}

// Function to check if event is today
function isEventToday($eventDate) {
    date_default_timezone_set('Asia/Kuala_Lumpur');
    return date('Y-m-d') === $eventDate;
}

// Get student information
$stmt = $pdo->prepare("SELECT * FROM student WHERE StudentID = ?");
$stmt->execute([$studentId]);
$student = $stmt->fetch();

// IMPROVED AUTO-UPDATE LOGIC
// Set timezone for consistent date handling
date_default_timezone_set('Asia/Kuala_Lumpur');
$today = date('Y-m-d');
$currentDateTime = date('Y-m-d H:i:s');

try {
    // Start transaction for atomic updates
    $pdo->beginTransaction();
    
    // 1. Update past events to 'Completed' (events before today)
    $updatePastStmt = $pdo->prepare("
        UPDATE event 
        SET EventStatus = 'Completed' 
        WHERE EventDate < ? 
        AND EventStatus NOT IN ('Completed', 'Deleted')
    ");
    $updatePastStmt->execute([$today]);
    
    // 2. Update today's events to 'Ongoing' if they're still 'Upcoming'
    $updateTodayStmt = $pdo->prepare("
        UPDATE event 
        SET EventStatus = 'Ongoing' 
        WHERE EventDate = ? 
        AND EventStatus = 'Upcoming'
    ");
    $updateTodayStmt->execute([$today]);
    
    // 3. Handle end-of-day logic: if it's late in the day (after 6 PM), 
    //    mark today's events as completed too
    $currentHour = (int)date('H');
    if ($currentHour >= 18) { // After 6 PM
        $updateTodayLateStmt = $pdo->prepare("
            UPDATE event 
            SET EventStatus = 'Completed' 
            WHERE EventDate = ? 
            AND EventStatus = 'Ongoing'
        ");
        $updateTodayLateStmt->execute([$today]);
    }
    
    // Commit the transaction
    $pdo->commit();
    
} catch (Exception $e) {
    // Rollback on error
    $pdo->rollback();
    error_log("Error updating event statuses: " . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $eventId = $_POST['event_id'];
    $action = $_POST['action'];
    
    // Get event details for validation
    $checkEventStmt = $pdo->prepare("SELECT EventDate, EventStatus, EventTitle FROM event WHERE EventID = ?");
    $checkEventStmt->execute([$eventId]);
    $eventToCheck = $checkEventStmt->fetch();
    
    if (!$eventToCheck) {
        $error = "Event not found.";
    } else {
        // Only handle unregistration now, since registration goes through health questionnaire
        if ($action === 'unregister') {
            // Enhanced validation for unregistration - only prevent for past events
            if (isEventPast($eventToCheck['EventDate'])) {
                $error = "Cannot unregister from past events.";
            } else {
                // Allow unregistration from upcoming and ongoing events
                $unregisterStmt = $pdo->prepare("UPDATE registration SET RegistrationStatus = 'Cancelled' WHERE StudentID = ? AND EventID = ?");
                $unregisterStmt->execute([$studentId, $eventId]);
                $success = "Successfully unregistered from " . htmlspecialchars($eventToCheck['EventTitle']) . ".";
            }
        }
    }
}

// Function to check if user has completed blood donation registration
function hasCompletedBloodDonationRegistration($pdo, $studentEmail) {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM registration 
            WHERE RegistrationEmail = ? 
            AND RegistrationName IS NOT NULL 
            AND RegistrationDate >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmt->execute([$studentEmail]);
        $result = $stmt->fetch();
        return $result['count'] > 0;
    } catch (Exception $e) {
        error_log("Error checking blood donation registration: " . $e->getMessage());
        return false;
    }
}

// Get student email for checking blood donation registration
$studentEmailStmt = $pdo->prepare("SELECT StudentEmail FROM student WHERE StudentID = ?");
$studentEmailStmt->execute([$studentId]);
$studentInfo = $studentEmailStmt->fetch();
$studentEmail = $studentInfo['StudentEmail'] ?? '';

// Check if user has completed blood donation registration
$hasCompletedBloodDonation = hasCompletedBloodDonationRegistration($pdo, $studentEmail);

// Filtering
$where = ["EventStatus != 'Deleted'"];
$params = [];

if (!empty($_GET['filter_date'])) {
    $where[] = "EventDate = ?";
    $params[] = $_GET['filter_date'];
}
if (!empty($_GET['filter_status'])) {
    $where[] = "EventStatus = ?";
    $params[] = $_GET['filter_status'];
}

// Simple query to get events with registration info
$sql = "SELECT e.*, 
        (SELECT COUNT(*) FROM registration r WHERE r.EventID = e.EventID AND r.StudentID = ? AND r.RegistrationStatus != 'Cancelled') as IsRegistered,
        (SELECT COUNT(*) FROM registration r WHERE r.EventID = e.EventID AND r.RegistrationStatus != 'Cancelled') as TotalRegistered,
        (SELECT COUNT(*) FROM donation d 
         INNER JOIN registration r ON d.RegistrationID = r.RegistrationID 
         WHERE r.EventID = e.EventID AND r.StudentID = ?) as HasDonated
        FROM event e";

if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY 
    CASE 
        WHEN EventStatus = 'Ongoing' THEN 1
        WHEN EventStatus = 'Upcoming' THEN 2
        WHEN EventStatus = 'Completed' THEN 3
        ELSE 4
    END,
    EventDate DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge([$studentId, $studentId], $params));
$events = $stmt->fetchAll();

// Get event statistics for dashboard
$statsQuery = "SELECT 
    COUNT(*) as total_events,
    SUM(CASE WHEN EventStatus = 'Upcoming' THEN 1 ELSE 0 END) as upcoming_events,
    SUM(CASE WHEN EventStatus = 'Ongoing' THEN 1 ELSE 0 END) as ongoing_events,
    SUM(CASE WHEN EventStatus = 'Completed' THEN 1 ELSE 0 END) as completed_events
    FROM event WHERE EventStatus != 'Deleted'";
$statsStmt = $pdo->query($statsQuery);
$stats = $statsStmt->fetch();

// Check for error message from URL parameter
if (isset($_GET['error'])) {
    $error = $_GET['error'];
}

// Debug information - Remove in production
$debugInfo = [
    'current_date' => $today,
    'current_time' => date('Y-m-d H:i:s'),
    'current_hour' => date('H'),
    'timezone' => date_default_timezone_get()
];
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
            display: flex;
            align-items: center;
            gap: 20px;
            text-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .page-header h1 i {
            color: #667eea;
        }

        .page-header p {
            color: #4a5568;
            font-size: 18px;
            font-weight: 400;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 25px;
            margin-bottom: 35px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 30px 25px;
            text-align: center;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(0, 0, 0, 0.05);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, transparent, var(--accent-color), transparent);
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-10px) scale(1.05);
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15);
            background: rgba(255, 255, 255, 0.95);
        }

        .stat-card:hover::before {
            height: 5px;
            background: linear-gradient(90deg, var(--accent-color), #667eea, var(--accent-color));
        }

        .stat-card .icon {
            font-size: 3rem;
            margin-bottom: 20px;
            color: #667eea;
            filter: drop-shadow(0 4px 8px rgba(102, 126, 234, 0.3));
            animation: float 3s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }

        .stat-card .number {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 10px;
            color: #2d3748;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            animation: countUp 1s ease-out;
        }

        @keyframes countUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .stat-card .label {
            color: #4a5568;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 2px;
            font-weight: 600;
        }

        .stat-card.total { --accent-color: #667eea; }
        .stat-card.upcoming { --accent-color: #10ac84; }
        .stat-card.ongoing { --accent-color: #feca57; }
        .stat-card.completed { --accent-color: #747d8c; }

        .filters-section {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 35px;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .filters-form {
            display: flex;
            flex-wrap: wrap;
            gap: 25px;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .filter-group label {
            font-weight: 600;
            color: #2d3748;
            font-size: 14px;
        }

        .filter-group input,
        .filter-group select {
            padding: 15px 20px;
            border: 2px solid rgba(102, 126, 234, 0.15);
            border-radius: 15px;
            font-size: 14px;
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
            min-width: 180px;
            color: #2d3748;
            font-weight: 500;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #667eea;
            background: rgba(255, 255, 255, 0.95);
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
            transform: scale(1.02);
        }

        .filter-actions {
            display: flex;
            gap: 10px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            padding: 15px 35px;
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

        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
        }

        .btn-primary:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 15px 35px rgba(102, 126, 234, 0.6);
        }

        .btn-outline {
            background: rgba(255, 255, 255, 0.8);
            color: #4a5568;
            border: 2px solid rgba(102, 126, 234, 0.3);
        }

        .btn-outline:hover {
            background: rgba(255, 255, 255, 0.95);
            border-color: #667eea;
            color: #2d3748;
            transform: translateY(-2px);
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

        .btn-danger {
            background: linear-gradient(135deg, #ff6b6b, #ee5a24);
            color: white;
            box-shadow: 0 8px 25px rgba(255, 107, 107, 0.4);
        }

        .btn-danger:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 15px 35px rgba(255, 107, 107, 0.6);
        }

        .btn-warning {
            background: linear-gradient(135deg, #feca57, #ff9f43);
            color: white;
            box-shadow: 0 8px 25px rgba(254, 202, 87, 0.4);
        }

        .btn-warning:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 15px 35px rgba(254, 202, 87, 0.6);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #747d8c, #57606f);
            color: white;
            box-shadow: 0 8px 25px rgba(116, 125, 140, 0.4);
        }

        .btn-secondary:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 15px 35px rgba(116, 125, 140, 0.6);
        }

        .events-container {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .events-header {
            padding: 30px 35px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.08);
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.08), rgba(139, 92, 246, 0.08));
        }

        .events-header h3 {
            font-size: 1.8rem;
            font-weight: 700;
            color: #2d3748;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .events-header h3 i {
            color: #667eea;
        }

        /* Student Card View */
        .events-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(420px, 1fr));
            gap: 30px;
            padding: 35px;
        }

        .event-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .event-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea, #764ba2, #ff6b6b);
            transition: height 0.3s ease;
        }

        .event-card:hover {
            transform: translateY(-15px) scale(1.02);
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.15);
            background: rgba(255, 255, 255, 0.98);
        }

        .event-card:hover::before {
            height: 6px;
        }

        .event-card.registered {
            background: linear-gradient(135deg, rgba(16, 172, 132, 0.1), rgba(255, 255, 255, 0.95));
            border: 1px solid rgba(16, 172, 132, 0.3);
        }

        .event-card.registered::before {
            background: linear-gradient(90deg, #10ac84, #00d2d3);
        }

        .event-card.past {
            opacity: 0.8;
            filter: grayscale(20%);
        }

        .event-card.past::before {
            background: linear-gradient(90deg, #747d8c, #57606f);
        }

        .event-card.ongoing {
            background: linear-gradient(135deg, rgba(254, 202, 87, 0.1), rgba(255, 255, 255, 0.95));
            border: 1px solid rgba(254, 202, 87, 0.3);
        }

        .event-card.ongoing::before {
            background: linear-gradient(90deg, #feca57, #ff9f43);
        }

        .event-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 25px;
        }

        .event-title {
            font-size: 1.4rem;
            font-weight: 800;
            color: #2d3748;
            margin: 0;
            line-height: 1.3;
            flex: 1;
            margin-right: 15px;
        }

        .event-status {
            padding: 8px 16px;
            border-radius: 25px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            white-space: nowrap;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .status-upcoming { 
            background: linear-gradient(135deg, #667eea, #764ba2); 
            color: white; 
        }
        .status-ongoing { 
            background: linear-gradient(135deg, #ff9f43, #feca57); 
            color: white; 
        }
        .status-completed { 
            background: linear-gradient(135deg, #10ac84, #00d2d3); 
            color: white; 
        }

        .event-details {
            margin: 25px 0;
            space-y: 15px;
        }

        .detail-item {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
            color: #4a5568;
            font-weight: 500;
        }

        .detail-item i {
            width: 24px;
            height: 24px;
            margin-right: 15px;
            color: white;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }

        .event-description {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 20px;
            border-radius: 15px;
            margin: 20px 0;
            font-style: italic;
            color: #4a5568;
            border-left: 4px solid #667eea;
            position: relative;
            font-weight: 500;
        }

        .event-description::before {
            content: '"';
            font-size: 4rem;
            color: #667eea;
            position: absolute;
            top: -10px;
            left: 10px;
            opacity: 0.3;
            font-family: serif;
        }

        .event-actions {
            margin-top: 25px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .ongoing-notice {
            margin-top: 15px;
            padding: 12px;
            background: linear-gradient(135deg, #fff3cd, #ffeaa7);
            border-radius: 10px;
            font-size: 12px;
            color: #856404;
            border-left: 4px solid #feca57;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
        }

        .ongoing-notice i {
            color: #feca57;
            font-size: 14px;
        }

        .registration-info {
            margin-top: 20px;
            padding: 16px;
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            border-radius: 12px;
            font-size: 13px;
            color: #155724;
            border-left: 4px solid #10ac84;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
        }

        .registration-info i {
            color: #10ac84;
            font-size: 16px;
        }

        .donation-info {
            margin-top: 20px;
            padding: 16px;
            background: linear-gradient(135deg, #fff3cd, #ffeaa7);
            border-radius: 12px;
            font-size: 13px;
            color: #856404;
            border-left: 4px solid #feca57;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
        }

        .donation-info i {
            color: #feca57;
            font-size: 16px;
        }

        .donation-registration-complete {
            margin-top: 20px;
            padding: 16px;
            background: linear-gradient(135deg, #cce7ff, #b8daff);
            border-radius: 12px;
            font-size: 13px;
            color: #004085;
            border-left: 4px solid #667eea;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
        }

        .donation-registration-complete i {
            color: #667eea;
            font-size: 16px;
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

        .alert-success {
            background: linear-gradient(135deg, rgba(16, 172, 132, 0.9), rgba(0, 210, 211, 0.9));
            color: white;
            border-left: 4px solid #10ac84;
        }

        .alert-danger {
            background: linear-gradient(135deg, rgba(255, 107, 107, 0.9), rgba(238, 90, 36, 0.9));
            color: white;
            border-left: 4px solid #ff6b6b;
        }

        .empty-state {
            text-align: center;
            padding: 80px 30px;
            color: #4a5568;
        }

        .empty-state i {
            font-size: 5rem;
            margin-bottom: 25px;
            opacity: 0.4;
            color: #667eea;
        }

        .empty-state h3 {
            font-size: 1.8rem;
            margin-bottom: 15px;
            color: #2d3748;
            font-weight: 700;
        }

        .empty-state p {
            font-size: 16px;
            opacity: 0.8;
        }

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

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }

        .btn:disabled:hover {
            transform: none !important;
            box-shadow: none !important;
        }

        /* Debug Panel - Completely hidden in production */
        .debug-panel {
            display: none !important;
        }

        /* Debug toggle button - Completely hidden in production */
        .debug-toggle {
            display: none !important;
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
            
            .events-grid {
                grid-template-columns: 1fr;
                padding: 20px;
            }
            
            .filters-form {
                flex-direction: column;
                align-items: stretch;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .debug-panel {
                display: none !important;
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
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
        }
    </style>
</head>
<body>
    <!-- Debug elements removed for clean production interface -->

    <button class="mobile-menu-btn" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>

    <div class="app-container">
        <!-- Enhanced Sidebar -->
        <nav class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <a href="student_dashboard.php" class="logo">
                    <img src="images/logo.jpg" alt="LifeSaver Hub Logo">
                    <span>LifeSaver Hub</span>
                </a>
            </div>
            
            <div class="sidebar-nav">
                <div class="nav-sections-container">
                    <div class="nav-section">
                        <div class="nav-section-title">Main Menu</div>
                        <a href="student_dashboard.php" class="nav-item">
                            <i class="fas fa-home"></i>
                            <span>Dashboard</span>
                        </a>
                        <a href="student_view_event.php" class="nav-item active">
                            <i class="fas fa-calendar-alt"></i>
                            <span>Events</span>
                        </a>
                        <a href="student_view_donation.php" class="nav-item">
                            <i class="fas fa-tint"></i>
                            <span>Donations</span>
                        </a>
                        <a href="view_donation_history.php" class="nav-item">
                            <i class="fas fa-history"></i>
                            <span>Donation History</span>
                        </a>
                        <a href="view_reward.php" class="nav-item">
                            <i class="fas fa-gift"></i>
                            <span>Rewards</span>
                        </a>
                        <a href="notifications.php" class="nav-item">
                            <i class="fas fa-bell"></i>
                            <span>Notifications</span>
                        </a>
                    </div>
                    
                    <div class="nav-section">
                        <div class="nav-section-title">Account</div>
                        <a href="student_account.php" class="nav-item">
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
                        <?php echo strtoupper(substr($student['StudentName'], 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <h4><?php echo htmlspecialchars($student['StudentName']); ?></h4>
                        <p>Student ID: <?php echo htmlspecialchars($studentId); ?></p>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Main Content -->
        <main class="main-content">
            <div class="page-header">
                <div class="page-header-content">
                    <h1>
                        <i class="fas fa-calendar-heart"></i>
                        Blood Donation Events
                    </h1>
                    <p>Find and register for upcoming blood donation events to save lives</p>
                </div>
            </div>

            <div class="stats-grid">
                <div class="stat-card total">
                    <div class="icon"><i class="fas fa-calendar-alt"></i></div>
                    <div class="number"><?= $stats['total_events'] ?></div>
                    <div class="label">Total Events</div>
                </div>
                <div class="stat-card upcoming">
                    <div class="icon"><i class="fas fa-clock"></i></div>
                    <div class="number"><?= $stats['upcoming_events'] ?></div>
                    <div class="label">Upcoming</div>
                </div>
                <div class="stat-card ongoing">
                    <div class="icon"><i class="fas fa-play-circle"></i></div>
                    <div class="number"><?= $stats['ongoing_events'] ?></div>
                    <div class="label">Ongoing</div>
                </div>
                <div class="stat-card completed">
                    <div class="icon"><i class="fas fa-check-circle"></i></div>
                    <div class="number"><?= $stats['completed_events'] ?></div>
                    <div class="label">Completed</div>
                </div>
            </div>

            <?php if (isset($success) && $success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>

            <?php if (isset($error) && $error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <div class="filters-section">
                <form class="filters-form" method="GET">
                    <div class="filter-group">
                        <label for="filter_date">Filter by Date</label>
                        <input type="date" name="filter_date" id="filter_date" value="<?= htmlspecialchars($_GET['filter_date'] ?? '') ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label for="filter_status">Filter by Status</label>
                        <select name="filter_status" id="filter_status">
                            <option value="">All Statuses</option>
                            <option value="Upcoming" <?= ($_GET['filter_status'] ?? '') === 'Upcoming' ? 'selected' : '' ?>>Upcoming</option>
                            <option value="Ongoing" <?= ($_GET['filter_status'] ?? '') === 'Ongoing' ? 'selected' : '' ?>>Ongoing</option>
                            <option value="Completed" <?= ($_GET['filter_status'] ?? '') === 'Completed' ? 'selected' : '' ?>>Completed</option>
                        </select>
                    </div>
                    
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="student_view_event.php" class="btn btn-outline">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    </div>
                </form>
            </div>

            <div class="events-container">
                <div class="events-header">
                    <h3>
                        <i class="fas fa-calendar-check"></i>
                        Available Events
                        <span style="color: #4a5568; font-weight: 400; font-size: 1rem;">(<?= count($events) ?> events found)</span>
                    </h3>
                </div>

                <!-- Student Card View -->
                <div class="events-grid">
                    <?php if ($events): ?>
                        <?php foreach ($events as $event): 
                            $isRegistered = $event['IsRegistered'] > 0;
                            $hasDonated = $event['HasDonated'] > 0;
                            $isPast = isEventPast($event['EventDate']);
                            $isToday = isEventToday($event['EventDate']);
                            
                            // Determine correct status based on current logic
                            $currentStatus = $event['EventStatus'];
                            if ($isPast && $currentStatus !== 'Completed') {
                                $currentStatus = 'Completed';
                            } elseif ($isToday && $currentStatus === 'Upcoming') {
                                $currentStatus = 'Ongoing';
                            }
                            
                            // Allow registration for BOTH upcoming AND ongoing events (not past)
                            $canRegister = !$isPast && 
                                           ($currentStatus === 'Upcoming' || $currentStatus === 'Ongoing') && 
                                           !$isRegistered;
                            
                            // Check if event is ongoing
                            $isOngoing = $currentStatus === 'Ongoing';
                        ?>
                            <div class="event-card <?= $isRegistered ? 'registered' : '' ?> <?= $isPast ? 'past' : '' ?> <?= $isOngoing ? 'ongoing' : '' ?>">
                                <div class="event-header">
                                    <h3 class="event-title"><?= htmlspecialchars($event['EventTitle']) ?></h3>
                                    <span class="event-status status-<?= strtolower($currentStatus) ?>">
                                        <?= htmlspecialchars($currentStatus) ?>
                                    </span>
                                </div>

                                <div class="event-details">
                                    <div class="detail-item">
                                        <i class="fas fa-calendar"></i>
                                        <span><?= date('F j, Y', strtotime($event['EventDate'])) ?> (<?= $event['EventDay'] ?>)</span>
                                    </div>
                                    <div class="detail-item">
                                        <i class="fas fa-map-marker-alt"></i>
                                        <span><?= htmlspecialchars($event['EventVenue']) ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <i class="fas fa-users"></i>
                                        <span><?= $event['TotalRegistered'] ?> students registered</span>
                                    </div>
                                </div>

                                <div class="event-description">
                                    <i class="fas fa-info-circle"></i>
                                    <?= htmlspecialchars($event['EventDescription']) ?>
                                </div>

                                <div class="event-actions">
                                    <?php if ($isPast): ?>
                                        <!-- For past events, show only status - NO registration button -->
                                        <span class="btn btn-secondary">
                                            <i class="fas fa-clock"></i> Event Ended
                                        </span>
                                        
                                        <?php if ($isRegistered): ?>
                                            <span class="btn btn-success">
                                                <i class="fas fa-check"></i> You Were Registered
                                            </span>
                                        <?php endif; ?>
                                        
                                    <?php elseif ($isRegistered): ?>
                                        <!-- For current/future events where user is registered -->
                                        <span class="btn btn-success">
                                            <i class="fas fa-check"></i> Registered
                                        </span>
                                        
                                        <?php if ($isOngoing): ?>
                                            <div class="ongoing-notice">
                                                <i class="fas fa-play-circle"></i> Event is currently in progress
                                            </div>
                                        <?php endif; ?>
                                        
                                    <?php elseif ($canRegister): ?>
                                        <!-- Allow registration for BOTH upcoming AND ongoing events -->
                                        <a href="health_questionnaire.php?event_id=<?= $event['EventID'] ?>" class="btn btn-primary">
                                            <i class="fas fa-hand-holding-heart"></i> 
                                            <?= $isOngoing ? 'Join Ongoing Event' : 'Register Now' ?>
                                        </a>
                                        
                                        <?php if ($isOngoing): ?>
                                            <div class="ongoing-notice">
                                                <i class="fas fa-exclamation-triangle"></i> 
                                                Event in progress - Late registration available
                                            </div>
                                        <?php endif; ?>
                                        
                                    <?php else: ?>
                                        <!-- For events that are not past but registration is closed for other reasons -->
                                        <span class="btn btn-secondary">
                                            <i class="fas fa-lock"></i> Registration Closed
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <?php if ($isRegistered && !$isPast): ?>
                                    <div class="registration-info">
                                        <i class="fas fa-info-circle"></i> 
                                        You are registered for this event. Please arrive on time and bring a valid ID.
                                    </div>
                                <?php endif; ?>

                                <?php if ($isOngoing && !$isRegistered && $canRegister): ?>
                                    <div class="donation-info">
                                        <i class="fas fa-clock"></i> 
                                        This event is currently in progress. You can still register and join!
                                    </div>
                                <?php endif; ?>

                                <?php if ($hasCompletedBloodDonation): ?>
                                    <div class="donation-registration-complete">
                                        <i class="fas fa-clipboard-check"></i> 
                                        Blood donation registration completed! Please wait for confirmation from our team.
                                    </div>
                                <?php endif; ?>

                                <?php if ($hasDonated): ?>
                                    <div class="donation-info">
                                        <i class="fas fa-heart"></i> 
                                        Thank you! You have already donated blood for this event.
                                    </div>
                                <?php endif; ?>

                                <?php if ($isPast): ?>
                                    <div class="donation-info" style="background: linear-gradient(135deg, #f1f2f6, #ddd);">
                                        <i class="fas fa-calendar-times" style="color: #747d8c;"></i>
                                        <span style="color: #57606f;">This event has ended. Thank you for your interest!</span>
                                    </div>
                                <?php endif; ?>

                                <!-- Debug info removed for clean production interface -->
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="grid-column: 1 / -1;" class="empty-state">
                            <i class="fas fa-calendar-times"></i>
                            <h3>No Events Found</h3>
                            <p>There are currently no events matching your criteria. Check back later for new opportunities to donate blood and save lives!</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('open');
        }

        function confirmUnregister(eventTitle) {
            return confirm(`Are you sure you want to unregister from "${eventTitle}"? You can register again later if spots are available.`);
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

        // Add smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Form submission feedback
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function() {
                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn && !submitBtn.disabled) {
                    const originalText = submitBtn.innerHTML;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                    submitBtn.disabled = true;
                    
                    // Re-enable after 5 seconds in case of errors
                    setTimeout(() => {
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }, 5000);
                }
            });
        });

        // Add loading animation to buttons
        document.querySelectorAll('.btn').forEach(btn => {
            btn.addEventListener('click', function() {
                if (this.type === 'submit' || this.href) {
                    this.style.opacity = '0.7';
                    setTimeout(() => {
                        this.style.opacity = '1';
                    }, 300);
                }
            });
        });

        // Animate numbers on page load
        document.addEventListener('DOMContentLoaded', function() {
            const numbers = document.querySelectorAll('.stat-card .number');
            numbers.forEach(number => {
                const finalValue = parseInt(number.textContent);
                let currentValue = 0;
                const increment = finalValue / 30;
                
                const timer = setInterval(() => {
                    currentValue += increment;
                    if (currentValue >= finalValue) {
                        number.textContent = finalValue;
                        clearInterval(timer);
                    } else {
                        number.textContent = Math.floor(currentValue);
                    }
                }, 50);
            });
        });

        // Auto-hide alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                setTimeout(function() {
                    alert.style.opacity = '0';
                    alert.style.transform = 'translateY(-20px)';
                    setTimeout(function() {
                        alert.remove();
                    }, 300);
                }, 5000);
            });
        });

        // Add keyboard navigation support
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const sidebar = document.getElementById('sidebar');
                sidebar.classList.remove('open');
            }
        });

        // Prevent double submission
        let formSubmitted = false;
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                if (formSubmitted) {
                    e.preventDefault();
                    return false;
                }
                formSubmitted = true;
                
                // Reset after 3 seconds
                setTimeout(() => {
                    formSubmitted = false;
                }, 3000);
            });
        });

        // Enhanced support for ongoing event registration
        document.addEventListener('DOMContentLoaded', function() {
            // Add special styling for ongoing events
            const ongoingCards = document.querySelectorAll('.event-card.ongoing');
            ongoingCards.forEach(card => {
                // Add pulsing animation to ongoing events
                card.style.animation = 'pulse 2s ease-in-out infinite';
            });
            
            // Add confirmation for ongoing event registration
            const ongoingRegistrationLinks = document.querySelectorAll('.event-card.ongoing a[href*="health_questionnaire"]');
            ongoingRegistrationLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    const confirmation = confirm('This event is currently in progress. Are you sure you want to register for late entry?');
                    if (!confirmation) {
                        e.preventDefault();
                        return false;
                    }
                });
            });
        });

        // Add CSS animation for pulsing effect
        const style = document.createElement('style');
        style.textContent = `
            @keyframes pulse {
                0% { box-shadow: 0 10px 30px rgba(254, 202, 87, 0.1); }
                50% { box-shadow: 0 15px 40px rgba(254, 202, 87, 0.3); }
                100% { box-shadow: 0 10px 30px rgba(254, 202, 87, 0.1); }
            }
        `;
        document.head.appendChild(style);

        // Auto-refresh page every 5 minutes to update event statuses
        setInterval(function() {
            // Only refresh if no forms are being submitted
            if (!formSubmitted) {
                console.log('Auto-refreshing to update event statuses...');
                window.location.reload();
            }
        }, 300000); // 5 minutes

        // Display current time in debug panel
        function updateDebugTime() {
            const debugPanel = document.querySelector('.debug-panel');
            if (debugPanel) {
                const timeElement = debugPanel.querySelector('p:nth-child(3)');
                if (timeElement) {
                    const now = new Date();
                    const timeString = now.toLocaleString('en-US', {
                        year: 'numeric',
                        month: '2-digit',
                        day: '2-digit',
                        hour: '2-digit',
                        minute: '2-digit',
                        second: '2-digit',
                        hour12: false
                    });
                    timeElement.innerHTML = '<strong>Current Time:</strong> ' + timeString;
                }
            }
        }

        // Update debug time every second
        setInterval(updateDebugTime, 1000);

        // Add visual indicator for auto-updates
        let updateIndicator = null;
        
        function showUpdateIndicator() {
            if (!updateIndicator) {
                updateIndicator = document.createElement('div');
                updateIndicator.style.cssText = `
                    position: fixed;
                    top: 50%;
                    left: 50%;
                    transform: translate(-50%, -50%);
                    background: linear-gradient(135deg, #667eea, #764ba2);
                    color: white;
                    padding: 20px 30px;
                    border-radius: 15px;
                    z-index: 10000;
                    font-weight: 600;
                    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
                    backdrop-filter: blur(10px);
                `;
                updateIndicator.innerHTML = '<i class="fas fa-sync fa-spin"></i> Updating event statuses...';
                document.body.appendChild(updateIndicator);
            }
        }

        function hideUpdateIndicator() {
            if (updateIndicator) {
                updateIndicator.remove();
                updateIndicator = null;
            }
        }

        // Show update indicator before refresh
        setInterval(function() {
            if (!formSubmitted) {
                showUpdateIndicator();
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            }
        }, 300000); // 5 minutes

        // Hide loading indicator on page load
        window.addEventListener('load', function() {
            hideUpdateIndicator();
        });
    </script>
</body>
</html>