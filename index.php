<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Welcome - LifeSaver Hub</title>
    <style>
        body {
            background-color: #f4f6fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-direction: column;
            height: 100vh;
            margin: 0;
        }

        .logo {
            max-width: 150px;
            margin-bottom: 20px;
        }

        h1 {
            color: #d62828;
            text-align: center;
            margin-bottom: 10px;
        }

        h2 {
            color: #1d3557;
            font-size: 1rem;
            margin-bottom: 30px;
        }

        .btn-container {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .login-btn {
            background-color: #1d1e4b;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 25px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            transition: background-color 0.3s ease;
        }

        .login-btn:hover {
            background-color: #141538;
        }
    </style>
</head>
<body>

    <img src="images/logo.jpg" alt="LifeSaver Hub Logo" class="logo">

    <h1>WELCOME TO LIFESAVER HUB: UiTM JASIN BLOOD DONATION</h1>
    <h2>LOG IN TO YOUR ACCOUNT</h2>

    <div class="btn-container">
        <a href="staff_login.php" class="login-btn">STAFF</a>
        <a href="student_login.php" class="login-btn">STUDENT</a>
    </div>

</body>
</html>
