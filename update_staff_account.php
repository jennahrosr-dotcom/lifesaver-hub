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
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        try {
            $update = $pdo->prepare("UPDATE staff SET StaffName = ?, StaffEmail = ?, StaffContact = ?, StaffPassword = ? WHERE StaffID = ?");
            $update->execute([$name, $email, $contact, $password, $_SESSION['staff_id']]);
            $success = true;

            // Refresh staff data
            $stmt = $pdo->prepare("SELECT * FROM staff WHERE StaffID = ?");
            $stmt->execute([$_SESSION['staff_id']]);
            $staff = $stmt->fetch();
        } catch (Exception $e) {
            $error = "An error occurred while updating your account. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Update Account - LifeSaver Hub</title>
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
        .sidebar a.active {
            background-color: #457b9d;
        }
        .main {
            margin-left: 250px;
            padding: 30px;
        }
        .form-container {
            max-width: 700px;
            margin: 0 auto;
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .form-header {
            background: linear-gradient(135deg, #d62828, #a61d1d);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .form-header h2 {
            margin: 0;
            font-size: 28px;
        }
        .form-header p {
            margin: 10px 0 0 0;
            opacity: 0.9;
        }
        .form-content {
            padding: 30px;
        }
        .form-group {
            margin-bottom: 25px;
        }
        label {
            display: flex;
            align-items: center;
            font-weight: bold;
            color: #1d3557;
            margin-bottom: 8px;
            font-size: 16px;
        }
        label i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }
        input {
            width: 100%;
            padding: 12px 15px;
            border-radius: 8px;
            border: 2px solid #e0e6ed;
            background-color: #f8f9fa;
            font-size: 16px;
            transition: all 0.3s;
            box-sizing: border-box;
        }
        input:focus {
            outline: none;
            border-color: #d62828;
            background-color: white;
            box-shadow: 0 0 0 3px rgba(214, 40, 40, 0.1);
        }
        input:hover {
            border-color: #c0c6cd;
        }
        .password-input {
            position: relative;
        }
        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #6c757d;
            font-size: 18px;
        }
        .password-toggle:hover {
            color: #1d3557;
        }
        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 25px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }
        .btn i {
            font-size: 14px;
        }
        .btn-primary {
            background-color: #d62828;
            color: white;
        }
        .btn-primary:hover {
            background-color: #a61d1d;
            transform: translateY(-2px);
        }
        .btn-secondary {
            background-color: #6c757d;
            color: white;
        }
        .btn-secondary:hover {
            background-color: #545b62;
            transform: translateY(-2px);
        }
        .message {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .message.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .message.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .staff-info {
            background-color: #e9ecef;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            text-align: center;
            color: #495057;
        }
        .required {
            color: #d62828;
        }
    </style>
</head>
<body>
<div class="sidebar">
    <h2>LifeSaver Hub</h2>
    <a href="staff_account.php" class="active"><i class="fas fa-user"></i> My Account</a>
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
        <div class="form-header">
            <h2><i class="fas fa-edit"></i> Update My Account</h2>
            <p>Modify your account information below</p>
        </div>
        
        <div class="form-content">
            <div class="staff-info">
                <i class="fas fa-id-badge"></i> Staff ID: <strong><?= htmlspecialchars($staff['StaffID']) ?></strong>
            </div>

            <?php if ($success): ?>
                <div class="message success">
                    <i class="fas fa-check-circle"></i>
                    Account updated successfully! Your changes have been saved.
                </div>
            <?php elseif ($error): ?>
                <div class="message error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" id="updateForm">
                <div class="form-group">
                    <label for="name">
                        <i class="fas fa-user"></i>
                        Full Name <span class="required">*</span>
                    </label>
                    <input type="text" id="name" name="name" value="<?= htmlspecialchars($staff['StaffName']) ?>" required>
                </div>

                <div class="form-group">
                    <label for="email">
                        <i class="fas fa-envelope"></i>
                        Email Address <span class="required">*</span>
                    </label>
                    <input type="email" id="email" name="email" value="<?= htmlspecialchars($staff['StaffEmail']) ?>" required>
                </div>

                <div class="form-group">
                    <label for="contact">
                        <i class="fas fa-phone"></i>
                        Contact Number <span class="required">*</span>
                    </label>
                    <input type="text" id="contact" name="contact" value="<?= htmlspecialchars($staff['StaffContact']) ?>" required>
                </div>

                <div class="form-group">
                    <label for="password">
                        <i class="fas fa-key"></i>
                        Password <span class="required">*</span>
                    </label>
                    <div class="password-input">
                        <input type="password" id="password" name="password" value="<?= htmlspecialchars($staff['StaffPassword']) ?>" required>
                        <i class="fas fa-eye password-toggle" onclick="togglePassword()"></i>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                    <a href="staff_account.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function togglePassword() {
    const passwordInput = document.getElementById('password');
    const toggleIcon = document.querySelector('.password-toggle');
    
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        toggleIcon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        passwordInput.type = 'password';
        toggleIcon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}

// Form validation
document.getElementById('updateForm').addEventListener('submit', function(e) {
    const name = document.getElementById('name').value.trim();
    const email = document.getElementById('email').value.trim();
    const contact = document.getElementById('contact').value.trim();
    const password = document.getElementById('password').value;
    
    if (!name || !email || !contact || !password) {
        e.preventDefault();
        alert('Please fill in all required fields.');
        return false;
    }
    
    // Basic email validation
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) {
        e.preventDefault();
        alert('Please enter a valid email address.');
        return false;
    }
});
</script>
</body>
</html>