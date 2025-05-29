<?php
session_start();

if (!isset($_SESSION['student_id'])) {
    header("Location: index.php");
    exit;
}

$pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$studentId = $_SESSION['student_id'];
$eligible = true;
$ineligibleQuestions = [];
$message = '';

// Check if student already submitted today
$checkToday = $pdo->prepare("SELECT * FROM healthquestion WHERE RegistrationID = ? AND HealthDate = CURDATE()");
$checkToday->execute([$studentId]);
$existingToday = $checkToday->fetch();

// Check if student is already eligible and has applied
$checkStatus = $pdo->prepare("SELECT * FROM healthquestion WHERE RegistrationID = ? ORDER BY HealthDate DESC LIMIT 1");
$checkStatus->execute([$studentId]);
$lastHealth = $checkStatus->fetch();
$preventSubmission = false;

if ($existingToday) {
    $preventSubmission = true;
    $message = "You have already submitted the health questionnaire today. Please try again next time.";
} elseif ($lastHealth && $lastHealth['HealthStatus'] === 'Eligible') {
    $preventSubmission = true;
    $message = "You are eligible to donate. You may proceed to apply for a donation.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$preventSubmission) {
    $yesNoAnswers = array_map('intval', $_POST['answers'] ?? []);
    $problem = trim($_POST['problem'] ?? '');
    $medication = trim($_POST['medication'] ?? '');
    $conditions = trim($_POST['conditions'] ?? '');
    $womenStatus = trim($_POST['women_status'] ?? '');

    foreach ($yesNoAnswers as $index => $answer) {
        if ($index !== 0 && $answer === 1) {
            $eligible = false;
            $ineligibleQuestions[] = $index + 1;
        }
    }

    $status = $eligible ? 'Eligible' : 'Not Eligible';
    $stmt = $pdo->prepare("INSERT INTO healthquestion (HealthDate, HealthStatus, RegistrationID) VALUES (CURDATE(), ?, ?)");
    $stmt->execute([$status, $studentId]);

    if ($eligible) {
        header("Location: apply_donation.php");
        exit;
    } else {
        $message = "You are not eligible to donate blood at this time. Please try again next time.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Health Questionnaire - LifeSaver Hub</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
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
        .main {
            margin-left: 250px;
            padding: 30px;
        }
        .question {
            margin: 15px 0;
        }
        .options label {
            margin-right: 15px;
        }
        .input-text {
            margin-left: 10px;
        }
        .btn-submit, .btn-back {
            padding: 12px 30px;
            font-size: 16px;
            font-weight: bold;
            border: none;
            border-radius: 25px;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-right: 10px;
        }
        .btn-submit {
            background-color: #6a0dad;
            color: white;
        }
        .btn-submit:hover {
            background-color: #580ea3;
        }
        .btn-back {
            background-color: #dc3545;
            color: white;
        }
        .btn-back:hover {
            background-color: #a71d2a;
        }
        .message {
            font-weight: bold;
            color: red;
            margin: 20px 0;
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
<div class="main">
    <h1>Health Questionnaire</h1>
    <?php if ($message): ?>
    <p class="message"><?= htmlspecialchars($message) ?></p>

    <?php if ($lastHealth && $lastHealth['HealthStatus'] === 'Eligible'): ?>
        <a href="apply_donation.php" class="btn-submit" style="background-color: #28a745; color: white; text-decoration: none; display: inline-block; margin-top: 20px;">
            Apply for Donation
        </a>
    <?php endif; ?>
<?php endif; ?>

    <?php if (!$preventSubmission): ?>
    <form method="POST">
        <?php
        $questions = [
            "Are you feeling healthy today?",
            "Are you planning to donate blood just to check if you have diseases like HIV or Hepatitis?",
            "Have you donated blood before?",
            "In the past 7 days, have you taken any medication?",
            "Have you had a fever, flu, or cough recently?",
            "Do you have any long-term health conditions?",
            "Have you had surgery or a blood transfusion in the past 6 months?",
            "Have you gotten a tattoo, body piercing, or acupuncture in the past 6 months?",
            "Have you taken any beauty injections (e.g., botox) in the past 4 weeks?",
            "Have you consumed alcohol in the past 24 hours?",
            "Have you tested positive for any sexually transmitted diseases (e.g., syphilis)?",
            "For men: Have you ever had sexual relations with other men?",
            "Have you ever paid for or been paid for sexual services?",
            "Have you injected drugs or used illegal substances?",
            "For women: Are you currently pregnant, breastfeeding, or on your period?"
        ];
        foreach ($questions as $index => $q) {
            echo "<div class='question'><strong>" . ($index+1) . ".</strong> $q<br>
            <div class='options'>
                <label><input type='radio' name='answers[$index]' value='0' required> No</label>
                <label><input type='radio' name='answers[$index]' value='1'> Yes</label>
            </div></div>";
            if ($index === 2) echo "<input type='text' name='problem' class='input-text' placeholder='If yes, did you face any problems during or after donation?'>";
            if ($index === 3) echo "<input type='text' name='medication' class='input-text' placeholder='If yes, what kind?'>";
            if ($index === 5) echo "<input type='text' name='conditions' class='input-text' placeholder='Please specify'>";
        }
        ?>
        <button class="btn-submit" type="submit">SUBMIT</button>
        <a href="student_dashboard.php" class="btn-back">BACK</a>
    </form>
    <?php endif; ?>
</div>
</body>
</html>
