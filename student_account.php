<?php
session_start();

// Check if student is logged in
if (!isset($_SESSION['student_id'])) {
    header("Location: index.php");
    exit;
}

// Database connection
try {
    require 'db.php';
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Fetch student data
$stmt = $pdo->prepare("SELECT * FROM student WHERE StudentID = ?");
$stmt->execute([$_SESSION['student_id']]);
$student = $stmt->fetch();

if (!$student) {
    session_destroy();
    header("Location: index.php");
    exit;
}

// Get notification count for sidebar badge
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notification WHERE StudentID = ? AND IsRead = 0");
    $stmt->execute([$_SESSION['student_id']]);
    $unread_notifications = $stmt->fetchColumn();
} catch (Exception $e) {
    $unread_notifications = 0;
}

$success = false;
$error = '';
$editMode = false;

// Check if edit mode is enabled
if (isset($_GET['edit']) && $_GET['edit'] === 'true') {
    $editMode = true;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize and validate input
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $contact = trim($_POST['contact']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $ic = trim($_POST['ic']);
    $gender = $_POST['gender'];
    $age = $_POST['age'];
    $address = trim($_POST['address']);

    // Validation
    if (!$name || !$email || !$contact || !$password || !$ic || !$gender || !$age || !$address) {
        $error = "All fields are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif (!preg_match('/^[0-9+\-\s\(\)]+$/', $contact)) {
        $error = "Contact number contains invalid characters.";
    } elseif (strlen(preg_replace('/[^0-9]/', '', $contact)) < 6) {
        $error = "Contact number must be at least 6 digits long.";
    } elseif (!preg_match('/^\d{6}-\d{2}-\d{4}$/', $ic)) {
        $error = "Invalid IC format. Please use format: XXXXXX-XX-XXXX";
    } elseif (!is_numeric($age) || $age < 18 || $age > 65) {
        $error = "Age must be between 18 and 65 years old.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } else {
        // Check if email is already used by another student
        $emailCheck = $pdo->prepare("SELECT StudentID FROM student WHERE StudentEmail = ? AND StudentID != ?");
        $emailCheck->execute([$email, $_SESSION['student_id']]);
        
        if ($emailCheck->fetch()) {
            $error = "This email address is already registered to another account.";
        } else {
            // Check if IC is already used by another student
            $icCheck = $pdo->prepare("SELECT StudentID FROM student WHERE StudentIC = ? AND StudentID != ?");
            $icCheck->execute([$ic, $_SESSION['student_id']]);
            
            if ($icCheck->fetch()) {
                $error = "This IC number is already registered to another account.";
            } else {
                // Update database with all fields
                try {
                    $update = $pdo->prepare("UPDATE student SET StudentName = ?, StudentEmail = ?, StudentContact = ?, StudentPassword = ?, StudentIC = ?, StudentGender = ?, StudentAge = ?, StudentAddress = ? WHERE StudentID = ?");
                    $update->execute([$name, $email, $contact, $password, $ic, $gender, $age, $address, $_SESSION['student_id']]);
                    $success = true;
                    
                    // Refresh student data
                    $stmt = $pdo->prepare("SELECT * FROM student WHERE StudentID = ?");
                    $stmt->execute([$_SESSION['student_id']]);
                    $student = $stmt->fetch();
                    
                } catch (Exception $e) {
                    $error = "An error occurred while updating your account. Please try again.";
                    error_log("Account update error: " . $e->getMessage());
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $editMode ? 'Update Account' : 'My Account' ?> - LifeSaver Hub</title>
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

        .account-container {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .student-id-card {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(139, 92, 246, 0.1));
            padding: 32px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.08);
            text-align: center;
            color: #2d3748;
            font-weight: 600;
            font-size: 18px;
        }

        .student-id-card i {
            margin-right: 12px;
            font-size: 24px;
            color: #667eea;
        }

        .account-content {
            padding: 40px;
        }

        .info-section {
            background: rgba(248, 249, 250, 0.8);
            border-radius: 20px;
            padding: 32px;
            margin-bottom: 32px;
            border: 1px solid rgba(0, 0, 0, 0.08);
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
        }

        .info-item {
            background: rgba(255, 255, 255, 0.8);
            padding: 24px;
            border-radius: 15px;
            border: 1px solid rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
        }

        .info-item:hover {
            background: rgba(255, 255, 255, 0.95);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .info-label {
            display: flex;
            align-items: center;
            font-weight: 700;
            color: #4a5568;
            margin-bottom: 8px;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .info-label i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
            color: #667eea;
        }

        .info-value {
            color: #2d3748;
            font-size: 16px;
            font-weight: 600;
            word-break: break-word;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            margin-bottom: 25px;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-label {
            display: flex;
            align-items: center;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 12px;
            font-size: 16px;
        }

        .form-label i {
            margin-right: 15px;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(139, 92, 246, 0.1));
            border-radius: 50%;
            font-size: 14px;
            color: #667eea;
        }

        .required {
            color: #ef4444;
            font-weight: 800;
        }

        input, select, textarea {
            width: 100%;
            padding: 16px 20px;
            border-radius: 15px;
            border: 2px solid rgba(0, 0, 0, 0.08);
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(10px);
            font-size: 16px;
            transition: all 0.3s ease;
            box-sizing: border-box;
            color: #2d3748;
            font-weight: 500;
            font-family: inherit;
        }

        input::placeholder, textarea::placeholder {
            color: #a0aec0;
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #667eea;
            background: rgba(255, 255, 255, 0.95);
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
            transform: scale(1.02);
        }

        input:hover, select:hover, textarea:hover {
            border-color: rgba(102, 126, 234, 0.3);
        }

        select option {
            background: rgba(255, 255, 255, 0.95);
            color: #2d3748;
        }

        textarea {
            resize: vertical;
            min-height: 120px;
        }

        .password-input {
            position: relative;
        }

        .password-toggle {
            position: absolute;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #a0aec0;
            font-size: 18px;
            transition: all 0.3s ease;
        }

        .password-toggle:hover {
            color: #667eea;
            transform: translateY(-50%) scale(1.1);
        }

        .password-strength {
            margin-top: 8px;
            font-size: 14px;
            font-weight: 600;
        }

        .strength-weak { color: #ef4444; }
        .strength-medium { color: #f59e0b; }
        .strength-strong { color: #10b981; }

        .field-help {
            font-size: 13px;
            color: #6b7280;
            margin-top: 8px;
            font-weight: 500;
        }

        .actions {
            display: flex;
            gap: 20px;
            justify-content: center;
            margin-top: 40px;
            padding-top: 30px;
            border-top: 1px solid rgba(0, 0, 0, 0.08);
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            padding: 16px 32px;
            border: none;
            border-radius: 16px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative;
            overflow: hidden;
            text-transform: uppercase;
            letter-spacing: 1px;
            min-width: 150px;
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

        .btn-success {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            box-shadow: 0 8px 25px rgba(40, 167, 69, 0.4);
        }

        .btn-success:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 15px 35px rgba(40, 167, 69, 0.6);
        }

        .message {
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

        .message.success {
            background: linear-gradient(135deg, rgba(16, 172, 132, 0.9), rgba(0, 210, 211, 0.9));
            color: white;
            border-left: 4px solid #10ac84;
        }

        .message.error {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.9), rgba(220, 38, 38, 0.9));
            color: white;
            border-left: 4px solid #ef4444;
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
            
            .actions {
                flex-direction: column;
                align-items: center;
            }
            
            .page-header h1 {
                font-size: 2rem;
                flex-direction: column;
                gap: 10px;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .account-content {
                padding: 20px;
            }
            .page-header {
                padding: 25px;
            }
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
    <!-- Mobile menu button -->
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
                        <a href="notifications.php" class="nav-item">
                            <i class="fas fa-bell"></i>
                            <span>Notifications</span>
                            <?php if ($unread_notifications > 0): ?>
                                <span class="notification-badge"><?php echo $unread_notifications; ?></span>
                            <?php endif; ?>
                        </a>
                    </div>
                    
                    <div class="nav-section">
                        <div class="nav-section-title">Account</div>
                        <a href="student_account.php" class="nav-item active">
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
                        <p>Student ID: <?php echo htmlspecialchars($_SESSION['student_id']); ?></p>
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
                        <?= $editMode ? 'Update My Account' : 'My Account' ?>
                    </h1>
                    <p><?= $editMode ? 'Edit your profile information below' : 'View and manage your student profile' ?></p>
                </div>
            </div>
            
            <div class="account-container">
                <div class="student-id-card">
                    <i class="fas fa-id-badge"></i> 
                    Student ID: <strong><?= htmlspecialchars($student['StudentID']) ?></strong>
                </div>

                <div class="account-content">
                    <?php if ($success): ?>
                        <div class="message success">
                            <i class="fas fa-check-circle"></i>
                            Account updated successfully! Your changes have been saved.
                        </div>
                        <script>
                            // Redirect to view mode after successful update
                            setTimeout(() => {
                                window.location.href = 'student_account.php';
                            }, 2000);
                        </script>
                    <?php elseif ($error): ?>
                        <div class="message error">
                            <i class="fas fa-exclamation-circle"></i>
                            <?= htmlspecialchars($error) ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!$editMode): ?>
                        <!-- View Mode -->
                        <div class="info-section">
                            <div class="info-grid">
                                <div class="info-item">
                                    <div class="info-label">
                                        <i class="fas fa-user"></i> Name
                                    </div>
                                    <div class="info-value"><?= htmlspecialchars($student['StudentName']) ?></div>
                                </div>
                                
                                <div class="info-item">
                                    <div class="info-label">
                                        <i class="fas fa-envelope"></i> Email Address
                                    </div>
                                    <div class="info-value"><?= htmlspecialchars($student['StudentEmail']) ?></div>
                                </div>
                                
                                <div class="info-item">
                                    <div class="info-label">
                                        <i class="fas fa-phone"></i> Contact Number
                                    </div>
                                    <div class="info-value"><?= htmlspecialchars($student['StudentContact']) ?></div>
                                </div>
                                
                                <div class="info-item">
                                    <div class="info-label">
                                        <i class="fas fa-id-card"></i> IC Number
                                    </div>
                                    <div class="info-value"><?= htmlspecialchars($student['StudentIC'] ?: 'Not provided') ?></div>
                                </div>

                                <div class="info-item">
                                    <div class="info-label">
                                        <i class="fas fa-venus-mars"></i> Gender
                                    </div>
                                    <div class="info-value"><?= htmlspecialchars($student['StudentGender'] ?: 'Not specified') ?></div>
                                </div>

                                <div class="info-item">
                                    <div class="info-label">
                                        <i class="fas fa-birthday-cake"></i> Age
                                    </div>
                                    <div class="info-value"><?= htmlspecialchars($student['StudentAge'] ?: 'Not provided') ?> years old</div>
                                </div>
                                
                                <div class="info-item" style="grid-column: 1 / -1;">
                                    <div class="info-label">
                                        <i class="fas fa-map-marker-alt"></i> Address
                                    </div>
                                    <div class="info-value"><?= htmlspecialchars($student['StudentAddress'] ?: 'Not provided') ?></div>
                                </div>
                                
                                <div class="info-item">
                                    <div class="info-label">
                                        <i class="fas fa-shield-alt"></i> Password
                                    </div>
                                    <div class="info-value">••••••••••••</div>
                                </div>
                            </div>
                        </div>

                        <div class="actions">
                            <a href="student_account.php?edit=true" class="btn btn-primary">
                                <i class="fas fa-edit"></i> Update Account
                            </a>
                            <a href="student_dashboard.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Back to Dashboard
                            </a>
                        </div>

                    <?php else: ?>
                        <!-- Edit Mode -->
                        <form method="POST" id="updateForm">
                            <div class="info-section">
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label" for="name">
                                            <i class="fas fa-user"></i> Name <span class="required">*</span>
                                        </label>
                                        <input type="text" id="name" name="name" value="<?= htmlspecialchars($student['StudentName']) ?>" required placeholder="Enter your name">
                                        <div class="field-help">Enter your name</div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label" for="email">
                                            <i class="fas fa-envelope"></i> Email Address <span class="required">*</span>
                                        </label>
                                        <input type="email" id="email" name="email" value="<?= htmlspecialchars($student['StudentEmail']) ?>" required placeholder="Enter your email address">
                                        <div class="field-help">We'll use this email for important notifications</div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label" for="contact">
                                            <i class="fas fa-phone"></i> Contact Number <span class="required">*</span>
                                        </label>
                                        <input type="text" id="contact" name="contact" value="<?= htmlspecialchars($student['StudentContact']) ?>" required placeholder="Enter your contact number">
                                        <div class="field-help">Include country code if international (e.g., +60123456789)</div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label" for="ic">
                                            <i class="fas fa-id-card"></i> IC Number <span class="required">*</span>
                                        </label>
                                        <input type="text" id="ic" name="ic" value="<?= htmlspecialchars($student['StudentIC']) ?>" required placeholder="XXXXXX-XX-XXXX" pattern="^\d{6}-\d{2}-\d{4}$">
                                        <div class="field-help">Format: XXXXXX-XX-XXXX (with dashes)</div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label" for="gender">
                                            <i class="fas fa-venus-mars"></i> Gender <span class="required">*</span>
                                        </label>
                                        <select id="gender" name="gender" required>
                                            <option value="">Select Gender</option>
                                            <option value="Male" <?= ($student['StudentGender'] === 'Male') ? 'selected' : '' ?>>Male</option>
                                            <option value="Female" <?= ($student['StudentGender'] === 'Female') ? 'selected' : '' ?>>Female</option>
                                        </select>
                                        <div class="field-help">Please select your gender</div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label" for="age">
                                            <i class="fas fa-birthday-cake"></i> Age <span class="required">*</span>
                                        </label>
                                        <input type="number" id="age" name="age" value="<?= htmlspecialchars($student['StudentAge']) ?>" required placeholder="Enter your age" min="18" max="65">
                                        <div class="field-help">Must be between 18-65 years old to donate blood</div>
                                    </div>
                                </div>

                                <div class="form-group full-width">
                                    <label class="form-label" for="address">
                                        <i class="fas fa-map-marker-alt"></i> Address <span class="required">*</span>
                                    </label>
                                    <textarea id="address" name="address" required placeholder="Enter your complete address"><?= htmlspecialchars($student['StudentAddress']) ?></textarea>
                                    <div class="field-help">Provide your complete residential address</div>
                                </div>

                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label" for="password">
                                            <i class="fas fa-key"></i> Password <span class="required">*</span>
                                        </label>
                                        <div class="password-input">
                                            <input type="password" id="password" name="password" value="<?= htmlspecialchars($student['StudentPassword']) ?>" required placeholder="Enter your password">
                                            <i class="fas fa-eye password-toggle" onclick="togglePassword('password')"></i>
                                        </div>
                                        <div class="password-strength" id="passwordStrength"></div>
                                        <div class="field-help">Minimum 6 characters required</div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label" for="confirm_password">
                                            <i class="fas fa-check-circle"></i> Confirm Password <span class="required">*</span>
                                        </label>
                                        <div class="password-input">
                                            <input type="password" id="confirm_password" name="confirm_password" value="<?= htmlspecialchars($student['StudentPassword']) ?>" required placeholder="Confirm your password">
                                            <i class="fas fa-eye password-toggle" onclick="togglePassword('confirm_password')"></i>
                                        </div>
                                        <div class="field-help">Re-enter your password to confirm</div>
                                    </div>
                                </div>
                            </div>

                            <div class="actions">
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-save"></i> Save Changes
                                </button>
                                <a href="student_account.php" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Toggle sidebar for mobile
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

        // Toggle password visibility
        function togglePassword(fieldId) {
            const passwordInput = document.getElementById(fieldId);
            const toggleIcon = passwordInput.nextElementSibling;
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }

        // Toast notifications
        function showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.textContent = message;
            
            document.body.appendChild(toast);
            setTimeout(() => toast.classList.add('show'), 100);
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => document.body.removeChild(toast), 400);
            }, 3000);
        }

        // Auto-hide mobile menu on resize
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth > 968) {
                sidebar.classList.remove('open');
            }
        });

        // Form validation (only in edit mode)
        <?php if ($editMode): ?>
        // IC number formatting
        document.getElementById('ic').addEventListener('input', function() {
            let value = this.value.replace(/\D/g, ''); // Remove all non-digits
            if (value.length >= 6) {
                value = value.substring(0, 6) + '-' + value.substring(6);
            }
            if (value.length >= 9) {
                value = value.substring(0, 9) + '-' + value.substring(9, 13);
            }
            this.value = value;
        });

        // Contact number validation
        document.getElementById('contact').addEventListener('input', function() {
            // Allow only numbers, spaces, dashes, plus signs, and parentheses
            this.value = this.value.replace(/[^0-9\-\+\s\(\)]/g, '');
        });

        // Password strength checker
        document.getElementById('password').addEventListener('input', function() {
            const password = this.value;
            const strengthDiv = document.getElementById('passwordStrength');
            
            if (password.length === 0) {
                strengthDiv.textContent = '';
                return;
            }
            
            let strength = 0;
            if (password.length >= 6) strength++;
            if (password.match(/[a-z]/)) strength++;
            if (password.match(/[A-Z]/)) strength++;
            if (password.match(/[0-9]/)) strength++;
            if (password.match(/[^a-zA-Z0-9]/)) strength++;
            
            if (strength < 2) {
                strengthDiv.textContent = 'Password strength: Weak';
                strengthDiv.className = 'password-strength strength-weak';
            } else if (strength < 4) {
                strengthDiv.textContent = 'Password strength: Medium';
                strengthDiv.className = 'password-strength strength-medium';
            } else {
                strengthDiv.textContent = 'Password strength: Strong';
                strengthDiv.className = 'password-strength strength-strong';
            }
        });

        // Form validation
        document.getElementById('updateForm').addEventListener('submit', function(e) {
            const name = document.getElementById('name').value.trim();
            const email = document.getElementById('email').value.trim();
            const contact = document.getElementById('contact').value.trim();
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const ic = document.getElementById('ic').value.trim();
            const gender = document.getElementById('gender').value;
            const age = document.getElementById('age').value;
            const address = document.getElementById('address').value.trim();
            
            // Basic validation
            if (!name || !email || !contact || !password || !confirmPassword || !ic || !gender || !age || !address) {
                e.preventDefault();
                showToast('Please fill in all required fields.', 'error');
                return false;
            }
            
            // Email validation
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                e.preventDefault();
                showToast('Please enter a valid email address.', 'error');
                return false;
            }
            
            // Phone number validation
            const phoneRegex = /^[0-9+\-\s\(\)]+$/;
            if (!phoneRegex.test(contact)) {
                e.preventDefault();
                showToast('Please enter a valid phone number (numbers, +, -, spaces, and parentheses only).', 'error');
                return false;
            }
            
            // Check minimum phone number length
            const cleanPhone = contact.replace(/[^0-9]/g, '');
            if (cleanPhone.length < 6) {
                e.preventDefault();
                showToast('Phone number must be at least 6 digits long.', 'error');
                return false;
            }

            // IC validation
            const icRegex = /^\d{6}-\d{2}-\d{4}$/;
            if (!icRegex.test(ic)) {
                e.preventDefault();
                showToast('IC format must be XXXXXX-XX-XXXX (with dashes).', 'error');
                return false;
            }

            // Age validation
            const ageNum = parseInt(age);
            if (isNaN(ageNum) || ageNum < 18 || ageNum > 65) {
                e.preventDefault();
                showToast('Age must be between 18 and 65 years old.', 'error');
                return false;
            }
            
            // Password validation
            if (password.length < 6) {
                e.preventDefault();
                showToast('Password must be at least 6 characters long.', 'error');
                return false;
            }
            
            if (password !== confirmPassword) {
                e.preventDefault();
                showToast('Passwords do not match.', 'error');
                return false;
            }
            
            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            submitBtn.disabled = true;
            
            // Re-enable after timeout in case of errors
            setTimeout(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }, 5000);
        });

        // Real-time password match validation
        document.getElementById('confirm_password').addEventListener('input', function() {
            const password = document.getElementById('password').value;
            const confirmPassword = this.value;
            
            if (confirmPassword && password !== confirmPassword) {
                this.style.borderColor = '#ef4444';
                this.style.boxShadow = '0 0 0 4px rgba(239, 68, 68, 0.2)';
            } else {
                this.style.borderColor = 'rgba(0, 0, 0, 0.08)';
                this.style.boxShadow = 'none';
            }
        });

        // Age validation visual feedback
        document.getElementById('age').addEventListener('blur', function() {
            const age = parseInt(this.value);
            if (age && (age < 18 || age > 65)) {
                this.style.borderColor = '#e74c3c';
                this.style.background = 'rgba(231, 76, 60, 0.1)';
            } else if (age && age >= 18 && age <= 65) {
                this.style.borderColor = '#27ae60';
                this.style.background = 'rgba(39, 174, 96, 0.1)';
            }
        });

        // Show confirmation before leaving edit mode with unsaved changes
        let formChanged = false;
        
        document.querySelectorAll('#updateForm input, #updateForm select, #updateForm textarea').forEach(input => {
            const originalValue = input.value;
            input.addEventListener('input', function() {
                if (this.value !== originalValue) {
                    formChanged = true;
                }
            });
        });
        
        document.querySelectorAll('a[href="student_account.php"]').forEach(link => {
            link.addEventListener('click', function(e) {
                if (formChanged) {
                    if (!confirm('You have unsaved changes. Are you sure you want to leave?')) {
                        e.preventDefault();
                    }
                }
            });
        });
        
        window.addEventListener('beforeunload', function(e) {
            if (formChanged) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
        
        // Reset form changed flag on successful submission
        document.getElementById('updateForm').addEventListener('submit', function() {
            formChanged = false;
        });

        // Auto-focus first input in edit mode
        document.addEventListener('DOMContentLoaded', function() {
            if (document.getElementById('name')) {
                document.getElementById('name').focus();
            }
        });
        <?php endif; ?>

        // Add smooth animations to buttons
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

        // Success message auto-hide
        <?php if ($success): ?>
        setTimeout(() => {
            const successMessage = document.querySelector('.message.success');
            if (successMessage) {
                successMessage.style.opacity = '0';
                successMessage.style.transform = 'translateY(-20px)';
                setTimeout(() => {
                    successMessage.remove();
                }, 300);
            }
        }, 1500);
        <?php endif; ?>

        // Info item hover animations
        document.querySelectorAll('.info-item').forEach(item => {
            item.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-5px) scale(1.02)';
                this.style.boxShadow = '0 15px 40px rgba(0, 0, 0, 0.2)';
            });
            
            item.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0) scale(1)';
                this.style.boxShadow = 'none';
            });
        });

        // Add entrance animations
        document.addEventListener('DOMContentLoaded', function() {
            const elements = document.querySelectorAll('.account-container, .info-item, .form-group');
            elements.forEach((el, index) => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    el.style.transition = 'all 0.6s ease';
                    el.style.opacity = '1';
                    el.style.transform = 'translateY(0)';
                }, index * 100);
            });
        });

        // Enhanced input focus effects
        document.querySelectorAll('input, select, textarea').forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.style.transform = 'scale(1.02)';
            });
            
            input.addEventListener('blur', function() {
                this.parentElement.style.transform = 'scale(1)';
            });
        });

        // Keyboard navigation improvements
        document.addEventListener('keydown', function(e) {
            // ESC key to cancel edit mode
            if (e.key === 'Escape' && <?= $editMode ? 'true' : 'false' ?>) {
                if (confirm('Are you sure you want to cancel editing?')) {
                    window.location.href = 'student_account.php';
                }
            }
        });

        // Console logging for debugging
        console.log('👤 Complete Student Account Page Loaded');
        console.log('📝 Edit Mode:', <?= $editMode ? 'true' : 'false' ?>);
        console.log('🔔 Unread Notifications:', <?= $unread_notifications ?>);
        console.log('✅ Enhanced Account System with All Student Fields Active!');
        console.log('📋 Available Fields: Name, Email, Contact, IC, Gender, Age, Address, Password');
        console.log('🛡️ Validation: IC format, age range (18-65), password strength, email format');
        console.log('🎨 UI Features: Password toggle, strength indicator, auto-formatting, responsive design');
    </script>
</body>
</html>