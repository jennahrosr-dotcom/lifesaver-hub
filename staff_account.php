<?php
session_start();

if (!isset($_SESSION['staff_id'])) {
    header("Location: staff_login.php");
    exit;
}

$pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$stmt = $pdo->prepare("SELECT * FROM staff WHERE StaffID = ?");
$stmt->execute([$_SESSION['staff_id']]);
$staff = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Account - LifeSaver Hub</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body {
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f4f6fa;
        }
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 250px;
            height: 100vh;
            background-color: #1d3557;
            color: white;
            padding-top: 30px;
        }
        .sidebar h2 {
            text-align: center;
            margin-bottom: 30px;
        }
        .sidebar a {
            display: block;
            color: white;
            padding: 15px 20px;
            text-decoration: none;
            font-size: 16px;
            transition: background 0.3s;
        }
        .sidebar a:hover {
            background-color: #457b9d;
        }
        .sidebar a.active {
            background-color: #457b9d;
        }
        .main {
            margin-left: 250px;
            padding: 30px;
        }
        .account-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .account-header {
            background: linear-gradient(135deg, #1d3557, #457b9d);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .account-header h2 {
            margin: 0;
            font-size: 28px;
        }
        .account-header p {
            margin: 10px 0 0 0;
            opacity: 0.9;
        }
        .account-details {
            padding: 30px;
        }
        .detail-row {
            display: flex;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid #eee;
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-icon {
            width: 50px;
            text-align: center;
            color: #1d3557;
            font-size: 18px;
        }
        .detail-label {
            font-weight: bold;
            color: #1d3557;
            width: 120px;
        }
        .detail-value {
            flex: 1;
            color: #333;
            font-size: 16px;
        }
        .password-value {
            font-family: monospace;
            background-color: #f8f9fa;
            padding: 5px 10px;
            border-radius: 5px;
        }
        .action-buttons {
            padding: 30px;
            text-align: center;
            background-color: #f8f9fa;
        }
        .btn {
            display: inline-block;
            padding: 12px 30px;
            margin: 0 10px;
            text-decoration: none;
            border-radius: 25px;
            font-weight: bold;
            transition: all 0.3s;
            cursor: pointer;
            border: none;
            font-size: 16px;
        }
        .btn-primary {
            background-color: #d62828;
            color: white;
        }
        .btn-primary:hover {
            background-color: #a61d1d;
            transform: translateY(-2px);
        }
        .btn-secondary {
            background-color: #6c757d;
            color: white;
        }
        .btn-secondary:hover {
            background-color: #545b62;
            transform: translateY(-2px);
        }
        .staff-badge {
            display: inline-block;
            background-color: rgba(255,255,255,0.2);
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 14px;
            margin-top: 10px;
        }
    </style>
</head>
<body>
<div class="sidebar">
    <h2>LifeSaver Hub</h2>
    <a href="staff_account.php" class="active"><i class="fas fa-user"></i> My Account</a>
    <a href="create_event.php"><i class="fas fa-calendar-plus"></i> Create Event</a>
    <a href="view_event.php"><i class="fas fa-calendar"></i> View Events</a>
    <a href="view_donation.php"><i class="fas fa-hand-holding-heart"></i> View Donations</a>
    <a href="confirm_attendance.php"><i class="fas fa-check"></i> Confirm Attendance</a>
    <a href="update_application.php"><i class="fas fa-sync"></i> Update Application</a>
    <a href="create_reward.php"><i class="fas fa-gift"></i> Create Rewards</a>
    <a href="generate_report.php"><i class="fas fa-chart-line"></i> Generate Report</a>
    <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
</div>
<div class="main">
    <div class="account-container">
        <div class="account-header">
            <h2><i class="fas fa-user-circle"></i> My Account</h2>
            <p>View and manage your account information</p>
            <div class="staff-badge">
                <i class="fas fa-id-badge"></i> Staff ID: <?= htmlspecialchars($staff['StaffID']) ?>
            </div>
        </div>
        
        <div class="account-details">
            <div class="detail-row">
                <div class="detail-icon">
                    <i class="fas fa-user"></i>
                </div>
                <div class="detail-label">Full Name:</div>
                <div class="detail-value"><?= htmlspecialchars($staff['StaffName']) ?></div>
            </div>
            
            <div class="detail-row">
                <div class="detail-icon">
                    <i class="fas fa-envelope"></i>
                </div>
                <div class="detail-label">Email:</div>
                <div class="detail-value"><?= htmlspecialchars($staff['StaffEmail']) ?></div>
            </div>
            
            <div class="detail-row">
                <div class="detail-icon">
                    <i class="fas fa-phone"></i>
                </div>
                <div class="detail-label">Contact:</div>
                <div class="detail-value"><?= htmlspecialchars($staff['StaffContact']) ?></div>
            </div>
            
            <div class="detail-row">
                <div class="detail-icon">
                    <i class="fas fa-key"></i>
                </div>
                <div class="detail-label">Password:</div>
                <div class="detail-value">
                    <span class="password-value">••••••••</span>
                </div>
            </div>
        </div>
        
        <div class="action-buttons">
            <a href="update_staff_account.php" class="btn btn-primary">
                <i class="fas fa-edit"></i> Update Account
            </a>
            <a href="view_event.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
    </div>
</div>
</body>
</html>