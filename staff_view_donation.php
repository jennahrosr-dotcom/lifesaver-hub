<?php
// Include the configuration file
session_start();
require_once 'config.php';

// Set timezone to Malaysia (since you're in Petaling Jaya, Selangor)
date_default_timezone_set('Asia/Kuala_Lumpur');

$pdo = getDbConnection();

// Initialize session (already handled in config.php)
if (!initializeSession()) {
    echo "<script>alert('Session expired. Please login again.'); window.location.href='index.php';</script>";
    exit();
}

// Check if user is logged in and is staff - FIXED FOR YOUR SESSION STRUCTURE
$user_logged_in = isset($_SESSION['staff_id']) && !empty($_SESSION['staff_id']);
$is_staff = $user_logged_in; // If staff_id exists, they are staff

// Set user_id for compatibility with the rest of the code
if ($user_logged_in) {
    $_SESSION['user_id'] = $_SESSION['staff_id']; // Map staff_id to user_id
    $_SESSION['user_type'] = 'staff'; // Set the user type
}

if (!$user_logged_in || !$is_staff) {
    echo "<script>alert('Access denied. Please login as staff first.'); window.location.href='index.php';</script>";
    exit();
}

// Get database connection
try {
    $pdo = getDbConnection();
} catch (PDOException $e) {
    $error_message = "Database connection error. Please check your database configuration.";
    $pdo = null; // Set to null to handle gracefully below
}

// Get staff information
$stmt = $pdo->prepare("SELECT * FROM staff WHERE StaffID = ?");
$stmt->execute([$_SESSION['staff_id']]);
$staff = $stmt->fetch();

// Initialize variables with security sanitization
$search = SecurityHelper::sanitizeInput($_GET['search'] ?? '');
$event_date = SecurityHelper::sanitizeInput($_GET['event_date'] ?? '');
$registration_status = SecurityHelper::sanitizeInput($_GET['registration_status'] ?? '');
$attendance_filter = SecurityHelper::sanitizeInput($_GET['attendance_filter'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 15;
$offset = ($page - 1) * $per_page;

// Initialize default values
$donations = [];
$stats = ['total' => 0, 'confirmed' => 0, 'cancelled' => 0, 'pending' => 0, 'present' => 0, 'absent' => 0, 'registered' => 0, 'attendance_pending' => 0];
$total_records = 0;
$total_pages = 1;
$available_dates = [];

try {
    // Check if we have a valid database connection
    if (!$pdo) {
        throw new Exception("No database connection available");
    }
    
    // Check if tables exist first - Updated for your actual table name
    $table_check = $pdo->query("SHOW TABLES LIKE 'registration'");
    if ($table_check->rowCount() == 0) {
        throw new Exception("Database table 'registration' does not exist. Please create the database tables first.");
    }

    // Build WHERE clause for your actual table structure - CORRECTED FIELD NAMES
    $where_conditions = [];
    $params = [];

    if (!empty($search)) {
        $where_conditions[] = "(s.StudentName LIKE ? OR r.StudentID LIKE ? OR s.StudentIC LIKE ? OR s.StudentEmail LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }

    // FIXED: Use correct column name with proper timezone handling
    if (!empty($event_date)) {
        $where_conditions[] = "DATE(CONVERT_TZ(e.EventDate, '+00:00', '+08:00')) = ?";
        $params[] = $event_date;
    }

    if (!empty($registration_status)) {
        $where_conditions[] = "LOWER(r.RegistrationStatus) = LOWER(?)";
        $params[] = $registration_status;
    }

    if (!empty($attendance_filter)) {
        switch ($attendance_filter) {
            case 'present':
                $where_conditions[] = "LOWER(COALESCE(r.AttendanceStatus, '')) IN ('present', 'attended', 'confirmed')";
                break;
            case 'absent':
                $where_conditions[] = "LOWER(COALESCE(r.AttendanceStatus, '')) IN ('absent', 'no-show')";
                break;
            case 'pending':
                $where_conditions[] = "(LOWER(COALESCE(r.AttendanceStatus, '')) IN ('pending', 'waiting', '') OR r.AttendanceStatus IS NULL)";
                break;
        }
    }

    $where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

    // Get total count for pagination - CORRECTED WITH STUDENT JOIN
    $count_sql = "SELECT COUNT(*) as total 
                  FROM registration r 
                  LEFT JOIN student s ON r.StudentID = s.StudentID 
                  LEFT JOIN event e ON r.EventID = e.EventID
                  $where_clause";
    
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $count_result = $count_stmt->fetch(PDO::FETCH_ASSOC);
    $total_records = $count_result ? $count_result['total'] : 0;
    $total_pages = ceil($total_records / $per_page);

    // FIXED: Main query with correct column names
    $sql = "SELECT 
                r.RegistrationID as donation_id,
                r.StudentID as student_id,
                COALESCE(s.StudentName, 'Unknown') as full_name,
                COALESCE(s.StudentEmail, 'N/A') as email,
                COALESCE(s.StudentContact, 'N/A') as phone,
                COALESCE(s.StudentGender, 'N/A') as gender,
                COALESCE(s.StudentAge, 0) as age,
                CONVERT_TZ(r.RegistrationDate, '+00:00', '+08:00') as registration_date,
                COALESCE(r.RegistrationStatus, 'pending') as registration_status,
                COALESCE(r.AttendanceStatus, 'pending') as attendance_status,
                r.EventID,
                -- Event information (using correct field names and timezone)
                CONVERT_TZ(e.EventDate, '+00:00', '+08:00') as event_date,
                COALESCE(e.EventTitle, CONCAT('Event ', r.EventID)) as event_name,
                COALESCE(e.EventVenue, 'TBD') as event_location,
                COALESCE(e.EventDescription, '') as event_description,
                COALESCE(e.EventDay, '') as event_day,
                COALESCE(e.EventStatus, 'active') as event_status,
                -- Student details
                COALESCE(s.StudentIC, 'N/A') as RegistrationIC,
                COALESCE(s.StudentAddress, 'N/A') as RegistrationAddress,
                r.CancellationReason,
                CONVERT_TZ(r.CancellationDate, '+00:00', '+08:00') as CancellationDate,
                -- Donation information
                d.DonationID as existing_donation_id,
                CONVERT_TZ(d.DonationDate, '+00:00', '+08:00') as donation_date,
                d.DonationBloodType as blood_type,
                d.Weight as weight,
                d.DonationStatus as donation_status
            FROM registration r
            LEFT JOIN student s ON r.StudentID = s.StudentID
            LEFT JOIN event e ON r.EventID = e.EventID
            LEFT JOIN donation d ON r.RegistrationID = d.RegistrationID
            $where_clause
            ORDER BY CONVERT_TZ(r.RegistrationDate, '+00:00', '+08:00') DESC
            LIMIT $per_page OFFSET $offset";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $donations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get enhanced statistics - CORRECTED WITH STUDENT JOIN
    $stats_sql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN LOWER(COALESCE(r.RegistrationStatus, 'pending')) IN ('confirmed', 'approved', 'active') THEN 1 ELSE 0 END) as confirmed,
                    SUM(CASE WHEN LOWER(COALESCE(r.RegistrationStatus, 'pending')) IN ('cancelled', 'rejected', 'inactive') THEN 1 ELSE 0 END) as cancelled,
                    SUM(CASE WHEN LOWER(COALESCE(r.RegistrationStatus, 'pending')) IN ('pending', 'waiting') OR r.RegistrationStatus IS NULL OR r.RegistrationStatus = '' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN LOWER(COALESCE(r.RegistrationStatus, 'pending')) = 'registered' THEN 1 ELSE 0 END) as registered,
                    SUM(CASE WHEN LOWER(COALESCE(r.AttendanceStatus, '')) IN ('present', 'attended', 'confirmed') THEN 1 ELSE 0 END) as present,
                    SUM(CASE WHEN LOWER(COALESCE(r.AttendanceStatus, '')) IN ('absent', 'no-show') THEN 1 ELSE 0 END) as absent,
                    SUM(CASE WHEN (LOWER(COALESCE(r.AttendanceStatus, '')) IN ('pending', 'waiting', '') OR r.AttendanceStatus IS NULL) 
                              AND LOWER(COALESCE(r.RegistrationStatus, 'pending')) NOT IN ('cancelled', 'rejected', 'inactive') THEN 1 ELSE 0 END) as attendance_pending
                  FROM registration r 
                  LEFT JOIN student s ON r.StudentID = s.StudentID 
                  LEFT JOIN event e ON r.EventID = e.EventID
                  $where_clause";
    
    $stats_stmt = $pdo->prepare($stats_sql);
    $stats_stmt->execute($params);
    $stats_result = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    if ($stats_result) {
        $stats = $stats_result;
    }

    // FIXED: Get available event dates for filter dropdown - using correct timezone
    $dates_sql = "SELECT DISTINCT 
                         DATE(CONVERT_TZ(e.EventDate, '+00:00', '+08:00')) as event_date, 
                         DATE_FORMAT(CONVERT_TZ(e.EventDate, '+00:00', '+08:00'), '%M %d, %Y') as event_name,
                         COUNT(*) as registration_count
                  FROM registration r 
                  LEFT JOIN event e ON r.EventID = e.EventID
                  WHERE e.EventDate IS NOT NULL
                  GROUP BY DATE(CONVERT_TZ(e.EventDate, '+00:00', '+08:00'))
                  
                  UNION
                  
                  SELECT DISTINCT 
                         DATE(CONVERT_TZ(r.RegistrationDate, '+00:00', '+08:00')) as event_date, 
                         CONCAT('Registered on ', DATE_FORMAT(CONVERT_TZ(r.RegistrationDate, '+00:00', '+08:00'), '%M %d, %Y')) as event_name,
                         COUNT(*) as registration_count
                  FROM registration r 
                  WHERE r.RegistrationDate IS NOT NULL
                  GROUP BY DATE(CONVERT_TZ(r.RegistrationDate, '+00:00', '+08:00'))
                  
                  ORDER BY event_date DESC
                  LIMIT 50";
    $dates_stmt = $pdo->prepare($dates_sql);
    $dates_stmt->execute();
    $available_dates = $dates_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch(PDOException $e) {
    $error_message = "Database connection error: " . $e->getMessage();
    error_log("Database error in staff_view_donation.php: " . $e->getMessage());
} catch(Exception $e) {
    $error_message = $e->getMessage();
    error_log("General error in staff_view_donation.php: " . $e->getMessage());
}

// Enhanced helper functions
function getTodayDate() {
    return date('Y-m-d', time());
}

function getTodayDateTime() {
    return date('Y-m-d H:i:s', time());
}

function getStatusBadge($status) {
    $status = strtolower(trim($status ?? 'pending'));
    
    // Map all similar statuses to simplified ones
    $status_mapping = [
        // Confirmed group
        'confirmed' => 'confirmed',
        'approved' => 'confirmed', 
        'active' => 'confirmed',
        
        // Cancelled group  
        'cancelled' => 'cancelled',
        'rejected' => 'cancelled',
        'inactive' => 'cancelled',
        
        // Registered
        'registered' => 'registered',
        
        // Present/Attended
        'present' => 'present',
        'attended' => 'present',
        
        // Absent
        'absent' => 'absent',
        'no-show' => 'absent',
        
        // Pending (default)
        'pending' => 'pending',
        'waiting' => 'pending',
        '' => 'pending'
    ];
    
    return $status_mapping[$status] ?? 'pending';
}

function getDisplayStatus($status) {
    $status = trim($status ?? '');
    if (empty($status)) {
        return 'Pending';
    }
    return ucfirst(strtolower($status));
}

function formatDate($date) {
    if (!$date || $date === '1970-01-01') return 'N/A';
    
    try {
        // Ensure we're working with Malaysia timezone
        $dateObj = new DateTime($date, new DateTimeZone('Asia/Kuala_Lumpur'));
        return $dateObj->format('M d, Y');
    } catch (Exception $e) {
        return 'Invalid Date';
    }
}

function canConfirmAttendance($donation) {
    // Use Malaysia timezone for accurate date comparison
    $event_date = new DateTime($donation['event_date'], new DateTimeZone('Asia/Kuala_Lumpur'));
    $today = new DateTime('now', new DateTimeZone('Asia/Kuala_Lumpur'));
    
    // Only allow confirmation ON the event date (not before, not after)
    $is_event_date = $event_date->format('Y-m-d') === $today->format('Y-m-d');
    
    $registrationStatus = strtolower(trim($donation['registration_status'] ?? ''));
    $attendanceStatus = strtolower(trim($donation['attendance_status'] ?? ''));
    
    $status_check = in_array($registrationStatus, ['registered', 'confirmed', 'approved', 'active']);
    $attendance_check = !in_array($attendanceStatus, ['present', 'attended', 'confirmed']);
    $no_existing_donation = empty($donation['existing_donation_id']);
    
    return $status_check && $attendance_check && $is_event_date && $no_existing_donation;
}

function getActionReasonText($donation) {
    // Use Malaysia timezone for accurate date comparison
    $event_date = new DateTime($donation['event_date'], new DateTimeZone('Asia/Kuala_Lumpur'));
    $today = new DateTime('now', new DateTimeZone('Asia/Kuala_Lumpur'));
    
    $is_future_event = $event_date->format('Y-m-d') > $today->format('Y-m-d');
    $is_past_event = $event_date->format('Y-m-d') < $today->format('Y-m-d');
    $is_event_date = $event_date->format('Y-m-d') === $today->format('Y-m-d');
    
    $registrationStatus = strtolower(trim($donation['registration_status'] ?? ''));
    $attendanceStatus = strtolower(trim($donation['attendance_status'] ?? ''));
    
    if ($is_future_event) {
        return "Event not started (" . $event_date->format('M d') . ")";
    }
    
    if ($is_past_event) {
        return "Past event (" . $event_date->format('M d') . ")";
    }
    
    if (!empty($donation['existing_donation_id'])) {
        return "Already donated (#" . $donation['existing_donation_id'] . ")";
    }
    
    if (in_array($attendanceStatus, ['present', 'attended', 'confirmed'])) {
        return "Already marked present";
    }
    
    if (in_array($registrationStatus, ['cancelled', 'rejected', 'inactive'])) {
        return "Registration " . $registrationStatus;
    }
    
    if (in_array($attendanceStatus, ['absent', 'no-show'])) {
        return "Marked as absent";
    }
    
    if (!in_array($registrationStatus, ['registered', 'confirmed', 'approved', 'active'])) {
        return "Status: " . ucfirst($registrationStatus);
    }
    
    // If we reach here and it's the event date, they're ready to confirm
    if ($is_event_date) {
        return "Ready to confirm attendance";
    }
    
    return "Cannot confirm yet";
}

// Add CSRF token for forms
$csrf_token = SecurityHelper::generateCSRF();

// Debug information - you can remove this after confirming it works
$debug_info = [
    'current_date' => date('Y-m-d'),
    'current_datetime' => date('Y-m-d H:i:s'),
    'timezone' => date_default_timezone_get(),
    'server_time' => date('Y-m-d H:i:s T')
];

// You can output this for debugging: 
// echo "<!-- Debug: " . json_encode($debug_info) . " -->";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Blood Donor Management - LifeSaver Hub</title>
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
            font-size: 2.5rem;
            font-weight: 900;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 20px;
            text-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            margin: 0 0 12px 0;
        }

        .page-header h1 i {
            background: linear-gradient(135deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-size: 2rem;
        }

        .subtitle {
            color: #4a5568;
            font-size: 16px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        /* Quick Action Panel */
        .quick-actions {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 25px;
            border-radius: 20px;
            margin-bottom: 25px;
            box-shadow: 0 15px 35px rgba(102, 126, 234, 0.2);
        }

        .quick-actions h3 {
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 18px;
            font-weight: 700;
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .action-btn-quick {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 12px 24px;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 12px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 10px;
            backdrop-filter: blur(10px);
        }

        .action-btn-quick:hover {
            background: rgba(255,255,255,0.3);
            border-color: rgba(255,255,255,0.5);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
        }

        /* Enhanced Search Section */
        .search-section {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.08);
            margin-bottom: 30px;
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .search-form {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr auto auto;
            gap: 20px;
            align-items: end;
        }

        .search-group {
            display: flex;
            flex-direction: column;
        }

        .search-label {
            color: #2d3748;
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .search-label i {
            color: #667eea;
        }

        .search-input, .search-select {
            padding: 15px 20px;
            border: 2px solid rgba(102, 126, 234, 0.2);
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(10px);
            color: #2d3748;
            font-weight: 500;
        }

        .search-input:focus, .search-select:focus {
            outline: none;
            border-color: #667eea;
            background: rgba(255, 255, 255, 0.95);
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
            transform: scale(1.02);
        }

        .search-button {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            padding: 15px 25px;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
        }

        .search-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 35px rgba(102, 126, 234, 0.4);
        }

        .clear-button {
            background: #95a5a6;
            color: white;
            border: none;
            padding: 15px 20px;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .clear-button:hover {
            background: #7f8c8d;
            transform: translateY(-2px);
        }

        /* Enhanced Statistics Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.08);
            text-align: center;
            border-left: 4px solid;
            position: relative;
            transition: all 0.3s ease;
            cursor: pointer;
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .stat-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15);
        }

        .stat-card.total { border-left-color: #667eea; }
        .stat-card.pending { border-left-color: #f39c12; }
        .stat-card.registered { border-left-color: #9b59b6; }
        .stat-card.confirmed { border-left-color: #27ae60; }
        .stat-card.cancelled { border-left-color: #e74c3c; }
        .stat-card.present { border-left-color: #16a085; }
        .stat-card.absent { border-left-color: #e67e22; }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 900;
            margin-bottom: 10px;
            display: block;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .stat-card.total .stat-number { color: #667eea; }
        .stat-card.pending .stat-number { color: #f39c12; }
        .stat-card.registered .stat-number { color: #9b59b6; }
        .stat-card.confirmed .stat-number { color: #27ae60; }
        .stat-card.cancelled .stat-number { color: #e74c3c; }
        .stat-card.present .stat-number { color: #16a085; }
        .stat-card.absent .stat-number { color: #e67e22; }

        .stat-label {
            color: #2d3748;
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .stat-description {
            font-size: 12px;
            color: #4a5568;
            margin-top: 8px;
            font-weight: 500;
        }

        /* Enhanced Table */
        .table-section {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .table-header {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(139, 92, 246, 0.1));
            padding: 25px 30px;
            border-bottom: 1px solid rgba(102, 126, 234, 0.15);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-title {
            color: #2d3748;
            font-size: 20px;
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .table-title i {
            color: #667eea;
        }

        .table-controls {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .table-control-btn {
            background: rgba(102, 126, 234, 0.1);
            border: 2px solid rgba(102, 126, 234, 0.2);
            padding: 10px 18px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            color: #667eea;
        }

        .table-control-btn:hover {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border-color: transparent;
            transform: translateY(-2px);
        }

        .table-control-btn.active {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border-color: transparent;
        }

        .simple-table {
            width: 100%;
            border-collapse: collapse;
        }

        .simple-table th {
            background: linear-gradient(135deg, rgba(248, 249, 250, 0.98), rgba(255, 255, 255, 0.98));
            padding: 20px;
            text-align: left;
            font-weight: 700;
            color: #2d3748;
            border-bottom: 2px solid rgba(102, 126, 234, 0.15);
            font-size: 14px;
            position: sticky;
            top: 0;
            z-index: 10;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .simple-table td {
            padding: 20px;
            border-bottom: 1px solid rgba(102, 126, 234, 0.1);
            vertical-align: top;
        }

        .simple-table tbody tr {
            transition: all 0.3s ease;
        }

        .simple-table tbody tr:hover {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.05), rgba(139, 92, 246, 0.05));
            transform: scale(1.01);
        }

        .donor-info {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .donor-name {
            font-weight: 700;
            color: #2d3748;
            font-size: 16px;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        }

        .donor-details {
            color: #4a5568;
            font-size: 13px;
            line-height: 1.4;
            font-weight: 500;
        }

        .donor-meta {
            display: flex;
            gap: 8px;
            margin-top: 8px;
            flex-wrap: wrap;
        }

        .donor-tag {
            background: rgba(102, 126, 234, 0.1);
            color: #2d3748;
            padding: 4px 10px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 600;
            border: 1px solid rgba(102, 126, 234, 0.2);
        }

        .donor-tag.blood-type {
            background: linear-gradient(135deg, #e8f4fd, #d6eaf8);
            color: #2980b9;
            font-weight: 700;
            border-color: #2980b9;
        }

        .contact-info {
            font-size: 13px;
            line-height: 1.5;
            font-weight: 500;
        }

        .contact-item {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 6px;
            color: #4a5568;
        }

        .contact-item i {
            width: 14px;
            color: #667eea;
            font-size: 12px;
        }

        /* Enhanced Status Badges */
        .status-badge {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-block;
            margin-bottom: 6px;
            border: 2px solid;
            backdrop-filter: blur(10px);
        }

        .status-badge.confirmed {
            background: linear-gradient(135deg, #d5f4e6, #c3e6cb);
            color: #27ae60;
            border-color: #27ae60;
        }

        .status-badge.cancelled {
            background: linear-gradient(135deg, #fadbd8, #f5c6cb);
            color: #e74c3c;
            border-color: #e74c3c;
        }

        .status-badge.pending {
            background: linear-gradient(135deg, #fef9e7, #ffeaa7);
            color: #f39c12;
            border-color: #f39c12;
        }

        .status-badge.registered {
            background: linear-gradient(135deg, #d6eaf8, #bee5eb);
            color: #667eea;
            border-color: #667eea;
        }

        .status-badge.present {
            background: linear-gradient(135deg, #d1f2eb, #a2e4d8);
            color: #16a085;
            border-color: #16a085;
        }

        .status-badge.absent {
            background: linear-gradient(135deg, #fdeaa7, #fdd835);
            color: #e67e22;
            border-color: #e67e22;
        }

        .status-info {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .status-date {
            color: #7f8c8d;
            font-size: 11px;
            margin-top: 4px;
            font-weight: 500;
        }

        /* Enhanced Action Buttons */
        .action-section {
            display: flex;
            flex-direction: column;
            gap: 10px;
            min-width: 200px;
        }

        .action-btn {
            padding: 10px 16px;
            border: none;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            white-space: nowrap;
            border: 2px solid;
            backdrop-filter: blur(10px);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .action-btn i {
            font-size: 12px;
        }

        .action-btn.primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border-color: #667eea;
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.3);
        }

        .action-btn.primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.4);
        }

        .action-btn.success {
            background: linear-gradient(135deg, #27ae60, #219a52);
            color: white;
            border-color: #27ae60;
            position: relative;
            box-shadow: 0 6px 20px rgba(39, 174, 96, 0.3);
        }

        .action-btn.success:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(39, 174, 96, 0.4);
        }

        .action-btn.success.priority {
            animation: pulse-success 2s infinite;
        }

        @keyframes pulse-success {
            0% { box-shadow: 0 6px 20px rgba(39, 174, 96, 0.3), 0 0 0 0 rgba(39, 174, 96, 0.7); }
            70% { box-shadow: 0 6px 20px rgba(39, 174, 96, 0.3), 0 0 0 15px rgba(39, 174, 96, 0); }
            100% { box-shadow: 0 6px 20px rgba(39, 174, 96, 0.3), 0 0 0 0 rgba(39, 174, 96, 0); }
        }

        .action-btn.secondary {
            background: linear-gradient(135deg, #6c757d, #5a6268);
            color: white;
            border-color: #6c757d;
            box-shadow: 0 6px 20px rgba(108, 117, 125, 0.3);
        }

        .action-btn.secondary:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(108, 117, 125, 0.4);
        }

        .action-btn.warning {
            background: linear-gradient(135deg, #f39c12, #e67e22);
            color: white;
            border-color: #f39c12;
            box-shadow: 0 6px 20px rgba(243, 156, 18, 0.3);
        }

        .action-btn.warning:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(243, 156, 18, 0.4);
        }

        .action-btn.disabled {
            background: #ecf0f1;
            color: #95a5a6;
            border-color: #bdc3c7;
            cursor: not-allowed;
            box-shadow: none;
        }

        .action-reason {
            font-size: 10px;
            color: #7f8c8d;
            text-align: center;
            margin-top: 4px;
            font-style: italic;
            font-weight: 500;
        }

        /* Priority Indicators */
        .priority-indicator {
            position: absolute;
            top: -8px;
            right: -8px;
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            color: white;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: bold;
            box-shadow: 0 4px 12px rgba(231, 76, 60, 0.4);
            animation: pulse-priority 1.5s infinite;
        }

        @keyframes pulse-priority {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }

        .row-highlight {
            background: linear-gradient(90deg, rgba(255, 245, 245, 0.8) 0%, rgba(255, 255, 255, 0.8) 100%) !important;
            border-left: 4px solid #e74c3c !important;
        }

        .row-highlight.ready {
            background: linear-gradient(90deg, rgba(240, 255, 244, 0.8) 0%, rgba(255, 255, 255, 0.8) 100%) !important;
            border-left: 4px solid #27ae60 !important;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 12px;
            margin-top: 30px;
        }

        .pagination a, .pagination span {
            padding: 12px 18px;
            background: rgba(255, 255, 255, 0.9);
            border: 2px solid rgba(102, 126, 234, 0.2);
            border-radius: 10px;
            color: #2d3748;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }

        .pagination a:hover {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border-color: transparent;
            transform: translateY(-2px);
        }

        .pagination .current {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border-color: transparent;
        }

        /* No Data State */
        .no-data {
            text-align: center;
            padding: 80px 20px;
            color: #7f8c8d;
        }

        .no-data i {
            font-size: 64px;
            margin-bottom: 25px;
            color: #bdc3c7;
        }

        .no-data h3 {
            font-size: 24px;
            margin-bottom: 15px;
            color: #2d3748;
            font-weight: 700;
        }

        .no-data p {
            font-size: 16px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        /* Alert Messages */
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

        .alert.success {
            background: linear-gradient(135deg, rgba(213, 244, 230, 0.9), rgba(195, 230, 203, 0.9));
            border-left-color: #27ae60;
            color: #1e8449;
        }

        .alert.info {
            background: linear-gradient(135deg, rgba(214, 234, 248, 0.9), rgba(190, 229, 235, 0.9));
            border-left-color: #667eea;
            color: #2874a6;
        }

        /* Help Section */
        .help-section {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.08);
            margin-top: 30px;
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .help-section h3 {
            color: #2d3748;
            margin-bottom: 20px;
            font-size: 20px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .help-section h3 i {
            color: #667eea;
        }

        .help-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
        }

        .help-item h4 {
            margin-bottom: 12px;
            font-size: 16px;
            font-weight: 700;
        }

        .help-item.ready h4 { color: #27ae60; }
        .help-item.cannot h4 { color: #e74c3c; }
        .help-item.actions h4 { color: #667eea; }

        .help-item ul {
            color: #4a5568;
            font-size: 14px;
            line-height: 1.6;
            font-weight: 500;
        }

        .help-item li {
            margin-bottom: 6px;
        }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .sidebar {
                width: 300px;
            }
            .main-content {
                margin-left: 300px;
            }
            
            .search-form {
                grid-template-columns: 1fr 1fr;
                gap: 15px;
            }
            
            .search-group:nth-child(5),
            .search-group:nth-child(6) {
                grid-column: span 2;
                display: flex;
                flex-direction: row;
                gap: 10px;
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

            .page-header {
                padding: 30px 25px;
            }

            .page-header h1 {
                font-size: 2rem;
            }

            .search-form {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
            }

            .simple-table {
                font-size: 13px;
            }

            .simple-table th,
            .simple-table td {
                padding: 15px 10px;
            }

            .action-section {
                min-width: 160px;
            }

            .action-btn {
                font-size: 11px;
                padding: 8px 12px;
            }

            .quick-actions {
                text-align: center;
            }

            .action-buttons {
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .page-header {
                padding: 20px;
            }
            
            .search-section {
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
            <!-- Header -->
            <div class="page-header">
                <div class="page-header-content">
                    <h1>
                        <i class="fas fa-heart"></i>
                        Blood Donor Management
                    </h1>
                    <p class="subtitle">Manage donor registrations, confirm attendance, and record donations</p>
                    
                    <!-- Quick Actions Panel -->
                    <div class="quick-actions">
                        <h3>
                            <i class="fas fa-bolt"></i>
                            Quick Actions
                        </h3>
                        <div class="action-buttons">
                            <a href="?attendance_filter=pending" class="action-btn-quick">
                                <i class="fas fa-clock"></i>
                                View Pending Attendance (<?php echo $stats['attendance_pending'] ?? 0; ?>)
                            </a>
                            <a href="?event_date=<?php echo date('Y-m-d'); ?>" class="action-btn-quick">
                                <i class="fas fa-calendar-day"></i>
                                Today's Events
                            </a>
                            <a href="?registration_status=registered" class="action-btn-quick">
                                <i class="fas fa-user-check"></i>
                                Ready to Confirm
                            </a>
                            <a href="generate_report.php" class="action-btn-quick">
                                <i class="fas fa-chart-line"></i>
                                View Reports
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Alerts -->
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
                <div class="alert error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <!-- Active Filters Display -->
            <?php if (!empty($search) || !empty($event_date) || !empty($registration_status) || !empty($attendance_filter)): ?>
                <div class="alert info">
                    <i class="fas fa-filter"></i>
                    <div>
                        <strong>Active Filters:</strong>
                        <?php if (!empty($search)): ?>
                            Search: "<?php echo htmlspecialchars($search); ?>"
                        <?php endif; ?>
                        <?php if (!empty($event_date)): ?>
                            | Date: <?php echo date('M d, Y', strtotime($event_date)); ?>
                        <?php endif; ?>
                        <?php if (!empty($registration_status)): ?>
                            | Status: <?php echo ucfirst($registration_status); ?>
                        <?php endif; ?>
                        <?php if (!empty($attendance_filter)): ?>
                            | Attendance: <?php echo ucfirst($attendance_filter); ?>
                        <?php endif; ?>
                        <a href="staff_view_donation.php" style="margin-left: 15px; color: #667eea; font-weight: 600;">Clear all filters</a>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Search Section -->
            <div class="search-section">
                <form method="GET" action="" class="search-form">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    
                    <div class="search-group">
                        <label class="search-label">
                            <i class="fas fa-search"></i> Search Donors
                        </label>
                        <input type="text" 
                               name="search" 
                               class="search-input" 
                               placeholder="Name, Student ID, IC, or email..."
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    
                    <div class="search-group">
                        <label class="search-label">
                            <i class="fas fa-calendar"></i> Event Date
                        </label>
                        <select name="event_date" class="search-select">
                            <option value="">All Dates</option>
                            <?php if (!empty($available_dates)): ?>
                                <?php foreach ($available_dates as $date): ?>
                                    <option value="<?php echo htmlspecialchars($date['event_date']); ?>" 
                                            <?php echo ($event_date === $date['event_date']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($date['event_name']); ?> (<?php echo $date['registration_count']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="search-group">
                        <label class="search-label">
                            <i class="fas fa-clipboard-list"></i> Registration
                        </label>
                        <select name="registration_status" class="search-select">
                            <option value="">All Statuses</option>
                            <option value="pending" <?php echo ($registration_status === 'pending') ? 'selected' : ''; ?>>Pending</option>
                            <option value="registered" <?php echo ($registration_status === 'registered') ? 'selected' : ''; ?>>Registered</option>
                            <option value="confirmed" <?php echo ($registration_status === 'confirmed') ? 'selected' : ''; ?>>Confirmed</option>
                            <option value="cancelled" <?php echo ($registration_status === 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>

                    <div class="search-group">
                        <label class="search-label">
                            <i class="fas fa-user-check"></i> Attendance
                        </label>
                        <select name="attendance_filter" class="search-select">
                            <option value="">All Attendance</option>
                            <option value="pending" <?php echo ($attendance_filter === 'pending') ? 'selected' : ''; ?>>Pending</option>
                            <option value="present" <?php echo ($attendance_filter === 'present') ? 'selected' : ''; ?>>Present</option>
                            <option value="absent" <?php echo ($attendance_filter === 'absent') ? 'selected' : ''; ?>>Absent</option>
                        </select>
                    </div>
                    
                    <button type="submit" class="search-button">
                        <i class="fas fa-search"></i> Search
                    </button>
                    
                    <a href="staff_view_donation.php" class="clear-button">
                        <i class="fas fa-times"></i> Clear
                    </a>
                </form>
            </div>

            <!-- Enhanced Statistics -->
            <div class="stats-grid">
                <div class="stat-card total">
                    <span class="stat-number"><?php echo $stats['total']; ?></span>
                    <span class="stat-label">Total Registrations</span>
                    <div class="stat-description">All donor registrations</div>
                </div>
                <div class="stat-card pending">
                    <span class="stat-number"><?php echo $stats['attendance_pending']; ?></span>
                    <span class="stat-label">Pending Attendance</span>
                    <div class="stat-description">Awaiting confirmation</div>
                </div>
                <div class="stat-card registered">
                    <span class="stat-number"><?php echo $stats['registered'] ?? 0; ?></span>
                    <span class="stat-label">Registered</span>
                    <div class="stat-description">Ready to confirm</div>
                </div>
                <div class="stat-card present">
                    <span class="stat-number"><?php echo $stats['present']; ?></span>
                    <span class="stat-label">Present</span>
                    <div class="stat-description">Confirmed attendance</div>
                </div>
                <div class="stat-card confirmed">
                    <span class="stat-number"><?php echo $stats['confirmed']; ?></span>
                    <span class="stat-label">Confirmed</span>
                    <div class="stat-description">Registration confirmed</div>
                </div>
                <div class="stat-card cancelled">
                    <span class="stat-number"><?php echo $stats['cancelled']; ?></span>
                    <span class="stat-label">Cancelled</span>
                    <div class="stat-description">Cancelled registrations</div>
                </div>
            </div>

            <!-- Table Section -->
            <div class="table-section">
                <div class="table-header">
                    <h2 class="table-title">
                        <i class="fas fa-users"></i>
                        Donor Records (<?php echo $total_records; ?> found)
                    </h2>
                    <div class="table-controls">
                        <button class="table-control-btn" onclick="exportData()">
                            <i class="fas fa-download"></i> Export
                        </button>
                    </div>
                </div>

                <?php if (empty($donations)): ?>
                    <div class="no-data">
                        <i class="fas fa-users"></i>
                        <h3>No Donors Found</h3>
                        <p>No donor records match your search criteria.</p>
                        <?php if (!empty($search) || !empty($event_date) || !empty($registration_status) || !empty($attendance_filter)): ?>
                            <a href="staff_view_donation.php" class="action-btn primary" style="margin-top: 20px;">
                                <i class="fas fa-times"></i> Clear Filters
                            </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <table class="simple-table">
                        <thead>
                            <tr>
                                <th>Donor Information</th>
                                <th>Contact Details</th>
                                <th>Registration Status</th>
                                <th>Attendance Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($donations as $donation): ?>
                                <?php 
                                $canConfirm = canConfirmAttendance($donation);
                                $attendanceStatus = strtolower(trim($donation['attendance_status'] ?? ''));
                                $rowClass = '';
                                if ($canConfirm) {
                                    $rowClass = 'ready';
                                }
                                ?>
                                <tr class="donor-row <?php echo $rowClass; ?>" data-can-confirm="<?php echo $canConfirm ? 'true' : 'false'; ?>">
                                    <td>
                                        <div class="donor-info">
                                            <div class="donor-name"><?php echo htmlspecialchars($donation['full_name']); ?></div>
                                            <div class="donor-details">
                                                Student ID: <?php echo htmlspecialchars($donation['student_id'] ?? 'N/A'); ?><br>
                                                IC: <?php echo htmlspecialchars($donation['RegistrationIC'] ?? 'N/A'); ?><br>
                                                <?php echo htmlspecialchars($donation['gender']); ?>, <?php echo $donation['age']; ?> years old
                                            </div>
                                            <div class="donor-meta">
                                                <?php if (!empty($donation['blood_type'])): ?>
                                                    <span class="donor-tag blood-type"><?php echo htmlspecialchars($donation['blood_type']); ?></span>
                                                <?php endif; ?>
                                                <span class="donor-tag">Event: <?php echo htmlspecialchars($donation['EventID'] ?? 'N/A'); ?></span>
                                                <?php if (!empty($donation['weight'])): ?>
                                                    <span class="donor-tag">Weight: <?php echo htmlspecialchars($donation['weight']); ?>kg</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="contact-info">
                                            <div class="contact-item">
                                                <i class="fas fa-envelope"></i>
                                                <?php echo htmlspecialchars($donation['email']); ?>
                                            </div>
                                            <div class="contact-item">
                                                <i class="fas fa-phone"></i>
                                                <?php echo htmlspecialchars($donation['phone']); ?>
                                            </div>
                                            <?php if (!empty($donation['RegistrationAddress'])): ?>
                                                <div class="contact-item">
                                                    <i class="fas fa-map-marker-alt"></i>
                                                    <small><?php echo htmlspecialchars(substr($donation['RegistrationAddress'], 0, 40)) . (strlen($donation['RegistrationAddress']) > 40 ? '...' : ''); ?></small>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="status-info">
                                            <span class="status-badge <?php echo getStatusBadge($donation['registration_status']); ?>">
                                                <?php echo getDisplayStatus($donation['registration_status']); ?>
                                            </span>
                                            <div class="status-date">
        Event: <?php echo formatDate($donation['event_date']); ?>
    </div>
    <div class="event-info" style="font-size: 11px; color: #666; margin-top: 2px;">
        <?php echo htmlspecialchars($donation['event_name']); ?>
        <?php if (!empty($donation['event_location']) && $donation['event_location'] !== 'TBD'): ?>
            <br><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($donation['event_location']); ?>
        <?php endif; ?>
        <?php if (!empty($donation['event_day'])): ?>
            <br><i class="fas fa-calendar"></i> <?php echo htmlspecialchars($donation['event_day']); ?>
        <?php endif; ?>
    </div>
    <div class="registration-date" style="font-size: 10px; color: #999; margin-top: 2px;">
        Registered: <?php echo formatDate($donation['registration_date']); ?>
    </div>
    <?php if (!empty($donation['CancellationReason'])): ?>
        <div style="color: #e74c3c; font-size: 11px; margin-top: 4px;">
            <i class="fas fa-info-circle"></i>
            <?php echo htmlspecialchars(substr($donation['CancellationReason'], 0, 30)) . (strlen($donation['CancellationReason']) > 30 ? '...' : ''); ?>
        </div>
    <?php endif; ?>
</div>
                                    </td>
                                    <td>
                                        <div class="status-info">
                                            <span class="status-badge <?php echo getStatusBadge($donation['attendance_status']); ?>">
                                                <?php echo getDisplayStatus($donation['attendance_status']); ?>
                                            </span>
                                            <?php if (!empty($donation['existing_donation_id'])): ?>
                                                <div class="status-date">
                                                    Donated: <?php echo formatDate($donation['donation_date']); ?>
                                                    <br>ID: #<?php echo $donation['existing_donation_id']; ?>
                                                    <?php if (!empty($donation['donation_status'])): ?>
                                                        <br>Status: <?php echo ucfirst($donation['donation_status']); ?>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="action-section">
                                            <?php if ($canConfirm): ?>
                                                <a href="confirm_attendance.php?id=<?php echo $donation['donation_id']; ?>" 
                                                   class="action-btn success priority"
                                                   title="Click to review and confirm attendance">
                                                    <i class="fas fa-user-check"></i> Confirm Attendance
                                                    <div class="priority-indicator">!</div>
                                                </a>
                                            <?php elseif (in_array($attendanceStatus, ['present', 'attended', 'confirmed']) && empty($donation['existing_donation_id'])): ?>
                                                <!-- Attendance confirmed, ready for donation process -->
                                                <a href="donation_process.php?id=<?php echo $donation['donation_id']; ?>" 
                                                   class="action-btn success priority"
                                                   title="Start the donation process">
                                                    <i class="fas fa-tint"></i> Start Donation Process
                                                    <div class="priority-indicator">!</div>
                                                </a>
                                            <?php elseif (in_array($attendanceStatus, ['present', 'attended', 'confirmed']) && !empty($donation['existing_donation_id']) && $donation['donation_status'] === 'pending'): ?>
                                                <!-- Attendance confirmed, donation record exists but pending -->
                                                <a href="donation_process.php?id=<?php echo $donation['donation_id']; ?>" 
                                                   class="action-btn success priority"
                                                   title="Complete the donation process">
                                                    <i class="fas fa-tint"></i> Complete Donation
                                                    <div class="priority-indicator">!</div>
                                                </a>
                                            <?php elseif (!empty($donation['existing_donation_id'])): ?>
                                                <!-- Donation completed or in progress -->
                                                <?php if ($donation['donation_status'] === 'completed'): ?>
                                                    <span class="action-btn disabled" title="Donation completed successfully">
                                                        <i class="fas fa-check-circle"></i> Donation Complete
                                                    </span>
                                                <?php else: ?>
                                                    <a href="donation_process.php?id=<?php echo $donation['donation_id']; ?>" 
                                                       class="action-btn warning"
                                                       title="Continue donation process">
                                                        <i class="fas fa-edit"></i> Continue Process
                                                    </a>
                                                <?php endif; ?>
                                                <div class="action-reason">Donation ID: #<?php echo $donation['existing_donation_id']; ?></div>
                                            <?php else: ?>
                                                <span class="action-btn disabled" title="<?php echo getActionReasonText($donation); ?>">
                                                    <i class="fas fa-times-circle"></i> Cannot Confirm
                                                </span>
                                                <div class="action-reason"><?php echo getActionReasonText($donation); ?></div>
                                            <?php endif; ?>

                                            <!-- FIXED: Use student_id from the donation array -->
                                            <a href="view_donor_details.php?id=<?php echo urlencode($donation['student_id']); ?>" 
                                                class="action-btn secondary" 
                                                title="View complete donor information and history">
                                                <i class="fas fa-eye"></i> View Details
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <?php
                            $query_params = $_GET;
                            
                            // Previous page
                            if ($page > 1):
                                $query_params['page'] = $page - 1;
                            ?>
                                <a href="?<?php echo http_build_query($query_params); ?>">
                                    <i class="fas fa-chevron-left"></i> Previous
                                </a>
                            <?php endif; ?>

                            <?php
                            // Page numbers
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            
                            for ($i = $start_page; $i <= $end_page; $i++):
                                $query_params['page'] = $i;
                                if ($i == $page):
                            ?>
                                    <span class="current"><?php echo $i; ?></span>
                                <?php else: ?>
                                    <a href="?<?php echo http_build_query($query_params); ?>"><?php echo $i; ?></a>
                                <?php endif;
                            endfor; ?>

                            <?php
                            // Next page
                            if ($page < $total_pages):
                                $query_params['page'] = $page + 1;
                            ?>
                                <a href="?<?php echo http_build_query($query_params); ?>">
                                    Next <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Help Section -->
            <div class="help-section">
                <h3>
                    <i class="fas fa-question-circle"></i>
                    How to Confirm Attendance
                </h3>
                <div class="help-grid">
                    <div class="help-item ready">
                        <h4>✅ Ready to Confirm</h4>
                        <ul>
                            <li>Registration status is "Registered" or "Confirmed"</li>
                            <li>Attendance is still "Pending"</li>
                            <li>Event is today or in the future</li>
                            <li>No existing donation record</li>
                        </ul>
                    </div>
                    <div class="help-item cannot">
                        <h4>❌ Cannot Confirm</h4>
                        <ul>
                            <li>Registration was cancelled or rejected</li>
                            <li>Attendance already confirmed</li>
                            <li>Event date has passed</li>
                            <li>Donation already recorded</li>
                        </ul>
                    </div>
                    <div class="help-item actions">
                        <h4>🔧 Quick Actions</h4>
                        <ul>
                            <li>Click "Confirm Attendance" for eligible donors</li>
                            <li>Filter by "Pending Attendance" to see who needs confirmation</li>
                            <li>Use today's date filter to see current events</li>
                            <li>Start donation process after attendance is confirmed</li>
                        </ul>
                    </div>
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

        // Auto-submit for filters
        document.querySelector('select[name="event_date"]')?.addEventListener('change', function() {
            this.form.submit();
        });

        document.querySelector('select[name="registration_status"]')?.addEventListener('change', function() {
            this.form.submit();
        });

        document.querySelector('select[name="attendance_filter"]')?.addEventListener('change', function() {
            this.form.submit();
        });

        // Focus search input with Ctrl+F
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                e.preventDefault();
                document.querySelector('input[name="search"]').focus();
            }
        });

        // Row highlighting functionality
        let highlightEnabled = false;
        function toggleRowHighlight() {
            highlightEnabled = !highlightEnabled;
            const btn = event.target.closest('.table-control-btn');
            const rows = document.querySelectorAll('.donor-row');
            
            if (highlightEnabled) {
                btn.classList.add('active');
                btn.innerHTML = '<i class="fas fa-highlighter"></i> Remove Highlight';
                
                rows.forEach(row => {
                    if (row.dataset.canConfirm === 'true') {
                        row.classList.add('row-highlight', 'ready');
                    }
                });
            } else {
                btn.classList.remove('active');
                btn.innerHTML = '<i class="fas fa-highlighter"></i> Highlight Ready';
                
                rows.forEach(row => {
                    row.classList.remove('row-highlight', 'ready');
                });
            }
        }

        // Export functionality
        function exportData() {
            const currentParams = new URLSearchParams(window.location.search);
            currentParams.set('export', 'csv');
            window.location.href = '?' + currentParams.toString();
        }

        // Enhanced initialization
        document.addEventListener('DOMContentLoaded', function() {
            const readyCount = document.querySelectorAll('[data-can-confirm="true"]').length;
            
            if (readyCount > 0) {
                console.log(`🩸 Found ${readyCount} donors ready for attendance confirmation`);
            }

            // Add entrance animations
            const elements = document.querySelectorAll('.page-header, .search-section, .stats-grid, .table-section');
            elements.forEach((el, index) => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    el.style.transition = 'all 0.6s ease';
                    el.style.opacity = '1';
                    el.style.transform = 'translateY(0)';
                }, index * 200);
            });

            console.log('✅ Staff Donor Management System Loaded Successfully');
            console.log('📊 Current Statistics:', {
                total: <?php echo $stats['total']; ?>,
                pending: <?php echo $stats['attendance_pending']; ?>,
                registered: <?php echo $stats['registered'] ?? 0; ?>,
                present: <?php echo $stats['present']; ?>,
                confirmed: <?php echo $stats['confirmed']; ?>,
                cancelled: <?php echo $stats['cancelled']; ?>
            });
        });

        // Statistics click handlers for quick filtering
        document.addEventListener('DOMContentLoaded', function() {
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach(card => {
                card.addEventListener('click', function() {
                    const cardClass = Array.from(this.classList).find(cls => 
                        ['pending', 'registered', 'confirmed', 'cancelled', 'present', 'absent'].includes(cls)
                    );
                    
                    if (cardClass) {
                        let filterParam = '';
                        let filterValue = '';
                        
                        if (['present', 'absent', 'pending'].includes(cardClass)) {
                            filterParam = 'attendance_filter';
                            filterValue = cardClass === 'pending' ? 'pending' : cardClass;
                        } else {
                            filterParam = 'registration_status';
                            filterValue = cardClass;
                        }
                        
                        const url = new URL(window.location);
                        url.searchParams.set(filterParam, filterValue);
                        window.location.href = url.toString();
                    }
                });
            });
        });

        // Real-time search (debounced)
        let searchTimeout;
        const searchInput = document.querySelector('input[name="search"]');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    if (this.value.length >= 3 || this.value.length === 0) {
                        this.form.submit();
                    }
                }, 500);
            });
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+H to toggle highlight
            if ((e.ctrlKey || e.metaKey) && e.key === 'h') {
                e.preventDefault();
                toggleRowHighlight();
            }
            
            // Ctrl+E to export
            if ((e.ctrlKey || e.metaKey) && e.key === 'e') {
                e.preventDefault();
                exportData();
            }
            
            // Escape to clear search
            if (e.key === 'Escape') {
                const searchInput = document.querySelector('input[name="search"]');
                if (searchInput && searchInput === document.activeElement) {
                    searchInput.value = '';
                } else if (window.location.search) {
                    window.location.href = 'staff_view_donation.php';
                }
            }
        });

        // Add loading states to buttons
        document.querySelectorAll('.action-btn:not(.disabled)').forEach(btn => {
            btn.addEventListener('click', function(e) {
                if ((this.classList.contains('success') || this.classList.contains('warning')) && this.href) {
                    // Add loading state for attendance confirmation
                    const originalText = this.innerHTML;
                    this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
                    this.style.pointerEvents = 'none';
                    
                    // Restore if user navigates back
                    setTimeout(() => {
                        this.innerHTML = originalText;
                        this.style.pointerEvents = 'auto';
                    }, 3000);
                }
            });
        });

        // Add progress indication for form submissions
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function() {
                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn) {
                    const originalText = submitBtn.innerHTML;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Searching...';
                    submitBtn.disabled = true;
                    
                    // Re-enable after delay to prevent multiple submissions
                    setTimeout(() => {
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }, 2000);
                }
            });
        });
    </script>
</body>
</html>