<?php
session_start();

if (!isset($_SESSION['staff_id'])) {
    header("Location: staff_login.php");
    exit;
}

$pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

// Fetch staff data
$stmt = $pdo->prepare("SELECT * FROM staff WHERE StaffID = ?");
$stmt->execute([$_SESSION['staff_id']]);
$staff = $stmt->fetch();

// Check if current user is super staff (StaffID = 1 for Adli)
$isSuperStaff = ($_SESSION['staff_id'] == 1);

// Get current time for greeting
$hour = date('H');
if ($hour < 12) {
    $greeting = "Good Morning";
} elseif ($hour < 17) {
    $greeting = "Good Afternoon";
} else {
    $greeting = "Good Evening";
}

// Fetch real statistics from database
try {
    // Count active events
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM events WHERE EventDate >= CURDATE()");
    $activeEvents = $stmt->fetch()['count'] ?? 0;
    
    // Count total donations
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM donations");
    $totalDonations = $stmt->fetch()['count'] ?? 0;
    
    // Count confirmed attendees
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM registrations WHERE Status = 'Confirmed'");
    $confirmedAttendees = $stmt->fetch()['count'] ?? 0;
    
    // Count pending applications
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM applications WHERE Status = 'Pending'");
    $pendingApplications = $stmt->fetch()['count'] ?? 0;
    
    // Count total staff (for super staff)
    if ($isSuperStaff) {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM staff");
        $totalStaff = $stmt->fetch()['count'] ?? 0;
    }
} catch (Exception $e) {
    // Fallback to 0 if tables don't exist yet
    $activeEvents = 0;
    $totalDonations = 0;
    $confirmedAttendees = 0;
    $pendingApplications = 0;
    $totalStaff = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Dashboard - LifeSaver Hub</title>
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

        /* Super Staff Badge */
        .super-staff-badge {
            background: linear-gradient(135deg, #f093fb, #f5576c);
            color: white;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-left: auto;
            display: inline-block;
            box-shadow: 0 4px 15px rgba(240, 147, 251, 0.3);
            animation: glow 2s ease-in-out infinite alternate;
        }

        @keyframes glow {
            from { box-shadow: 0 4px 15px rgba(240, 147, 251, 0.3); }
            to { box-shadow: 0 6px 20px rgba(240, 147, 251, 0.5); }
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
            display: flex;
            align-items: center;
            gap: 8px;
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
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .page-header .greeting {
            color: #667eea;
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .page-header .subtitle {
            color: #4a5568;
            font-size: 18px;
            font-weight: 400;
        }

        .stats-bar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 25px;
            margin-bottom: 32px;
        }

        .stat-item {
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

        .stat-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea, #764ba2);
            border-radius: 20px 20px 0 0;
        }

        .stat-item:hover {
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

        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 30px;
            margin-top: 32px;
        }

        .card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 32px;
            border: 1px solid rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
        }

        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea, #764ba2);
            border-radius: 24px 24px 0 0;
        }

        .card:hover {
            transform: translateY(-10px);
            box-shadow: 0 25px 50px rgba(102, 126, 234, 0.15);
        }

        .card-header {
            display: flex;
            align-items: center;
            margin-bottom: 24px;
        }

        .card-icon {
            width: 60px;
            height: 60px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 20px;
            font-size: 28px;
            color: white;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
            transition: all 0.3s ease;
        }

        .card:hover .card-icon {
            transform: scale(1.1) rotate(5deg);
        }

        .card-icon.account { background: linear-gradient(135deg, #667eea, #764ba2); }
        .card-icon.events { background: linear-gradient(135deg, #f093fb, #f5576c); }
        .card-icon.donations { background: linear-gradient(135deg, #4facfe, #00f2fe); }
        .card-icon.rewards { background: linear-gradient(135deg, #43e97b, #38f9d7); }
        .card-icon.super-admin { background: linear-gradient(135deg, #ff9a9e, #fad0c4); }

        .card h3 {
            color: #2d3748;
            font-size: 24px;
            font-weight: 700;
            margin: 0;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .card-description {
            color: #4a5568;
            font-size: 16px;
            margin-bottom: 24px;
            line-height: 1.6;
            font-weight: 500;
        }

        .card-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            padding: 12px 20px;
            background: rgba(102, 126, 234, 0.1);
            color: #667eea;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s ease;
            border: 2px solid rgba(102, 126, 234, 0.2);
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
            background: linear-gradient(90deg, transparent, rgba(102, 126, 234, 0.1), transparent);
            transition: left 0.5s;
        }

        .btn:hover::before {
            left: 100%;
        }

        .btn:hover {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
            border-color: transparent;
        }

        .btn i {
            margin-right: 8px;
            font-size: 14px;
        }

        .btn.super-staff {
            background: linear-gradient(135deg, rgba(240, 147, 251, 0.1), rgba(245, 87, 108, 0.1));
            color: #f093fb;
            border-color: rgba(240, 147, 251, 0.3);
        }

        .btn.super-staff:hover {
            background: linear-gradient(135deg, #f093fb, #f5576c);
            color: white;
            box-shadow: 0 8px 25px rgba(240, 147, 251, 0.4);
        }

        /* Mobile menu button */
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
            
            .cards-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-bar {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .page-header h1 {
                font-size: 2rem;
            }
        }

        @media (max-width: 480px) {
            .stats-bar {
                grid-template-columns: 1fr;
            }
            
            .page-header {
                padding: 25px;
            }
            
            .card {
                padding: 20px;
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
            background: linear-gradient(135deg, #764ba2, #667eea);
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

        .container {
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
        <!-- Enhanced Sidebar -->
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
                        <a href="staff_dashboard.php" class="nav-item active"> 
                            <i class="fas fa-home"></i>
                            <span>Dashboard</span>
                        </a>
                        <a href="create_event.php" class="nav-item">
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
                    
                    <?php if ($isSuperStaff): ?>
                    <div class="nav-section">
                        <div class="nav-section-title">Super Staff</div>
                        <a href="create_staff_account.php" class="nav-item"> 
                            <i class="fas fa-user-plus"></i>
                            <span>Create Staff Account</span>
                        </a>
                    </div>
                    <?php endif; ?>
                    
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
                        <h4>
                            <?php echo htmlspecialchars($staff['StaffName']); ?>
                            <?php if ($isSuperStaff): ?>
                                <span class="super-staff-badge">
                                    <i class="fas fa-crown"></i> SUPER
                                </span>
                            <?php endif; ?>
                        </h4>
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
                    <div class="greeting"><?= $greeting ?>,</div>
                    <h1>
                        <?= htmlspecialchars($staff['StaffName']) ?>!
                        <?php if ($isSuperStaff): ?>
                            <i class="fas fa-crown" style="color: #f093fb; font-size: 2rem;"></i>
                        <?php endif; ?>
                    </h1>
                    <div class="subtitle">
                        Welcome back to your dashboard. 
                        <?php if ($isSuperStaff): ?>
                            <span style="color: #f093fb; font-weight: 700;">You have super staff privileges.</span>
                        <?php else: ?>
                            Ready to make a difference today?
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Management Cards -->
            <div class="cards-grid">
                <div class="card">
                    <div class="card-header">
                        <div class="card-icon account">
                            <i class="fas fa-user-cog"></i>
                        </div>
                        <h3>Account Management</h3>
                    </div>
                    <div class="card-description">
                        Manage your personal information, preferences, and account settings.
                    </div>
                    <div class="card-actions">
                        <a href="staff_account.php" class="btn">
                            <i class="fas fa-eye"></i>
                            View Profile
                        </a>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div class="card-icon events">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <h3>Event Management</h3>
                    </div>
                    <div class="card-description">
                        Create, manage, and oversee blood donation events and campaigns.
                    </div>
                    <div class="card-actions">
                        <a href="create_event.php" class="btn">
                            <i class="fas fa-plus"></i>
                            Create Event
                        </a>
                        <a href="staff_view_event.php" class="btn">
                            <i class="fas fa-list"></i>
                            View Events
                        </a>
                        <a href="update_event.php" class="btn">
                            <i class="fas fa-edit"></i>
                            Update Event
                        </a>
                        <a href="delete_event.php" class="btn">
                            <i class="fas fa-trash"></i>
                            Delete Event
                        </a>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div class="card-icon donations">
                            <i class="fas fa-heartbeat"></i>
                        </div>
                        <h3>Donation Management</h3>
                    </div>
                    <div class="card-description">
                        Track donations, confirm attendance, and manage donor applications.
                    </div>
                    <div class="card-actions">
                        <a href="staff_view_donation.php" class="btn">
                            <i class="fas fa-eye"></i>
                            View Donations
                        </a>
                        <a href="confirm_attendance.php" class="btn">
                            <i class="fas fa-check"></i>
                            Confirm Attendance
                        </a>
                        <a href="update_donor_application.php" class="btn">
                            <i class="fas fa-clipboard-check"></i>
                            Update Applications
                        </a>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div class="card-icon rewards">
                            <i class="fas fa-trophy"></i>
                        </div>
                        <h3>Rewards & Reports</h3>
                    </div>
                    <div class="card-description">
                        Create donor rewards and generate comprehensive reports and analytics.
                    </div>
                    <div class="card-actions">
                        <a href="create_reward.php" class="btn">
                            <i class="fas fa-gift"></i>
                            Create Rewards
                        </a>
                        <a href="generate_report.php" class="btn">
                            <i class="fas fa-chart-bar"></i>
                            Generate Report
                        </a>
                    </div>
                </div>

                <?php if ($isSuperStaff): ?>
                <div class="card">
                    <div class="card-header">
                        <div class="card-icon super-admin">
                            <i class="fas fa-crown"></i>
                        </div>
                        <h3>Super Staff Management</h3>
                    </div>
                    <div class="card-description">
                        Exclusive super staff features: create staff accounts, system administration.
                    </div>
                    <div class="card-actions">
                        <a href="create_staff_account.php" class="btn super-staff">
                            <i class="fas fa-user-plus"></i>
                            Create Staff Account
                        </a>
                    </div>
                </div>
                <?php endif; ?>
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

        // Add entrance animations
        document.addEventListener('DOMContentLoaded', function() {
            // Animate elements on page load
            const elements = document.querySelectorAll('.page-header, .stat-item, .card');
            elements.forEach((el, index) => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    el.style.transition = 'all 0.6s ease';
                    el.style.opacity = '1';
                    el.style.transform = 'translateY(0)';
                }, index * 100);
            });

            // Add hover effects to stat items
            document.querySelectorAll('.stat-item').forEach(item => {
                item.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-8px) scale(1.05)';
                });
                
                item.addEventListener('mouseleave', function() {
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

            // Add loading animation to page transitions
            document.querySelectorAll('a:not([href^="#"])').forEach(link => {
                link.addEventListener('click', function() {
                    if (!this.href.includes('javascript:')) {
                        document.body.style.opacity = '0.7';
                        document.body.style.pointerEvents = 'none';
                    }
                });
            });

            // Update stat numbers with animation
            document.querySelectorAll('.stat-number').forEach(stat => {
                const finalValue = parseInt(stat.textContent);
                let currentValue = 0;
                const increment = Math.ceil(finalValue / 20);
                const timer = setInterval(() => {
                    currentValue += increment;
                    if (currentValue >= finalValue) {
                        currentValue = finalValue;
                        clearInterval(timer);
                    }
                    stat.textContent = currentValue;
                }, 50);
            });

            // Real-time clock update for greeting
            function updateGreeting() {
                const hour = new Date().getHours();
                let greeting;
                if (hour < 12) {
                    greeting = "Good Morning";
                } else if (hour < 17) {
                    greeting = "Good Afternoon";
                } else {
                    greeting = "Good Evening";
                }
                
                const greetingElement = document.querySelector('.greeting');
                if (greetingElement && greetingElement.textContent !== greeting + ',') {
                    greetingElement.textContent = greeting + ',';
                }
            }

            // Update greeting every minute
            updateGreeting();
            setInterval(updateGreeting, 60000);

            // Add smooth scroll for better user experience
            document.documentElement.style.scrollBehavior = 'smooth';

            // Console logging for debugging
            console.log('👨‍💼 Staff Dashboard Loaded');
            console.log('📊 Active Events:', <?= $activeEvents ?>);
            console.log('🩸 Total Donations:', <?= $totalDonations ?>);
            console.log('✅ Confirmed Attendees:', <?= $confirmedAttendees ?>);
            console.log('⏳ Pending Applications:', <?= $pendingApplications ?>);
            <?php if ($isSuperStaff): ?>
            console.log('👑 Super Staff Access Granted');
            console.log('👥 Total Staff:', <?= $totalStaff ?>);
            <?php endif; ?>
            console.log('🎯 Enhanced Staff Dashboard Active!');
        });

        // Keyboard shortcuts for accessibility
        document.addEventListener('keydown', function(e) {
            // Alt+D for dashboard
            if (e.altKey && e.key === 'd') {
                e.preventDefault();
                window.location.href = 'staff_dashboard.php';
            }
            
            // Alt+E for events
            if (e.altKey && e.key === 'e') {
                e.preventDefault();
                window.location.href = 'staff_view_event.php';
            }
            
            // Alt+P for profile
            if (e.altKey && e.key === 'p') {
                e.preventDefault();
                window.location.href = 'staff_account.php';
            }
            
            <?php if ($isSuperStaff): ?>
            // Alt+S for super staff functions
            if (e.altKey && e.key === 's') {
                e.preventDefault();
                window.location.href = 'create_staff_account.php';
            }
            <?php endif; ?>
        });

        // Add interactive card animations
        document.querySelectorAll('.card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                const icon = this.querySelector('.card-icon');
                if (icon) {
                    icon.style.transform = 'scale(1.1) rotate(5deg)';
                }
            });
            
            card.addEventListener('mouseleave', function() {
                const icon = this.querySelector('.card-icon');
                if (icon) {
                    icon.style.transform = 'scale(1) rotate(0deg)';
                }
            });
        });

        // Performance monitoring
        window.addEventListener('load', function() {
            const loadTime = performance.now();
            console.log(`⚡ Page loaded in ${Math.round(loadTime)}ms`);
            
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
                
                <?php if ($isSuperStaff): ?>
                notification.innerHTML = '👑 Super Staff Dashboard Ready!';
                notification.style.background = 'linear-gradient(135deg, #f093fb, #f5576c)';
                <?php else: ?>
                notification.innerHTML = '✅ Dashboard Ready!';
                <?php endif; ?>
                
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
                }, 3000);
            }, 500);
        });

        // Add dynamic time display
        function updateTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('en-US', { 
                hour12: true, 
                hour: 'numeric', 
                minute: '2-digit' 
            });
        }

        // Update time every second
        updateTime();
        setInterval(updateTime, 1000);

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
            .keyboard-navigation .btn:focus {
                outline: 3px solid #667eea;
                outline-offset: 2px;
            }
        `;
        document.head.appendChild(keyboardStyle);

        // Special effects for super staff
        <?php if ($isSuperStaff): ?>
        // Add crown animation
        const crown = document.querySelector('.fas.fa-crown');
        if (crown) {
            setInterval(() => {
                crown.style.transform = 'scale(1.1) rotate(5deg)';
                setTimeout(() => {
                    crown.style.transform = 'scale(1) rotate(0deg)';
                }, 200);
            }, 3000);
        }

        // Add super staff welcome message
        setTimeout(() => {
            console.log('👑 Welcome Super Staff! You have elevated privileges.');
            console.log('🔧 Available Super Staff Features:');
            console.log('   • Create Staff Accounts');
            console.log('   • Manage All Staff');
            console.log('   • System Administration');
        }, 1000);
        <?php endif; ?>
    </script>
</body>
</html>