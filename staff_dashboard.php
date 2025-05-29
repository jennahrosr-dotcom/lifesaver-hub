<?php
session_start();

if (!isset($_SESSION['staff_id'])) {
    header("Location: staff_login.php");
    exit;
}

$pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

// Fetch staff data
$stmt = $pdo->prepare("SELECT * FROM staff WHERE StaffID = ?");
$stmt->execute([$_SESSION['staff_id']]);
$staff = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Staff Dashboard - LifeSaver Hub</title>
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
        .main {
            margin-left: 250px;
            padding: 30px;
        }
        h1 {
            color: #1d3557;
        }
        .card {
            background: white;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .card h3 {
            color: #1d3557;
            margin-bottom: 10px;
        }
        .card a.btn {
            display: inline-block;
            margin: 5px 10px 0 0;
            padding: 10px 20px;
            background-color: #6a0dad;
            color: white;
            border-radius: 8px;
            text-decoration: none;
            font-weight: bold;
        }
        .card a.btn:hover {
            background-color: #580ea3;
        }
    </style>
</head>
<body>
<div class="sidebar">
    <h2>LifeSaver Hub</h2>
    <a href="staff_account.php"><i class="fas fa-user"></i> My Account</a>
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
    <h1>Welcome, <?= htmlspecialchars($staff['StaffName']) ?>!</h1>

    <div class="card">
        <h3>Account Management</h3>
        <a href="staff_account.php" class="btn">View / Update Account</a>
    </div>

    <div class="card">
        <h3>Event Management</h3>
        <a href="create_event.php" class="btn">Create Event</a>
        <a href="view_event.php" class="btn">View Events</a>
        <a href="update_event.php" class="btn">Update Event</a>
        <a href="delete_event.php" class="btn">Delete Event</a>
    </div>

    <div class="card">
        <h3>Donation Management</h3>
        <a href="view_donation.php" class="btn">View Donations</a>
        <a href="confirm_attendance.php" class="btn">Confirm Donor Attendance</a>
        <a href="update_application.php" class="btn">Update Donor Applications</a>
    </div>

    <div class="card">
        <h3>Reward & Report</h3>
        <a href="create_reward.php" class="btn">Create Rewards</a>
        <a href="generate_report.php" class="btn">Generate Report</a>
    </div>
</div>
</body>
</html>
