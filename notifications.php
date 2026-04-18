<?php
session_start();

// Suppress all PHP output and errors from displaying
error_reporting(0);
ini_set('display_errors', 0);

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    header("Location: student_login.php");
    exit;
}

try {
    $pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    header("Location: error.php");
    exit;
}

$studentId = $_SESSION['student_id'];

// Get student information
try {
    $stmt = $pdo->prepare("SELECT * FROM student WHERE StudentID = ?");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch();

    if (!$student) {
        session_destroy();
        header("Location: student_login.php");
        exit;
    }
} catch (PDOException $e) {
    error_log("Error fetching student data: " . $e->getMessage());
    header("Location: error.php");
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'mark_read':
                if (!isset($_POST['notification_id'])) {
                    echo json_encode(['success' => false, 'error' => 'Notification ID required']);
                    exit;
                }
                
                $notificationId = (int)$_POST['notification_id'];
                $updateStmt = $pdo->prepare("
                    UPDATE notification 
                    SET NotificationIsRead = 1, ReadDate = NOW() 
                    WHERE NotificationID = ? AND StudentID = ?
                ");
                $result = $updateStmt->execute([$notificationId, $studentId]);
                
                if ($result && $updateStmt->rowCount() > 0) {
                    echo json_encode(['success' => true, 'message' => 'Notification marked as read']);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Notification not found or already read']);
                }
                break;
                
            case 'mark_all_read':
                $updateAllStmt = $pdo->prepare("
                    UPDATE notification 
                    SET NotificationIsRead = 1, ReadDate = NOW() 
                    WHERE StudentID = ? AND NotificationIsRead = 0
                ");
                $result = $updateAllStmt->execute([$studentId]);
                
                if ($result) {
                    echo json_encode(['success' => true, 'message' => 'All notifications marked as read', 'count' => $updateAllStmt->rowCount()]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Failed to update notifications']);
                }
                break;
                
            case 'delete_notification':
                if (!isset($_POST['notification_id'])) {
                    echo json_encode(['success' => false, 'error' => 'Notification ID required']);
                    exit;
                }
                
                $notificationId = (int)$_POST['notification_id'];
                $deleteStmt = $pdo->prepare("
                    DELETE FROM notification 
                    WHERE NotificationID = ? AND StudentID = ?
                ");
                $result = $deleteStmt->execute([$notificationId, $studentId]);
                
                if ($result && $deleteStmt->rowCount() > 0) {
                    echo json_encode(['success' => true, 'message' => 'Notification deleted successfully']);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Notification not found or could not be deleted']);
                }
                break;
                
            case 'set_priority':
                if (!isset($_POST['notification_id'], $_POST['priority'])) {
                    echo json_encode(['success' => false, 'error' => 'Notification ID and priority required']);
                    exit;
                }
                
                $notificationId = (int)$_POST['notification_id'];
                $priority = $_POST['priority'];
                
                // Validate priority value
                if (!in_array($priority, ['Low', 'Medium', 'High'])) {
                    echo json_encode(['success' => false, 'error' => 'Invalid priority value']);
                    exit;
                }
                
                $updateStmt = $pdo->prepare("
                    UPDATE notification 
                    SET Priority = ? 
                    WHERE NotificationID = ? AND StudentID = ?
                ");
                $result = $updateStmt->execute([$priority, $notificationId, $studentId]);
                
                if ($result && $updateStmt->rowCount() > 0) {
                    echo json_encode(['success' => true, 'message' => "Priority updated to {$priority}"]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Notification not found or priority unchanged']);
                }
                break;
                
            case 'respond_to_event':
                if (!isset($_POST['notification_id'], $_POST['response'])) {
                    echo json_encode(['success' => false, 'error' => 'Notification ID and response required']);
                    exit;
                }
                
                $notificationId = (int)$_POST['notification_id'];
                $response = $_POST['response'];
                
                // Validate response
                if (!in_array($response, ['interested', 'not_interested', 'maybe'])) {
                    echo json_encode(['success' => false, 'error' => 'Invalid response']);
                    exit;
                }
                
                // Mark notification as read when responding
                $updateStmt = $pdo->prepare("
                    UPDATE notification 
                    SET NotificationIsRead = 1, ReadDate = NOW() 
                    WHERE NotificationID = ? AND StudentID = ? AND NotificationType = 'Event'
                ");
                $result = $updateStmt->execute([$notificationId, $studentId]);
                
                $responseMessages = [
                    'interested' => 'Thank you for your interest! We\'ll keep you updated about this event.',
                    'not_interested' => 'No problem! You can change your mind anytime by visiting the Events page.',
                    'maybe' => 'Thanks for considering! Check back anytime for event updates.'
                ];
                
                echo json_encode([
                    'success' => true, 
                    'message' => $responseMessages[$response],
                    'response' => $response
                ]);
                break;
                
            default:
                echo json_encode(['success' => false, 'error' => 'Invalid action']);
                break;
        }
    } catch (PDOException $e) {
        error_log("Database error in notification action: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Database error occurred']);
    } catch (Exception $e) {
        error_log("General error in notification action: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'An unexpected error occurred']);
    }
    exit;
}

// Get filter parameters with validation
$allowedFilters = ['all', 'unread', 'read', 'event', 'registration', 'system'];
$allowedPriorities = ['all', 'high', 'medium', 'low'];

$filter = isset($_GET['filter']) && in_array($_GET['filter'], $allowedFilters) ? $_GET['filter'] : 'all';
$priority = isset($_GET['priority']) && in_array($_GET['priority'], $allowedPriorities) ? $_GET['priority'] : 'all';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Build WHERE clause with proper parameterization
$whereClause = "WHERE StudentID = ?";
$params = [$studentId];

// Add filter conditions
switch ($filter) {
    case 'unread':
        $whereClause .= " AND NotificationIsRead = 0";
        break;
    case 'read':
        $whereClause .= " AND NotificationIsRead = 1";
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
}

// Add priority filter
switch ($priority) {
    case 'high':
        $whereClause .= " AND Priority = 'High'";
        break;
    case 'medium':
        $whereClause .= " AND Priority = 'Medium'";
        break;
    case 'low':
        $whereClause .= " AND Priority = 'Low'";
        break;
}

// Get notification counts for filter badges
$counts = [];
try {
    $countQueries = [
        'total' => "SELECT COUNT(*) FROM notification WHERE StudentID = ?",
        'unread' => "SELECT COUNT(*) FROM notification WHERE StudentID = ? AND NotificationIsRead = 0",
        'read' => "SELECT COUNT(*) FROM notification WHERE StudentID = ? AND NotificationIsRead = 1",
        'event' => "SELECT COUNT(*) FROM notification WHERE StudentID = ? AND NotificationType = 'Event'",
        'registration' => "SELECT COUNT(*) FROM notification WHERE StudentID = ? AND NotificationType = 'Registration'",
        'system' => "SELECT COUNT(*) FROM notification WHERE StudentID = ? AND NotificationType = 'System'",
        'high_priority' => "SELECT COUNT(*) FROM notification WHERE StudentID = ? AND Priority = 'High'",
        'medium_priority' => "SELECT COUNT(*) FROM notification WHERE StudentID = ? AND Priority = 'Medium'",
        'low_priority' => "SELECT COUNT(*) FROM notification WHERE StudentID = ? AND Priority = 'Low'"
    ];
    
    foreach ($countQueries as $key => $query) {
        $stmt = $pdo->prepare($query);
        $stmt->execute([$studentId]);
        $counts[$key] = $stmt->fetchColumn();
    }
} catch (PDOException $e) {
    error_log("Error getting notification counts: " . $e->getMessage());
    $counts = array_fill_keys(['total', 'unread', 'read', 'event', 'registration', 'system', 'high_priority', 'medium_priority', 'low_priority'], 0);
}

// Get total count for pagination
try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM notification $whereClause");
    $countStmt->execute($params);
    $totalNotifications = $countStmt->fetchColumn();
    $totalPages = ceil($totalNotifications / $limit);
} catch (PDOException $e) {
    error_log("Error getting total notification count: " . $e->getMessage());
    $totalNotifications = 0;
    $totalPages = 1;
}

// Get notifications with pagination and filtering
$notifications = [];
try {
    $stmt = $pdo->prepare("
        SELECT n.*, e.EventTitle 
        FROM notification n 
        LEFT JOIN event e ON n.EventID = e.EventID 
        $whereClause 
        ORDER BY 
            CASE WHEN n.Priority = 'High' THEN 1 
                 WHEN n.Priority = 'Medium' THEN 2 
                 WHEN n.Priority = 'Low' THEN 3 
                 ELSE 4 END,
            n.NotificationIsRead ASC,
            n.CreatedDate DESC, 
            n.NotificationID DESC 
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $notifications = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching notifications: " . $e->getMessage());
    $notifications = [];
}

// Helper functions
function getNotificationIcon($type) {
    $icons = [
        'Event' => 'fas fa-calendar-alt',
        'Registration' => 'fas fa-user-check',
        'System' => 'fas fa-cog'
    ];
    return $icons[$type] ?? 'fas fa-bell';
}

function getNotificationColor($type) {
    $colors = [
        'Event' => 'event',
        'Registration' => 'registration',
        'System' => 'system'
    ];
    return $colors[$type] ?? 'default';
}

function getPriorityColor($priority) {
    $colors = [
        'High' => '#ef4444',
        'Medium' => '#f59e0b',
        'Low' => '#10b981'
    ];
    return $colors[$priority] ?? '#64748b';
}

function timeAgo($datetime) {
    if (empty($datetime)) return 'Unknown';
    
    $time = time() - strtotime($datetime);
    
    if ($time < 60) return 'Just now';
    if ($time < 3600) return floor($time / 60) . ' minute' . (floor($time / 60) > 1 ? 's' : '') . ' ago';
    if ($time < 86400) return floor($time / 3600) . ' hour' . (floor($time / 3600) > 1 ? 's' : '') . ' ago';
    if ($time < 2592000) return floor($time / 86400) . ' day' . (floor($time / 86400) > 1 ? 's' : '') . ' ago';
    if ($time < 31536000) return floor($time / 2592000) . ' month' . (floor($time / 2592000) > 1 ? 's' : '') . ' ago';
    return floor($time / 31536000) . ' year' . (floor($time / 31536000) > 1 ? 's' : '') . ' ago';
}

function sanitizeOutput($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

// Sample notifications for demo (only if no real notifications exist)
if (count($notifications) === 0 && $filter === 'all' && $page === 1) {
    $sampleNotifications = [
        [
            'NotificationID' => 'demo1',
            'NotificationTitle' => 'Welcome to LifeSaver Hub!',
            'NotificationMessage' => 'Thank you for joining our blood donation community. Your participation can save lives and make a real difference in your community.',
            'NotificationType' => 'System',
            'Priority' => 'High',
            'NotificationIsRead' => 0,
            'CreatedDate' => date('Y-m-d H:i:s'),
            'EventTitle' => null,
            'ReadDate' => null,
            'StudentID' => $studentId,
            'EventID' => null
        ],
        [
            'NotificationID' => 'demo2',
            'NotificationTitle' => 'Upcoming Blood Drive Event',
            'NotificationMessage' => 'Don\'t forget about the blood donation drive scheduled for next week. Your contribution can help save up to three lives! Click to learn more and register.',
            'NotificationType' => 'Event',
            'Priority' => 'Medium',
            'NotificationIsRead' => 0,
            'CreatedDate' => date('Y-m-d H:i:s', strtotime('-2 hours')),
            'EventTitle' => 'LifeSaver Blood Donation Drive 2025',
            'ReadDate' => null,
            'StudentID' => $studentId,
            'EventID' => 1
        ],
        [
            'NotificationID' => 'demo3',
            'NotificationTitle' => 'Registration Confirmed',
            'NotificationMessage' => 'Your registration for the upcoming blood drive has been confirmed. We look forward to seeing you there! Remember to bring a valid ID and stay hydrated.',
            'NotificationType' => 'Registration',
            'Priority' => 'Low',
            'NotificationIsRead' => 1,
            'CreatedDate' => date('Y-m-d H:i:s', strtotime('-1 day')),
            'EventTitle' => null,
            'ReadDate' => date('Y-m-d H:i:s', strtotime('-1 day')),
            'StudentID' => $studentId,
            'EventID' => null
        ]
    ];
    $notifications = $sampleNotifications;
    $totalNotifications = count($sampleNotifications);
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
            overflow-y: auto;
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

        .nav-item:hover::before, .nav-item.active::before {
            opacity: 1;
            transform: translateX(0);
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

        .notification-badge {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 4px 10px;
            border-radius: 14px;
            font-size: 11px;
            font-weight: 800;
            margin-left: auto;
            min-width: 22px;
            text-align: center;
            box-shadow: 0 3px 12px rgba(102, 126, 234, 0.4);
            position: relative;
            z-index: 1;
            animation: notificationPulse 2s infinite;
        }

        @keyframes notificationPulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
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

        /* Filter Section */
        .filter-section {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 32px;
            margin-bottom: 32px;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .filter-section h3 {
            color: #2d3748;
            margin-bottom: 24px;
            font-size: 20px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .priority-filters, .filter-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 24px;
        }

        .priority-btn, .filter-tab {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 14px 24px;
            background: rgba(248, 249, 250, 0.8);
            color: #4a5568;
            text-decoration: none;
            border-radius: 16px;
            font-weight: 600;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 2px solid transparent;
            position: relative;
            overflow: hidden;
        }

        .priority-btn::before, .filter-tab::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(139, 92, 246, 0.1));
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .priority-btn:hover::before, .filter-tab:hover::before,
        .priority-btn.active::before, .filter-tab.active::before {
            opacity: 1;
        }

        .priority-btn:hover, .filter-tab:hover,
        .priority-btn.active, .filter-tab.active {
            color: #2d3748;
            border-color: rgba(102, 126, 234, 0.3);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .priority-btn.high { border-left: 4px solid #f56565; }
        .priority-btn.medium { border-left: 4px solid #ed8936; }
        .priority-btn.low { border-left: 4px solid #48bb78; }

        .filter-badge {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 6px 12px;
            border-radius: 16px;
            font-size: 12px;
            font-weight: 700;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }

        /* Notifications Container */
        .notifications-container {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .bulk-actions {
            padding: 24px 32px;
            background: rgba(248, 249, 250, 0.8);
            border-bottom: 1px solid rgba(0, 0, 0, 0.08);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }

        .notification-item {
            padding: 32px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.08);
            display: flex;
            gap: 24px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            background: rgba(255, 255, 255, 0.5);
            margin: 1px;
        }

        .notification-item:hover {
            background: rgba(248, 249, 250, 0.8);
            transform: translateX(8px);
        }

        .notification-item.unread {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.08), rgba(139, 92, 246, 0.08));
            border-left: 4px solid #667eea;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.1);
        }

        .notification-item.unread .notification-title::after {
            content: 'NEW';
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 800;
            margin-left: 12px;
            animation: pulse 2s infinite;
        }

        .notification-icon {
            width: 64px;
            height: 64px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
            flex-shrink: 0;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
            position: relative;
        }

        .notification-icon::before {
            content: '';
            position: absolute;
            top: -2px;
            left: -2px;
            right: -2px;
            bottom: -2px;
            border-radius: 22px;
            background: linear-gradient(45deg, rgba(255, 255, 255, 0.3), transparent, rgba(255, 255, 255, 0.3));
            z-index: -1;
        }

        .notification-icon.event { 
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
        }
        .notification-icon.registration { 
            background: linear-gradient(135deg, #10b981, #059669);
        }
        .notification-icon.system { 
            background: linear-gradient(135deg, #f59e0b, #d97706);
        }

        .notification-content {
            flex: 1;
        }

        .notification-title {
            font-size: 18px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 12px;
            line-height: 1.4;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
        }

        .notification-message {
            color: #4a5568;
            line-height: 1.6;
            margin-bottom: 20px;
            font-size: 15px;
        }

        .notification-meta {
            display: flex;
            align-items: center;
            gap: 20px;
            font-size: 13px;
            color: #718096;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }

        .notification-meta span {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .event-info {
            margin: 16px 0;
            padding: 16px;
            background: rgba(139, 92, 246, 0.1);
            border-radius: 12px;
            border-left: 4px solid #8b5cf6;
        }

        .event-info i {
            margin-right: 12px;
            color: #8b5cf6;
        }

        .quick-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 16px;
        }

        .quick-action {
            padding: 8px 16px;
            background: rgba(248, 249, 250, 0.8);
            border: 1px solid rgba(0, 0, 0, 0.08);
            border-radius: 12px;
            color: #4a5568;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .quick-action:hover {
            background: rgba(102, 126, 234, 0.1);
            color: #2d3748;
            transform: translateY(-1px);
            border-color: #667eea;
            text-decoration: none;
        }

        .quick-action.interested {
            background: rgba(16, 185, 129, 0.1);
            border-color: rgba(16, 185, 129, 0.3);
            color: #059669;
        }

        .quick-action.not-interested {
            background: rgba(239, 68, 68, 0.1);
            border-color: rgba(239, 68, 68, 0.3);
            color: #dc2626;
        }

        .quick-action.maybe {
            background: rgba(245, 158, 11, 0.1);
            border-color: rgba(245, 158, 11, 0.3);
            color: #d97706;
        }

        .notification-actions {
            display: flex;
            flex-direction: column;
            gap: 12px;
            align-items: flex-end;
            flex-shrink: 0;
        }

        .action-btn {
            background: rgba(248, 249, 250, 0.8);
            border: 1px solid rgba(0, 0, 0, 0.08);
            color: #4a5568;
            padding: 10px 16px;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 13px;
            font-weight: 500;
            min-width: 80px;
            text-align: center;
        }

        .action-btn:hover {
            background: rgba(102, 126, 234, 0.1);
            color: #2d3748;
            transform: translateY(-2px);
            border-color: #667eea;
        }

        .priority-selector select {
            background: rgba(248, 249, 250, 0.8);
            border: 1px solid rgba(0, 0, 0, 0.08);
            color: #4a5568;
            padding: 8px 12px;
            border-radius: 12px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .priority-selector select:hover {
            background: rgba(102, 126, 234, 0.1);
            border-color: #667eea;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
            font-size: 14px;
        }

        .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 35px rgba(102, 126, 234, 0.4);
            text-decoration: none;
            color: white;
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .empty-state {
            text-align: center;
            padding: 80px 40px;
            color: #4a5568;
        }

        .empty-state i {
            font-size: 5rem;
            margin-bottom: 24px;
            opacity: 0.4;
            color: #a0aec0;
        }

        .empty-state h3 {
            color: #2d3748;
            font-size: 1.8rem;
            margin-bottom: 16px;
            font-weight: 700;
        }

        .empty-state p {
            max-width: 500px;
            margin: 0 auto 24px;
            line-height: 1.6;
        }

        /* Pagination */
        .pagination {
            padding: 24px;
            text-align: center;
            background: rgba(248, 249, 250, 0.8);
            border-top: 1px solid rgba(0, 0, 0, 0.08);
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }

        .pagination-info {
            color: #4a5568;
            font-weight: 500;
            font-size: 14px;
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
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }

        /* Toast notifications */
        .toast {
            position: fixed;
            top: 24px;
            right: 24px;
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            padding: 16px 24px;
            border-radius: 16px;
            box-shadow: 0 12px 35px rgba(16, 185, 129, 0.3);
            z-index: 10000;
            font-weight: 600;
            transform: translateX(400px);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            gap: 12px;
            max-width: 400px;
        }

        .toast.show {
            transform: translateX(0);
        }

        .toast.error {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            box-shadow: 0 12px 35px rgba(239, 68, 68, 0.3);
        }

        .toast.info {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            box-shadow: 0 12px 35px rgba(59, 130, 246, 0.3);
        }

        .toast.success {
            background: linear-gradient(135deg, #10b981, #059669);
            box-shadow: 0 12px 35px rgba(16, 185, 129, 0.3);
        }

        /* Loading state */
        .loading {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px;
            color: #4a5568;
        }

        .loading::after {
            content: '';
            width: 20px;
            height: 20px;
            border: 2px solid #e2e8f0;
            border-top-color: #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-left: 12px;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
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
            
            .filter-tabs, .priority-filters {
                flex-direction: column;
            }
            
            .notification-item {
                flex-direction: column;
                text-align: left;
                padding: 24px;
                gap: 16px;
            }

            .notification-actions {
                flex-direction: row;
                justify-content: flex-start;
                align-items: center;
            }

            .page-header h1 {
                font-size: 2rem;
            }

            .bulk-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .quick-actions {
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .page-header {
                padding: 32px 24px;
            }
            
            .filter-section {
                padding: 24px;
            }

            .notification-item {
                padding: 20px;
            }

            .page-header h1 {
                font-size: 1.8rem;
                flex-direction: column;
                gap: 12px;
                text-align: center;
            }
        }

        /* Accessibility */
        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        /* Focus styles for keyboard navigation */
        .keyboard-navigation .nav-item:focus,
        .keyboard-navigation .btn:focus,
        .keyboard-navigation .action-btn:focus,
        .keyboard-navigation .quick-action:focus,
        .keyboard-navigation .filter-tab:focus,
        .keyboard-navigation .priority-btn:focus {
            outline: 3px solid #667eea;
            outline-offset: 2px;
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

        /* Animation classes */
        .fade-in {
            animation: fadeInUp 0.6s ease-out forwards;
        }

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

        .slide-in {
            animation: slideIn 0.4s ease-out forwards;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
    </style>
</head>
<body>
    <!-- Mobile menu button -->
    <button class="mobile-menu-btn" onclick="toggleSidebar()" aria-label="Toggle navigation menu">
        <i class="fas fa-bars"></i>
    </button>

    <div class="app-container">
        <!-- Enhanced Sidebar -->
        <nav class="sidebar" id="sidebar" role="navigation" aria-label="Main navigation">
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
                        <a href="student_view_event.php" class="nav-item">
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
                        <a href="notifications.php" class="nav-item active">
                            <i class="fas fa-bell"></i>
                            <span>Notifications</span>
                            <?php if ($counts['unread'] > 0): ?>
                                <span class="notification-badge" id="unread-badge"><?php echo $counts['unread']; ?></span>
                            <?php endif; ?>
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
                        <?php echo strtoupper(substr(sanitizeOutput($student['StudentName']), 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <h4><?php echo sanitizeOutput($student['StudentName']); ?></h4>
                        <p>Student ID: <?php echo sanitizeOutput($studentId); ?></p>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Main Content -->
        <main class="main-content" role="main">
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-header-content">
                    <h1>
                        <i class="fas fa-bell" aria-hidden="true"></i>
                        Notifications
                    </h1>
                    <p>Stay updated with your blood donation activities and events</p>
                </div>
            </div>

            <!-- Priority Inbox & Filters -->
            <div class="filter-section">
                <h3>
                    <i class="fas fa-inbox" aria-hidden="true"></i>
                    Priority Inbox
                </h3>
                
                <div class="priority-filters">
                    <a href="?priority=all&filter=<?php echo $filter; ?>" 
                       class="priority-btn <?php echo $priority === 'all' ? 'active' : ''; ?>"
                       aria-current="<?php echo $priority === 'all' ? 'page' : 'false'; ?>">
                        All Priorities
                    </a>
                    <a href="?priority=high&filter=<?php echo $filter; ?>" 
                       class="priority-btn high <?php echo $priority === 'high' ? 'active' : ''; ?>"
                       aria-current="<?php echo $priority === 'high' ? 'page' : 'false'; ?>">
                        High Priority (<?php echo $counts['high_priority']; ?>)
                    </a>
                    <a href="?priority=medium&filter=<?php echo $filter; ?>" 
                       class="priority-btn medium <?php echo $priority === 'medium' ? 'active' : ''; ?>"
                       aria-current="<?php echo $priority === 'medium' ? 'page' : 'false'; ?>">
                        Medium Priority (<?php echo $counts['medium_priority']; ?>)
                    </a>
                    <a href="?priority=low&filter=<?php echo $filter; ?>" 
                       class="priority-btn low <?php echo $priority === 'low' ? 'active' : ''; ?>"
                       aria-current="<?php echo $priority === 'low' ? 'page' : 'false'; ?>">
                        Low Priority (<?php echo $counts['low_priority']; ?>)
                    </a>
                </div>

                <div class="filter-tabs">
                    <a href="?filter=all&priority=<?php echo $priority; ?>" 
                       class="filter-tab <?php echo $filter === 'all' ? 'active' : ''; ?>"
                       aria-current="<?php echo $filter === 'all' ? 'page' : 'false'; ?>">
                        <i class="fas fa-list" aria-hidden="true"></i>
                        <span>All</span>
                        <span class="filter-badge"><?php echo $counts['total']; ?></span>
                    </a>
                    <a href="?filter=unread&priority=<?php echo $priority; ?>" 
                       class="filter-tab <?php echo $filter === 'unread' ? 'active' : ''; ?>"
                       aria-current="<?php echo $filter === 'unread' ? 'page' : 'false'; ?>">
                        <i class="fas fa-envelope" aria-hidden="true"></i>
                        <span>Unread</span>
                        <span class="filter-badge"><?php echo $counts['unread']; ?></span>
                    </a>
                    <a href="?filter=event&priority=<?php echo $priority; ?>" 
                       class="filter-tab <?php echo $filter === 'event' ? 'active' : ''; ?>"
                       aria-current="<?php echo $filter === 'event' ? 'page' : 'false'; ?>">
                        <i class="fas fa-calendar-alt" aria-hidden="true"></i>
                        <span>Events</span>
                        <span class="filter-badge"><?php echo $counts['event']; ?></span>
                    </a>
                    <a href="?filter=registration&priority=<?php echo $priority; ?>" 
                       class="filter-tab <?php echo $filter === 'registration' ? 'active' : ''; ?>"
                       aria-current="<?php echo $filter === 'registration' ? 'page' : 'false'; ?>">
                        <i class="fas fa-user-check" aria-hidden="true"></i>
                        <span>Registrations</span>
                        <span class="filter-badge"><?php echo $counts['registration']; ?></span>
                    </a>
                    <a href="?filter=system&priority=<?php echo $priority; ?>" 
                       class="filter-tab <?php echo $filter === 'system' ? 'active' : ''; ?>"
                       aria-current="<?php echo $filter === 'system' ? 'page' : 'false'; ?>">
                        <i class="fas fa-cog" aria-hidden="true"></i>
                        <span>System</span>
                        <span class="filter-badge"><?php echo $counts['system']; ?></span>
                    </a>
                </div>
            </div>

            <!-- Notifications -->
            <div class="notifications-container">
                <?php if (count($notifications) > 0): ?>
                    <div class="bulk-actions">
                        <span class="pagination-info">
                            <?php echo count($notifications); ?> of <?php echo $totalNotifications; ?> notifications
                        </span>
                        <?php if ($counts['unread'] > 0): ?>
                            <button onclick="markAllAsRead()" class="btn" id="mark-all-btn">
                                <i class="fas fa-check-double" aria-hidden="true"></i>
                                Mark All Read
                            </button>
                        <?php endif; ?>
                    </div>

                    <?php foreach ($notifications as $index => $notification): ?>
                        <?php 
                        // Security check: Verify notification belongs to current student
                        if (!isset($notification['StudentID']) || ($notification['StudentID'] != $studentId && !str_contains($notification['NotificationID'], 'demo'))) {
                            error_log("SECURITY WARNING: Notification " . $notification['NotificationID'] . " security check failed");
                            continue;
                        }
                        ?>
                        <article class="notification-item <?php echo $notification['NotificationIsRead'] ? '' : 'unread'; ?> slide-in" 
                                 data-notification-id="<?php echo sanitizeOutput($notification['NotificationID']); ?>"
                                 style="animation-delay: <?php echo $index * 0.05; ?>s;">
                            
                            <div class="notification-icon <?php echo getNotificationColor($notification['NotificationType']); ?>">
                                <i class="<?php echo getNotificationIcon($notification['NotificationType']); ?>" aria-hidden="true"></i>
                            </div>
                            
                            <div class="notification-content">
                                <h3 class="notification-title">
                                    <?php echo sanitizeOutput($notification['NotificationTitle']); ?>
                                </h3>
                                
                                <div class="notification-message">
                                    <?php echo nl2br(sanitizeOutput($notification['NotificationMessage'])); ?>
                                </div>
                                
                                <?php if (!empty($notification['EventTitle'])): ?>
                                    <div class="event-info">
                                        <i class="fas fa-calendar-alt" aria-hidden="true"></i>
                                        <strong>Related Event:</strong> <?php echo sanitizeOutput($notification['EventTitle']); ?>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="notification-meta">
                                    <span>
                                        <i class="fas fa-clock" aria-hidden="true"></i>
                                        <time datetime="<?php echo $notification['CreatedDate']; ?>">
                                            <?php echo timeAgo($notification['CreatedDate']); ?>
                                        </time>
                                    </span>
                                    
                                    <span>
                                        <i class="<?php echo getNotificationIcon($notification['NotificationType']); ?>" aria-hidden="true"></i>
                                        <?php echo sanitizeOutput($notification['NotificationType']); ?>
                                    </span>
                                    
                                    <span style="color: <?php echo getPriorityColor($notification['Priority'] ?? 'Low'); ?>;">
                                        <i class="fas fa-flag" aria-hidden="true"></i>
                                        <?php echo sanitizeOutput($notification['Priority'] ?? 'Low'); ?> Priority
                                    </span>
                                    
                                    <?php if ($notification['NotificationIsRead'] && !empty($notification['ReadDate'])): ?>
                                        <span>
                                            <i class="fas fa-eye" aria-hidden="true"></i>
                                            Read <?php echo timeAgo($notification['ReadDate']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <!-- Event notification responses -->
                                <?php if ($notification['NotificationType'] === 'Event' && !str_contains($notification['NotificationID'], 'demo')): ?>
                                    <div class="quick-actions">
                                        <button class="quick-action interested" 
                                                onclick="respondToEvent('<?php echo $notification['NotificationID']; ?>', 'interested')"
                                                aria-label="Express interest in this event">
                                            <i class="fas fa-thumbs-up" aria-hidden="true"></i> 
                                            I'm Interested
                                        </button>
                                        <button class="quick-action maybe" 
                                                onclick="respondToEvent('<?php echo $notification['NotificationID']; ?>', 'maybe')"
                                                aria-label="Maybe interested in this event">
                                            <i class="fas fa-question-circle" aria-hidden="true"></i> 
                                            Maybe Later
                                        </button>
                                        <button class="quick-action not-interested" 
                                                onclick="respondToEvent('<?php echo $notification['NotificationID']; ?>', 'not_interested')"
                                                aria-label="Not interested in this event">
                                            <i class="fas fa-times" aria-hidden="true"></i> 
                                            Not Interested
                                        </button>
                                        <?php if (!empty($notification['EventID'])): ?>
                                            <a href="student_view_event.php?event_id=<?php echo $notification['EventID']; ?>" 
                                               class="quick-action"
                                               aria-label="View event details">
                                                <i class="fas fa-info-circle" aria-hidden="true"></i> 
                                                View Details
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="quick-actions">
                                        <?php if ($notification['NotificationType'] === 'Event'): ?>
                                            <button class="quick-action" 
                                                    onclick="showEventDetails('<?php echo addslashes($notification['EventTitle'] ?? ''); ?>')"
                                                    aria-label="Show event details">
                                                <i class="fas fa-info-circle" aria-hidden="true"></i> 
                                                Event Details
                                            </button>
                                        <?php endif; ?>
                                        
                                        <?php if ($notification['NotificationType'] === 'Registration'): ?>
                                            <a href="view_donation_history.php" class="quick-action"
                                               aria-label="View your donation history">
                                                <i class="fas fa-history" aria-hidden="true"></i> 
                                                View History
                                            </a>
                                        <?php endif; ?>
                                        
                                        <a href="student_view_event.php" class="quick-action"
                                           aria-label="Browse all events">
                                            <i class="fas fa-calendar-alt" aria-hidden="true"></i> 
                                            Browse Events
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="notification-actions">
                                <?php if (!$notification['NotificationIsRead']): ?>
                                    <button onclick="markAsRead(<?php echo $notification['NotificationID']; ?>)" 
                                            class="action-btn" 
                                            title="Mark as read"
                                            aria-label="Mark notification as read">
                                        <i class="fas fa-check" aria-hidden="true"></i> Read
                                    </button>
                                <?php endif; ?>
                                
                                <?php if (!str_contains($notification['NotificationID'], 'demo')): ?>
                                    <div class="priority-selector">
                                        <select onchange="setPriority(<?php echo $notification['NotificationID']; ?>, this.value)" 
                                                aria-label="Set notification priority">
                                            <option value="Low" <?php echo ($notification['Priority'] ?? 'Low') === 'Low' ? 'selected' : ''; ?>>Low Priority</option>
                                            <option value="Medium" <?php echo ($notification['Priority'] ?? 'Low') === 'Medium' ? 'selected' : ''; ?>>Medium Priority</option>
                                            <option value="High" <?php echo ($notification['Priority'] ?? 'Low') === 'High' ? 'selected' : ''; ?>>High Priority</option>
                                        </select>
                                    </div>
                                    
                                    <button onclick="deleteNotification(<?php echo $notification['NotificationID']; ?>)" 
                                            class="action-btn" 
                                            title="Delete notification"
                                            aria-label="Delete this notification">
                                        <i class="fas fa-trash" aria-hidden="true"></i> Delete
                                    </button>
                                <?php else: ?>
                                    <div class="priority-selector">
                                        <select disabled aria-label="Priority setting disabled for demo">
                                            <option><?php echo sanitizeOutput($notification['Priority'] ?? 'Low'); ?> Priority</option>
                                        </select>
                                    </div>
                                    
                                    <button disabled class="action-btn" 
                                            title="Demo notification - cannot delete"
                                            aria-label="Delete disabled for demo notification">
                                        <i class="fas fa-lock" aria-hidden="true"></i> Demo
                                    </button>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                    
                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="?filter=<?php echo $filter; ?>&priority=<?php echo $priority; ?>&page=<?php echo $page - 1; ?>" 
                                   class="btn" 
                                   aria-label="Go to previous page">
                                    <i class="fas fa-chevron-left" aria-hidden="true"></i> Previous
                                </a>
                            <?php endif; ?>
                            
                            <span class="pagination-info">
                                Page <?php echo $page; ?> of <?php echo $totalPages; ?>
                            </span>
                            
                            <?php if ($page < $totalPages): ?>
                                <a href="?filter=<?php echo $filter; ?>&priority=<?php echo $priority; ?>&page=<?php echo $page + 1; ?>" 
                                   class="btn"
                                   aria-label="Go to next page">
                                    Next <i class="fas fa-chevron-right" aria-hidden="true"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                <?php else: ?>
                    <!-- Empty State -->
                    <div class="empty-state">
                        <i class="fas fa-bell-slash" aria-hidden="true"></i>
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
                        <a href="student_view_event.php" class="btn">
                            <i class="fas fa-calendar-plus" aria-hidden="true"></i>
                            Browse Events
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        // Global state management
        const NotificationApp = {
            isLoading: false,
            
            // Initialize the application
            init: function() {
                this.setupEventListeners();
                this.setupKeyboardShortcuts();
                this.setupAccessibility();
                this.animateOnLoad();
                this.logStats();
            },

            // Setup event listeners
            setupEventListeners: function() {
                // Auto-hide mobile menu on resize
                window.addEventListener('resize', function() {
                    const sidebar = document.getElementById('sidebar');
                    if (window.innerWidth > 968) {
                        sidebar.classList.remove('open');
                    }
                });

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

                // Auto-mark unread notifications as read when clicked
                document.querySelectorAll('.notification-item.unread').forEach(item => {
                    item.addEventListener('click', function(e) {
                        if (e.target.closest('.action-btn') || 
                            e.target.closest('.quick-action') || 
                            e.target.closest('select') ||
                            e.target.closest('button')) return;
                        
                        const notificationId = this.dataset.notificationId;
                        if (notificationId && 
                            this.classList.contains('unread') && 
                            !notificationId.includes('demo')) {
                            markAsRead(notificationId);
                        }
                    });
                    
                    if (!item.dataset.notificationId.includes('demo')) {
                        item.style.cursor = 'pointer';
                        item.title = 'Click to mark as read';
                    }
                });
            },

            // Setup keyboard shortcuts
            setupKeyboardShortcuts: function() {
                document.addEventListener('keydown', function(e) {
                    // Alt+M for mark all read
                    if (e.altKey && e.key === 'm') {
                        e.preventDefault();
                        const markAllBtn = document.getElementById('mark-all-btn');
                        if (markAllBtn && !markAllBtn.disabled) {
                            markAllAsRead();
                        }
                    }
                    
                    // Alt+D for dashboard
                    if (e.altKey && e.key === 'd') {
                        e.preventDefault();
                        window.location.href = 'student_dashboard.php';
                    }
                    
                    // Alt+E for events
                    if (e.altKey && e.key === 'e') {
                        e.preventDefault();
                        window.location.href = 'student_view_event.php';
                    }

                    // Escape key to close mobile menu
                    if (e.key === 'Escape') {
                        const sidebar = document.getElementById('sidebar');
                        if (sidebar.classList.contains('open')) {
                            sidebar.classList.remove('open');
                        }
                    }
                });
            },

            // Setup accessibility features
            setupAccessibility: function() {
                // Add keyboard navigation classes
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Tab') {
                        document.body.classList.add('keyboard-navigation');
                    }
                });

                document.addEventListener('mousedown', function() {
                    document.body.classList.remove('keyboard-navigation');
                });
            },

            // Animate elements on load
            animateOnLoad: function() {
                const elements = document.querySelectorAll('.page-header, .filter-section, .notifications-container');
                elements.forEach((el, index) => {
                    el.style.opacity = '0';
                    el.style.transform = 'translateY(20px)';
                    setTimeout(() => {
                        el.style.transition = 'all 0.6s ease';
                        el.style.opacity = '1';
                        el.style.transform = 'translateY(0)';
                    }, index * 100);
                });
            },

            // Log application statistics
            logStats: function() {
                const totalNotifications = document.querySelectorAll('.notification-item').length;
                const unreadNotifications = document.querySelectorAll('.notification-item.unread').length;
                
                console.log('📊 Notification Statistics:', {
                    total: totalNotifications,
                    unread: unreadNotifications,
                    read: totalNotifications - unreadNotifications
                });
            }
        };

        // API request handler with loading states
        async function makeRequest(action, data = {}) {
            if (NotificationApp.isLoading) return;
            
            NotificationApp.isLoading = true;
            
            try {
                const formData = new FormData();
                formData.append('action', action);
                
                Object.keys(data).forEach(key => {
                    formData.append(key, data[key]);
                });

                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const result = await response.json();
                return result;
            } catch (error) {
                console.error('Request failed:', error);
                showToast('Network error occurred. Please try again.', 'error');
                return { success: false, error: error.message };
            } finally {
                NotificationApp.isLoading = false;
            }
        }

        // Mark single notification as read
        async function markAsRead(notificationId) {
            if (String(notificationId).includes('demo')) {
                showToast('Demo notification - cannot mark as read', 'info');
                return;
            }

            const result = await makeRequest('mark_read', { notification_id: notificationId });
            
            if (result.success) {
                const item = document.querySelector(`[data-notification-id="${notificationId}"]`);
                if (item) {
                    item.classList.remove('unread');
                    
                    // Remove NEW indicator
                    const title = item.querySelector('.notification-title');
                    const newIndicator = title.querySelector('::after');
                    if (title) {
                        title.style.position = 'relative';
                        title.style.paddingRight = '0';
                    }
                    
                    // Remove read button
                    const readBtn = item.querySelector('button[onclick*="markAsRead"]');
                    if (readBtn) readBtn.remove();
                    
                    // Add read indicator to meta
                    const meta = item.querySelector('.notification-meta');
                    const readSpan = document.createElement('span');
                    readSpan.innerHTML = '<i class="fas fa-eye" aria-hidden="true"></i> Just read';
                    meta.appendChild(readSpan);
                }
                
                showToast(result.message || 'Notification marked as read', 'success');
                updateUnreadBadge();
            } else {
                showToast(result.error || 'Failed to mark notification as read', 'error');
            }
        }

        // Mark all notifications as read
        async function markAllAsRead() {
            if (!confirm('Mark all notifications as read?')) return;
            
            const result = await makeRequest('mark_all_read');
            
            if (result.success) {
                showToast(result.message || 'All notifications marked as read', 'success');
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showToast(result.error || 'Failed to mark all notifications as read', 'error');
            }
        }

        // Delete notification
        async function deleteNotification(notificationId) {
            if (String(notificationId).includes('demo')) {
                showToast('Demo notification - cannot delete', 'info');
                return;
            }

            if (!confirm('Are you sure you want to delete this notification?')) return;
            
            const result = await makeRequest('delete_notification', { notification_id: notificationId });
            
            if (result.success) {
                const item = document.querySelector(`[data-notification-id="${notificationId}"]`);
                if (item) {
                    item.style.opacity = '0';
                    item.style.transform = 'translateX(-100%)';
                    setTimeout(() => {
                        item.remove();
                        
                        // Check if page is empty
                        const remaining = document.querySelectorAll('.notification-item');
                        if (remaining.length === 0) {
                            setTimeout(() => window.location.reload(), 500);
                        }
                    }, 300);
                }
                
                showToast(result.message || 'Notification deleted', 'success');
                updateUnreadBadge();
            } else {
                showToast(result.error || 'Failed to delete notification', 'error');
            }
        }

        // Set notification priority
        async function setPriority(notificationId, priority) {
            if (String(notificationId).includes('demo')) {
                showToast('Demo notification - cannot change priority', 'info');
                return;
            }

            const result = await makeRequest('set_priority', { 
                notification_id: notificationId, 
                priority: priority 
            });
            
            if (result.success) {
                showToast(result.message || `Priority set to ${priority}`, 'success');
                
                // Update priority display
                const prioritySpan = document.querySelector(`[data-notification-id="${notificationId}"] .notification-meta span:nth-child(3)`);
                if (prioritySpan) {
                    const colors = { High: '#ef4444', Medium: '#f59e0b', Low: '#10b981' };
                    prioritySpan.style.color = colors[priority] || '#64748b';
                    prioritySpan.innerHTML = `<i class="fas fa-flag" aria-hidden="true"></i> ${priority} Priority`;
                }
            } else {
                showToast(result.error || 'Failed to set priority', 'error');
            }
        }

        // Respond to event notification
        async function respondToEvent(notificationId, response) {
            if (String(notificationId).includes('demo')) {
                const responses = {
                    'interested': 'Thanks for your interest! We\'ll keep you updated.',
                    'not_interested': 'No problem! You can change your mind anytime.',
                    'maybe': 'Thanks for considering! Check back anytime for updates.'
                };
                showToast(responses[response] || 'Response recorded', 'success');
                return;
            }

            const result = await makeRequest('respond_to_event', { 
                notification_id: notificationId, 
                response: response 
            });
            
            if (result.success) {
                showToast(result.message, 'success');
                
                // Disable response buttons and mark as read
                const item = document.querySelector(`[data-notification-id="${notificationId}"]`);
                if (item) {
                    const buttons = item.querySelectorAll('.quick-action');
                    buttons.forEach(btn => {
                        if (!btn.href) { // Don't disable link buttons
                            btn.disabled = true;
                            btn.style.opacity = '0.6';
                        }
                    });
                    
                    // Add response indicator
                    const quickActions = item.querySelector('.quick-actions');
                    const responseDiv = document.createElement('div');
                    responseDiv.className = 'response-indicator';
                    responseDiv.style.cssText = 'margin-top: 12px; padding: 8px 12px; background: rgba(16, 185, 129, 0.1); border-radius: 8px; color: #059669; font-size: 12px; font-weight: 600;';
                    responseDiv.innerHTML = `<i class="fas fa-check-circle" aria-hidden="true"></i> Response: ${response.replace('_', ' ')}`;
                    quickActions.appendChild(responseDiv);
                }
                
                updateUnreadBadge();
            } else {
                showToast(result.error || 'Failed to record response', 'error');
            }
        }

        // Show event details (for demo notifications)
        function showEventDetails(eventTitle) {
            if (!eventTitle) {
                showToast('No event details available', 'info');
                return;
            }
            
            const modal = document.createElement('div');
            modal.style.cssText = `
                position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
                background: rgba(0, 0, 0, 0.5); z-index: 10000; 
                display: flex; align-items: center; justify-content: center;
                backdrop-filter: blur(5px);
            `;
            
            modal.innerHTML = `
                <div style="background: white; padding: 32px; border-radius: 20px; max-width: 500px; margin: 20px; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);">
                    <h3 style="margin-bottom: 16px; color: #2d3748; font-size: 20px;">Event Details</h3>
                    <p style="margin-bottom: 24px; color: #4a5568; line-height: 1.6;">${eventTitle}</p>
                    <p style="margin-bottom: 24px; color: #718096; font-size: 14px;">For complete event information, registration, and updates, please visit the Events page.</p>
                    <div style="display: flex; gap: 12px; justify-content: flex-end;">
                        <button onclick="this.closest('div').parentElement.remove()" 
                                style="padding: 8px 16px; background: #e2e8f0; border: none; border-radius: 8px; cursor: pointer;">
                            Close
                        </button>
                        <a href="student_view_event.php" 
                           style="padding: 8px 16px; background: linear-gradient(135deg, #667eea, #764ba2); color: white; text-decoration: none; border-radius: 8px;">
                            View Events
                        </a>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
            
            // Close on outside click
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    modal.remove();
                }
            });
        }

        // Mobile sidebar toggle
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('open');
        }

        // Toast notification system
        function showToast(message, type = 'info') {
            const existingToast = document.querySelector('.toast');
            if (existingToast) {
                existingToast.remove();
            }

            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            
            const icons = {
                success: 'check-circle',
                error: 'exclamation-circle',
                info: 'info-circle'
            };
            
            toast.innerHTML = `
                <i class="fas fa-${icons[type] || 'info-circle'}" aria-hidden="true"></i>
                <span>${message}</span>
            `;
            
            document.body.appendChild(toast);
            
            // Show toast
            setTimeout(() => toast.classList.add('show'), 100);
            
            // Hide toast
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => {
                    if (document.body.contains(toast)) {
                        document.body.removeChild(toast);
                    }
                }, 400);
            }, 4000);
        }

        // Update unread badge count
        function updateUnreadBadge() {
            const unreadCount = document.querySelectorAll('.notification-item.unread').length;
            const badge = document.getElementById('unread-badge');
            
            if (badge) {
                if (unreadCount > 0) {
                    badge.textContent = unreadCount;
                    badge.style.display = 'inline-block';
                } else {
                    badge.style.display = 'none';
                }
            }
        }

        // Performance monitoring
        window.addEventListener('load', function() {
            const loadTime = performance.now();
            console.log(`⚡ Notifications page loaded in ${Math.round(loadTime)}ms`);
            
            const totalNotifications = document.querySelectorAll('.notification-item').length;
            
            if (totalNotifications > 0) {
                setTimeout(() => {
                    showToast(`📬 ${totalNotifications} notification${totalNotifications > 1 ? 's' : ''} loaded successfully!`, 'success');
                }, 1000);
            }
        });

        // Initialize the application
        document.addEventListener('DOMContentLoaded', function() {
            NotificationApp.init();
            console.log('🔔 LifeSaver Hub Notification System Initialized');
            console.log('⌨️ Keyboard shortcuts: Alt+M (mark all read), Alt+D (dashboard), Alt+E (events), ESC (close menu)');
        });

        // Export functions for console access (development/debugging)
        window.NotificationApp = NotificationApp;
        window.showToast = showToast;
        window.markAsRead = markAsRead;
        window.markAllAsRead = markAllAsRead;
        window.deleteNotification = deleteNotification;
        window.setPriority = setPriority;
        window.respondToEvent = respondToEvent;
    </script>
</body>
</html>