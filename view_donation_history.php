<?php
// ================================================================
// PHP LOGIC SECTION
// ================================================================

session_start();

// Authentication check
if (!isset($_SESSION['student_id'])) {
    header("Location: student_login.php");
    exit;
}

// Initialize variables
$donationHistory = [];
$totalDonations = 0;
$totalVolume = 0;
$student = null;
$filterYear = $_GET['year'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$searchTerm = $_GET['search'] ?? '';
$availableYears = [];

try {
    // Database connection
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

    // Build main query - retrieve donations based on StudentID via registration table
    $sql = "
        SELECT 
            d.DonationID,
            d.DonationDate,
            d.DonationBloodType,
            d.DonationQuantity,
            d.DonationStatus,
            d.Weight,
            d.BloodPressure,
            d.Temperature,
            d.PulseRate,
            d.HemoglobinLevel,
            d.PlateletCount,
            r.RegistrationID,
            r.EventID,
            e.EventTitle,
            e.EventDate
        FROM registration r
        INNER JOIN donation d ON r.RegistrationID = d.RegistrationID
        LEFT JOIN event e ON r.EventID = e.EventID
        WHERE r.StudentID = ?
    ";
    
    $params = [$_SESSION['student_id']];
    
    // Apply filters
    if (!empty($filterYear)) {
        $sql .= " AND YEAR(d.DonationDate) = ?";
        $params[] = $filterYear;
    }
    
    if (!empty($filterStatus)) {
        $sql .= " AND LOWER(TRIM(d.DonationStatus)) = LOWER(TRIM(?))";
        $params[] = $filterStatus;
    }
    
    if (!empty($searchTerm)) {
        $sql .= " AND (e.EventTitle LIKE ? OR d.DonationID LIKE ? OR CAST(d.DonationID AS CHAR) LIKE ?)";
        $params[] = "%$searchTerm%";
        $params[] = "%$searchTerm%";
        $params[] = "%$searchTerm%";
    }
    
    // Order by DonationDate (newest first)
    $sql .= " ORDER BY d.DonationDate DESC, d.DonationID DESC";
    
    // Execute main query
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $donationHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate statistics for all donations (not filtered)
    $statsStmt = $pdo->prepare("
        SELECT 
            COUNT(d.DonationID) as total_donations,
            SUM(CASE WHEN d.DonationQuantity IS NOT NULL AND d.DonationQuantity > 0 
                THEN d.DonationQuantity ELSE 0 END) as total_volume
        FROM registration r
        INNER JOIN donation d ON r.RegistrationID = d.RegistrationID
        WHERE r.StudentID = ?
    ");
    $statsStmt->execute([$_SESSION['student_id']]);
    $stats = $statsStmt->fetch();
    $totalDonations = $stats['total_donations'] ?? 0;
    $totalVolume = $stats['total_volume'] ?? 0;

    // Get available years for filter dropdown
    $yearsStmt = $pdo->prepare("
        SELECT DISTINCT YEAR(d.DonationDate) as year
        FROM registration r
        INNER JOIN donation d ON r.RegistrationID = d.RegistrationID
        WHERE r.StudentID = ? AND d.DonationDate IS NOT NULL
        ORDER BY year DESC
    ");
    $yearsStmt->execute([$_SESSION['student_id']]);
    $availableYears = $yearsStmt->fetchAll(PDO::FETCH_COLUMN);

} catch (Exception $e) {
    error_log("Database error in view_donation_history.php: " . $e->getMessage());
    $donationHistory = [];
    $availableYears = [];
}

// Helper functions
function getDonorStatus($totalDonations) {
    if ($totalDonations >= 10) return "Diamond";
    elseif ($totalDonations >= 8) return "Platinum";
    elseif ($totalDonations >= 6) return "Gold";
    elseif ($totalDonations >= 4) return "Silver";
    elseif ($totalDonations >= 2) return "Bronze";
    else return "Beginner";
}

function getStatusIcon($status) {
    switch(strtolower($status)) {
        case 'completed': return '<i class="fas fa-check"></i> Completed';
        case 'pending': return '<i class="fas fa-clock"></i> Pending';
        case 'processing': return '<i class="fas fa-spinner"></i> Processing';
        case 'cancelled': return '<i class="fas fa-times"></i> Cancelled';
        default: return '<i class="fas fa-check"></i> ' . htmlspecialchars($status);
    }
}

function getNextMilestone($totalDonations) {
    $milestones = [2, 4, 6, 8, 10];
    foreach ($milestones as $milestone) {
        if ($totalDonations < $milestone) {
            return $milestone;
        }
    }
    return null;
}

// Calculate achievement data
$lastDonation = !empty($donationHistory) ? $donationHistory[0]['DonationDate'] : null;
$daysSinceLastDonation = $lastDonation ? (new DateTime())->diff(new DateTime($lastDonation))->days : null;
$thisYearDonations = array_filter($donationHistory, function($donation) {
    return $donation['DonationDate'] && date('Y', strtotime($donation['DonationDate'])) == date('Y');
});
$avgVolume = $totalDonations > 0 ? round($totalVolume / $totalDonations) : 0;
$nextMilestone = getNextMilestone($totalDonations);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Donation History - LifeSaver Hub</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- ================================================================ -->
    <!-- CSS STYLES SECTION -->
    <!-- ================================================================ -->
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
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(0, 0, 0, 0.05);
            margin-bottom: 32px;
        }

        .section-header {
            padding: 30px 35px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.08);
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.08), rgba(139, 92, 246, 0.08));
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .section-header h3 {
            color: #2d3748;
            font-size: 1.8rem;
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .section-header h3 i {
            color: #667eea;
        }

        .section-content {
            padding: 35px;
        }

        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 25px;
            margin-bottom: 35px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 30px 25px;
            text-align: center;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(0, 0, 0, 0.05);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, transparent, #667eea, transparent);
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-10px) scale(1.05);
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15);
            background: rgba(255, 255, 255, 0.95);
        }

        .stat-card:hover::before {
            height: 5px;
            background: linear-gradient(90deg, #667eea, #764ba2, #667eea);
        }

        .stat-icon {
            font-size: 3rem;
            margin-bottom: 20px;
            color: #667eea;
            filter: drop-shadow(0 4px 8px rgba(102, 126, 234, 0.3));
            animation: float 3s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 10px;
            color: #2d3748;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            animation: countUp 1s ease-out;
            display: block;
        }

        @keyframes countUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .stat-label {
            color: #4a5568;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 2px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .stat-detail {
            color: #718096;
            font-size: 12px;
            opacity: 0.8;
        }

        /* Filter Section */
        .filters-section {
            background: rgba(248, 249, 250, 0.8);
            border-radius: 20px;
            padding: 25px 30px;
            margin-bottom: 30px;
            border: 1px solid rgba(102, 126, 234, 0.1);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.05);
        }

        .filters-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            color: #2d3748;
            font-weight: 700;
            font-size: 16px;
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .filter-label {
            color: #4a5568;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .filter-input {
            padding: 12px 15px;
            border: 2px solid rgba(102, 126, 234, 0.15);
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.9);
            color: #2d3748;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .filter-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            background: white;
        }

        .filter-btn {
            padding: 12px 25px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            justify-content: center;
        }

        .filter-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
        }

        .clear-btn {
            background: linear-gradient(135deg, #6c757d, #5a6268);
            padding: 12px 20px;
        }

        .clear-btn:hover {
            background: linear-gradient(135deg, #5a6268, #495057);
            box-shadow: 0 8px 25px rgba(108, 117, 125, 0.3);
        }

        /* Table Styles */
        .table-container {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .table-responsive {
            overflow-x: auto;
        }

        .donations-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .donations-table th {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(139, 92, 246, 0.1));
            color: #2d3748;
            font-weight: 700;
            padding: 20px 15px;
            text-align: left;
            border-bottom: 2px solid rgba(102, 126, 234, 0.1);
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .donations-table td {
            padding: 18px 15px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            vertical-align: top;
        }

        .donations-table tbody tr {
            transition: all 0.3s ease;
        }

        .donations-table tbody tr:hover {
            background: rgba(102, 126, 234, 0.03);
            transform: scale(1.005);
        }

        /* Status Badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .status-completed {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }

        .status-pending {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
        }

        .status-cancelled {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }

        .status-processing {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
        }

        /* Blood Type Badge */
        .blood-type {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 45px;
            height: 35px;
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            color: white;
            font-weight: 800;
            font-size: 13px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(231, 76, 60, 0.3);
        }

        /* Volume Badge */
        .volume-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            color: #667eea;
            font-weight: 600;
        }

        /* Medical Info */
        .medical-value {
            font-weight: 600;
            color: #2d3748;
        }

        .medical-unit {
            font-size: 11px;
            color: #718096;
            font-weight: 500;
        }

        .empty-state {
            text-align: center;
            padding: 80px 30px;
            color: #4a5568;
        }

        .empty-state i {
            font-size: 5rem;
            margin-bottom: 25px;
            opacity: 0.4;
            color: #667eea;
        }

        .empty-state h3 {
            font-size: 1.8rem;
            margin-bottom: 15px;
            color: #2d3748;
            font-weight: 700;
        }

        .empty-state p {
            font-size: 16px;
            margin-bottom: 30px;
            opacity: 0.8;
        }

        .action-btn {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            padding: 15px 30px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            text-decoration: none;
            border-radius: 50px;
            font-weight: 700;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 14px;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
        }

        .action-btn:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 15px 35px rgba(102, 126, 234, 0.6);
            color: white;
            text-decoration: none;
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

        /* Results Info */
        .results-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding: 15px 20px;
            background: rgba(102, 126, 234, 0.05);
            border-radius: 12px;
            border: 1px solid rgba(102, 126, 234, 0.1);
        }

        .results-count {
            color: #2d3748;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .export-btn {
            padding: 8px 16px;
            background: rgba(102, 126, 234, 0.1);
            color: #667eea;
            border: 1px solid rgba(102, 126, 234, 0.2);
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .export-btn:hover {
            background: rgba(102, 126, 234, 0.15);
            color: #4c51bf;
            transform: translateY(-1px);
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
                font-size: 2.2rem;
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            .section-header {
                padding: 25px 30px;
                flex-direction: column;
                align-items: flex-start;
            }
            .section-content {
                padding: 25px;
            }
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 20px;
            }

            .filters-grid {
                grid-template-columns: 1fr;
            }

            .results-info {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }

            /* Mobile table styling */
            .table-responsive {
                font-size: 12px;
            }

            .donations-table th,
            .donations-table td {
                padding: 12px 8px;
            }

            /* Hide less important columns on mobile */
            .hide-mobile {
                display: none;
            }
        }

        @media (max-width: 480px) {
            .main-content {
                padding: 20px 12px;
            }
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .donations-table {
                font-size: 11px;
            }

            .donations-table th,
            .donations-table td {
                padding: 10px 6px;
            }
        }

        /* Loading Animation */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(102, 126, 234, 0.3);
            border-radius: 50%;
            border-top-color: #667eea;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Highlight Search Terms */
        .highlight {
            background: rgba(255, 235, 59, 0.3);
            padding: 2px 4px;
            border-radius: 4px;
        }
    </style>
</head>

<!-- ================================================================ -->
<!-- HTML CONTENT SECTION -->
<!-- ================================================================ -->
<body>
    <button class="mobile-menu-btn" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>

    <div class="app-container">
        <!-- Sidebar Navigation -->
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
                        <a href="student_view_donation.php" class="nav-item">
                            <i class="fas fa-tint"></i>
                            <span>Donations</span>
                        </a>
                        <a href="view_donation_history.php" class="nav-item active">
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

        <!-- Main Content -->
        <main class="main-content">
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-header-content">
                    <h1><i class="fas fa-history"></i> My Donation History</h1>
                    <p>Track your blood donation journey and detailed medical records</p>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-tint"></i>
                    </div>
                    <span class="stat-number"><?= $totalDonations ?></span>
                    <div class="stat-label">Total Donations</div>
                    <div class="stat-detail">Completed successfully</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-flask"></i>
                    </div>
                    <span class="stat-number"><?= number_format($totalVolume) ?></span>
                    <div class="stat-label">Total Volume (ml)</div>
                    <div class="stat-detail">Blood donated</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-heart"></i>
                    </div>
                    <span class="stat-number"><?= $totalDonations * 3 ?></span>
                    <div class="stat-label">Lives Potentially Saved</div>
                    <div class="stat-detail">Estimated impact</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-award"></i>
                    </div>
                    <span class="stat-number"><?= getDonorStatus($totalDonations) ?></span>
                    <div class="stat-label">Donor Status</div>
                    <div class="stat-detail">Your level</div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filters-section">
                <div class="filters-header">
                    <i class="fas fa-filter"></i>
                    <span>Filter & Search</span>
                </div>
                <form method="GET" action="" class="filters-grid">
                    <div class="filter-group">
                        <label class="filter-label" for="search">Search</label>
                        <input type="text" 
                               id="search" 
                               name="search" 
                               class="filter-input" 
                               placeholder="Search by event title or donation ID..."
                               value="<?= htmlspecialchars($searchTerm) ?>">
                    </div>
                    <div class="filter-group">
                        <label class="filter-label" for="year">Year</label>
                        <select id="year" name="year" class="filter-input">
                            <option value="">All Years</option>
                            <?php foreach ($availableYears as $year): ?>
                                <option value="<?= $year ?>" <?= $filterYear == $year ? 'selected' : '' ?>>
                                    <?= $year ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label class="filter-label" for="status">Status</label>
                        <select id="status" name="status" class="filter-input">
                            <option value="">All Statuses</option>
                            <option value="completed" <?= $filterStatus == 'completed' ? 'selected' : '' ?>>Completed</option>
                            <option value="pending" <?= $filterStatus == 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="processing" <?= $filterStatus == 'processing' ? 'selected' : '' ?>>Processing</option>
                            <option value="cancelled" <?= $filterStatus == 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <button type="submit" class="filter-btn">
                            <i class="fas fa-search"></i>
                            Apply Filters
                        </button>
                    </div>
                    <div class="filter-group">
                        <a href="view_donation_history.php" class="filter-btn clear-btn">
                            <i class="fas fa-times"></i>
                            Clear All
                        </a>
                    </div>
                </form>
            </div>

            <!-- Donation History Table -->
            <div class="donations-container">
                <div class="section-header">
                    <h3><i class="fas fa-list-alt"></i> Donation Records</h3>
                    <?php if (!empty($donationHistory)): ?>
                    <div style="color: #4a5568; font-size: 14px; font-weight: 500;">
                        <i class="fas fa-info-circle"></i> 
                        Showing <?= count($donationHistory) ?> donation<?= count($donationHistory) !== 1 ? 's' : '' ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="section-content">
                    <?php if (empty($donationHistory)): ?>
                        <!-- Empty State -->
                        <div class="empty-state">
                            <i class="fas fa-history"></i>
                            <h3>
                                <?php if (!empty($filterYear) || !empty($filterStatus) || !empty($searchTerm)): ?>
                                    No Results Found
                                <?php else: ?>
                                    No Donation History
                                <?php endif; ?>
                            </h3>
                            <p>
                                <?php if (!empty($filterYear) || !empty($filterStatus) || !empty($searchTerm)): ?>
                                    No donations match your current filters. Try adjusting your search criteria.
                                <?php else: ?>
                                    You haven't completed any blood donations yet. Start your journey by registering for a donation event!
                                <?php endif; ?>
                            </p>
                            <?php if (empty($filterYear) && empty($filterStatus) && empty($searchTerm)): ?>
                            <a href="student_view_donation.php" class="action-btn">
                                <i class="fas fa-plus"></i>
                                Register for Donation
                            </a>
                            <?php else: ?>
                            <a href="view_donation_history.php" class="action-btn">
                                <i class="fas fa-list"></i>
                                View All Donations
                            </a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <!-- Results Info -->
                        <div class="results-info">
                            <div class="results-count">
                                <i class="fas fa-list"></i>
                                <span>
                                    Showing <?= count($donationHistory) ?> of <?= $totalDonations ?> total donations
                                    <?php if (!empty($filterYear) || !empty($filterStatus) || !empty($searchTerm)): ?>
                                        (filtered)
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>

                        <!-- Donations Table -->
                        <div class="table-container">
                            <div class="table-responsive">
                                <table class="donations-table">
                                    <thead>
                                        <tr>
                                            <th>Donation Date</th>
                                            <th>Blood Type</th>
                                            <th>Quantity (ml)</th>
                                            <th>Status</th>
                                            <th class="hide-mobile">Weight (kg)</th>
                                            <th class="hide-mobile">Blood Pressure</th>
                                            <th class="hide-mobile">Temperature (°C)</th>
                                            <th class="hide-mobile">Pulse Rate (bpm)</th>
                                            <th class="hide-mobile">Hemoglobin (g/dL)</th>
                                            <th class="hide-mobile">Platelet Count</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($donationHistory as $donation): ?>
                                        <tr>
                                            <!-- Donation Date -->
                                            <td>
                                                <?php if (!empty($donation['DonationDate'])): ?>
                                                    <div class="medical-value">
                                                        <?= date('M j, Y', strtotime($donation['DonationDate'])) ?>
                                                    </div>
                                                    <div style="font-size: 12px; color: #718096;">
                                                        <?= date('l', strtotime($donation['DonationDate'])) ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span style="color: #718096; font-style: italic;">No date</span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Blood Type -->
                                            <td>
                                                <?php if (!empty($donation['DonationBloodType'])): ?>
                                                    <span class="blood-type"><?= htmlspecialchars($donation['DonationBloodType']) ?></span>
                                                <?php else: ?>
                                                    <span style="color: #718096; font-style: italic;">Not specified</span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Quantity -->
                                            <td>
                                                <?php if (!empty($donation['DonationQuantity'])): ?>
                                                    <div class="volume-badge">
                                                        <i class="fas fa-flask"></i>
                                                        <span class="medical-value"><?= number_format($donation['DonationQuantity']) ?></span>
                                                    </div>
                                                <?php else: ?>
                                                    <span style="color: #718096;">N/A</span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Status -->
                                            <td>
                                                <span class="status-badge status-<?= strtolower($donation['DonationStatus'] ?? 'completed') ?>">
                                                    <?= getStatusIcon($donation['DonationStatus'] ?? 'completed') ?>
                                                </span>
                                            </td>

                                            <!-- Weight -->
                                            <td class="hide-mobile">
                                                <?php if (!empty($donation['Weight'])): ?>
                                                    <span class="medical-value"><?= htmlspecialchars($donation['Weight']) ?></span>
                                                <?php else: ?>
                                                    <span style="color: #718096;">N/A</span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Blood Pressure -->
                                            <td class="hide-mobile">
                                                <?php if (!empty($donation['BloodPressure'])): ?>
                                                    <span class="medical-value"><?= htmlspecialchars($donation['BloodPressure']) ?></span>
                                                    <div class="medical-unit">mmHg</div>
                                                <?php else: ?>
                                                    <span style="color: #718096;">N/A</span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Temperature -->
                                            <td class="hide-mobile">
                                                <?php if (!empty($donation['Temperature'])): ?>
                                                    <span class="medical-value"><?= htmlspecialchars($donation['Temperature']) ?></span>
                                                <?php else: ?>
                                                    <span style="color: #718096;">N/A</span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Pulse Rate -->
                                            <td class="hide-mobile">
                                                <?php if (!empty($donation['PulseRate'])): ?>
                                                    <span class="medical-value"><?= htmlspecialchars($donation['PulseRate']) ?></span>
                                                <?php else: ?>
                                                    <span style="color: #718096;">N/A</span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Hemoglobin Level -->
                                            <td class="hide-mobile">
                                                <?php if (!empty($donation['HemoglobinLevel'])): ?>
                                                    <span class="medical-value"><?= htmlspecialchars($donation['HemoglobinLevel']) ?></span>
                                                <?php else: ?>
                                                    <span style="color: #718096;">N/A</span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Platelet Count -->
                                            <td class="hide-mobile">
                                                <?php if (!empty($donation['PlateletCount'])): ?>
                                                    <span class="medical-value"><?= htmlspecialchars($donation['PlateletCount']) ?></span>
                                                    <div class="medical-unit">×10³/μL</div>
                                                <?php else: ?>
                                                    <span style="color: #718096;">N/A</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Data Source Information -->
                        <div style="margin-top: 25px; padding: 20px; background: rgba(102, 126, 234, 0.05); border-radius: 12px; border: 1px solid rgba(102, 126, 234, 0.1);">
                            <h5 style="color: #2d3748; font-size: 16px; font-weight: 700; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                                <i class="fas fa-database"></i> Data Retrieval Information
                            </h5>
                            <div style="color: #4a5568; font-size: 14px; line-height: 1.6;">
                                <p style="margin-bottom: 8px;">• <strong>Student ID:</strong> <?= htmlspecialchars($_SESSION['student_id']) ?></p>
                                <p style="margin-bottom: 8px;">• <strong>Data Source:</strong> registration table → donation table (via RegistrationID)</p>
                                <p style="margin-bottom: 8px;">• <strong>Security:</strong> Only your donations are shown based on your StudentID</p>
                                <p style="margin-bottom: 8px;">• <strong>Sort Order:</strong> Newest donations first (by DonationDate)</p>
                                <p style="margin-bottom: 0;">• <strong>Fields Shown:</strong> DonationDate, DonationBloodType, DonationQuantity, DonationStatus, Weight, BloodPressure, Temperature, PulseRate, HemoglobinLevel, PlateletCount</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Achievement Section -->
            <?php if (!empty($donationHistory)): ?>
            <div class="donations-container">
                <div class="section-header">
                    <h3><i class="fas fa-trophy"></i> Your Achievements</h3>
                </div>
                <div class="section-content">
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fas fa-medal"></i>
                            </div>
                            <span class="stat-number"><?= $daysSinceLastDonation ?? 'N/A' ?></span>
                            <div class="stat-label">Days Since Last Donation</div>
                            <div class="stat-detail">
                                <?php if ($lastDonation): ?>
                                    Last donated on <?= date('M d, Y', strtotime($lastDonation)) ?>
                                <?php else: ?>
                                    No donations yet
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fas fa-calendar-check"></i>
                            </div>
                            <span class="stat-number"><?= count($thisYearDonations) ?></span>
                            <div class="stat-label">Donations This Year</div>
                            <div class="stat-detail"><?= date('Y') ?> contributions</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <span class="stat-number"><?= $avgVolume ?></span>
                            <div class="stat-label">Average Volume (ml)</div>
                            <div class="stat-detail">Per donation</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fas fa-star"></i>
                            </div>
                            <span class="stat-number"><?= $nextMilestone ? ($nextMilestone - $totalDonations) : '✓' ?></span>
                            <div class="stat-label">
                                <?php if ($nextMilestone): ?>
                                    To Next Milestone
                                <?php else: ?>
                                    All Milestones Achieved!
                                <?php endif; ?>
                            </div>
                            <div class="stat-detail">
                                <?php if ($nextMilestone): ?>
                                    Next: <?= $nextMilestone ?> donations
                                <?php else: ?>
                                    Congratulations!
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- ================================================================ -->
    <!-- JAVASCRIPT SECTION -->
    <!-- ================================================================ -->
    <script>
        // Sidebar functionality
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

        // Export to CSV functionality
        function exportToCSV() {
            const table = document.querySelector('.donations-table');
            const rows = Array.from(table.querySelectorAll('tr'));
            
            const csvContent = rows.map(row => {
                const cells = Array.from(row.querySelectorAll('th, td'));
                return cells.map(cell => {
                    let text = cell.textContent.trim();
                    text = text.replace(/\s+/g, ' ');
                    if (text.includes(',') || text.includes('"')) {
                        text = '"' + text.replace(/"/g, '""') + '"';
                    }
                    return text;
                }).join(',');
            }).join('\n');

            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', 'donation_history_<?= date('Y-m-d') ?>.csv');
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        // Animation functions
        function animateTableRows() {
            const rows = document.querySelectorAll('.donations-table tbody tr');
            rows.forEach((row, index) => {
                row.style.opacity = '0';
                row.style.transform = 'translateX(-20px)';
                
                setTimeout(() => {
                    row.style.transition = 'all 0.5s ease';
                    row.style.opacity = '1';
                    row.style.transform = 'translateX(0)';
                }, 100 + (index * 50));
            });
        }

        function animateNumbers() {
            const numbers = document.querySelectorAll('.stat-card .stat-number');
            numbers.forEach(number => {
                const text = number.textContent.trim();
                if (!isNaN(text) && text !== '') {
                    const finalValue = parseInt(text);
                    let currentValue = 0;
                    const increment = finalValue / 30;
                    
                    const timer = setInterval(() => {
                        currentValue += increment;
                        if (currentValue >= finalValue) {
                            number.textContent = finalValue;
                            clearInterval(timer);
                        } else {
                            number.textContent = Math.floor(currentValue);
                        }
                    }, 50);
                }
            });
        }

        function addTableInteractivity() {
            document.querySelectorAll('.donations-table tbody tr').forEach(row => {
                row.addEventListener('click', function() {
                    document.querySelectorAll('.donations-table tbody tr').forEach(r => r.classList.remove('selected'));
                    this.classList.add('selected');
                });
            });
        }

        function highlightSearchTerms() {
            const searchTerm = '<?= htmlspecialchars($searchTerm) ?>';
            if (!searchTerm) return;

            const cells = document.querySelectorAll('.donations-table td');
            cells.forEach(cell => {
                if (cell.textContent.toLowerCase().includes(searchTerm.toLowerCase())) {
                    const regex = new RegExp(`(${searchTerm})`, 'gi');
                    cell.innerHTML = cell.innerHTML.replace(regex, '<span class="highlight">$1</span>');
                }
            });
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            animateTableRows();
            animateNumbers();
            addTableInteractivity();
            highlightSearchTerms();
        });

        // Add CSS for row selection
        const style = document.createElement('style');
        style.textContent = `
            .donations-table tbody tr.selected {
                background: rgba(102, 126, 234, 0.1) !important;
                border-left: 4px solid #667eea;
            }
            .donations-table tbody tr {
                cursor: pointer;
            }
        `;
        document.head.appendChild(style);

        // Console logging for debugging
        console.log('🩸 LifeSaver Hub - Clean Structured Donation History');
        console.log('=== DATA RETRIEVAL VERIFICATION ===');
        console.log('Student ID:', <?= json_encode($_SESSION['student_id']) ?>);
        console.log('Student Name:', <?= json_encode($student['StudentName'] ?? 'Unknown') ?>);
        console.log('Total Donations:', <?= json_encode($totalDonations) ?>);
        console.log('Displayed Results:', <?= json_encode(count($donationHistory)) ?>);
        console.log('=== FILE STRUCTURE ===');
        console.log('✅ PHP Logic Section: Variables, DB queries, helper functions');
        console.log('✅ CSS Styles Section: All styling organized');
        console.log('✅ HTML Content Section: Clean semantic markup');
        console.log('✅ JavaScript Section: All interactions and animations');
        console.log('=== FEATURES ACTIVE ===');
        console.log('✅ Student-specific data via registration → donation JOIN');
        console.log('✅ Required fields displayed in organized table');
        console.log('✅ Newest donations first (DonationDate DESC)');
        console.log('✅ Clean file structure with proper separation of concerns');
    </script>
</body>
</html>