<?php
session_start();

// Check if staff is logged in
if (!isset($_SESSION['staff_id'])) {
    header("Location: staff_login.php");
    exit;
}

$pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

// Fetch all registrations
$stmt = $pdo->query("
    SELECT r.RegistrationID, r.RegistrationDate, r.RegistrationStatus, r.AttendanceStatus,
           d.DonorName, e.EventTitle, e.EventDate, e.EventVenue
    FROM registration r
    JOIN donor d ON r.StudentID = d.DonorID
    JOIN event e ON r.EventID = e.EventID
    ORDER BY r.RegistrationDate DESC
");
$registrations = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Staff - View Donations</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f0f2f5; padding: 20px; }
        .container { max-width: 1100px; margin: auto; background: #fff; padding: 25px; border-radius: 8px; }
        h2 { margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ccc; padding: 10px; text-align: left; }
        th { background: #f1f1f1; }
        .btn { padding: 6px 12px; border-radius: 4px; font-size: 14px; text-decoration: none; display: inline-block; }
        .btn-confirm { background: #28a745; color: white; }
        .btn-confirm:hover { background: #218838; }
        .status-present { color: green; font-weight: bold; }
        .status-cancelled { color: red; font-weight: bold; }
        .message { padding: 10px; margin-bottom: 20px; border-left: 5px solid; }
        .success { background: #d4edda; color: #155724; border-color: #28a745; }
        .error { background: #f8d7da; color: #721c24; border-color: #dc3545; }
    </style>
</head>
<body>
<div class="container">
    <h2>All Blood Donation Registrations</h2>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="message success"><?= htmlspecialchars($_SESSION['success']) ?></div>
        <?php unset($_SESSION['success']); ?>
    <?php elseif (isset($_SESSION['error'])): ?>
        <div class="message error"><?= htmlspecialchars($_SESSION['error']); ?></div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <?php if (count($registrations) === 0): ?>
        <p>No donation registrations found.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Donor Name</th>
                    <th>Event</th>
                    <th>Date</th>
                    <th>Venue</th>
                    <th>Registered On</th>
                    <th>Status</th>
                    <th>Attendance</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($registrations as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['DonorName']) ?></td>
                        <td><?= htmlspecialchars($row['EventTitle']) ?></td>
                        <td><?= htmlspecialchars($row['EventDate']) ?></td>
                        <td><?= htmlspecialchars($row['EventVenue']) ?></td>
                        <td><?= htmlspecialchars($row['RegistrationDate']) ?></td>
                        <td>
                            <?php if ($row['RegistrationStatus'] === 'Cancelled'): ?>
                                <span class="status-cancelled">Cancelled</span>
                            <?php else: ?>
                                <?= htmlspecialchars($row['RegistrationStatus']) ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['AttendanceStatus'] === 'Present'): ?>
                                <span class="status-present">Present</span>
                            <?php else: ?>
                                <?= htmlspecialchars($row['AttendanceStatus'] ?? 'Not Marked') ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['AttendanceStatus'] !== 'Present' && $row['RegistrationStatus'] !== 'Cancelled'): ?>
                                <a class="btn btn-confirm"
                                   href="confirm_attendance.php?id=<?= $row['RegistrationID'] ?>"
                                   onclick="return confirm('Confirm attendance for this donor?');">
                                   Confirm
                                </a>
                            <?php else: ?>
                                <span style="color: gray;">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
</body>
</html>
