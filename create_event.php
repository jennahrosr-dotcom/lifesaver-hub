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

    // Fallback: Calculate day if JavaScript is disabled
    if (!$day && $date) {
        $day = date('l', strtotime($date));
    }

    if (!$title || !$description || !$date || !$day || !$venue) {
        $errors[] = "All fields are required.";
    } elseif (strtotime($date) < strtotime(date('Y-m-d'))) {
        $errors[] = "Event date cannot be in the past.";
    } else {
        if ($date > date('Y-m-d')) {
            $status = 'Upcoming';
        } elseif ($date == date('Y-m-d')) {
            $status = 'Ongoing';
        } else {
            $status = 'Past';
        }

        try {
            // Begin transaction
            $pdo->beginTransaction();

            // Insert the event
            $stmt = $pdo->prepare("INSERT INTO event (EventTitle, EventDescription, EventDate, EventDay, EventVenue, EventStatus, StaffID) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$title, $description, $date, $day, $venue, $status, $staffId]);
            
            // Get the newly created event ID
            $eventId = $pdo->lastInsertId();
            
            // Create a general registration record for notification purposes
            $generalRegStmt = $pdo->prepare("INSERT INTO registration (StudentID, EventID, RegistrationDate, RegistrationStatus, AttendanceStatus) VALUES (0, ?, NOW(), 'General', 'N/A')");
            $generalRegStmt->execute([$eventId]);
            $generalRegistrationId = $pdo->lastInsertId();
            
            // Create notification for the event (using the general registration)
            $notificationTitle = "New Blood Donation Event: " . $title;
            $notificationMessage = "🩸 A new blood donation event has been scheduled!\n\n" .
                                 "📅 Event: " . $title . "\n" .
                                 "📆 Date: " . $date . " (" . $day . ")\n" .
                                 "📍 Venue: " . $venue . "\n\n" .
                                 "📝 Description: " . $description . "\n\n" .
                                 "💡 Click 'Interested' if you'd like to participate and get automatically registered for this event!";
            
            $insertNotification = $pdo->prepare("INSERT INTO notification (NotificationTitle, NotificationMessage, NotificationDate, NotificationIsRead, RegistrationID) 
                                               VALUES (?, ?, NOW(), '0', ?)");
            $insertNotification->execute([$notificationTitle, $notificationMessage, $generalRegistrationId]);
            
            // Commit transaction
            $pdo->commit();
            
            $success = "Event created successfully and notification has been posted for all students to see.";
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $pdo->rollback();
            $errors[] = "Error creating event: " . $e->getMessage();
        }
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
            box-sizing: border-box;
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
            background: #d4edda;
            padding: 10px;
            border-radius: 5px;
            margin: 10px 0;
        }
        .error {
            color: red;
            background: #f8d7da;
            padding: 10px;
            border-radius: 5px;
            margin: 10px 0;
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
        .filter-form {
            margin-bottom: 20px;
            background: white;
            padding: 15px;
            border-radius: 10px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        .filter-form input, .filter-form select {
            width: 200px;
            margin-right: 10px;
            display: inline-block;
        }
    </style>
</head>
<body>

<div class="sidebar">
    <h2>LifeSaver Hub</h2>
    <a href="staff_account.php">My Account</a>
    <a href="create_event.php">Create Event</a>
    <a href="view_event.php">View Events</a>
    <a href="view_donation.php">View Donations</a>
    <a href="confirm_attendance.php">Confirm Attendance</a>
    <a href="update_application.php">Update Application</a>
    <a href="create_reward.php">Create Rewards</a>
    <a href="generate_report.php">Generate Report</a>
    <a href="logout.php">Logout</a>
</div>

<div class="container">
    <h1>Create a New Event</h1>

    <?php if ($success): ?><p class="success"><?= htmlspecialchars($success) ?></p><?php endif; ?>
    <?php foreach ($errors as $e): ?><p class="error"><?= htmlspecialchars($e) ?></p><?php endforeach; ?>

    <form method="POST">
        <label for="title">Event Title:</label>
        <input type="text" name="title" id="title" placeholder="e.g., Blood Donation Drive 2025" required>
        
        <label for="description">Event Description:</label>
        <textarea name="description" id="description" placeholder="Describe the event details, requirements, and what to expect..." rows="4" required></textarea>
        
        <label for="date">Event Date:</label>
        <input type="date" name="date" id="date" required>
        
        <label for="day">Day of Week:</label>
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
        
        <label for="venue">Event Venue:</label>
        <input type="text" name="venue" id="venue" placeholder="e.g., University Main Hall, Room 101" required>
        
        <button type="submit" name="add_event">Create Event & Notify Students</button>
    </form>

    <h2>Filter Events</h2>
    <form method="GET" class="filter-form">
        <label for="filter_date">Filter by Date:</label>
        <input type="date" name="filter_date" id="filter_date" value="<?= htmlspecialchars($_GET['filter_date'] ?? '') ?>">
        
        <label for="filter_status">Filter by Status:</label>
        <select name="filter_status" id="filter_status">
            <option value="">All</option>
            <option value="Upcoming" <?= (($_GET['filter_status'] ?? '') === 'Upcoming') ? 'selected' : '' ?>>Upcoming</option>
            <option value="Ongoing" <?= (($_GET['filter_status'] ?? '') === 'Ongoing') ? 'selected' : '' ?>>Ongoing</option>
            <option value="Past" <?= (($_GET['filter_status'] ?? '') === 'Past') ? 'selected' : '' ?>>Past</option>
        </select>
        
        <button type="submit">Apply Filters</button>
        <a href="create_event.php" style="margin-left: 10px; color: #1d3557; text-decoration: none;">Clear Filters</a>
    </form>

    <h2>All Events</h2>
    <table>
        <thead>
            <tr>
                <th>Title</th>
                <th>Date</th>
                <th>Day</th>
                <th>Venue</th>
                <th>Status</th>
                <th>Created By</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($eventList): foreach ($eventList as $e): ?>
            <tr>
                <td><?= htmlspecialchars($e['EventTitle']) ?></td>
                <td><?= htmlspecialchars($e['EventDate']) ?></td>
                <td><?= htmlspecialchars($e['EventDay']) ?></td>
                <td><?= htmlspecialchars($e['EventVenue']) ?></td>
                <td>
                    <span style="
                        padding: 3px 8px; 
                        border-radius: 12px; 
                        font-size: 12px; 
                        font-weight: bold;
                        background-color: <?= $e['EventStatus'] === 'Upcoming' ? '#d4edda' : ($e['EventStatus'] === 'Ongoing' ? '#fff3cd' : '#f8d7da') ?>;
                        color: <?= $e['EventStatus'] === 'Upcoming' ? '#155724' : ($e['EventStatus'] === 'Ongoing' ? '#856404' : '#721c24') ?>;
                    ">
                        <?= htmlspecialchars($e['EventStatus']) ?>
                    </span>
                </td>
                <td>Staff ID: <?= htmlspecialchars($e['StaffID']) ?></td>
            </tr>
        <?php endforeach; else: ?>
            <tr><td colspan="6" style="text-align: center; color: #666;">No events found.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
    // Auto-fill day when date is selected
    document.getElementById('date').addEventListener('change', function() {
        const date = new Date(this.value);
        const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        document.getElementById('day').value = days[date.getDay()];
    });

    // Set minimum date to today
    document.getElementById('date').min = new Date().toISOString().split('T')[0];
</script>

</body>
</html>