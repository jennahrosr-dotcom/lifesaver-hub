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
$success = "";
$error = "";

// Fetch student email
$studentStmt = $pdo->prepare("SELECT * FROM student WHERE StudentID = ?");
$studentStmt->execute([$studentId]);
$student = $studentStmt->fetch();

// Fetch eligible events (Upcoming, not Deleted)
$events = $pdo->query("SELECT * FROM event WHERE EventStatus = 'Upcoming'")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['event_id'])) {
    $eventId = $_POST['event_id'];

    // Insert into registration
    $stmt = $pdo->prepare("INSERT INTO registration (RegistrationDate, RegistrationStatus, AttendanceStatus, StudentID, EventID) VALUES (CURDATE(), 'Registered', 'Pending', ?, ?)");
    $stmt->execute([$studentId, $eventId]);
    $registrationId = $pdo->lastInsertId();

    // Send notification
    $eventStmt = $pdo->prepare("SELECT * FROM event WHERE EventID = ?");
    $eventStmt->execute([$eventId]);
    $event = $eventStmt->fetch();

    $title = "Donation Application";
    $message = "You have applied for the event: " . $event['EventTitle'] . " on " . $event['EventDate'];
    $notify = $pdo->prepare("INSERT INTO notification (NotificationTitle, NotificationMessage, NotificationDate, NotificationIsRead, RegistrationID) VALUES (?, ?, CURDATE(), 0, ?)");
    $notify->execute([$title, $message, $registrationId]);

    // Send email
    $to = $student['StudentEmail'];
    $subject = "Blood Donation Confirmation";
    $body = "Dear " . $student['StudentName'] . ",\n\nThank you for applying to donate blood at the event: " . $event['EventTitle'] . ".\nPlease attend on " . $event['EventDate'] . " at " . $event['EventVenue'] . ".\n\n- LifeSaver Hub";
    $headers = "From: lifesaverhub@example.com";

    mail($to, $subject, $body, $headers);

    $success = "Application successful. Please check your email and notifications.";
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
