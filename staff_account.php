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

    if (!$name || !$email || !$contact || !$password) {
        $error = "All fields are required.";
    } else {
        $update = $pdo->prepare("UPDATE staff SET StaffName = ?, StaffEmail = ?, StaffContact = ?, StaffPassword = ? WHERE StaffID = ?");
        $update->execute([$name, $email, $contact, $password, $_SESSION['staff_id']]);
        $success = true;

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
    <title>Staff Account - LifeSaver Hub</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body {
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f4f6fa;
        }
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 250px;
            height: 100vh;
            background-color: #1d3557;
            color: white;
            padding-top: 30px;
        }
        .sidebar h2 {
            text-align: center;
            margin-bottom: 30px;
        }
        .sidebar a {
            display: block;
            color: white;
            padding: 15px 20px;
            text-decoration: none;
            font-size: 16px;
            transition: background 0.3s;
        }
        .sidebar a:hover {
            background-color: #457b9d;
        }
        .main {
            margin-left: 250px;
            padding: 30px;
        }
        .form-container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        h2 {
            color: #1d3557;
            margin-bottom: 20px;
            text-align: center;
        }
        label {
            font-weight: bold;
            color: #1d3557;
            display: block;
            margin-top: 15px;
        }
        input {
            width: 100%;
            padding: 10px;
            margin-top: 5px;
            border-radius: 5px;
            border: 1px solid #ccc;
            background-color: #e9ecef;
        }
        button {
            margin-top: 20px;
            padding: 10px 20px;
            background-color: #d62828;
            color: white;
            border: none;
            border-radius: 25px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
        }
        button:hover {
            background-color: #a61d1d;
        }
        .message {
            text-align: center;
            margin-top: 15px;
            font-weight: bold;
            color: green;
        }
        .error {
            color: red;
        }
    </style>
</head>
<body>
<div class="sidebar">
    <h2>LifeSaver Hub</h2>
    <a href="staff_account.php"><i class="fas fa-user"></i> My Account</a>
    <a href="create_event.php"><i class="fas fa-calendar-plus"></i> Create Event</a>
    <a href="view_event.php"><i class="fas fa-calendar"></i> View Events</a>
    <a href="view_donation.php"><i class="fas fa-hand-holding-heart"></i> View Donations</a>
    <a href="confirm_attendance.php"><i class="fas fa-check"></i> Confirm Attendance</a>
    <a href="update_application.php"><i class="fas fa-sync"></i> Update Application</a>
    <a href="create_reward.php"><i class="fas fa-gift"></i> Create Rewards</a>
    <a href="generate_report.php"><i class="fas fa-chart-line"></i> Generate Report</a>
    <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
</div>
<div class="main">
    <div class="form-container">
        <h2>Update My Account</h2>
        <?php if ($success): ?>
            <div class="message">Account updated successfully!</div>
        <?php elseif ($error): ?>
            <div class="message error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <label for="name">Name</label>
            <input type="text" id="name" name="name" value="<?= htmlspecialchars($staff['StaffName']) ?>" required>

            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="<?= htmlspecialchars($staff['StaffEmail']) ?>" required>

            <label for="contact">Contact</label>
            <input type="text" id="contact" name="contact" value="<?= htmlspecialchars($staff['StaffContact']) ?>" required>

            <label for="password">Password</label>
            <input type="text" id="password" name="password" value="<?= htmlspecialchars($staff['StaffPassword']) ?>" required>

            <button type="submit">Update</button>
        </form>
    </div>
</div>
</body>
</html>
