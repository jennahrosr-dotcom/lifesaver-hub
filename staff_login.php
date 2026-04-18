<?php
session_start();
$pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $staffName = $_POST['staff_name'] ?? '';
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM staff WHERE StaffName = ?");
    $stmt->execute([$staffName]);
    $staff = $stmt->fetch();

    if ($staff && $password === $staff['StaffPassword']) {
        $_SESSION['staff_id'] = $staff['StaffID'];
        $_SESSION['staff_name'] = $staff['StaffName'];
        header("Location: staff_dashboard.php");
        exit;
    } else {
        $error = "Invalid Staff Name or Password.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Staff Login - LifeSaver Hub</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f4f6fa;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            text-align: center;
        }

        .logo {
            width: 150px;
            margin-bottom: 20px;
        }

        h1 {
            color: #d62828;
            margin-bottom: 30px;
            font-size: 24px;
        }

        .login-box {
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            max-width: 400px;
            width: 100%;
        }

        h2 {
            margin-bottom: 20px;
            font-size: 22px;
            color: #111;
        }

        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }

        label {
            display: block;
            margin-bottom: 5px;
            color: #333;
            font-weight: 500;
        }

        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 6px;
            background-color: #e9ecef;
        }

        .submit-btn {
            width: 100%;
            padding: 12px;
            background-color: #d62828;
            color: white;
            border: none;
            border-radius: 25px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        .submit-btn:hover {
            background-color: #a61d1d;
        }

        .back-link {
            margin-top: 20px;
            color: #1d3557;
            text-decoration: none;
            font-size: 14px;
            display: inline-block;
        }

        .error {
            color: red;
            margin-bottom: 15px;
        }

        @media (max-width: 500px) {
            h1 {
                font-size: 18px;
            }

            .login-box {
                padding: 20px;
            }
        }
    </style>
</head>
<body>

    <img src="images/logo.jpg" alt="LifeSaver Hub Logo" class="logo">

    <h1>WELCOME TO LIFESAVER HUB: UiTM JASIN BLOOD DONATION</h1>

    <div class="login-box">
        <h2>STAFF LOGIN</h2>
        <?php if ($error): ?>
            <p class="error"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
        <form method="POST">
            <div class="form-group">
                <label for="staff_name">USERNAME</label>
                <input type="text" id="staff_name" name="staff_name" required>
            </div>
            <div class="form-group">
                <label for="password">PASSWORD</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" class="submit-btn">LOGIN</button>
        </form>
        <a href="index.php" class="back-link">Back to selection</a>
    </div>

</body>
</html>
