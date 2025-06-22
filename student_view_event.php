<?php
session_start();

if (!isset($_SESSION['student_id'])) {
    header("Location: index.php");
    exit;
}

$pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$studentId = $_SESSION['student_id'];

// Get student information
$stmt = $pdo->prepare("SELECT * FROM student WHERE StudentID = ?");
$stmt->execute([$studentId]);
$student = $stmt->fetch();

// Auto-update event status to 'Completed' if date is past and not already 'Deleted' or 'Completed'
$today = date('Y-m-d');
$updateStmt = $pdo->prepare("UPDATE event SET EventStatus = 'Completed' WHERE EventDate < ? AND EventStatus NOT IN ('Completed', 'Deleted')");
$updateStmt->execute([$today]);

// Handle event registration
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $eventId = $_POST['event_id'];
    $action = $_POST['action'];
    
    if ($action === 'register') {
        // Check if already registered
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM registration WHERE StudentID = ? AND EventID = ? AND RegistrationStatus != 'Cancelled'");
        $checkStmt->execute([$studentId, $eventId]);
        
        if ($checkStmt->fetchColumn() == 0) {
            // Register for event
            $registerStmt = $pdo->prepare("INSERT INTO registration (StudentID, EventID, RegistrationDate, RegistrationStatus, AttendanceStatus) VALUES (?, ?, NOW(), 'Confirmed', 'Pending')");
            $registerStmt->execute([$studentId, $eventId]);
            $success = "Successfully registered for the event!";
        } else {
            $error = "You are already registered for this event.";
        }
    } elseif ($action === 'unregister') {
        // Unregister from event
        $unregisterStmt = $pdo->prepare("UPDATE registration SET RegistrationStatus = 'Cancelled' WHERE StudentID = ? AND EventID = ?");
        $unregisterStmt->execute([$studentId, $eventId]);
        $success = "Successfully unregistered from the event.";
    }
}

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

// Get events with registration info - FIXED QUERY
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
$sql .= " ORDER BY EventDate DESC";

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
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
                radial-gradient(circle at 20% 80%, rgba(120, 119, 198, 0.3) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(255, 107, 107, 0.3) 0%, transparent 50%),
                radial-gradient(circle at 40% 40%, rgba(255, 183, 77, 0.2) 0%, transparent 50%);
            z-index: -1;
            animation: float 20s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            33% { transform: translateY(-20px) rotate(1deg); }
            66% { transform: translateY(-10px) rotate(-1deg); }
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 280px;
            height: 100vh;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(25px);
            border-right: 1px solid rgba(255, 255, 255, 0.2);
            padding: 30px 0 20px 0;
            z-index: 1000;
            box-shadow: 5px 0 25px rgba(0, 0, 0, 0.1);
            border-radius: 0 25px 25px 0;
            overflow-y: auto;
            overflow-x: hidden;
        }

        .sidebar::-webkit-scrollbar {
            width: 6px;
        }

        .sidebar::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 3px;
        }

        .sidebar::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.3);
            border-radius: 3px;
        }

        .sidebar::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.5);
        }

        .sidebar-header {
            text-align: center;
            margin-bottom: 40px;
            padding: 0 20px;
            position: relative;
        }

        .sidebar-header::before {
            content: '🩸';
            font-size: 3rem;
            display: block;
            margin-bottom: 10px;
            animation: pulse 2s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }

        .sidebar-header h2 {
            color: white;
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 5px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        .sidebar-header p {
            color: rgba(255, 255, 255, 0.8);
            font-size: 14px;
            font-weight: 500;
        }

        .sidebar-nav {
            padding: 0 15px;
        }

        .sidebar a {
            display: flex;
            align-items: center;
            color: rgba(255, 255, 255, 0.9);
            padding: 15px 20px;
            text-decoration: none;
            font-size: 15px;
            font-weight: 500;
            border-radius: 15px;
            margin: 8px 0;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative;
            overflow: hidden;
        }

        .sidebar a::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, 
                rgba(255, 255, 255, 0.1) 0%, 
                rgba(255, 255, 255, 0.3) 50%, 
                rgba(255, 255, 255, 0.1) 100%);
            transition: left 0.5s;
        }

        .sidebar a:hover::before {
            left: 100%;
        }

        .sidebar a:hover {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            transform: translateX(10px) scale(1.05);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        .sidebar a.active {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.3), rgba(255, 255, 255, 0.1));
            color: white;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .sidebar a i {
            width: 20px;
            margin-right: 15px;
            text-align: center;
            font-size: 16px;
        }

        .main-content {
            margin-left: 280px;
            padding: 30px;
            min-height: 100vh;
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

        .page-header h1 {
            font-size: 3rem;
            font-weight: 800;
            color: white;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 20px;
            position: relative;
            z-index: 1;
            text-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
        }

        .page-header h1 i {
            background: linear-gradient(135deg, #ff6b6b, #feca57);
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
            position: relative;
            z-index: 1;
            font-weight: 500;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 25px;
            margin-bottom: 35px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(25px);
            border-radius: 20px;
            padding: 30px 25px;
            text-align: center;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
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
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.2);
            background: rgba(255, 255, 255, 0.25);
        }

        .stat-card:hover::before {
            height: 5px;
            background: linear-gradient(90deg, var(--accent-color), #feca57, var(--accent-color));
        }

        .stat-card .icon {
            font-size: 3rem;
            margin-bottom: 20px;
            color: white;
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.3));
            animation: float 3s ease-in-out infinite;
        }

        .stat-card .number {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 10px;
            color: white;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
            animation: countUp 1s ease-out;
        }

        @keyframes countUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .stat-card .label {
            color: rgba(255, 255, 255, 0.9);
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
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(25px);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 35px;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
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
            color: white;
            font-size: 14px;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
        }

        .filter-group input,
        .filter-group select {
            padding: 15px 20px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 15px;
            font-size: 14px;
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
            min-width: 180px;
            color: white;
            font-weight: 500;
        }

        .filter-group input::placeholder,
        .filter-group select option {
            color: rgba(0, 0, 0, 0.7);
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: rgba(255, 255, 255, 0.6);
            background: rgba(255, 255, 255, 0.3);
            box-shadow: 0 0 0 4px rgba(255, 255, 255, 0.1);
            transform: scale(1.05);
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
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
        }

        .btn-primary:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 15px 35px rgba(102, 126, 234, 0.6);
        }

        .btn-outline {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 2px solid rgba(255, 255, 255, 0.4);
        }

        .btn-outline:hover {
            background: rgba(255, 255, 255, 0.3);
            border-color: rgba(255, 255, 255, 0.6);
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
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(25px);
            border-radius: 25px;
            box-shadow: 0 15px 50px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
            overflow: hidden;
        }

        .events-header {
            padding: 30px 35px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.05));
        }

        .events-header h3 {
            font-size: 1.8rem;
            font-weight: 700;
            color: white;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 15px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        .events-header h3 i {
            background: linear-gradient(135deg, #feca57, #ff9f43);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
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
            border: 1px solid rgba(255, 255, 255, 0.2);
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
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.2);
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

        .event-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 25px;
        }

        .event-title {
            font-size: 1.4rem;
            font-weight: 800;
            color: #2c3e50;
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
            color: #495057;
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
            color: #495057;
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
            color: rgba(255, 255, 255, 0.8);
        }

        .empty-state i {
            font-size: 5rem;
            margin-bottom: 25px;
            opacity: 0.4;
            background: linear-gradient(135deg, #feca57, #ff9f43);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .empty-state h3 {
            font-size: 1.8rem;
            margin-bottom: 15px;
            color: white;
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
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            border: none;
            padding: 12px;
            border-radius: 15px;
            color: white;
            font-size: 18px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .sidebar {
                width: 250px;
            }
            .main-content {
                margin-left: 250px;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }
            .sidebar.mobile-open {
                transform: translateX(0);
            }
            .main-content {
                margin-left: 0;
                padding: 20px;
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
            .mobile-menu-btn {
                display: block;
            }
        }
    </style>
</head>
<body>
    <button class="mobile-menu-btn" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>

    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h2>LifeSaver Hub</h2>
            <p>Student Portal</p>
        </div>
        
        <nav class="sidebar-nav">
            <a href="student_account.php"><i class="fas fa-user-graduate"></i> My Account</a>
            <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="student_view_event.php" class="active"><i class="fas fa-calendar-heart"></i> View Events</a>
            <a href="notifications.php"><i class="fas fa-bell"></i> Notifications</a>
            <a href="student_view_donation.php"><i class="fas fa-tint"></i> View Donation</a>
            <a href="donation_history.php"><i class="fas fa-history"></i> Donation History</a>
            <a href="view_reward.php"><i class="fas fa-trophy"></i> My Rewards</a>
            <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </nav>
    </div>

    <div class="main-content">
        <div class="page-header">
            <h1>
                <i class="fas fa-calendar-heart"></i>
                Blood Donation Events
            </h1>
            <p>Find and register for upcoming blood donation events to save lives</p>
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
                    <span style="color: rgba(255, 255, 255, 0.7); font-weight: 400; font-size: 1rem;">(<?= count($events) ?> events found)</span>
                </h3>
            </div>

            <!-- Student Card View -->
            <div class="events-grid">
                <?php if ($events): ?>
                    <?php foreach ($events as $event): 
                        $isRegistered = $event['IsRegistered'] > 0;
                        $hasDonated = $event['HasDonated'] > 0;
                        $isPast = strtotime($event['EventDate']) < strtotime($today);
                        $canRegister = !$isPast && $event['EventStatus'] === 'Upcoming';
                        $canApplyDonation = $isRegistered && !$hasDonated && in_array($event['EventStatus'], ['Upcoming', 'Ongoing']);
                    ?>
                        <div class="event-card <?= $isRegistered ? 'registered' : '' ?> <?= $isPast ? 'past' : '' ?>">
                            <div class="event-header">
                                <h3 class="event-title"><?= htmlspecialchars($event['EventTitle']) ?></h3>
                                <span class="event-status status-<?= strtolower($event['EventStatus']) ?>">
                                    <?= htmlspecialchars($event['EventStatus']) ?>
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
                                <?php if ($isRegistered): ?>
                                    <span class="btn btn-success">
                                        <i class="fas fa-check"></i> Registered
                                    </span>
                                    
                                    <?php if ($canApplyDonation): ?>
                                        <a href="health_questionnaire.php?event_id=<?= $event['EventID'] ?>" class="btn btn-warning">
                                            <i class="fas fa-heart"></i> Apply Donation
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php if ($canRegister): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="event_id" value="<?= $event['EventID'] ?>">
                                            <input type="hidden" name="action" value="unregister">
                                            <button type="submit" class="btn btn-danger" onclick="return confirm('Are you sure you want to unregister?')">
                                                <i class="fas fa-times"></i> Unregister
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    
                                <?php elseif ($canRegister): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="event_id" value="<?= $event['EventID'] ?>">
                                        <input type="hidden" name="action" value="register">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-hand-holding-heart"></i> Register Now
                                        </button>
                                    </form>
                                <?php elseif ($isPast): ?>
                                    <span class="btn btn-secondary">
                                        <i class="fas fa-clock"></i> Event Ended
                                    </span>
                                <?php else: ?>
                                    <span class="btn btn-secondary">
                                        <i class="fas fa-lock"></i> Registration Closed
                                    </span>
                                <?php endif; ?>
                            </div>

                            <?php if ($isRegistered): ?>
                                <div class="registration-info">
                                    <i class="fas fa-info-circle"></i> 
                                    You are registered for this event. Please arrive on time and bring a valid ID.
                                </div>
                            <?php endif; ?>

                            <?php if ($hasDonated): ?>
                                <div class="donation-info">
                                    <i class="fas fa-heart"></i> 
                                    Thank you! You have already donated blood for this event.
                                </div>
                            <?php endif; ?>
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
    </div>

    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('mobile-open');
        }

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const menuBtn = document.querySelector('.mobile-menu-btn');
            
            if (window.innerWidth <= 768 && 
                !sidebar.contains(event.target) && 
                !menuBtn.contains(event.target)) {
                sidebar.classList.remove('mobile-open');
            }
        });

        // Auto-hide mobile menu on resize
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth > 768) {
                sidebar.classList.remove('mobile-open');
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
                if (submitBtn) {
                    const originalText = submitBtn.innerHTML;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                    submitBtn.disabled = true;
                    
                    // Re-enable after 3 seconds in case of errors
                    setTimeout(() => {
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }, 3000);
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

        // Add confirmation dialogs for important actions
        document.querySelectorAll('form').forEach(form => {
            const action = form.querySelector('input[name="action"]');
            if (action && action.value === 'unregister') {
                form.addEventListener('submit', function(e) {
                    if (!confirm('Are you sure you want to unregister from this event? You can register again later if spots are available.')) {
                        e.preventDefault();
                    }
                });
            }
        });

        // Add tooltip-like behavior for status badges
        document.querySelectorAll('.event-status').forEach(status => {
            status.addEventListener('mouseenter', function() {
                this.style.transform = 'scale(1.1)';
            });
            
            status.addEventListener('mouseleave', function() {
                this.style.transform = 'scale(1)';
            });
        });

        // Auto-hide alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                setTimeout(function() {
                    alert.style.opacity = '0';
                    setTimeout(function() {
                        alert.remove();
                    }, 300);
                }, 5000);
            });
        });
    </script>
</body>
</html>