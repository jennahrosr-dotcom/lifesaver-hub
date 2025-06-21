<?php
session_start();
$pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

// Auto-update event status to 'Completed' if date is past and not already 'Deleted' or 'Completed'
$today = date('Y-m-d');
$updateStmt = $pdo->prepare("UPDATE event SET EventStatus = 'Completed' WHERE EventDate < ? AND EventStatus NOT IN ('Completed', 'Deleted')");
$updateStmt->execute([$today]);

$isStaff = isset($_SESSION['staff_id']);
$isStudent = isset($_SESSION['student_id']);

if (!$isStaff && !$isStudent) {
    header("Location: index.php");
    exit;
}

$studentId = $isStudent ? $_SESSION['student_id'] : null;

// Handle student registration/unregistration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isStudent) {
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
$where = [];
$params = [];

// For students, don't show deleted events
if ($isStudent) {
    $where[] = "EventStatus != 'Deleted'";
}

if (!empty($_GET['filter_date'])) {
    $where[] = "EventDate = ?";
    $params[] = $_GET['filter_date'];
}
if (!empty($_GET['filter_status'])) {
    $where[] = "EventStatus = ?";
    $params[] = $_GET['filter_status'];
}

// Different queries for staff vs students
if ($isStudent) {
    // For students, include registration info
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
    // For staff, simple event query
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

        @keyframes shimmerMove {
            0%, 100% { transform: translateX(-100px) translateY(-100px); }
            50% { transform: translateX(100px) translateY(100px); }
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
        .stat-card.upcoming { --accent-color: #00d2d3; }
        .stat-card.ongoing { --accent-color: #f093fb; }
        .stat-card.completed { --accent-color: #764ba2; }

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
            background: linear-gradient(135deg, #f093fb, #00d2d3);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        /* Staff Table View */
        .events-table {
            width: 100%;
            border-collapse: collapse;
        }

        .events-table th,
        .events-table td {
            padding: 20px 25px;
            text-align: left;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .events-table th {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.2), rgba(255, 255, 255, 0.1));
            color: white;
            font-weight: 700;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 1px;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .events-table th i {
            margin-right: 8px;
            opacity: 0.8;
        }

        .events-table tr {
            transition: all 0.3s ease;
        }

        .events-table tr:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: scale(1.01);
        }

        .events-table td {
            color: white;
            font-weight: 500;
        }

        .table-actions {
            display: flex;
            gap: 10px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
        }

        .badge.upcoming { 
            background: linear-gradient(135deg, #00d2d3, #667eea); 
            color: white; 
        }
        .badge.today { 
            background: linear-gradient(135deg, #f093fb, #ff9f43); 
            color: white; 
            animation: pulse 2s ease-in-out infinite;
        }
        .badge.past { 
            background: linear-gradient(135deg, #9c88ff, #8c7ae6); 
            color: white; 
        }
        .badge.ongoing { 
            background: linear-gradient(135deg, #f093fb, #feca57); 
            color: white; 
        }
        .badge.completed { 
            background: linear-gradient(135deg, #764ba2, #667eea); 
            color: white; 
        }
        .badge.deleted { 
            background: linear-gradient(135deg, #ff6b6b, #ee5a24); 
            color: white; 
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
            background: linear-gradient(135deg, #f093fb, #00d2d3);
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
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .mobile-menu-btn {
                display: block;
            }
            .filters-form {
                flex-direction: column;
                align-items: stretch;
            }
            .page-header h1 {
                font-size: 2rem;
                flex-direction: column;
                gap: 10px;
            }
            .events-table {
                font-size: 14px;
            }
            .events-table th,
            .events-table td {
                padding: 15px 10px;
            }
            .table-actions {
                flex-direction: column;
                gap: 5px;
            }
            .table-actions .btn {
                padding: 8px 16px;
                font-size: 12px;
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
            <p><?= $isStudent ? 'Student Portal' : 'Staff Portal' ?></p>
        </div>
        
        <nav class="sidebar-nav">
            <?php if ($isStaff): ?>
                <a href="staff_account.php"><i class="fas fa-user-tie"></i> My Account</a>
                <a href="create_event.php"><i class="fas fa-calendar-plus"></i> Create Event</a>
                <a href="view_event.php" class="active"><i class="fas fa-calendar-check"></i> View Events</a>
                <a href="view_donation.php"><i class="fas fa-hand-holding-heart"></i> View Donations</a>
                <a href="confirm_attendance.php"><i class="fas fa-user-check"></i> Confirm Attendance</a>
                <a href="update_application.php"><i class="fas fa-sync-alt"></i> Update Application</a>
                <a href="create_reward.php"><i class="fas fa-gift"></i> Create Rewards</a>
                <a href="generate_report.php"><i class="fas fa-chart-line"></i> Generate Report</a>
                <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
            <?php elseif ($isStudent): ?>
                <a href="student_account.php"><i class="fas fa-user-graduate"></i> My Account</a>
                <a href="view_event.php" class="active"><i class="fas fa-calendar-heart"></i> View Events</a>
                <a href="notifications.php"><i class="fas fa-bell"></i> Notifications</a>
                <a href="view_donation.php"><i class="fas fa-tint"></i> View Donation</a>
                <a href="donation_history.php"><i class="fas fa-history"></i> Donation History</a>
                <a href="view_rewards.php"><i class="fas fa-trophy"></i> My Rewards</a>
                <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
            <?php endif; ?>
        </nav>
    </div>

    <div class="main-content">
        <div class="page-header">
            <h1>
                <i class="fas fa-<?= $isStudent ? 'calendar-heart' : 'calendar-check' ?>"></i>
                <?= $isStudent ? 'Blood Donation Events' : 'Event Management' ?>
            </h1>
            <p><?= $isStudent ? 'Find and register for upcoming blood donation events' : 'Manage and monitor all blood donation events' ?></p>
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

        <?php if ($isStudent && isset($success)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($isStudent && isset($error)): ?>
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
                        <?php if ($isStaff): ?>
                            <option value="Deleted" <?= ($_GET['filter_status'] ?? '') === 'Deleted' ? 'selected' : '' ?>>Deleted</option>
                        <?php endif; ?>
                    </select>
                </div>
                
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                    <a href="view_event.php" class="btn btn-outline">
                        <i class="fas fa-times"></i> Clear
                    </a>
                </div>
            </form>
        </div>

        <div class="events-container">
            <div class="events-header">
                <h3>
                    <i class="fas fa-list"></i>
                    <?= $isStudent ? 'Available Events' : 'All Events' ?>
                    <span style="color: rgba(255, 255, 255, 0.7); font-weight: 400; font-size: 1rem;">(<?= count($events) ?> events found)</span>
                </h3>
            </div>

            <?php if ($isStaff): ?>
                <!-- Staff Table View -->
                <table class="events-table">
                    <thead>
                        <tr>
                            <th><i class="fas fa-hashtag"></i> ID</th>
                            <th><i class="fas fa-tag"></i> Title</th>
                            <th><i class="fas fa-align-left"></i> Description</th>
                            <th><i class="fas fa-calendar"></i> Date</th>
                            <th><i class="fas fa-map-marker-alt"></i> Venue</th>
                            <th><i class="fas fa-users"></i> Registered</th>
                            <th><i class="fas fa-info-circle"></i> Status</th>
                            <th><i class="fas fa-cogs"></i> Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($events): ?>
                            <?php foreach ($events as $event): 
                                $eventDate = $event['EventDate'];
                                $statusBadge = '';

                                if ($event['EventStatus'] === 'Deleted') {
                                    $statusBadge = '<span class="badge deleted">Deleted</span>';
                                } elseif ($eventDate < $today) {
                                    $statusBadge = '<span class="badge past">Past</span>';
                                } elseif ($eventDate === $today) {
                                    $statusBadge = '<span class="badge today"><i class="fas fa-bullseye"></i> Today</span>';
                                } else {
                                    $statusBadge = '<span class="badge upcoming">Upcoming</span>';
                                }
                            ?>
                                <tr>
                                    <td>#<?= $event['EventID'] ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($event['EventTitle']) ?></strong>
                                        <br><?= $statusBadge ?>
                                    </td>
                                    <td style="max-width: 200px;"><?= htmlspecialchars(substr($event['EventDescription'], 0, 100)) ?><?= strlen($event['EventDescription']) > 100 ? '...' : '' ?></td>
                                    <td>
                                        <?= date('M j, Y', strtotime($event['EventDate'])) ?><br>
                                        <small style="color: rgba(255, 255, 255, 0.7);"><?= $event['EventDay'] ?></small>
                                    </td>
                                    <td><?= htmlspecialchars($event['EventVenue']) ?></td>
                                    <td>
                                        <span style="font-weight: 600; color: #10ac84;">
                                            <i class="fas fa-users"></i> <?= $event['TotalRegistered'] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?= strtolower($event['EventStatus']) ?>">
                                            <?= htmlspecialchars($event['EventStatus']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="table-actions">
                                            <a href="update_event.php?id=<?= $event['EventID'] ?>" class="btn btn-warning" style="padding: 10px 16px; font-size: 13px; border-radius: 8px;">
                                                <i class="fas fa-edit"></i> Update
                                            </a>
                                            <a href="delete_event.php?id=<?= $event['EventID'] ?>" class="btn btn-danger" style="padding: 10px 16px; font-size: 13px; border-radius: 8px;" onclick="return confirm('Are you sure you want to delete this event? This action cannot be undone.')">
                                                <i class="fas fa-trash-alt"></i> Delete
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="empty-state">
                                    <i class="fas fa-calendar-times"></i>
                                    <h3>No Events Found</h3>
                                    <p>No events match your current filters.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            <?php endif; ?>
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
    </script>
</body>
</html>