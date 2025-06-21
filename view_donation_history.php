<?php
session_start();

// Ensure donor is logged in
if (!isset($_SESSION['donor_id'])) {
    header("Location: donor_login.php");
    exit;
}

$pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$donorId = $_SESSION['donor_id'];

// Get donor name (optional)
$stmt = $pdo->prepare("SELECT DonorName FROM donor WHERE DonorID = ?");
$stmt->execute([$donorId]);
$donor = $stmt->fetch();

// Get donation history via registration
$query = $pdo->prepare("
    SELECT d.DonationDate, d.DonationBloodType, d.DonationQuantity,
           e.EventTitle, e.EventDate, e.EventVenue
    FROM donation d
    JOIN registration r ON d.RegistrationID = r.RegistrationID
    JOIN event e ON r.EventID = e.EventID
    WHERE r.StudentID = ?
    ORDER BY d.DonationDate DESC
");
$query->execute([$donorId]);
$donations = $query->fetchAll();
?>

<!DOCTYPE html>
<html>
<head>
    <title>My Donation History - LifeSaver Hub</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f0f2f5; padding: 20px; }
        .container { max-width: 1000px; margin: auto; background: #fff; padding: 25px; border-radius: 8px; }
        h2 { margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 10px; border: 1px solid #ccc; text-align: left; }
        th { background: #f9f9f9; }
        .empty-msg { color: #777; margin-top: 15px; }
    </style>
</head>
<body>
<div class="container">
    <h2>My Blood Donation History</h2>
    <p><strong>Donor:</strong> <?= htmlspecialchars($donor['DonorName']) ?></p>

    <?php if (count($donations) === 0): ?>
        <p class="empty-msg">You have not made any blood donations yet.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Donation Date</th>
                    <th>Blood Type</th>
                    <th>Quantity (ml)</th>
                    <th>Event Title</th>
                    <th>Event Date</th>
                    <th>Venue</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($donations as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['DonationDate']) ?></td>
                        <td><?= htmlspecialchars($row['DonationBloodType']) ?></td>
                        <td><?= htmlspecialchars($row['DonationQuantity']) ?></td>
                        <td><?= htmlspecialchars($row['EventTitle']) ?></td>
                        <td><?= htmlspecialchars($row['EventDate']) ?></td>
                        <td><?= htmlspecialchars($row['EventVenue']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
</body>
</html>
