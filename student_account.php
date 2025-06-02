<?php
session_start();

// Ensure user is logged in
if (!isset($_SESSION['student_id']) && !isset($_SESSION['staff_id'])) {
    header("Location: index.php");
    exit;
}

// Detect user type
$isStudent = isset($_SESSION['student_id']);
$userID = $isStudent ? $_SESSION['student_id'] : $_SESSION['staff_id'];
$table = $isStudent ? "student" : "staff";
$idField = $isStudent ? "StudentID" : "StaffID";

// DB Connection
$pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

// Fetch current user data
$stmt = $pdo->prepare("SELECT * FROM $table WHERE $idField = ?");
$stmt->execute([$userID]);
$user = $stmt->fetch();

// Handle update form
$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['username']);
    $email = trim($_POST['email']);
    $contact = trim($_POST['contact']);
    $password = $_POST['password'];
    $confirm = $_POST['confirm_password'];

    if (!$name || !$email || !$contact) {
        $error = "All fields are required.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } else {
        $updateQuery = "UPDATE $table SET " .
            ($isStudent
                ? "StudentName = ?, StudentEmail = ?, StudentContact = ?, StudentPassword = ?"
                : "StaffName = ?, StaffEmail = ?, StaffContact = ?, StaffPassword = ?") .
            " WHERE $idField = ?";
        $stmt = $pdo->prepare($updateQuery);
        $stmt->execute([$name, $email, $contact, $password, $userID]);
        $success = true;
        // Refresh user data
        $stmt = $pdo->prepare("SELECT * FROM $table WHERE $idField = ?");
        $stmt->execute([$userID]);
        $user = $stmt->fetch();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Account - LifeSaver Hub</title>
    <style>
        body {
            background-color: #f4f6fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
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

        .sidebar h2 {
            text-align: center;
            margin-bottom: 30px;
            font-size: 24px;
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

        .container {
            max-width: 800px;
            margin: 40px auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            text-align: center;
        }

        h2 {
            color: #1d3557;
            margin-bottom: 20px;
        }

        form {
            display: inline-block;
            text-align: left;
            width: 100%;
            max-width: 500px;
        }

        label {
            display: block;
            margin-top: 15px;
            font-weight: bold;
            color: #1d3557;
        }

        input {
            width: 100%;
            padding: 10px;
            margin-top: 5px;
            border: 1px solid #ccc;
            border-radius: 8px;
            background-color: #f1f3f5;
        }

        .btn {
            margin-top: 25px;
            padding: 12px 30px;
            background-color: #7b6eea;
            color: white;
            font-weight: bold;
            border: none;
            border-radius: 25px;
            cursor: pointer;
            transition: 0.3s ease;
        }

        .btn:hover {
            background-color: #5a4fd4;
        }

        .message {
            margin-top: 20px;
            font-weight: bold;
            color: green;
        }

        .error {
            color: red;
        }

        .nav-left {
            position: fixed;
            top: 0;
            left: 0;
            background-color: #edf2f7;
            width: 120px;
            height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding-top: 20px;
        }

        .nav-left img.logo {
            width: 60px;
        }

        .nav-link {
            margin: 20px 0;
            text-decoration: none;
            color: #1d3557;
            font-weight: bold;
            text-align: center;
        }

        .nav-link:hover {
            text-decoration: underline;
        }

        @media (max-width: 768px) {
            .nav-left {
                display: none;
            }

            .container {
                margin: 20px;
                width: auto;
            }
        }
    </style>
</head>
<body>

<div class="sidebar">
        <h2>LifeSaver Hub</h2>
        <a href="student_account.php"><i class="fas fa-user"></i> My Account</a>
        <a href="view_event.php"><i class="fas fa-calendar"></i> View Events</a>
        <a href="health_questionnaire.php"><i class="fas fa-notes-medical"></i> Health Questions</a>
        <a href="notifications.php"><i class="fas fa-bell"></i> Notifications</a>
        <a href="view_donation.php"><i class="fas fa-eye"></i> View Donations</a>
        <a href="update_donation.php"><i class="fas fa-sync-alt"></i> Update Donation</a>
        <a href="delete_donation.php"><i class="fas fa-trash"></i> Delete Donation</a>
        <a href="donation_history.php"><i class="fas fa-history"></i> Donation History</a>
        <a href="view_rewards.php"><i class="fas fa-gift"></i> My Rewards</a>
        <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>

<div class="container">
    <h2><?= htmlspecialchars($user[$isStudent ? 'StudentName' : 'StaffName']) ?></h2>

    <?php if ($success): ?>
        <p class="message">Profile updated successfully.</p>
    <?php elseif ($error): ?>
        <p class="message error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="POST">
        <label>USERNAME</label>
        <input type="text" name="username" value="<?= htmlspecialchars($user[$isStudent ? 'StudentName' : 'StaffName']) ?>" required>

        <label>EMAIL</label>
        <input type="email" name="email" value="<?= htmlspecialchars($user[$isStudent ? 'StudentEmail' : 'StaffEmail']) ?>" required>

        <label>CONTACT</label>
        <input type="text" name="contact" value="<?= htmlspecialchars($user[$isStudent ? 'StudentContact' : 'StaffContact']) ?>" required>

        <label>PASSWORD</label>
        <input type="text" name="password" value="<?= htmlspecialchars($user[$isStudent ? 'StudentPassword' : 'StaffPassword']) ?>" required>

        <label>CONFIRM PASSWORD</label>
        <input type="text" name="confirm_password" value="<?= htmlspecialchars($user[$isStudent ? 'StudentPassword' : 'StaffPassword']) ?>" required>

        <button class="btn" type="submit">UPDATE</button>
    </form>
</div>

</body>
</html>
