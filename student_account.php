<?php
session_start();

if (!isset($_SESSION['staff_id'])) {
    header("Location: staff_login.php");
    exit;
}

$pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$staffId = $_SESSION['staff_id'];

// Get staff information
$stmt = $pdo->prepare("SELECT * FROM staff WHERE StaffID = ?");
$stmt->execute([$staffId]);
$staff = $stmt->fetch();

// Check if event ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: staff_manage_events.php");
    exit;
}

$eventId = $_GET['id'];

// Get event details with registration count
$stmt = $pdo->prepare("
    SELECT e.*, 
    (SELECT COUNT(*) FROM registration r WHERE r.EventID = e.EventID AND r.RegistrationStatus != 'Cancelled') as TotalRegistered
    FROM event e 
    WHERE e.EventID = ?
");
$stmt->execute([$eventId]);
$event = $stmt->fetch();

if (!$event) {
    $_SESSION['error'] = "Event not found.";
    header("Location: staff_manage_events.php");
    exit;
}

// Get event registrations
$regStmt = $pdo->prepare("
    SELECT r.*, d.DonorName, d.DonorEmail, d.DonorPhone 
    FROM registration r 
    JOIN donor d ON r.DonorID = d.DonorID 
    WHERE r.EventID = ? 
    ORDER BY r.RegistrationDate DESC
");
$regStmt->execute([$eventId]);
$registrations = $regStmt->fetchAll();

// Get registration statistics
$statsStmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_registrations,
        SUM(CASE WHEN RegistrationStatus = 'Confirmed' THEN 1 ELSE 0 END) as confirmed_registrations,
        SUM(CASE WHEN RegistrationStatus = 'Pending' THEN 1 ELSE 0 END) as pending_registrations,
        SUM(CASE WHEN RegistrationStatus = 'Cancelled' THEN 1 ELSE 0 END) as cancelled_registrations
    FROM registration 
    WHERE EventID = ?
");
$statsStmt->execute([$eventId]);
$regStats = $statsStmt->fetch();

// Format event date for display
$eventDate = new DateTime($event['EventDate']);
$today = new DateTime();
$isToday = $eventDate->format('Y-m-d') === $today->format('Y-m-d');
$isPast = $eventDate < $today;
$isFuture = $eventDate > $today;

// Determine date badge class
$dateBadgeClass = 'upcoming';
if ($isToday) {
    $dateBadgeClass = 'today';
} elseif ($isPast) {
    $dateBadgeClass = 'past';
}

// Check for success/error messages
$success = isset($_SESSION['success']) ? $_SESSION['success'] : '';
$error = isset($_SESSION['error']) ? $_SESSION['error'] : '';
unset($_SESSION['success'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Event - <?php echo htmlspecialchars($event['EventName']); ?> - LifeSaver Hub</title>
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

        .page-header-content {
            position: relative;
            z-index: 1;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 20px;
        }

        .page-header h1 {
            font-size: 2.5rem;
            font-weight: 800;
            color: white;
            display: flex;
            align-items: center;
            gap: 20px;
            text-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
            margin: 0 0 10px 0;
        }

        .page-header h1 i {
            background: linear-gradient(135deg, #f093fb, #00d2d3);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-size: 2rem;
        }

        .page-header .event-meta {
            color: rgba(255, 255, 255, 0.9);
            font-size: 16px;
            margin-bottom: 15px;
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
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

        .btn-warning {
            background: linear-gradient(135deg, #feca57, #ff9f43);
            color: white;
            box-shadow: 0 8px 25px rgba(254, 202, 87, 0.4);
        }

        .btn-warning:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 15px 35px rgba(254, 202, 87, 0.6);
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

        .btn-success {
            background: linear-gradient(135deg, #10ac84, #00d2d3);
            color: white;
            box-shadow: 0 8px 25px rgba(16, 172, 132, 0.4);
        }

        .btn-success:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 15px 35px rgba(16, 172, 132, 0.6);
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

        .event-details {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }

        .event-info {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(25px);
            border-radius: 25px;
            padding: 40px;
            box-shadow: 0 15px 50px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .event-stats {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(25px);
            border-radius: 25px;
            padding: 30px;
            box-shadow: 0 15px 50px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .info-item {
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .info-item:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }

        .info-label {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
            color: white;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 8px;
        }

        .info-value {
            color: rgba(255, 255, 255, 0.9);
            font-size: 16px;
            line-height: 1.5;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 8px 16px;
            border-radius: 25px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
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

        .stat-item {
            text-align: center;
            margin-bottom: 25px;
            padding: 20px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 15px;
            transition: all 0.3s ease;
        }

        .stat-item:hover {
            transform: translateY(-5px);
            background: rgba(255, 255, 255, 0.2);
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 800;
            color: white;
            margin-bottom: 5px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        .stat-label {
            color: rgba(255, 255, 255, 0.8);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }

        .registrations-section {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(25px);
            border-radius: 25px;
            box-shadow: 0 15px 50px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
            overflow: hidden;
        }

        .section-header {
            padding: 30px 35px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.05));
        }

        .section-header h3 {
            font-size: 1.8rem;
            font-weight: 700;
            color: white;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 15px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        .section-header h3 i {
            background: linear-gradient(135deg, #f093fb, #00d2d3);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .registrations-table {
            width: 100%;
            border-collapse: collapse;
        }

        .registrations-table th,
        .registrations-table td {
            padding: 20px 25px;
            text-align: left;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .registrations-table th {
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

        .registrations-table th i {
            margin-right: 8px;
            opacity: 0.8;
        }

        .registrations-table tr {
            transition: all 0.3s ease;
        }

        .registrations-table tr:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .registrations-table td {
            color: white;
            font-weight: 500;
            vertical-align: top;
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
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.4;
            background: linear-gradient(135deg, #f093fb, #00d2d3);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .empty-state h4 {
            font-size: 1.5rem;
            margin-bottom: 10px;
            color: white;
            font-weight: 700;
        }

        .empty-state p {
            font-size: 16px;
            opacity: 0.8;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px;
            }
            
            .sidebar {
                transform: translateX(-100%);
            }
            
            .event-details {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <h2>LifeSaver Hub</h2>
            <p>Staff Dashboard</p>
        </div>
        <nav class="sidebar-nav">
            <a href="staff_dashboard.php">
                <i class="fas fa-home"></i>
                Dashboard
            </a>
            <a href="staff_manage_events.php" class="active">
                <i class="fas fa-calendar-alt"></i>
                Manage Events
            </a>
            <a href="staff_manage_donors.php">
                <i class="fas fa-users"></i>
                Manage Donors
            </a>
            <a href="staff_reports.php">
                <i class="fas fa-chart-bar"></i>
                Reports
            </a>
            <a href="staff_profile.php">
                <i class="fas fa-user"></i>
                Profile
            </a>
            <a href="staff_logout.php">
                <i class="fas fa-sign-out-alt"></i>
                Logout
            </a>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Success/Error Messages -->
        <?php if (!empty($success)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-content">
                <div>
                    <h1>
                        <i class="fas fa-eye"></i>
                        <?php echo htmlspecialchars($event['EventTitle']); ?>
                    </h1>
                    <div class="event-meta">
                        <span class="badge <?php echo $dateBadgeClass; ?>">
                            <i class="fas fa-calendar"></i>
                            <?php echo $eventDate->format('F j, Y'); ?>
                        </span>
                        <span class="badge <?php echo strtolower($event['EventStatus']); ?>">
                            <i class="fas fa-info-circle"></i>
                            <?php echo htmlspecialchars($event['EventStatus']); ?>
                        </span>
                    </div>
                </div>
                <div class="action-buttons">
                    <a href="staff_manage_events.php" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i>
                        Back to Events
                    </a>
                    <?php if ($event['EventStatus'] !== 'Deleted'): ?>
                        <a href="update_event.php?id=<?php echo $eventId; ?>" class="btn btn-warning">
                            <i class="fas fa-edit"></i>
                            Edit Event
                        </a>
                        <button onclick="confirmDelete(<?php echo $eventId; ?>)" class="btn btn-danger">
                            <i class="fas fa-trash"></i>
                            Delete Event
                        </button>
                    <?php else: ?>
                        <form method="POST" action="delete_event.php" style="display: inline;">
                            <input type="hidden" name="event_id" value="<?php echo $eventId; ?>">
                            <input type="hidden" name="action" value="restore">
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-undo"></i>
                                Restore Event
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Event Details Grid -->
        <div class="event-details">
            <!-- Event Information -->
            <div class="event-info">
                <div class="info-item">
                    <div class="info-label">
                        <i class="fas fa-align-left"></i>
                        Description
                    </div>
                    <div class="info-value">
                        <?php echo nl2br(htmlspecialchars($event['EventDescription'])); ?>
                    </div>
                </div>

                <div class="info-item">
                    <div class="info-label">
                        <i class="fas fa-calendar-day"></i>
                        Event Date & Day
                    </div>
                    <div class="info-value">
                        <?php echo $eventDate->format('l, F j, Y'); ?>
                        <?php if (!empty($event['EventDay'])): ?>
                            (<?php echo htmlspecialchars($event['EventDay']); ?>)
                        <?php endif; ?>
                    </div>
                </div>

                <div class="info-item">
                    <div class="info-label">
                        <i class="fas fa-map-marker-alt"></i>
                        Venue
                    </div>
                    <div class="info-value">
                        <?php echo htmlspecialchars($event['EventVenue']); ?>
                    </div>
                </div>

                <div class="info-item">
                    <div class="info-label">
                        <i class="fas fa-user-tie"></i>
                        Assigned Staff ID
                    </div>
                    <div class="info-value">
                        <?php 
                        if (!empty($event['StaffID'])) {
                            echo 'Staff ID: ' . htmlspecialchars($event['StaffID']);
                        } else {
                            echo 'Not assigned';
                        }
                        ?>
                    </div>
                </div>

                <div class="info-item">
                    <div class="info-label">
                        <i class="fas fa-info-circle"></i>
                        Current Status
                    </div>
                    <div class="info-value">
                        <span class="badge <?php echo strtolower($event['EventStatus']); ?>">
                            <?php echo htmlspecialchars($event['EventStatus']); ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Event Statistics -->
            <div class="event-stats">
                <div class="stat-item">
                    <div class="stat-number"><?php echo number_format($event['TotalRegistered']); ?></div>
                    <div class="stat-label">Total Registered</div>
                </div>

                <div class="stat-item">
                    <div class="stat-number"><?php echo number_format($regStats['confirmed_registrations']); ?></div>
                    <div class="stat-label">Confirmed</div>
                </div>

                <div class="stat-item">
                    <div class="stat-number"><?php echo number_format($regStats['pending_registrations']); ?></div>
                    <div class="stat-label">Pending</div>
                </div>

                <div class="stat-item">
                    <div class="stat-number"><?php echo number_format($regStats['cancelled_registrations']); ?></div>
                    <div class="stat-label">Cancelled</div>
                </div>
            </div>
        </div>

        <!-- Event Registrations -->
        <div class="registrations-section">
            <div class="section-header">
                <h3>
                    <i class="fas fa-user-plus"></i>
                    Event Registrations
                </h3>
            </div>

            <?php if (count($registrations) > 0): ?>
                <table class="registrations-table">
                    <thead>
                        <tr>
                            <th><i class="fas fa-user"></i> Donor Name</th>
                            <th><i class="fas fa-envelope"></i> Email</th>
                            <th><i class="fas fa-phone"></i> Phone</th>
                            <th><i class="fas fa-calendar"></i> Registration Date</th>
                            <th><i class="fas fa-info-circle"></i> Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($registrations as $registration): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($registration['DonorName']); ?></strong>
                                </td>
                                <td><?php echo htmlspecialchars($registration['DonorEmail']); ?></td>
                                <td><?php echo htmlspecialchars($registration['DonorPhone']); ?></td>
                                <td>
                                    <?php 
                                    $regDate = new DateTime($registration['RegistrationDate']);
                                    echo $regDate->format('M j, Y g:i A'); 
                                    ?>
                                </td>
                                <td>
                                    <span class="badge <?php echo strtolower($registration['RegistrationStatus']); ?>">
                                        <?php echo htmlspecialchars($registration['RegistrationStatus']); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-user-slash"></i>
                    <h4>No Registrations Yet</h4>
                    <p>This event doesn't have any registrations yet.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <script>
        function confirmDelete(eventId) {
            if (confirm('Are you sure you want to delete this event? This action will mark the event as deleted but can be restored later.')) {
                // Create a form and submit it
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'delete_event.php';
                
                const eventIdInput = document.createElement('input');
                eventIdInput.type = 'hidden';
                eventIdInput.name = 'event_id';
                eventIdInput.value = eventId;
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete';
                
                form.appendChild(eventIdInput);
                form.appendChild(actionInput);
                document.body.appendChild(form);
                form.submit();
            }
        }

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