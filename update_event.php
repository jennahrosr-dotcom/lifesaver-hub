<?php
session_start();

if (!isset($_SESSION['staff_id'])) {
    header("Location: staff_login.php");
    exit;
}

$pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$staffId = $_SESSION['staff_id'];
$success = '';
$error = '';

// Get staff information
$stmt = $pdo->prepare("SELECT * FROM staff WHERE StaffID = ?");
$stmt->execute([$staffId]);
$staff = $stmt->fetch();

// Check if event ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error'] = "Event ID is required.";
    header("Location: staff_manage_events.php");
    exit;
}

$eventId = (int)$_GET['id'];

// Get event details
$stmt = $pdo->prepare("SELECT * FROM event WHERE EventID = ?");
$stmt->execute([$eventId]);
$event = $stmt->fetch();

if (!$event) {
    $_SESSION['error'] = "Event not found.";
    header("Location: staff_manage_events.php");
    exit;
}

// Check if event can be edited (not deleted)
if ($event['EventStatus'] === 'Deleted') {
    $_SESSION['error'] = "Cannot edit a deleted event. Please restore it first.";
    header("Location: staff_view_event.php?id=" . $eventId);
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Get form data
        $eventTitle = trim($_POST['event_title']);
        $eventDescription = trim($_POST['event_description']);
        $eventDate = $_POST['event_date'];
        $eventDay = trim($_POST['event_day']);
        $eventVenue = trim($_POST['event_venue']);
        $eventStatus = $_POST['event_status'];
        
        // Validation
        if (empty($eventTitle)) {
            throw new Exception("Event title is required.");
        }
        
        if (empty($eventDescription)) {
            throw new Exception("Event description is required.");
        }
        
        if (empty($eventDate)) {
            throw new Exception("Event date is required.");
        }
        
        if (empty($eventVenue)) {
            throw new Exception("Event venue is required.");
        }
        
        // Check if date is not in the past (unless it's a completed event)
        if ($eventStatus !== 'Completed' && $eventDate < date('Y-m-d')) {
            throw new Exception("Event date cannot be in the past for active events.");
        }
        
        // Update event
        $updateStmt = $pdo->prepare("
            UPDATE event SET 
            EventTitle = ?, 
            EventDescription = ?, 
            EventDate = ?, 
            EventDay = ?, 
            EventVenue = ?, 
            EventStatus = ?
            WHERE EventID = ?
        ");
        
        $result = $updateStmt->execute([
            $eventTitle, $eventDescription, $eventDate, $eventDay, 
            $eventVenue, $eventStatus, $eventId
        ]);
        
        if ($result) {
            $_SESSION['success'] = "Event updated successfully!";
            header("Location: staff_view_event.php?id=" . $eventId);
            exit;
        } else {
            throw new Exception("Failed to update event.");
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// If form was submitted and there was an error, use POST data, otherwise use database data
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($error)) {
    $formData = [
        'EventTitle' => $_POST['event_title'],
        'EventDescription' => $_POST['event_description'],
        'EventDate' => $_POST['event_date'],
        'EventDay' => $_POST['event_day'],
        'EventVenue' => $_POST['event_venue'],
        'EventStatus' => $_POST['event_status']
    ];
} else {
    $formData = $event;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Event - <?php echo htmlspecialchars($event['EventTitle']); ?> - LifeSaver Hub</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%);
            min-height: 100vh;
            color: #333;
            position: relative;
            overflow-x: hidden;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: 
                radial-gradient(circle at 15% 85%, rgba(255, 107, 107, 0.4) 0%, transparent 50%),
                radial-gradient(circle at 85% 15%, rgba(102, 126, 234, 0.4) 0%, transparent 50%),
                radial-gradient(circle at 50% 50%, rgba(240, 147, 251, 0.3) 0%, transparent 50%),
                radial-gradient(circle at 30% 40%, rgba(118, 75, 162, 0.3) 0%, transparent 50%);
            z-index: -1;
            animation: float 25s ease-in-out infinite;
        }

        body::after {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: 
                linear-gradient(45deg, transparent 40%, rgba(255, 255, 255, 0.1) 50%, transparent 60%),
                linear-gradient(-45deg, transparent 40%, rgba(255, 255, 255, 0.05) 50%, transparent 60%);
            z-index: -1;
            animation: shimmerMove 15s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            33% { transform: translateY(-20px) rotate(1deg); }
            66% { transform: translateY(-10px) rotate(-1deg); }
        }

        @keyframes shimmerMove {
            0%, 100% { transform: translateX(-100px) translateY(-100px); }
            50% { transform: translateX(100px) translateY(100px); }
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 280px;
            height: 100vh;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(25px);
            border-right: 1px solid rgba(255, 255, 255, 0.2);
            padding: 30px 0 20px 0;
            z-index: 1000;
            box-shadow: 5px 0 25px rgba(0, 0, 0, 0.1);
            border-radius: 0 25px 25px 0;
            overflow-y: auto;
            overflow-x: hidden;
        }

        .sidebar-header {
            text-align: center;
            margin-bottom: 40px;
            padding: 0 20px;
            position: relative;
        }

        .sidebar-header::before {
            content: '🩸';
            font-size: 3rem;
            display: block;
            margin-bottom: 10px;
            animation: pulse 2s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }

        .sidebar-header h2 {
            color: white;
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 5px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        .sidebar-header p {
            color: rgba(255, 255, 255, 0.8);
            font-size: 14px;
            font-weight: 500;
        }

        .sidebar-nav {
            padding: 0 15px;
        }

        .sidebar a {
            display: flex;
            align-items: center;
            color: rgba(255, 255, 255, 0.9);
            padding: 15px 20px;
            text-decoration: none;
            font-size: 15px;
            font-weight: 500;
            border-radius: 15px;
            margin: 8px 0;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative;
            overflow: hidden;
        }

        .sidebar a:hover {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            transform: translateX(10px) scale(1.05);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        .sidebar a.active {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.3), rgba(255, 255, 255, 0.1));
            color: white;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .sidebar a i {
            width: 20px;
            margin-right: 15px;
            text-align: center;
            font-size: 16px;
        }

        .main-content {
            margin-left: 280px;
            padding: 30px;
            min-height: 100vh;
        }

        .page-header {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(25px);
            border-radius: 25px;
            padding: 40px;
            margin-bottom: 30px;
            box-shadow: 0 15px 50px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
            animation: shimmer 8s linear infinite;
        }

        @keyframes shimmer {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .page-header-content {
            position: relative;
            z-index: 1;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .page-header h1 {
            font-size: 2.5rem;
            font-weight: 800;
            color: white;
            display: flex;
            align-items: center;
            gap: 20px;
            text-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
            margin: 0;
        }

        .page-header h1 i {
            background: linear-gradient(135deg, #f093fb, #00d2d3);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-size: 2rem;
        }

        .page-header p {
            color: rgba(255, 255, 255, 0.9);
            font-size: 16px;
            margin-top: 10px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            padding: 15px 30px;
            border: none;
            border-radius: 50px;
            font-weight: 700;
            font-size: 14px;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            text-align: center;
            white-space: nowrap;
            position: relative;
            overflow: hidden;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-family: inherit;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }

        .btn:hover::before {
            left: 100%;
        }

        .btn-outline {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 2px solid rgba(255, 255, 255, 0.4);
        }

        .btn-outline:hover {
            background: rgba(255, 255, 255, 0.3);
            border-color: rgba(255, 255, 255, 0.6);
            transform: translateY(-2px);
        }

        .btn-success {
            background: linear-gradient(135deg, #10ac84, #00d2d3);
            color: white;
            box-shadow: 0 8px 25px rgba(16, 172, 132, 0.4);
        }

        .btn-success:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 15px 35px rgba(16, 172, 132, 0.6);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
        }

        .btn-secondary:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 15px 35px rgba(102, 126, 234, 0.6);
        }

        .edit-form {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(25px);
            border-radius: 25px;
            padding: 40px;
            margin-bottom: 30px;
            box-shadow: 0 15px 50px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            font-weight: 700;
            color: white;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 10px;
        }

        .form-group label i {
            margin-right: 8px;
            opacity: 0.8;
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 15px 20px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 15px;
            font-size: 16px;
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
            color: white;
            font-weight: 500;
            font-family: inherit;
        }

        .form-group input::placeholder,
        .form-group textarea::placeholder {
            color: rgba(255, 255, 255, 0.6);
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: rgba(255, 255, 255, 0.6);
            background: rgba(255, 255, 255, 0.3);
            box-shadow: 0 0 0 4px rgba(255, 255, 255, 0.1);
            transform: scale(1.02);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 120px;
        }

        .form-group select option {
            background: rgba(0, 0, 0, 0.8);
            color: white;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .form-actions {
            display: flex;
            gap: 20px;
            justify-content: flex-end;
            margin-top: 40px;
            padding-top: 30px;
            border-top: 1px solid rgba(255, 255, 255, 0.2);
        }

        .alert {
            padding: 20px 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 15px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
        }

        .alert-danger {
            background: linear-gradient(135deg, rgba(255, 107, 107, 0.9), rgba(238, 90, 36, 0.9));
            color: white;
            border-left: 4px solid #ff6b6b;
        }

        .form-help {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.7);
            margin-top: 5px;
            font-style: italic;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px;
            }
            
            .sidebar {
                transform: translateX(-100%);
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .form-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <h2>LifeSaver Hub</h2>
            <p>Staff Dashboard</p>
        </div>
        <nav class="sidebar-nav">
            <a href="staff_dashboard.php">
                <i class="fas fa-home"></i>
                Dashboard
            </a>
            <a href="staff_manage_events.php" class="active">
                <i class="fas fa-calendar-alt"></i>
                Manage Events
            </a>
            <a href="staff_manage_donors.php">
                <i class="fas fa-users"></i>
                Manage Donors
            </a>
            <a href="staff_reports.php">
                <i class="fas fa-chart-bar"></i>
                Reports
            </a>
            <a href="staff_profile.php">
                <i class="fas fa-user"></i>
                Profile
            </a>
            <a href="staff_logout.php">
                <i class="fas fa-sign-out-alt"></i>
                Logout
            </a>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Error Messages -->
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-content">
                <div>
                    <h1>
                        <i class="fas fa-edit"></i>
                        Edit Event
                    </h1>
                    <p>Update event details and settings</p>
                </div>
                <a href="staff_view_event.php?id=<?php echo $eventId; ?>" class="btn btn-outline">
                    <i class="fas fa-arrow-left"></i>
                    Back to Event
                </a>
            </div>
        </div>

        <!-- Edit Form -->
        <div class="edit-form">
            <form method="POST" action="">
                <div class="form-group">
                    <label for="event_title">
                        <i class="fas fa-calendar-alt"></i>
                        Event Title
                    </label>
                    <input 
                        type="text" 
                        id="event_title" 
                        name="event_title" 
                        value="<?php echo htmlspecialchars($formData['EventTitle']); ?>" 
                        required 
                        maxlength="100"
                        placeholder="Enter event title">
                    <div class="form-help">Choose a clear and descriptive title for your event</div>
                </div>

                <div class="form-group">
                    <label for="event_description">
                        <i class="fas fa-align-left"></i>
                        Event Description
                    </label>
                    <textarea 
                        id="event_description" 
                        name="event_description" 
                        required 
                        maxlength="100"
                        placeholder="Describe the event, its purpose, and what participants can expect"><?php echo htmlspecialchars($formData['EventDescription']); ?></textarea>
                    <div class="form-help">Provide detailed information about the event to help donors understand what to expect</div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="event_date">
                            <i class="fas fa-calendar-day"></i>
                            Event Date
                        </label>
                        <input 
                            type="date" 
                            id="event_date" 
                            name="event_date" 
                            value="<?php echo htmlspecialchars($formData['EventDate']); ?>" 
                            required>
                        <div class="form-help">Select the date when the event will take place</div>
                    </div>

                    <div class="form-group">
                        <label for="event_day">
                            <i class="fas fa-clock"></i>
                            Event Day/Time
                        </label>
                        <input 
                            type="text" 
                            id="event_day" 
                            name="event_day" 
                            value="<?php echo htmlspecialchars($formData['EventDay']); ?>" 
                            maxlength="9"
                            placeholder="e.g., Monday, 9 AM">
                        <div class="form-help">Specify the day or time details for the event</div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="event_venue">
                        <i class="fas fa-map-marker-alt"></i>
                        Event Venue
                    </label>
                    <input 
                        type="text" 
                        id="event_venue" 
                        name="event_venue" 
                        value="<?php echo htmlspecialchars($formData['EventVenue']); ?>" 
                        required 
                        maxlength="100"
                        placeholder="Enter the venue or address where the event will be held">
                    <div class="form-help">Provide the complete address or venue name for the event</div>
                </div>

                <div class="form-group">
                    <label for="event_status">
                        <i class="fas fa-info-circle"></i>
                        Event Status
                    </label>
                    <select id="event_status" name="event_status" required>
                        <option value="Upcoming" <?php echo ($formData['EventStatus'] === 'Upcoming') ? 'selected' : ''; ?>>Upcoming</option>
                        <option value="Ongoing" <?php echo ($formData['EventStatus'] === 'Ongoing') ? 'selected' : ''; ?>>Ongoing</option>
                        <option value="Completed" <?php echo ($formData['EventStatus'] === 'Completed') ? 'selected' : ''; ?>>Completed</option>
                        <option value="Cancelled" <?php echo ($formData['EventStatus'] === 'Cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                    <div class="form-help">Update the current status of the event</div>
                </div>

                <div class="form-actions">
                    <a href="staff_view_event.php?id=<?php echo $eventId; ?>" class="btn btn-secondary">
                        <i class="fas fa-times"></i>
                        Cancel
                    </a>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save"></i>
                        Update Event
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Auto-hide alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                setTimeout(function() {
                    alert.style.opacity = '0';
                    setTimeout(function() {
                        alert.remove();
                    }, 300);
                }, 5000);
            });

            // Set minimum date to today for upcoming events
            const dateInput = document.getElementById('event_date');
            const statusSelect = document.getElementById('event_status');
            
            function updateDateConstraints() {
                if (statusSelect.value === 'Completed') {
                    dateInput.removeAttribute('min');
                } else {
                    const today = new Date().toISOString().split('T')[0];
                    dateInput.setAttribute('min', today);
                }
            }
            
            statusSelect.addEventListener('change', updateDateConstraints);
            updateDateConstraints(); // Set initial state
        });
    </script>
</body>
</html>