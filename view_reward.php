<?php
session_start();

// Only donors (students) can access
if (!isset($_SESSION['donor_id'])) {
    header("Location: donor_login.php");
    exit;
}

$pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$donorId = $_SESSION['donor_id'];

// Get donor name
$stmt = $pdo->prepare("SELECT DonorName FROM donor WHERE DonorID = ?");
$stmt->execute([$donorId]);
$donor = $stmt->fetch();

// Count successful donations by donor
$donationStmt = $pdo->prepare("
    SELECT COUNT(*) as total_donations
    FROM donation d
    JOIN registration r ON d.RegistrationID = r.RegistrationID
    WHERE r.StudentID = ?
");
$donationStmt->execute([$donorId]);
$donationData = $donationStmt->fetch();

$totalDonations = $donationData['total_donations'] ?? 0;
$pointsPerDonation = 10;
$totalPoints = $totalDonations * $pointsPerDonation;
?>

<!DOCTYPE html>
<html>
<head>
    <title>My Reward Points - LifeSaver Hub</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f5f5f5; padding: 30px; }
        .container {
            max-width: 500px; margin: auto;
            background: white; padding: 30px;
            border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            text-align: center;
        }
        h2 { margin-bottom: 20px; }
        .reward-box {
            background: #e8f5e9;
            padding: 25px;
            border-radius: 8px;
            font-size: 1.5rem;
            color: #2e7d32;
            font-weight: bold;
            margin-top: 15px;
        }
        .note {
            color: #555;
            font-size: 0.95rem;
            margin-top: 15px;
        }
    </style>
</head>
<body>
<div class="container">
    <h2>🎁 My Reward Points</h2>
    <p><strong>Donor Name:</strong> <?= htmlspecialchars($donor['DonorName']) ?></p>

    <div class="reward-box">
        <?= $totalPoints ?> Points
    </div>

    <p class="note">You earn <?= $pointsPerDonation ?> points for every successful blood donation.</p>
</div>
</body>
</html>
