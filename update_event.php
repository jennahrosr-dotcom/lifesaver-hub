<?php
session_start();
if (!isset($_SESSION['staff_id'])) {
    header("Location: staff_login.php");
    exit;
}

$pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$event = null;
$error = '';
$success = '';
$staffId = $_SESSION['staff_id'];

if (!isset($_GET['id'])) {
    header("Location: view_event.php");
    exit;
}

$id = $_GET['id'];
$stmt = $pdo->prepare("SELECT * FROM event WHERE EventID = ?");
$stmt->execute([$id]);
$event = $stmt->fetch();

if (!$event || $event['EventStatus'] === 'Deleted') {
    $error = "This event has been deleted or does not exist.";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = $_POST['title'];
    $description = $_POST['description'];
    $date = $_POST['date'];
    $day = $_POST['day'];
    $venue = $_POST['venue'];

    if (!$title || !$description || !$date || !$day || !$venue) {
        $error = "All fields are required.";
    } elseif (strtotime($date) < strtotime(date('Y-m-d'))) {
        $error = "Event date cannot be in the past.";
    } else {
        $status = (strtotime($date) > strtotime(date('Y-m-d'))) ? 'Upcoming' : 'Ongoing';
        $stmt = $pdo->prepare("UPDATE event SET EventTitle=?, EventDescription=?, EventDate=?, EventDay=?, EventVenue=?, EventStatus=? WHERE EventID=?");
        $stmt->execute([$title, $description, $date, $day, $venue, $status, $id]);
        $success = "Event updated successfully.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Update Event</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body {
            font-family: 'Segoe UI', sans-serif;
            background-color: #f4f6fa;
            margin: 0;
        }
        .sidebar {
            position: fixed;
            width: 250px;
            height: 100vh;
            background-color: #1d3557;
            color: white;
            padding-top: 30px;
        }
        .sidebar h2 {
            text-align: center;
        }
        .sidebar a {
            display: block;
            color: white;
            padding: 15px 20px;
            text-decoration: none;
        }
        .sidebar a:hover {
            background-color: #457b9d;
        }
        .main {
            margin-left: 260px;
            padding: 30px;
        }
        form {
            background-color: white;
            padding: 25px;
            border-radius: 10px;
            max-width: 700px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        input, textarea, select {
            width: 100%;
            padding: 12px;
            margin: 12px 0;
            border-radius: 6px;
            border: 1px solid #ccc;
        }
        button {
            background-color: #d62828;
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 25px;
            font-weight: bold;
            cursor: pointer;
        }
        button:hover {
            background-color: #a61c1c;
        }
        .message {
            margin-bottom: 20px;
            padding: 12px;
            border-radius: 6px;
        }
        .error {
            background-color: #ffcccc;
            color: #a30000;
        }
        .success {
            background-color: #d4edda;
            color: #155724;
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
    <h1>Update Event</h1>

    <?php if ($error): ?>
        <div class="message error"><?= htmlspecialchars($error) ?></div>
    <?php elseif ($success): ?>
        <div class="message success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php if ($event && $event['EventStatus'] !== 'Deleted'): ?>
    <form method="POST">
        <label>Title:</label>
        <input type="text" name="title" value="<?= htmlspecialchars($event['EventTitle']) ?>" required>

        <label>Description:</label>
        <textarea name="description" rows="4" required><?= htmlspecialchars($event['EventDescription']) ?></textarea>

        <label>Date:</label>
        <input type="date" name="date" id="dateInput" value="<?= htmlspecialchars($event['EventDate']) ?>" required>

        <label>Day:</label>
        <input type="text" name="day" id="dayInput" value="<?= htmlspecialchars($event['EventDay']) ?>" readonly required>

        <label>Venue:</label>
        <input type="text" name="venue" value="<?= htmlspecialchars($event['EventVenue']) ?>" required>

        <button type="submit">Update Event</button>
    </form>
    <?php endif; ?>
</div>

<script>
    document.getElementById('dateInput').addEventListener('change', function () {
        const date = new Date(this.value);
        const options = { weekday: 'long' };
        const dayName = new Intl.DateTimeFormat('en-US', options).format(date);
        document.getElementById('dayInput').value = dayName;
    });
</script>

</body>
</html>
