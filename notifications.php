<?php
session_start();
if (!isset($_SESSION['student_id'])) {
    header("Location: index.php");
    exit;
}

$pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$studentId = $_SESSION['student_id'];

// Handle responses
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $notificationId = $_POST['notification_id'];
    $response = $_POST['response'];

    // Mark notification as read
    $pdo->prepare("UPDATE notification SET NotificationIsRead = 1 WHERE NotificationID = ? AND RegistrationID = ?")
        ->execute([$notificationId, $studentId]);

    if ($response === 'remind') {
        // Schedule reminder (e.g., 2 days before event if logic available)
        $reminderDate = date('Y-m-d', strtotime('+2 days'));
        $stmt = $pdo->prepare("INSERT INTO reminder (NotificationID, RegistrationID, ReminderDate) VALUES (?, ?, ?)");
        $stmt->execute([$notificationId, $studentId, $reminderDate]);
    }
}

// Fetch notifications
$stmt = $pdo->prepare("SELECT * FROM notification WHERE RegistrationID = ? ORDER BY NotificationDate DESC");
$stmt->execute([$studentId]);
$notifications = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Notifications - LifeSaver Hub</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: 'Segoe UI', sans-serif;
            background-color: #f4f6fa;
            margin: 0;
        }
        .sidebar {
            width: 250px;
            background: #1d3557;
            height: 100vh;
            position: fixed;
            color: white;
            padding-top: 20px;
        }
        .sidebar h2 {
            text-align: center;
            margin-bottom: 30px;
        }
        .sidebar a {
            display: block;
            padding: 15px 20px;
            text-decoration: none;
            color: white;
            font-size: 16px;
        }
        .sidebar a:hover {
            background-color: #457b9d;
        }
        .main {
            margin-left: 250px;
            padding: 30px;
        }
        .notification-card {
            background: white;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        }
        .notification-card h3 {
            margin-bottom: 10px;
        }
        .notification-card p {
            margin-bottom: 10px;
        }
        .notification-card small {
            display: block;
            color: gray;
            margin-bottom: 10px;
        }
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 25px;
            font-weight: bold;
            cursor: pointer;
            margin-right: 10px;
        }
        .btn-remind {
            background-color: #28a745;
            color: white;
        }
        .btn-not {
            background-color: #dc3545;
            color: white;
        }
        .btn.disabled {
            background-color: #ccc;
            cursor: not-allowed;
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
    <h1>Event Notifications</h1>

    <?php foreach ($notifications as $note): ?>
        <div class="notification-card">
            <h3><?= htmlspecialchars($note['NotificationTitle']) ?></h3>
            <p><?= htmlspecialchars($note['NotificationMessage']) ?></p>
            <small>Notification Date: <?= htmlspecialchars($note['NotificationDate']) ?></small>
            <?php if ($note['NotificationIsRead']): ?>
                <p><em>Responded</em></p>
            <?php else: ?>
                <form method="POST" style="margin-top: 10px;">
                    <input type="hidden" name="notification_id" value="<?= $note['NotificationID'] ?>">
                    <button type="submit" name="response" value="not_interested" class="btn btn-not">Not Interested</button>
                    <button type="submit" name="response" value="remind" class="btn btn-remind">Remind Me</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

</body>
</html>
