<?php
session_start();
require_once 'config.php';

// Check if user is logged in as student (using your existing session variable)
if (!isset($_SESSION['student_id']) || empty($_SESSION['student_id'])) {
    // Redirect to your existing student login page
    header("Location: student_login.php");
    exit();
}

// Get database connection
try {
    $pdo = getDbConnection();
} catch (PDOException $e) {
    $_SESSION['error'] = "Database connection failed. Please try again.";
    header("Location: student_dashboard.php");
    exit();
}

$student_id = $_SESSION['student_id'];

// Get student information
try {
    $stmt = $pdo->prepare("SELECT * FROM student WHERE StudentID = ?");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch();

    if (!$student) {
        // Student not found, destroy session and redirect
        session_destroy();
        header("Location: student_login.php");
        exit();
    }
} catch (Exception $e) {
    error_log("Error getting student info: " . $e->getMessage());
    header("Location: student_login.php");
    exit();
}

// Function to get student rewards using your exact SQL structure
function getStudentRewards($pdo, $student_id) {
    try {
        // Your exact SQL query as requested
        $stmt = $pdo->prepare("
            SELECT r.*
            FROM reward r
            JOIN registration reg ON r.RegistrationID = reg.RegistrationID
            WHERE reg.StudentID = ?
            ORDER BY r.RewardID DESC
        ");
        $stmt->execute([$student_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting student rewards: " . $e->getMessage());
        return [];
    }
}

// Get all rewards for this student
$student_rewards = getStudentRewards($pdo, $student_id);

// Calculate statistics
$total_rewards = count($student_rewards);
$total_points = array_sum(array_column($student_rewards, 'RewardPoint'));
$latest_reward = !empty($student_rewards) ? $student_rewards[0] : null;

// Get total registrations count (for statistics)
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM registration WHERE StudentID = ?");
    $stmt->execute([$student_id]);
    $total_registrations = $stmt->fetchColumn();
} catch (Exception $e) {
    $total_registrations = 0;
}

// Get notification count for badge
$notification_count = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notification WHERE StudentID = ? AND IsRead = 0");
    $stmt->execute([$student_id]);
    $notification_count = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    $notification_count = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Rewards & Achievements - LifeSaver Hub</title>
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
            margin: 0;
            padding: 0;
        }

        /* Full-Screen Fireworks Animation */
        .fireworks-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            z-index: 99999;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: fadeOut 3s ease-out forwards;
            pointer-events: none;
        }

        .fireworks-container {
            position: absolute;
            width: 100%;
            height: 100%;
            overflow: hidden;
        }

        .firework-burst {
            position: absolute;
            pointer-events: none;
        }

        .firework-burst::before,
        .firework-burst::after {
            content: '';
            position: absolute;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            animation: fireworkBurst 2s ease-out forwards;
        }

        .firework-burst::before {
            background: radial-gradient(circle, #ff6b6b, #feca57, #48cae4, #16a085);
            box-shadow: 
                0 0 60px #ff6b6b,
                20px 0 60px #feca57,
                40px 0 60px #48cae4,
                60px 0 60px #16a085,
                80px 0 60px #9b59b6,
                100px 0 60px #e74c3c;
        }

        .firework-burst::after {
            background: radial-gradient(circle, #e74c3c, #9b59b6, #3498db, #2ecc71);
            box-shadow: 
                0 0 60px #e74c3c,
                -20px 0 60px #9b59b6,
                -40px 0 60px #3498db,
                -60px 0 60px #2ecc71,
                -80px 0 60px #f39c12,
                -100px 0 60px #e67e22;
            animation-delay: 0.3s;
        }

        .celebration-text {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
            font-size: 4rem;
            font-weight: 900;
            text-align: center;
            z-index: 100000;
            text-shadow: 0 0 20px rgba(255, 255, 255, 0.8);
            animation: celebrationTextPop 3s ease-out forwards;
        }

        .celebration-text .emoji {
            font-size: 5rem;
            display: block;
            margin-bottom: 20px;
            animation: emojiSpin 2s ease-in-out infinite;
        }

        @keyframes fireworkBurst {
            0% {
                transform: scale(0) rotate(0deg);
                opacity: 1;
            }
            25% {
                opacity: 1;
            }
            100% {
                transform: scale(30) rotate(360deg);
                opacity: 0;
            }
        }

        @keyframes fadeOut {
            0% { opacity: 1; }
            85% { opacity: 1; }
            100% { opacity: 0; visibility: hidden; }
        }

        @keyframes celebrationTextPop {
            0% { 
                transform: translate(-50%, -50%) scale(0);
                opacity: 0;
            }
            30% { 
                transform: translate(-50%, -50%) scale(1.2);
                opacity: 1;
            }
            60% { 
                transform: translate(-50%, -50%) scale(1);
                opacity: 1;
            }
            85% { 
                opacity: 1;
            }
            100% { 
                transform: translate(-50%, -50%) scale(0.8);
                opacity: 0;
            }
        }

        @keyframes emojiSpin {
            0%, 100% { transform: rotate(0deg) scale(1); }
            25% { transform: rotate(90deg) scale(1.1); }
            50% { transform: rotate(180deg) scale(1.2); }
            75% { transform: rotate(270deg) scale(1.1); }
        }

        /* Particle system for additional effects */
        .particle {
            position: absolute;
            pointer-events: none;
            border-radius: 50%;
            animation: particleFloat 3s ease-out forwards;
        }

        @keyframes particleFloat {
            0% {
                transform: translateY(100vh) scale(0);
                opacity: 0;
            }
            10% {
                opacity: 1;
            }
            90% {
                opacity: 1;
            }
            100% {
                transform: translateY(-100vh) scale(1);
                opacity: 0;
            }
        }

        /* Main content initially hidden */
        .main-content {
            flex: 1;
            margin-left: 320px;
            padding: 32px;
            background: rgba(248, 249, 250, 0.3);
        }

        @keyframes fadeInContent {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Animated background elements - Consistent with dashboard */
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

        /* App container - Ensure proper layout structure */
        .app-container {
            display: block; /* Changed from flex to block for better sidebar control */
            min-height: 100vh;
            position: relative;
        }

        /* Enhanced Sidebar - FIXED TO STAY ON LEFT SIDE */
        /* Enhanced Sidebar - Consistent with notifications.php */
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

        /* Main Content - Properly positioned next to sidebar */
        .main-content {
            flex: 1;
            margin-left: 320px; /* MUST match sidebar width exactly */
            padding: 32px;
            background: rgba(248, 249, 250, 0.3);
            min-height: 100vh;
            position: relative;
            z-index: 1;
            width: calc(100% - 320px); /* Ensures proper width calculation */
            box-sizing: border-box;
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
            text-align: center;
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
            justify-content: center;
            gap: 20px;
            text-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .page-header h1 i {
            color: #667eea;
            animation: bounce 2s infinite;
        }

        .congratulations-banner {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            padding: 24px;
            border-radius: 20px;
            margin-bottom: 32px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(16, 185, 129, 0.3);
            position: relative;
            overflow: hidden;
        }

        .congratulations-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255,255,255,0.1), transparent);
            transform: rotate(45deg);
            animation: shimmer 3s infinite;
        }

        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% { transform: translateY(0); }
            40% { transform: translateY(-10px); }
            60% { transform: translateY(-5px); }
        }

        .congratulations-content {
            position: relative;
            z-index: 1;
        }

        .congratulations-content h2 {
            font-size: 2.2rem;
            margin-bottom: 12px;
            animation: fadeInUp 1s ease-out;
        }

        .congratulations-content p {
            font-size: 1.2rem;
            opacity: 0.9;
            animation: fadeInUp 1s ease-out 0.2s both;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 24px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 32px;
            border-radius: 20px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
            transition: transform 0.3s ease;
            position: relative;
            overflow: hidden;
            animation: slideInUp 0.8s ease-out;
        }

        .stat-card:nth-child(2) { animation-delay: 0.2s; }
        .stat-card:nth-child(3) { animation-delay: 0.4s; }

        .stat-card::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255,255,255,0.1), transparent);
            transform: rotate(45deg);
            transition: transform 0.6s;
        }

        .stat-card:hover {
            transform: translateY(-8px) scale(1.02);
        }

        .stat-card:hover::before {
            transform: rotate(45deg) translate(100%, 100%);
        }

        .stat-icon {
            font-size: 3.5rem;
            margin-bottom: 16px;
            animation: float 3s ease-in-out infinite;
        }

        .stat-icon:nth-child(1) { animation-delay: 0s; }
        .stat-icon:nth-child(2) { animation-delay: 1s; }
        .stat-icon:nth-child(3) { animation-delay: 2s; }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }

        .stat-number {
            font-size: 3rem;
            font-weight: 900;
            margin-bottom: 8px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .stat-label {
            font-size: 1.1rem;
            opacity: 0.9;
            font-weight: 600;
        }

        .rewards-section {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 32px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(0, 0, 0, 0.05);
            margin-bottom: 32px;
        }

        .section-title {
            font-size: 2rem;
            font-weight: 700;
            color: #2d3748;
            text-align: center;
            margin-bottom: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
        }

        .rewards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 24px;
        }

        .reward-card {
            background: rgba(255, 255, 255, 0.9);
            border-radius: 20px;
            padding: 32px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            border: 2px solid transparent;
            transition: all 0.4s ease;
            position: relative;
            overflow: hidden;
            animation: fadeInScale 0.6s ease-out;
        }

        .reward-card:nth-child(even) { animation-delay: 0.2s; }
        .reward-card:nth-child(3n) { animation-delay: 0.4s; }

        .reward-card:hover {
            transform: translateY(-10px) scale(1.02);
            box-shadow: 0 20px 40px rgba(102, 126, 234, 0.2);
            border-color: #667eea;
        }

        .reward-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea, #764ba2, #10b981);
            border-radius: 20px 20px 0 0;
        }

        .reward-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 20px;
        }

        .reward-icon {
            font-size: 4rem;
            width: 80px;
            height: 80px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
            animation: pulse 2s infinite;
        }

        .reward-info {
            flex: 1;
        }

        .reward-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 8px;
        }

        .reward-points {
            display: inline-block;
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            padding: 8px 16px;
            border-radius: 25px;
            font-size: 1rem;
            font-weight: 700;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
        }

        .reward-description {
            color: #4a5568;
            font-size: 1.1rem;
            line-height: 1.6;
            margin: 20px 0;
            padding: 16px;
            background: rgba(102, 126, 234, 0.05);
            border-radius: 12px;
            border-left: 4px solid #667eea;
        }

        .reward-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 16px;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 2px solid rgba(102, 126, 234, 0.1);
        }

        .meta-item {
            text-align: center;
            padding: 12px;
            background: rgba(248, 249, 250, 0.8);
            border-radius: 12px;
        }

        .meta-label {
            font-size: 0.85rem;
            color: #667eea;
            font-weight: 600;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .meta-value {
            font-size: 1rem;
            font-weight: 700;
            color: #2d3748;
        }

        .empty-state {
            text-align: center;
            padding: 80px 40px;
            color: #4a5568;
        }

        .empty-state i {
            font-size: 6rem;
            margin-bottom: 24px;
            opacity: 0.3;
            color: #a0aec0;
            animation: float 3s ease-in-out infinite;
        }

        .empty-state h3 {
            color: #2d3748;
            font-size: 2rem;
            margin-bottom: 16px;
            font-weight: 700;
        }

        .empty-state p {
            font-size: 1.1rem;
            margin-bottom: 24px;
            line-height: 1.6;
        }

        .btn {
            padding: 16px 32px;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 35px rgba(102, 126, 234, 0.4);
        }

        .btn-success {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.3);
        }

        .btn-success:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 35px rgba(16, 185, 129, 0.4);
        }

        /* Mobile menu button */
        .mobile-menu-btn {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1002;
            background: rgba(30, 41, 59, 0.9);
            border: none;
            color: white;
            padding: 12px;
            border-radius: 12px;
            cursor: pointer;
            backdrop-filter: blur(20px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
        }

        /* Responsive Design - Enhanced for proper sidebar behavior */
        @media (max-width: 1200px) {
            .sidebar {
                width: 300px;
            }
            .main-content {
                margin-left: 300px;
                width: calc(100% - 300px);
            }
        }

        @media (max-width: 968px) {
            .mobile-menu-btn {
                display: block;
            }
            
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
                position: fixed;
                top: 0;
                left: 0;
                bottom: 0;
                height: 100vh;
                width: 320px;
                z-index: 1001;
            }
            
            .sidebar.open {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 24px 16px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .rewards-grid {
                grid-template-columns: 1fr;
            }
            
            .page-header h1 {
                font-size: 2.2rem;
                flex-direction: column;
            }

            .congratulations-content h2 {
                font-size: 1.8rem;
            }

            .celebration-text {
                font-size: 2.5rem;
            }

            .celebration-text .emoji {
                font-size: 3rem;
            }
        }

        @media (max-width: 480px) {
            .page-header {
                padding: 32px 24px;
            }
            
            .stat-number {
                font-size: 2.2rem;
            }
            
            .reward-meta {
                grid-template-columns: 1fr;
            }

            .celebration-text {
                font-size: 2rem;
            }

            .celebration-text .emoji {
                font-size: 2.5rem;
            }
        }

        /* Animation Keyframes */
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

        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeInScale {
            from {
                opacity: 0;
                transform: scale(0.8);
            }
            to {
                opacity: 1;
                transform: scale(1);
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
    </style>
</head>
<body>
    <!-- Full-Screen Fireworks Overlay -->
    <div class="fireworks-overlay" id="fireworksOverlay">
        <div class="fireworks-container" id="fireworksContainer">
            <!-- Fireworks will be generated here -->
        </div>
        <div class="celebration-text">
            <span class="emoji">🎉</span>
            Congratulations!<br>
            <span style="font-size: 2.5rem;">🏆 Amazing Achievements! 🏆</span>
        </div>
    </div>

    <!-- Main App Content -->
    <div class="main-app-content">
        <!-- Mobile menu button -->
        <button class="mobile-menu-btn" onclick="toggleSidebar()">
            <i class="fas fa-bars"></i>
        </button>

        <div class="app-container">
            <!-- Enhanced Sidebar - EXACTLY the same as student_dashboard.php -->
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
                            <a href="view_reward.php" class="nav-item active">
                                <i class="fas fa-gift"></i>
                                <span>Rewards</span>
                            </a>
                            <a href="notifications.php" class="nav-item">
                                <i class="fas fa-bell"></i>
                                <span>Notifications</span>
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
                            <?php echo strtoupper(substr($student['StudentName'], 0, 1)); ?>
                        </div>
                        <div class="user-details">
                            <h4><?php echo htmlspecialchars($student['StudentName']); ?></h4>
                            <p>Student ID: <?php echo htmlspecialchars($student_id); ?></p>
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
                            <i class="fas fa-trophy"></i>
                            My Rewards & Achievements
                        </h1>
                        <p>Celebrate your life-saving contributions and earned rewards</p>
                    </div>
                </div>

                <!-- Congratulations Banner -->
                <?php if ($total_rewards > 0): ?>
                    <div class="congratulations-banner">
                        <div class="congratulations-content">
                            <h2>🎉 Congratulations, <?= htmlspecialchars($student['StudentName']) ?>! 🎉</h2>
                            <p>You've earned <strong><?= $total_rewards ?></strong> reward<?= $total_rewards > 1 ? 's' : '' ?> for your amazing contributions to saving lives!</p>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Statistics Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">🏆</div>
                        <div class="stat-number"><?= $total_rewards ?></div>
                        <div class="stat-label">Total Rewards</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon">⭐</div>
                        <div class="stat-number"><?= number_format($total_points) ?></div>
                        <div class="stat-label">Total Points</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon">📝</div>
                        <div class="stat-number"><?= $total_registrations ?></div>
                        <div class="stat-label">Event Registrations</div>
                    </div>
                </div>

                <!-- Rewards Section -->
                <div class="rewards-section">
                    <h3 class="section-title">
                        <i class="fas fa-medal"></i>
                        Your Achievement Rewards
                    </h3>

                    <?php if (!empty($student_rewards)): ?>
                        <div class="rewards-grid">
                            <?php foreach ($student_rewards as $reward): ?>
                                <div class="reward-card">
                                    <div class="reward-header">
                                        <div class="reward-icon">
                                            🏅
                                        </div>
                                        <div class="reward-info">
                                            <div class="reward-title">
                                                <?= htmlspecialchars($reward['RewardTitle']) ?>
                                            </div>
                                            <div class="reward-points">
                                                +<?= number_format($reward['RewardPoint']) ?> points
                                            </div>
                                        </div>
                                    </div>

                                    <div class="reward-description">
                                        <?= htmlspecialchars($reward['RewardDescription']) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-gift"></i>
                            <h3>You have no rewards yet</h3>
                            <p>Keep donating and help save lives!<br>Your contributions will be recognized with amazing rewards.</p>
                            <a href="student_view_event.php" class="btn btn-success">
                                <i class="fas fa-plus"></i>
                                Register for Blood Donation
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Motivation Section -->
                <div style="background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(139, 92, 246, 0.1)); border-radius: 24px; padding: 40px; text-align: center; margin-bottom: 32px;">
                    <h3 style="color: #2d3748; margin-bottom: 20px; font-size: 1.8rem;">
                        <i class="fas fa-heart" style="color: #e74c3c;"></i>
                        Keep Making a Difference!
                    </h3>
                    <p style="color: #4a5568; font-size: 1.1rem; margin-bottom: 24px; line-height: 1.6;">
                        Every blood donation can save up to <strong>3 lives</strong>. Your contributions make a real difference in our community!
                    </p>
                    <div style="display: flex; justify-content: center; gap: 16px; flex-wrap: wrap;">
                        <a href="student_view_event.php" class="btn btn-primary">
                            <i class="fas fa-calendar-plus"></i>
                            Register for Next Event
                        </a>
                    </div>
                </div>

                <!-- Latest Achievement Highlight -->
                <?php if ($latest_reward): ?>
                    <div style="background: rgba(255, 255, 255, 0.9); backdrop-filter: blur(20px); border-radius: 20px; padding: 32px; margin-bottom: 32px; box-shadow: 0 15px 40px rgba(0, 0, 0, 0.08); border: 2px solid #10b981;">
                        <h3 style="color: #10b981; margin-bottom: 20px; font-size: 1.5rem; text-align: center;">
                            <i class="fas fa-star"></i>
                            Latest Achievement
                        </h3>
                        <div style="background: rgba(16, 185, 129, 0.05); padding: 24px; border-radius: 16px; text-align: center;">
                            <div style="font-size: 3rem; margin-bottom: 16px;">🏆</div>
                            <h4 style="color: #2d3748; margin-bottom: 12px; font-size: 1.3rem;">
                                <?= htmlspecialchars($latest_reward['RewardTitle']) ?>
                            </h4>
                            <p style="color: #4a5568; margin-bottom: 16px;">
                                <?= htmlspecialchars($latest_reward['RewardDescription']) ?>
                            </p>
                            <div style="display: inline-block; background: #10b981; color: white; padding: 8px 16px; border-radius: 20px; font-weight: 600;">
                                +<?= number_format($latest_reward['RewardPoint']) ?> Points Earned
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Action Buttons -->
                <div style="text-align: center; padding: 20px; margin-top: 32px;">
                    <button onclick="shareProgress()" class="btn btn-success">
                        <i class="fas fa-share"></i>
                        Share My Progress
                    </button>
                </div>
            </main>
        </div>
    </div>

    <script>
        // Create and display full-screen fireworks animation
        function createFullScreenFireworks() {
            const container = document.getElementById('fireworksContainer');
            const colors = ['#ff6b6b', '#feca57', '#48cae4', '#16a085', '#9b59b6', '#e74c3c', '#2ecc71', '#f39c12'];
            
            // Create multiple firework bursts at different positions
            for (let i = 0; i < 15; i++) {
                setTimeout(() => {
                    const firework = document.createElement('div');
                    firework.className = 'firework-burst';
                    
                    // Random position
                    const x = Math.random() * window.innerWidth;
                    const y = Math.random() * window.innerHeight;
                    
                    firework.style.left = x + 'px';
                    firework.style.top = y + 'px';
                    
                    // Random colors for the firework
                    const color1 = colors[Math.floor(Math.random() * colors.length)];
                    const color2 = colors[Math.floor(Math.random() * colors.length)];
                    
                    firework.style.setProperty('--color1', color1);
                    firework.style.setProperty('--color2', color2);
                    
                    container.appendChild(firework);
                    
                    // Remove firework after animation completes
                    setTimeout(() => {
                        if (firework.parentNode) {
                            firework.parentNode.removeChild(firework);
                        }
                    }, 2000);
                }, i * 200);
            }

            // Create floating particles
            createFloatingParticles();
        }

        function createFloatingParticles() {
            const container = document.getElementById('fireworksContainer');
            const particles = ['🎉', '🎊', '⭐', '✨', '🏆', '🎈', '🌟', '💫', '🎁', '🏅'];
            
            for (let i = 0; i < 30; i++) {
                setTimeout(() => {
                    const particle = document.createElement('div');
                    particle.className = 'particle';
                    particle.textContent = particles[Math.floor(Math.random() * particles.length)];
                    
                    // Random properties
                    const size = Math.random() * 20 + 15;
                    const x = Math.random() * window.innerWidth;
                    const delay = Math.random() * 2;
                    
                    particle.style.left = x + 'px';
                    particle.style.fontSize = size + 'px';
                    particle.style.animationDelay = delay + 's';
                    particle.style.width = size + 'px';
                    particle.style.height = size + 'px';
                    
                    container.appendChild(particle);
                    
                    // Remove particle after animation
                    setTimeout(() => {
                        if (particle.parentNode) {
                            particle.parentNode.removeChild(particle);
                        }
                    }, 3000 + delay * 1000);
                }, i * 100);
            }
        }

        // Mobile sidebar toggle - EXACTLY the same as dashboard
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

        // Share progress function
        function shareProgress() {
            const totalRewards = <?= $total_rewards ?>;
            const totalPoints = <?= $total_points ?>;
            const totalRegistrations = <?= $total_registrations ?>;
            
            const shareText = `🏆 My Blood Donation Journey:\n✨ ${totalRewards} achievement rewards\n⭐ ${totalPoints} points earned\n📝 ${totalRegistrations} event registrations\n\nJoin me in saving lives! #BloodDonation #LifeSaver #CommunityHero`;
            
            if (navigator.share) {
                navigator.share({
                    title: 'My Blood Donation Achievements',
                    text: shareText,
                    url: window.location.href
                });
            } else {
                // Fallback: copy to clipboard
                navigator.clipboard.writeText(shareText).then(() => {
                    showNotification('Achievement summary copied to clipboard! 📋', 'success');
                }).catch(() => {
                    prompt('Copy this text to share your achievements:', shareText);
                });
            }
        }

        // Notification system
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: ${type === 'success' ? 'linear-gradient(135deg, #10b981, #059669)' : 'linear-gradient(135deg, #667eea, #764ba2)'};
                color: white;
                padding: 16px 24px;
                border-radius: 12px;
                box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
                z-index: 10000;
                font-weight: 600;
                transform: translateX(400px);
                transition: all 0.3s ease;
                max-width: 300px;
            `;
            notification.textContent = message;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.transform = 'translateX(0)';
            }, 100);
            
            setTimeout(() => {
                notification.style.transform = 'translateX(400px)';
                setTimeout(() => notification.remove(), 300);
            }, 4000);
        }

        // Page initialization
        document.addEventListener('DOMContentLoaded', function() {
            // Start fireworks animation immediately
            createFullScreenFireworks();
            
            // Remove the fireworks overlay after 3 seconds
            setTimeout(() => {
                const overlay = document.getElementById('fireworksOverlay');
                overlay.style.display = 'none';
            }, 3000);

            // Animate reward cards on load (after fireworks)
            setTimeout(() => {
                const rewardCards = document.querySelectorAll('.reward-card');
                rewardCards.forEach((card, index) => {
                    card.style.opacity = '0';
                    card.style.transform = 'translateY(30px)';
                    setTimeout(() => {
                        card.style.transition = 'all 0.6s ease';
                        card.style.opacity = '1';
                        card.style.transform = 'translateY(0)';
                    }, index * 200);
                });

                // Animate stat cards
                const statCards = document.querySelectorAll('.stat-card');
                statCards.forEach((card, index) => {
                    card.style.opacity = '0';
                    card.style.transform = 'scale(0.8)';
                    setTimeout(() => {
                        card.style.transition = 'all 0.6s ease';
                        card.style.opacity = '1';
                        card.style.transform = 'scale(1)';
                    }, index * 150);
                });
            }, 3200);

            console.log('🎉 Rewards page loaded with fireworks animation!');
            console.log('📊 User stats:', {
                rewards: <?= $total_rewards ?>,
                points: <?= $total_points ?>,
                registrations: <?= $total_registrations ?>
            });
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.key === 's' && e.ctrlKey) {
                e.preventDefault();
                shareProgress();
            }
        });

        // Easter egg: Konami code for extra fireworks
        let konamiCode = [];
        const konamiSequence = ['ArrowUp', 'ArrowUp', 'ArrowDown', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'ArrowLeft', 'ArrowRight', 'KeyB', 'KeyA'];
        
        document.addEventListener('keydown', function(e) {
            konamiCode.push(e.code);
            if (konamiCode.length > konamiSequence.length) {
                konamiCode.shift();
            }
            
            if (JSON.stringify(konamiCode) === JSON.stringify(konamiSequence)) {
                // Show fireworks overlay again temporarily
                const overlay = document.getElementById('fireworksOverlay');
                overlay.style.display = 'flex';
                overlay.style.animation = 'fadeOut 4s ease-out forwards';
                createFullScreenFireworks();
                setTimeout(() => {
                    overlay.style.display = 'none';
                }, 4000);
                showNotification('🎊 SUPER CELEBRATION UNLOCKED! 🎊', 'success');
                konamiCode = [];
            }
        });

        // Animate numbers on page load with more realistic animation
        document.addEventListener('DOMContentLoaded', function() {
            const numbers = document.querySelectorAll('.stat-card .stat-number');
            numbers.forEach(number => {
                const finalValue = parseInt(number.textContent);
                let currentValue = 0;
                const increment = Math.max(1, finalValue / 30);
                
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

        // Debug information
        console.log('🎯 LifeSaver Hub - Rewards Page Loaded');
        console.log('Student ID:', <?= $student_id ?>);
        console.log('Student Name:', '<?= addslashes($student['StudentName'] ?? 'Unknown') ?>');
        console.log('Rewards page ready with enhanced features and consistent theme!');
    </script>
</body>
</html>