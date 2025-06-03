<?php
session_start();
if (!isset($_SESSION['student_id'])) {
    header("Location: index.php");
    exit;
}

require 'PHPMailer/PHPMailer-master/src/PHPMailer.php';
require 'PHPMailer/PHPMailer-master/src/SMTP.php';
require 'PHPMailer/PHPMailer-master/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$studentId = $_SESSION['student_id'];

// Get latest registration
$registrationStmt = $pdo->prepare("SELECT r.RegistrationID, s.StudentName, s.StudentEmail 
                                   FROM registration r
                                   JOIN student s ON r.StudentID = s.StudentID
                                   WHERE r.StudentID = ?
                                   ORDER BY r.RegistrationID DESC LIMIT 1");
$registrationStmt->execute([$studentId]);
$registration = $registrationStmt->fetch();

if (!$registration) {
    die("No registration found.");
}

$registrationId = $registration['RegistrationID'];
$studentEmail = $registration['StudentEmail'];
$studentName = $registration['StudentName'];

// Handle response
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['notification_id'], $_POST['response'])) {
    $notificationId = $_POST['notification_id'];
    $response = $_POST['response']; // 'Remind Me' or 'Not Interested'

    $pdo->prepare("UPDATE notification SET NotificationIsRead = ? WHERE NotificationID = ?")
        ->execute([$response, $notificationId]);

    if ($response === 'Remind Me') {
        $eventStmt = $pdo->prepare("SELECT e.EventTitle, e.EventDate, e.EventVenue
                                    FROM event e 
                                    JOIN registration r ON e.EventID = r.EventID
                                    WHERE r.RegistrationID = ?");
        $eventStmt->execute([$registrationId]);
        $event = $eventStmt->fetch();

        if ($event) {
            $eventDateTime = new DateTime($event['EventDate'] . " 00:00:00");
            $now = new DateTime();
            $diffHours = ($eventDateTime->getTimestamp() - $now->getTimestamp()) / 3600;

            if ($diffHours <= 27 && $diffHours > 0) {
                try {
                    $mail = new PHPMailer(true);
                    $mail->isSMTP();
                    $mail->Host = 'smtp.example.com';
                    $mail->SMTPAuth = true;
                    $mail->Username = 'your_email@example.com';
                    $mail->Password = 'your_password';
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port = 587;

                    $mail->setFrom('lifesaverhub@example.com', 'LifeSaver Hub');
                    $mail->addAddress($studentEmail, $studentName);
                    $mail->isHTML(true);
                    $mail->Subject = "⏰ Reminder: Blood Donation Event";
                    $mail->Body = "
                        <p>Hi $studentName,</p>
                        <p>This is a friendly reminder about your upcoming blood donation:</p>
                        <ul>
                            <li><strong>Event:</strong> {$event['EventTitle']}</li>
                            <li><strong>Date:</strong> {$event['EventDate']}</li>
                            <li><strong>Venue:</strong> {$event['EventVenue']}</li>
                        </ul>
                        <p>Thank you for making a difference! ❤️</p>
                    ";
                    $mail->send();
                } catch (Exception $e) {
                    echo "<p style='color:red;'>Failed to send email: {$mail->ErrorInfo}</p>";
                }
            }
        }
    }
}

// Fetch notifications
$notifications = $pdo->prepare("SELECT * FROM notification WHERE RegistrationID = ?");
$notifications->execute([$registrationId]);
$rows = $notifications->fetchAll();
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Notifications - LifeSaver Hub</title>
    <style>
        body {
            font-family: Arial;
            background: #f4f6fa;
            margin: 0;
            padding: 30px;
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
        }
        .sidebar a:hover {
            background-color: #457b9d;
        }
        .main {
            margin-left: 270px;
        }
        h1 {
            color: #1d3557;
        }
        .notification {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .notification h3 {
            margin-top: 0;
        }
        form {
            margin-top: 15px;
        }
        button {
            padding: 8px 14px;
            margin-right: 10px;
            border: none;
            border-radius: 5px;
            font-weight: bold;
            cursor: pointer;
        }
        .remind { background: #28a745; color: white; }
        .not { background: #dc3545; color: white; }
        .status {
            font-style: italic;
            margin-top: 10px;
        }
    </style>
</head>
<body>

<div class="sidebar">
    <h2>LifeSaver Hub</h2>
    <a href="student_account.php">My Account</a>
    <a href="view_event.php">View Events</a>
    <a href="health_questionnaire.php">Health Questions</a>
    <a href="notifications.php">Notifications</a>
    <a href="view_donation.php">View Donations</a>
    <a href="update_donation.php">Update Donation</a>
    <a href="delete_donation.php">Delete Donation</a>
    <a href="donation_history.php">Donation History</a>
    <a href="view_rewards.php">My Rewards</a>
    <a href="logout.php">Logout</a>
</div>

<div class="main">
    <h1>📬 Your Event Notifications</h1>

    <?php if (empty($rows)): ?>
        <p>No notifications found.</p>
    <?php endif; ?>

    <?php foreach ($rows as $n): ?>
        <div class="notification">
            <h3><?= htmlspecialchars($n['NotificationTitle']) ?></h3>
            <p><?= htmlspecialchars($n['NotificationMessage']) ?></p>
            <small>Date: <?= htmlspecialchars($n['NotificationDate']) ?></small><br>

            <?php
                $isPast = strtotime($n['NotificationDate']) < strtotime(date('Y-m-d'));
                $hasResponded = in_array($n['NotificationIsRead'], ['Remind Me', 'Not Interested']);
            ?>

            <?php if ($hasResponded): ?>
                <p class="status">You responded: <strong><?= $n['NotificationIsRead'] ?></strong></p>
            <?php elseif ($isPast): ?>
                <p class="status" style="color: gray;">This event has passed.</p>
            <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="notification_id" value="<?= $n['NotificationID'] ?>">
                    <button name="response" value="Remind Me" class="remind">Remind Me</button>
                    <button name="response" value="Not Interested" class="not">Not Interested</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
</body>
</html>
