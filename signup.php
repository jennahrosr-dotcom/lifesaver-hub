<?php
session_start();
require_once 'db.php';

$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['signup_username'] ?? '');
    $email = trim($_POST['signup_email'] ?? '');
    $contact = trim($_POST['signup_contact'] ?? '');
    $password = $_POST['signup_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // Validation
    if (!$name || !$email || !$contact || !$password || !$confirmPassword) {
        $error = "Please fill in all required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";
    } elseif (!preg_match('/^[0-9\-\+\s\(\)]+$/', $contact)) {
        $error = "Invalid contact number.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } elseif ($password !== $confirmPassword) {
        $error = "Passwords do not match.";
    } else {
        // Check if email already exists
        $stmt = $conn->prepare("SELECT StudentID FROM student WHERE StudentEmail = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $error = "Email already registered.";
        } else {
            // Insert student
            $hashedPassword = $password;
            $insertStmt = $conn->prepare("INSERT INTO student (StudentName, StudentContact, StudentEmail, StudentPassword) VALUES (?, ?, ?, ?)");
            $insertStmt->bind_param("siss", $name, $contact, $email, $hashedPassword);

            if ($insertStmt->execute()) {
                $success = true;
                header("refresh:2;url=student_login.php");
            } else {
                $error = "Registration failed. Please try again.";
            }
            $insertStmt->close();
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Student Sign Up - LifeSaver Hub</title>
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }

        .container {
            background-color: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 450px;
            text-align: center;
        }

        .container h1 {
            color: #d62828;
            margin-bottom: 25px;
        }

        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }

        .form-group label {
            display: block;
            font-weight: bold;
            margin-bottom: 8px;
            color: #1d3557;
        }

        .form-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }

        .submit-btn {
            background-color: #d62828;
            color: white;
            padding: 12px;
            width: 100%;
            border: none;
            border-radius: 25px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .submit-btn:hover {
            background-color: #c21f1f;
        }

        .message {
            margin-bottom: 15px;
            padding: 10px;
            border-radius: 5px;
        }

        .error-message {
            background-color: #f8d7da;
            color: #dc3545;
            border: 1px solid #f5c6cb;
        }

        .success-message {
            background-color: #d4edda;
            color: #28a745;
            border: 1px solid #c3e6cb;
        }

        .back-link {
            margin-top: 15px;
            display: inline-block;
            color: #1d3557;
            cursor: pointer;
        }

        .back-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
<div class="container">
    <h1>Student Sign Up</h1>

    <?php if ($error): ?>
        <div class="message error-message"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="message success-message">Registration successful! Redirecting to login...</div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="form-group">
            <label for="signup-username">Name</label>
            <input type="text" id="signup-username" name="signup_username" required value="<?= htmlspecialchars($_POST['signup_username'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label for="signup-email">Email</label>
            <input type="email" id="signup-email" name="signup_email" required value="<?= htmlspecialchars($_POST['signup_email'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label for="signup-contact">Contact</label>
            <input type="text" id="signup-contact" name="signup_contact" required value="<?= htmlspecialchars($_POST['signup_contact'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label for="signup-password">Password</label>
            <input type="password" id="signup-password" name="signup_password" required>
        </div>
        <div class="form-group">
            <label for="confirm-password">Confirm Password</label>
            <input type="password" id="confirm-password" name="confirm_password" required>
        </div>
        <button type="submit" class="submit-btn">Sign Up</button>
    </form>
    <p class="back-link" onclick="window.location.href='student_login.php'">Back to Login</p>
</div>
</body>
</html>
