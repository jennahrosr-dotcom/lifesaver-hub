<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php'; // PHPMailer autoload

session_start();
if (!isset($_SESSION['student_id'])) {
    header("Location: index.php");
    exit;
}

$pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$studentId = $_SESSION['student_id'];
$success = "";
$error = "";

// Fetch student details
$studentStmt = $pdo->prepare("SELECT * FROM student WHERE StudentID = ?");
$studentStmt->execute([$studentId]);
$student = $studentStmt->fetch();

// Fetch upcoming events only
$events = $pdo->query("SELECT * FROM event WHERE EventStatus = 'Upcoming' AND EventDate >= CURDATE()")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['event_id'])) {
    $eventId = $_POST['event_id'];

    // Validate event date
    $eventStmt = $pdo->prepare("SELECT * FROM event WHERE EventID = ?");
    $eventStmt->execute([$eventId]);
    $event = $eventStmt->fetch();

    if (!$event || strtotime($event['EventDate']) < strtotime(date('Y-m-d'))) {
        $error = "You cannot apply for past events.";
    } else {
        // Register student for event
        $stmt = $pdo->prepare("INSERT INTO registration (RegistrationDate, RegistrationStatus, AttendanceStatus, StudentID, EventID) VALUES (CURDATE(), 'Registered', 'Pending', ?, ?)");
        $stmt->execute([$studentId, $eventId]);
        $registrationId = $pdo->lastInsertId();

        // Insert notification
        $title = "Donation Application";
        $message = "You have applied for the event: " . $event['EventTitle'] . " on " . $event['EventDate'];
        $notify = $pdo->prepare("INSERT INTO notification (NotificationTitle, NotificationMessage, NotificationDate, NotificationIsRead, RegistrationID) VALUES (?, ?, CURDATE(), 'Unread', ?)");
        $notify->execute([$title, $message, $registrationId]);

        // Send confirmation email via PHPMailer
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = 'smtp.example.com'; // 🔧 your SMTP server
            $mail->SMTPAuth = true;
            $mail->Username = 'your_email@example.com';
            $mail->Password = 'your_password';
            $mail->SMTPSecure = 'tls';
            $mail->Port = 587;

            $mail->setFrom('lifesaverhub@example.com', 'LifeSaver Hub');
            $mail->addAddress($student['StudentEmail'], $student['StudentName']);
            $mail->Subject = 'Blood Donation Confirmation';
            $mail->Body = "Dear {$student['StudentName']},\n\nThank you for applying to donate blood at: {$event['EventTitle']}.\nPlease attend on {$event['EventDate']} at {$event['EventVenue']}.\n\n- LifeSaver Hub";

            $mail->send();
            $success = "Application successful. Confirmation email sent.";
        } catch (Exception $e) {
            $error = "Application submitted, but email could not be sent. Error: " . $mail->ErrorInfo;
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Apply for Donation</title>
    <style>
        body { font-family: Arial; background: #f4f6fa; padding: 40px; }
        .form-box { background: white; padding: 20px; border-radius: 10px; width: 400px; margin: auto; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        select, button { width: 100%; padding: 10px; margin-top: 10px; }
        .success { color: green; }
        .error { color: red; }
    </style>
</head>
<body>
<div class="form-box">
    <h2>Apply for Blood Donation</h2>
    <?php if ($success): ?><p class="success"><?= $success ?></p><?php endif; ?>
    <?php if ($error): ?><p class="error"><?= $error ?></p><?php endif; ?>

    <form method="POST">
        <label>Select an Event:</label>
        <select name="event_id" required>
            <option value="">-- Choose an Event --</option>
            <?php foreach ($events as $event): ?>
                <option value="<?= $event['EventID'] ?>">
                    <?= htmlspecialchars($event['EventTitle']) ?> on <?= $event['EventDate'] ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit">Apply</button>
    </form>
</div>
</body>
</html>
