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

// Get donor and donation information
$donor = null;
$donation_record = null;

try {
    // Get donor registration info
    $donor_sql = "SELECT * FROM registration WHERE RegistrationID = ?";
    $donor_stmt = $pdo->prepare($donor_sql);
    $donor_stmt->execute([$registration_id]);
    $donor = $donor_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$donor) {
        $_SESSION['error'] = "Donor record not found.";
        header("Location: staff_view_donation.php");
        exit();
    }
    
    // Enhanced debugging for donation_process.php
    $attendanceStatus = strtolower(trim($donor['AttendanceStatus'] ?? ''));
    $validAttendanceStatuses = ['present', 'attended', 'confirmed'];

    // Log the current state for debugging
    error_log("DONATION_PROCESS DEBUG for Registration ID {$registration_id}:");
    error_log("- AttendanceStatus: '{$donor['AttendanceStatus']}' (lowercase: '{$attendanceStatus}')");
    error_log("- Valid statuses: " . implode(', ', $validAttendanceStatuses));
    error_log("- Status check result: " . (in_array($attendanceStatus, $validAttendanceStatuses) ? 'PASS' : 'FAIL'));
    
    // Check if attendance has been confirmed - Updated with detailed logging
    if (!in_array($attendanceStatus, $validAttendanceStatuses)) {
        error_log("REDIRECT: Invalid attendance status '{$attendanceStatus}' for registration {$registration_id}");
        $_SESSION['error'] = "Donor attendance must be confirmed before starting donation process. Current status: " . ucfirst($attendanceStatus);
        header("Location: confirm_attendance.php?id=" . $registration_id);
        exit();
    }
    
    // Get existing donation record with detailed logging
    $donation_sql = "SELECT * FROM donation WHERE RegistrationID = ?";
    $donation_stmt = $pdo->prepare($donation_sql);
    $donation_stmt->execute([$registration_id]);
    $donation_record = $donation_stmt->fetch(PDO::FETCH_ASSOC);
    
    error_log("- Donation record exists: " . ($donation_record ? 'YES (ID: ' . $donation_record['DonationID'] . ')' : 'NO'));
    
    if (!$donation_record) {
        error_log("REDIRECT: No donation record found for registration {$registration_id}");
        $_SESSION['error'] = "No donation record found. Please confirm attendance first.";
        header("Location: confirm_attendance.php?id=" . $registration_id);
        exit();
    }
    
    // Check if donation is already completed
    if (strtolower($donation_record['DonationStatus'] ?? '') === 'completed') {
        error_log("REDIRECT: Donation already completed for registration {$registration_id}");
        $_SESSION['info'] = "Donation already completed for Registration ID: " . htmlspecialchars($registration_id) . 
                           ". Volume: " . $donation_record['DonationQuantity'] . "ml";
        header("Location: staff_view_donation.php");
        exit();
    }
    
    // If we get here, log success
    error_log("SUCCESS: All checks passed for registration {$registration_id}, proceeding with donation process");
    
} catch (PDOException $e) {
    $_SESSION['error'] = "Error retrieving donor information: " . $e->getMessage();
    error_log("DATABASE ERROR in donation_process.php: " . $e->getMessage());
    header("Location: staff_view_donation.php");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Debug CSRF tokens
        error_log("CSRF DEBUG for Registration ID {$registration_id}:");
        error_log("- POST token: " . ($_POST['csrf_token'] ?? 'NOT SET'));
        error_log("- SESSION token: " . ($_SESSION['csrf_token'] ?? 'NOT SET'));
        error_log("- Tokens match: " . (isset($_POST['csrf_token'], $_SESSION['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token'] ? 'YES' : 'NO'));

        // Enhanced CSRF protection
        if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token'])) {
            error_log("CSRF ERROR: Missing tokens - POST: " . (isset($_POST['csrf_token']) ? 'SET' : 'MISSING') . ", SESSION: " . (isset($_SESSION['csrf_token']) ? 'SET' : 'MISSING'));
            throw new Exception("Security token is missing. Please refresh the page and try again.");
        }
        
        if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            error_log("CSRF ERROR: Token mismatch - Expected: " . $_SESSION['csrf_token'] . ", Got: " . $_POST['csrf_token']);
            
            // Try to regenerate token and show helpful error
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            throw new Exception("Security token has expired. Please refresh the page and try again.");
        }

        // Get form data with enhanced validation - mapping to existing donation table columns
        $hemoglobin_level = !empty($_POST['hemoglobin_level']) ? (float)$_POST['hemoglobin_level'] : null;
        $platelet_count = !empty($_POST['platelet_count']) ? (float)$_POST['platelet_count'] : null;
        $weight = !empty($_POST['weight']) ? (float)$_POST['weight'] : null;
        $blood_pressure = !empty($_POST['blood_pressure']) ? trim($_POST['blood_pressure']) : null;
        $temperature = !empty($_POST['temperature']) ? (float)$_POST['temperature'] : null;
        $pulse_rate = !empty($_POST['pulse_rate']) ? (int)$_POST['pulse_rate'] : null;
        $notes_comments = !empty($_POST['notes_comments']) ? trim($_POST['notes_comments']) : '';

        // Enhanced validation
        if ($hemoglobin_level !== null && $hemoglobin_level < 11.0) {
            throw new Exception("Hemoglobin level is too low for donation (minimum 11.0 g/dL). Current: {$hemoglobin_level} g/dL");
        }

        if ($platelet_count !== null && $platelet_count < 150) {
            throw new Exception("Platelet count is too low for donation (minimum 150×10³/μL). Current: {$platelet_count}×10³/μL");
        }

        // Start transaction for donation completion
        $pdo->beginTransaction();

        try {
            // Prepare the update fields and values
            $update_fields = [];
            $update_values = [];
            
            // Only update fields that have values
            if ($hemoglobin_level !== null) {
                $update_fields[] = "HemoglobinLevel = ?";
                $update_values[] = $hemoglobin_level;
            }
            
            if ($platelet_count !== null) {
                $update_fields[] = "PlateletCount = ?";
                $update_values[] = $platelet_count;
            }
            
            if ($weight !== null) {
                $update_fields[] = "Weight = ?";
                $update_values[] = $weight;
            }
            
            if ($blood_pressure !== null) {
                $update_fields[] = "BloodPressure = ?";
                $update_values[] = $blood_pressure;
            }
            
            if ($temperature !== null) {
                $update_fields[] = "Temperature = ?";
                $update_values[] = $temperature;
            }
            
            if ($pulse_rate !== null) {
                $update_fields[] = "PulseRate = ?";
                $update_values[] = $pulse_rate;
            }
            
            // Always update status to completed
            $update_fields[] = "DonationStatus = ?";
            $update_values[] = 'completed';
            
            // Add the DonationID for WHERE clause
            $update_values[] = $donation_record['DonationID'];
            
            // Build and execute the update query
            if (!empty($update_fields)) {
                $update_sql = "UPDATE donation SET " . implode(", ", $update_fields) . " WHERE DonationID = ?";
                $stmt = $pdo->prepare($update_sql);
                $update_result = $stmt->execute($update_values);

                if (!$update_result) {
                    $error_info = $stmt->errorInfo();
                    throw new Exception("Failed to update donation record. SQL Error: " . $error_info[2]);
                }

                if ($stmt->rowCount() === 0) {
                    throw new Exception("No donation record was updated. Record may have been modified by another user.");
                }
            }

            // Verify the update
            $verify_sql = "SELECT DonationStatus, DonationID FROM donation WHERE DonationID = ?";
            $verify_stmt = $pdo->prepare($verify_sql);
            $verify_stmt->execute([$donation_record['DonationID']]);
            $verify_result = $verify_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$verify_result || $verify_result['DonationStatus'] !== 'completed') {
                throw new Exception("Donation completion verification failed.");
            }

            // Commit the transaction
            $commit_result = $pdo->commit();
            
            if (!$commit_result) {
                throw new Exception("Failed to commit donation completion transaction.");
            }

            // Log successful completion
            error_log("SUCCESS: Staff {$_SESSION['staff_id']} completed donation process for registration ID: {$registration_id}, donation ID: {$donation_record['DonationID']}");

            $_SESSION['success'] = "✅ Donation process completed successfully for Registration ID: " . 
                                  htmlspecialchars($registration_id) . 
                                  ". Volume collected: " . $donation_record['DonationQuantity'] . "ml. " .
                                  "Donation ID: " . $donation_record['DonationID'] . ". Thank you for helping save lives!";
            
            header("Location: staff_view_donation.php");
            exit();

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollback();
            }
            error_log("FAILED: Donation completion error for registration ID {$registration_id}: " . $e->getMessage());
            throw $e;
        }

    } catch (Exception $e) {
        $error_message = $e->getMessage();
        error_log("DONATION_PROCESS ERROR: " . $e->getMessage());
    }
}

// Enhanced CSRF token generation with debugging
if (!isset($_SESSION['csrf_token']) || empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    error_log("CSRF TOKEN GENERATED: New token created for registration {$registration_id}: " . $_SESSION['csrf_token']);
} else {
    error_log("CSRF TOKEN EXISTS: Using existing token for registration {$registration_id}: " . $_SESSION['csrf_token']);
}

$csrf_token = $_SESSION['csrf_token'];

// Add timestamp to track token age
if (!isset($_SESSION['csrf_token_time'])) {
    $_SESSION['csrf_token_time'] = time();
}

// Check if token is older than 1 hour (3600 seconds)
$token_age = time() - $_SESSION['csrf_token_time'];
if ($token_age > 3600) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['csrf_token_time'] = time();
    $csrf_token = $_SESSION['csrf_token'];
    error_log("CSRF TOKEN REFRESHED: Token was {$token_age} seconds old, generated new one");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Donation Process - LifeSaver Hub</title>
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

        .donor-summary {
            background: linear-gradient(135deg, #27ae60, #219a52);
            color: white;
            padding: 30px;
            margin: 30px;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(39, 174, 96, 0.2);
        }

        .donor-summary h3 {
            font-size: 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 700;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .summary-item {
            background: rgba(255,255,255,0.15);
            padding: 15px;
            border-radius: 12px;
            font-size: 14px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
            transition: all 0.3s ease;
        }

        .summary-item:hover {
            background: rgba(255,255,255,0.2);
            transform: translateY(-2px);
        }

        .summary-item strong {
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

        .info-box {
            background: linear-gradient(135deg, #d6eaf8, #bee5eb);
            border: 2px solid #3498db;
            border-radius: 15px;
            padding: 20px;
            margin: 20px 0;
            color: #2874a6;
            box-shadow: 0 8px 25px rgba(52, 152, 219, 0.15);
        }

        .status-info {
            background: linear-gradient(135deg, #fff3cd, #ffeaa7);
            border: 2px solid #f39c12;
            border-radius: 15px;
            padding: 20px;
            margin: 20px 0;
            color: #856404;
            box-shadow: 0 8px 25px rgba(243, 156, 18, 0.15);
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

        /* Validation styles */
        .form-input.valid {
            border-color: #27ae60;
            background: linear-gradient(135deg, #f8fff8, #f0fff4);
        }

        .form-input.invalid {
            border-color: #e74c3c;
            background: linear-gradient(135deg, #fff5f5, #fef5e7);
        }

        .validation-message {
            font-size: 12px;
            margin-top: 6px;
            padding: 6px 12px;
            border-radius: 8px;
            font-weight: 600;
        }

        .validation-message.success {
            color: #27ae60;
            background: linear-gradient(135deg, #d5f4e6, #c3e6cb);
        }

        .validation-message.error {
            color: #e74c3c;
            background: linear-gradient(135deg, #fadbd8, #f5c6cb);
        }

        /* Auto-save indicator */
        .auto-save-indicator {
            position: fixed;
            top: 20px;
            right: 20px;
            background: linear-gradient(135deg, #27ae60, #219a52);
            color: white;
            padding: 12px 18px;
            border-radius: 12px;
            font-size: 14px;
            z-index: 1000;
            display: none;
            animation: fadeInOut 2s;
            box-shadow: 0 8px 25px rgba(39, 174, 96, 0.3);
        }

        @keyframes fadeInOut {
            0%, 100% { opacity: 0; transform: translateY(-20px); }
            50% { opacity: 1; transform: translateY(0); }
        }

        /* Progress indicator */
        .progress-bar {
            width: 100%;
            height: 6px;
            background: rgba(102, 126, 234, 0.2);
            border-radius: 3px;
            margin: 15px 0;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #667eea, #764ba2);
            width: 0%;
            transition: width 0.3s ease;
            border-radius: 3px;
        }

        /* Token status indicator */
        .token-status {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: linear-gradient(135deg, #17a2b8, #138496);
            color: white;
            padding: 8px 15px;
            border-radius: 8px;
            font-size: 12px;
            z-index: 1000;
            display: none;
            box-shadow: 0 4px 15px rgba(23, 162, 184, 0.3);
        }

        .token-status.valid {
            background: linear-gradient(135deg, #28a745, #218838);
        }

        .token-status.expired {
            background: linear-gradient(135deg, #dc3545, #c82333);
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

            .form-grid {
                grid-template-columns: 1fr;
            }

            .summary-grid {
                grid-template-columns: 1fr;
            }

            .button-group {
                flex-direction: column;
            }

            .debug-info {
                font-size: 11px;
                padding: 15px;
                margin: 15px 20px;
            }

            .page-header h1 {
                font-size: 2rem;
            }

            .donor-summary {
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

    <div class="auto-save-indicator" id="autoSaveIndicator">
        <i class="fas fa-save"></i> Auto-saved
    </div>

    <div class="token-status" id="tokenStatus">
        <i class="fas fa-shield-alt"></i> Token: Valid
    </div>

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
                        <i class="fas fa-tint"></i>
                        Complete Donation Process
                    </h1>
                    <p>Final step: Record donation details and complete the procedure</p>
                </div>

                <!-- Donor Summary -->
                <div class="donor-summary">
                    <h3><i class="fas fa-user-check"></i> Ready for Donation</h3>
                    <div class="summary-grid">
                        <div class="summary-item">
                            <strong>Registration ID:</strong> <?php echo htmlspecialchars($donor['RegistrationID']); ?>
                        </div>
                        <div class="summary-item">
                            <strong>Student ID:</strong> <?php echo htmlspecialchars($donor['StudentID'] ?? 'N/A'); ?>
                        </div>
                        <div class="summary-item">
                            <strong>Event ID:</strong> <?php echo htmlspecialchars($donor['EventID'] ?? 'N/A'); ?>
                        </div>
                        <div class="summary-item">
                            <strong>Blood Type:</strong> <?php echo htmlspecialchars($donation_record['DonationBloodType'] ?? 'Not set'); ?>
                        </div>
                        <div class="summary-item">
                            <strong>Weight:</strong> <?php echo htmlspecialchars($donation_record['Weight'] ?? 'N/A'); ?> kg
                        </div>
                        <div class="summary-item">
                            <strong>Planned Volume:</strong> <?php echo htmlspecialchars($donation_record['DonationQuantity']); ?>ml
                        </div>
                        <div class="summary-item">
                            <strong>Blood Pressure:</strong> <?php echo htmlspecialchars($donation_record['BloodPressure'] ?? 'Not recorded'); ?>
                        </div>
                        <div class="summary-item">
                            <strong>Temperature:</strong> <?php echo htmlspecialchars($donation_record['Temperature'] ?? 'N/A'); ?>°C
                        </div>
                    </div>
                </div>

                <!-- Debug Info -->
                <div class="debug-info">
                    <strong>🔍 Debug Info:</strong> Registration ID: <?php echo $registration_id; ?> | 
                    Attendance: <?php echo htmlspecialchars($donor['AttendanceStatus'] ?? 'NULL'); ?> | 
                    Donation ID: <?php echo $donation_record['DonationID']; ?> | 
                    Status: <?php echo htmlspecialchars($donation_record['DonationStatus'] ?? 'NULL'); ?> |
                    Staff: <?php echo $_SESSION['staff_id']; ?> |
                    Token: <?php echo substr($csrf_token, 0, 8); ?>...
                </div>

                <!-- Status Info -->
                <div class="status-info">
                    <strong>📋 Current Status:</strong> 
                    Attendance: <?php echo ucfirst($attendanceStatus); ?> | 
                    Donation Status: <?php echo ucfirst($donation_record['DonationStatus'] ?? 'pending'); ?> |
                    Registration ID: <?php echo $registration_id; ?> |
                    Donation ID: <?php echo $donation_record['DonationID']; ?> |
                    Token Age: <?php echo $token_age; ?>s
                </div>

                <!-- Progress Bar -->
                <div class="progress-bar">
                    <div class="progress-fill" id="progressFill"></div>
                </div>

                <!-- Alert -->
                <?php if (isset($error_message)): ?>
                    <div class="alert error">
                        <i class="fas fa-exclamation-triangle"></i>
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>

                <!-- Form -->
                <div class="form-container">
                    <form method="POST" action="" id="donationForm">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>" id="csrfToken">

                        <!-- Pre-Donation Tests -->
                        <div class="form-section">
                            <h3 class="section-title">
                                <i class="fas fa-microscope"></i>
                                Pre-Donation Tests
                            </h3>
                            
                            <div class="info-box">
                                <strong>Important:</strong> Hemoglobin must be ≥11.0 g/dL and platelets ≥150×10³/μL for safe donation.
                            </div>

                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label">Hemoglobin Level (g/dL)</label>
                                    <input type="number" name="hemoglobin_level" class="form-input" 
                                           min="10" max="20" step="0.1"
                                           value="<?php echo htmlspecialchars($donation_record['HemoglobinLevel'] ?? ''); ?>"
                                           placeholder="e.g. 13.5" id="hemoglobinInput">
                                    <div class="validation-message" id="hemoglobinMessage"></div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Platelet Count (×10³/μL)</label>
                                    <input type="number" name="platelet_count" class="form-input" 
                                           min="100" max="1000" step="1"
                                           value="<?php echo htmlspecialchars($donation_record['PlateletCount'] ?? ''); ?>"
                                           placeholder="Normal: 150-450" id="plateletInput">
                                    <div class="validation-message" id="plateletMessage"></div>
                                </div>
                            </div>
                        </div>

                        <!-- Vital Signs -->
                        <div class="form-section">
                            <h3 class="section-title">
                                <i class="fas fa-heartbeat"></i>
                                Vital Signs & Measurements
                            </h3>

                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label">Weight (kg)</label>
                                    <input type="number" name="weight" class="form-input" 
                                           min="30" max="200" step="0.1"
                                           value="<?php echo htmlspecialchars($donation_record['Weight'] ?? ''); ?>"
                                           placeholder="e.g. 70.5" id="weightInput">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Blood Pressure</label>
                                    <input type="text" name="blood_pressure" class="form-input" 
                                           value="<?php echo htmlspecialchars($donation_record['BloodPressure'] ?? ''); ?>"
                                           placeholder="e.g. 120/80" id="bloodPressureInput">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Temperature (°C)</label>
                                    <input type="number" name="temperature" class="form-input" 
                                           min="35" max="42" step="0.1"
                                           value="<?php echo htmlspecialchars($donation_record['Temperature'] ?? ''); ?>"
                                           placeholder="e.g. 36.5" id="temperatureInput">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Pulse Rate (bpm)</label>
                                    <input type="number" name="pulse_rate" class="form-input" 
                                           min="40" max="200" step="1"
                                           value="<?php echo htmlspecialchars($donation_record['PulseRate'] ?? ''); ?>"
                                           placeholder="e.g. 72" id="pulseRateInput">
                                </div>
                            </div>
                        </div>

                        <!-- Notes and Comments -->
                        <div class="form-section">
                            <h3 class="section-title">
                                <i class="fas fa-notes-medical"></i>
                                Additional Notes & Comments
                            </h3>
                            
                            <div class="form-group">
                                <label class="form-label">Staff Notes (Optional)</label>
                                <textarea name="notes_comments" class="form-textarea" 
                                          placeholder="Enter any additional observations, complications, or notes about the donation process..."></textarea>
                            </div>

                            <div class="info-box">
                                <strong>Note:</strong> All donation details will be updated in the donation record upon completion.
                            </div>
                        </div>

                        <!-- Submit Buttons -->
                        <div class="button-group">
                            <button type="submit" class="btn btn-primary" id="submitBtn">
                                <i class="fas fa-check-circle"></i>
                                Complete Donation Process
                            </button>
                            
                            <a href="staff_view_donation.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i>
                                Back to Donations
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

        // Real-time validation and form functionality
        document.addEventListener('DOMContentLoaded', function() {
            const hemoglobinInput = document.getElementById('hemoglobinInput');
            const plateletInput = document.getElementById('plateletInput');
            const progressFill = document.getElementById('progressFill');
            const submitBtn = document.getElementById('submitBtn');
            const autoSaveIndicator = document.getElementById('autoSaveIndicator');
            const tokenStatus = document.getElementById('tokenStatus');

            // Show token status
            tokenStatus.style.display = 'block';
            tokenStatus.className = 'token-status valid';

            // Validate hemoglobin level
            hemoglobinInput.addEventListener('input', function() {
                const value = parseFloat(this.value);
                const messageElement = document.getElementById('hemoglobinMessage');
                
                if (value >= 11.0 && value <= 20.0) {
                    this.className = 'form-input valid';
                    messageElement.textContent = '✓ Acceptable level for donation';
                    messageElement.className = 'validation-message success';
                } else if (value > 0 && value < 11.0) {
                    this.className = 'form-input invalid';
                    messageElement.textContent = '⚠ Too low for safe donation (minimum 11.0 g/dL)';
                    messageElement.className = 'validation-message error';
                } else if (value > 20.0) {
                    this.className = 'form-input invalid';
                    messageElement.textContent = '⚠ Unusually high level - please verify';
                    messageElement.className = 'validation-message error';
                } else {
                    this.className = 'form-input';
                    messageElement.textContent = '';
                }
                updateProgress();
            });

            // Validate platelet count
            plateletInput.addEventListener('input', function() {
                const value = parseFloat(this.value);
                const messageElement = document.getElementById('plateletMessage');
                
                if (value >= 150 && value <= 450) {
                    this.className = 'form-input valid';
                    messageElement.textContent = '✓ Normal platelet count';
                    messageElement.className = 'validation-message success';
                } else if (value > 0 && value < 150) {
                    this.className = 'form-input invalid';
                    messageElement.textContent = '⚠ Too low for safe donation (minimum 150×10³/μL)';
                    messageElement.className = 'validation-message error';
                } else if (value > 450) {
                    this.className = 'form-input valid';
                    messageElement.textContent = '✓ Elevated but acceptable for donation';
                    messageElement.className = 'validation-message success';
                } else {
                    this.className = 'form-input';
                    messageElement.textContent = '';
                }
                updateProgress();
            });

            // Update progress bar
            function updateProgress() {
                let progress = 0;
                const fields = [
                    hemoglobinInput.value,
                    plateletInput.value,
                    document.getElementById('weightInput').value,
                    document.getElementById('bloodPressureInput').value,
                    document.getElementById('temperatureInput').value,
                    document.getElementById('pulseRateInput').value
                ];
                
                fields.forEach(field => {
                    if (field && field.trim() !== '') {
                        progress += 16.67; // 100/6 fields
                    }
                });
                
                progressFill.style.width = Math.min(progress, 100) + '%';
            }

            // Auto-save functionality (simulate)
            let autoSaveTimeout;
            const formInputs = document.querySelectorAll('.form-input, .form-select, .form-textarea');
            
            formInputs.forEach(input => {
                input.addEventListener('input', function() {
                    clearTimeout(autoSaveTimeout);
                    autoSaveTimeout = setTimeout(function() {
                        // Simulate auto-save
                        autoSaveIndicator.style.display = 'block';
                        setTimeout(() => {
                            autoSaveIndicator.style.display = 'none';
                        }, 2000);
                    }, 3000);
                    updateProgress();
                });
            });

            // CSRF token monitoring
            const csrfToken = document.getElementById('csrfToken');
            let tokenAge = <?php echo $token_age; ?>;
            
            setInterval(function() {
                tokenAge += 1;
                if (tokenAge > 3000) { // 50 minutes
                    tokenStatus.className = 'token-status expired';
                    tokenStatus.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Token: Expired';
                } else if (tokenAge > 2700) { // 45 minutes
                    tokenStatus.className = 'token-status';
                    tokenStatus.innerHTML = '<i class="fas fa-clock"></i> Token: Expiring';
                }
            }, 1000);

            // Form submission validation
            document.getElementById('donationForm').addEventListener('submit', function(e) {
                const hemoglobin = parseFloat(hemoglobinInput.value);
                const platelets = parseFloat(plateletInput.value);
                
                if (hemoglobin > 0 && hemoglobin < 11.0) {
                    e.preventDefault();
                    alert('Hemoglobin level is too low for safe donation. Please verify the reading.');
                    hemoglobinInput.focus();
                    return false;
                }
                
                if (platelets > 0 && platelets < 150) {
                    e.preventDefault();
                    alert('Platelet count is too low for safe donation. Please verify the reading.');
                    plateletInput.focus();
                    return false;
                }

                // Confirm submission
                if (!confirm('Are you sure you want to complete this donation process? This action cannot be undone.')) {
                    e.preventDefault();
                    return false;
                }

                // Disable submit button to prevent double submission
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            });

            // Initialize progress
            updateProgress();

            // Add entrance animations
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

            // Add ripple effect to buttons
            document.querySelectorAll('.btn').forEach(btn => {
                btn.addEventListener('click', function(e) {
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
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+S to simulate save
            if (e.ctrlKey && e.key === 's') {
                e.preventDefault();
                const autoSaveIndicator = document.getElementById('autoSaveIndicator');
                autoSaveIndicator.style.display = 'block';
                setTimeout(() => {
                    autoSaveIndicator.style.display = 'none';
                }, 2000);
            }
            
            // Escape to go back
            if (e.key === 'Escape') {
                if (confirm('Confirm donation?')) {
                    window.location.href = 'staff_view_donation.php';
                }
            }
        });

        // Page visibility API to handle tab switching
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                // User switched tabs, refresh token on return
                setTimeout(function() {
                    if (!document.hidden) {
                        // Refresh CSRF token if page was hidden for more than 5 minutes
                        location.reload();
                    }
                }, 300000); // 5 minutes
            }
        });

        // Warn before leaving page if form has data
        window.addEventListener('beforeunload', function(e) {
            const formData = new FormData(document.getElementById('donationForm'));
            let hasData = false;
            
            for (let [key, value] of formData.entries()) {
                if (key !== 'csrf_token' && value.trim() !== '') {
                    hasData = true;
                    break;
                }
            }
            
            if (hasData) {
                e.preventDefault();
                e.returnValue = '';
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

        // Add CSS for keyboard navigation
        const keyboardStyle = document.createElement('style');
        keyboardStyle.textContent = `
            .keyboard-navigation .nav-item:focus,
            .keyboard-navigation .btn:focus,
            .keyboard-navigation .form-input:focus,
            .keyboard-navigation .form-select:focus,
            .keyboard-navigation .form-textarea:focus {
                outline: 3px solid #667eea;
                outline-offset: 2px;
            }
        `;
        document.head.appendChild(keyboardStyle);

        // Performance monitoring
        window.addEventListener('load', function() {
            const loadTime = performance.now();
            console.log(`⚡ Donation Process Page loaded in ${Math.round(loadTime)}ms`);
            
            // Show load complete notification
            setTimeout(() => {
                const notification = document.createElement('div');
                notification.style.cssText = `
                    position: fixed; 
                    top: 20px; 
                    right: 380px; 
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
                notification.innerHTML = '🩸 Donation Process Ready!';
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

        // Console logging for debugging
        console.log('🩸 Donation Process System Ready');
        console.log('📊 Registration ID:', <?php echo $registration_id; ?>);
        console.log('🆔 Donation ID:', <?php echo $donation_record['DonationID']; ?>);
        console.log('📋 Status:', '<?php echo addslashes(htmlspecialchars($donation_record['DonationStatus'] ?? 'pending')); ?>');
        console.log('🎯 Enhanced Donation Process Active!');
    </script>
</body>
</html>