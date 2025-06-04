<?php
session_start();
$pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

// Auto-update event status to 'Completed' if date is past and not already 'Deleted' or 'Completed'
$today = date('Y-m-d');
$updateStmt = $pdo->prepare("UPDATE event SET EventStatus = 'Completed' WHERE EventDate < ? AND EventStatus NOT IN ('Completed', 'Deleted')");
$updateStmt->execute([$today]);

$isStaff = isset($_SESSION['staff_id']);
$isStudent = isset($_SESSION['student_id']);

if (!$isStaff && !$isStudent) {
    header("Location: index.php");
    exit;
}

$studentId = $isStudent ? $_SESSION['student_id'] : null;

// Handle student registration/unregistration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isStudent) {
    $eventId = $_POST['event_id'];
    $action = $_POST['action'];
    
    if ($action === 'register') {
        // Check if already registered
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM registration WHERE StudentID = ? AND EventID = ? AND RegistrationStatus != 'Cancelled'");
        $checkStmt->execute([$studentId, $eventId]);
        
        if ($checkStmt->fetchColumn() == 0) {
            // Register for event
            $registerStmt = $pdo->prepare("INSERT INTO registration (StudentID, EventID, RegistrationDate, RegistrationStatus, AttendanceStatus) VALUES (?, ?, NOW(), 'Confirmed', 'Pending')");
            $registerStmt->execute([$studentId, $eventId]);
            $success = "Successfully registered for the event!";
        } else {
            $error = "You are already registered for this event.";
        }
    } elseif ($action === 'unregister') {
        // Unregister from event
        $unregisterStmt = $pdo->prepare("UPDATE registration SET RegistrationStatus = 'Cancelled' WHERE StudentID = ? AND EventID = ?");
        $unregisterStmt->execute([$studentId, $eventId]);
        $success = "Successfully unregistered from the event.";
    }
}

// Filtering
$where = [];
$params = [];

// For students, don't show deleted events
if ($isStudent) {
    $where[] = "EventStatus != 'Deleted'";
}

if (!empty($_GET['filter_date'])) {
    $where[] = "EventDate = ?";
    $params[] = $_GET['filter_date'];
}
if (!empty($_GET['filter_status'])) {
    $where[] = "EventStatus = ?";
    $params[] = $_GET['filter_status'];
}

// Different queries for staff vs students
if ($isStudent) {
    // For students, include registration info
    $sql = "SELECT e.*, 
            (SELECT COUNT(*) FROM registration r WHERE r.EventID = e.EventID AND r.StudentID = ? AND r.RegistrationStatus != 'Cancelled') as IsRegistered,
            (SELECT COUNT(*) FROM registration r WHERE r.EventID = e.EventID AND r.RegistrationStatus != 'Cancelled') as TotalRegistered
            FROM event e";
    
    if ($where) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }
    $sql .= " ORDER BY EventDate DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$studentId], $params));
} else {
    // For staff, simple event query
    $sql = "SELECT * FROM event";
    if ($where) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }
    $sql .= " ORDER BY EventDate DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

$events = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>View Events - LifeSaver Hub</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: 'Segoe UI', sans-serif;
            background: <?= $isStudent ? 'linear-gradient(135deg, #f4f6fa 0%, #e9ecef 100%)' : '#f4f6fa' ?>;
            margin: 0;
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 250px;
            height: 100vh;
            background: <?= $isStudent ? 'linear-gradient(180deg, #1d3557 0%, #457b9d 100%)' : '#1d3557' ?>;
            padding-top: 30px;
            color: white;
            <?= $isStudent ? 'box-shadow: 3px 0 10px rgba(0,0,0,0.1);' : '' ?>
        }

        .sidebar h2 {
            text-align: center;
            margin-bottom: 30px;
            <?= $isStudent ? 'font-weight: 300;' : '' ?>
        }

        .sidebar a {
            display: block;
            color: white;
            padding: 15px 20px;
            text-decoration: none;
            font-size: 16px;
            <?= $isStudent ? 'transition: all 0.3s ease; border-left: 3px solid transparent;' : '' ?>
        }

        .sidebar a:hover {
            background-color: <?= $isStudent ? 'rgba(255,255,255,0.1)' : '#457b9d' ?>;
            <?= $isStudent ? 'border-left-color: #f1faee; padding-left: 25px;' : '' ?>
        }

        .sidebar a.active {
            <?= $isStudent ? 'background-color: rgba(255,255,255,0.2); border-left-color: #f1faee;' : 'background-color: #457b9d;' ?>
        }

        .main {
            margin-left: 270px;
            padding: 30px;
            width: calc(100% - 270px);
            box-sizing: border-box;
            min-height: 100vh;
        }

        h1 {
            font-size: <?= $isStudent ? '2.5em' : '24px' ?>;
            margin-bottom: <?= $isStudent ? '30px' : '20px' ?>;
            margin-top: 0;
            color: #1d3557;
            <?= $isStudent ? 'text-align: center; font-weight: 300;' : '' ?>
        }

        .filters {
            margin-bottom: <?= $isStudent ? '30px' : '20px' ?>;
            <?= $isStudent ? 'background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); display: flex; gap: 15px; align-items: center; flex-wrap: wrap;' : '' ?>
        }

        .filters input,
        .filters select,
        .filters button {
            padding: <?= $isStudent ? '12px 16px' : '10px' ?>;
            <?= $isStudent ? '' : 'margin-right: 10px;' ?>
            border-radius: 8px;
            border: 1px solid <?= $isStudent ? '#ddd' : '#ccc' ?>;
            font-size: 14px;
            <?= $isStudent ? 'transition: all 0.3s ease;' : '' ?>
        }

        .filters button {
            <?= $isStudent ? 'background: linear-gradient(135deg, #1d3557 0%, #457b9d 100%); color: white; border: none; cursor: pointer; font-weight: 600;' : '' ?>
        }

        <?php if ($isStudent): ?>
        .filters button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(29, 53, 87, 0.3);
        }

        .events-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 25px;
        }

        .event-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            border-left: 5px solid #1d3557;
            position: relative;
            overflow: hidden;
        }

        .event-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0,0,0,0.15);
        }

        .event-card.registered {
            border-left-color: #28a745;
            background: linear-gradient(135deg, #f8fff9 0%, #ffffff 100%);
        }

        .event-card.past {
            opacity: 0.7;
            border-left-color: #6c757d;
        }

        .event-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .event-title {
            font-size: 1.3em;
            font-weight: 600;
            color: #1d3557;
            margin: 0 0 10px 0;
        }

        .event-status {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .status-upcoming { background: #e3f2fd; color: #1976d2; }
        .status-ongoing { background: #fff3e0; color: #f57c00; }
        .status-completed { background: #e8f5e8; color: #388e3c; }

        .event-details {
            margin: 15px 0;
            color: #495057;
            line-height: 1.6;
        }

        .event-details .detail-item {
            display: flex;
            align-items: center;
            margin-bottom: 8px;
        }

        .event-details .detail-item i {
            width: 20px;
            margin-right: 10px;
            color: #1d3557;
        }

        .event-description {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
            font-style: italic;
            color: #6c757d;
        }

        .event-actions {
            margin-top: 20px;
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .btn {
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            font-size: 14px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
            color: white;
        }

        .btn-success {
            background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%);
            color: white;
        }

        .btn-danger {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
        }

        .btn-secondary {
            background: linear-gradient(135deg, #6c757d 0%, #545b62 100%);
            color: white;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }

        .registration-info {
            margin-top: 15px;
            padding: 10px;
            background: #e9ecef;
            border-radius: 6px;
            font-size: 13px;
            color: #495057;
        }

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }
        <?php else: ?>
        table {
            width: 100%;
            border-collapse: collapse;
            background-color: white;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        }

        th, td {
            padding: 12px;
            border-bottom: 1px solid #eee;
            text-align: left;
        }

        th {
            background-color: #1d3557;
            color: white;
        }

        .actions {
            display: flex;
            gap: 8px;
        }

        .actions a {
            padding: 8px 16px;
            font-size: 14px;
            font-weight: 600;
            border-radius: 8px;
            text-decoration: none;
            color: white;
            display: inline-block;
            transition: background-color 0.3s ease;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
        }

        .actions .edit-btn {
            background-color: #28a745;
        }

        .actions .edit-btn:hover {
            background-color: #218838;
        }

        .actions .delete-btn {
            background-color: #dc3545;
        }

        .actions .delete-btn:hover {
            background-color: #c82333;
        }

        .badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 8px;
            color: white;
            font-size: 13px;
            font-weight: bold;
        }

        .badge.upcoming { background-color: #007bff; }
        .badge.today    { background-color: #28a745; }
        .badge.past     { background-color: #6c757d; }
        <?php endif; ?>

        @media (max-width: 768px) {
            .main {
                margin-left: 0;
                padding: 15px;
                width: 100%;
            }
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
            }
            <?php if ($isStudent): ?>
            .events-grid {
                grid-template-columns: 1fr;
            }
            .filters {
                flex-direction: column;
                align-items: stretch;
            }
            <?php endif; ?>
        }
    </style>
</head>
<body>

<div class="sidebar">
    <h2>LifeSaver Hub</h2>
    <?php if ($isStaff): ?>
        <a href="staff_account.php"><i class="fas fa-user"></i> My Account</a>
        <a href="create_event.php"><i class="fas fa-calendar-plus"></i> Create Event</a>
        <a href="view_event.php" class="active"><i class="fas fa-calendar"></i> View Events</a>
        <a href="view_donation.php"><i class="fas fa-hand-holding-heart"></i> View Donations</a>
        <a href="confirm_attendance.php"><i class="fas fa-check"></i> Confirm Attendance</a>
        <a href="update_application.php"><i class="fas fa-sync"></i> Update Application</a>
        <a href="create_reward.php"><i class="fas fa-gift"></i> Create Rewards</a>
        <a href="generate_report.php"><i class="fas fa-chart-line"></i> Generate Report</a>
        <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
    <?php elseif ($isStudent): ?>
        <a href="student_account.php"><i class="fas fa-user"></i> My Account</a>
        <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
        <a href="view_event.php" class="active"><i class="fas fa-calendar"></i> View Events</a>
        <a href="health_questionnaire.php"><i class="fas fa-notes-medical"></i> Health Questions</a>
        <a href="notifications.php"><i class="fas fa-bell"></i> Notifications</a>
        <a href="view_donation.php"><i class="fas fa-eye"></i> View Donations</a>
        <a href="update_donation.php"><i class="fas fa-sync-alt"></i> Update Donation</a>
        <a href="delete_donation.php"><i class="fas fa-trash"></i> Delete Donation</a>
        <a href="donation_history.php"><i class="fas fa-history"></i> Donation History</a>
        <a href="view_rewards.php"><i class="fas fa-gift"></i> My Rewards</a>
        <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
    <?php endif; ?>
</div>

<div class="main">
    <h1>
        <i class="fas fa-<?= $isStudent ? 'calendar-alt' : 'clipboard-list' ?>"></i> 
        <?= $isStudent ? 'Available Blood Donation Events' : 'View & Manage Events' ?>
    </h1>

    <?php if ($isStudent && isset($success)): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($isStudent && isset($error)): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form class="filters" method="GET">
        <?php if ($isStudent): ?>
            <div>
                <label for="filter_date">Filter by Date:</label>
                <input type="date" name="filter_date" id="filter_date" value="<?= htmlspecialchars($_GET['filter_date'] ?? '') ?>">
            </div>
            <div>
                <label for="filter_status">Filter by Status:</label>
                <select name="filter_status" id="filter_status">
                    <option value="">All Events</option>
                    <option value="Upcoming" <?= ($_GET['filter_status'] ?? '') === 'Upcoming' ? 'selected' : '' ?>>Upcoming</option>
                    <option value="Ongoing" <?= ($_GET['filter_status'] ?? '') === 'Ongoing' ? 'selected' : '' ?>>Ongoing</option>
                    <option value="Completed" <?= ($_GET['filter_status'] ?? '') === 'Completed' ? 'selected' : '' ?>>Completed</option>
                </select>
            </div>
            <button type="submit"><i class="fas fa-filter"></i> Apply Filters</button>
            <a href="view_event.php" style="color: #6c757d; text-decoration: none; margin-left: 10px;">Clear Filters</a>
        <?php else: ?>
            <input type="date" name="filter_date" value="<?= htmlspecialchars($_GET['filter_date'] ?? '') ?>">
            <select name="filter_status">
                <option value="">-- Filter by Status --</option>
                <option value="Upcoming" <?= ($_GET['filter_status'] ?? '') === 'Upcoming' ? 'selected' : '' ?>>Upcoming</option>
                <option value="Ongoing" <?= ($_GET['filter_status'] ?? '') === 'Ongoing' ? 'selected' : '' ?>>Ongoing</option>
                <option value="Completed" <?= ($_GET['filter_status'] ?? '') === 'Completed' ? 'selected' : '' ?>>Completed</option>
                <option value="Deleted" <?= ($_GET['filter_status'] ?? '') === 'Deleted' ? 'selected' : '' ?>>Deleted</option>
            </select>
            <button type="submit">Filter</button>
        <?php endif; ?>
    </form>

    <?php if ($isStudent): ?>
        <!-- Student Card View -->
        <div class="events-grid">
            <?php if ($events): ?>
                <?php foreach ($events as $event): 
                    $isRegistered = $event['IsRegistered'] > 0;
                    $isPast = strtotime($event['EventDate']) < strtotime($today);
                    $canRegister = !$isPast && $event['EventStatus'] === 'Upcoming';
                ?>
                    <div class="event-card <?= $isRegistered ? 'registered' : '' ?> <?= $isPast ? 'past' : '' ?>">
                        <div class="event-header">
                            <h3 class="event-title"><?= htmlspecialchars($event['EventTitle']) ?></h3>
                            <span class="event-status status-<?= strtolower($event['EventStatus']) ?>">
                                <?= htmlspecialchars($event['EventStatus']) ?>
                            </span>
                        </div>

                        <div class="event-details">
                            <div class="detail-item">
                                <i class="fas fa-calendar"></i>
                                <span><?= date('F j, Y', strtotime($event['EventDate'])) ?> (<?= $event['EventDay'] ?>)</span>
                            </div>
                            <div class="detail-item">
                                <i class="fas fa-map-marker-alt"></i>
                                <span><?= htmlspecialchars($event['EventVenue']) ?></span>
                            </div>
                            <div class="detail-item">
                                <i class="fas fa-users"></i>
                                <span><?= $event['TotalRegistered'] ?> students registered</span>
                            </div>
                        </div>

                        <div class="event-description">
                            <?= htmlspecialchars($event['EventDescription']) ?>
                        </div>

                        <div class="event-actions">
                            <?php if ($isRegistered): ?>
                                <span class="btn btn-success">
                                    <i class="fas fa-check"></i> Registered
                                </span>
                                <?php if ($canRegister): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="event_id" value="<?= $event['EventID'] ?>">
                                        <input type="hidden" name="action" value="unregister">
                                        <button type="submit" class="btn btn-danger" onclick="return confirm('Are you sure you want to unregister?')">
                                            <i class="fas fa-times"></i> Unregister
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php elseif ($canRegister): ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="event_id" value="<?= $event['EventID'] ?>">
                                    <input type="hidden" name="action" value="register">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-hand-holding-heart"></i> Register Now
                                    </button>
                                </form>
                            <?php elseif ($isPast): ?>
                                <span class="btn btn-secondary">
                                    <i class="fas fa-clock"></i> Event Ended
                                </span>
                            <?php else: ?>
                                <span class="btn btn-secondary">
                                    <i class="fas fa-lock"></i> Registration Closed
                                </span>
                            <?php endif; ?>
                        </div>

                        <?php if ($isRegistered): ?>
                            <div class="registration-info">
                                <i class="fas fa-info-circle"></i> You are registered for this event. 
                                Please arrive on time and bring a valid ID.
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="grid-column: 1 / -1; text-align: center; padding: 50px; color: #6c757d;">
                    <i class="fas fa-calendar-times" style="font-size: 4em; margin-bottom: 20px; opacity: 0.3;"></i>
                    <h3>No events found</h3>
                    <p>There are currently no events matching your criteria.</p>
                </div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <!-- Staff Table View -->
        <table>
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Description</th>
                    <th>Date</th>
                    <th>Day</th>
                    <th>Venue</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($events): ?>
                    <?php foreach ($events as $e): 
                        $today = date('Y-m-d');
                        $eventDate = $e['EventDate'];
                        $statusBadge = '';

                        if ($e['EventStatus'] === 'Deleted') {
                            $statusBadge = '<span class="badge past">Deleted</span>';
                        } elseif ($eventDate < $today) {
                            $statusBadge = '<span class="badge past">Past</span>';
                        } elseif ($eventDate === $today) {
                            $statusBadge = '<span class="badge today"><i class="fas fa-bullseye"></i> Today</span>';
                        } else {
                            $statusBadge = '<span class="badge upcoming">Upcoming</span>';
                        }
                    ?>
                        <tr>
                            <td><?= htmlspecialchars($e['EventTitle']) ?> <?= $statusBadge ?></td>
                            <td><?= htmlspecialchars($e['EventDescription']) ?></td>
                            <td><?= htmlspecialchars($e['EventDate']) ?></td>
                            <td><?= htmlspecialchars($e['EventDay']) ?></td>
                            <td><?= htmlspecialchars($e['EventVenue']) ?></td>
                            <td><?= htmlspecialchars($e['EventStatus']) ?></td>
                            <td class="actions">
                                <a href="update_event.php?id=<?= $e['EventID'] ?>" class="edit-btn"><i class="fas fa-edit"></i> Edit</a>
                                <a href="delete_event.php?id=<?= $e['EventID'] ?>" class="delete-btn" onclick="return confirm('Are you sure to delete this event?')"><i class="fas fa-trash-alt"></i> Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7">No events found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

</body>
</html>