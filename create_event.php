<?php
session_start();

if (!isset($_SESSION['staff_id'])) {
    header("Location: staff_login.php");
    exit;
}

$pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$errors = [];
$success = '';
$staffId = $_SESSION['staff_id'];

// Handle event creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_event'])) {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $date = $_POST['date'];
    $day = $_POST['day'];
    $venue = trim($_POST['venue']);

    if (!$title || !$description || !$date || !$day || !$venue) {
        $errors[] = "All fields are required.";
    } elseif (strtotime($date) < strtotime(date('Y-m-d'))) {
        $errors[] = "Event date cannot be in the past.";
    } else {
        $status = (strtotime($date) > strtotime(date('Y-m-d'))) ? 'Upcoming' : 'Ongoing';
        $stmt = $pdo->prepare("INSERT INTO event (EventTitle, EventDescription, EventDate, EventDay, EventVenue, EventStatus, StaffID) 
                               VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$title, $description, $date, $day, $venue, $status, $staffId]);
        $success = "Event created successfully.";
    }
}

// Filters
$where = [];
$params = [];

if (!empty($_GET['filter_date'])) {
    $where[] = "EventDate = ?";
    $params[] = $_GET['filter_date'];
}
if (!empty($_GET['filter_status'])) {
    $where[] = "EventStatus = ?";
    $params[] = $_GET['filter_status'];
}

$sql = "SELECT * FROM event";
if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY EventDate DESC";

$events = $pdo->prepare($sql);
$events->execute($params);
$eventList = $events->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create Event - LifeSaver Hub</title>
    <style>
        body {
            background: #f4f6fa;
            font-family: 'Segoe UI', sans-serif;
            margin: 0;
        }
        .container {
            margin-left: 260px;
            padding: 30px;
        }
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 250px;
            height: 100vh;
            background-color: #1d3557;
            padding-top: 30px;
            color: white;
        }
        .sidebar h2 {
            text-align: center;
        }
        .sidebar a {
            display: block;
            padding: 15px 20px;
            color: white;
            text-decoration: none;
        }
        .sidebar a:hover {
            background-color: #457b9d;
        }
        h1 {
            color: #1d3557;
        }
        form {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        input, textarea, select {
            width: 100%;
            padding: 10px;
            margin-top: 10px;
            margin-bottom: 15px;
            border: 1px solid #ccc;
            border-radius: 8px;
        }
        button {
            background-color: #d62828;
            color: white;
            border: none;
            padding: 12px 25px;
            font-weight: bold;
            border-radius: 25px;
            cursor: pointer;
        }
        button:hover {
            background-color: #a61c1c;
        }
        .success {
            color: green;
        }
        .error {
            color: red;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            margin-top: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background-color: #1d3557;
            color: white;
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

<div class="container">
    <h1>Create a New Event</h1>

    <?php if ($success): ?><p class="success"><?= $success ?></p><?php endif; ?>
    <?php foreach ($errors as $e): ?><p class="error"><?= $e ?></p><?php endforeach; ?>

    <form method="POST">
        <input type="text" name="title" placeholder="Event Title" required>
        <textarea name="description" placeholder="Event Description" rows="4" required></textarea>
        <input type="date" name="date" id="date" required>
        <select name="day" id="day" required>
            <option value="">Select Day</option>
            <option value="Sunday">Sunday</option>
            <option value="Monday">Monday</option>
            <option value="Tuesday">Tuesday</option>
            <option value="Wednesday">Wednesday</option>
            <option value="Thursday">Thursday</option>
            <option value="Friday">Friday</option>
            <option value="Saturday">Saturday</option>
        </select>
        <input type="text" name="venue" placeholder="Event Venue" required>
        <button type="submit" name="add_event">Create Event</button>
    </form>

    <h2>All Events</h2>
    <table>
        <thead>
            <tr><th>Title</th><th>Date</th><th>Day</th><th>Venue</th><th>Status</th></tr>
        </thead>
        <tbody>
        <?php if ($eventList): foreach ($eventList as $e): ?>
            <tr>
                <td><?= htmlspecialchars($e['EventTitle']) ?></td>
                <td><?= htmlspecialchars($e['EventDate']) ?></td>
                <td><?= htmlspecialchars($e['EventDay']) ?></td>
                <td><?= htmlspecialchars($e['EventVenue']) ?></td>
                <td><?= htmlspecialchars($e['EventStatus']) ?></td>
            </tr>
        <?php endforeach; else: ?>
            <tr><td colspan="5">No events found.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
    document.getElementById('date').addEventListener('change', function() {
        const date = new Date(this.value);
        const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        document.getElementById('day').value = days[date.getDay()];
    });
</script>

</body>
</html>
