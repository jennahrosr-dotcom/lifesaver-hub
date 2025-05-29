<?php
session_start();

if (!isset($_SESSION['student_id'])) {
    header("Location: student_login.php");
    exit;
}

$pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$stmt = $pdo->prepare("SELECT * FROM student WHERE StudentID = ?");
$stmt->execute([$_SESSION['student_id']]);
$student = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Student Dashboard - LifeSaver Hub</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body {
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f4f6fa;
        }

        .sidebar {
            height: 100vh;
            width: 250px;
            position: fixed;
            top: 0;
            left: 0;
            background-color: #1d3557;
            padding-top: 30px;
            color: white;
        }

        .sidebar h2 {
            text-align: center;
            margin-bottom: 30px;
            font-size: 24px;
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

        .card {
            background-color: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }

        h1 {
            color: #1d3557;
        }

        .card h3 {
            color: #d62828;
        }

        .action-btn {
            background-color: #d62828;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        .action-btn:hover {
            background-color: #a71d2a;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <h2>LifeSaver Hub</h2>
        <a href="student_account.php"><i class="fas fa-user"></i> My Account</a>
        <a href="view_event.php"><i class="fas fa-calendar"></i> View Events</a>
        <a href="health_questionnaire.php"><i class="fas fa-notes-medical"></i> Health Questions</a>
        <a href="notifications.php"><i class="fas fa-bell"></i> Notifications</a>
        <a href="view_donation.php"><i class="fas fa-eye"></i> View Donations</a>
        <a href="update_donation.php"><i class="fas fa-sync-alt"></i> Update Donation</a>
        <a href="delete_donation.php"><i class="fas fa-trash"></i> Delete Donation</a>
        <a href="donation_history.php"><i class="fas fa-history"></i> Donation History</a>
        <a href="view_rewards.php"><i class="fas fa-gift"></i> My Rewards</a>
        <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>

    <div class="main">
        <h1>Welcome, <?= htmlspecialchars($student['StudentName']) ?>!</h1>
    </div>
</body>
</html>
