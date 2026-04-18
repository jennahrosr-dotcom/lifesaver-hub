<?php
session_start();

if (!isset($_SESSION['student_id'])) {
    header("Location: student_login.php");
    exit;
}

// Initialize ALL variables first to prevent undefined variable warnings
$registrationDetails = null;
$donationDetails = null;
$canModify = true;
$canCancel = false;
$student = null;

try {
    $pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // Get student information
    $stmt = $pdo->prepare("SELECT * FROM student WHERE StudentID = ?");
    $stmt->execute([$_SESSION['student_id']]);
    $student = $stmt->fetch();

    if (!$student) {
        header("Location: student_login.php");
        exit;
    }

    // Get registration details with health information and event details - FIXED QUERY
    $stmt = $pdo->prepare("
        SELECT r.*, h.HealthStatus, h.HealthDate, e.EventTitle, e.EventDate, e.EventVenue, e.EventStatus
        FROM registration r
        LEFT JOIN healthquestion h ON r.RegistrationID = h.RegistrationID
        LEFT JOIN event e ON r.EventID = e.EventID
        WHERE r.StudentID = ?
        ORDER BY r.RegistrationDate DESC
        LIMIT 1
    ");
    $stmt->execute([$_SESSION['student_id']]);
    $registrationDetails = $stmt->fetch();
    
    // Check if student can modify their application and cancel registration
    if ($registrationDetails) {
        $attendanceStatus = strtolower(trim($registrationDetails['AttendanceStatus'] ?? ''));
        $registrationStatus = strtolower(trim($registrationDetails['RegistrationStatus'] ?? ''));
        $eventDate = $registrationDetails['EventDate'] ?? null;
        $currentDate = date('Y-m-d');
        
        // Check if event date has passed
        $eventHasPassed = $eventDate && (strtotime($eventDate) < strtotime($currentDate));
        
        // Student cannot modify if:
        // 1. Attendance is marked as "present", "attended", or "confirmed"
        // 2. Event date has passed
        if (in_array($attendanceStatus, ['present', 'attended', 'confirmed']) || $eventHasPassed) {
            $canModify = false;
        }
        
        // Student can cancel if:
        // 1. Registration status is not 'Cancelled'
        // 2. Attendance status is not confirmed (present/attended/confirmed)
        // 3. Registration exists and is active
        // 4. Event date has NOT passed (NEW CONDITION)
        $canCancel = ($registrationStatus !== 'cancelled') && 
                     !in_array($attendanceStatus, ['present', 'attended', 'confirmed']) &&
                     !empty($registrationDetails['RegistrationID']) &&
                     !$eventHasPassed; // NEW: Prevent cancellation of past events
        
        // Get donation details with ALL fields from donation table - ENHANCED QUERY
        $stmt = $pdo->prepare("
            SELECT d.*, 
                   d.DonationDate,
                   d.DonationBloodType,
                   d.DonationQuantity,
                   d.Weight,
                   d.BloodPressure,
                   d.Temperature,
                   d.PulseRate,
                   d.HemoglobinLevel,
                   d.PlateletCount,
                   d.DonationStatus
            FROM donation d
            WHERE d.RegistrationID = ?
            ORDER BY d.DonationID DESC
            LIMIT 1
        ");
        $stmt->execute([$registrationDetails['RegistrationID']]);
        $donationDetails = $stmt->fetch();
        
        // If donation is completed, student cannot cancel
        if ($donationDetails && !empty($donationDetails['DonationID'])) {
            $canCancel = false;
        }
    }

} catch (Exception $e) {
    error_log("Database error in student_view_donation.php: " . $e->getMessage());
    // Set default values to prevent undefined variable errors
    $registrationDetails = null;
    $donationDetails = null;
    $student = ['StudentName' => 'Unknown Student'];
    $canCancel = false;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Donation Application - LifeSaver Hub</title>
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

        .nav-section-title {
            padding: 0 24px 8px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #667eea;
            position: relative;
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
            transition: all 0.3s ease;
        }

        .nav-item:hover i, .nav-item.active i {
            color: #667eea;
            transform: scale(1.1);
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
            border: 2px solid rgba(255, 255, 255, 0.9);
        }

        .user-details h4 {
            font-weight: 700;
            margin-bottom: 2px;
            color: #1a202c;
            font-size: 14px;
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

        .donations-container {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
        }

        .section-header {
            padding: 25px 30px;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.05), rgba(139, 92, 246, 0.05));
            border-bottom: 1px solid rgba(0, 0, 0, 0.08);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .section-header h3 {
            color: #2d3748;
            font-size: 1.4rem;
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .section-header i {
            color: #667eea;
        }

        .section-content {
            padding: 30px;
        }

        .info-card {
            background: rgba(248, 249, 250, 0.8);
            border-radius: 15px;
            padding: 20px;
            border-left: 4px solid #10ac84;
            transition: all 0.3s ease;
            margin-bottom: 20px;
        }

        .info-card:hover {
            background: rgba(248, 249, 250, 1);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        /* NEW: Medical info cards with different colors */
        .info-card.medical {
            border-left-color: #e74c3c;
            background: linear-gradient(135deg, rgba(231, 76, 60, 0.05), rgba(248, 249, 250, 0.8));
        }

        .info-card.vital-signs {
            border-left-color: #f39c12;
            background: linear-gradient(135deg, rgba(243, 156, 18, 0.05), rgba(248, 249, 250, 0.8));
        }

        .info-card.lab-results {
            border-left-color: #9b59b6;
            background: linear-gradient(135deg, rgba(155, 89, 182, 0.05), rgba(248, 249, 250, 0.8));
        }

        .info-label {
            color: #718096;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .info-value {
            color: #2d3748;
            font-size: 16px;
            font-weight: 600;
        }

        /* NEW: Styles for medical values */
        .info-value.large {
            font-size: 20px;
            font-weight: 700;
        }

        .info-value.with-unit {
            display: flex;
            align-items: baseline;
            gap: 5px;
        }

        .info-value .unit {
            font-size: 14px;
            font-weight: 500;
            color: #718096;
        }

        .personal-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
        }

        /* NEW: Medical info grid for organized display */
        .medical-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .info-card.full-width {
            grid-column: 1 / -1;
        }

        .health-status, .registration-status {
            padding: 4px 12px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-block;
        }

        .health-status.eligible {
            background: rgba(16, 172, 132, 0.2);
            color: #10ac84;
            border: 1px solid rgba(16, 172, 132, 0.3);
        }

        .health-status.not.eligible {
            background: rgba(245, 101, 101, 0.2);
            color: #f56565;
            border: 1px solid rgba(245, 101, 101, 0.3);
        }

        .registration-status.registered {
            background: rgba(16, 172, 132, 0.2);
            color: #10ac84;
            border: 1px solid rgba(16, 172, 132, 0.3);
        }

        .registration-status.cancelled {
            background: rgba(255, 107, 107, 0.2);
            color: #ff6b6b;
            border: 1px solid rgba(255, 107, 107, 0.3);
        }

        .registration-status.present {
            background: rgba(34, 197, 94, 0.2);
            color: #22c55e;
            border: 1px solid rgba(34, 197, 94, 0.3);
        }

        .registration-status.pending {
            background: rgba(254, 202, 87, 0.2);
            color: #feca57;
            border: 1px solid rgba(254, 202, 87, 0.3);
        }

        .registration-status.completed {
            background: rgba(34, 197, 94, 0.2);
            color: #22c55e;
            border: 1px solid rgba(34, 197, 94, 0.3);
        }

        .attendance-notice {
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.1), rgba(34, 197, 94, 0.05));
            border: 2px solid #22c55e;
            border-radius: 15px;
            padding: 20px;
            margin: 20px 0;
            color: #059669;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .attendance-notice i {
            font-size: 2rem;
            color: #22c55e;
        }

        .attendance-notice-content {
            flex: 1;
        }

        .attendance-notice h4 {
            color: #22c55e;
            margin-bottom: 8px;
            font-size: 1.1rem;
        }

        .attendance-notice p {
            color: #4a5568;
            font-size: 14px;
            line-height: 1.5;
        }

        .cancelled-notice {
            background: linear-gradient(135deg, rgba(255, 107, 107, 0.1), rgba(255, 107, 107, 0.05));
            border: 2px solid #ff6b6b;
            border-radius: 15px;
            padding: 20px;
            margin: 20px 0;
            color: #c92a2a;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .cancelled-notice i {
            font-size: 2rem;
            color: #ff6b6b;
        }

        .cancelled-notice-content {
            flex: 1;
        }

        .cancelled-notice h4 {
            color: #ff6b6b;
            margin-bottom: 8px;
            font-size: 1.1rem;
        }

        .cancelled-notice p {
            color: #4a5568;
            font-size: 14px;
            line-height: 1.5;
        }

        /* NEW: Past Event Notice */
        .past-event-notice {
            background: linear-gradient(135deg, rgba(108, 117, 125, 0.1), rgba(108, 117, 125, 0.05));
            border: 2px solid #6c757d;
            border-radius: 15px;
            padding: 20px;
            margin: 20px 0;
            color: #495057;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .past-event-notice i {
            font-size: 2rem;
            color: #6c757d;
        }

        .past-event-notice-content {
            flex: 1;
        }

        .past-event-notice h4 {
            color: #6c757d;
            margin-bottom: 8px;
            font-size: 1.1rem;
        }

        .past-event-notice p {
            color: #4a5568;
            font-size: 14px;
            line-height: 1.5;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #718096;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.3;
            color: #a0aec0;
            display: block;
        }

        .empty-state h3 {
            font-size: 1.5rem;
            margin-bottom: 10px;
            color: #2d3748;
        }

        .empty-state p {
            font-size: 16px;
            margin-bottom: 25px;
        }

        .action-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 12px 25px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            text-decoration: none;
            border-radius: 15px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
            border: none;
            cursor: pointer;
            font-family: inherit;
            font-size: 14px;
            text-transform: none;
        }

        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 35px rgba(102, 126, 234, 0.4);
            color: white;
            text-decoration: none;
        }

        .action-btn.btn-danger {
            background: linear-gradient(135deg, #ff6b6b, #ee5a52);
            box-shadow: 0 8px 25px rgba(255, 107, 107, 0.3);
        }

        .action-btn.btn-danger:hover {
            background: linear-gradient(135deg, #ee5a52, #e03131);
            box-shadow: 0 12px 35px rgba(255, 107, 107, 0.4);
            transform: translateY(-2px);
        }

        .action-btn.btn-secondary {
            background: linear-gradient(135deg, #6c757d, #5a6268);
            box-shadow: 0 8px 25px rgba(108, 117, 125, 0.3);
        }

        .action-btn.btn-secondary:hover {
            background: linear-gradient(135deg, #5a6268, #495057);
            box-shadow: 0 12px 35px rgba(108, 117, 125, 0.4);
            transform: translateY(-2px);
        }

        .action-btn:disabled {
            background: #a0aec0;
            box-shadow: none;
            cursor: not-allowed;
            opacity: 0.6;
        }

        .action-btn:disabled:hover {
            transform: none;
            box-shadow: none;
        }

        .actions-section {
            background: rgba(248, 249, 250, 0.8);
            border-radius: 15px;
            padding: 25px;
            margin-top: 30px;
            border: 1px solid rgba(102, 126, 234, 0.1);
            text-align: center;
        }

        .actions-section h4 {
            color: #2d3748;
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .actions-grid {
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
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

        .event-info {
            background: linear-gradient(135deg, #e7f3ff, #f0f8ff);
            border: 2px solid #667eea;
            border-radius: 16px;
            padding: 24px;
            margin: 20px 0;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.15);
        }

        .event-info h4 {
            color: #2d3748;
            margin-bottom: 16px;
            font-size: 18px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .event-info p {
            color: #4a5568;
            margin-bottom: 8px;
            font-weight: 500;
        }

        .no-registration-notice {
            background: linear-gradient(135deg, #fff3cd, #ffeaa7);
            border: 2px solid #f0ad4e;
            border-radius: 16px;
            padding: 32px;
            margin: 32px;
            box-shadow: 0 8px 25px rgba(240, 173, 78, 0.15);
            text-align: center;
        }

        .no-registration-notice h3 {
            color: #856404;
            margin-bottom: 20px;
            font-size: 20px;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
        }

        .no-registration-notice p {
            color: #856404;
            margin-bottom: 20px;
            font-weight: 600;
            font-size: 16px;
        }

        /* NEW: Medical summary stats */
        .medical-summary {
            background: linear-gradient(135deg, rgba(231, 76, 60, 0.1), rgba(231, 76, 60, 0.05));
            border: 2px solid #e74c3c;
            border-radius: 16px;
            padding: 24px;
            margin: 20px 0;
            text-align: center;
        }

        .medical-summary h4 {
            color: #c0392b;
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
        }

        .summary-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
        }

        .summary-stat {
            background: rgba(255, 255, 255, 0.8);
            border-radius: 12px;
            padding: 16px;
            text-align: center;
            border: 1px solid rgba(231, 76, 60, 0.2);
        }

        .summary-stat .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #c0392b;
            margin-bottom: 4px;
        }

        .summary-stat .stat-label {
            font-size: 12px;
            color: #718096;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
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
            
            .page-header {
                padding: 30px 25px;
            }
            
            .page-header h1 {
                font-size: 2rem;
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }
            
            .section-header {
                padding: 20px 25px;
                flex-direction: column;
                align-items: flex-start;
            }
            
            .section-content {
                padding: 20px;
            }
            
            .actions-grid {
                flex-direction: column;
                align-items: center;
            }
            
            .action-btn {
                width: 100%;
                justify-content: center;
                max-width: 300px;
            }

            .medical-info-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .main-content {
                padding: 15px;
            }
            .personal-info-grid {
                grid-template-columns: 1fr;
            }
            .medical-info-grid {
                grid-template-columns: 1fr;
            }
            .summary-stats {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
    <button class="mobile-menu-btn" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>

    <div class="app-container">
        <!-- Enhanced Sidebar -->
        <nav class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <a href="student_dashboard.php" class="logo">
                    <img src="images/logo.jpg" alt="LifeSaver Hub Logo" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                    <div style="width: 48px; height: 48px; background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 14px; display: none; align-items: center; justify-content: center; color: white; font-weight: 800; font-size: 18px; box-shadow: 0 6px 20px rgba(102, 126, 234, 0.3);">L</div>
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
                        <a href="student_view_donation.php" class="nav-item active">
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
                        <?php echo strtoupper(substr($student['StudentName'] ?? 'U', 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <h4><?php echo htmlspecialchars($student['StudentName'] ?? 'Student'); ?></h4>
                        <p>Student ID: <?php echo htmlspecialchars($_SESSION['student_id']); ?></p>
                    </div>
                </div>
            </div>
        </nav>

        <div class="main-content">
            <div class="page-header">
                <div class="page-header-content">
                    <h1><i class="fas fa-file-medical"></i> My Donation Application</h1>
                    <p>View your blood donation registration information and detailed medical records</p>
                </div>
            </div>

            <?php if ($registrationDetails): ?>

            <!-- Event Information Section -->
            <?php if ($registrationDetails['EventTitle']): ?>
            <div class="event-info">
                <h4><i class="fas fa-calendar-alt"></i> Registered Event</h4>
                <p><strong>Event:</strong> <?php echo htmlspecialchars($registrationDetails['EventTitle']); ?></p>
                <p><strong>Date:</strong> <?php echo $registrationDetails['EventDate'] ? date('d F Y', strtotime($registrationDetails['EventDate'])) : 'Date TBD'; ?></p>
                <p><strong>Venue:</strong> <?php echo htmlspecialchars($registrationDetails['EventVenue'] ?? 'Venue TBD'); ?></p>
                <p><strong>Status:</strong> <?php echo htmlspecialchars($registrationDetails['EventStatus'] ?? 'Unknown'); ?></p>
                <p><strong>Registration Date:</strong> <?php echo date('d F Y', strtotime($registrationDetails['RegistrationDate'])); ?></p>
                
                <?php 
                // Check if event has passed and show notice
                $eventDate = $registrationDetails['EventDate'] ?? null;
                $currentDate = date('Y-m-d');
                $eventHasPassed = $eventDate && (strtotime($eventDate) < strtotime($currentDate));
                
                if ($eventHasPassed): ?>
                <div style="margin-top: 15px; padding: 12px; background: rgba(108, 117, 125, 0.1); border: 1px solid #6c757d; border-radius: 8px; color: #495057; font-size: 14px; display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-calendar-times"></i>
                    <strong>Past Event:</strong> This event has already concluded on <?php echo date('F j, Y', strtotime($eventDate)); ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Personal Information Section (from Student Table) -->
            <div class="donations-container">
                <div class="section-header">
                    <h3><i class="fas fa-id-card"></i> Personal Information</h3>
                    <div style="color: #667eea; font-size: 14px; font-weight: 600;">
                        <i class="fas fa-database"></i> From Student Profile
                    </div>
                </div>
                <div class="section-content">

                    <!-- Show attendance confirmation notice if attendance is confirmed -->
                    <?php if (!$canModify && in_array($attendanceStatus, ['present', 'attended', 'confirmed'])): ?>
                    <div class="attendance-notice">
                        <i class="fas fa-check-circle"></i>
                        <div class="attendance-notice-content">
                            <h4>Attendance Confirmed</h4>
                            <p>Your attendance has been confirmed by staff. You can no longer modify or cancel your application. 
                            <?php if ($donationDetails && !empty($donationDetails['DonationID'])): ?>
                                Your blood donation has been completed successfully!
                            <?php else: ?>
                                Please arrive at the scheduled time for your blood donation.
                            <?php endif; ?>
                            </p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- NEW: Show past event notice if event has passed -->
                    <?php 
                    $eventDate = $registrationDetails['EventDate'] ?? null;
                    $currentDate = date('Y-m-d');
                    $eventHasPassed = $eventDate && (strtotime($eventDate) < strtotime($currentDate));
                    
                    if ($eventHasPassed && $registrationDetails['RegistrationStatus'] !== 'Cancelled'): ?>
                    <div class="past-event-notice">
                        <i class="fas fa-calendar-times"></i>
                        <div class="past-event-notice-content">
                            <h4>Past Event Registration</h4>
                            <p>This registration is for an event that has already concluded on <?php echo date('F j, Y', strtotime($eventDate)); ?>. 
                            You can no longer modify or cancel this registration. 
                            <?php if (!$donationDetails || empty($donationDetails['DonationID'])): ?>
                                If you did not attend, please register for upcoming events.
                            <?php endif; ?>
                            </p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Show cancellation notice if registration is cancelled -->
                    <?php if ($registrationDetails['RegistrationStatus'] === 'Cancelled'): ?>
                    <div class="cancelled-notice">
                        <i class="fas fa-times-circle"></i>
                        <div class="cancelled-notice-content">
                            <h4>Application Cancelled</h4>
                            <p>Your blood donation application has been cancelled. 
                            <?php if (!empty($registrationDetails['CancellationReason'])): ?>
                                Reason: <?php echo htmlspecialchars($registrationDetails['CancellationReason']); ?>
                            <?php endif; ?>
                            <?php if (!empty($registrationDetails['CancellationDate'])): ?>
                                <br>Cancelled on: <?php echo date('F j, Y \a\t g:i A', strtotime($registrationDetails['CancellationDate'])); ?>
                            <?php endif; ?>
                            <br>You can apply for future blood donation events.
                            </p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Show donation completion notice if donation exists -->
                    <?php if ($donationDetails && !empty($donationDetails['DonationID'])): ?>
                    <div style="background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.05)); border: 2px solid #10b981; border-radius: 15px; padding: 20px; margin: 20px 0; display: flex; align-items: center; gap: 15px;">
                        <i class="fas fa-heart" style="font-size: 2rem; color: #10b981;"></i>
                        <div style="flex: 1;">
                            <h4 style="color: #10b981; margin-bottom: 8px; font-size: 1.1rem;">🎉 Donation Completed Successfully!</h4>
                            <p style="color: #4a5568; font-size: 14px; line-height: 1.5;">
                                Thank you for your life-saving donation! Your contribution will help save lives.
                                <?php if ($donationDetails['DonationDate']): ?>
                                    <br><strong>Donation Date:</strong> <?php echo date('F j, Y', strtotime($donationDetails['DonationDate'])); ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Personal Information Grid (from Student Table) -->
                    <div class="personal-info-grid">
                        <div class="info-card">
                            <div class="info-label">Full Name</div>
                            <div class="info-value"><?= htmlspecialchars($student['StudentName'] ?? 'Not Available') ?></div>
                        </div>
                        <div class="info-card">
                            <div class="info-label">Email Address</div>
                            <div class="info-value"><?= htmlspecialchars($student['StudentEmail'] ?? 'Not Available') ?></div>
                        </div>
                        <div class="info-card">
                            <div class="info-label">Phone Number</div>
                            <div class="info-value"><?= htmlspecialchars($student['StudentContact'] ?? 'Not Available') ?></div>
                        </div>
                        <div class="info-card">
                            <div class="info-label">IC Number</div>
                            <div class="info-value"><?= htmlspecialchars($student['StudentIC'] ?? 'Not Available') ?></div>
                        </div>
                        <div class="info-card">
                            <div class="info-label">Gender</div>
                            <div class="info-value"><?= htmlspecialchars($student['StudentGender'] ?? 'Not Available') ?></div>
                        </div>
                        <div class="info-card">
                            <div class="info-label">Age</div>
                            <div class="info-value"><?= htmlspecialchars($student['StudentAge'] ?? 'Not Available') ?> years old</div>
                        </div>
                        <div class="info-card full-width">
                            <div class="info-label">Address</div>
                            <div class="info-value"><?= htmlspecialchars($student['StudentAddress'] ?? 'Not Available') ?></div>
                        </div>
                    </div>

                    <div style="text-align: center; margin-top: 20px; padding: 16px; background: rgba(102, 126, 234, 0.05); border-radius: 12px;">
                        <p style="color: #667eea; font-size: 14px; font-weight: 600;">
                            <i class="fas fa-info-circle"></i> 
                            Personal information is pulled from your student profile. To update this information, please contact the administrator.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Registration Status Section -->
            <div class="donations-container">
                <div class="section-header">
                    <h3><i class="fas fa-clipboard-check"></i> Registration Status</h3>
                </div>
                <div class="section-content">
                    <div class="personal-info-grid">
                        <div class="info-card">
                            <div class="info-label">Registration ID</div>
                            <div class="info-value">#<?= htmlspecialchars($registrationDetails['RegistrationID']) ?></div>
                        </div>
                        <div class="info-card">
                            <div class="info-label">Application Date</div>
                            <div class="info-value"><?= date('F j, Y', strtotime($registrationDetails['RegistrationDate'])) ?></div>
                        </div>
                        <div class="info-card">
                            <div class="info-label">Health Screening</div>
                            <div class="info-value">
                                <span class="health-status <?= strtolower(str_replace(' ', '.', $registrationDetails['HealthStatus'] ?? 'eligible')) ?>">
                                    <?= htmlspecialchars($registrationDetails['HealthStatus'] ?? 'Eligible') ?>
                                </span>
                            </div>
                        </div>
                        <div class="info-card">
                            <div class="info-label">Registration Status</div>
                            <div class="info-value">
                                <span class="registration-status <?= strtolower($registrationDetails['RegistrationStatus'] ?? 'registered') ?>">
                                    <?= htmlspecialchars($registrationDetails['RegistrationStatus'] ?? 'Registered') ?>
                                </span>
                            </div>
                        </div>
                        <div class="info-card">
                            <div class="info-label">Attendance Status</div>
                            <div class="info-value">
                                <span class="registration-status <?= strtolower($registrationDetails['AttendanceStatus'] ?? 'pending') ?>">
                                    <?= htmlspecialchars($registrationDetails['AttendanceStatus'] ?? 'Pending') ?>
                                </span>
                            </div>
                        </div>
                        <?php if ($registrationDetails['HealthDate']): ?>
                        <div class="info-card">
                            <div class="info-label">Health Screening Date</div>
                            <div class="info-value"><?= date('F j, Y', strtotime($registrationDetails['HealthDate'])) ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Show cancellation details if registration is cancelled -->
                        <?php if ($registrationDetails['RegistrationStatus'] === 'Cancelled'): ?>
                            <?php if (!empty($registrationDetails['CancellationReason'])): ?>
                            <div class="info-card">
                                <div class="info-label">Cancellation Reason</div>
                                <div class="info-value"><?= htmlspecialchars($registrationDetails['CancellationReason']) ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($registrationDetails['CancellationDate'])): ?>
                            <div class="info-card">
                                <div class="info-label">Cancellation Date</div>
                                <div class="info-value"><?= date('F j, Y \a\t g:i A', strtotime($registrationDetails['CancellationDate'])) ?></div>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Actions Section - Show cancel button if applicable -->
                    <?php if ($registrationDetails['RegistrationStatus'] !== 'Cancelled'): ?>
                    <div class="actions-section">
                        <h4><i class="fas fa-cogs"></i> Application Actions</h4>
                        <div class="actions-grid">
                            <?php if ($canCancel): ?>
                                <a href="delete_donation.php" class="action-btn btn-danger" onclick="return confirmCancellation()">
                                    <i class="fas fa-times-circle"></i>
                                    Cancel Registration
                                </a>
                            <?php else: ?>
                                <button class="action-btn btn-secondary" disabled title="<?php 
                                    if ($eventHasPassed) {
                                        echo 'Cannot cancel - event has passed';
                                    } elseif (in_array($attendanceStatus, ['present', 'attended', 'confirmed'])) {
                                        echo 'Cannot cancel - attendance confirmed';
                                    } elseif ($donationDetails && !empty($donationDetails['DonationID'])) {
                                        echo 'Cannot cancel - donation completed';
                                    } else {
                                        echo 'Cannot cancel registration';
                                    }
                                ?>">
                                    <i class="fas fa-lock"></i>
                                    Cannot Cancel
                                </button>
                            <?php endif; ?>
                            
                            <a href="student_view_event.php" class="action-btn">
                                <i class="fas fa-calendar-alt"></i>
                                View Other Events
                            </a>
                        </div>
                        
                        <?php if (!$canCancel && $registrationDetails['RegistrationStatus'] !== 'Cancelled'): ?>
                        <div style="margin-top: 15px; padding: 12px; background: rgba(254, 202, 87, 0.1); border: 1px solid #feca57; border-radius: 8px; color: #856404; font-size: 14px; text-align: center;">
                            <i class="fas fa-info-circle"></i>
                            <?php 
                            if ($eventHasPassed) {
                                echo "Registration cannot be cancelled because the event has already passed.";
                            } elseif (in_array($attendanceStatus, ['present', 'attended', 'confirmed'])) {
                                echo "Registration cannot be cancelled because your attendance has been confirmed by staff.";
                            } elseif ($donationDetails && !empty($donationDetails['DonationID'])) {
                                echo "Registration cannot be cancelled because your donation has been completed.";
                            } else {
                                echo "Registration cannot be cancelled at this time.";
                            }
                            ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <!-- Actions for cancelled registration -->
                    <div class="actions-section">
                        <h4><i class="fas fa-redo"></i> Start New Application</h4>
                        <div class="actions-grid">
                            <a href="student_view_event.php" class="action-btn">
                                <i class="fas fa-calendar-plus"></i>
                                Register for New Event
                            </a>
                            <a href="student_dashboard.php" class="action-btn btn-secondary">
                                <i class="fas fa-home"></i>
                                Go to Dashboard
                            </a>
                        </div>
                        <div style="margin-top: 15px; padding: 12px; background: rgba(102, 126, 234, 0.1); border: 1px solid #667eea; border-radius: 8px; color: #4c51bf; font-size: 14px; text-align: center;">
                            <i class="fas fa-heart"></i>
                            Thank you for your interest in blood donation. You can apply for future events anytime!
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ENHANCED: Detailed Donation Medical Information Section -->
            <?php if ($donationDetails && !empty($donationDetails['DonationID'])): ?>
            <div class="donations-container">
                <div class="section-header">
                    <h3><i class="fas fa-heartbeat"></i> Medical & Donation Details</h3>
                    <div style="color: #e74c3c; font-size: 14px; font-weight: 600;">
                        <i class="fas fa-stethoscope"></i> 
                        Complete Medical Record
                    </div>
                </div>
                <div class="section-content">
                    
                    <!-- Medical Summary Stats -->
                    <div class="medical-summary">
                        <h4><i class="fas fa-chart-line"></i> Donation Summary</h4>
                        <div class="summary-stats">
                            <div class="summary-stat">
                                <div class="stat-value"><?= htmlspecialchars($donationDetails['DonationBloodType'] ?? 'N/A') ?></div>
                                <div class="stat-label">Blood Type</div>
                            </div>
                            <div class="summary-stat">
                                <div class="stat-value"><?= htmlspecialchars($donationDetails['DonationQuantity'] ?? 'N/A') ?></div>
                                <div class="stat-label">Quantity (ml)</div>
                            </div>
                            <div class="summary-stat">
                                <div class="stat-value">
                                    <span class="registration-status <?= strtolower($donationDetails['DonationStatus'] ?? 'completed') ?>">
                                        <?= htmlspecialchars($donationDetails['DonationStatus'] ?? 'Completed') ?>
                                    </span>
                                </div>
                                <div class="stat-label">Status</div>
                            </div>
                            <?php if ($donationDetails['DonationDate']): ?>
                            <div class="summary-stat">
                                <div class="stat-value"><?= date('M j, Y', strtotime($donationDetails['DonationDate'])) ?></div>
                                <div class="stat-label">Donation Date</div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Detailed Medical Information Grid -->
                    <div class="medical-info-grid">
                        
                        <!-- Basic Donation Info -->
                        <?php if (!empty($donationDetails['DonationDate'])): ?>
                        <div class="info-card medical">
                            <div class="info-label"><i class="fas fa-calendar-check"></i> Donation Date</div>
                            <div class="info-value"><?= date('l, F j, Y', strtotime($donationDetails['DonationDate'])) ?></div>
                        </div>
                        <?php endif; ?>

                        <div class="info-card medical">
                            <div class="info-label"><i class="fas fa-tint"></i> Blood Type</div>
                            <div class="info-value large" style="color: #e74c3c;">
                                <?= htmlspecialchars($donationDetails['DonationBloodType'] ?? 'Not specified') ?>
                            </div>
                        </div>

                        <div class="info-card medical">
                            <div class="info-label"><i class="fas fa-flask"></i> Quantity Collected</div>
                            <div class="info-value with-unit">
                                <span class="large"><?= htmlspecialchars($donationDetails['DonationQuantity'] ?? 'N/A') ?></span>
                                <span class="unit">ml</span>
                            </div>
                        </div>

                        <!-- Physical Measurements -->
                        <?php if (!empty($donationDetails['Weight'])): ?>
                        <div class="info-card vital-signs">
                            <div class="info-label"><i class="fas fa-weight"></i> Weight</div>
                            <div class="info-value with-unit">
                                <span class="large"><?= htmlspecialchars($donationDetails['Weight']) ?></span>
                                <span class="unit">kg</span>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($donationDetails['BloodPressure'])): ?>
                        <div class="info-card vital-signs">
                            <div class="info-label"><i class="fas fa-heartbeat"></i> Blood Pressure</div>
                            <div class="info-value with-unit">
                                <span class="large"><?= htmlspecialchars($donationDetails['BloodPressure']) ?></span>
                                <span class="unit">mmHg</span>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($donationDetails['Temperature'])): ?>
                        <div class="info-card vital-signs">
                            <div class="info-label"><i class="fas fa-thermometer-half"></i> Body Temperature</div>
                            <div class="info-value with-unit">
                                <span class="large"><?= htmlspecialchars($donationDetails['Temperature']) ?></span>
                                <span class="unit">°C</span>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($donationDetails['PulseRate'])): ?>
                        <div class="info-card vital-signs">
                            <div class="info-label"><i class="fas fa-heart"></i> Pulse Rate</div>
                            <div class="info-value with-unit">
                                <span class="large"><?= htmlspecialchars($donationDetails['PulseRate']) ?></span>
                                <span class="unit">bpm</span>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Laboratory Results -->
                        <?php if (!empty($donationDetails['HemoglobinLevel'])): ?>
                        <div class="info-card lab-results">
                            <div class="info-label"><i class="fas fa-vial"></i> Hemoglobin Level</div>
                            <div class="info-value with-unit">
                                <span class="large"><?= htmlspecialchars($donationDetails['HemoglobinLevel']) ?></span>
                                <span class="unit">g/dL</span>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($donationDetails['PlateletCount'])): ?>
                        <div class="info-card lab-results">
                            <div class="info-label"><i class="fas fa-microscope"></i> Platelet Count</div>
                            <div class="info-value with-unit">
                                <span class="large"><?= htmlspecialchars($donationDetails['PlateletCount']) ?></span>
                                <span class="unit">×10³/μL</span>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Donation Status -->
                        <div class="info-card medical full-width">
                            <div class="info-label"><i class="fas fa-check-circle"></i> Donation Status</div>
                            <div class="info-value">
                                <span class="registration-status <?= strtolower($donationDetails['DonationStatus'] ?? 'completed') ?>">
                                    <?= htmlspecialchars($donationDetails['DonationStatus'] ?? 'Completed') ?>
                                </span>
                                <?php if ($donationDetails['DonationDate']): ?>
                                    <span style="margin-left: 15px; color: #718096; font-size: 14px; font-weight: 500;">
                                        on <?= date('F j, Y', strtotime($donationDetails['DonationDate'])) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Medical Information Notice -->
                    <div style="text-align: center; margin-top: 25px; padding: 20px; background: rgba(231, 76, 60, 0.05); border-radius: 12px; border: 1px solid rgba(231, 76, 60, 0.1);">
                        <p style="color: #c0392b; font-size: 14px; font-weight: 600; margin-bottom: 8px;">
                            <i class="fas fa-user-md"></i> 
                            Medical Data Recorded by Healthcare Professionals
                        </p>
                        <p style="color: #718096; font-size: 13px; line-height: 1.4;">
                            All medical measurements and laboratory results were taken during your donation session by qualified medical staff. 
                            This information is part of your permanent medical record for this donation.
                        </p>
                    </div>

                    <!-- Health Tips Section -->
                    <div style="margin-top: 25px; padding: 20px; background: linear-gradient(135deg, rgba(16, 185, 129, 0.05), rgba(16, 185, 129, 0.02)); border-radius: 12px; border: 1px solid rgba(16, 185, 129, 0.1);">
                        <h5 style="color: #059669; font-size: 16px; font-weight: 700; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-heart"></i> Post-Donation Health Tips
                        </h5>
                        <div style="color: #4a5568; font-size: 14px; line-height: 1.5;">
                            <p style="margin-bottom: 8px;">• Stay hydrated by drinking plenty of fluids</p>
                            <p style="margin-bottom: 8px;">• Eat iron-rich foods to help replenish your blood</p>
                            <p style="margin-bottom: 8px;">• Avoid heavy lifting for 24 hours</p>
                            <p style="margin-bottom: 0;">• Rest if you feel lightheaded or dizzy</p>
                        </div>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <!-- No Donation Completed Yet -->
            <?php if ($registrationDetails['RegistrationStatus'] !== 'Cancelled'): ?>
            <div class="donations-container">
                <div class="section-header">
                    <h3><i class="fas fa-heartbeat"></i> Medical & Donation Details</h3>
                    <div style="color: #f39c12; font-size: 14px; font-weight: 600;">
                        <i class="fas fa-clock"></i> 
                        Pending Donation
                    </div>
                </div>
                <div class="section-content">
                    <div style="text-align: center; padding: 40px 20px; color: #718096;">
                        <i class="fas fa-stethoscope" style="font-size: 3rem; margin-bottom: 20px; opacity: 0.3; color: #a0aec0; display: block;"></i>
                        <h4 style="font-size: 1.3rem; margin-bottom: 12px; color: #2d3748;">Medical Details Not Available Yet</h4>
                        <p style="font-size: 16px; margin-bottom: 20px; line-height: 1.5;">
                            Medical measurements and donation details will be recorded when you complete your blood donation at the event.
                        </p>
                        <div style="background: rgba(102, 126, 234, 0.1); border: 1px solid #667eea; border-radius: 8px; padding: 16px; margin: 20px auto; max-width: 500px;">
                            <p style="color: #4c51bf; font-size: 14px; font-weight: 600; margin: 0;">
                                <i class="fas fa-info-circle"></i>
                                The following will be recorded during your donation: weight, blood pressure, temperature, pulse rate, hemoglobin level, platelet count, and blood type confirmation.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <?php else: ?>
            <!-- No Registration Found -->
            <div class="no-registration-notice">
                <h3><i class="fas fa-info-circle"></i> No Blood Donation Application Found</h3>
                <p>You haven't completed the health questionnaire or registered for blood donation yet.</p>
                <p>To donate blood, you need to:</p>
                <ol style="text-align: left; max-width: 400px; margin: 20px auto; color: #856404; font-weight: 600;">
                    <li>Select an available blood donation event</li>
                    <li>Complete the health questionnaire</li>
                    <li>Get your eligibility status</li>
                    <li>Register for donation (if eligible)</li>
                </ol>
                <a href="student_view_event.php" class="action-btn">
                    <i class="fas fa-calendar-alt"></i>
                    Start Blood Donation Process
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('open');
        }

        function confirmCancellation() {
            return confirm(
                '⚠️ CANCEL REGISTRATION CONFIRMATION\n\n' +
                'Are you sure you want to cancel your blood donation registration?\n\n' +
                '• This action will be permanently recorded in the database\n' +
                '• You will need to provide a reason for cancellation\n' +
                '• You can register for future events if you change your mind\n\n' +
                'Click OK to proceed with cancellation or Cancel to keep your registration.'
            );
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

        // Add smooth animations for cards
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.info-card');
            cards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    card.style.transition = 'all 0.6s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, 100 + (index * 50));
            });

            // Animate medical cards with special effects
            const medicalCards = document.querySelectorAll('.info-card.medical, .info-card.vital-signs, .info-card.lab-results');
            medicalCards.forEach((card, index) => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-5px) scale(1.02)';
                    this.style.boxShadow = '0 8px 25px rgba(231, 76, 60, 0.15)';
                });
                
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0) scale(1)';
                    this.style.boxShadow = '0 4px 15px rgba(0, 0, 0, 0.1)';
                });
            });
        });

        // Add click effects to action buttons
        document.querySelectorAll('.action-btn:not(:disabled)').forEach(btn => {
            btn.addEventListener('click', function() {
                this.style.transform = 'scale(0.95)';
                setTimeout(() => {
                    this.style.transform = 'scale(1)';
                }, 150);
            });
        });

        // Enhanced debugging with medical data info
        console.log('🩸 LifeSaver Hub - Enhanced Donation View with Medical Details');
        console.log('Student ID:', <?php echo json_encode($_SESSION['student_id']); ?>);
        console.log('Registration Details:', <?php echo json_encode($registrationDetails ? 'Found' : 'Not Found'); ?>);
        <?php if ($registrationDetails): ?>
        console.log('Registration ID:', <?php echo json_encode($registrationDetails['RegistrationID']); ?>);
        console.log('Registration Status:', <?php echo json_encode($registrationDetails['RegistrationStatus'] ?? 'Unknown'); ?>);
        console.log('Attendance Status:', <?php echo json_encode($registrationDetails['AttendanceStatus'] ?? 'Unknown'); ?>);
        console.log('Event Date:', <?php echo json_encode($registrationDetails['EventDate'] ?? 'No Date'); ?>);
        console.log('Current Date:', <?php echo json_encode(date('Y-m-d')); ?>);
        console.log('Event Has Passed:', <?php 
            $eventDate = $registrationDetails['EventDate'] ?? null;
            $currentDate = date('Y-m-d');
            $eventHasPassed = $eventDate && (strtotime($eventDate) < strtotime($currentDate));
            echo json_encode($eventHasPassed ? 'Yes' : 'No');
        ?>);
        console.log('Can Cancel:', <?php echo json_encode($canCancel ? 'Yes' : 'No'); ?>);
        console.log('Can Modify:', <?php echo json_encode($canModify ? 'Yes' : 'No'); ?>);
        console.log('Donation Details:', <?php echo json_encode($donationDetails ? 'Found' : 'Not Found'); ?>);
        <?php if ($donationDetails): ?>
        console.log('Donation ID:', <?php echo json_encode($donationDetails['DonationID'] ?? 'No ID'); ?>);
        console.log('Blood Type:', <?php echo json_encode($donationDetails['DonationBloodType'] ?? 'Not Specified'); ?>);
        console.log('Donation Quantity:', <?php echo json_encode($donationDetails['DonationQuantity'] ?? 'Not Specified'); ?>);
        console.log('Weight:', <?php echo json_encode($donationDetails['Weight'] ?? 'Not Recorded'); ?>);
        console.log('Blood Pressure:', <?php echo json_encode($donationDetails['BloodPressure'] ?? 'Not Recorded'); ?>);
        console.log('Temperature:', <?php echo json_encode($donationDetails['Temperature'] ?? 'Not Recorded'); ?>);
        console.log('Pulse Rate:', <?php echo json_encode($donationDetails['PulseRate'] ?? 'Not Recorded'); ?>);
        console.log('Hemoglobin Level:', <?php echo json_encode($donationDetails['HemoglobinLevel'] ?? 'Not Recorded'); ?>);
        console.log('Platelet Count:', <?php echo json_encode($donationDetails['PlateletCount'] ?? 'Not Recorded'); ?>);
        console.log('Donation Status:', <?php echo json_encode($donationDetails['DonationStatus'] ?? 'Unknown'); ?>);
        <?php if (!empty($donationDetails['DonationDate'])): ?>
        console.log('Donation Date:', <?php echo json_encode($donationDetails['DonationDate']); ?>);
        <?php endif; ?>
        <?php endif; ?>
        <?php endif; ?>
        console.log('🔄 Enhanced System Flow: student_view_event.php → health_questionnaire.php → student_view_donation.php (with full medical details)');
        console.log('✅ Medical Data Display: Active - Shows comprehensive donation and health measurements');
        console.log('🏥 Medical Features: Donation details, vital signs, lab results, post-donation health tips');
    </script>
</body>
</html>