<?php
session_start();

if (!isset($_SESSION['staff_id'])) {
    header("Location: staff_login.php");
    exit;
}

$pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$stmt = $pdo->prepare("SELECT * FROM staff WHERE StaffID = ?");
$stmt->execute([$_SESSION['staff_id']]);
$staff = $stmt->fetch();

$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $contact = trim($_POST['contact']);
    $password = $_POST['password'];

    if (!$name || !$email || !$contact || !$password) {
        $error = "All fields are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        try {
            $update = $pdo->prepare("UPDATE staff SET StaffName = ?, StaffEmail = ?, StaffContact = ?, StaffPassword = ? WHERE StaffID = ?");
            $update->execute([$name, $email, $contact, $password, $_SESSION['staff_id']]);
            $success = true;

            // Refresh staff data
            $stmt = $pdo->prepare("SELECT * FROM staff WHERE StaffID = ?");
            $stmt->execute([$_SESSION['staff_id']]);
            $staff = $stmt->fetch();
        } catch (Exception $e) {
            $error = "An error occurred while updating your account. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Account - LifeSaver Hub</title>
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

        /* Enhanced Sidebar - Identical to dashboard */
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

        /* Form Container */
        .form-container {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(0, 0, 0, 0.05);
            max-width: 800px;
            margin: 0 auto;
        }

        .form-content {
            padding: 40px;
        }

        .form-group {
            margin-bottom: 32px;
            position: relative;
        }

        .form-group label {
            display: flex;
            align-items: center;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 12px;
            font-size: 16px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .form-group label i {
            width: 24px;
            margin-right: 12px;
            color: #667eea;
            font-size: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .required {
            color: #ef4444;
            margin-left: 4px;
        }

        .form-input {
            position: relative;
        }

        .form-input input {
            width: 100%;
            padding: 18px 24px;
            border: 2px solid rgba(102, 126, 234, 0.2);
            border-radius: 16px;
            background: rgba(248, 249, 250, 0.8);
            font-size: 16px;
            font-weight: 500;
            color: #2d3748;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            outline: none;
        }

        .form-input input:focus {
            border-color: #667eea;
            background: rgba(255, 255, 255, 0.95);
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
            transform: translateY(-2px);
        }

        .form-input input:hover {
            border-color: rgba(102, 126, 234, 0.4);
            background: rgba(255, 255, 255, 0.9);
        }

        .password-toggle {
            position: absolute;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #667eea;
            font-size: 20px;
            transition: all 0.3s ease;
            padding: 4px;
            border-radius: 8px;
        }

        .password-toggle:hover {
            background: rgba(102, 126, 234, 0.1);
            transform: translateY(-50%) scale(1.1);
        }

        .form-actions {
            display: flex;
            gap: 20px;
            justify-content: center;
            margin-top: 40px;
            padding-top: 30px;
            border-top: 1px solid rgba(102, 126, 234, 0.15);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            padding: 16px 32px;
            border: none;
            border-radius: 16px;
            font-weight: 700;
            font-size: 16px;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
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

        /* Messages */
        .message {
            padding: 20px 24px;
            border-radius: 16px;
            margin-bottom: 24px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 16px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .message.success {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(5, 150, 105, 0.1));
            color: #065f46;
            border: 2px solid rgba(16, 185, 129, 0.3);
        }

        .message.error {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.1), rgba(220, 38, 38, 0.1));
            color: #7f1d1d;
            border: 2px solid rgba(239, 68, 68, 0.3);
        }

        .message i {
            font-size: 20px;
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
            
            .page-header h1 {
                font-size: 2rem;
                flex-direction: column;
                gap: 10px;
            }
            
            .form-actions {
                flex-direction: column;
                align-items: center;
            }
            
            .btn {
                width: 100%;
                max-width: 300px;
            }
        }

        @media (max-width: 480px) {
            .form-content {
                padding: 25px;
            }
            .page-header {
                padding: 25px;
            }
        }

        /* Toast Notification */
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
                        <i class="fas fa-edit"></i> 
                        Update Account
                    </h1>
                    <p>Modify your account information and settings</p>
                    <div class="staff-badge">
                        <i class="fas fa-id-badge"></i> Staff ID: <?= htmlspecialchars($staff['StaffID']) ?>
                    </div>
                </div>
            </div>
            
            <div class="form-container">
                <div class="form-content">
                    <?php if ($success): ?>
                        <div class="message success">
                            <i class="fas fa-check-circle"></i>
                            Account updated successfully! Your changes have been saved.
                        </div>
                    <?php elseif ($error): ?>
                        <div class="message error">
                            <i class="fas fa-exclamation-circle"></i>
                            <?= htmlspecialchars($error) ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" id="updateForm">
                        <div class="form-group">
                            <label for="name">
                                <i class="fas fa-user"></i>
                                Name <span class="required">*</span>
                            </label>
                            <div class="form-input">
                                <input type="text" id="name" name="name" value="<?= htmlspecialchars($staff['StaffName']) ?>" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="email">
                                <i class="fas fa-envelope"></i>
                                Email Address <span class="required">*</span>
                            </label>
                            <div class="form-input">
                                <input type="email" id="email" name="email" value="<?= htmlspecialchars($staff['StaffEmail']) ?>" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="contact">
                                <i class="fas fa-phone"></i>
                                Contact Number <span class="required">*</span>
                            </label>
                            <div class="form-input">
                                <input type="text" id="contact" name="contact" value="<?= htmlspecialchars($staff['StaffContact']) ?>" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="password">
                                <i class="fas fa-key"></i>
                                Password <span class="required">*</span>
                            </label>
                            <div class="form-input">
                                <input type="password" id="password" name="password" value="<?= htmlspecialchars($staff['StaffPassword']) ?>" required>
                                <i class="fas fa-eye password-toggle" onclick="togglePassword()"></i>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                            <a href="staff_account.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Back to Profile
                            </a>
                        </div>
                    </form>
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

        // Password toggle functionality
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.querySelector('.password-toggle');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }

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

        // Form validation and enhancement
        document.getElementById('updateForm').addEventListener('submit', function(e) {
            const name = document.getElementById('name').value.trim();
            const email = document.getElementById('email').value.trim();
            const contact = document.getElementById('contact').value.trim();
            const password = document.getElementById('password').value;
            
            if (!name || !email || !contact || !password) {
                e.preventDefault();
                showToast('Please fill in all required fields.', 'error');
                return false;
            }
            
            // Basic email validation
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                e.preventDefault();
                showToast('Please enter a valid email address.', 'error');
                return false;
            }

            // Contact number validation
            const contactRegex = /^[\d\s\-\+\(\)]+$/;
            if (!contactRegex.test(contact)) {
                e.preventDefault();
                showToast('Please enter a valid contact number.', 'error');
                return false;
            }

            // Password strength validation
            if (password.length < 6) {
                e.preventDefault();
                showToast('Password must be at least 6 characters long.', 'error');
                return false;
            }

            // Show loading state
            const submitBtn = this.querySelector('.btn-primary');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            submitBtn.disabled = true;

            // Re-enable button after form submission (in case of errors)
            setTimeout(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }, 3000);
        });

        // Initialize page functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Add entrance animations
            const elements = document.querySelectorAll('.page-header, .form-container, .form-group');
            elements.forEach((el, index) => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    el.style.transition = 'all 0.6s cubic-bezier(0.4, 0, 0.2, 1)';
                    el.style.opacity = '1';
                    el.style.transform = 'translateY(0)';
                }, index * 100);
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

            // Enhanced input focus effects
            document.querySelectorAll('input').forEach(input => {
                input.addEventListener('focus', function() {
                    this.parentElement.style.transform = 'translateY(-2px)';
                    this.parentElement.style.boxShadow = '0 8px 25px rgba(102, 126, 234, 0.15)';
                });
                
                input.addEventListener('blur', function() {
                    this.parentElement.style.transform = 'translateY(0)';
                    this.parentElement.style.boxShadow = 'none';
                });

                // Real-time validation feedback
                input.addEventListener('input', function() {
                    validateField(this);
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

            // Auto-save draft functionality
            let saveTimer;
            document.querySelectorAll('input').forEach(input => {
                input.addEventListener('input', function() {
                    clearTimeout(saveTimer);
                    saveTimer = setTimeout(() => {
                        saveDraft();
                    }, 2000);
                });
            });

            // Load saved draft on page load
            loadDraft();

            // Add smooth scroll behavior
            document.documentElement.style.scrollBehavior = 'smooth';

            // Console logging for debugging
            console.log('✏️ Staff Update Account Page Loaded');
            console.log('📝 Staff ID:', '<?= htmlspecialchars($_SESSION['staff_id']) ?>');
            console.log('👤 Staff Name:', '<?= htmlspecialchars($staff['StaffName']) ?>');
            console.log('✅ Enhanced Update Account System Active!');
        });

        // Real-time field validation
        function validateField(input) {
            const value = input.value.trim();
            const fieldType = input.type;
            const fieldName = input.name;
            
            // Remove previous validation styling
            input.style.borderColor = '';
            
            if (!value) {
                input.style.borderColor = '#ef4444';
                return false;
            }
            
            switch (fieldName) {
                case 'email':
                    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    if (!emailRegex.test(value)) {
                        input.style.borderColor = '#ef4444';
                        return false;
                    }
                    break;
                case 'contact':
                    const contactRegex = /^[\d\s\-\+\(\)]+$/;
                    if (!contactRegex.test(value)) {
                        input.style.borderColor = '#ef4444';
                        return false;
                    }
                    break;
                case 'password':
                    if (value.length < 6) {
                        input.style.borderColor = '#ef4444';
                        return false;
                    }
                    break;
            }
            
            input.style.borderColor = '#10b981';
            return true;
        }

        // Draft saving functionality
        function saveDraft() {
            const formData = {
                name: document.getElementById('name').value,
                email: document.getElementById('email').value,
                contact: document.getElementById('contact').value
            };
            
            localStorage.setItem('staff_update_draft', JSON.stringify(formData));
            console.log('📄 Draft saved automatically');
        }

        function loadDraft() {
            const draft = localStorage.getItem('staff_update_draft');
            if (draft) {
                const formData = JSON.parse(draft);
                
                // Only load draft if fields are empty (to avoid overwriting current data)
                if (!document.getElementById('name').value) {
                    document.getElementById('name').value = formData.name || '';
                }
                if (!document.getElementById('email').value) {
                    document.getElementById('email').value = formData.email || '';
                }
                if (!document.getElementById('contact').value) {
                    document.getElementById('contact').value = formData.contact || '';
                }
                
                console.log('📄 Draft loaded from previous session');
            }
        }

        // Clear draft after successful submission
        document.getElementById('updateForm').addEventListener('submit', function() {
            setTimeout(() => {
                localStorage.removeItem('staff_update_draft');
            }, 1000);
        });

        // Keyboard shortcuts for accessibility
        document.addEventListener('keydown', function(e) {
            // Ctrl+S to save form
            if (e.ctrlKey && e.key === 's') {
                e.preventDefault();
                document.getElementById('updateForm').dispatchEvent(new Event('submit'));
            }
            
            // ESC to go back to account page
            if (e.key === 'Escape') {
                window.location.href = 'staff_account.php';
            }
            
            // Alt+P for password toggle
            if (e.altKey && e.key === 'p') {
                e.preventDefault();
                togglePassword();
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
            .keyboard-navigation input:focus {
                outline: 3px solid #667eea;
                outline-offset: 2px;
                border-radius: 8px;
            }
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
        document.head.appendChild(keyboardStyle);

        // Performance monitoring
        window.addEventListener('load', function() {
            const loadTime = performance.now();
            console.log(`⚡ Staff Update Account Page loaded in ${Math.round(loadTime)}ms`);
            
            // Show load complete notification
            setTimeout(() => {
                showToast('✅ Update Form Ready!', 'success');
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

        // Enhanced error handling
        window.addEventListener('error', function(e) {
            console.error('JavaScript Error:', e.error);
            showToast('❌ An error occurred. Please refresh the page.', 'error');
        });

        // Auto-logout functionality (optional security feature)
        let lastActivity = Date.now();
        const TIMEOUT_DURATION = 30 * 60 * 1000; // 30 minutes

        function resetActivity() {
            lastActivity = Date.now();
        }

        function checkActivity() {
            if (Date.now() - lastActivity > TIMEOUT_DURATION) {
                showToast('⚠️ Session expired due to inactivity', 'error');
                setTimeout(() => {
                    window.location.href = 'logout.php';
                }, 3000);
            }
        }

        // Track user activity
        ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart'].forEach(event => {
            document.addEventListener(event, resetActivity, true);
        });

        // Check activity every minute
        setInterval(checkActivity, 60000);

        // Console logging for debugging
        console.log('🎯 Enhanced Staff Update Account Page Active!');
        console.log('📱 Responsive Design Active');
        console.log('🔒 Security Features Enabled');
        console.log('💾 Auto-save Draft Enabled');
        console.log('⌨️ Keyboard Shortcuts: Ctrl+S (Save), ESC (Back), Alt+P (Toggle Password)');
    </script>
</body>
</html>