<?php
session_start();

$pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$isStaff = isset($_SESSION['staff_id']);
$isStudent = isset($_SESSION['student_id']);

if (!$isStaff && !$isStudent) {
    header("Location: index.php");
    exit;
}

// Filtering
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

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
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
            background-color: #f4f6fa;
            margin: 0;
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 250px;
            height: 100vh;
            background-color: #1d3557;
            padding-top: 30px;
            color: white;
        }

        .sidebar h2 {
            text-align: center;
            margin-bottom: 30px;
        }

        .sidebar a {
            display: block;
            color: white;
            padding: 15px 20px;
            text-decoration: none;
            font-size: 16px;
        }

        .sidebar a:hover {
            background-color: #457b9d;
        }

        .main {
            margin-left: 250px;
            padding: 30px;
        }

        h1 {
            font-size: 24px;
            margin-bottom: 20px;
            color: #1d3557;
        }

        .filters {
            margin-bottom: 20px;
        }

        .filters input,
        .filters select,
        .filters button {
            padding: 10px;
            margin-right: 10px;
            border-radius: 8px;
            border: 1px solid #ccc;
            font-size: 14px;
        }

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


        @media (max-width: 768px) {
            .main {
                margin-left: 0;
                padding: 15px;
            }
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
            }
        }
    </style>
</head>
<body>

<div class="sidebar">
    <h2>LifeSaver Hub</h2>
    <?php if ($isStaff): ?>
        <a href="staff_account.php"><i class="fas fa-user"></i> My Account</a>
        <a href="create_event.php"><i class="fas fa-calendar-plus"></i> Create Event</a>
        <a href="view_event.php"><i class="fas fa-calendar"></i> View Events</a>
        <a href="view_donation.php"><i class="fas fa-hand-holding-heart"></i> View Donations</a>
        <a href="confirm_attendance.php"><i class="fas fa-check"></i> Confirm Attendance</a>
        <a href="update_application.php"><i class="fas fa-sync"></i> Update Application</a>
        <a href="create_reward.php"><i class="fas fa-gift"></i> Create Rewards</a>
        <a href="generate_report.php"><i class="fas fa-chart-line"></i> Generate Report</a>
        <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
    <?php elseif ($isStudent): ?>
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
    <?php endif; ?>
</div>

<div class="main">
    <h1><i class="fas fa-clipboard-list"></i> View & Manage Events</h1>

    <form class="filters" method="GET">
        <input type="date" name="filter_date" value="<?= htmlspecialchars($_GET['filter_date'] ?? '') ?>">
        <select name="filter_status">
            <option value="">-- Filter by Status --</option>
            <option value="Upcoming" <?= ($_GET['filter_status'] ?? '') === 'Upcoming' ? 'selected' : '' ?>>Upcoming</option>
            <option value="Ongoing" <?= ($_GET['filter_status'] ?? '') === 'Ongoing' ? 'selected' : '' ?>>Ongoing</option>
            <option value="Completed" <?= ($_GET['filter_status'] ?? '') === 'Completed' ? 'selected' : '' ?>>Completed</option>
            <option value="Deleted" <?= ($_GET['filter_status'] ?? '') === 'Deleted' ? 'selected' : '' ?>>Deleted</option>
        </select>
        <button type="submit">Filter</button>
    </form>

    <table>
        <thead>
            <tr>
                <th>Title</th>
                <th>Description</th>
                <th>Date</th>
                <th>Day</th>
                <th>Venue</th>
                <th>Status</th>
                <?php if ($isStaff): ?><th>Action</th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php if ($events): ?>
                <?php foreach ($events as $e): ?>
                    <tr>
                        <td><?= htmlspecialchars($e['EventTitle']) ?></td>
                        <td><?= htmlspecialchars($e['EventDescription']) ?></td>
                        <td><?= htmlspecialchars($e['EventDate']) ?></td>
                        <td><?= htmlspecialchars($e['EventDay']) ?></td>
                        <td><?= htmlspecialchars($e['EventVenue']) ?></td>
                        <td><?= htmlspecialchars($e['EventStatus']) ?></td>
                        <?php if ($isStaff): ?>
                            <td class="actions">
                             <a href="update_event.php?id=<?= $e['EventID'] ?>" class="edit-btn"><i class="fas fa-edit"></i> Edit</a>
                             <a href="delete_event.php?id=<?= $e['EventID'] ?>" class="delete-btn" onclick="return confirm('Are you sure to delete this event?')"><i class="fas fa-trash-alt"></i> Delete</a>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="<?= $isStaff ? '7' : '6' ?>">No events found.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

</body>
</html>
