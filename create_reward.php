<?php
session_start();
require_once 'config.php';

// Check if user is logged in and is staff
if (!isset($_SESSION['staff_id']) || empty($_SESSION['staff_id'])) {
    echo "<script>alert('Access denied. Please login as staff first.'); window.location.href='index.php';</script>";
    exit();
}

// Initialize session properly
if (function_exists('initializeSession')) {
    if (!initializeSession()) {
        header("Location: login.php?timeout=1");
        exit();
    }
}

// Get database connection
try {
    $pdo = getDbConnection();
    
    // Ensure RewardID is properly set up as auto-increment
    ensureRewardTableStructure($pdo);
    
} catch (PDOException $e) {
    $_SESSION['error'] = handleDatabaseError($e);
    header("Location: staff_dashboard.php");
    exit();
}

// Fetch staff data
$stmt = $pdo->prepare("SELECT * FROM staff WHERE StaffID = ?");
$stmt->execute([$_SESSION['staff_id']]);
$staff = $stmt->fetch();

if (!$staff) {
    session_destroy();
    header("Location: login.php");
    exit();
}

$success_message = '';
$error_message = '';
$rewardsCreated = [];

// Default reward settings
$default_points = 100;
$default_badge_name = 'Blood Donation Hero';
$default_badge_description = 'Thank you for your generous blood donation!';

// Function to get available event dates with eligible students
function getEventDates($pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT 
                DATE(r.RegistrationDate) as event_date,
                COUNT(DISTINCT r.RegistrationID) as total_students,
                COUNT(DISTINCT CASE WHEN rw.RegistrationID IS NOT NULL THEN r.RegistrationID END) as students_with_rewards
            FROM registration r
            LEFT JOIN reward rw ON r.RegistrationID = rw.RegistrationID
            WHERE LOWER(TRIM(r.RegistrationStatus)) = 'confirmed' 
            AND LOWER(TRIM(r.AttendanceStatus)) = 'present'
            AND r.EventID IS NOT NULL
            AND r.EventID > 0
            GROUP BY DATE(r.RegistrationDate)
            HAVING total_students > 0
            ORDER BY event_date DESC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting event dates: " . $e->getMessage());
        return [];
    }
}

// Function to get eligible students by date (all events on that date)
function getEligibleStudentsByDate($pdo, $date) {
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT
                r.RegistrationID,
                r.StudentID,
                r.RegistrationDate,
                r.RegistrationStatus,
                r.AttendanceStatus,
                r.EventID,
                COALESCE(s.StudentName, CONCAT('Student #', r.StudentID)) as StudentName,
                COALESCE(s.StudentEmail, 'No email') as StudentEmail,
                rw.RewardID as existing_reward_id,
                rw.RewardTitle as existing_reward_title,
                rw.RewardPoint as existing_reward_points
            FROM registration r
            LEFT JOIN student s ON r.StudentID = s.StudentID
            LEFT JOIN reward rw ON r.RegistrationID = rw.RegistrationID
            WHERE DATE(r.RegistrationDate) = ?
            AND LOWER(TRIM(r.RegistrationStatus)) = 'confirmed'
            AND LOWER(TRIM(r.AttendanceStatus)) = 'present'
            AND r.EventID IS NOT NULL
            AND r.EventID > 0
            ORDER BY r.EventID, r.RegistrationID
        ");
        $stmt->execute([$date]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting eligible students: " . $e->getMessage());
        return [];
    }
}

// Function to ensure RewardID is auto-increment
function ensureRewardTableStructure($pdo) {
    try {
        // Check current table structure
        $check_stmt = $pdo->prepare("SHOW COLUMNS FROM reward WHERE Field = 'RewardID'");
        $check_stmt->execute();
        $column_info = $check_stmt->fetch();
        
        if ($column_info && strpos($column_info['Extra'], 'auto_increment') === false) {
            // RewardID exists but is not auto-increment, fix it
            $pdo->exec("ALTER TABLE reward MODIFY RewardID INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY");
            error_log("Fixed RewardID to be auto-increment");
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Error checking/fixing reward table structure: " . $e->getMessage());
        return false;
    }
}

// Function to create reward for student with proper duplicate checking
function createRewardForStudent($student_data, $points, $title, $description, $staff_id, $pdo) {
    try {
        // Double-check for existing reward to prevent duplicates
        $check_stmt = $pdo->prepare("
            SELECT RewardID FROM reward 
            WHERE RegistrationID = ? 
            LIMIT 1
        ");
        $check_stmt->execute([$student_data['RegistrationID']]);
        
        if ($check_stmt->rowCount() > 0) {
            return false; // Already has a reward - prevent duplicate
        }
        
        // Create the reward entry WITHOUT RewardID (let auto-increment handle it)
        $reward_stmt = $pdo->prepare("
            INSERT INTO reward (
                RewardPoint, StaffID, RewardTitle, RewardDescription, 
                RewardType, RegistrationID
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $success = $reward_stmt->execute([
            $points,
            $staff_id,
            $title,
            $description,
            'achievement',
            $student_data['RegistrationID']
        ]);
        
        if ($success) {
            // Get the auto-generated RewardID to confirm it worked
            $new_reward_id = $pdo->lastInsertId();
            error_log("Successfully created reward with RewardID: " . $new_reward_id);
            return $new_reward_id; // Return the new ID instead of just true
        }
        
        return false;
        
    } catch (Exception $e) {
        error_log("Error creating reward: " . $e->getMessage());
        return false;
    }
}

// Handle form submission - IMMEDIATE ASSIGNMENT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_date'])) {
    $selected_date = filter_input(INPUT_POST, 'assign_date', FILTER_SANITIZE_STRING);
    $custom_points = filter_input(INPUT_POST, 'points', FILTER_SANITIZE_NUMBER_INT) ?: $default_points;
    
    if (empty($selected_date)) {
        $error_message = "Please select an event date.";
    } elseif ($custom_points < 10 || $custom_points > 500) {
        $error_message = "Points must be between 10 and 500.";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Get all eligible students for the selected date
            $eligible_students = getEligibleStudentsByDate($pdo, $selected_date);
            
            if (empty($eligible_students)) {
                $error_message = "No eligible students found for the selected date.";
            } else {
                $created_count = 0;
                $skipped_count = 0;
                
                foreach ($eligible_students as $student) {
                    // Skip if student already has a reward
                    if (!empty($student['existing_reward_id'])) {
                        $skipped_count++;
                        continue;
                    }
                    
                    if (createRewardForStudent($student, $custom_points, $default_badge_name, $default_badge_description, $_SESSION['staff_id'], $pdo)) {
                        $rewardsCreated[] = [
                            'student' => htmlspecialchars($student['StudentName']),
                            'student_id' => $student['StudentID'],
                            'event_id' => $student['EventID'],
                            'points' => $custom_points
                        ];
                        $created_count++;
                    }
                }
                
                $pdo->commit();
                
                if ($created_count > 0) {
                    $success_message = "✅ Reward points successfully assigned to {$created_count} eligible students on " . date('F j, Y', strtotime($selected_date)) . "!";
                    if ($skipped_count > 0) {
                        $success_message .= " ({$skipped_count} students already had rewards and were skipped)";
                    }
                } else {
                    $error_message = "No new rewards were created. All eligible students already have rewards for this date.";
                }
            }
            
        } catch (Exception $e) {
            $pdo->rollback();
            $error_message = "Error creating rewards: " . $e->getMessage();
            error_log("Reward creation error: " . $e->getMessage());
        }
    }
}

// Get data
$event_dates = getEventDates($pdo);

// Calculate statistics
$total_dates = count($event_dates);
$total_eligible = array_sum(array_column($event_dates, 'total_students'));
$total_with_rewards = array_sum(array_column($event_dates, 'students_with_rewards'));
$completion_rate = $total_eligible > 0 ? round(($total_with_rewards / $total_eligible) * 100, 1) : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign Reward Points - LifeSaver Hub</title>
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

        /* Sidebar Styles */
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
            padding: 40px 48px;
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
            font-size: 2.5rem;
            font-weight: 900;
            color: #2d3748;
            margin-bottom: 12px;
            text-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
        }

        .page-header .subtitle {
            color: #4a5568;
            font-size: 18px;
            font-weight: 500;
            opacity: 0.9;
        }

        .alert {
            padding: 20px 24px;
            border-radius: 16px;
            margin-bottom: 24px;
            border-left: 4px solid;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            font-weight: 600;
            backdrop-filter: blur(20px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .alert.success {
            background: rgba(212, 237, 218, 0.9);
            border-left-color: #28a745;
            color: #155724;
        }

        .alert.error {
            background: rgba(248, 215, 218, 0.9);
            border-left-color: #dc3545;
            color: #721c24;
        }

        .content-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 24px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(0, 0, 0, 0.05);
            position: relative;
            overflow: hidden;
        }

        .content-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea, #764ba2);
            border-radius: 24px 24px 0 0;
        }

        .content-card h3 {
            color: #2d3748;
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 24px;
            border-radius: 16px;
            text-align: center;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 35px rgba(102, 126, 234, 0.4);
        }

        .stat-number {
            font-size: 2.2rem;
            font-weight: 900;
            margin-bottom: 8px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .stat-label {
            font-size: 14px;
            opacity: 0.9;
            font-weight: 600;
        }

        .assignment-section {
            background: linear-gradient(135deg, rgba(39, 174, 96, 0.1), rgba(32, 201, 151, 0.1));
            border-radius: 24px;
            padding: 40px;
            margin-bottom: 24px;
            border: 2px solid rgba(39, 174, 96, 0.2);
            text-align: center;
        }

        .assignment-section h3 {
            color: #27ae60;
            font-size: 24px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            align-items: end;
            margin-bottom: 30px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-label {
            display: block;
            color: #2d3748;
            font-weight: 700;
            margin-bottom: 8px;
            font-size: 16px;
        }

        .form-select, .form-input {
            width: 100%;
            padding: 16px 20px;
            border: 2px solid rgba(39, 174, 96, 0.2);
            border-radius: 12px;
            font-size: 16px;
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .form-select:focus, .form-input:focus {
            outline: none;
            border-color: #27ae60;
            box-shadow: 0 0 0 3px rgba(39, 174, 96, 0.1);
            background: white;
        }

        .btn-assign {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
            padding: 18px 40px;
            border: none;
            border-radius: 12px;
            font-size: 18px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 8px 25px rgba(39, 174, 96, 0.3);
        }

        .btn-assign:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 35px rgba(39, 174, 96, 0.4);
        }

        .btn-assign:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .success-rewards {
            background: rgba(39, 174, 96, 0.1);
            backdrop-filter: blur(20px);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 32px;
            border: 2px solid rgba(39, 174, 96, 0.3);
        }

        .rewards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 16px;
            margin-top: 16px;
        }

        .reward-item {
            background: rgba(255, 255, 255, 0.9);
            padding: 16px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 4px 15px rgba(39, 174, 96, 0.1);
            transition: all 0.3s ease;
        }

        .reward-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(39, 174, 96, 0.2);
        }

        .reward-icon {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            box-shadow: 0 4px 15px rgba(39, 174, 96, 0.3);
        }

        .reward-details {
            flex: 1;
        }

        .reward-student {
            font-weight: 700;
            color: #2d3748;
            font-size: 14px;
            margin-bottom: 2px;
        }

        .reward-meta {
            color: #4a5568;
            font-size: 12px;
            font-weight: 500;
        }

        .dates-list {
            max-height: 300px;
            overflow-y: auto;
            background: rgba(248, 249, 250, 0.8);
            border-radius: 12px;
            padding: 16px;
        }

        .date-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 16px;
            margin-bottom: 8px;
            background: white;
            border-radius: 8px;
            border-left: 4px solid #667eea;
            transition: all 0.3s ease;
        }

        .date-item:hover {
            transform: translateX(4px);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.15);
        }

        .date-info h4 {
            color: #2d3748;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .date-stats {
            font-size: 13px;
            color: #4a5568;
        }

        .completion-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .completion-badge.complete {
            background: rgba(39, 174, 96, 0.15);
            color: #27ae60;
        }

        .completion-badge.partial {
            background: rgba(255, 193, 7, 0.15);
            color: #ffc107;
        }

        .completion-badge.none {
            background: rgba(231, 76, 60, 0.15);
            color: #e74c3c;
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
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
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
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .page-header h1 {
                font-size: 2rem;
            }

            .rewards-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .page-header {
                padding: 24px;
            }
            
            .content-card {
                padding: 20px;
            }

            .assignment-section {
                padding: 24px;
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
    <!-- Mobile menu button -->
    <button class="mobile-menu-btn" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>

    <div class="app-container">
        <!-- Sidebar -->
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
                        <a href="create_reward.php" class="nav-item active"> 
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
                        <i class="fas fa-gift"></i>
                        Assign Reward Points
                    </h1>
                    <div class="subtitle">Simple one-click reward assignment for all eligible donors</div>
                </div>
            </div>

            <!-- Success/Error Messages -->
            <?php if ($success_message): ?>
                <div class="alert success">
                    <i class="fas fa-check-circle"></i>
                    <div>
                        <strong>Success!</strong><br>
                        <?= htmlspecialchars($success_message) ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="alert error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <strong>Error!</strong><br>
                        <?= htmlspecialchars($error_message) ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Display newly created rewards -->
            <?php if (!empty($rewardsCreated)): ?>
                <div class="success-rewards">
                    <h4 style="margin-bottom: 20px; color: #27ae60; font-weight: 700; font-size: 24px;">
                        <i class="fas fa-trophy"></i>
                        Successfully Assigned Rewards to <?= count($rewardsCreated) ?> Students!
                    </h4>
                    <div class="rewards-grid">
                        <?php foreach ($rewardsCreated as $reward): ?>
                            <div class="reward-item">
                                <div class="reward-icon">🎉</div>
                                <div class="reward-details">
                                    <div class="reward-student"><?= $reward['student'] ?></div>
                                    <div class="reward-meta">+<?= $reward['points'] ?> points | Student ID: <?= $reward['student_id'] ?> | Event #<?= $reward['event_id'] ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="content-card">
                <h3>
                    <i class="fas fa-chart-bar"></i>
                    Reward Assignment Overview
                </h3>
                
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number"><?= $total_dates ?></div>
                        <div class="stat-label">Available Event Dates</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?= $total_eligible ?></div>
                        <div class="stat-label">Total Eligible Students</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?= $total_with_rewards ?></div>
                        <div class="stat-label">Students with Rewards</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?= $completion_rate ?>%</div>
                        <div class="stat-label">Completion Rate</div>
                    </div>
                </div>
            </div>

            <!-- Main Assignment Section -->
            <div class="assignment-section">
                <h3>
                    <i class="fas fa-magic"></i>
                    Quick Reward Assignment
                </h3>
                
                <p style="color: #4a5568; margin-bottom: 30px; font-size: 16px;">
                    Select an event date to automatically assign reward points to all eligible students<br>
                    <small>(Students with confirmed registration and present attendance)</small>
                </p>

                <?php if (!empty($event_dates)): ?>
                    <form method="POST" action="" id="assignmentForm">
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Select Event Date</label>
                                <select name="assign_date" class="form-select" required>
                                    <option value="">Choose an event date to assign rewards...</option>
                                    <?php foreach ($event_dates as $date_option): ?>
                                        <?php 
                                        $pending_students = $date_option['total_students'] - $date_option['students_with_rewards'];
                                        $status = '';
                                        if ($pending_students == 0) {
                                            $status = ' ✅ (All rewarded)';
                                        } elseif ($date_option['students_with_rewards'] > 0) {
                                            $status = " ⚠️ ({$pending_students} pending)";
                                        } else {
                                            $status = " 🎯 (Ready for rewards)";
                                        }
                                        ?>
                                        <option value="<?= $date_option['event_date'] ?>">
                                            <?= date('F j, Y', strtotime($date_option['event_date'])) ?> 
                                            - <?= $date_option['total_students'] ?> eligible students<?= $status ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Points to Assign</label>
                                <input type="number" name="points" class="form-input" min="10" max="500" value="<?= $default_points ?>" required>
                                <small style="color: #4a5568; font-size: 0.8rem;">Points per student (10-500)</small>
                            </div>
                        </div>

                        <button type="submit" class="btn-assign" id="assignBtn">
                            <i class="fas fa-magic"></i>
                            Assign Rewards to All Eligible Students
                        </button>
                    </form>
                <?php else: ?>
                    <div style="background: rgba(231, 76, 60, 0.1); border: 2px solid rgba(231, 76, 60, 0.2); border-radius: 12px; padding: 30px;">
                        <h4 style="color: #e74c3c; margin-bottom: 15px;">
                            <i class="fas fa-exclamation-triangle"></i>
                            No Event Dates Available
                        </h4>
                        <p style="color: #4a5568; margin-bottom: 15px;">No events found with eligible students for reward assignment.</p>
                        <div style="background: white; padding: 20px; border-radius: 8px;">
                            <h5 style="color: #2d3748; margin-bottom: 10px;">Requirements for Eligible Students:</h5>
                            <ul style="color: #4a5568; line-height: 1.6;">
                                <li>✅ Registration Status = <strong>'confirmed'</strong></li>
                                <li>✅ Attendance Status = <strong>'present'</strong></li>
                                <li>✅ Valid EventID assignment</li>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Available Event Dates List -->
            <?php if (!empty($event_dates)): ?>
                <div class="content-card">
                    <h3>
                        <i class="fas fa-calendar-alt"></i>
                        Available Event Dates (<?= count($event_dates) ?> dates)
                    </h3>
                    
                    <div class="dates-list">
                        <?php foreach ($event_dates as $date_option): ?>
                            <?php 
                            $pending_students = $date_option['total_students'] - $date_option['students_with_rewards'];
                            $completion_percentage = $date_option['total_students'] > 0 ? 
                                round(($date_option['students_with_rewards'] / $date_option['total_students']) * 100) : 0;
                            
                            $badge_class = 'none';
                            if ($completion_percentage == 100) {
                                $badge_class = 'complete';
                            } elseif ($completion_percentage > 0) {
                                $badge_class = 'partial';
                            }
                            ?>
                            <div class="date-item">
                                <div class="date-info">
                                    <h4><?= date('F j, Y (l)', strtotime($date_option['event_date'])) ?></h4>
                                    <div class="date-stats">
                                        <strong><?= $date_option['total_students'] ?></strong> eligible students | 
                                        <strong><?= $date_option['students_with_rewards'] ?></strong> with rewards | 
                                        <strong><?= $pending_students ?></strong> pending
                                    </div>
                                </div>
                                <div>
                                    <span class="completion-badge <?= $badge_class ?>">
                                        <?= $completion_percentage ?>% Complete
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Instructions -->
            <div class="content-card" style="background: linear-gradient(135deg, rgba(102, 126, 234, 0.05), rgba(139, 92, 246, 0.05)); border: 2px solid rgba(102, 126, 234, 0.2);">
                <h3 style="color: #667eea;">
                    <i class="fas fa-info-circle"></i>
                    How It Works
                </h3>
                
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-top: 20px;">
                    <div style="background: white; padding: 20px; border-radius: 12px;">
                        <h4 style="color: #667eea; margin-bottom: 10px;">📅 Step 1: Select Date</h4>
                        <p style="line-height: 1.5;">Choose an event date from the dropdown. Only dates with eligible students will appear.</p>
                    </div>
                    
                    <div style="background: white; padding: 20px; border-radius: 12px;">
                        <h4 style="color: #667eea; margin-bottom: 10px;">⚡ Step 2: Auto-Assign</h4>
                        <p style="line-height: 1.5;">System automatically finds all eligible students and assigns rewards instantly.</p>
                    </div>

                    <div style="background: white; padding: 20px; border-radius: 12px;">
                        <h4 style="color: #667eea; margin-bottom: 10px;">✅ Step 3: Complete</h4>
                        <p style="line-height: 1.5;">Receive confirmation with details of all students who received rewards.</p>
                    </div>
                </div>

                <div style="background: rgba(39, 174, 96, 0.1); padding: 20px; border-radius: 12px; margin-top: 20px;">
                    <h4 style="color: #27ae60; margin-bottom: 10px;">🎯 Automatic Eligibility Criteria</h4>
                    <ul style="color: #4a5568; line-height: 1.6;">
                        <li>✅ Registration Status = <strong>'confirmed'</strong></li>
                        <li>✅ Attendance Status = <strong>'present'</strong></li>
                        <li>✅ Students across all events on the selected date</li>
                        <li>✅ Prevents duplicate rewards automatically</li>
                    </ul>
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

        // Form validation and confirmation
        document.getElementById('assignmentForm')?.addEventListener('submit', function(e) {
            const selectedDate = this.querySelector('select[name="assign_date"]').value;
            const points = this.querySelector('input[name="points"]').value;
            
            if (!selectedDate) {
                e.preventDefault();
                alert('Please select an event date.');
                return false;
            }
            
            if (!points || points < 10 || points > 500) {
                e.preventDefault();
                alert('Please enter points between 10 and 500.');
                return false;
            }
            
            const selectedOption = this.querySelector('select[name="assign_date"] option:checked');
            const optionText = selectedOption.textContent;
            
            const confirmation = confirm(
                `Assign ${points} points to all eligible students on ${selectedDate}?\n\n` +
                `${optionText}\n\n` +
                `This action will create reward entries for all students with:\n` +
                `• Registration Status = 'confirmed'\n` +
                `• Attendance Status = 'present'\n` +
                `• Students without existing rewards\n\n` +
                `Continue with assignment?`
            );
            
            if (!confirmation) {
                e.preventDefault();
                return false;
            }
            
            // Show loading state
            const submitBtn = this.querySelector('#assignBtn');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Assigning Rewards...';
            }
        });

        // Page initialization
        document.addEventListener('DOMContentLoaded', function() {
            // Add entrance animations
            const elements = document.querySelectorAll('.content-card, .assignment-section, .date-item');
            elements.forEach((el, index) => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    el.style.transition = 'all 0.6s ease';
                    el.style.opacity = '1';
                    el.style.transform = 'translateY(0)';
                }, index * 100);
            });

            // Add hover effects to date items
            document.querySelectorAll('.date-item').forEach(item => {
                item.addEventListener('click', function() {
                    const dateValue = this.querySelector('h4').textContent;
                    const selectElement = document.querySelector('select[name="assign_date"]');
                    if (selectElement) {
                        // Find matching option by date
                        const options = selectElement.querySelectorAll('option');
                        options.forEach(option => {
                            if (option.textContent.includes(dateValue.split(' (')[0])) {
                                selectElement.value = option.value;
                                selectElement.focus();
                            }
                        });
                    }
                });
            });
        });

        // Performance monitoring
        window.addEventListener('load', function() {
            const loadTime = performance.now();
            console.log(`🎯 Reward Assignment System loaded in ${Math.round(loadTime)}ms`);
            
            // Show ready notification
            setTimeout(() => {
                const notification = document.createElement('div');
                notification.style.cssText = `
                    position: fixed; 
                    top: 20px; 
                    right: 20px; 
                    background: linear-gradient(135deg, #27ae60, #2ecc71); 
                    color: white; 
                    padding: 12px 20px; 
                    border-radius: 12px; 
                    box-shadow: 0 8px 25px rgba(39, 174, 96, 0.3);
                    z-index: 10000; 
                    font-weight: 600;
                    font-size: 14px;
                    opacity: 0;
                    transform: translateY(-20px);
                    transition: all 0.3s ease;
                `;
                notification.innerHTML = '🎯 Reward System Ready!';
                document.body.appendChild(notification);
                
                setTimeout(() => {
                    notification.style.opacity = '1';
                    notification.style.transform = 'translateY(0)';
                }, 100);
                
                setTimeout(() => {
                    notification.style.opacity = '0';
                    notification.style.transform = 'translateY(-20px)';
                    setTimeout(() => notification.remove(), 300);
                }, 3000);
            }, 500);
        });

        // Console logging
        console.log('🎯 Simple Reward Assignment System Ready');
        console.log('📊 Available Event Dates:', <?= json_encode(array_column($event_dates, 'event_date')) ?>);
        console.log('💡 Process: Select Date → Auto-Assign → Success Message');
        console.log('⚡ Features: One-click assignment, duplicate prevention, instant feedback');
    </script>
</body>
</html>