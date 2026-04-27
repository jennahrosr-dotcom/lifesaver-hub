<?php
session_start();

// Set timezone to Malaysia
date_default_timezone_set('Asia/Kuala_Lumpur');

// Check if user is logged in as staff
if (!isset($_SESSION['staff_id']) || empty($_SESSION['staff_id'])) {
    header("Location: staff_login.php");
    exit();
}

// Database connection
try {
    require 'db.php';
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Get staff information
try {
    $stmt = $pdo->prepare("SELECT * FROM staff WHERE StaffID = ?");
    $stmt->execute([$_SESSION['staff_id']]);
    $staff = $stmt->fetch();
    
    if (!$staff) {
        header("Location: staff_login.php");
        exit();
    }
} catch (Exception $e) {
    die("Error fetching staff data: " . $e->getMessage());
}

// Initialize variables
$reportType = $_POST['report_type'] ?? '';
$selectedEventId = $_POST['event_id'] ?? '';
$reportData = [];
$reportGenerated = false;
$reportTitle = '';
$reportSummary = [];
$errorMessage = '';
$successMessage = '';

// Process report generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($reportType)) {
    try {
        switch ($reportType) {
            case 'event_summary':
                $reportTitle = 'Event Summary Report';
                $reportData = generateEventSummary($pdo, $selectedEventId);
                break;
                
            case 'registration_list':
                $reportTitle = 'Event Registration List';
                $reportData = generateRegistrationList($pdo, $selectedEventId);
                break;
                
            case 'donation_records':
                $reportTitle = 'Blood Donation Records';
                $reportData = generateDonationRecords($pdo, $selectedEventId);
                break;
                
            case 'attendance_report':
                $reportTitle = 'Student Attendance Report';
                $reportData = generateAttendanceReport($pdo, $selectedEventId);
                break;
                
            default:
                throw new Exception("Invalid report type selected");
        }
        
        $reportGenerated = true;
        $reportSummary = generateReportSummary($reportData, $reportType);
        
        if (!empty($reportData)) {
            $successMessage = "Report generated successfully with " . count($reportData) . " records found.";
        }
        
    } catch (Exception $e) {
        $reportGenerated = false;
        $errorMessage = "Error generating report: " . $e->getMessage();
        error_log($errorMessage);
    }
}

// Function to generate event summary
function generateEventSummary($pdo, $eventId) {
    $sql = "
        SELECT 
            e.EventID,
            e.EventTitle,
            e.EventDescription,
            e.EventDate,
            e.EventDay,
            e.EventVenue,
            e.EventStatus,
            COUNT(DISTINCT r.RegistrationID) as total_registrations,
            COUNT(DISTINCT CASE WHEN r.AttendanceStatus = 'present' THEN r.RegistrationID END) as present_count,
            COUNT(DISTINCT d.DonationID) as total_donations,
            SUM(d.DonationQuantity) as total_volume,
            COUNT(DISTINCT d.DonationBloodType) as blood_types_count,
            AVG(d.DonationQuantity) as avg_donation_volume
        FROM event e
        LEFT JOIN registration r ON e.EventID = r.EventID
        LEFT JOIN donation d ON r.RegistrationID = d.RegistrationID AND d.DonationStatus = 'completed'
        WHERE 1=1
    ";
    
    $params = [];
    if (!empty($eventId)) {
        $sql .= " AND e.EventID = ?";
        $params[] = $eventId;
    }
    
    $sql .= " GROUP BY e.EventID ORDER BY e.EventDate DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Function to generate registration list
function generateRegistrationList($pdo, $eventId) {
    $sql = "
        SELECT 
            r.RegistrationID,
            r.RegistrationDate,
            r.RegistrationStatus,
            r.AttendanceStatus,
            s.StudentID,
            s.StudentName,
            s.StudentEmail,
            s.StudentContact,
            s.StudentGender,
            s.StudentAge,
            e.EventTitle,
            e.EventDate,
            e.EventVenue,
            CASE WHEN d.DonationID IS NOT NULL THEN 'Yes' ELSE 'No' END as HasDonated
        FROM registration r
        INNER JOIN student s ON r.StudentID = s.StudentID
        INNER JOIN event e ON r.EventID = e.EventID
        LEFT JOIN donation d ON r.RegistrationID = d.RegistrationID
        WHERE 1=1
    ";
    
    $params = [];
    if (!empty($eventId)) {
        $sql .= " AND e.EventID = ?";
        $params[] = $eventId;
    }
    
    $sql .= " ORDER BY r.RegistrationDate DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Function to generate donation records
function generateDonationRecords($pdo, $eventId) {
    $sql = "
        SELECT 
            d.DonationID,
            d.DonationDate,
            d.DonationBloodType,
            d.DonationQuantity,
            d.Weight,
            d.BloodPressure,
            d.Temperature,
            d.PulseRate,
            d.HemoglobinLevel,
            d.PlateletCount,
            d.DonationStatus,
            s.StudentID,
            s.StudentName,
            s.StudentEmail,
            s.StudentContact,
            s.StudentGender,
            s.StudentAge,
            e.EventTitle,
            e.EventDate,
            e.EventVenue
        FROM donation d
        INNER JOIN registration r ON d.RegistrationID = r.RegistrationID
        INNER JOIN student s ON r.StudentID = s.StudentID
        INNER JOIN event e ON r.EventID = e.EventID
        WHERE d.DonationStatus IS NOT NULL
    ";
    
    $params = [];
    if (!empty($eventId)) {
        $sql .= " AND e.EventID = ?";
        $params[] = $eventId;
    }
    
    $sql .= " ORDER BY d.DonationDate DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Function to generate attendance report
function generateAttendanceReport($pdo, $eventId) {
    $sql = "
        SELECT 
            r.RegistrationID,
            r.RegistrationDate,
            r.AttendanceStatus,
            s.StudentID,
            s.StudentName,
            s.StudentEmail,
            s.StudentContact,
            s.StudentGender,
            s.StudentAge,
            e.EventTitle,
            e.EventDate,
            e.EventVenue,
            CASE WHEN d.DonationID IS NOT NULL THEN 'Donated' ELSE 'Not Donated' END as DonationStatus
        FROM registration r
        INNER JOIN student s ON r.StudentID = s.StudentID
        INNER JOIN event e ON r.EventID = e.EventID
        LEFT JOIN donation d ON r.RegistrationID = d.RegistrationID AND d.DonationStatus = 'completed'
        WHERE 1=1
    ";
    
    $params = [];
    if (!empty($eventId)) {
        $sql .= " AND e.EventID = ?";
        $params[] = $eventId;
    }
    
    $sql .= " ORDER BY r.AttendanceStatus DESC, s.StudentName ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Function to generate report summary
function generateReportSummary($data, $reportType) {
    $summary = ['total_records' => count($data)];
    
    if (empty($data)) return $summary;
    
    switch ($reportType) {
        case 'event_summary':
            $summary['total_events'] = count($data);
            $summary['total_registrations'] = array_sum(array_column($data, 'total_registrations'));
            $summary['total_donations'] = array_sum(array_column($data, 'total_donations'));
            $summary['total_volume'] = array_sum(array_column($data, 'total_volume'));
            break;
            
        case 'registration_list':
            $summary['total_registrations'] = count($data);
            $summary['present_count'] = count(array_filter($data, fn($d) => $d['AttendanceStatus'] === 'present'));
            $summary['donated_count'] = count(array_filter($data, fn($d) => $d['HasDonated'] === 'Yes'));
            $summary['attendance_rate'] = $summary['total_registrations'] > 0 ? 
                round(($summary['present_count'] / $summary['total_registrations']) * 100, 1) : 0;
            break;
            
        case 'donation_records':
            $summary['total_donations'] = count($data);
            $summary['total_volume'] = array_sum(array_column($data, 'DonationQuantity'));
            $summary['completed_donations'] = count(array_filter($data, fn($d) => $d['DonationStatus'] === 'completed'));
            $summary['unique_blood_types'] = count(array_unique(array_column($data, 'DonationBloodType')));
            break;
            
        case 'attendance_report':
            $summary['total_registrations'] = count($data);
            $summary['present_count'] = count(array_filter($data, fn($d) => $d['AttendanceStatus'] === 'present'));
            $summary['donated_count'] = count(array_filter($data, fn($d) => $d['DonationStatus'] === 'Donated'));
            $summary['attendance_rate'] = $summary['total_registrations'] > 0 ? 
                round(($summary['present_count'] / $summary['total_registrations']) * 100, 1) : 0;
            break;
    }
    
    return $summary;
}

// Get available events for dropdown
$availableEvents = [];
try {
    $stmt = $pdo->query("
        SELECT 
            e.EventID,
            e.EventTitle,
            e.EventDate,
            e.EventVenue,
            e.EventStatus,
            COUNT(r.RegistrationID) as registration_count
        FROM event e 
        LEFT JOIN registration r ON e.EventID = r.EventID 
        GROUP BY e.EventID 
        ORDER BY e.EventDate DESC
    ");
    $availableEvents = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Error fetching events: " . $e->getMessage());
}

// Get quick statistics
$quickStats = [];
try {
    $today = date('Y-m-d');
    
    // Today's donations
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM donation WHERE DATE(DonationDate) = ? AND DonationStatus = 'completed'");
    $stmt->execute([$today]);
    $quickStats['today_donations'] = $stmt->fetchColumn();
    
    // Total events
    $stmt = $pdo->query("SELECT COUNT(*) FROM event");
    $quickStats['total_events'] = $stmt->fetchColumn();
    
    // Total registrations
    $stmt = $pdo->query("SELECT COUNT(*) FROM registration");
    $quickStats['total_registrations'] = $stmt->fetchColumn();
    
    // Total completed donations
    $stmt = $pdo->query("SELECT COUNT(*) FROM donation WHERE DonationStatus = 'completed'");
    $quickStats['total_donations'] = $stmt->fetchColumn();
    
} catch (Exception $e) {
    $quickStats = [
        'today_donations' => 0,
        'total_events' => 0,
        'total_registrations' => 0,
        'total_donations' => 0
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate Reports - LifeSaver Hub</title>
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

        /* Enhanced Sidebar - SAME AS STAFF_ACCOUNT */
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

        /* Page Header */
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

        .staff-info {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(139, 92, 246, 0.1));
            color: #667eea;
            padding: 12px 24px;
            border-radius: 20px;
            font-size: 16px;
            font-weight: 600;
            border: 2px solid rgba(102, 126, 234, 0.2);
            display: inline-flex;
            align-items: center;
            gap: 20px;
        }

        /* Content Cards */
        .content-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 24px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.08);
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

        /* Alert Messages */
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            border-left: 4px solid;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
        }

        .alert.success {
            background: #f0fff4;
            border-left-color: #38a169;
            color: #2f855a;
        }

        .alert.error {
            background: #fed7d7;
            border-left-color: #e53e3e;
            color: #c53030;
        }

        .alert.info {
            background: #ebf8ff;
            border-left-color: #3182ce;
            color: #2c5282;
        }

        /* Form Styles */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr auto;
            gap: 20px;
            align-items: end;
            margin-bottom: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-label {
            color: #2d3748;
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .form-control {
            padding: 12px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            background: white;
            transition: all 0.2s ease;
            color: #2d3748;
        }

        .form-control:focus {
            outline: none;
            border-color: #4299e1;
            box-shadow: 0 0 0 3px rgba(66, 153, 225, 0.1);
        }

        .form-help {
            color: #718096;
            font-size: 12px;
            margin-top: 4px;
        }

        /* Button Styles */
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            justify-content: center;
        }

        .btn-primary {
            background: #4299e1;
            color: white;
            box-shadow: 0 2px 8px rgba(66, 153, 225, 0.2);
        }

        .btn-primary:hover {
            background: #3182ce;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(66, 153, 225, 0.3);
        }

        .btn-success {
            background: #38a169;
            color: white;
            box-shadow: 0 2px 8px rgba(56, 161, 105, 0.2);
        }

        .btn-success:hover {
            background: #2f855a;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(56, 161, 105, 0.3);
        }

        /* Quick Stats */
        .quick-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
        }

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

        .stat-number {
            font-size: 2.2rem;
            font-weight: 900;
            margin-bottom: 8px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .stat-label {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
            opacity: 0.9;
            font-weight: 600;
        }

        /* Report Section */
        .report-section {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 40px;
            margin-bottom: 32px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(0, 0, 0, 0.05);
            position: relative;
            overflow: hidden;
        }

        .report-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea, #764ba2);
            border-radius: 24px 24px 0 0;
        }

        .report-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 32px;
            padding-bottom: 24px;
            border-bottom: 2px solid rgba(102, 126, 234, 0.1);
        }

        .report-title {
            font-size: 1.8rem;
            font-weight: 800;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 16px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .report-meta {
            text-align: right;
            color: #4a5568;
            font-size: 14px;
            font-weight: 500;
            line-height: 1.6;
        }

        /* Summary Grid */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }

        .summary-card {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 24px;
            border-radius: 16px;
            text-align: center;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .summary-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 35px rgba(102, 126, 234, 0.4);
        }

        .summary-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            transition: left 0.8s;
        }

        .summary-card:hover::before {
            left: 100%;
        }

        .summary-card .number {
            font-size: 2.2rem;
            font-weight: 900;
            margin-bottom: 8px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .summary-card .label {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
            opacity: 0.9;
            font-weight: 600;
        });
            box-shadow: 0 4px 15px rgba(66, 153, 225, 0.3);
        }

        .summary-card .number {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .summary-card .label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            opacity: 0.9;
        }

        /* Data Table */
        .table-container {
            overflow-x: auto;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        .data-table th {
            background: #f7fafc;
            color: #2d3748;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid #e2e8f0;
        }

        .data-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 14px;
            color: #2d3748;
        }

        .data-table tr:hover {
            background: #f7fafc;
        }

        .data-table tr:last-child td {
            border-bottom: none;
        }

        /* Status Badges */
        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-completed, .status-present { 
            background: #c6f6d5; 
            color: #2f855a; 
        }

        .status-pending { 
            background: #fef5e7; 
            color: #d69e2e; 
        }

        .status-absent { 
            background: #fed7d7; 
            color: #c53030; 
        }

        /* No Data State */
        .no-data {
            text-align: center;
            padding: 40px;
            color: #718096;
        }

        .no-data i {
            font-size: 3rem;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        .no-data h3 {
            color: #2d3748;
            margin-bottom: 8px;
            font-weight: 600;
        }

        /* Mobile Responsive */
        .mobile-menu-btn {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 15000;
            background: #4299e1;
            border: none;
            color: white;
            padding: 12px;
            border-radius: 8px;
            cursor: pointer;
        }

        @media (max-width: 968px) {
            .mobile-menu-btn {
                display: block;
            }
            
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
                z-index: 20000;
            }
            
            .sidebar.open {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
                padding: 20px 16px;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .quick-stats {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .summary-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .report-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
        }

        @media (max-width: 480px) {
            .quick-stats,
            .summary-grid {
                grid-template-columns: 1fr;
            }
            
            .page-header,
            .content-card,
            .report-section {
                padding: 16px;
            }
        }

        /* Print Styles */
        @media print {
            .sidebar,
            .mobile-menu-btn,
            .quick-stats {
                display: none !important;
            }

            .main-content {
                margin-left: 0;
                padding: 20px;
            }

            .report-section {
                box-shadow: none;
                border: 1px solid #ccc;
            }

            .data-table th {
                background: #f5f5f5 !important;
            }
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
                        <a href="create_reward.php" class="nav-item">
                            <i class="fas fa-gift"></i>
                            <span>Rewards</span>
                        </a>
                        <a href="generate_report.php" class="nav-item active">
                            <i class="fas fa-chart-line"></i>
                            <span>Reports</span>
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
                <h1>
                    <i class="fas fa-chart-line"></i>
                    Generate Reports
                </h1>
                <p>Create comprehensive reports for blood donation events and activities</p>
                <div class="staff-info">
                    <span>
                        <i class="fas fa-user-tie"></i>
                        <?= htmlspecialchars($staff['StaffName']) ?>
                    </span>
                    <span>
                        <i class="fas fa-calendar"></i>
                        <?= date('F j, Y - g:i A') ?>
                    </span>
                </div>
            </div>

            <!-- Quick Statistics -->
            <div class="quick-stats">
                <div class="stat-card">
                    <div class="stat-number"><?= number_format($quickStats['today_donations']) ?></div>
                    <div class="stat-label">Today's Donations</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= number_format($quickStats['total_events']) ?></div>
                    <div class="stat-label">Total Events</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= number_format($quickStats['total_registrations']) ?></div>
                    <div class="stat-label">Total Registrations</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= number_format($quickStats['total_donations']) ?></div>
                    <div class="stat-label">Total Donations</div>
                </div>
            </div>

            <!-- Alert Messages -->
            <?php if (!empty($successMessage)): ?>
            <div class="alert success">
                <i class="fas fa-check-circle"></i>
                <?= htmlspecialchars($successMessage) ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($errorMessage)): ?>
            <div class="alert error">
                <i class="fas fa-exclamation-triangle"></i>
                <?= htmlspecialchars($errorMessage) ?>
            </div>
            <?php endif; ?>

            <!-- Report Generator Form -->
            <div class="content-card">
                <h3>
                    <i class="fas fa-cog"></i>
                    Report Generator
                </h3>
                
                <div class="alert info">
                    <i class="fas fa-info-circle"></i>
                    <div>
                        <strong>Available Report Types:</strong><br>
                        <small>
                            • <strong>Event Summary:</strong> Overview of events with registration and donation statistics<br>
                            • <strong>Registration List:</strong> Complete list of student registrations for events<br>
                            • <strong>Donation Records:</strong> Detailed blood donation records with medical data<br>
                            • <strong>Attendance Report:</strong> Student attendance tracking and donation status
                        </small>
                    </div>
                </div>
                
                <form method="POST" id="reportForm">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="report_type" class="form-label">Report Type</label>
                            <select name="report_type" id="report_type" class="form-control" required>
                                <option value="">Select Report Type</option>
                                <option value="event_summary" <?= $reportType === 'event_summary' ? 'selected' : '' ?>>
                                    📊 Event Summary Report
                                </option>
                                <option value="registration_list" <?= $reportType === 'registration_list' ? 'selected' : '' ?>>
                                    📝 Registration List
                                </option>
                                <option value="donation_records" <?= $reportType === 'donation_records' ? 'selected' : '' ?>>
                                    🩸 Donation Records
                                </option>
                                <option value="attendance_report" <?= $reportType === 'attendance_report' ? 'selected' : '' ?>>
                                    👥 Attendance Report
                                </option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="event_id" class="form-label">Select Event</label>
                            <select name="event_id" id="event_id" class="form-control">
                                <option value="">All Events</option>
                                <?php foreach ($availableEvents as $event): ?>
                                    <option value="<?= $event['EventID'] ?>" 
                                            <?= $selectedEventId == $event['EventID'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($event['EventTitle']) ?> - 
                                        <?= date('M j, Y', strtotime($event['EventDate'])) ?>
                                        (<?= $event['registration_count'] ?> registrations)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-help"> </small>
                        </div>

                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-play"></i>
                                Generate Report
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Report Results -->
            <?php if ($reportGenerated): ?>
            <div class="report-section">
                <div class="report-header">
                    <div class="report-title">
                        <i class="fas fa-file-alt"></i>
                        <?= htmlspecialchars($reportTitle) ?>
                    </div>
                    <div class="report-meta">
                        <div><strong>Generated:</strong> <?= date('F j, Y - g:i A') ?></div>
                        <div><strong>Staff:</strong> <?= htmlspecialchars($staff['StaffName']) ?></div>
                        <?php if ($selectedEventId): ?>
                            <?php 
                            $selectedEvent = array_filter($availableEvents, fn($e) => $e['EventID'] == $selectedEventId);
                            $selectedEvent = reset($selectedEvent);
                            ?>
                            <div><strong>Event:</strong> <?= htmlspecialchars($selectedEvent['EventTitle'] ?? 'Unknown') ?></div>
                        <?php else: ?>
                            <div><strong>Scope:</strong> All Events</div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($reportData)): ?>
                    <!-- Report Summary -->
                    <?php if (!empty($reportSummary)): ?>
                    <div class="summary-grid">
                        <?php foreach ($reportSummary as $key => $value): ?>
                            <div class="summary-card">
                                <div class="number"><?= is_numeric($value) ? number_format($value) : $value ?></div>
                                <div class="label"><?= ucwords(str_replace('_', ' ', $key)) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Export Actions -->
                    <div style="margin-bottom: 20px;">
                        <button onclick="window.print()" class="btn btn-success">
                            <i class="fas fa-print"></i>
                            Print Report
                        </button>
                    </div>

                    <!-- Report Data Table -->
                    <div class="table-container">
                        <?php if ($reportType === 'event_summary'): ?>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Event Title</th>
                                        <th>Date</th>
                                        <th>Venue</th>
                                        <th>Status</th>
                                        <th>Registrations</th>
                                        <th>Present</th>
                                        <th>Donations</th>
                                        <th>Total Volume (ml)</th>
                                        <th>Blood Types</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($reportData as $row): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($row['EventTitle']) ?></strong></td>
                                        <td><?= date('M j, Y', strtotime($row['EventDate'])) ?></td>
                                        <td><?= htmlspecialchars($row['EventVenue']) ?></td>
                                        <td>
                                            <span class="status-badge status-<?= strtolower($row['EventStatus']) ?>">
                                                <?= ucfirst($row['EventStatus']) ?>
                                            </span>
                                        </td>
                                        <td><?= number_format($row['total_registrations']) ?></td>
                                        <td><?= number_format($row['present_count']) ?></td>
                                        <td><?= number_format($row['total_donations']) ?></td>
                                        <td><?= number_format($row['total_volume'] ?? 0) ?></td>
                                        <td><?= $row['blood_types_count'] ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>

                        <?php elseif ($reportType === 'registration_list'): ?>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Registration ID</th>
                                        <th>Student Name</th>
                                        <th>Email</th>
                                        <th>Contact</th>
                                        <th>Gender</th>
                                        <th>Age</th>
                                        <th>Event</th>
                                        <th>Registration Date</th>
                                        <th>Attendance</th>
                                        <th>Donated</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($reportData as $row): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['RegistrationID']) ?></td>
                                        <td><strong><?= htmlspecialchars($row['StudentName']) ?></strong></td>
                                        <td><?= htmlspecialchars($row['StudentEmail']) ?></td>
                                        <td><?= htmlspecialchars($row['StudentContact']) ?></td>
                                        <td><?= ucfirst($row['StudentGender']) ?></td>
                                        <td><?= $row['StudentAge'] ?></td>
                                        <td><?= htmlspecialchars($row['EventTitle']) ?></td>
                                        <td><?= date('M j, Y', strtotime($row['RegistrationDate'])) ?></td>
                                        <td>
                                            <span class="status-badge status-<?= strtolower($row['AttendanceStatus']) ?>">
                                                <?= ucfirst($row['AttendanceStatus']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge <?= $row['HasDonated'] === 'Yes' ? 'status-completed' : 'status-pending' ?>">
                                                <?= $row['HasDonated'] ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>

                        <?php elseif ($reportType === 'donation_records'): ?>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Donation ID</th>
                                        <th>Student Name</th>
                                        <th>Event</th>
                                        <th>Donation Date</th>
                                        <th>Blood Type</th>
                                        <th>Quantity (ml)</th>
                                        <th>Weight (kg)</th>
                                        <th>Blood Pressure</th>
                                        <th>Temperature (°C)</th>
                                        <th>Pulse Rate</th>
                                        <th>Hemoglobin</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($reportData as $row): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['DonationID']) ?></td>
                                        <td><strong><?= htmlspecialchars($row['StudentName']) ?></strong></td>
                                        <td><?= htmlspecialchars($row['EventTitle']) ?></td>
                                        <td><?= date('M j, Y', strtotime($row['DonationDate'])) ?></td>
                                        <td><strong style="color: #e53e3e;"><?= htmlspecialchars($row['DonationBloodType']) ?></strong></td>
                                        <td><?= number_format($row['DonationQuantity']) ?></td>
                                        <td><?= $row['Weight'] ?? 'N/A' ?></td>
                                        <td><?= htmlspecialchars($row['BloodPressure'] ?? 'N/A') ?></td>
                                        <td><?= $row['Temperature'] ?? 'N/A' ?></td>
                                        <td><?= $row['PulseRate'] ?? 'N/A' ?></td>
                                        <td><?= $row['HemoglobinLevel'] ?? 'N/A' ?></td>
                                        <td>
                                            <span class="status-badge status-<?= strtolower($row['DonationStatus']) ?>">
                                                <?= ucfirst($row['DonationStatus']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>

                        <?php elseif ($reportType === 'attendance_report'): ?>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Registration ID</th>
                                        <th>Student Name</th>
                                        <th>Email</th>
                                        <th>Contact</th>
                                        <th>Gender</th>
                                        <th>Age</th>
                                        <th>Event</th>
                                        <th>Event Date</th>
                                        <th>Attendance</th>
                                        <th>Donation Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($reportData as $row): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['RegistrationID']) ?></td>
                                        <td><strong><?= htmlspecialchars($row['StudentName']) ?></strong></td>
                                        <td><?= htmlspecialchars($row['StudentEmail']) ?></td>
                                        <td><?= htmlspecialchars($row['StudentContact']) ?></td>
                                        <td><?= ucfirst($row['StudentGender']) ?></td>
                                        <td><?= $row['StudentAge'] ?></td>
                                        <td><?= htmlspecialchars($row['EventTitle']) ?></td>
                                        <td><?= date('M j, Y', strtotime($row['EventDate'])) ?></td>
                                        <td>
                                            <span class="status-badge status-<?= strtolower($row['AttendanceStatus']) ?>">
                                                <?= ucfirst($row['AttendanceStatus']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge <?= $row['DonationStatus'] === 'Donated' ? 'status-completed' : 'status-pending' ?>">
                                                <?= $row['DonationStatus'] ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>

                <?php else: ?>
                    <!-- No Data Found -->
                    <div class="no-data">
                        <i class="fas fa-search"></i>
                        <h3>No Data Found</h3>
                        <p>No records found for the selected criteria. Try selecting a different event or report type.</p>
                        
                        <?php if (!empty($availableEvents)): ?>
                        <div style="margin-top: 20px; padding: 16px; background: #f7fafc; border-radius: 8px; color: #2d3748;">
                            <strong>Available Events:</strong>
                            <ul style="margin: 8px 0 0 20px; text-align: left; display: inline-block;">
                                <?php foreach (array_slice($availableEvents, 0, 3) as $eventInfo): ?>
                                    <li><?= date('M j, Y', strtotime($eventInfo['EventDate'])) ?> - <?= htmlspecialchars($eventInfo['EventTitle']) ?></li>
                                <?php endforeach; ?>
                                <?php if (count($availableEvents) > 3): ?>
                                    <li><em>... and <?= count($availableEvents) - 3 ?> more events</em></li>
                                <?php endif; ?>
                            </ul>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
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

        // Form submission with loading state
        document.getElementById('reportForm').addEventListener('submit', function() {
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
            submitBtn.disabled = true;

            // Re-enable button after 10 seconds (fallback)
            setTimeout(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }, 10000);
        });

        // Enhanced form interactions
        document.addEventListener('DOMContentLoaded', function() {
            // Add entrance animations
            const elements = document.querySelectorAll('.content-card, .report-section, .quick-stats');
            elements.forEach((el, index) => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    el.style.transition = 'all 0.6s ease';
                    el.style.opacity = '1';
                    el.style.transform = 'translateY(0)';
                }, index * 100);
            });

            // Animate stat numbers
            const statNumbers = document.querySelectorAll('.stat-number');
            statNumbers.forEach(stat => {
                const finalValue = parseInt(stat.textContent.replace(/,/g, ''));
                if (!isNaN(finalValue) && finalValue > 0) {
                    let currentValue = 0;
                    const increment = Math.max(1, finalValue / 30);
                    
                    const timer = setInterval(() => {
                        currentValue += increment;
                        if (currentValue >= finalValue) {
                            stat.textContent = finalValue.toLocaleString();
                            clearInterval(timer);
                        } else {
                            stat.textContent = Math.floor(currentValue).toLocaleString();
                        }
                    }, 50);
                }
            });

            // Form field enhancements
            const reportTypeSelect = document.getElementById('report_type');
            const eventSelect = document.getElementById('event_id');
            
            reportTypeSelect.addEventListener('change', function() {
                if (this.value) {
                    this.style.borderColor = '#4299e1';
                    this.style.backgroundColor = '#f7fafc';
                }
            });

            eventSelect.addEventListener('change', function() {
                if (this.value) {
                    this.style.borderColor = '#4299e1';
                    this.style.backgroundColor = '#f7fafc';
                }
            });

            // Table row hover effects
            const tableRows = document.querySelectorAll('.data-table tbody tr');
            tableRows.forEach(row => {
                row.addEventListener('mouseenter', function() {
                    this.style.backgroundColor = '#f7fafc';
                    this.style.transform = 'scale(1.01)';
                });
                
                row.addEventListener('mouseleave', function() {
                    this.style.backgroundColor = '';
                    this.style.transform = 'scale(1)';
                });
            });

            console.log('📊 Report System Loaded Successfully');
            console.log('Available Events:', <?= count($availableEvents) ?>);
            console.log('Report Generated:', <?= $reportGenerated ? 'true' : 'false' ?>);
            console.log('Data Records:', <?= count($reportData) ?>);
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+P for print
            if ((e.ctrlKey || e.metaKey) && e.key === 'p' && document.querySelector('.report-section')) {
                e.preventDefault();
                window.print();
            }
            
            // Escape to close mobile menu
            if (e.key === 'Escape') {
                const sidebar = document.getElementById('sidebar');
                if (sidebar.classList.contains('open')) {
                    sidebar.classList.remove('open');
                }
            }
        });

        // Print optimization
        window.addEventListener('beforeprint', function() {
            document.body.classList.add('printing');
        });

        window.addEventListener('afterprint', function() {
            document.body.classList.remove('printing');
        });
    </script>
</body>
</html>