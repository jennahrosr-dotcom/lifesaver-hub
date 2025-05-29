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

// Get registration IDs for student
$regStmt = $pdo->prepare("SELECT RegistrationID FROM registration WHERE StudentID = ?");
$regStmt->execute([$studentId]);
$registrationIds = $regStmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch notifications
$notifications = [];
if ($registrationIds) {
    $placeholders = implode(',', array_fill(0, count($registrationIds), '?'));
    $notifStmt = $pdo->prepare("SELECT * FROM notification WHERE RegistrationID IN ($placeholders) ORDER BY NotificationDate DESC");
    $notifStmt->execute($registrationIds);
    $notifications = $notifStmt->fetchAll();
}

// Mark as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read'])) {
    $notifId = $_POST['notif_id'];
    $pdo->prepare("UPDATE notification SET NotificationIsRead = 1 WHERE NotificationID = ?")->execute([$notifId]);
    header("Location: notification.php");
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Notifications - LifeSaver Hub</title>
    <style>
        body { font-family: Arial; background: #f4f6fa; padding: 40px; }
        .box { max-width: 700px; margin: auto; background: white; padding: 20px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .notification { padding: 15px; border-bottom: 1px solid #ccc; }
        .unread { font-weight: bold; }
        .read { color: #777; }
        form.inline { display: inline; }
        button { padding: 5px 10px; border: none; background: #28a745; color: white; cursor: pointer; border-radius: 5px; }
        button:hover { background: #218838; }
    </style>
</head>
<body>
    <div class="box">
        <h2>Your Notifications</h2>
        <?php if ($notifications): ?>
            <?php foreach ($notifications as $notif): ?>
                <div class="notification <?= $notif['NotificationIsRead'] ? 'read' : 'unread' ?>">
                    <strong><?= htmlspecialchars($notif['NotificationTitle']) ?></strong><br>
                    <?= htmlspecialchars($notif['NotificationMessage']) ?><br>
                    <small><?= $notif['NotificationDate'] ?></small>
                    <?php if (!$notif['NotificationIsRead']): ?>
                        <form method="POST" class="inline">
                            <input type="hidden" name="notif_id" value="<?= $notif['NotificationID'] ?>">
                            <button type="submit" name="mark_read">Mark as Read</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p>No notifications found.</p>
        <?php endif; ?>
    </div>
</body>
</html>
