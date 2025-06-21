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

// Enhanced notification function
function createEventNotification($pdo, $eventId, $title, $description, $date, $day, $venue) {
    try {
        // Create a general registration record for notification purposes
        $generalRegStmt = $pdo->prepare("INSERT INTO registration (StudentID, EventID, RegistrationDate, RegistrationStatus, AttendanceStatus) VALUES (0, ?, NOW(), 'General', 'N/A')");
        $generalRegStmt->execute([$eventId]);
        $generalRegistrationId = $pdo->lastInsertId();
        
        // Enhanced notification with better formatting
        $notificationTitle = "🩸 New Blood Donation Event: " . $title;
        $notificationMessage = "🌟 A new blood donation event has been scheduled!\n\n" .
                             "🎯 Event: " . $title . "\n" .
                             "📅 Date: " . $date . " (" . $day . ")\n" .
                             "📍 Venue: " . $venue . "\n\n" .
                             "📋 Description:\n" . $description . "\n\n" .
                             "🤝 Your participation can save lives!\n" .
                             "💡 Click 'Interested' to register and get updates about this event.\n\n" .
                             "❤️ Every donation counts - be someone's hero today!";
        
        $insertNotification = $pdo->prepare("INSERT INTO notification (NotificationTitle, NotificationMessage, NotificationDate, NotificationIsRead, RegistrationID) 
                                           VALUES (?, ?, NOW(), '0', ?)");
        $insertNotification->execute([$notificationTitle, $notificationMessage, $generalRegistrationId]);
        
        return true;
    } catch (Exception $e) {
        error_log("Notification creation failed: " . $e->getMessage());
        return false;
    }
}

// Enhanced event status calculation
function calculateEventStatus($date) {
    $today = date('Y-m-d');
    $eventDate = date('Y-m-d', strtotime($date));
    
    if ($eventDate > $today) {
        return 'Upcoming';
    } elseif ($eventDate === $today) {
        return 'Ongoing';
    } else {
        return 'Past';
    }
}

// Handle event creation with enhanced validation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_event'])) {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $date = $_POST['date'];
    $day = $_POST['day'];
    $venue = trim($_POST['venue']);

    // Enhanced validation
    $validationErrors = [];
    
    if (!$title) $validationErrors[] = "Event title is required.";
    if (!$description) $validationErrors[] = "Event description is required.";
    if (!$date) $validationErrors[] = "Event date is required.";
    if (!$venue) $validationErrors[] = "Event venue is required.";
    
    if ($date && strtotime($date) < strtotime(date('Y-m-d'))) {
        $validationErrors[] = "Event date cannot be in the past.";
    }

    // Auto-calculate day if not provided
    if (!$day && $date) {
        $day = date('l', strtotime($date));
    }

    if (empty($validationErrors)) {
        $status = calculateEventStatus($date);

        try {
            // Begin transaction for data integrity
            $pdo->beginTransaction();

            // Insert the event using your existing table structure
            $stmt = $pdo->prepare("INSERT INTO event (EventTitle, EventDescription, EventDate, EventDay, EventVenue, EventStatus, StaffID) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$title, $description, $date, $day, $venue, $status, $staffId]);
            
            // Get the newly created event ID
            $eventId = $pdo->lastInsertId();
            
            // Create notification
            $notificationSuccess = createEventNotification($pdo, $eventId, $title, $description, $date, $day, $venue);
            
            // Commit transaction
            $pdo->commit();
            
            if ($notificationSuccess) {
                $success = "✅ Event created successfully! All students have been notified about the new blood donation event.";
            } else {
                $success = "⚠️ Event created successfully, but there was an issue sending notifications to students.";
            }
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $pdo->rollback();
            $errors[] = "❌ Error creating event: " . $e->getMessage();
        }
    } else {
        $errors = $validationErrors;
    }
}

// Enhanced filtering with search functionality
$where = [];
$params = [];

if (!empty($_GET['search'])) {
    $searchTerm = '%' . $_GET['search'] . '%';
    $where[] = "(EventTitle LIKE ? OR EventDescription LIKE ? OR EventVenue LIKE ?)";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
}

if (!empty($_GET['filter_date'])) {
    $where[] = "EventDate = ?";
    $params[] = $_GET['filter_date'];
}

if (!empty($_GET['filter_status'])) {
    $where[] = "EventStatus = ?";
    $params[] = $_GET['filter_status'];
}

if (!empty($_GET['filter_month'])) {
    $where[] = "DATE_FORMAT(EventDate, '%Y-%m') = ?";
    $params[] = $_GET['filter_month'];
}

$sql = "SELECT * FROM event";
if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY EventDate DESC, EventTitle ASC";

$events = $pdo->prepare($sql);
$events->execute($params);
$eventList = $events->fetchAll();

// Get event statistics using existing table
$statsQuery = $pdo->query("
    SELECT 
        EventStatus,
        COUNT(*) as count
    FROM event 
    GROUP BY EventStatus
");
$eventStats = $statsQuery->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Event - LifeSaver Hub</title>
    <style>
        body {
            background: linear-gradient(135deg, #f4f6fa 0%, #e8ecf3 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            min-height: 100vh;
        }
        .container {
            margin-left: 260px;
            padding: 30px;
            max-width: 1200px;
        }
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 250px;
            height: 100vh;
            background: linear-gradient(180deg, #1d3557 0%, #457b9d 100%);
            padding-top: 30px;
            color: white;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }
        .sidebar h2 {
            text-align: center;
            margin-bottom: 30px;
            font-size: 1.5em;
        }
        .sidebar a {
            display: block;
            padding: 15px 20px;
            color: white;
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
        }
        .sidebar a:hover, .sidebar a.active {
            background-color: rgba(255,255,255,0.1);
            border-left-color: #f1faee;
            padding-left: 25px;
        }
        .page-header {
            background: white;
            padding: 25px 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            border-left: 5px solid #d62828;
        }
        .page-header h1 {
            color: #1d3557;
            margin: 0;
            font-size: 2em;
        }
        .page-header p {
            color: #666;
            margin: 5px 0 0 0;
        }
        .form-container {
            background: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #1d3557;
        }
        input, textarea, select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e1e5e9;
            border-radius: 10px;
            box-sizing: border-box;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }
        input:focus, textarea:focus, select:focus {
            outline: none;
            border-color: #d62828;
            box-shadow: 0 0 0 3px rgba(214, 40, 40, 0.1);
        }
        .btn {
            background: linear-gradient(135deg, #d62828 0%, #a61c1c 100%);
            color: white;
            border: none;
            padding: 15px 30px;
            font-weight: bold;
            border-radius: 25px;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(214, 40, 40, 0.3);
        }
        .btn-secondary {
            background: linear-gradient(135deg, #457b9d 0%, #1d3557 100%);
        }
        .btn-secondary:hover {
            box-shadow: 0 5px 15px rgba(69, 123, 157, 0.3);
        }
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }
        .filter-container {
            background: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            align-items: end;
        }
        .table-container {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #e1e5e9;
        }
        th {
            background: linear-gradient(135deg, #1d3557 0%, #457b9d 100%);
            color: white;
            font-weight: 600;
        }
        tr:hover {
            background-color: #f8f9fa;
        }
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .status-upcoming { background: #d4edda; color: #155724; }
        .status-ongoing { background: #fff3cd; color: #856404; }
        .status-past { background: #f8d7da; color: #721c24; }
        
        @media (max-width: 768px) {
            .container { margin-left: 0; padding: 20px; }
            .sidebar { transform: translateX(-100%); }
            .form-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<div class="sidebar">
    <h2>🩸 LifeSaver Hub</h2>
    <a href="staff_account.php">👤 My Account</a>
    <a href="create_event.php" class="active">📅 Create Event</a>
    <a href="view_event.php">👁️ View Events</a>
    <a href="view_donation.php">🩸 View Donations</a>
    <a href="confirm_attendance.php">✅ Confirm Attendance</a>
    <a href="update_application.php">📝 Update Application</a>
    <a href="create_reward.php">🎁 Create Rewards</a>
    <a href="generate_report.php">📊 Generate Report</a>
    <a href="logout.php">🚪 Logout</a>
</div>

<div class="container">
    <div class="page-header">
        <h1>Create a New Blood Donation Event</h1>
        <p>Schedule events and automatically notify all students about upcoming blood donation opportunities</p>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <span>✅</span>
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error">
            <span>❌</span>
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endforeach; ?>

    <div class="form-container">
        <form method="POST" id="eventForm">
            <div class="form-row">
                <div class="form-group">
                    <label for="title">Event Title *</label>
                    <input type="text" name="title" id="title" placeholder="e.g., Blood Donation Drive 2025" required>
                </div>
                <div class="form-group">
                    <label for="venue">Event Venue *</label>
                    <input type="text" name="venue" id="venue" placeholder="e.g., University Main Hall, Room 101" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="date">Event Date *</label>
                    <input type="date" name="date" id="date" required>
                </div>
                <div class="form-group">
                    <label for="day">Day of Week *</label>
                    <select name="day" id="day" required>
                        <option value="">Auto-select based on date</option>
                        <option value="Sunday">Sunday</option>
                        <option value="Monday">Monday</option>
                        <option value="Tuesday">Tuesday</option>
                        <option value="Wednesday">Wednesday</option>
                        <option value="Thursday">Thursday</option>
                        <option value="Friday">Friday</option>
                        <option value="Saturday">Saturday</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label for="description">Event Description *</label>
                <textarea name="description" id="description" placeholder="Describe the event details, requirements, eligibility criteria, and what participants can expect..." rows="4" required></textarea>
            </div>

            <button type="submit" name="add_event" class="btn">
                <span>📢</span> Create Event & Notify Students
            </button>
        </form>
    </div>

    <div class="filter-container">
        <h3 style="margin-top: 0; color: #1d3557;">🔍 Filter & Search Events</h3>
        <form method="GET" class="filter-grid">
            <div class="form-group">
                <label for="search">Search Events</label>
                <input type="text" name="search" id="search" placeholder="Search title, description, venue..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
            </div>
            
            <div class="form-group">
                <label for="filter_date">Filter by Date</label>
                <input type="date" name="filter_date" id="filter_date" value="<?= htmlspecialchars($_GET['filter_date'] ?? '') ?>">
            </div>
            
            <div class="form-group">
                <label for="filter_month">Filter by Month</label>
                <input type="month" name="filter_month" id="filter_month" value="<?= htmlspecialchars($_GET['filter_month'] ?? '') ?>">
            </div>
            
            <div class="form-group">
                <label for="filter_status">Filter by Status</label>
                <select name="filter_status" id="filter_status">
                    <option value="">All Statuses</option>
                    <option value="Upcoming" <?= (($_GET['filter_status'] ?? '') === 'Upcoming') ? 'selected' : '' ?>>Upcoming</option>
                    <option value="Ongoing" <?= (($_GET['filter_status'] ?? '') === 'Ongoing') ? 'selected' : '' ?>>Ongoing</option>
                    <option value="Past" <?= (($_GET['filter_status'] ?? '') === 'Past') ? 'selected' : '' ?>>Past</option>
                </select>
            </div>
            
            <div class="form-group">
                <button type="submit" class="btn btn-secondary">Apply Filters</button>
            </div>
            
            <div class="form-group">
                <a href="create_event.php" class="btn btn-secondary" style="text-decoration: none; text-align: center;">Clear All</a>
            </div>
        </form>
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>📅 Event Details</th>
                    <th>📍 Venue & Date</th>
                    <th>📊 Status</th>
                    <th>👤 Created By</th>
                    <th>📈 Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($eventList): foreach ($eventList as $event): ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($event['EventTitle']) ?></strong><br>
                        <small style="color: #666;"><?= htmlspecialchars(substr($event['EventDescription'], 0, 80)) ?>...</small>
                    </td>
                    <td>
                        <strong><?= htmlspecialchars($event['EventVenue']) ?></strong><br>
                        <small><?= htmlspecialchars($event['EventDate']) ?> (<?= htmlspecialchars($event['EventDay']) ?>)</small>
                    </td>
                    <td>
                        <span class="status-badge status-<?= strtolower($event['EventStatus']) ?>">
                            <?= htmlspecialchars($event['EventStatus']) ?>
                        </span>
                    </td>
                    <td>
                        Staff ID: <?= htmlspecialchars($event['StaffID']) ?>
                    </td>
                    <td>
                        <a href="view_event.php?id=<?= $event['EventID'] ?>" style="color: #1d3557; text-decoration: none;">👁️ View</a><br>
                        <a href="update_event.php?id=<?= $event['EventID'] ?>" style="color: #457b9d; text-decoration: none;">✏️ Edit</a>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr>
                    <td colspan="5" style="text-align: center; color: #666; padding: 40px;">
                        📭 No events found matching your criteria.<br>
                        <small>Try adjusting your filters or create a new event.</small>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// Enhanced JavaScript functionality
document.addEventListener('DOMContentLoaded', function() {
    const dateInput = document.getElementById('date');
    const daySelect = document.getElementById('day');
    const eventForm = document.getElementById('eventForm');

    // Set minimum date to today
    const today = new Date();
    dateInput.min = today.toISOString().split('T')[0];

    // Auto-fill day when date is selected
    dateInput.addEventListener('change', function() {
        if (this.value) {
            const date = new Date(this.value);
            const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            daySelect.value = days[date.getDay()];
        }
    });

    // Form validation enhancement
    eventForm.addEventListener('submit', function(e) {
        const title = document.getElementById('title').value.trim();
        const description = document.getElementById('description').value.trim();
        const date = document.getElementById('date').value;
        const venue = document.getElementById('venue').value.trim();

        if (!title || !description || !date || !venue) {
            e.preventDefault();
            alert('Please fill in all required fields.');
            return false;
        }

        // Confirm submission
        if (!confirm('Are you sure you want to create this event? All students will be notified immediately.')) {
            e.preventDefault();
            return false;
        }
    });

    // Auto-save form data to prevent loss
    const formInputs = eventForm.querySelectorAll('input, textarea, select');
    formInputs.forEach(input => {
        // Load saved data
        const savedValue = localStorage.getItem('event_form_' + input.name);
        if (savedValue && input.type !== 'submit') {
            input.value = savedValue;
        }

        // Save data on change
        input.addEventListener('input', function() {
            localStorage.setItem('event_form_' + this.name, this.value);
        });
    });

    // Clear saved data on successful submission
    eventForm.addEventListener('submit', function() {
        formInputs.forEach(input => {
            localStorage.removeItem('event_form_' + input.name);
        });
    });
});
</script>

</body>
</html>