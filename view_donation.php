<?php
session_start();

// DB connection
$pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$isStudent = isset($_SESSION['student_id']);
$isStaff = isset($_SESSION['staff_id']);
$donations = [];

if ($isStudent) {
    $studentId = $_SESSION['student_id'];
    $stmt = $pdo->prepare("SELECT * FROM donation WHERE RegistrationID = ?");
    $stmt->execute([$studentId]);
    $donations = $stmt->fetchAll();
} elseif ($isStaff) {
    $stmt = $pdo->query("SELECT * FROM donation");
    $donations = $stmt->fetchAll();
} else {
    header("Location: index.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>View Donations - LifeSaver Hub</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body {
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f4f6fa;
        }
        .sidebar {
            position: fixed;
            top: 0; left: 0;
            width: 250px;
            height: 100vh;
            background: #1d3557;
            color: white;
            padding-top: 30px;
        }
        .sidebar h2 {
            text-align: center;
        }
        .sidebar a {
            display: block;
            color: white;
            padding: 15px 20px;
            text-decoration: none;
        }
        .sidebar a:hover {
            background: #457b9d;
        }
        .container {
            margin-left: 260px;
            padding: 30px;
        }
        h1 {
            color: #1d3557;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ccc;
        }
        th {
            background: #1d3557;
            color: white;
        }
        .btn {
            padding: 8px 14px;
            font-size: 14px;
            font-weight: bold;
            text-decoration: none;
            border-radius: 6px;
            margin-right: 5px;
            display: inline-block;
        }
        .btn-edit {
            background-color: #28a745;
            color: white;
        }
        .btn-delete {
            background-color: #dc3545;
            color: white;
        }
    </style>
</head>
<body>

<!-- Sidebar -->
<div class="sidebar">
    <h2>LifeSaver Hub</h2>

    <?php if ($isStudent): ?>
        <a href="student_account.php"><i class="fas fa-user"></i> My Account</a>
        <a href="view_event.php"><i class="fas fa-calendar"></i> View Events</a>
        <a href="health_questionnaire.php"><i class="fas fa-notes-medical"></i> Health Questions</a>
        <a href="notifications.php"><i class="fas fa-bell"></i> Notifications</a>
        <a href="view_donation.php"><i class="fas fa-eye"></i> View Donations</a>
        <a href="update_donation.php"><i class="fas fa-sync-alt"></i> Update Donation</a>
        <a href="delete_donation.php"><i class="fas fa-trash"></i> Delete Donation</a>
        <a href="donation_history.php"><i class="fas fa-history"></i> Donation History</a>
        <a href="view_rewards.php"><i class="fas fa-gift"></i> My Rewards</a>
    <?php elseif ($isStaff): ?>
        <a href="staff_account.php"><i class="fas fa-user"></i> My Account</a>
        <a href="create_event.php"><i class="fas fa-calendar-plus"></i> Create Event</a>
        <a href="view_event.php"><i class="fas fa-calendar"></i> View Events</a>
        <a href="view_donation.php"><i class="fas fa-hand-holding-heart"></i> View Donations</a>
        <a href="confirm_attendance.php"><i class="fas fa-check"></i> Confirm Attendance</a>
        <a href="update_application.php"><i class="fas fa-sync"></i> Update Application</a>
        <a href="create_reward.php"><i class="fas fa-gift"></i> Create Rewards</a>
        <a href="generate_report.php"><i class="fas fa-chart-line"></i> Generate Report</a>
    <?php endif; ?>

    <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
</div>

<!-- Content -->
<div class="container">
    <h1>Donation Records</h1>

    <table>
        <thead>
            <tr>
                <th>Donation ID</th>
                <th>Date</th>
                <th>Location</th>
                <th>Quantity (ml)</th>
                <?php if ($isStudent): ?>
                    <th>Actions</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (count($donations)): ?>
                <?php foreach ($donations as $donation): ?>
                    <tr>
                        <td><?= htmlspecialchars($donation['DonationID']) ?></td>
                        <td><?= htmlspecialchars($donation['DonationDate']) ?></td>
                        <td><?= htmlspecialchars($donation['DonationLocation']) ?></td>
                        <td><?= htmlspecialchars($donation['DonationQuantity']) ?></td>
                        <?php if ($isStudent): ?>
                            <td>
                                <a href="update_donation.php?id=<?= $donation['DonationID'] ?>" class="btn btn-edit">Edit</a>
                                <a href="delete_donation.php?id=<?= $donation['DonationID'] ?>" class="btn btn-delete" onclick="return confirm('Are you sure you want to delete this donation?')">Delete</a>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="<?= $isStudent ? 5 : 4 ?>">No donation records found.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

</body>
</html>
