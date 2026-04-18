<?php
session_start();
$pdo = new PDO("mysql:host=127.0.0.1;port=3307;dbname=lifesaver;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM student WHERE StudentName = ?");
    $stmt->execute([$username]);
    $student = $stmt->fetch();

    if ($student && $password === $student['StudentPassword']) {
        $_SESSION['student_id'] = $student['StudentID'];
        $_SESSION['student_name'] = $student['StudentName'];
        header("Location: student_dashboard.php");
        exit;
    } else {
        $error = "Invalid username or password.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Student Login - LifeSaver Hub</title>
    <style>
        * {
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            margin: 0;
            background-color: #f4f6fa;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }

        .logo {
            width: 150px;
            margin-bottom: 20px;
        }

        h1 {
            color: #d62828;
            text-align: center;
            margin-bottom: 40px;
        }

        .login-box {
            background-color: #ffffff;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            max-width: 400px;
            width: 100%;
            text-align: center;
        }

        .login-box h2 {
            font-size: 20px;
            margin-bottom: 10px;
            color: #1d3557;
        }

        .role-btn {
            background-color: #2d2a8f;
            color: white;
            padding: 10px 20px;
            border-radius: 20px;
            margin-bottom: 20px;
            font-weight: bold;
            border: none;
        }

        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }

        .form-group label {
            font-weight: 600;
            color: #6c757d;
            margin-bottom: 5px;
            display: block;
        }

        .form-group input {
            width: 100%;
            padding: 12px;
            background: #e9ecef;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-size: 15px;
        }

        .submit-btn {
            width: 100%;
            padding: 12px;
            background-color: #d62828;
            color: white;
            border: none;
            border-radius: 25px;
            font-weight: bold;
            font-size: 16px;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        .submit-btn:hover {
            background-color: #a61d1d;
        }

        .bottom-text {
            margin-top: 20px;
            font-size: 14px;
        }

        .bottom-text a {
            color: #d62828;
            font-weight: bold;
            text-decoration: none;
            margin-left: 5px;
        }

        .error {
            color: red;
            font-size: 14px;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>

    <img src="images/logo.jpg" alt="LifeSaver Logo" class="logo">

    <h1>WELCOME TO LIFESAVER HUB: UiTM JASIN BLOOD DONATION</h1>

    <div class="login-box">
        <h2>LOG IN TO YOUR ACCOUNT</h2>
        <div class="role-btn">STUDENT</div>

        <?php if ($error): ?>
            <p class="error"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label for="username">USERNAME</label>
                <input type="text" name="username" id="username" required>
            </div>

            <div class="form-group">
                <label for="password">PASSWORD</label>
                <input type="password" name="password" id="password" required>
            </div>

            <button type="submit" class="submit-btn">LOG IN</button>
        </form>

        <div class="bottom-text">
            DO NOT HAVE AN ACCOUNT?
            <a href="signup.php">SIGN UP</a>
        </div>
    </div>

</body>
</html>
