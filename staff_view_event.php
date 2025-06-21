<?php
session_start();
$pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$today = date('Y-m-d');
$updateStmt = $pdo->prepare("UPDATE event SET EventStatus = 'Completed' WHERE EventDate < ? AND EventStatus NOT IN ('Completed', 'Deleted')");
$updateStmt->execute([$today]);

$isStaff = isset($_SESSION['staff_id']);
$isStudent = isset($_SESSION['student_id']);

if (!$isStaff && !$isStudent) {
    header("Location: index.php");
    exit;
}

$where = [];
$params = [];

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

if ($isStudent) {
    $studentId = $_SESSION['student_id'];
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
    $sql = "SELECT e.*, 
            (SELECT COUNT(*) FROM registration r WHERE r.EventID = e.EventID AND r.RegistrationStatus != 'Cancelled') as TotalRegistered
            FROM event e";
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Management - LifeSaver Hub</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: #333;
        }

        .container {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar Styles */
        .sidebar {
            width: 280px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border-right: 1px solid rgba(255, 255, 255, 0.2);
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
        }

        .sidebar-header {
            padding: 30px 25px;
            text-align: center;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        .sidebar-header h2 {
            color: #667eea;
            font-weight: 700;
            font-size: 24px;
            margin-bottom: 5px;
        }

        .sidebar-header p {
            color: #666;
            font-size: 14px;
            font-weight: 400;
        }

        .sidebar-nav {
            padding: 20px 0;
        }

        .nav-item {
            margin: 0 15px 8px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: 15px 20px;
            color: #555;
            text-decoration: none;
            border-radius: 12px;
            transition: all 0.3s ease;
            font-weight: 500;
            position: relative;
        }

        .nav-link:hover {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            transform: translateX(5px);
        }

        .nav-link.active {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }

        .nav-link i {
            width: 20px;
            margin-right: 15px;
            text-align: center;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
            background: rgba(255, 255, 255, 0.05);
        }

        .page-header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }

        .page-title {
            font-size: 32px;
            font-weight: 700;
            color: #333;
            margin-bottom: 8px;
        }

        .page-subtitle {
            color: #666;
            font-size: 16px;
            font-weight: 400;
        }

        /* Filters Section */
        .filters-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-label {
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .filter-input {
            padding: 12px 16px;
            border: 2px solid #e1e5e9;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: white;
        }

        .filter-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .filter-btn {
            padding: 12px 24px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .filter-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
        }

        /* Events Table */
        .events-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }

        .table-header {
            padding: 25px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        .table-title {
            font-size: 20px;
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }

        .table-subtitle {
            color: #666;
            font-size: 14px;
        }

        .table-wrapper {
            overflow-x: visible;
            width: 100%;
        }

        .events-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .events-table th {
            background: #f8f9fa;
            padding: 15px 10px;
            text-align: left;
            font-weight: 600;
            color: #333;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid #e1e5e9;
        }

        .events-table td {
            padding: 15px 10px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            vertical-align: middle;
            word-wrap: break-word;
        }

        .events-table tr {
            transition: all 0.3s ease;
        }

        .events-table tr:hover {
            background: rgba(102, 126, 234, 0.02);
        }

        /* Column widths */
        .events-table th:nth-child(1),
        .events-table td:nth-child(1) { width: 8%; }
        .events-table th:nth-child(2),
        .events-table td:nth-child(2) { width: 25%; }
        .events-table th:nth-child(3),
        .events-table td:nth-child(3) { width: 20%; }
        .events-table th:nth-child(4),
        .events-table td:nth-child(4) { width: 12%; }
        .events-table th:nth-child(5),
        .events-table td:nth-child(5) { width: 12%; }
        .events-table th:nth-child(6),
        .events-table td:nth-child(6) { width: 23%; }

        /* Event ID */
        .event-id {
            font-weight: 600;
            color: #667eea;
            font-size: 16px;
        }

        /* Event Title */
        .event-title {
            font-weight: 600;
            color: #333;
            margin-bottom: 4px;
            font-size: 14px;
            line-height: 1.3;
        }

        /* Event Description */
        .event-description {
            color: #666;
            font-size: 13px;
            line-height: 1.4;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            max-height: 2.8em;
        }

        /* Date */
        .event-date {
            font-weight: 500;
            color: #333;
            font-size: 13px;
            margin-bottom: 4px;
        }

        /* Venue */
        .event-venue {
            color: #666;
            font-size: 12px;
        }

        .event-venue i,
        .event-date i {
            width: 12px;
            margin-right: 6px;
            font-size: 11px;
        }

        /* Registration Count */
        .registration-count {
            display: inline-flex;
            align-items: center;
            background: #e3f2fd;
            color: #1976d2;
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 14px;
        }

        .registration-count i {
            margin-right: 6px;
        }

        /* Status Badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 8px 16px;
            border-radius: 25px;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-badge.upcoming {
            background: linear-gradient(135deg, #00d2d3, #00b4d8);
            color: white;
        }

        .status-badge.completed {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
        }

        .status-badge.deleted {
            background: linear-gradient(135deg, #dc3545, #e74c3c);
            color: white;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .action-btn {
            padding: 8px 12px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            font-size: 11px;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: none;
            cursor: pointer;
            text-align: center;
        }

        .action-btn i {
            margin-right: 4px;
            font-size: 10px;
        }

        .btn-edit {
            background: linear-gradient(135deg, #ffc107, #ff9800);
            color: white;
        }

        .btn-edit:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 193, 7, 0.4);
        }

        .btn-delete {
            background: linear-gradient(135deg, #dc3545, #e74c3c);
            color: white;
        }

        .btn-delete:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(220, 53, 69, 0.4);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 30px;
            color: #666;
        }

        .empty-state i {
            font-size: 64px;
            color: #ddd;
            margin-bottom: 20px;
        }

        .empty-state h3 {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 10px;
            color: #333;
        }

        .empty-state p {
            font-size: 16px;
            color: #666;
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .main-content {
                padding: 20px;
            }
            
            .events-table th,
            .events-table td {
                padding: 12px 8px;
            }
            
            .events-table th {
                font-size: 11px;
            }
            
            .action-btn {
                font-size: 10px;
                padding: 6px 8px;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
            }

            .main-content {
                margin-left: 0;
                padding: 15px;
            }

            .filters-grid {
                grid-template-columns: 1fr;
            }

            .page-title {
                font-size: 24px;
            }

            .events-table th,
            .events-table td {
                padding: 10px 6px;
            }

            .events-table th {
                font-size: 10px;
            }

            .event-title {
                font-size: 13px;
            }

            .event-description {
                font-size: 11px;
                -webkit-line-clamp: 1;
                max-height: 1.4em;
            }

            .event-date,
            .event-venue {
                font-size: 11px;
            }

            .action-btn {
                font-size: 9px;
                padding: 5px 6px;
            }

            .action-btn i {
                font-size: 8px;
            }
        }

        /* Scroll animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .events-container,
        .page-header,
        .filters-section {
            animation: fadeInUp 0.6s ease-out;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-heartbeat"></i> LifeSaver Hub</h2>
                <p>Event Management System</p>
            </div>
            <nav class="sidebar-nav">
                <div class="nav-item">
                    <a href="staff_account.php" class="nav-link">
                        <i class="fas fa-user-tie"></i>
                        <span>My Account</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="create_event.php" class="nav-link">
                        <i class="fas fa-calendar-plus"></i>
                        <span>Create Event</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="staff_view_event.php" class="nav-link active">
                        <i class="fas fa-calendar-check"></i>
                        <span>View Events</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="view_donation.php" class="nav-link">
                        <i class="fas fa-hand-holding-heart"></i>
                        <span>View Donations</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="confirm_attendance.php" class="nav-link">
                        <i class="fas fa-user-check"></i>
                        <span>Confirm Attendance</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="update_application.php" class="nav-link">
                        <i class="fas fa-sync-alt"></i>
                        <span>Update Application</span>
                    </a>
                </div>
                <div class="nav-item">
                    <a href="logout.php" class="nav-link">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </a>
                </div>
            </nav>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="page-title">
                    <i class="fas fa-calendar-alt"></i>
                    Event Management
                </h1>
                <p class="page-subtitle">Manage and monitor all your events in one place</p>
            </div>

            <!-- Filters Section -->
            <div class="filters-section">
                <form method="GET" action="">
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label class="filter-label">Filter by Date</label>
                            <input type="date" name="filter_date" class="filter-input" 
                                   value="<?= htmlspecialchars($_GET['filter_date'] ?? '') ?>">
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Filter by Status</label>
                            <select name="filter_status" class="filter-input">
                                <option value="">All Status</option>
                                <option value="Upcoming" <?= ($_GET['filter_status'] ?? '') === 'Upcoming' ? 'selected' : '' ?>>
                                    Upcoming
                                </option>
                                <option value="Completed" <?= ($_GET['filter_status'] ?? '') === 'Completed' ? 'selected' : '' ?>>
                                    Completed
                                </option>
                                <option value="Deleted" <?= ($_GET['filter_status'] ?? '') === 'Deleted' ? 'selected' : '' ?>>
                                    Deleted
                                </option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <button type="submit" class="filter-btn">
                                <i class="fas fa-search"></i>
                                Apply Filters
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Events Table -->
            <div class="events-container">
                <div class="table-header">
                    <h3 class="table-title">All Events</h3>
                    <p class="table-subtitle">
                        <?= count($events) ?> event<?= count($events) !== 1 ? 's' : '' ?> found
                    </p>
                </div>

                <?php if (empty($events)): ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-times"></i>
                        <h3>No Events Found</h3>
                        <p>There are no events matching your current filters.</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrapper">
                        <table class="events-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Event Details</th>
                                    <th>Date & Venue</th>
                                    <th>Registrations</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($events as $event): ?>
                                    <tr>
                                        <td>
                                            <div class="event-id">#<?= $event['EventID'] ?></div>
                                        </td>
                                        <td>
                                            <div class="event-title"><?= htmlspecialchars($event['EventTitle']) ?></div>
                                            <div class="event-description" title="<?= htmlspecialchars($event['EventDescription']) ?>">
                                                <?= htmlspecialchars($event['EventDescription']) ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="event-date">
                                                <i class="fas fa-calendar"></i>
                                                <?= date('M d, Y', strtotime($event['EventDate'])) ?>
                                            </div>
                                            <div class="event-venue">
                                                <i class="fas fa-map-marker-alt"></i>
                                                <?= htmlspecialchars($event['EventVenue']) ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="registration-count">
                                                <i class="fas fa-users"></i>
                                                <?= $event['TotalRegistered'] ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="status-badge <?= strtolower($event['EventStatus']) ?>">
                                                <?= $event['EventStatus'] ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="update_event.php?id=<?= $event['EventID'] ?>" 
                                                   class="action-btn btn-edit">
                                                    <i class="fas fa-edit"></i>
                                                    Edit
                                                </a>
                                                <a href="delete_event.php?id=<?= $event['EventID'] ?>" 
                                                   class="action-btn btn-delete"
                                                   onclick="return confirm('Are you sure you want to delete this event?')">
                                                    <i class="fas fa-trash-alt"></i>
                                                    Delete
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Add smooth scrolling and interactive effects
        document.addEventListener('DOMContentLoaded', function() {
            // Add loading animation to action buttons
            const actionButtons = document.querySelectorAll('.action-btn');
            actionButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    if (!this.classList.contains('btn-delete') || confirm('Are you sure you want to delete this event?')) {
                        this.style.transform = 'scale(0.95)';
                        setTimeout(() => {
                            this.style.transform = '';
                        }, 150);
                    }
                });
            });

            // Add hover effects to table rows
            const tableRows = document.querySelectorAll('.events-table tbody tr');
            tableRows.forEach(row => {
                row.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateX(5px)';
                });
                
                row.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateX(0)';
                });
            });

            // Auto-refresh page every 5 minutes to update event statuses
            setTimeout(function() {
                window.location.reload();
            }, 300000); // 5 minutes
        });
    </script>
</body>
</html>