<?php
session_start();

// Redirect if donor not logged in
if (!isset($_SESSION['donor_id'])) {
    header("Location: donor_login.php");
    exit;
}

$pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$donorId = $_SESSION['donor_id'];
$registrationId = $_GET['id'] ?? null;

if (!$registrationId) {
    $_SESSION['error'] = "Missing registration ID.";
    header("Location: student_view_donation.php");
    exit;
}

// Fetch registration for the current donor
$stmt = $pdo->prepare("
    SELECT r.*, e.EventTitle, e.EventDate, e.EventVenue 
    FROM registration r 
    JOIN event e ON r.EventID = e.EventID 
    WHERE r.RegistrationID = ? AND r.StudentID = ?
");
$stmt->execute([$registrationId, $donorId]);
$registration = $stmt->fetch();

if (!$registration) {
    $_SESSION['error'] = "No matching registration found.";
    header("Location: student_view_donation.php");
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $attendance = $_POST['attendance'] ?? '';
    $notes = trim($_POST['notes'] ?? '');

    $update = $pdo->prepare("
        UPDATE registration 
        SET AttendanceStatus = ?, RegistrationDate = NOW()
        WHERE RegistrationID = ? AND StudentID = ?
    ");
    $update->execute([$attendance, $registrationId, $donorId]);

    $_SESSION['success'] = "Registration updated successfully.";
    header("Location: student_view_donation.php");
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Update Donation Registration</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f2f2f2; padding: 20px; }
        .container { background: #fff; padding: 25px; border-radius: 8px; max-width: 600px; margin: auto; }
        h2 { margin-bottom: 20px; }
        label { display: block; margin-top: 15px; }
        select, textarea { width: 100%; padding: 10px; margin-top: 5px; border-radius: 4px; border: 1px solid #ccc; }
        button { margin-top: 20px; padding: 10px 20px; background: #28a745; border: none; color: white; border-radius: 4px; cursor: pointer; }
        button:hover { background: #218838; }
        .info { margin-top: 20px; background: #e9ecef; padding: 10px; border-left: 4px solid #17a2b8; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Update Registration for: <?= htmlspecialchars($registration['EventTitle']) ?></h2>

        <form method="post">
            <label for="attendance">Attendance Status:</label>
            <select name="attendance" id="attendance" required>
                <option value="Present" <?= $registration['AttendanceStatus'] == 'Present' ? 'selected' : '' ?>>Present</option>
                <option value="Tentative" <?= $registration['AttendanceStatus'] == 'Tentative' ? 'selected' : '' ?>>Tentative</option>
                <option value="Absent" <?= $registration['AttendanceStatus'] == 'Absent' ? 'selected' : '' ?>>Absent</option>
            </select>

            <label for="notes">Additional Notes (Optional):</label>
            <textarea name="notes" id="notes" rows="4" placeholder="Any additional info (not saved to DB in current schema)..."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>

            <button type="submit">Update Registration</button>
        </form>

        <div class="info">
            <strong>Event Date:</strong> <?= htmlspecialchars($registration['EventDate']) ?><br>
            <strong>Venue:</strong> <?= htmlspecialchars($registration['EventVenue']) ?><br>
            <strong>Status:</strong> <?= htmlspecialchars($registration['RegistrationStatus']) ?>
        </div>
    </div>
</body>
</html>