<?php
session_start();

if (!isset($_SESSION['student_id'])) {
    header("Location: student_login.php");
    exit;
}

$pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$stmt = $pdo->prepare("SELECT * FROM student WHERE StudentID = ?");
$stmt->execute([$_SESSION['student_id']]);
$student = $stmt->fetch();

// Get dashboard statistics with error handling
$stats = [
    'upcoming_events' => 0,
    'registered_events' => 0,
    'total_donations' => 0,
    'rewards_earned' => 0,
    'pending_notifications' => 0
];

try {
    // Count upcoming events
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM event WHERE EventStatus = 'Upcoming' AND EventStatus != 'Deleted'");
    $stmt->execute();
    $stats['upcoming_events'] = $stmt->fetchColumn();

    // Count registered events
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM registration WHERE StudentID = ? AND RegistrationStatus != 'Cancelled'");
    $stmt->execute([$_SESSION['student_id']]);
    $stats['registered_events'] = $stmt->fetchColumn();

    // Count total donations
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM donation WHERE StudentID = ?");
    $stmt->execute([$_SESSION['student_id']]);
    $stats['total_donations'] = $stmt->fetchColumn();

    // Count rewards earned
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM student_reward WHERE StudentID = ?");
    $stmt->execute([$_SESSION['student_id']]);
    $stats['rewards_earned'] = $stmt->fetchColumn();

    // Count pending notifications
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notification WHERE StudentID = ? AND Status = 'Unread'");
    $stmt->execute([$_SESSION['student_id']]);
    $stats['pending_notifications'] = $stmt->fetchColumn();
} catch (Exception $e) {
    // Keep default values if tables don't exist
}

// Get recent events
$recentEvents = [];
try {
    $stmt = $pdo->prepare("
        SELECT e.*, 
        (SELECT COUNT(*) FROM registration r WHERE r.EventID = e.EventID AND r.StudentID = ? AND r.RegistrationStatus != 'Cancelled') as IsRegistered
        FROM event e 
        WHERE e.EventStatus IN ('Upcoming', 'Ongoing') 
        ORDER BY e.EventDate ASC 
        LIMIT 3
    ");
    $stmt->execute([$_SESSION['student_id']]);
    $recentEvents = $stmt->fetchAll();
} catch (Exception $e) {
    // Keep empty array if query fails
}

// Get recent donations
$recentDonations = [];
try {
    $stmt = $pdo->prepare("
        SELECT d.*, e.EventTitle 
        FROM donation d 
        LEFT JOIN event e ON d.EventID = e.EventID 
        WHERE d.StudentID = ? 
        ORDER BY d.DonationDate DESC 
        LIMIT 3
    ");
    $stmt->execute([$_SESSION['student_id']]);
    $recentDonations = $stmt->fetchAll();
} catch (Exception $e) {
    // Keep empty array if query fails
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - LifeSaver Hub</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
                radial-gradient(circle at 20% 80%, rgba(120, 119, 198, 0.3) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(255, 107, 107, 0.3) 0%, transparent 50%),
                radial-gradient(circle at 40% 40%, rgba(255, 183, 77, 0.2) 0%, transparent 50%);
            z-index: -1;
            animation: float 20s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            33% { transform: translateY(-20px) rotate(1deg); }
            66% { transform: translateY(-10px) rotate(-1deg); }
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

        .sidebar::-webkit-scrollbar {
            width: 6px;
        }

        .sidebar::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 3px;
        }

        .sidebar::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.3);
            border-radius: 3px;
        }

        .sidebar::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.5);
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

        .sidebar a::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, 
                rgba(255, 255, 255, 0.1) 0%, 
                rgba(255, 255, 255, 0.3) 50%, 
                rgba(255, 255, 255, 0.1) 100%);
            transition: left 0.5s;
        }

        .sidebar a:hover::before {
            left: 100%;
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

        .welcome-header {
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

        .welcome-header::before {
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

        .welcome-content {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 20px;
        }

        .welcome-text h1 {
            font-size: 2.5rem;
            font-weight: 800;
            color: white;
            margin-bottom: 10px;
            text-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
        }

        .welcome-text p {
            color: rgba(255, 255, 255, 0.9);
            font-size: 18px;
            font-weight: 500;
        }

        .welcome-avatar {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.3), rgba(255, 255, 255, 0.1));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: white;
            border: 3px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 25px;
            margin-bottom: 35px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(25px);
            border-radius: 20px;
            padding: 25px;
            text-align: center;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, transparent, var(--accent-color), transparent);
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-10px) scale(1.05);
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.2);
            background: rgba(255, 255, 255, 0.25);
        }

        .stat-card:hover::before {
            height: 5px;
            background: linear-gradient(90deg, var(--accent-color), #feca57, var(--accent-color));
        }

        .stat-card .icon {
            font-size: 2.5rem;
            margin-bottom: 15px;
            color: white;
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.3));
            animation: float 3s ease-in-out infinite;
        }

        .stat-card .number {
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 8px;
            color: white;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        .stat-card .label {
            color: rgba(255, 255, 255, 0.9);
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }

        .stat-card.events { --accent-color: #667eea; }
        .stat-card.registered { --accent-color: #10ac84; }
        .stat-card.donations { --accent-color: #ff6b6b; }
        .stat-card.rewards { --accent-color: #feca57; }
        .stat-card.notifications { --accent-color: #f093fb; }

        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }

        .dashboard-section {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(25px);
            border-radius: 20px;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
            overflow: hidden;
        }

        .section-header {
            padding: 25px 30px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.05));
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .section-header h3 {
            font-size: 1.3rem;
            font-weight: 700;
            color: white;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        .section-header i {
            background: linear-gradient(135deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .section-content {
            padding: 30px;
        }

        .event-item,
        .donation-item {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 15px;
            border-left: 4px solid #667eea;
            transition: all 0.3s ease;
        }

        .event-item:hover,
        .donation-item:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateX(5px);
        }

        .event-item.registered {
            border-left-color: #10ac84;
        }

        .event-item h4,
        .donation-item h4 {
            color: white;
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 1.1rem;
        }

        .event-item p,
        .donation-item p {
            color: rgba(255, 255, 255, 0.8);
            font-size: 14px;
            margin-bottom: 5px;
        }

        .event-status,
        .donation-status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 15px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-upcoming { background: rgba(102, 126, 234, 0.3); color: #667eea; }
        .status-registered { background: rgba(16, 172, 132, 0.3); color: #10ac84; }
        .status-completed { background: rgba(116, 125, 140, 0.3); color: #747d8c; }

        .quick-actions {
            display: grid;
            grid-template-columns: 1fr;
            gap: 15px;
        }

        .action-btn {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 15px 20px;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.2), rgba(255, 255, 255, 0.1));
            color: white;
            text-decoration: none;
            border-radius: 15px;
            font-weight: 600;
            transition: all 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .action-btn:hover {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.3), rgba(255, 255, 255, 0.2));
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .action-btn i {
            font-size: 18px;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: rgba(255, 255, 255, 0.7);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.3;
        }

        .mobile-menu-btn {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1001;
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            border: none;
            padding: 12px;
            border-radius: 15px;
            color: white;
            font-size: 18px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .sidebar {
                width: 250px;
            }
            .main-content {
                margin-left: 250px;
            }
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }
            .sidebar.mobile-open {
                transform: translateX(0);
            }
            .main-content {
                margin-left: 0;
                padding: 20px;
            }
            .mobile-menu-btn {
                display: block;
            }
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .welcome-content {
                flex-direction: column;
                text-align: center;
            }
            .welcome-text h1 {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
    <button class="mobile-menu-btn" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>

    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h2>LifeSaver Hub</h2>
            <p>Student Portal</p>
        </div>
        
        <nav class="sidebar-nav">
            <a href="student_account.php"><i class="fas fa-user-graduate"></i> My Account</a>
            <a href="dashboard.php" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="student_view_event.php"><i class="fas fa-calendar-heart"></i> View Events</a>
            <a href="notifications.php"><i class="fas fa-bell"></i> Notifications</a>
            <a href="view_donation.php"><i class="fas fa-tint"></i> View Donation</a>
            <a href="donation_history.php"><i class="fas fa-history"></i> Donation History</a>
            <a href="view_rewards.php"><i class="fas fa-trophy"></i> My Rewards</a>
            <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </nav>
    </div>

    <div class="main-content">
        <div class="welcome-header">
            <div class="welcome-content">
                <div class="welcome-text">
                    <h1>Welcome back, <?= htmlspecialchars($student['StudentName']) ?>!</h1>
                    <p>Ready to make a difference? Check your dashboard for updates and upcoming events.</p>
                </div>
                <div class="welcome-avatar">
                    <i class="fas fa-user-graduate"></i>
                </div>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card events">
                <div class="icon"><i class="fas fa-calendar-alt"></i></div>
                <div class="number"><?= $stats['upcoming_events'] ?></div>
                <div class="label">Upcoming Events</div>
            </div>
            <div class="stat-card registered">
                <div class="icon"><i class="fas fa-calendar-check"></i></div>
                <div class="number"><?= $stats['registered_events'] ?></div>
                <div class="label">Registered Events</div>
            </div>
            <div class="stat-card donations">
                <div class="icon"><i class="fas fa-heart"></i></div>
                <div class="number"><?= $stats['total_donations'] ?></div>
                <div class="label">Total Donations</div>
            </div>
            <div class="stat-card rewards">
                <div class="icon"><i class="fas fa-trophy"></i></div>
                <div class="number"><?= $stats['rewards_earned'] ?></div>
                <div class="label">Rewards Earned</div>
            </div>
            <?php if ($stats['pending_notifications'] > 0): ?>
            <div class="stat-card notifications">
                <div class="icon"><i class="fas fa-bell"></i></div>
                <div class="number"><?= $stats['pending_notifications'] ?></div>
                <div class="label">New Notifications</div>
            </div>
            <?php endif; ?>
        </div>

        <div class="dashboard-grid">
            <div class="dashboard-section">
                <div class="section-header">
                    <h3><i class="fas fa-calendar-alt"></i> Recent Events</h3>
                    <a href="view_event.php" style="color: rgba(255,255,255,0.8); text-decoration: none; font-size: 14px;">View All →</a>
                </div>
                <div class="section-content">
                    <?php if (!empty($recentEvents)): ?>
                        <?php foreach ($recentEvents as $event): ?>
                            <div class="event-item <?= $event['IsRegistered'] ? 'registered' : '' ?>">
                                <h4><?= htmlspecialchars($event['EventTitle']) ?></h4>
                                <p><i class="fas fa-calendar"></i> <?= date('F j, Y', strtotime($event['EventDate'])) ?> (<?= $event['EventDay'] ?>)</p>
                                <p><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($event['EventVenue']) ?></p>
                                <span class="event-status <?= $event['IsRegistered'] ? 'status-registered' : 'status-upcoming' ?>">
                                    <?= $event['IsRegistered'] ? 'Registered' : $event['EventStatus'] ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-calendar-times"></i>
                            <p>No upcoming events at the moment.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="dashboard-section">
                <div class="section-header">
                    <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
                </div>
                <div class="section-content">
                    <div class="quick-actions">
                        <a href="view_event.php" class="action-btn">
                            <i class="fas fa-calendar-plus"></i>
                            <span>Register for Events</span>
                        </a>
                        <a href="view_donation.php" class="action-btn">
                            <i class="fas fa-tint"></i>
                            <span>View My Donations</span>
                        </a>
                        <a href="view_rewards.php" class="action-btn">
                            <i class="fas fa-gift"></i>
                            <span>Check My Rewards</span>
                        </a>
                        <a href="notifications.php" class="action-btn">
                            <i class="fas fa-bell"></i>
                            <span>View Notifications</span>
                        </a>
                        <a href="student_account.php" class="action-btn">
                            <i class="fas fa-user-cog"></i>
                            <span>Update Profile</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($recentDonations)): ?>
        <div class="dashboard-section">
            <div class="section-header">
                <h3><i class="fas fa-heart"></i> Recent Donations</h3>
                <a href="donation_history.php" style="color: rgba(255,255,255,0.8); text-decoration: none; font-size: 14px;">View History →</a>
            </div>
            <div class="section-content">
                <?php foreach ($recentDonations as $donation): ?>
                    <div class="donation-item">
                        <h4><?= $donation['EventTitle'] ? htmlspecialchars($donation['EventTitle']) : 'Blood Donation' ?></h4>
                        <p><i class="fas fa-calendar"></i> <?= date('F j, Y', strtotime($donation['DonationDate'])) ?></p>
                        <p><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($donation['DonationVenue']) ?></p>
                        <span class="donation-status status-completed">Completed</span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('mobile-open');
        }

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const menuBtn = document.querySelector('.mobile-menu-btn');
            
            if (window.innerWidth <= 768 && 
                !sidebar.contains(event.target) && 
                !menuBtn.contains(event.target)) {
                sidebar.classList.remove('mobile-open');
            }
        });

        // Auto-hide mobile menu on resize
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth > 768) {
                sidebar.classList.remove('mobile-open');
            }
        });

        // Animate numbers on page load
        document.addEventListener('DOMContentLoaded', function() {
            const numbers = document.querySelectorAll('.stat-card .number');
            numbers.forEach(number => {
                const finalValue = parseInt(number.textContent);
                let currentValue = 0;
                const increment = finalValue / 30;
                
                const timer = setInterval(() => {
                    currentValue += increment;
                    if (currentValue >= finalValue) {
                        number.textContent = finalValue;
                        clearInterval(timer);
                    } else {
                        number.textContent = Math.floor(currentValue);
                    }
                }, 50);
            });
        });

        // Add hover effects to cards
        document.querySelectorAll('.stat-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-10px) scale(1.05)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0) scale(1)';
            });
        });

        // Add click effects to action buttons
        document.querySelectorAll('.action-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                this.style.transform = 'scale(0.95)';
                setTimeout(() => {
                    this.style.transform = 'scale(1)';
                }, 150);
            });
        });

        // Add loading animation for quick actions
        document.querySelectorAll('.action-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                if (this.href) {
                    const icon = this.querySelector('i');
                    const originalIcon = icon.className;
                    icon.className = 'fas fa-spinner fa-spin';
                    
                    setTimeout(() => {
                        icon.className = originalIcon;
                    }, 1000);
                }
            });
        });

        // Smooth scroll for internal links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Add welcome animation
        window.addEventListener('load', function() {
            const welcomeHeader = document.querySelector('.welcome-header');
            const statsCards = document.querySelectorAll('.stat-card');
            const dashboardSections = document.querySelectorAll('.dashboard-section');
            
            // Animate welcome header
            welcomeHeader.style.opacity = '0';
            welcomeHeader.style.transform = 'translateY(-30px)';
            
            setTimeout(() => {
                welcomeHeader.style.transition = 'all 0.8s ease';
                welcomeHeader.style.opacity = '1';
                welcomeHeader.style.transform = 'translateY(0)';
            }, 100);
            
            // Animate stats cards with stagger
            statsCards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(30px)';
                
                setTimeout(() => {
                    card.style.transition = 'all 0.6s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, 300 + (index * 100));
            });
            
            // Animate dashboard sections
            dashboardSections.forEach((section, index) => {
                section.style.opacity = '0';
                section.style.transform = 'translateY(30px)';
                
                setTimeout(() => {
                    section.style.transition = 'all 0.6s ease';
                    section.style.opacity = '1';
                    section.style.transform = 'translateY(0)';
                }, 800 + (index * 200));
            });
        });

        // Add real-time clock
        function updateClock() {
            const now = new Date();
            const options = { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            };
            const dateTimeString = now.toLocaleDateString('en-US', options);
            
            // You can add a clock element to display this
            // For now, it's just prepared for future use
        }

        // Update clock every minute
        setInterval(updateClock, 60000);
        updateClock(); // Initial call
    </script>
</body>
</html>