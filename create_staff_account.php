<?php
session_start();
require_once 'db.php';

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Check if user is logged in
if (!isset($_SESSION['staff_id'])) {
    header("Location: staff_login.php");
    exit;
}

// Enhanced super staff check (works with existing table structure)
$stmt = $conn->prepare("SELECT StaffName, StaffEmail FROM staff WHERE StaffID = ?");
$stmt->bind_param("i", $_SESSION['staff_id']);
$stmt->execute();
$result = $stmt->get_result();
$currentStaff = $result->fetch_assoc();
$stmt->close();

// Check super staff privileges (using existing StaffID = 1 method)
$isSuperStaff = false;
if ($currentStaff && $_SESSION['staff_id'] == 1) {
    $isSuperStaff = true;
}

if (!$isSuperStaff) {
    header("Location: staff_dashboard.php");
    exit;
}

$success = false;
$error = '';
$successDetails = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Protection
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Invalid request. Please refresh the page and try again.";
    } else {
        // Sanitize inputs
        $name = trim($_POST['staff_name'] ?? '');
        $email = trim(strtolower($_POST['staff_email'] ?? ''));
        $contact = trim($_POST['staff_contact'] ?? '');
        $password = $_POST['staff_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        // Enhanced validation
        if (empty($name) || empty($email) || empty($contact) || empty($password) || empty($confirmPassword)) {
            $error = "Please fill in all required fields.";
        } elseif (strlen($name) < 2 || strlen($name) > 100) {
            $error = "Name must be between 2 and 100 characters.";
        } elseif (!preg_match('/^[a-zA-Z\s\-\'\.]+$/', $name)) {
            $error = "Name contains invalid characters. Only letters, spaces, hyphens, apostrophes, and periods allowed.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            $error = "Invalid email format or email too long.";
        } elseif (!preg_match('/^[0-9\-\+\s\(\)]{8,20}$/', $contact)) {
            $error = "Invalid contact number format. Must be 8-20 characters.";
        } elseif (strlen($password) < 8) {
            $error = "Password must be at least 8 characters long.";
        } elseif (!preg_match('/^(?=.*[a-z])(?=.*\d)/', $password)) {
            $error = "Password must contain at least one lowercase letter and one number.";
        } elseif ($password !== $confirmPassword) {
            $error = "Passwords do not match.";
        } else {
            // Check for duplicate email
            $stmt = $conn->prepare("SELECT StaffID FROM staff WHERE StaffEmail = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows > 0) {
                $error = "Email address is already registered.";
            } else {
                // Insert new staff member (keeping original password format)
                $hashedPassword = $password; // Keep as plain text to match existing records
                $insertStmt = $conn->prepare("INSERT INTO staff (StaffName, StaffContact, StaffEmail, StaffPassword) VALUES (?, ?, ?, ?)");
                $insertStmt->bind_param("ssss", $name, $contact, $email, $hashedPassword);

                if ($insertStmt->execute()) {
                    $success = true;
                    $newStaffID = $conn->insert_id;
                    
                    // Store success details for display
                    $successDetails = [
                        'id' => $newStaffID,
                        'name' => htmlspecialchars($name),
                        'email' => htmlspecialchars($email),
                        'contact' => htmlspecialchars($contact)
                    ];
                    
                    // Log the account creation for audit
                    error_log("STAFF_CREATION: New staff account created - ID: $newStaffID, Created by Staff ID: " . $_SESSION['staff_id']);
                    
                    // Clear form data and sensitive info
                    $_POST = [];
                    $password = $confirmPassword = null;
                    
                } else {
                    $error = "Failed to create staff account. Please try again.";
                    error_log("STAFF_CREATION_ERROR: " . $conn->error);
                }
                $insertStmt->close();
            }
            $stmt->close();
        }
        
        // Regenerate CSRF token after form submission
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Staff Account - LifeSaver Hub</title>
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

        .super-staff-badge {
            background: linear-gradient(135deg, #f093fb, #f5576c);
            color: white;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-left: 8px;
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

        .main-content {
            flex: 1;
            margin-left: 320px;
            padding: 32px;
            background: rgba(248, 249, 250, 0.3);
            display: flex;
            justify-content: center;
            align-items: flex-start;
            min-height: 100vh;
        }

        .container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.2);
            width: 100%;
            max-width: 500px;
            text-align: center;
            position: relative;
            overflow: hidden;
            margin-top: 20px;
        }

        .container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea, #764ba2);
            border-radius: 24px 24px 0 0;
        }

        .header {
            margin-bottom: 32px;
        }

        .super-staff-badge-header {
            background: linear-gradient(135deg, #f093fb, #f5576c);
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 16px;
            display: inline-block;
            box-shadow: 0 4px 15px rgba(240, 147, 251, 0.3);
        }

        .container h1 {
            font-size: 2.5rem;
            font-weight: 900;
            color: #2d3748;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
        }

        .container h1 i {
            background: linear-gradient(135deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-size: 2rem;
        }

        .subtitle {
            color: #4a5568;
            font-size: 16px;
            font-weight: 500;
            margin-bottom: 8px;
        }

        .description {
            color: #667eea;
            font-size: 14px;
            font-weight: 600;
            background: rgba(102, 126, 234, 0.1);
            padding: 12px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
        }

        .security-notice {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.2);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 24px;
            text-align: left;
        }

        .security-notice h4 {
            color: #10b981;
            margin-bottom: 8px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .security-notice ul {
            margin-left: 16px;
            font-size: 12px;
            color: #4a5568;
        }

        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }

        .form-group label {
            display: block;
            font-weight: 700;
            margin-bottom: 8px;
            color: #2d3748;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .form-group label i {
            margin-right: 8px;
            opacity: 0.8;
            color: #667eea;
        }

        .form-group input {
            width: 100%;
            padding: 15px 20px;
            border: 2px solid rgba(102, 126, 234, 0.2);
            border-radius: 15px;
            font-size: 16px;
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
            color: #2d3748;
            font-weight: 500;
            font-family: inherit;
        }

        .form-group input::placeholder {
            color: rgba(45, 55, 72, 0.6);
        }

        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            background: rgba(255, 255, 255, 0.95);
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
            transform: scale(1.02);
        }

        .form-help {
            font-size: 12px;
            color: rgba(45, 55, 72, 0.7);
            margin-top: 5px;
            font-style: italic;
        }

        .password-requirements {
            background: rgba(255, 255, 255, 0.9);
            border-radius: 8px;
            padding: 12px;
            margin-top: 8px;
            font-size: 12px;
        }

        .requirement {
            display: flex;
            align-items: center;
            margin-bottom: 4px;
            color: #718096;
            transition: color 0.3s ease;
        }

        .requirement.met {
            color: #38a169;
        }

        .requirement i {
            margin-right: 8px;
            width: 12px;
        }

        .submit-btn {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 16px 32px;
            width: 100%;
            border: none;
            border-radius: 50px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.4s ease;
            text-transform: uppercase;
            letter-spacing: 1px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
        }

        .submit-btn:hover:not(:disabled) {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 15px 35px rgba(102, 126, 234, 0.6);
        }

        .submit-btn:disabled {
            background: linear-gradient(135deg, #95a5a6, #bdc3c7);
            cursor: not-allowed;
            transform: none;
            box-shadow: 0 4px 15px rgba(149, 165, 166, 0.3);
        }

        .message {
            margin-bottom: 24px;
            padding: 20px 25px;
            border-radius: 15px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 15px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .error-message {
            background: linear-gradient(135deg, rgba(220, 53, 69, 0.9), rgba(255, 107, 107, 0.9));
            color: white;
            border-left: 4px solid #dc3545;
        }

        .success-message {
            background: linear-gradient(135deg, rgba(16, 172, 132, 0.9), rgba(0, 210, 211, 0.9));
            color: white;
            border-left: 4px solid #10ac84;
        }

        .success-details {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 15px;
            padding: 20px;
            margin-top: 20px;
            border-left: 4px solid #10ac84;
            text-align: left;
        }

        .success-details h3 {
            color: #2d3748;
            margin-bottom: 10px;
            font-size: 18px;
        }

        .success-details p {
            color: #4a5568;
            margin-bottom: 8px;
        }

        .staff-id {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 8px 16px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 16px;
            display: inline-block;
            margin-top: 10px;
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

        /* Responsive Design */
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
            
            .container {
                max-width: 95%;
                padding: 30px 24px;
            }
            
            .container h1 {
                font-size: 2rem;
            }
        }

        @media (max-width: 480px) {
            .container {
                padding: 24px 20px;
            }

            .container h1 {
                font-size: 1.8rem;
            }
        }

        /* Animations */
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
    <button class="mobile-menu-btn" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>

    <div class="app-container">
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
                        <div class="nav-section-title">Super Staff</div>
                        <a href="create_staff_account.php" class="nav-item active"> 
                            <i class="fas fa-user-plus"></i>
                            <span>Create Staff Account</span>
                        </a>
                    </div>
                    
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
                    <div class="user-avatar">A</div>
                    <div class="user-details">
                        <h4>
                            Adli
                            <span class="super-staff-badge">
                                <i class="fas fa-crown"></i> SUPER
                            </span>
                        </h4>
                        <p>Staff ID: 1</p>
                    </div>
                </div>
            </div>
        </nav>

        <main class="main-content">
            <div class="container">
                <div class="header">
                    <div class="super-staff-badge-header">
                        <i class="fas fa-crown"></i>
                        Super Staff Access
                    </div>
                    <h1>
                        <i class="fas fa-user-tie"></i>
                        Create Staff Account
                    </h1>
                    <div class="subtitle">Add a new staff member to LifeSaver Hub</div>
                    <div class="description">Only Super Staff can create new staff accounts</div>
                </div>

                <div class="security-notice">
                    <h4>
                        <i class="fas fa-info-circle"></i>
                        Account Creation Information
                    </h4>
                    <ul>
                        <li>New staff accounts will be created with the specified credentials</li>
                        <li>CSRF protection prevents unauthorized requests</li>
                        <li>Input validation and sanitization protect against malicious data</li>
                        <li>Account creation is logged for audit purposes</li>
                    </ul>
                </div>

                <?php if ($error): ?>
                    <div class="message error-message">
                        <i class="fas fa-exclamation-triangle"></i>
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="message success-message">
                        <i class="fas fa-check-circle"></i>
                        Staff account created successfully!
                    </div>
                    <div class="success-details">
                        <h3><i class="fas fa-user-check"></i> Account Created Successfully</h3>
                        <p><strong>Staff Name:</strong> <?= $successDetails['name'] ?></p>
                        <p><strong>Email:</strong> <?= $successDetails['email'] ?></p>
                        <p><strong>Contact:</strong> <?= $successDetails['contact'] ?></p>
                        <div class="staff-id">
                            <i class="fas fa-id-badge"></i>
                            Staff ID: <?= $successDetails['id'] ?>
                        </div>
                        <div style="margin-top: 16px; padding: 12px; background: rgba(16, 185, 129, 0.1); border-radius: 8px; font-size: 14px;">
                            <i class="fas fa-info-circle"></i>
                            The staff member can now log in using their email and password.
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!$success): ?>
                <form method="POST" action="" id="staffCreateForm">
                    <!-- CSRF Protection -->
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    
                    <div class="form-group">
                        <label for="staff-name">
                            <i class="fas fa-user"></i>
                            Name
                        </label>
                        <input type="text" id="staff-name" name="staff_name" required 
                               maxlength="100"
                               value="<?= htmlspecialchars($_POST['staff_name'] ?? '') ?>"
                               placeholder="Enter staff member's name">
                        <div class="form-help">2-100 characters, letters only</div>
                    </div>

                    <div class="form-group">
                        <label for="staff-email">
                            <i class="fas fa-envelope"></i>
                            Email Address
                        </label>
                        <input type="email" id="staff-email" name="staff_email" required 
                               maxlength="255"
                               value="<?= htmlspecialchars($_POST['staff_email'] ?? '') ?>"
                               placeholder="staff.email@lifesaverhub.com">
                        <div class="form-help">This will be their login email (must be unique)</div>
                    </div>

                    <div class="form-group">
                        <label for="staff-contact">
                            <i class="fas fa-phone"></i>
                            Contact Number
                        </label>
                        <input type="text" id="staff-contact" name="staff_contact" required 
                               maxlength="20"
                               value="<?= htmlspecialchars($_POST['staff_contact'] ?? '') ?>"
                               placeholder="01X-XXXXXXX">
                        <div class="form-help">8-20 characters, numbers and basic formatting only</div>
                    </div>

                    <div class="form-group">
                        <label for="staff-password">
                            <i class="fas fa-lock"></i>
                            Password
                        </label>
                        <input type="password" id="staff-password" name="staff_password" required
                               minlength="8"
                               placeholder="Create a secure password">
                        
                        <div class="password-requirements">
                            <div class="requirement" id="req-length">
                                <i class="fas fa-times"></i> At least 8 characters
                            </div>
                            <div class="requirement" id="req-lower">
                                <i class="fas fa-times"></i> One lowercase letter
                            </div>
                            <div class="requirement" id="req-number">
                                <i class="fas fa-times"></i> One number
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="confirm-password">
                            <i class="fas fa-lock"></i>
                            Confirm Password
                        </label>
                        <input type="password" id="confirm-password" name="confirm_password" required
                               placeholder="Re-enter the password">
                        <div class="form-help">Must match the password above</div>
                    </div>

                    <button type="submit" class="submit-btn" id="submitBtn" disabled>
                        <i class="fas fa-user-plus"></i>
                        Create Staff Account
                    </button>
                </form>
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

        // Enhanced password validation with real-time checking
        const passwordInput = document.getElementById('staff-password');
        const confirmInput = document.getElementById('confirm-password');
        const submitBtn = document.getElementById('submitBtn');
        
        const requirements = {
            length: { element: document.getElementById('req-length'), test: (pwd) => pwd.length >= 8 },
            lower: { element: document.getElementById('req-lower'), test: (pwd) => /[a-z]/.test(pwd) },
            number: { element: document.getElementById('req-number'), test: (pwd) => /\d/.test(pwd) }
        };

        function validatePassword() {
            const password = passwordInput.value;
            const confirmPassword = confirmInput.value;
            let allMet = true;

            // Check each requirement
            Object.values(requirements).forEach(req => {
                const met = req.test(password);
                req.element.classList.toggle('met', met);
                req.element.querySelector('i').className = met ? 'fas fa-check' : 'fas fa-times';
                if (!met) allMet = false;
            });

            // Check if passwords match
            const passwordsMatch = password && confirmPassword && password === confirmPassword;
            
            // Visual feedback for password match
            if (confirmPassword) {
                if (passwordsMatch) {
                    confirmInput.style.borderColor = '#27ae60';
                    confirmInput.style.background = 'rgba(39, 174, 96, 0.1)';
                } else {
                    confirmInput.style.borderColor = '#e74c3c';
                    confirmInput.style.background = 'rgba(231, 76, 60, 0.1)';
                }
            }
            
            // Enable submit button only if all requirements are met and passwords match
            submitBtn.disabled = !(allMet && passwordsMatch && password.length > 0);
            
            return allMet && passwordsMatch;
        }

        passwordInput.addEventListener('input', validatePassword);
        confirmInput.addEventListener('input', validatePassword);

        // Enhanced contact validation
        document.getElementById('staff-contact').addEventListener('input', function(e) {
            // Allow only valid characters
            this.value = this.value.replace(/[^0-9\-\+\s\(\)]/g, '');
            
            // Limit length
            if (this.value.length > 20) {
                this.value = this.value.slice(0, 20);
            }
        });

        // Name validation
        document.getElementById('staff-name').addEventListener('input', function(e) {
            // Allow only letters, spaces, hyphens, apostrophes, and periods
            this.value = this.value.replace(/[^a-zA-Z\s\-\'\.]/g, '');
            
            // Limit length
            if (this.value.length > 100) {
                this.value = this.value.slice(0, 100);
            }
        });

        // Email validation
        document.getElementById('staff-email').addEventListener('input', function(e) {
            // Convert to lowercase and limit length
            this.value = this.value.toLowerCase();
            if (this.value.length > 255) {
                this.value = this.value.slice(0, 255);
            }
        });

        // Form submission security
        document.getElementById('staffCreateForm')?.addEventListener('submit', function(e) {
            if (!validatePassword()) {
                e.preventDefault();
                alert('Please ensure all password requirements are met and passwords match.');
                return false;
            }
            
            const submitBtn = document.getElementById('submitBtn');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating Account...';
            submitBtn.disabled = true;

            // Timeout fallback
            setTimeout(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }, 15000);
        });

        // Auto-hide error messages after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const errorMessages = document.querySelectorAll('.error-message');
            errorMessages.forEach(function(message) {
                setTimeout(function() {
                    message.style.opacity = '0';
                    message.style.transform = 'translateY(-20px)';
                    setTimeout(function() {
                        message.remove();
                    }, 300);
                }, 5000);
            });

            // Add entrance animations
            const container = document.querySelector('.container');
            if (container) {
                container.style.opacity = '0';
                container.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    container.style.transition = 'all 0.6s ease';
                    container.style.opacity = '1';
                    container.style.transform = 'translateY(0)';
                }, 100);
            }
        });

        // Keyboard accessibility
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
            .keyboard-navigation input:focus,
            .keyboard-navigation .submit-btn:focus,
            .keyboard-navigation .nav-item:focus {
                outline: 3px solid #667eea;
                outline-offset: 2px;
            }
        `;
        document.head.appendChild(keyboardStyle);

        // Keyboard shortcuts for navigation
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
        });

        // Performance monitoring and notifications
        window.addEventListener('load', function() {
            const loadTime = performance.now();
            
            // Show confirmation notification
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
                notification.innerHTML = '👑 Staff Creation Ready!';
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

        console.log('👑 Staff Account Creation Loaded');
        console.log('✅ Features:');
        console.log('  - CSRF Protection Enabled');
        console.log('  - Enhanced Input Validation');
        console.log('  - Real-time Password Requirements');
        console.log('  - Audit Logging');
        console.log('  - XSS Protection');
    </script>
</body>
</html>