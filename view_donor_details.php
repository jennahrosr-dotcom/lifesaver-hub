<?php
session_start();

if (!isset($_SESSION['staff_id'])) {
    header("Location: staff_login.php");
    exit;
}

// Initialize ALL variables first to prevent undefined variable warnings
$donorDetails = null;
$registrationDetails = null;
$donationDetails = null;
$eventDetails = null;
$healthDetails = null;
$staff = null;
$error = '';
$success = '';
$donationStats = [];
$studentId = null;

try {
    require 'db.php';

    // Get staff information
    $stmt = $pdo->prepare("SELECT * FROM staff WHERE StaffID = ?");
    $stmt->execute([$_SESSION['staff_id']]);
    $staff = $stmt->fetch();

    if (!$staff) {
        header("Location: staff_login.php");
        exit;
    }

    // FIXED: Check if donor ID is provided - Handle both StudentID and RegistrationID
    if (!isset($_GET['id']) && !isset($_GET['registration_id'])) {
        $error = "Student ID or Registration ID is required. Please provide a valid ID.";
    } else {
        // Handle both StudentID and RegistrationID parameters
        if (isset($_GET['id']) && !empty($_GET['id'])) {
            // Direct StudentID provided
            $studentId = trim($_GET['id']);
            
            // First try to find student by StudentID
            $stmt = $pdo->prepare("SELECT * FROM student WHERE StudentID = ?");
            $stmt->execute([$studentId]);
            $donorDetails = $stmt->fetch();

            // If not found and input looks numeric, try casting to int
            if (!$donorDetails && is_numeric($studentId)) {
                $stmt = $pdo->prepare("SELECT * FROM student WHERE StudentID = ?");
                $stmt->execute([(int)$studentId]);
                $donorDetails = $stmt->fetch();
            }

            // If still not found, try searching by name (partial match)
            if (!$donorDetails && !is_numeric($studentId)) {
                $stmt = $pdo->prepare("SELECT * FROM student WHERE StudentName LIKE ? LIMIT 1");
                $stmt->execute(['%' . $studentId . '%']);
                $donorDetails = $stmt->fetch();
            }
        } 
        elseif (isset($_GET['registration_id']) && !empty($_GET['registration_id'])) {
            // RegistrationID provided - get StudentID from registration table
            $registrationId = trim($_GET['registration_id']);
            
            // Get StudentID from registration table
            $stmt = $pdo->prepare("SELECT StudentID FROM registration WHERE RegistrationID = ?");
            $stmt->execute([$registrationId]);
            $registrationRecord = $stmt->fetch();
            
            if ($registrationRecord) {
                $studentId = $registrationRecord['StudentID'];
                
                // Now get student details
                $stmt = $pdo->prepare("SELECT * FROM student WHERE StudentID = ?");
                $stmt->execute([$studentId]);
                $donorDetails = $stmt->fetch();
            } else {
                $error = "Registration not found with ID: " . htmlspecialchars($registrationId);
            }
        }

        if (!$donorDetails && !isset($error)) {
            // Get some debug info about available students
            $stmt = $pdo->query("SELECT StudentID, StudentName FROM student ORDER BY StudentID LIMIT 10");
            $availableStudents = $stmt->fetchAll();
            
            $debugInfo = "Available students: ";
            foreach ($availableStudents as $student) {
                $debugInfo .= "ID: {$student['StudentID']} ({$student['StudentName']}), ";
            }
            
            $searchTerm = isset($_GET['id']) ? $_GET['id'] : $_GET['registration_id'];
            $error = "Student not found with ID: " . htmlspecialchars($searchTerm) . ". " . rtrim($debugInfo, ', ');
        } else {
            // Use the found student's actual ID for further queries
            $studentId = $donorDetails['StudentID'];
            
            // Get ALL registration details with health information and event details
            $stmt = $pdo->prepare("
                SELECT r.*, h.HealthStatus, h.HealthDate, e.EventTitle, e.EventDate, e.EventDay, e.EventVenue, e.EventStatus, e.EventID
                FROM registration r
                LEFT JOIN healthquestion h ON r.RegistrationID = h.RegistrationID
                LEFT JOIN event e ON r.EventID = e.EventID
                WHERE r.StudentID = ?
                ORDER BY r.RegistrationDate DESC
            ");
            $stmt->execute([$studentId]);
            $registrationDetails = $stmt->fetchAll();

            // Get most recent registration for primary display
            $primaryRegistration = !empty($registrationDetails) ? $registrationDetails[0] : null;

            if ($primaryRegistration) {
                // Get ALL donation details for all registrations
                $stmt = $pdo->prepare("
                    SELECT d.*, r.RegistrationID, e.EventTitle, e.EventDate
                    FROM donation d
                    INNER JOIN registration r ON d.RegistrationID = r.RegistrationID
                    LEFT JOIN event e ON r.EventID = e.EventID
                    WHERE r.StudentID = ?
                    ORDER BY d.DonationDate DESC, d.DonationID DESC
                ");
                $stmt->execute([$studentId]);
                $donationDetails = $stmt->fetchAll();

                // Get health questionnaire details for primary registration
                $stmt = $pdo->prepare("
                    SELECT * FROM healthquestion 
                    WHERE RegistrationID = ?
                    ORDER BY HealthDate DESC
                    LIMIT 1
                ");
                $stmt->execute([$primaryRegistration['RegistrationID']]);
                $healthDetails = $stmt->fetch();

                // Get event details for primary registration
                if ($primaryRegistration['EventID']) {
                    $stmt = $pdo->prepare("SELECT * FROM event WHERE EventID = ?");
                    $stmt->execute([$primaryRegistration['EventID']]);
                    $eventDetails = $stmt->fetch();
                }
            }

            // Get comprehensive donation statistics for this donor
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(d.DonationID) as total_donations,
                    COUNT(CASE WHEN d.DonationStatus = 'Completed' THEN 1 END) as completed_donations,
                    SUM(CASE WHEN d.DonationStatus = 'Completed' THEN CAST(d.DonationQuantity AS UNSIGNED) ELSE 0 END) as total_quantity,
                    MAX(d.DonationDate) as last_donation_date,
                    MIN(d.DonationDate) as first_donation_date,
                    COUNT(DISTINCT r.EventID) as events_participated,
                    COUNT(CASE WHEN r.AttendanceStatus = 'Present' THEN 1 END) as events_attended
                FROM registration r
                LEFT JOIN donation d ON r.RegistrationID = d.RegistrationID
                WHERE r.StudentID = ?
            ");
            $stmt->execute([$studentId]);
            $donationStats = $stmt->fetch();

            // Get registration statistics
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as total_registrations,
                    COUNT(CASE WHEN RegistrationStatus = 'Registered' THEN 1 END) as active_registrations,
                    COUNT(CASE WHEN RegistrationStatus = 'Cancelled' THEN 1 END) as cancelled_registrations,
                    COUNT(CASE WHEN AttendanceStatus = 'Present' THEN 1 END) as attended_events
                FROM registration 
                WHERE StudentID = ?
            ");
            $stmt->execute([$studentId]);
            $registrationStats = $stmt->fetch();
        }
    }

} catch (Exception $e) {
    error_log("Database error in view_donor_details.php: " . $e->getMessage());
    $error = "An error occurred while fetching donor details: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Donor Details - <?php echo htmlspecialchars($donorDetails['StudentName'] ?? 'Unknown'); ?> - LifeSaver Hub</title>
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .page-header h1 {
            font-size: 2.5rem;
            font-weight: 900;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 20px;
            text-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            margin: 0;
        }

        .page-header h1 i {
            background: linear-gradient(135deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-size: 2rem;
        }

        .page-header p {
            color: #4a5568;
            font-size: 16px;
            margin-top: 10px;
            font-weight: 500;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            padding: 15px 30px;
            border: none;
            border-radius: 50px;
            font-weight: 700;
            font-size: 14px;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            text-align: center;
            white-space: nowrap;
            position: relative;
            overflow: hidden;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-family: inherit;
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

        .btn-outline {
            background: rgba(102, 126, 234, 0.1);
            color: #667eea;
            border: 2px solid rgba(102, 126, 234, 0.2);
        }

        .btn-outline:hover {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
            border-color: transparent;
        }

        .donor-container {
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

        .donor-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
        }

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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border-radius: 16px;
            padding: 24px;
            text-align: center;
            border: 1px solid rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea, #764ba2);
            border-radius: 16px 16px 0 0;
        }

        .stat-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 20px 40px rgba(102, 126, 234, 0.15);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 900;
            color: #2d3748;
            margin-bottom: 8px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .stat-label {
            font-size: 12px;
            color: #4a5568;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }

        .alert {
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

        .alert-danger {
            background: linear-gradient(135deg, rgba(220, 53, 69, 0.9), rgba(255, 107, 107, 0.9));
            color: white;
            border-left: 4px solid #dc3545;
        }

        .alert-success {
            background: linear-gradient(135deg, rgba(16, 172, 132, 0.9), rgba(0, 210, 211, 0.9));
            color: white;
            border-left: 4px solid #10ac84;
        }

        .no-data {
            text-align: center;
            padding: 40px 20px;
            color: #718096;
        }

        .no-data i {
            font-size: 3rem;
            margin-bottom: 20px;
            opacity: 0.3;
            color: #a0aec0;
            display: block;
        }

        .no-data h3 {
            font-size: 1.3rem;
            margin-bottom: 12px;
            color: #2d3748;
        }

        .no-data p {
            font-size: 16px;
            margin-bottom: 20px;
        }

        .registration-history {
            background: rgba(248, 249, 250, 0.8);
            border-radius: 15px;
            padding: 20px;
            margin-top: 20px;
            border: 1px solid rgba(102, 126, 234, 0.1);
        }

        .registration-history h5 {
            color: #2d3748;
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .registration-item {
            background: rgba(255, 255, 255, 0.8);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            border-left: 4px solid #667eea;
        }

        .registration-item:last-child {
            margin-bottom: 0;
        }

        .registration-item h6 {
            color: #2d3748;
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .registration-item p {
            color: #4a5568;
            font-size: 13px;
            margin-bottom: 5px;
        }

        .registration-item p:last-child {
            margin-bottom: 0;
        }

        .donor-summary {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(139, 92, 246, 0.05));
            border: 2px solid #667eea;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 30px;
            text-align: center;
        }

        .donor-summary h4 {
            color: #4c51bf;
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
            border: 1px solid rgba(102, 126, 234, 0.2);
        }

        .summary-stat .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #667eea;
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
            
            .medical-info-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 480px) {
            .main-content {
                padding: 15px;
            }
            .donor-info-grid {
                grid-template-columns: 1fr;
            }
            .medical-info-grid {
                grid-template-columns: 1fr;
            }
            .summary-stats {
                grid-template-columns: repeat(2, 1fr);
            }
            .stats-grid {
                grid-template-columns: 1fr;
            }
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
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
                
                <!-- Student Search Helper -->
                <div class="donor-container">
                    <div class="section-header">
                        <h3><i class="fas fa-search"></i> Find Student</h3>
                    </div>
                    <div class="section-content">
                        <form method="GET" action="">
                            <div style="display: grid; grid-template-columns: 1fr auto; gap: 15px; align-items: end;">
                                <div>
                                    <label for="student_search" style="display: block; margin-bottom: 8px; font-weight: 600; color: #2d3748;">Search by Student ID or Name:</label>
                                    <input type="text" 
                                           id="student_search" 
                                           name="id" 
                                           placeholder="Enter Student ID (1-10) or Name" 
                                           value="<?php echo htmlspecialchars($_GET['id'] ?? ''); ?>"
                                           style="width: 100%; padding: 12px 16px; border: 2px solid rgba(102, 126, 234, 0.2); border-radius: 10px; font-size: 16px;">
                                </div>
                                <button type="submit" class="btn btn-outline" style="height: fit-content;">
                                    <i class="fas fa-search"></i>
                                    Search
                                </button>
                            </div>
                            
                            <!-- Quick Select Buttons for Common IDs -->
                            <div style="margin-top: 15px;">
                                <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #2d3748; font-size: 14px;">Quick Select:</label>
                                <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                                    <?php for ($i = 1; $i <= 10; $i++): ?>
                                        <a href="?id=<?= $i ?>" 
                                           style="padding: 6px 12px; background: <?= (isset($_GET['id']) && $_GET['id'] == $i) ? '#667eea' : 'rgba(102, 126, 234, 0.1)' ?>; color: <?= (isset($_GET['id']) && $_GET['id'] == $i) ? 'white' : '#667eea' ?>; text-decoration: none; border-radius: 6px; font-size: 12px; font-weight: 600; border: 1px solid rgba(102, 126, 234, 0.3); transition: all 0.2s;">
                                            ID <?= $i ?>
                                        </a>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        </form>
                        
                        <?php
                        // Show available students for reference
                        try {
                            // First, get all students for general reference
                            $stmt = $pdo->query("
                                SELECT StudentID, StudentName, StudentEmail
                                FROM student 
                                ORDER BY StudentID
                                LIMIT 20
                            ");
                            $allStudents = $stmt->fetchAll();
                            
                            // Then get students with donation activity
                            $stmt = $pdo->query("
                                SELECT s.StudentID, s.StudentName, s.StudentEmail,
                                       COUNT(DISTINCT r.RegistrationID) as total_registrations,
                                       COUNT(DISTINCT d.DonationID) as total_donations
                                FROM student s
                                LEFT JOIN registration r ON s.StudentID = r.StudentID
                                LEFT JOIN donation d ON r.RegistrationID = d.RegistrationID
                                GROUP BY s.StudentID, s.StudentName, s.StudentEmail
                                HAVING total_registrations > 0 OR total_donations > 0
                                ORDER BY total_donations DESC, total_registrations DESC
                                LIMIT 10
                            ");
                            $activeStudents = $stmt->fetchAll();
                            
                            ?>
                            <div style="margin-top: 25px;">
                                <!-- Active Students Section -->
                                <?php if (!empty($activeStudents)): ?>
                                <h5 style="color: #2d3748; font-size: 16px; font-weight: 700; margin-bottom: 15px;">
                                    <i class="fas fa-star" style="color: #f59e0b;"></i> Students with Donation Activity:
                                </h5>
                                <div style="display: grid; gap: 10px; margin-bottom: 30px;">
                                    <?php foreach ($activeStudents as $student): ?>
                                        <div style="background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.05)); border: 1px solid rgba(16, 185, 129, 0.2); border-radius: 10px; padding: 15px; display: flex; justify-content: space-between; align-items: center;">
                                            <div>
                                                <strong style="color: #065f46;"><?= htmlspecialchars($student['StudentName']) ?></strong>
                                                <br>
                                                <small style="color: #059669;">
                                                    <i class="fas fa-id-badge"></i> ID: <?= htmlspecialchars($student['StudentID']) ?> | 
                                                    <i class="fas fa-clipboard-list"></i> Registrations: <?= $student['total_registrations'] ?> | 
                                                    <i class="fas fa-tint"></i> Donations: <?= $student['total_donations'] ?>
                                                    <?php if ($student['StudentEmail']): ?>
                                                        <br><i class="fas fa-envelope"></i> <?= htmlspecialchars($student['StudentEmail']) ?>
                                                    <?php endif; ?>
                                                </small>
                                            </div>
                                            <a href="view_donor_details.php?id=<?= urlencode($student['StudentID']); ?>" class="btn btn-outline">
                                                <i class="fas fa-eye"></i> VIEW DETAILS
                                            </a>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                                
                                <!-- All Students Quick Reference -->
                                <details style="margin-top: 20px;">
                                    <summary style="color: #667eea; font-weight: 600; cursor: pointer; padding: 10px; background: rgba(102, 126, 234, 0.1); border-radius: 8px; margin-bottom: 15px;">
                                        <i class="fas fa-users"></i> View All Students (Quick Reference)
                                    </summary>
                                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; margin-top: 15px;">
                                        <?php foreach ($allStudents as $student): ?>
                                            <div style="background: rgba(248, 249, 250, 0.8); border: 1px solid rgba(102, 126, 234, 0.2); border-radius: 8px; padding: 12px; display: flex; justify-content: space-between; align-items: center;">
                                                <div style="flex: 1;">
                                                    <strong style="color: #2d3748; font-size: 14px;"><?= htmlspecialchars($student['StudentName']) ?></strong>
                                                    <br>
                                                    <small style="color: #718096;">ID: <?= htmlspecialchars($student['StudentID']) ?></small>
                                                </div>
                                                <a href="?id=<?= urlencode($student['StudentID']) ?>" 
                                                   style="color: #667eea; text-decoration: none; font-size: 12px; padding: 4px 8px; border: 1px solid #667eea; border-radius: 4px; transition: all 0.2s;">
                                                    <i class="fas fa-search"></i>
                                                </a>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </details>
                            </div>
                            <?php
                        } catch (Exception $e) {
                            // Silently handle any database errors
                            echo '<p style="color: #718096; font-style: italic;">Unable to load student list at this time.</p>';
                        }
                        ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <?php if ($donorDetails): ?>
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-header-content">
                    <div>
                        <h1>
                            <i class="fas fa-user-md"></i>
                            Donor Profile
                        </h1>
                        <p>Comprehensive medical and donation details for <?php echo htmlspecialchars($donorDetails['StudentName']); ?></p>
                    </div>
                    <a href="staff_view_donation.php" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i>
                        Back to Donations
                    </a>
                </div>
            </div>

            <!-- Donor Statistics Overview -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?= $donationStats['total_donations'] ?? 0 ?></div>
                    <div class="stat-label">Total Donations</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= $donationStats['completed_donations'] ?? 0 ?></div>
                    <div class="stat-label">Completed Donations</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= $donationStats['total_quantity'] ?? 0 ?> ml</div>
                    <div class="stat-label">Total Blood Donated</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= isset($registrationStats) ? $registrationStats['total_registrations'] : 0 ?></div>
                    <div class="stat-label">Total Registrations</div>
                </div>
            </div>

            <!-- Donor Summary -->
            <?php if ($donationStats['completed_donations'] > 0): ?>
            <div class="donor-summary">
                <h4><i class="fas fa-award"></i> Donor Achievement Summary</h4>
                <div class="summary-stats">
                    <div class="summary-stat">
                        <div class="stat-value"><?= $donationStats['events_attended'] ?? 0 ?></div>
                        <div class="stat-label">Events Attended</div>
                    </div>
                    <div class="summary-stat">
                        <div class="stat-value">
                            <?= $donationStats['first_donation_date'] ? date('M Y', strtotime($donationStats['first_donation_date'])) : 'N/A' ?>
                        </div>
                        <div class="stat-label">First Donation</div>
                    </div>
                    <div class="summary-stat">
                        <div class="stat-value">
                            <?= $donationStats['last_donation_date'] ? date('M Y', strtotime($donationStats['last_donation_date'])) : 'N/A' ?>
                        </div>
                        <div class="stat-label">Last Donation</div>
                    </div>
                    <div class="summary-stat">
                        <div class="stat-value">
                            <?= round(($donationStats['total_quantity'] ?? 0) / 450, 1) ?> 
                        </div>
                        <div class="stat-label">Lives Saved*</div>
                    </div>
                </div>
                <div style="margin-top: 15px; font-size: 12px; color: #718096;">
                    *Estimated based on average 450ml donation helping 3 patients
                </div>
            </div>
            <?php endif; ?>

            <!-- Personal Information Section -->
            <div class="donor-container">
                <div class="section-header">
                    <h3><i class="fas fa-id-card"></i> Personal Information</h3>
                    <div style="color: #667eea; font-size: 14px; font-weight: 600;">
                        <i class="fas fa-user"></i> Student ID: <?= htmlspecialchars($donorDetails['StudentID']) ?>
                    </div>
                </div>
                <div class="section-content">
                    <div class="donor-info-grid">
                        <div class="info-card">
                            <div class="info-label">Full Name</div>
                            <div class="info-value"><?= htmlspecialchars($donorDetails['StudentName']) ?></div>
                        </div>
                        <div class="info-card">
                            <div class="info-label">Email Address</div>
                            <div class="info-value"><?= htmlspecialchars($donorDetails['StudentEmail'] ?? 'Not provided') ?></div>
                        </div>
                        <div class="info-card">
                            <div class="info-label">Phone Number</div>
                            <div class="info-value"><?= htmlspecialchars($donorDetails['StudentContact'] ?? 'Not provided') ?></div>
                        </div>
                        <div class="info-card">
                            <div class="info-label">IC Number</div>
                            <div class="info-value"><?= htmlspecialchars($donorDetails['StudentIC'] ?? 'Not provided') ?></div>
                        </div>
                        <div class="info-card">
                            <div class="info-label">Gender</div>
                            <div class="info-value"><?= htmlspecialchars($donorDetails['StudentGender'] ?? 'Not specified') ?></div>
                        </div>
                        <div class="info-card">
                            <div class="info-label">Age</div>
                            <div class="info-value"><?= htmlspecialchars($donorDetails['StudentAge'] ?? 'Not specified') ?> years old</div>
                        </div>
                        <div class="info-card full-width">
                            <div class="info-label">Address</div>
                            <div class="info-value"><?= htmlspecialchars($donorDetails['StudentAddress'] ?? 'Not provided') ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Current Registration Status -->
            <?php if ($primaryRegistration): ?>
            <div class="donor-container">
                <div class="section-header">
                    <h3><i class="fas fa-clipboard-check"></i> Current Registration Status</h3>
                    <div style="color: #667eea; font-size: 14px; font-weight: 600;">
                        <i class="fas fa-calendar"></i> 
                        <?= $eventDetails ? htmlspecialchars($eventDetails['EventTitle']) : 'Event Details' ?>
                    </div>
                </div>
                <div class="section-content">
                    <div class="donor-info-grid">
                        <div class="info-card">
                            <div class="info-label">Registration ID</div>
                            <div class="info-value">#<?= htmlspecialchars($primaryRegistration['RegistrationID']) ?></div>
                        </div>
                        <div class="info-card">
                            <div class="info-label">Registration Date</div>
                            <div class="info-value"><?= date('F j, Y', strtotime($primaryRegistration['RegistrationDate'])) ?></div>
                        </div>
                        <div class="info-card">
                            <div class="info-label">Event Date</div>
                            <div class="info-value">
                                <?= $primaryRegistration['EventDate'] ? date('F j, Y', strtotime($primaryRegistration['EventDate'])) : 'TBD' ?>
                                <?= $primaryRegistration['EventDay'] ? ' (' . htmlspecialchars($primaryRegistration['EventDay']) . ')' : '' ?>
                            </div>
                        </div>
                        <div class="info-card">
                            <div class="info-label">Event Venue</div>
                            <div class="info-value"><?= htmlspecialchars($primaryRegistration['EventVenue'] ?? 'TBD') ?></div>
                        </div>
                        <div class="info-card">
                            <div class="info-label">Health Status</div>
                            <div class="info-value">
                                <span class="health-status <?= strtolower(str_replace(' ', '.', $primaryRegistration['HealthStatus'] ?? 'eligible')) ?>">
                                    <?= htmlspecialchars($primaryRegistration['HealthStatus'] ?? 'Eligible') ?>
                                </span>
                            </div>
                        </div>
                        <div class="info-card">
                            <div class="info-label">Registration Status</div>
                            <div class="info-value">
                                <span class="registration-status <?= strtolower($primaryRegistration['RegistrationStatus'] ?? 'registered') ?>">
                                    <?= htmlspecialchars($primaryRegistration['RegistrationStatus'] ?? 'Registered') ?>
                                </span>
                            </div>
                        </div>
                        <div class="info-card">
                            <div class="info-label">Attendance Status</div>
                            <div class="info-value">
                                <span class="registration-status <?= strtolower($primaryRegistration['AttendanceStatus'] ?? 'pending') ?>">
                                    <?= htmlspecialchars($primaryRegistration['AttendanceStatus'] ?? 'Pending') ?>
                                </span>
                            </div>
                        </div>
                        <?php if ($primaryRegistration['HealthDate']): ?>
                        <div class="info-card">
                            <div class="info-label">Health Screening Date</div>
                            <div class="info-value"><?= date('F j, Y', strtotime($primaryRegistration['HealthDate'])) ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Donation History -->
            <?php if (!empty($donationDetails)): ?>
            <div class="donor-container">
                <div class="section-header">
                    <h3><i class="fas fa-heartbeat"></i> Donation History</h3>
                    <div style="color: #e74c3c; font-size: 14px; font-weight: 600;">
                        <i class="fas fa-stethoscope"></i> 
                        Complete Medical Records
                    </div>
                </div>
                <div class="section-content">
                    <?php foreach ($donationDetails as $index => $donation): ?>
                    <div style="margin-bottom: 40px; padding: 25px; background: rgba(248, 249, 250, 0.8); border-radius: 15px; border: 1px solid rgba(102, 126, 234, 0.1);">
                        <h5 style="color: #2d3748; font-size: 18px; font-weight: 700; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                            <i class="fas fa-calendar-check"></i>
                            Donation #<?= $index + 1 ?>
                            <?php if ($donation['EventTitle']): ?>
                                - <?= htmlspecialchars($donation['EventTitle']) ?>
                            <?php endif; ?>
                            <?php if ($donation['DonationDate']): ?>
                                <span style="color: #718096; font-size: 14px; font-weight: 500;">
                                    (<?= date('F j, Y', strtotime($donation['DonationDate'])) ?>)
                                </span>
                            <?php endif; ?>
                        </h5>

                        <!-- Medical Information Grid -->
                        <div class="medical-info-grid">
                            <div class="info-card medical">
                                <div class="info-label"><i class="fas fa-tint"></i> Blood Type</div>
                                <div class="info-value large" style="color: #e74c3c;">
                                    <?= htmlspecialchars($donation['DonationBloodType'] ?? 'Not specified') ?>
                                </div>
                            </div>

                            <div class="info-card medical">
                                <div class="info-label"><i class="fas fa-flask"></i> Quantity Collected</div>
                                <div class="info-value with-unit">
                                    <span class="large"><?= htmlspecialchars($donation['DonationQuantity'] ?? 'N/A') ?></span>
                                    <span class="unit">ml</span>
                                </div>
                            </div>

                            <?php if (!empty($donation['Weight'])): ?>
                            <div class="info-card vital-signs">
                                <div class="info-label"><i class="fas fa-weight"></i> Weight</div>
                                <div class="info-value with-unit">
                                    <span class="large"><?= htmlspecialchars($donation['Weight']) ?></span>
                                    <span class="unit">kg</span>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($donation['BloodPressure'])): ?>
                            <div class="info-card vital-signs">
                                <div class="info-label"><i class="fas fa-heartbeat"></i> Blood Pressure</div>
                                <div class="info-value with-unit">
                                    <span class="large"><?= htmlspecialchars($donation['BloodPressure']) ?></span>
                                    <span class="unit">mmHg</span>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($donation['Temperature'])): ?>
                            <div class="info-card vital-signs">
                                <div class="info-label"><i class="fas fa-thermometer-half"></i> Temperature</div>
                                <div class="info-value with-unit">
                                    <span class="large"><?= htmlspecialchars($donation['Temperature']) ?></span>
                                    <span class="unit">°C</span>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($donation['PulseRate'])): ?>
                            <div class="info-card vital-signs">
                                <div class="info-label"><i class="fas fa-heart"></i> Pulse Rate</div>
                                <div class="info-value with-unit">
                                    <span class="large"><?= htmlspecialchars($donation['PulseRate']) ?></span>
                                    <span class="unit">bpm</span>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($donation['HemoglobinLevel'])): ?>
                            <div class="info-card lab-results">
                                <div class="info-label"><i class="fas fa-vial"></i> Hemoglobin Level</div>
                                <div class="info-value with-unit">
                                    <span class="large"><?= htmlspecialchars($donation['HemoglobinLevel']) ?></span>
                                    <span class="unit">g/dL</span>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($donation['PlateletCount'])): ?>
                            <div class="info-card lab-results">
                                <div class="info-label"><i class="fas fa-microscope"></i> Platelet Count</div>
                                <div class="info-value with-unit">
                                    <span class="large"><?= htmlspecialchars($donation['PlateletCount']) ?></span>
                                    <span class="unit">×10³/μL</span>
                                </div>
                            </div>
                            <?php endif; ?>

                            <div class="info-card medical">
                                <div class="info-label"><i class="fas fa-check-circle"></i> Donation Status</div>
                                <div class="info-value">
                                    <span class="registration-status <?= strtolower($donation['DonationStatus'] ?? 'completed') ?>">
                                        <?= htmlspecialchars($donation['DonationStatus'] ?? 'Completed') ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Registration History -->
            <?php if (count($registrationDetails) > 1): ?>
            <div class="donor-container">
                <div class="section-header">
                    <h3><i class="fas fa-history"></i> Registration History</h3>
                    <div style="color: #667eea; font-size: 14px; font-weight: 600;">
                        <i class="fas fa-list"></i> 
                        All Registrations (<?= count($registrationDetails) ?>)
                    </div>
                </div>
                <div class="section-content">
                    <?php foreach ($registrationDetails as $index => $registration): ?>
                    <div class="registration-item">
                        <h6>Registration #<?= $index + 1 ?> - <?= htmlspecialchars($registration['EventTitle'] ?? 'Unknown Event') ?></h6>
                        <p><strong>Date:</strong> <?= date('F j, Y', strtotime($registration['RegistrationDate'])) ?></p>
                        <p><strong>Event Date:</strong> <?= $registration['EventDate'] ? date('F j, Y', strtotime($registration['EventDate'])) : 'TBD' ?></p>
                        <p><strong>Status:</strong> 
                            <span class="registration-status <?= strtolower($registration['RegistrationStatus'] ?? 'registered') ?>">
                                <?= htmlspecialchars($registration['RegistrationStatus'] ?? 'Registered') ?>
                            </span>
                        </p>
                        <p><strong>Attendance:</strong> 
                            <span class="registration-status <?= strtolower($registration['AttendanceStatus'] ?? 'pending') ?>">
                                <?= htmlspecialchars($registration['AttendanceStatus'] ?? 'Pending') ?>
                            </span>
                        </p>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <!-- No donor data or search needed -->
            <div class="donor-container">
                <div class="section-content">
                    <div class="no-data">
                        <i class="fas fa-search"></i>
                        <h3>Search for a Student</h3>
                        <p>Use the search form above to find a student by ID or name, or select from the list of students with donation activity.</p>
                        
                        <!-- Quick access to all students -->
                        <div style="margin-top: 30px;">
                            <h4 style="color: #2d3748; margin-bottom: 15px;">Or browse all students:</h4>
                            <a href="staff_view_donation.php" class="btn btn-outline">
                                <i class="fas fa-list"></i>
                                View All Donations
                            </a>
                        </div>
                    </div>
                </div>
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

        // Add smooth animations for cards
        document.addEventListener('DOMContentLoaded', function() {
            // Animate stat cards
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    card.style.transition = 'all 0.6s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, 100 + (index * 100));
            });

            // Animate info cards
            const infoCards = document.querySelectorAll('.info-card');
            infoCards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    card.style.transition = 'all 0.6s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, 200 + (index * 50));
            });

            // Animate containers
            const containers = document.querySelectorAll('.donor-container');
            containers.forEach((container, index) => {
                container.style.opacity = '0';
                container.style.transform = 'translateY(30px)';
                
                setTimeout(() => {
                    container.style.transition = 'all 0.8s ease';
                    container.style.opacity = '1';
                    container.style.transform = 'translateY(0)';
                }, 300 + (index * 200));
            });

            // Enhanced hover effects for medical cards
            const medicalCards = document.querySelectorAll('.info-card.medical, .info-card.vital-signs, .info-card.lab-results');
            medicalCards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-5px) scale(1.02)';
                    this.style.boxShadow = '0 12px 30px rgba(102, 126, 234, 0.2)';
                });
                
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0) scale(1)';
                    this.style.boxShadow = '0 4px 15px rgba(0, 0, 0, 0.1)';
                });
            });

            // Add click effects to buttons
            document.querySelectorAll('.btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    this.style.transform = 'scale(0.95)';
                    setTimeout(() => {
                        this.style.transform = 'scale(1)';
                    }, 150);
                });
            });

            // Enhance stat card hover effects
            document.querySelectorAll('.stat-card').forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-10px) scale(1.05)';
                    this.style.boxShadow = '0 25px 50px rgba(102, 126, 234, 0.2)';
                });
                
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0) scale(1)';
                    this.style.boxShadow = '0 8px 25px rgba(0, 0, 0, 0.1)';
                });
            });

            console.log('✅ Staff Donor Details Page Loaded Successfully');
            console.log('👤 Viewing donor:', <?php echo json_encode($donorDetails['StudentName'] ?? 'Unknown'); ?>);
            console.log('🆔 Student ID:', <?php echo json_encode($studentId); ?>);
            console.log('📊 Total Donations:', <?php echo json_encode($donationStats['total_donations'] ?? 0); ?>);
            console.log('🩸 Total Blood Donated:', <?php echo json_encode($donationStats['total_quantity'] ?? 0); ?>, 'ml');
            console.log('📋 Total Registrations:', <?php echo json_encode(count($registrationDetails ?? [])); ?>);
            console.log('🏥 Medical Records Available:', <?php echo json_encode(!empty($donationDetails) ? 'Yes' : 'No'); ?>);
        });

        // Print functionality for medical records
        function printDonorDetails() {
            window.print();
        }

        // Export functionality (placeholder for future implementation)
        function exportDonorData() {
            alert('Export functionality will be implemented soon!');
        }

        // Keyboard shortcuts for staff efficiency
        document.addEventListener('keydown', function(e) {
            // Ctrl+P for print
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                printDonorDetails();
            }
            
            // Escape to go back
            if (e.key === 'Escape') {
                window.location.href = 'staff_view_donation.php';
            }
            
            // Ctrl+E for export (future feature)
            if (e.ctrlKey && e.key === 'e') {
                e.preventDefault();
                exportDonorData();
            }
        });

        // Add accessibility features
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
            .keyboard-navigation .btn:focus {
                outline: 3px solid #667eea;
                outline-offset: 2px;
            }
        `;
        document.head.appendChild(keyboardStyle);

        // Performance monitoring
        window.addEventListener('load', function() {
            const loadTime = performance.now();
            console.log(`⚡ Donor Details Page loaded in ${Math.round(loadTime)}ms`);
            
            // Show load complete notification
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
                notification.innerHTML = '✅ Donor Details Loaded!';
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
                }, 2000);
            }, 500);
        });

        // Enhanced debugging and monitoring
        <?php if ($donorDetails): ?>
        console.log('📋 DONOR PROFILE SUMMARY:');
        console.log('==========================================');
        console.log('Name:', <?php echo json_encode($donorDetails['StudentName']); ?>);
        console.log('Student ID:', <?php echo json_encode($donorDetails['StudentID']); ?>);
        console.log('Email:', <?php echo json_encode($donorDetails['StudentEmail'] ?? 'Not provided'); ?>);
        console.log('Phone:', <?php echo json_encode($donorDetails['StudentContact'] ?? 'Not provided'); ?>);
        console.log('Age:', <?php echo json_encode($donorDetails['StudentAge'] ?? 'Not specified'); ?>);
        console.log('Gender:', <?php echo json_encode($donorDetails['StudentGender'] ?? 'Not specified'); ?>);
        console.log('==========================================');
        
        <?php if ($donationStats): ?>
        console.log('📊 DONATION STATISTICS:');
        console.log('Total Donations:', <?php echo json_encode($donationStats['total_donations']); ?>);
        console.log('Completed Donations:', <?php echo json_encode($donationStats['completed_donations']); ?>);
        console.log('Total Quantity:', <?php echo json_encode($donationStats['total_quantity']); ?>, 'ml');
        console.log('First Donation:', <?php echo json_encode($donationStats['first_donation_date']); ?>);
        console.log('Last Donation:', <?php echo json_encode($donationStats['last_donation_date']); ?>);
        console.log('Events Participated:', <?php echo json_encode($donationStats['events_participated']); ?>);
        console.log('Events Attended:', <?php echo json_encode($donationStats['events_attended']); ?>);
        console.log('==========================================');
        <?php endif; ?>
        
        <?php if ($primaryRegistration): ?>
        console.log('📋 CURRENT REGISTRATION:');
        console.log('Registration ID:', <?php echo json_encode($primaryRegistration['RegistrationID']); ?>);
        console.log('Registration Status:', <?php echo json_encode($primaryRegistration['RegistrationStatus']); ?>);
        console.log('Attendance Status:', <?php echo json_encode($primaryRegistration['AttendanceStatus']); ?>);
        console.log('Health Status:', <?php echo json_encode($primaryRegistration['HealthStatus']); ?>);
        console.log('Event:', <?php echo json_encode($primaryRegistration['EventTitle']); ?>);
        console.log('Event Date:', <?php echo json_encode($primaryRegistration['EventDate']); ?>);
        console.log('==========================================');
        <?php endif; ?>
        
        <?php if (!empty($donationDetails)): ?>
        console.log('🩺 MEDICAL RECORDS:');
        console.log('Number of Donation Records:', <?php echo json_encode(count($donationDetails)); ?>);
        <?php foreach ($donationDetails as $index => $donation): ?>
        console.log('Donation #<?= $index + 1 ?>:');
        console.log('  - Blood Type:', <?php echo json_encode($donation['DonationBloodType'] ?? 'Not specified'); ?>);
        console.log('  - Quantity:', <?php echo json_encode($donation['DonationQuantity'] ?? 'N/A'); ?>, 'ml');
        console.log('  - Date:', <?php echo json_encode($donation['DonationDate'] ?? 'N/A'); ?>);
        console.log('  - Status:', <?php echo json_encode($donation['DonationStatus'] ?? 'Unknown'); ?>);
        <?php if (!empty($donation['Weight'])): ?>
        console.log('  - Weight:', <?php echo json_encode($donation['Weight']); ?>, 'kg');
        <?php endif; ?>
        <?php if (!empty($donation['BloodPressure'])): ?>
        console.log('  - Blood Pressure:', <?php echo json_encode($donation['BloodPressure']); ?>, 'mmHg');
        <?php endif; ?>
        <?php if (!empty($donation['HemoglobinLevel'])): ?>
        console.log('  - Hemoglobin:', <?php echo json_encode($donation['HemoglobinLevel']); ?>, 'g/dL');
        <?php endif; ?>
        <?php endforeach; ?>
        console.log('==========================================');
        <?php endif; ?>
        
        console.log('🏥 Staff Access - Medical data viewing authorized');
        console.log('🔐 Privacy Notice: All medical data is confidential and protected');
        console.log('📱 Interface: Responsive design with mobile support');
        console.log('🎨 UI Features: Animated cards, hover effects, keyboard shortcuts');
        console.log('⌨️ Shortcuts: Ctrl+P (Print), Escape (Back), Ctrl+E (Export)');
        <?php endif; ?>
    </script>
</body>
</html>