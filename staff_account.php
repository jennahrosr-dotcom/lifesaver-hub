<?php
session_start();

// Check if staff is logged in
if (!isset($_SESSION['staff_id'])) {
    header("Location: staff_login.php");
    exit;
}

// Database connection with error handling
try {
    $pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Fetch staff information
try {
    $stmt = $pdo->prepare("SELECT * FROM staff WHERE StaffID = ?");
    $stmt->execute([$_SESSION['staff_id']]);
    $staff = $stmt->fetch();
    
    if (!$staff) {
        session_destroy();
        header("Location: staff_login.php");
        exit;
    }
} catch (PDOException $e) {
    die("Error fetching staff data: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Account - LifeSaver Hub</title>
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

        /* Enhanced Sidebar - Exact same as dashboard */
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
            text-align: center;
        }

        .page-header h1 {
            font-size: 3rem;
            font-weight: 900;
            color: #2d3748;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
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
            margin-bottom: 20px;
        }

        .staff-badge {
            display: inline-block;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(139, 92, 246, 0.1));
            color: #667eea;
            padding: 12px 24px;
            border-radius: 20px;
            font-size: 16px;
            font-weight: 600;
            border: 2px solid rgba(102, 126, 234, 0.2);
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .staff-badge:hover {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            transform: scale(1.05);
        }

        .staff-badge i {
            margin-right: 8px;
        }

        .account-container {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .account-details {
            padding: 40px;
        }

        .detail-row {
            display: flex;
            align-items: center;
            padding: 24px 0;
            border-bottom: 1px solid rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            border-radius: 0;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-row:hover {
            background: rgba(248, 249, 250, 0.8);
            margin: 0 -20px;
            padding: 24px 20px;
            border-radius: 12px;
            transform: translateX(5px);
        }

        .detail-icon {
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(139, 92, 246, 0.1));
            border-radius: 16px;
            color: #667eea;
            font-size: 24px;
            margin-right: 20px;
            transition: all 0.3s ease;
        }

        .detail-row:hover .detail-icon {
            transform: scale(1.1) rotate(5deg);
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
        }

        .detail-content {
            flex: 1;
        }

        .detail-label {
            font-weight: 700;
            color: #4a5568;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 6px;
        }

        .detail-value {
            color: #2d3748;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .detail-value:hover {
            color: #667eea;
        }

        .password-value {
            font-family: 'Courier New', monospace;
            background: rgba(248, 249, 250, 0.8);
            padding: 8px 16px;
            border-radius: 8px;
            letter-spacing: 2px;
            font-size: 16px;
            cursor: default;
        }

        .password-value:hover {
            color: #2d3748;
        }

        .action-buttons {
            padding: 40px;
            text-align: center;
            background: rgba(248, 249, 250, 0.8);
            border-top: 1px solid rgba(0, 0, 0, 0.08);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            padding: 16px 32px;
            margin: 0 12px;
            text-decoration: none;
            border-radius: 16px;
            font-weight: 700;
            font-size: 16px;
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            cursor: pointer;
            border: none;
            position: relative;
            overflow: hidden;
            text-transform: uppercase;
            letter-spacing: 1px;
            min-width: 180px;
            justify-content: center;
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

        .btn-secondary {
            background: linear-gradient(135deg, #6c757d, #495057);
            color: white;
            box-shadow: 0 8px 25px rgba(108, 117, 125, 0.4);
        }

        .btn-secondary:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 15px 35px rgba(108, 117, 125, 0.6);
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
            
            .detail-row {
                flex-direction: column;
                text-align: center;
                gap: 12px;
            }
            
            .detail-icon {
                margin-right: 0;
            }
            
            .page-header h1 {
                font-size: 2rem;
                flex-direction: column;
                gap: 10px;
            }
            
            .action-buttons {
                display: flex;
                flex-direction: column;
                gap: 15px;
                align-items: center;
            }
            
            .btn {
                margin: 0;
                width: 100%;
                max-width: 300px;
            }
        }

        @media (max-width: 480px) {
            .account-details {
                padding: 20px;
            }
            .page-header {
                padding: 25px;
            }
            .action-buttons {
                padding: 25px;
            }
        }

        /* Security Indicator */
        .security-indicator {
            margin-top: 8px;
            font-size: 12px;
            color: #667eea;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        /* Copy hint */
        .copy-hint {
            opacity: 0;
            font-size: 11px;
            color: #9ca3af;
            margin-top: 4px;
            transition: opacity 0.3s ease;
        }

        .detail-value:hover + .copy-hint {
            opacity: 1;
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

        /* Toast Notification Styles */
        .toast {
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
        }

        .toast.show {
            opacity: 1;
            transform: translateX(0);
        }

        .toast.success {
            background: linear-gradient(135deg, #10b981, #059669);
        }

        .toast.error {
            background: linear-gradient(135deg, #ef4444, #dc2626);
        }

        /* Status Indicator */
        .status-indicator {
            position: absolute;
            bottom: 2px;
            right: 2px;
            width: 12px;
            height: 12px;
            background: #10b981;
            border: 2px solid white;
            border-radius: 50%;
            box-shadow: 0 0 8px rgba(16, 185, 129, 0.4);
        }
    </style>
</head>
<body>
    <!-- Mobile menu button -->
    <button class="mobile-menu-btn" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>

    <div class="app-container">
        <!-- Enhanced Sidebar - Identical to dashboard -->
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
                        <a href="staff_account.php" class="nav-item active"> 
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
                        <div class="status-indicator"></div>
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
                    <h1>
                        <i class="fas fa-user-circle"></i> 
                        My Account
                    </h1>
                    <p>View and manage your account information</p>
                    <div class="staff-badge">
                        <i class="fas fa-id-badge"></i> Staff ID: <?= htmlspecialchars($staff['StaffID']) ?>
                    </div>
                </div>
            </div>
            
            <div class="account-container">
                <div class="account-details">
                    <div class="detail-row">
                        <div class="detail-icon">
                            <i class="fas fa-user"></i>
                        </div>
                        <div class="detail-content">
                            <div class="detail-label">Name</div>
                            <div class="detail-value" data-copy="true"><?= htmlspecialchars($staff['StaffName']) ?></div>
                            <div class="copy-hint">Double-click to copy</div>
                        </div>
                    </div>
                    
                    <div class="detail-row">
                        <div class="detail-icon">
                            <i class="fas fa-envelope"></i>
                        </div>
                        <div class="detail-content">
                            <div class="detail-label">Email Address</div>
                            <div class="detail-value" data-copy="true"><?= htmlspecialchars($staff['StaffEmail']) ?></div>
                            <div class="copy-hint">Double-click to copy</div>
                        </div>
                    </div>
                    
                    <div class="detail-row">
                        <div class="detail-icon">
                            <i class="fas fa-phone"></i>
                        </div>
                        <div class="detail-content">
                            <div class="detail-label">Contact Number</div>
                            <div class="detail-value" data-copy="true"><?= htmlspecialchars($staff['StaffContact']) ?></div>
                            <div class="copy-hint">Double-click to copy</div>
                        </div>
                    </div>
                    
                    <div class="detail-row">
                        <div class="detail-icon">
                            <i class="fas fa-key"></i>
                        </div>
                        <div class="detail-content">
                            <div class="detail-label">Password</div>
                            <div class="detail-value">
                                <span class="password-value">••••••••••••</span>
                            </div>
                            <div class="security-indicator">
                                <i class="fas fa-shield-alt"></i>
                                Password protected
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="action-buttons">
                    <a href="update_staff_account.php" class="btn btn-primary">
                        <i class="fas fa-edit"></i> Update Account
                    </a>
                    <a href="staff_dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
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

        // Toast notification function
        function showToast(message, type = 'success') {
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.textContent = message;
            
            document.body.appendChild(toast);
            
            // Show toast
            setTimeout(() => toast.classList.add('show'), 100);
            
            // Hide toast
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => document.body.removeChild(toast), 300);
            }, 3000);
        }

        // Copy to clipboard functionality
        function copyToClipboard(text) {
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(() => {
                    showToast('📋 Copied to clipboard!', 'success');
                }).catch(() => {
                    fallbackCopyTextToClipboard(text);
                });
            } else {
                fallbackCopyTextToClipboard(text);
            }
        }

        // Fallback copy function
        function fallbackCopyTextToClipboard(text) {
            const textArea = document.createElement('textarea');
            textArea.value = text;
            textArea.style.position = 'fixed';
            textArea.style.left = '-999999px';
            textArea.style.top = '-999999px';
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();
            
            try {
                document.execCommand('copy');
                showToast('📋 Copied to clipboard!', 'success');
            } catch (err) {
                showToast('❌ Could not copy to clipboard', 'error');
            }
            
            document.body.removeChild(textArea);
        }

        // Add ripple effect to buttons
        function createRipple(event) {
            const button = event.currentTarget;
            const circle = document.createElement('span');
            const diameter = Math.max(button.clientWidth, button.clientHeight);
            const radius = diameter / 2;
            
            circle.style.width = circle.style.height = `${diameter}px`;
            circle.style.left = `${event.clientX - button.offsetLeft - radius}px`;
            circle.style.top = `${event.clientY - button.offsetTop - radius}px`;
            circle.classList.add('ripple');
            
            const ripple = button.getElementsByClassName('ripple')[0];
            if (ripple) {
                ripple.remove();
            }
            
            button.appendChild(circle);
        }

        // Initialize page functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Add entrance animations
            const elements = document.querySelectorAll('.page-header, .account-container, .detail-row');
            elements.forEach((el, index) => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    el.style.transition = 'all 0.6s cubic-bezier(0.4, 0, 0.2, 1)';
                    el.style.opacity = '1';
                    el.style.transform = 'translateY(0)';
                }, index * 100);
            });

            // Add copy functionality to detail values
            document.querySelectorAll('.detail-value[data-copy="true"]').forEach(value => {
                value.addEventListener('dblclick', function() {
                    const text = this.textContent.trim();
                    if (text && !text.includes('••••')) {
                        copyToClipboard(text);
                    }
                });
                
                // Add tooltip
                value.title = 'Double-click to copy';
                value.style.cursor = 'pointer';
            });

            // Add ripple effect to buttons
            document.querySelectorAll('.btn').forEach(btn => {
                btn.addEventListener('click', createRipple);
                
                // Enhanced hover effect
                btn.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-3px) scale(1.05)';
                });
                
                btn.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0) scale(1)';
                });
            });

            // Staff badge click animation
            document.querySelector('.staff-badge').addEventListener('click', function() {
                this.style.transform = 'scale(1.1)';
                this.style.background = 'linear-gradient(135deg, #667eea, #764ba2)';
                this.style.color = 'white';
                
                setTimeout(() => {
                    this.style.transform = 'scale(1)';
                    this.style.background = 'linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(139, 92, 246, 0.1))';
                    this.style.color = '#667eea';
                }, 200);
            });

            // Detail row hover effects
            document.querySelectorAll('.detail-row').forEach(row => {
                row.addEventListener('mouseenter', function() {
                    const icon = this.querySelector('.detail-icon');
                    if (icon) {
                        icon.style.transform = 'scale(1.1) rotate(5deg)';
                        icon.style.background = 'linear-gradient(135deg, #667eea, #764ba2)';
                        icon.style.color = 'white';
                        icon.style.boxShadow = '0 8px 25px rgba(102, 126, 234, 0.3)';
                    }
                });
                
                row.addEventListener('mouseleave', function() {
                    const icon = this.querySelector('.detail-icon');
                    if (icon) {
                        icon.style.transform = 'scale(1) rotate(0deg)';
                        icon.style.background = 'linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(139, 92, 246, 0.1))';
                        icon.style.color = '#667eea';
                        icon.style.boxShadow = 'none';
                    }
                });
            });

            // Add smooth scroll behavior
            document.documentElement.style.scrollBehavior = 'smooth';

            // Console logging for debugging
            console.log('👨‍💼 Staff Account Page Loaded');
            console.log('📝 Staff ID:', '<?= htmlspecialchars($_SESSION['staff_id']) ?>');
            console.log('👤 Staff Name:', '<?= htmlspecialchars($staff['StaffName']) ?>');
            console.log('✅ Enhanced Staff Account System Active!');
        });

        // Keyboard shortcuts for accessibility
        document.addEventListener('keydown', function(e) {
            // Alt+E for edit account
            if (e.altKey && e.key === 'e') {
                e.preventDefault();
                window.location.href = 'update_staff_account.php';
            }
            
            // Alt+D for dashboard
            if (e.altKey && e.key === 'd') {
                e.preventDefault();
                window.location.href = 'staff_dashboard.php';
            }
            
            // ESC to go back to dashboard
            if (e.key === 'Escape') {
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

        // Enhanced keyboard navigation styles
        const keyboardStyle = document.createElement('style');
        keyboardStyle.textContent = `
            .keyboard-navigation .nav-item:focus,
            .keyboard-navigation .btn:focus,
            .keyboard-navigation .detail-value:focus {
                outline: 3px solid #667eea;
                outline-offset: 2px;
                border-radius: 8px;
            }
        `;
        document.head.appendChild(keyboardStyle);

        // Performance monitoring
        window.addEventListener('load', function() {
            const loadTime = performance.now();
            console.log(`⚡ Staff Account Page loaded in ${Math.round(loadTime)}ms`);
            
            // Show load complete notification
            setTimeout(() => {
                showToast('✅ Account Page Ready!', 'success');
            }, 500);
        });

        // Add loading animation to page transitions
        document.querySelectorAll('a:not([href^="#"])').forEach(link => {
            link.addEventListener('click', function() {
                if (!this.href.includes('javascript:')) {
                    document.body.style.opacity = '0.8';
                    document.body.style.pointerEvents = 'none';
                    document.body.style.transition = 'opacity 0.3s ease';
                }
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

        // Status indicator pulse animation
        const statusIndicators = document.querySelectorAll('.status-indicator');
        statusIndicators.forEach(indicator => {
            setInterval(() => {
                indicator.style.boxShadow = '0 0 15px rgba(16, 185, 129, 0.6)';
                setTimeout(() => {
                    indicator.style.boxShadow = '0 0 8px rgba(16, 185, 129, 0.4)';
                }, 500);
            }, 2000);
        });

        // Enhanced user profile info click
        document.querySelector('.user-info').addEventListener('click', function() {
            this.style.transform = 'translateY(-2px) scale(1.02)';
            setTimeout(() => {
                this.style.transform = 'translateY(-1px) scale(1)';
            }, 200);
        });

        // Add click effect to nav items
        document.querySelectorAll('.nav-item').forEach(item => {
            item.addEventListener('click', function() {
                // Add visual feedback
                this.style.transform = 'translateX(4px) scale(0.98)';
                setTimeout(() => {
                    this.style.transform = 'translateX(4px) scale(1)';
                }, 150);
            });
        });

        // Console logging for debugging
        console.log('🎯 Enhanced Staff Account Page Active!');
        console.log('📱 Responsive Design Active');
        console.log('🔒 Security Features Enabled');
    </script>
</body>
</html>