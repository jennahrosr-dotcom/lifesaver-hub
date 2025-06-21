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

// Handle mark as read action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        if ($_POST['action'] === 'mark_read' && isset($_POST['notification_id'])) {
            $notificationId = (int)$_POST['notification_id'];
            $updateStmt = $pdo->prepare("
                UPDATE notification 
                SET IsRead = 1, ReadDate = NOW() 
                WHERE NotificationID = ? AND DonorID = ?
            ");
            $updateStmt->execute([$notificationId, $donorId]);
            
            echo json_encode(['success' => true]);
            exit;
        } elseif ($_POST['action'] === 'mark_all_read') {
            $updateAllStmt = $pdo->prepare("
                UPDATE notification 
                SET IsRead = 1, ReadDate = NOW() 
                WHERE DonorID = ? AND IsRead = 0
            ");
            $updateAllStmt->execute([$donorId]);
            
            echo json_encode(['success' => true]);
            exit;
        } elseif ($_POST['action'] === 'delete_notification' && isset($_POST['notification_id'])) {
            $notificationId = (int)$_POST['notification_id'];
            $deleteStmt = $pdo->prepare("
                DELETE FROM notification 
                WHERE NotificationID = ? AND DonorID = ?
            ");
            $deleteStmt->execute([$notificationId, $donorId]);
            
            echo json_encode(['success' => true]);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Get filter parameters
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Build WHERE clause based on filter
$whereClause = "WHERE DonorID = ?";
$params = [$donorId];

switch ($filter) {
    case 'unread':
        $whereClause .= " AND IsRead = 0";
        break;
    case 'read':
        $whereClause .= " AND IsRead = 1";
        break;
    case 'event':
        $whereClause .= " AND NotificationType = 'Event'";
        break;
    case 'registration':
        $whereClause .= " AND NotificationType = 'Registration'";
        break;
    case 'system':
        $whereClause .= " AND NotificationType = 'System'";
        break;
    // 'all' - no additional filter
}

// Get total count for pagination
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM notification $whereClause");
$countStmt->execute($params);
$totalNotifications = $countStmt->fetchColumn();
$totalPages = ceil($totalNotifications / $limit);

// Get notifications with pagination
$stmt = $pdo->prepare("
    SELECT n.*, e.EventTitle 
    FROM notification n 
    LEFT JOIN event e ON n.EventID = e.EventID 
    $whereClause 
    ORDER BY n.CreatedDate DESC, n.NotificationID DESC 
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$notifications = $stmt->fetchAll();

// Get notification counts for filter badges
$countsStmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN IsRead = 0 THEN 1 ELSE 0 END) as unread,
        SUM(CASE WHEN IsRead = 1 THEN 1 ELSE 0 END) as read,
        SUM(CASE WHEN NotificationType = 'Event' THEN 1 ELSE 0 END) as event,
        SUM(CASE WHEN NotificationType = 'Registration' THEN 1 ELSE 0 END) as registration,
        SUM(CASE WHEN NotificationType = 'System' THEN 1 ELSE 0 END) as system
    FROM notification 
    WHERE DonorID = ?
");
$countsStmt->execute([$donorId]);
$counts = $countsStmt->fetch();

// Function to get notification icon
function getNotificationIcon($type) {
    switch ($type) {
        case 'Event':
            return 'fas fa-calendar-alt';
        case 'Registration':
            return 'fas fa-user-check';
        case 'System':
            return 'fas fa-cog';
        default:
            return 'fas fa-bell';
    }
}

// Function to get notification color
function getNotificationColor($type) {
    switch ($type) {
        case 'Event':
            return 'event';
        case 'Registration':
            return 'registration';
        case 'System':
            return 'system';
        default:
            return 'default';
    }
}

// Function to format time ago
function timeAgo($datetime) {
    $time = time() - strtotime($datetime);
    
    if ($time < 60) return 'Just now';
    if ($time < 3600) return floor($time/60) . ' minutes ago';
    if ($time < 86400) return floor($time/3600) . ' hours ago';
    if ($time < 2592000) return floor($time/86400) . ' days ago';
    if ($time < 31536000) return floor($time/2592000) . ' months ago';
    return floor($time/31536000) . ' years ago';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - LifeSaver Hub</title>
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

        .navbar-nav {
            display: flex;
            align-items: center;
            gap: 30px;
        }

        .nav-link {
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            font-weight: 500;
            padding: 10px 20px;
            border-radius: 25px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .nav-link:hover, .nav-link.active {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            transform: translateY(-2px);
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
            max-width: 1200px;
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .page-header h1 {
            font-size: 3rem;
            font-weight: 800;
            color: white;
            display: flex;
            align-items: center;
            gap: 20px;
            text-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
            margin: 0;
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
            margin-top: 10px;
        }

        .notification-actions {
            display: flex;
            gap: 15px;
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

        .filter-tabs {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(25px);
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .filter-tabs-list {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
        }

        .filter-tab {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 25px;
            background: rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            border-radius: 25px;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }

        .filter-tab:hover {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            transform: translateY(-2px);
        }

        .filter-tab.active {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.3), rgba(255, 255, 255, 0.1));
            color: white;
            border-color: rgba(255, 255, 255, 0.3);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .filter-badge {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 700;
            min-width: 20px;
            text-align: center;
        }

        .notifications-container {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(25px);
            border-radius: 25px;
            box-shadow: 0 15px 50px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
            overflow: hidden;
        }

        .notifications-header {
            padding: 30px 35px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.05));
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .notifications-header h3 {
            font-size: 1.8rem;
            font-weight: 700;
            color: white;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 15px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        .notifications-header h3 i {
            background: linear-gradient(135deg, #f093fb, #00d2d3);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .notification-item {
            padding: 25px 35px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            transition: all 0.3s ease;
            position: relative;
            display: flex;
            align-items: flex-start;
            gap: 20px;
        }

        .notification-item:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .notification-item:last-child {
            border-bottom: none;
        }

        .notification-item.unread {
            background: rgba(255, 255, 255, 0.08);
            border-left: 4px solid #00d2d3;
        }

        .notification-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
            flex-shrink: 0;
        }

        .notification-icon.event {
            background: linear-gradient(135deg, #667eea, #764ba2);
        }

        .notification-icon.registration {
            background: linear-gradient(135deg, #10ac84, #00d2d3);
        }

        .notification-icon.system {
            background: linear-gradient(135deg, #feca57, #ff9f43);
        }

        .notification-icon.default {
            background: linear-gradient(135deg, #f093fb, #ff6b6b);
        }

        .notification-content {
            flex: 1;
        }

        .notification-title {
            font-size: 16px;
            font-weight: 700;
            color: white;
            margin-bottom: 8px;
            line-height: 1.4;
        }

        .notification-message {
            color: rgba(255, 255, 255, 0.8);
            font-size: 14px;
            line-height: 1.5;
            margin-bottom: 10px;
        }

        .notification-meta {
            display: flex;
            align-items: center;
            gap: 15px;
            font-size: 12px;
            color: rgba(255, 255, 255, 0.6);
        }

        .notification-time {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .notification-type {
            display: flex;
            align-items: center;
            gap: 5px;
            background: rgba(255, 255, 255, 0.1);
            padding: 4px 8px;
            border-radius: 12px;
        }

        .notification-actions-menu {
            display: flex;
            gap: 10px;
        }

        .action-btn {
            background: none;
            border: none;
            color: rgba(255, 255, 255, 0.6);
            cursor: pointer;
            padding: 8px;
            border-radius: 50%;
            transition: all 0.3s ease;
            font-size: 14px;
        }

        .action-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            transform: scale(1.1);
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

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            padding: 30px;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.05));
        }

        .pagination a {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            border-radius: 50%;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .pagination a:hover, .pagination a.active {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            transform: scale(1.1);
        }

        .pagination a.active {
            background: linear-gradient(135deg, #667eea, #764ba2);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }

        .loading {
            text-align: center;
            padding: 40px;
            color: rgba(255, 255, 255, 0.6);
        }

        .loading i {
            animation: spin 1s linear infinite;
            font-size: 2rem;
            margin-bottom: 15px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
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
            
            .navbar-nav {
                gap: 15px;
            }
            
            .page-header {
                padding: 30px 20px;
            }
            
            .page-header h1 {
                font-size: 2rem;
            }
            
            .filter-tabs-list {
                flex-direction: column;
            }
            
            .notification-item {
                padding: 20px;
                flex-direction: column;
                text-align: center;
            }
            
            .notification-actions {
                flex-direction: column;
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar">
        <div class="navbar-content">
            <a href="donor_dashboard.php" class="navbar-brand">LifeSaver Hub</a>
            <div class="navbar-nav">
                <a href="donor_dashboard.php" class="nav-link">
                    <i class="fas fa-home"></i>
                    Dashboard
                </a>
                <a href="events.php" class="nav-link">
                    <i class="fas fa-calendar-alt"></i>
                    Events
                </a>
                <a href="notifications.php" class="nav-link active">
                    <i class="fas fa-bell"></i>
                    Notifications
                    <?php if ($counts['unread'] > 0): ?>
                        <span class="filter-badge"><?php echo $counts['unread']; ?></span>
                    <?php endif; ?>
                </a>
                <a href="donor_profile.php" class="nav-link">
                    <i class="fas fa-user"></i>
                    Profile
                </a>
                <a href="donor_logout.php" class="nav-link">
                    <i class="fas fa-sign-out-alt"></i>
                    Logout
                </a>
            </div>
            <div class="user-info">
                <i class="fas fa-user-circle"></i>
                <span><?php echo htmlspecialchars($donor['DonorName']); ?></span>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container">
        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-content">
                <div>
                    <h1>
                        <i class="fas fa-bell"></i>
                        Notifications
                    </h1>
                    <p>Stay updated with your blood donation activities and events</p>
                </div>
                <div class="notification-actions">
                    <?php if ($counts['unread'] > 0): ?>
                        <button onclick="markAllAsRead()" class="btn btn-primary">
                            <i class="fas fa-check-double"></i>
                            Mark All Read
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Filter Tabs -->
        <div class="filter-tabs">
            <div class="filter-tabs-list">
                <a href="?filter=all" class="filter-tab <?php echo $filter === 'all' ? 'active' : ''; ?>">
                    <i class="fas fa-list"></i>
                    All Notifications
                    <span class="filter-badge"><?php echo $counts['total']; ?></span>
                </a>
                <a href="?filter=unread" class="filter-tab <?php echo $filter === 'unread' ? 'active' : ''; ?>">
                    <i class="fas fa-envelope"></i>
                    Unread
                    <span class="filter-badge"><?php echo $counts['unread']; ?></span>
                </a>
                <a href="?filter=read" class="filter-tab <?php echo $filter === 'read' ? 'active' : ''; ?>">
                    <i class="fas fa-envelope-open"></i>
                    Read
                    <span class="filter-badge"><?php echo $counts['read']; ?></span>
                </a>
                <a href="?filter=event" class="filter-tab <?php echo $filter === 'event' ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-alt"></i>
                    Events
                    <span class="filter-badge"><?php echo $counts['event']; ?></span>
                </a>
                <a href="?filter=registration" class="filter-tab <?php echo $filter === 'registration' ? 'active' : ''; ?>">
                    <i class="fas fa-user-check"></i>
                    Registrations
                    <span class="filter-badge"><?php echo $counts['registration']; ?></span>
                </a>
                <a href="?filter=system" class="filter-tab <?php echo $filter === 'system' ? 'active' : ''; ?>">
                    <i class="fas fa-cog"></i>
                    System
                    <span class="filter-badge"><?php echo $counts['system']; ?></span>
                </a>
            </div>
        </div>

        <!-- Notifications Container -->
        <div class="notifications-container">
            <div class="notifications-header">
                <h3>
                    <i class="fas fa-inbox"></i>
                    <?php 
                    $filterTitles = [
                        'all' => 'All Notifications',
                        'unread' => 'Unread Notifications',
                        'read' => 'Read Notifications',
                        'event' => 'Event Notifications',
                        'registration' => 'Registration Notifications',
                        'system' => 'System Notifications'
                    ];
                    echo $filterTitles[$filter] ?? 'Notifications';
                    ?>
                </h3>
                <span class="filter-badge"><?php echo count($notifications); ?> of <?php echo $totalNotifications; ?></span>
            </div>

            <?php if (count($notifications) > 0): ?>
                <?php foreach ($notifications as $notification): ?>
                    <div class="notification-item <?php echo $notification['IsRead'] ? '' : 'unread'; ?>" 
                         data-notification-id="<?php echo $notification['NotificationID']; ?>">
                        <div class="notification-icon <?php echo getNotificationColor($notification['NotificationType']); ?>">
                            <i class="<?php echo getNotificationIcon($notification['NotificationType']); ?>"></i>
                        </div>
                        
                        <div class="notification-content">
                            <div class="notification-title">
                                <?php echo htmlspecialchars($notification['NotificationTitle']); ?>
                                <?php if (!$notification['IsRead']): ?>
                                    <span style="color: #00d2d3; font-size: 12px; margin-left: 8px;">● NEW</span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="notification-message">
                                <?php echo nl2br(htmlspecialchars($notification['NotificationMessage'])); ?>
                            </div>
                            
                            <?php if (!empty($notification['EventTitle'])): ?>
                                <div class="notification-message" style="margin-top: 10px; padding: 10px; background: rgba(255,255,255,0.1); border-radius: 10px;">
                                    <i class="fas fa-calendar-alt" style="margin-right: 8px; color: #00d2d3;"></i>
                                    <strong>Related Event:</strong> <?php echo htmlspecialchars($notification['EventTitle']); ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="notification-meta">
                                <div class="notification-time">
                                    <i class="fas fa-clock"></i>
                                    <?php echo timeAgo($notification['CreatedDate']); ?>
                                </div>
                                
                                <div class="notification-type">
                                    <i class="<?php echo getNotificationIcon($notification['NotificationType']); ?>"></i>
                                    <?php echo htmlspecialchars($notification['NotificationType']); ?>
                                </div>
                                
                                <?php if ($notification['IsRead'] && !empty($notification['ReadDate'])): ?>
                                    <div class="notification-time">
                                        <i class="fas fa-eye"></i>
                                        Read <?php echo timeAgo($notification['ReadDate']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="notification-actions-menu">
                            <?php if (!$notification['IsRead']): ?>
                                <button onclick="markAsRead(<?php echo $notification['NotificationID']; ?>)" 
                                        class="action-btn" title="Mark as read">
                                    <i class="fas fa-check"></i>
                                </button>
                            <?php endif; ?>
                            
                            <button onclick="deleteNotification(<?php echo $notification['NotificationID']; ?>)" 
                                    class="action-btn" title="Delete notification">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?filter=<?php echo $filter; ?>&page=<?php echo $page - 1; ?>" title="Previous page">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <a href="?filter=<?php echo $filter; ?>&page=<?php echo $i; ?>" 
                               class="<?php echo $i === $page ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <a href="?filter=<?php echo $filter; ?>&page=<?php echo $page + 1; ?>" title="Next page">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
            <?php else: ?>
                <!-- Empty State -->
                <div class="empty-state">
                    <i class="fas fa-bell-slash"></i>
                    <h3>No Notifications Found</h3>
                    <p>
                        <?php 
                        $emptyMessages = [
                            'all' => "You don't have any notifications yet. When you register for events or when there are updates, they'll appear here.",
                            'unread' => "You're all caught up! No unread notifications at this time.",
                            'read' => "No read notifications found.",
                            'event' => "No event notifications found.",
                            'registration' => "No registration notifications found.",
                            'system' => "No system notifications found."
                        ];
                        echo $emptyMessages[$filter] ?? "No notifications found for this filter.";
                        ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div id="loadingOverlay" class="loading" style="display: none;">
        <i class="fas fa-spinner"></i>
        <p>Processing...</p>
    </div>

    <script>
        // Mark single notification as read
        function markAsRead(notificationId) {
            showLoading();
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=mark_read&notification_id=${notificationId}`
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                if (data.success) {
                    // Update the notification item visually
                    const notificationItem = document.querySelector(`[data-notification-id="${notificationId}"]`);
                    if (notificationItem) {
                        notificationItem.classList.remove('unread');
                        
                        // Remove the "NEW" indicator
                        const newIndicator = notificationItem.querySelector('.notification-title span');
                        if (newIndicator && newIndicator.textContent.includes('NEW')) {
                            newIndicator.remove();
                        }
                        
                        // Remove the mark as read button
                        const markReadBtn = notificationItem.querySelector('button[onclick*="markAsRead"]');
                        if (markReadBtn) {
                            markReadBtn.remove();
                        }
                        
                        // Add read timestamp
                        const metaDiv = notificationItem.querySelector('.notification-meta');
                        const readTime = document.createElement('div');
                        readTime.className = 'notification-time';
                        readTime.innerHTML = '<i class="fas fa-eye"></i> Read just now';
                        metaDiv.appendChild(readTime);
                    }
                    
                    // Update notification counts in navigation
                    updateNotificationCounts();
                    
                    showToast('Notification marked as read', 'success');
                } else {
                    showToast('Failed to mark notification as read', 'error');
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Error:', error);
                showToast('An error occurred', 'error');
            });
        }

        // Mark all notifications as read
        function markAllAsRead() {
            if (!confirm('Mark all notifications as read?')) {
                return;
            }
            
            showLoading();
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=mark_all_read'
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                if (data.success) {
                    // Reload the page to reflect changes
                    window.location.reload();
                } else {
                    showToast('Failed to mark all notifications as read', 'error');
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Error:', error);
                showToast('An error occurred', 'error');
            });
        }

        // Delete notification
        function deleteNotification(notificationId) {
            if (!confirm('Are you sure you want to delete this notification? This action cannot be undone.')) {
                return;
            }
            
            showLoading();
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=delete_notification&notification_id=${notificationId}`
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                if (data.success) {
                    // Remove the notification item from the page
                    const notificationItem = document.querySelector(`[data-notification-id="${notificationId}"]`);
                    if (notificationItem) {
                        notificationItem.style.opacity = '0';
                        notificationItem.style.transform = 'translateX(-100%)';
                        setTimeout(() => {
                            notificationItem.remove();
                            
                            // Check if no notifications left
                            const remainingNotifications = document.querySelectorAll('.notification-item');
                            if (remainingNotifications.length === 0) {
                                window.location.reload();
                            }
                        }, 300);
                    }
                    
                    updateNotificationCounts();
                    showToast('Notification deleted', 'success');
                } else {
                    showToast('Failed to delete notification', 'error');
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Error:', error);
                showToast('An error occurred', 'error');
            });
        }

        // Show loading overlay
        function showLoading() {
            document.getElementById('loadingOverlay').style.display = 'block';
        }

        // Hide loading overlay
        function hideLoading() {
            document.getElementById('loadingOverlay').style.display = 'none';
        }

        // Update notification counts in navigation
        function updateNotificationCounts() {
            // This would require another AJAX call to get updated counts
            // For now, we'll just refresh the page on bulk operations
        }

        // Show toast notification
        function showToast(message, type = 'info') {
            // Create toast element
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: ${type === 'success' ? 'linear-gradient(135deg, #10ac84, #00d2d3)' : 'linear-gradient(135deg, #ff6b6b, #ee5a24)'};
                color: white;
                padding: 15px 25px;
                border-radius: 25px;
                box-shadow: 0 8px 25px rgba(0,0,0,0.2);
                z-index: 10000;
                backdrop-filter: blur(10px);
                font-weight: 600;
                transform: translateX(400px);
                transition: all 0.3s ease;
            `;
            toast.textContent = message;
            
            document.body.appendChild(toast);
            
            // Animate in
            setTimeout(() => {
                toast.style.transform = 'translateX(0)';
            }, 100);
            
            // Auto remove after 3 seconds
            setTimeout(() => {
                toast.style.transform = 'translateX(400px)';
                setTimeout(() => {
                    document.body.removeChild(toast);
                }, 300);
            }, 3000);
        }

        // Auto-refresh notifications every 5 minutes
        setInterval(() => {
            // Silently check for new notifications
            fetch('check_notifications.php')
                .then(response => response.json())
                .then(data => {
                    if (data.newNotifications > 0) {
                        showToast(`You have ${data.newNotifications} new notification${data.newNotifications > 1 ? 's' : ''}`, 'info');
                        
                        // Update badge in navigation
                        const badge = document.querySelector('.nav-link.active .filter-badge');
                        if (badge) {
                            badge.textContent = parseInt(badge.textContent) + data.newNotifications;
                        }
                    }
                })
                .catch(error => {
                    console.log('Auto-refresh failed:', error);
                });
        }, 5 * 60 * 1000); // 5 minutes

        // Add click handlers for notification items to mark as read when clicked
        document.addEventListener('DOMContentLoaded', function() {
            const notificationItems = document.querySelectorAll('.notification-item.unread');
            notificationItems.forEach(item => {
                item.addEventListener('click', function(e) {
                    // Don't trigger if clicking on action buttons
                    if (e.target.closest('.action-btn') || e.target.closest('.notification-actions-menu')) {
                        return;
                    }
                    
                    const notificationId = this.dataset.notificationId;
                    if (notificationId && this.classList.contains('unread')) {
                        markAsRead(notificationId);
                    }
                });
                
                // Add visual feedback
                item.style.cursor = 'pointer';
                item.title = 'Click to mark as read';
            });
        });

        // Add smooth scrolling for pagination
        document.querySelectorAll('.pagination a').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                showLoading();
                window.location.href = this.href;
            });
        });
    </script>
</body>
</html>