<?php
// Include the configuration file
require_once 'config.php';

// Initialize session
if (!initializeSession()) {
    echo "<script>alert('Session expired. Please login again.'); window.location.href='index.php';</script>";
    exit();
}

// Check if user is logged in and is staff
$user_logged_in = isset($_SESSION['staff_id']) && !empty($_SESSION['staff_id']);
$is_staff = $user_logged_in;

if (!$user_logged_in || !$is_staff) {
    echo "<script>alert('Access denied. Please login as staff first.'); window.location.href='index.php';</script>";
    exit();
}

// Get database connection
try {
    $pdo = getDbConnection();
} catch (PDOException $e) {
    $_SESSION['error'] = "Database connection error. Please try again.";
    header("Location: staff_view_donation.php");
    exit();
}

// Get staff information
$stmt = $pdo->prepare("SELECT * FROM staff WHERE StaffID = ?");
$stmt->execute([$_SESSION['staff_id']]);
$staff = $stmt->fetch();

// Check if registration ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error'] = "Registration ID is required.";
    header("Location: staff_view_donation.php");
    exit();
}

$registration_id = (int)$_GET['id'];

// Get existing donor information and check if event is in the past
$registration = null;
try {
    $sql = "SELECT * FROM registration WHERE RegistrationID = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$registration_id]);
    $registration = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$registration) {
        $_SESSION['error'] = "Registration record not found.";
        header("Location: staff_view_donation.php");
        exit();
    }
    
    // Check if the event is in the past
    $registration_date = new DateTime($registration['RegistrationDate']);
    $today = new DateTime();
    $is_past_event = $registration_date->format('Y-m-d') < $today->format('Y-m-d');
    
    if ($is_past_event) {
        $_SESSION['error'] = "Cannot confirm attendance for past events. Event date was: " . $registration_date->format('M d, Y');
        header("Location: staff_view_donation.php");
        exit();
    }
    
} catch (PDOException $e) {
    $_SESSION['error'] = "Error retrieving donor information.";
    header("Location: staff_view_donation.php");
    exit();
}

// Check eligibility conditions
$registrationStatus = strtolower(trim($registration['RegistrationStatus'] ?? ''));
$attendanceStatus = strtolower(trim($registration['AttendanceStatus'] ?? ''));

$status_check = in_array($registrationStatus, ['registered', 'confirmed', 'approved', 'active']);
$attendance_check = !in_array($attendanceStatus, ['present', 'attended', 'confirmed']);

// Check if donation already exists
$donation_check_sql = "SELECT DonationID FROM donation WHERE RegistrationID = ?";
$donation_check_stmt = $pdo->prepare($donation_check_sql);
$donation_check_stmt->execute([$registration_id]);
$existing_donation = $donation_check_stmt->fetch();

$can_confirm = $status_check && $attendance_check && !$existing_donation;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_attendance'])) {
    try {
        // CSRF protection
        if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || 
            $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception("Invalid security token. Please try again.");
        }

        if (!$can_confirm) {
            throw new Exception("Cannot confirm attendance at this time.");
        }

        // Get medical screening data from form
        $blood_type = trim($_POST['blood_type'] ?? '');
        $weight = (float)($_POST['weight'] ?? 0);
        $blood_pressure = trim($_POST['blood_pressure'] ?? '');
        $temperature = (float)($_POST['temperature'] ?? 0);
        $pulse_rate = (int)($_POST['pulse_rate'] ?? 0);
        
        // Fixed health check logic - more explicit validation
        $feels_healthy = isset($_POST['feels_healthy']) ? 'yes' : 'no';
        $taking_medicine = isset($_POST['taking_medicine']) ? 'yes' : 'no';
        $recent_illness = isset($_POST['recent_illness']) ? 'yes' : 'no';
        $staff_notes = trim($_POST['staff_notes'] ?? '');
        
        // Enhanced validation with better error messages
        if (empty($blood_type)) {
            throw new Exception("Please select blood type.");
        }
        if ($weight < 45) {
            throw new Exception("Weight must be at least 45 kg to donate blood.");
        }
        if ($temperature > 0 && $temperature > 37.5) {
            throw new Exception("Temperature is too high for donation (over 37.5°C). Current: " . $temperature . "°C");
        }
        if ($feels_healthy === 'no') {
            throw new Exception("Donor reports not feeling healthy today. Cannot proceed with donation.");
        }

        // Additional safety checks
        if ($taking_medicine === 'yes') {
            // Add to staff notes for review
            $staff_notes .= "\n[AUTO] Donor taking medicine - requires review.";
        }
        if ($recent_illness === 'yes') {
            // Add to staff notes for review  
            $staff_notes .= "\n[AUTO] Donor had recent illness - requires review.";
        }

        // Determine donation volume based on weight (Malaysian standards)
        if ($weight >= 50) {
            $donation_quantity = 450; // Standard volume for donors >50kg
        } elseif ($weight >= 45) {
            $donation_quantity = 350; // Reduced volume for donors 45-50kg
        } else {
            throw new Exception("Donor weight is below minimum requirement (45kg).");
        }

        // Enhanced transaction with better error checking
        $pdo->beginTransaction();

        try {
            // Update attendance status with explicit row count checking
            $update_sql = "UPDATE registration 
                           SET AttendanceStatus = 'present', 
                               RegistrationStatus = 'confirmed'
                           WHERE RegistrationID = ?";
            
            $update_stmt = $pdo->prepare($update_sql);
            $update_result = $update_stmt->execute([$registration_id]);
            
            // Check if the update actually worked
            if (!$update_result) {
                $error_info = $update_stmt->errorInfo();
                throw new Exception("Failed to update attendance status. SQL Error: " . $error_info[2]);
            }
            
            if ($update_stmt->rowCount() === 0) {
                throw new Exception("No rows were updated. Registration ID may not exist or already processed.");
            }

            // Verify the update by reading back the data
            $verify_sql = "SELECT AttendanceStatus, RegistrationStatus FROM registration WHERE RegistrationID = ?";
            $verify_stmt = $pdo->prepare($verify_sql);
            $verify_stmt->execute([$registration_id]);
            $verify_result = $verify_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$verify_result || $verify_result['AttendanceStatus'] !== 'present') {
                throw new Exception("Attendance status verification failed. Expected 'present', got: " . ($verify_result['AttendanceStatus'] ?? 'NULL'));
            }

            // Create donation record with error checking - using only existing columns
            $donation_sql = "INSERT INTO donation (
                                DonationDate, DonationBloodType, DonationQuantity, RegistrationID,
                                Weight, BloodPressure, Temperature, PulseRate, DonationStatus
                             ) VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, 'pending')";
            
            $donation_stmt = $pdo->prepare($donation_sql);
            $donation_result = $donation_stmt->execute([
                $blood_type, $donation_quantity, $registration_id,
                $weight, $blood_pressure, $temperature, $pulse_rate
            ]);
            
            if (!$donation_result) {
                $error_info = $donation_stmt->errorInfo();
                throw new Exception("Failed to create donation record. SQL Error: " . $error_info[2]);
            }

            $donation_id = $pdo->lastInsertId();
            
            if (!$donation_id) {
                throw new Exception("Failed to get donation ID after insertion. Insert may have failed silently.");
            }
            
            // Enhanced verification with length checking
            $verify_sql = "SELECT AttendanceStatus, RegistrationStatus, LENGTH(AttendanceStatus) as status_length FROM registration WHERE RegistrationID = ?";
            $verify_stmt = $pdo->prepare($verify_sql);
            $verify_stmt->execute([$registration_id]);
            $verify_result = $verify_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$verify_result) {
                throw new Exception("Registration record not found after update.");
            }

            // Check if the status was truncated
            if ($verify_result['status_length'] < 7 && $verify_result['AttendanceStatus'] === 'presen') {
                // Column is too short, try to fix it
                error_log("WARNING: AttendanceStatus column appears too short. Value truncated to: " . $verify_result['AttendanceStatus']);
                
                // Try to fix the column length
                try {
                    $alter_sql = "ALTER TABLE registration MODIFY COLUMN AttendanceStatus VARCHAR(20) DEFAULT 'pending'";
                    $pdo->exec($alter_sql);
                    
                    // Update the value again
                    $fix_sql = "UPDATE registration SET AttendanceStatus = 'present' WHERE RegistrationID = ?";
                    $fix_stmt = $pdo->prepare($fix_sql);
                    $fix_stmt->execute([$registration_id]);
                    
                    error_log("FIXED: Extended AttendanceStatus column and corrected value for registration {$registration_id}");
                } catch (Exception $e) {
                    error_log("ERROR: Could not fix AttendanceStatus column: " . $e->getMessage());
                }
            }

            if ($verify_result['AttendanceStatus'] !== 'present') {
                throw new Exception("Attendance status verification failed. Expected 'present', got: '" . $verify_result['AttendanceStatus'] . "' (length: " . $verify_result['status_length'] . ")");
            }
            
            // Commit the transaction
            $commit_result = $pdo->commit();
            
            if (!$commit_result) {
                throw new Exception("Failed to commit transaction.");
            }

            // Log successful completion
            error_log("SUCCESS: Staff {$_SESSION['staff_id']} confirmed attendance for registration ID: {$registration_id}, created donation ID: {$donation_id}");
            
            $_SESSION['success'] = "✅ Attendance confirmed and donation record created for Registration ID: " . 
                                  htmlspecialchars($registration_id) . 
                                  ". Planned volume: " . $donation_quantity . "ml (Donation ID: " . $donation_id . ")";
            
            header("Location: staff_view_donation.php");
            exit();

        } catch (Exception $e) {
            // Rollback on any error
            if ($pdo->inTransaction()) {
                $pdo->rollback();
            }
            
            // Log the detailed error
            error_log("FAILED: Attendance confirmation error for registration ID {$registration_id}: " . $e->getMessage());
            error_log("FAILED: Stack trace: " . $e->getTraceAsString());
            
            // Re-throw to be caught by outer try-catch
            throw $e;
        }

    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Helper functions
function formatDate($date) {
    if (!$date || $date === '1970-01-01') return 'N/A';
    try {
        $dateObj = new DateTime($date);
        return $dateObj->format('M d, Y');
    } catch (Exception $e) {
        return 'Invalid Date';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirm Attendance - LifeSaver Hub</title>
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

        /* Enhanced Sidebar - Matching dashboard theme */
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

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 320px;
            padding: 32px;
            background: rgba(248, 249, 250, 0.3);
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .page-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 48px;
            text-align: center;
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
            background: linear-gradient(45deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            animation: shimmer 3s infinite;
        }

        .page-header h1 {
            font-size: 2.5rem;
            font-weight: 900;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 20px;
            position: relative;
            z-index: 1;
            text-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        .page-header h1 i {
            font-size: 2rem;
        }

        .page-header p {
            font-size: 18px;
            opacity: 0.9;
            position: relative;
            z-index: 1;
            font-weight: 500;
        }

        .donor-info {
            background: linear-gradient(135deg, #27ae60, #219a52);
            color: white;
            padding: 30px;
            margin: 30px;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(39, 174, 96, 0.2);
        }

        .donor-info h3 {
            font-size: 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 700;
        }

        .donor-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .detail-item {
            background: rgba(255,255,255,0.15);
            padding: 15px;
            border-radius: 12px;
            font-size: 14px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
            transition: all 0.3s ease;
        }

        .detail-item:hover {
            background: rgba(255,255,255,0.2);
            transform: translateY(-2px);
        }

        .detail-item strong {
            display: block;
            margin-bottom: 4px;
            font-weight: 600;
        }

        .debug-info {
            background: linear-gradient(135deg, #fff3cd, #ffeaa7);
            border: 2px solid #f39c12;
            border-radius: 15px;
            padding: 20px;
            margin: 20px 30px;
            color: #856404;
            font-size: 13px;
            box-shadow: 0 8px 25px rgba(243, 156, 18, 0.15);
        }

        .form-container {
            padding: 30px;
        }

        .form-section {
            margin-bottom: 30px;
            padding: 25px;
            background: linear-gradient(135deg, rgba(248, 249, 250, 0.8), rgba(255, 255, 255, 0.8));
            border-radius: 20px;
            border-left: 4px solid #667eea;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.05);
            backdrop-filter: blur(10px);
        }

        .section-title {
            color: #2d3748;
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .section-title i {
            color: #667eea;
            font-size: 20px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            color: #2d3748;
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .form-input, .form-select {
            width: 100%;
            padding: 15px 18px;
            border: 2px solid rgba(102, 126, 234, 0.2);
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(10px);
            color: #2d3748;
            font-weight: 500;
        }

        .form-input:focus, .form-select:focus {
            outline: none;
            border-color: #667eea;
            background: rgba(255, 255, 255, 0.95);
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
            transform: scale(1.02);
        }

        .checkbox-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 15px;
            background: rgba(255, 255, 255, 0.8);
            border-radius: 12px;
            border: 2px solid rgba(102, 126, 234, 0.2);
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }

        .checkbox-item:hover {
            background: rgba(255, 255, 255, 0.95);
            border-color: #667eea;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.1);
        }

        .checkbox-item input[type="checkbox"] {
            width: 20px;
            height: 20px;
            accent-color: #667eea;
        }

        .checkbox-item label {
            font-weight: 500;
            color: #2d3748;
            cursor: pointer;
            flex: 1;
        }

        .form-textarea {
            width: 100%;
            padding: 18px 20px;
            border: 2px solid rgba(102, 126, 234, 0.2);
            border-radius: 12px;
            font-size: 14px;
            resize: vertical;
            min-height: 100px;
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(10px);
            color: #2d3748;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .form-textarea:focus {
            outline: none;
            border-color: #667eea;
            background: rgba(255, 255, 255, 0.95);
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
        }

        .warning-box {
            background: linear-gradient(135deg, #fff3cd, #ffeaa7);
            border: 2px solid #f39c12;
            border-radius: 15px;
            padding: 20px;
            margin: 20px 0;
            color: #856404;
            box-shadow: 0 8px 25px rgba(243, 156, 18, 0.15);
        }

        .warning-box h4 {
            margin-bottom: 8px;
            font-size: 16px;
            font-weight: 700;
        }

        .volume-info {
            margin-top: 8px;
            padding: 12px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            display: none;
            transition: all 0.3s ease;
        }

        .btn {
            padding: 15px 30px;
            border: none;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            position: relative;
            overflow: hidden;
            text-transform: uppercase;
            letter-spacing: 0.5px;
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
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 35px rgba(102, 126, 234, 0.4);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #95a5a6, #7f8c8d);
            color: white;
            box-shadow: 0 8px 25px rgba(149, 165, 166, 0.3);
        }

        .btn-secondary:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 35px rgba(149, 165, 166, 0.4);
        }

        .button-group {
            display: flex;
            gap: 20px;
            justify-content: center;
            margin-top: 30px;
            padding-top: 25px;
            border-top: 2px solid rgba(102, 126, 234, 0.15);
        }

        .alert {
            padding: 20px 25px;
            border-radius: 15px;
            margin-bottom: 25px;
            border-left: 4px solid;
            display: flex;
            align-items: center;
            gap: 15px;
            backdrop-filter: blur(20px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            font-weight: 500;
        }

        .alert.error {
            background: linear-gradient(135deg, rgba(248, 215, 218, 0.9), rgba(245, 198, 203, 0.9));
            border-left-color: #e74c3c;
            color: #c0392b;
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
            
            .sidebar.mobile-open {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
                padding: 24px 16px;
            }

            .form-grid, .checkbox-grid {
                grid-template-columns: 1fr;
            }

            .button-group {
                flex-direction: column;
            }

            .donor-details {
                grid-template-columns: 1fr;
            }

            .debug-info {
                font-size: 11px;
                padding: 15px;
                margin: 15px 20px;
            }

            .page-header h1 {
                font-size: 2rem;
            }

            .donor-info {
                margin: 20px;
                padding: 20px;
            }
        }

        @media (max-width: 480px) {
            .page-header {
                padding: 30px 25px;
            }
            
            .form-container {
                padding: 20px;
            }

            .form-section {
                padding: 20px;
            }
        }

        /* Scrollbar Styling */
        ::-webkit-scrollbar {
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: rgba(15, 23, 42, 0.3);
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

        .fade-in {
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
        <!-- Enhanced Sidebar - Matching dashboard theme -->
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
                        <a href="staff_view_donation.php" class="nav-item active"> 
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
            <div class="container">
                <!-- Header -->
                <div class="page-header">
                    <h1>
                        <i class="fas fa-user-check"></i>
                        Confirm Attendance & Medical Screening
                    </h1>
                    <p>One-step process: Confirm attendance and complete basic medical screening</p>
                </div>

                <!-- Donor Info -->
                <div class="donor-info">
                    <h3><i class="fas fa-user"></i> Registration Information</h3>
                    <div class="donor-details">
                        <div class="detail-item">
                            <strong>Registration ID:</strong> <?php echo htmlspecialchars($registration['RegistrationID']); ?>
                        </div>
                        <div class="detail-item">
                            <strong>Student ID:</strong> <?php echo htmlspecialchars($registration['StudentID'] ?? 'N/A'); ?>
                        </div>
                        <div class="detail-item">
                            <strong>Event ID:</strong> <?php echo htmlspecialchars($registration['EventID'] ?? 'N/A'); ?>
                        </div>
                        <div class="detail-item">
                            <strong>Registration Date:</strong> <?php echo formatDate($registration['RegistrationDate']); ?>
                        </div>
                        <div class="detail-item">
                            <strong>Registration Status:</strong> <?php echo htmlspecialchars($registration['RegistrationStatus'] ?? 'N/A'); ?>
                        </div>
                        <div class="detail-item">
                            <strong>Attendance Status:</strong> <?php echo htmlspecialchars($registration['AttendanceStatus'] ?? 'N/A'); ?>
                        </div>
                    </div>
                </div>

                <!-- Debug Info -->
                <div class="debug-info">
                    <strong>🔍 Debug Info:</strong> Registration ID: <?php echo $registration_id; ?> | 
                    Current Status: <?php echo htmlspecialchars($registration['RegistrationStatus'] ?? 'NULL'); ?> | 
                    Attendance: <?php echo htmlspecialchars($registration['AttendanceStatus'] ?? 'NULL'); ?> | 
                    Can Confirm: <?php echo $can_confirm ? '✅ YES' : '❌ NO'; ?>
                    <?php if ($existing_donation): ?>
                        | Existing Donation: ID <?php echo $existing_donation['DonationID']; ?>
                    <?php endif; ?>
                </div>

                <!-- Alert -->
                <?php if (isset($error_message)): ?>
                    <div class="alert error">
                        <i class="fas fa-exclamation-triangle"></i>
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>

                <?php if (!$can_confirm): ?>
                    <div class="alert error">
                        <i class="fas fa-exclamation-triangle"></i>
                        Cannot confirm attendance. 
                        <?php if ($existing_donation): ?>
                            Donation record already exists (ID: <?php echo $existing_donation['DonationID']; ?>).
                        <?php elseif (in_array($attendanceStatus, ['present', 'attended', 'confirmed'])): ?>
                            Attendance already confirmed.
                        <?php elseif (!$status_check): ?>
                            Invalid registration status: <?php echo htmlspecialchars($registration['RegistrationStatus'] ?? 'NULL'); ?>.
                        <?php else: ?>
                            Please check donor status and eligibility.
                        <?php endif; ?>
                    </div>
                    <div class="button-group">
                        <a href="staff_view_donation.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Donor List
                        </a>
                    </div>
                <?php else: ?>
                    <!-- Form -->
                    <div class="form-container">
                        <form method="POST" action="" id="confirmForm">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="confirm_attendance" value="1">

                            <!-- Basic Measurements -->
                            <div class="form-section">
                                <h3 class="section-title">
                                    <i class="fas fa-weight"></i>
                                    Basic Measurements
                                </h3>
                                
                                <div class="warning-box">
                                    <h4>📋 Malaysian Standards (Pertubuhan Penderma Darah Malaysia)</h4>
                                    <p><strong>≥50kg:</strong> Standard donation (450ml) | <strong>45-50kg:</strong> Reduced donation (350ml)</p>
                                </div>

                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label">Blood Type *</label>
                                        <select name="blood_type" class="form-select" required>
                                            <option value="">Choose blood type</option>
                                            <option value="A+">A+</option>
                                            <option value="A-">A-</option>
                                            <option value="B+">B+</option>
                                            <option value="B-">B-</option>
                                            <option value="AB+">AB+</option>
                                            <option value="AB-">AB-</option>
                                            <option value="O+">O+</option>
                                            <option value="O-">O-</option>
                                        </select>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Weight (kg) *</label>
                                        <input type="number" name="weight" class="form-input" 
                                               min="45" max="200" step="0.1" required
                                               placeholder="Must be 45kg or more" id="weightInput">
                                        <div id="volumeInfo" class="volume-info"></div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Blood Pressure</label>
                                        <input type="text" name="blood_pressure" class="form-input" 
                                               placeholder="e.g. 120/80">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Temperature (°C)</label>
                                        <input type="number" name="temperature" class="form-input" 
                                               min="35" max="40" step="0.1"
                                               placeholder="e.g. 36.5">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Pulse Rate (per minute)</label>
                                        <input type="number" name="pulse_rate" class="form-input" 
                                               min="50" max="120"
                                               placeholder="e.g. 72">
                                    </div>
                                </div>
                            </div>

                            <!-- Quick Health Check -->
                            <div class="form-section">
                                <h3 class="section-title">
                                    <i class="fas fa-heartbeat"></i>
                                    Quick Health Check
                                </h3>
                                
                                <div class="warning-box">
                                    <h4>⚠️ Ask the donor these questions</h4>
                                    <p>Check the box if they answer "YES" to any of these.</p>
                                </div>

                                <div class="checkbox-grid">
                                    <div class="checkbox-item">
                                        <input type="checkbox" id="feels_healthy" name="feels_healthy" checked>
                                        <label for="feels_healthy">Feeling healthy and well today</label>
                                    </div>
                                    <div class="checkbox-item">
                                        <input type="checkbox" id="taking_medicine" name="taking_medicine">
                                        <label for="taking_medicine">Taking any medicine today</label>
                                    </div>
                                    <div class="checkbox-item">
                                        <input type="checkbox" id="recent_illness" name="recent_illness">
                                        <label for="recent_illness">Had fever, cold, or feeling sick recently</label>
                                    </div>
                                </div>
                            </div>

                            <!-- Staff Notes -->
                            <div class="form-section">
                                <h3 class="section-title">
                                    <i class="fas fa-notes-medical"></i>
                                    Staff Notes (Optional)
                                </h3>
                                
                                <textarea name="staff_notes" class="form-textarea" 
                                          placeholder="Any additional observations or notes..."></textarea>
                            </div>

                            <!-- Buttons -->
                            <div class="button-group">
                                <a href="staff_view_donation.php" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                                <button type="submit" class="btn btn-primary" id="submitBtn">
                                    <i class="fas fa-check-circle"></i> Confirm Attendance & Create Donation Record
                                </button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        // Mobile sidebar toggle
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('mobile-open');
        }

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const menuBtn = document.querySelector('.mobile-menu-btn');
            
            if (window.innerWidth <= 968 && 
                !sidebar.contains(event.target) && 
                !menuBtn.contains(event.target) && 
                sidebar.classList.contains('mobile-open')) {
                sidebar.classList.remove('mobile-open');
            }
        });

        // Auto-hide mobile menu on resize
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth > 968) {
                sidebar.classList.remove('mobile-open');
            }
        });

        // Weight validation and volume calculation
        document.getElementById('weightInput').addEventListener('input', function() {
            const weight = parseFloat(this.value);
            const volumeInfo = document.getElementById('volumeInfo');
            
            if (weight >= 50) {
                this.style.borderColor = '#27ae60';
                this.style.background = '#f8fff8';
                volumeInfo.style.display = 'block';
                volumeInfo.style.background = '#d5f4e6';
                volumeInfo.style.color = '#27ae60';
                volumeInfo.style.border = '1px solid #27ae60';
                volumeInfo.innerHTML = '✅ <strong>Standard Donation: 450ml</strong>';
            } else if (weight >= 45) {
                this.style.borderColor = '#f39c12';
                this.style.background = '#fefbf3';
                volumeInfo.style.display = 'block';
                volumeInfo.style.background = '#fef9e7';
                volumeInfo.style.color = '#f39c12';
                volumeInfo.style.border = '1px solid #f39c12';
                volumeInfo.innerHTML = '⚠️ <strong>Reduced Donation: 350ml</strong>';
            } else if (weight > 0) {
                this.style.borderColor = '#e74c3c';
                this.style.background = '#fff5f5';
                volumeInfo.style.display = 'block';
                volumeInfo.style.background = '#fadbd8';
                volumeInfo.style.color = '#e74c3c';
                volumeInfo.style.border = '1px solid #e74c3c';
                volumeInfo.innerHTML = '❌ <strong>Not Eligible</strong> (Min 45kg)';
            } else {
                this.style.borderColor = 'rgba(102, 126, 234, 0.2)';
                this.style.background = 'rgba(255, 255, 255, 0.8)';
                volumeInfo.style.display = 'none';
            }
        });

        // Form submission validation
        document.getElementById('confirmForm').addEventListener('submit', function(e) {
            const bloodType = document.querySelector('select[name="blood_type"]').value;
            const weight = parseFloat(document.querySelector('input[name="weight"]').value);
            const feelsHealthy = document.querySelector('input[name="feels_healthy"]').checked;
            
            if (!bloodType) {
                alert('Please select blood type.');
                e.preventDefault();
                return;
            }
            
            if (!weight || weight < 45) {
                alert('Weight must be at least 45kg for blood donation.');
                e.preventDefault();
                return;
            }
            
            if (!feelsHealthy) {
                alert('Donor must be feeling healthy today to proceed.');
                e.preventDefault();
                return;
            }

            // Calculate volume for confirmation
            const volume = weight >= 50 ? '450ml' : '350ml';
            
            if (!confirm(`Confirm attendance for Registration ID: <?php echo $registration_id; ?>\nBlood Type: ${bloodType}\nWeight: ${weight}kg\nDonation Volume: ${volume}\n\nProceed?`)) {
                e.preventDefault();
                return;
            }
            
            // Show loading state
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            submitBtn.disabled = true;
        });

        // Add entrance animations
        document.addEventListener('DOMContentLoaded', function() {
            const elements = document.querySelectorAll('.form-section');
            elements.forEach((el, index) => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    el.style.transition = 'all 0.6s ease';
                    el.style.opacity = '1';
                    el.style.transform = 'translateY(0)';
                }, index * 200);
            });
        });

        console.log('🩸 Enhanced Confirm Attendance Ready');
        console.log('📋 Registration ID: <?php echo $registration_id; ?>');
        console.log('🔍 Can Confirm: <?php echo $can_confirm ? "true" : "false"; ?>');
    </script>
</body>
</html>