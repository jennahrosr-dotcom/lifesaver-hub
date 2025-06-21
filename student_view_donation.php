<?php
session_start();
$pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

if (!isset($_SESSION['donor_id'])) {
    header("Location: donor_login.php");
    exit;
}

$donorId = $_SESSION['donor_id'];

$stmt = $pdo->prepare("
    SELECT r.RegistrationID, r.RegistrationDate, r.RegistrationStatus, r.AttendanceStatus,
           e.EventTitle, e.EventDate, e.EventVenue
    FROM registration r
    JOIN event e ON r.EventID = e.EventID
    WHERE r.StudentID = ?
    ORDER BY r.RegistrationDate DESC
");
$stmt->execute([$donorId]);
$donations = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>My Donations - LifeSaver Hub</title>
    <style>
        body { font-family: Arial, sans-serif; background: #eef2f3; padding: 20px; }
        table { width: 100%; border-collapse: collapse; background: #fff; }
        th, td { border: 1px solid #ccc; padding: 10px; }
        th { background: #ddd; }
        h2 { margin-bottom: 20px; }
    </style>
</head>
<body>
    <h2>My Blood Donation Registrations</h2>
    <table>
        <thead>
            <tr>
                <th>Event</th>
                <th>Event Date</th>
                <th>Venue</th>
                <th>Registration Date</th>
                <th>Status</th>
                <th>Attendance</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($donations as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['EventTitle']) ?></td>
                    <td><?= htmlspecialchars($row['EventDate']) ?></td>
                    <td><?= htmlspecialchars($row['EventVenue']) ?></td>
                    <td><?= htmlspecialchars($row['RegistrationDate']) ?></td>
                    <td><?= htmlspecialchars($row['RegistrationStatus']) ?></td>
                    <td><?= htmlspecialchars($row['AttendanceStatus']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>
