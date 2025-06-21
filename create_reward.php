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

$staffId = $_SESSION['staff_id'];
$success = "";
$error = "";

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rewardPoint = intval($_POST['reward_point'] ?? 0);

    if ($rewardPoint <= 0) {
        $error = "Please enter a valid reward point greater than 0.";
    } else {
        $stmt = $pdo->prepare("INSERT INTO reward (RewardPoint, StaffID) VALUES (?, ?)");
        $stmt->execute([$rewardPoint, $staffId]);
        $success = "Reward successfully created.";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Create Reward - LifeSaver Hub</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f6f8; padding: 20px; }
        .container { max-width: 600px; margin: auto; background: #fff; padding: 25px; border-radius: 8px; }
        h2 { margin-bottom: 20px; }
        label { display: block; margin-top: 15px; }
        input[type="number"] {
            width: 100%; padding: 10px; margin-top: 5px; border: 1px solid #ccc; border-radius: 5px;
        }
        button {
            margin-top: 20px; padding: 10px 20px;
            background: #28a745; color: white;
            border: none; border-radius: 4px;
            cursor: pointer;
        }
        button:hover { background: #218838; }
        .message { padding: 10px; margin-top: 15px; border-radius: 4px; }
        .success { background: #d4edda; color: #155724; }
        .error { background: #f8d7da; color: #721c24; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Create New Reward</h2>

        <?php if ($success): ?>
            <div class="message success"><?= htmlspecialchars($success) ?></div>
        <?php elseif ($error): ?>
            <div class="message error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <label for="reward_point">Reward Points:</label>
            <input type="number" id="reward_point" name="reward_point" min="1" required>

            <button type="submit">Create Reward</button>
        </form>
    </div>
</body>
</html>
