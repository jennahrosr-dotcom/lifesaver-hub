<?php
session_start();

if (!isset($_SESSION['donor_id'])) {
    header("Location: donor_login.php");
    exit;
}

$pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$donorId = $_SESSION['donor_id'];
$success = '';
$error = '';

// Get donor name
$donorStmt = $pdo->prepare("SELECT DonorName FROM donor WHERE DonorID = ?");
$donorStmt->execute([$donorId]);
$donor = $donorStmt->fetch();

// Get total donation count = rewardable units
$totalStmt = $pdo->prepare("
    SELECT COUNT(*) as total 
    FROM donation d 
    JOIN registration r ON d.RegistrationID = r.RegistrationID 
    WHERE r.StudentID = ?
");
$totalStmt->execute([$donorId]);
$totalDonations = $totalStmt->fetchColumn();
$totalPoints = $totalDonations * 10;

// Get redeemed reward IDs
$redeemedStmt = $pdo->prepare("SELECT RewardID FROM studentreward WHERE StudentID = ?");
$redeemedStmt->execute([$donorId]);
$redeemedIds = $redeemedStmt->fetchAll(PDO::FETCH_COLUMN);

// Get available rewards
$rewards = $pdo->query("SELECT * FROM reward")->fetchAll(PDO::FETCH_ASSOC);

// Handle redeem action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reward_id'])) {
    $rewardId = intval($_POST['reward_id']);

    $selectedReward = null;
    foreach ($rewards as $reward) {
        if ($reward['RewardID'] == $rewardId) {
            $selectedReward = $reward;
            break;
        }
    }

    if (!$selectedReward) {
        $error = "Invalid reward selection.";
    } elseif (in_array($rewardId, $redeemedIds)) {
        $error = "You have already redeemed this reward.";
    } elseif ($selectedReward['RewardPoint'] > $totalPoints) {
        $error = "You do not have enough points to redeem this reward.";
    } else {
        $insert = $pdo->prepare("INSERT INTO studentreward (StudentID, RewardID) VALUES (?, ?)");
        $insert->execute([$donorId, $rewardId]);
        $success = "You have successfully redeemed: " . htmlspecialchars($selectedReward['RewardPoint']) . " point reward.";
        // Recalculate
        $totalPoints -= $selectedReward['RewardPoint'];
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Redeem Reward - LifeSaver Hub</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f2f2f2; padding: 30px; }
        .container { max-width: 800px; margin: auto; background: white; padding: 25px; border-radius: 8px; }
        h2 { margin-bottom: 20px; }
        .message { padding: 10px; border-radius: 5px; margin-bottom: 20px; }
        .success { background: #d4edda; color: #155724; }
        .error { background: #f8d7da; color: #721c24; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ccc; padding: 10px; }
        th { background: #eee; }
        form { display: inline; }
        button {
            padding: 5px 15px; background: #28a745; color: white;
            border: none; border-radius: 4px; cursor: pointer;
        }
        button[disabled] {
            background: #aaa; cursor: not-allowed;
        }
    </style>
</head>
<body>
<div class="container">
    <h2>🎁 Redeem Rewards</h2>
    <p><strong>Donor:</strong> <?= htmlspecialchars($donor['DonorName']) ?></p>
    <p><strong>Total Points:</strong> <?= $totalPoints ?></p>

    <?php if ($success): ?>
        <div class="message success"><?= $success ?></div>
    <?php elseif ($error): ?>
        <div class="message error"><?= $error ?></div>
    <?php endif; ?>

    <table>
        <thead>
            <tr>
                <th>Reward ID</th>
                <th>Point Cost</th>
                <th>Created By (Staff ID)</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rewards as $reward): ?>
                <tr>
                    <td><?= $reward['RewardID'] ?></td>
                    <td><?= $reward['RewardPoint'] ?> pts</td>
                    <td><?= $reward['StaffID'] ?></td>
                    <td>
                        <?php if (in_array($reward['RewardID'], $redeemedIds)): ?>
                            <span style="color: green;">Redeemed</span>
                        <?php elseif ($reward['RewardPoint'] > $totalPoints): ?>
                            <button disabled>Not enough points</button>
                        <?php else: ?>
                            <form method="post">
                                <input type="hidden" name="reward_id" value="<?= $reward['RewardID'] ?>">
                                <button type="submit">Redeem</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
</body>
</html>
