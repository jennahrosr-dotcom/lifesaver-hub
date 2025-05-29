<?php
session_start();

if (!isset($_SESSION['staff_id'])) {
    header("Location: staff_login.php");
    exit;
}

$pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$stmt = $pdo->prepare("SELECT * FROM staff WHERE StaffID = ?");
$stmt->execute([$_SESSION['staff_id']]);
$staff = $stmt->fetch();

$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $contact = trim($_POST['contact']);
    $password = $_POST['password'];
    $confirm = $_POST['confirm_password'];

    if (!$name || !$email || !$contact || !$password || !$confirm) {
        $error = "All fields are required.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } else {
        $stmt = $pdo->prepare("UPDATE staff SET StaffName = ?, StaffEmail = ?, StaffContact = ?, StaffPassword = ? WHERE StaffID = ?");
        $stmt->execute([$name, $email, $contact, $password, $_SESSION['staff_id']]);
        $success = true;

        // Refresh data
        $stmt = $pdo->prepare("SELECT * FROM staff WHERE StaffID = ?");
        $stmt->execute([$_SESSION['staff_id']]);
        $staff = $stmt->fetch();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Account - Staff</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f4f6fa;
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
            margin-left: 250px;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }

        .card {
            background: white;
            padding: 40px;
            width: 500px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }

        .card h2 {
            text-align: center;
            color: #1d3557;
            margin-bottom: 30px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-weight: bold;
            margin-bottom: 6px;
            color: #1d3557;
        }

        .form-group input {
            width: 100%;
            padding: 12px;
            border-radius: 6px;
            border: 1px solid #ccc;
            background-color: #f1f3f5;
        }

        .btn {
            background-color: #d62828;
            color: white;
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 25px;
            font-weight: bold;
            font-size: 16px;
            cursor: pointer;
            margin-top: 20px;
        }

        .btn:hover {
            background-color: #b71c1c;
        }

        .message {
            text-align: center;
            font-weight: bold;
            margin-bottom: 20px;
        }

        .success {
            color: green;
        }

        .error {
            color: red;
        }
    </style>
</head>
<body>

<div class="sidebar">
    <a href="student_account.php"><i class="fas fa-user"></i> My Account</a>
    <a href="view_event.php"><i class="fas fa-calendar"></i> View Events</a>
    <a href="health_questionnaire.php"><i class="fas fa-notes-medical"></i> Health Questions</a>
    <a href="notifications.php"><i class="fas fa-bell"></i> Notifications</a>
    <a href="view_donation.php"><i class="fas fa-eye"></i> View Donations</a>
    <a href="update_donation.php"><i class="fas fa-sync"></i> Update Donation</a>
    <a href="delete_donation.php"><i class="fas fa-trash"></i> Delete Donation</a>
    <a href="donation_history.php"><i class="fas fa-history"></i> Donation History</a>
    <a href="view_rewards.php"><i class="fas fa-gift"></i> My Rewards</a>
    <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
</div>

<div class="main">
    <div class="card">
        <h2><?= htmlspecialchars($staff['StaffName']) ?>'s Profile</h2>

        <?php if ($success): ?>
            <p class="message success">Profile updated successfully.</p>
        <?php elseif ($error): ?>
            <p class="message error"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>USERNAME</label>
                <input type="text" name="name" value="<?= htmlspecialchars($staff['StaffName']) ?>" required>
            </div>
            <div class="form-group">
                <label>EMAIL</label>
                <input type="email" name="email" value="<?= htmlspecialchars($staff['StaffEmail']) ?>" required>
            </div>
            <div class="form-group">
                <label>CONTACT</label>
                <input type="text" name="contact" value="<?= htmlspecialchars($staff['StaffContact']) ?>" required>
            </div>
            <div class="form-group">
                <label>PASSWORD</label>
                <input type="text" name="password" value="<?= htmlspecialchars($staff['StaffPassword']) ?>" required>
            </div>
            <div class="form-group">
                <label>CONFIRM PASSWORD</label>
                <input type="text" name="confirm_password" value="<?= htmlspecialchars($staff['StaffPassword']) ?>" required>
            </div>
            <button type="submit" class="btn">UPDATE</button>
        </form>
    </div>
</div>

</body>
</html>
