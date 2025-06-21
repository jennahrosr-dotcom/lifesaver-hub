<?php
session_start();

if (!isset($_SESSION['staff_id'])) {
    header("Location: staff_login.php");
    exit;
}

$pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

// Fetch staff data
$stmt = $pdo->prepare("SELECT * FROM staff WHERE StaffID = ?");
$stmt->execute([$_SESSION['staff_id']]);
$staff = $stmt->fetch();

// Get current time for greeting
$hour = date('H');
if ($hour < 12) {
    $greeting = "Good Morning";
} elseif ($hour < 17) {
    $greeting = "Good Afternoon";
} else {
    $greeting = "Good Evening";
}

// Fetch real statistics from database
try {
    // Count active events (assuming you have an events table)
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM events WHERE EventDate >= CURDATE()");
    $activeEvents = $stmt->fetch()['count'] ?? 0;
    
    // Count total donations (assuming you have a donations table)
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM donations");
    $totalDonations = $stmt->fetch()['count'] ?? 0;
    
    // Count confirmed attendees (assuming you have an attendance or registration table)
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM registrations WHERE Status = 'Confirmed'");
    $confirmedAttendees = $stmt->fetch()['count'] ?? 0;
    
    // Count pending applications (assuming you have an applications table)
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM applications WHERE Status = 'Pending'");
    $pendingApplications = $stmt->fetch()['count'] ?? 0;
} catch (Exception $e) {
    // Fallback to 0 if tables don't exist yet
    $activeEvents = 0;
    $totalDonations = 0;
    $confirmedAttendees = 0;
    $pendingApplications = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Dashboard - LifeSaver Hub</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            overflow-x: hidden;
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 280px;
            height: 100vh;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(20px);
            border-right: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
            z-index: 1000;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            overflow: hidden; /* Hide scrollbar overflow on container */
        }

        .sidebar-header {
            text-align: center;
            padding: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            flex-shrink: 0; /* Prevent header from shrinking */
        }

        .sidebar-header h2 {
            font-size: 24px;
            font-weight: 700;
            background: linear-gradient(45deg, #ff6b6b, #4ecdc4);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .sidebar-header .subtitle {
            font-size: 12px;
            opacity: 0.7;
            margin-top: 5px;
        }

        .sidebar-nav {
            flex: 1;
            overflow-y: auto; /* Enable vertical scrolling */
            overflow-x: hidden;
            padding: 20px 0;
        }

        /* Custom scrollbar styling */
        .sidebar-nav::-webkit-scrollbar {
            width: 6px;
        }

        .sidebar-nav::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 3px;
        }

        .sidebar-nav::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.3);
            border-radius: 3px;
            transition: background 0.3s ease;
        }

        .sidebar-nav::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.5);
        }

        /* For Firefox */
        .sidebar-nav {
            scrollbar-width: thin;
            scrollbar-color: rgba(255, 255, 255, 0.3) rgba(255, 255, 255, 0.1);
        }

        .nav-item {
            margin: 5px 20px;
        }

        .nav-item a {
            display: flex;
            align-items: center;
            color: white;
            padding: 15px 20px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            border-radius: 12px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .nav-item a::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }

        .nav-item a:hover::before {
            left: 100%;
        }

        .nav-item a:hover {
            background: rgba(255, 255, 255, 0.15);
            transform: translateX(5px);
        }

        .nav-item a i {
            margin-right: 12px;
            width: 20px;
            text-align: center;
            font-size: 16px;
        }

        .main {
            margin-left: 280px;
            padding: 30px;
            min-height: 100vh;
        }

        .header {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            position: relative;
            overflow: hidden;
        }

        .header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
            animation: float 6s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(180deg); }
        }

        .welcome-text {
            position: relative;
            z-index: 2;
        }

        .welcome-text h1 {
            color: white;
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .welcome-text .greeting {
            color: rgba(255, 255, 255, 0.8);
            font-size: 18px;
            margin-bottom: 5px;
        }

        .welcome-text .subtitle {
            color: rgba(255, 255, 255, 0.6);
            font-size: 14px;
        }

        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-top: 30px;
        }

        .card {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 30px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #ff6b6b, #4ecdc4, #45b7d1, #96ceb4);
            background-size: 300% 100%;
            animation: gradientShift 3s ease infinite;
        }

        @keyframes gradientShift {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }

        .card:hover {
            transform: translateY(-10px);
            background: rgba(255, 255, 255, 0.15);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
        }

        .card-header {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
        }

        .card-icon {
            width: 50px;
            height: 50px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-size: 24px;
            color: white;
        }

        .card-icon.account { background: linear-gradient(45deg, #667eea, #764ba2); }
        .card-icon.events { background: linear-gradient(45deg, #f093fb, #f5576c); }
        .card-icon.donations { background: linear-gradient(45deg, #4facfe, #00f2fe); }
        .card-icon.rewards { background: linear-gradient(45deg, #43e97b, #38f9d7); }

        .card h3 {
            color: white;
            font-size: 20px;
            font-weight: 600;
            margin: 0;
        }

        .card-description {
            color: rgba(255, 255, 255, 0.7);
            font-size: 14px;
            margin-bottom: 20px;
            line-height: 1.5;
        }

        .card-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            padding: 12px 20px;
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 500;
            font-size: 14px;
            transition: all 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.3);
            position: relative;
            overflow: hidden;
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

        .btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }

        .btn i {
            margin-right: 8px;
            font-size: 12px;
        }

        .stats-bar {
            display: flex;
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-item {
            flex: 1;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(20px);
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: all 0.3s ease;
        }

        .stat-item:hover {
            transform: scale(1.05);
        }

        .stat-number {
            font-size: 24px;
            font-weight: 700;
            color: white;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.7);
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .quick-actions {
            position: fixed;
            bottom: 30px;
            right: 30px;
            z-index: 1000;
            display: none;
        }

        .fab {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(45deg, #ff6b6b, #4ecdc4);
            color: white;
            border: none;
            font-size: 24px;
            cursor: pointer;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .fab:hover {
            transform: scale(1.1) rotate(90deg);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.3);
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
                max-height: 60vh; /* Limit height on mobile */
            }
            
            .main {
                margin-left: 0;
                padding: 20px;
            }
            
            .cards-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-bar {
                flex-direction: column;
            }
        }

        .pulse {
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        /* Scroll indicator for better UX */
        .scroll-indicator {
            position: absolute;
            bottom: 10px;
            left: 50%;
            transform: translateX(-50%);
            color: rgba(255, 255, 255, 0.5);
            font-size: 12px;
            animation: bounce 2s infinite;
            pointer-events: none;
        }

        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% { transform: translateX(-50%) translateY(0); }
            40% { transform: translateX(-50%) translateY(-5px); }
            60% { transform: translateX(-50%) translateY(-3px); }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <h2>LifeSaver Hub</h2>
            <div class="subtitle">Staff Portal</div>
        </div>
        
        <div class="sidebar-nav">
            <div class="nav-item">
                <a href="staff_account.php">
                    <i class="fas fa-user-circle"></i>
                    My Account
                </a>
            </div>
            <div class="nav-item">
                <a href="create_event.php">
                    <i class="fas fa-calendar-plus"></i>
                    Create Event
                </a>
            </div>
            <div class="nav-item">
                <a href="staff_view_event.php">
                    <i class="fas fa-calendar-alt"></i>
                    View Events
                </a>
            </div>
            <div class="nav-item">
                <a href="staff_view_donation.php">
                    <i class="fas fa-hand-holding-heart"></i>
                    View Donations
                </a>
            </div>
            <div class="nav-item">
                <a href="confirm_attendance.php">
                    <i class="fas fa-check-circle"></i>
                    Confirm Attendance
                </a>
            </div>
            <div class="nav-item">
                <a href="update_donor_application.php">
                    <i class="fas fa-sync-alt"></i>
                    Update Application
                </a>
            </div>
            <div class="nav-item">
                <a href="create_reward.php">
                    <i class="fas fa-gift"></i>
                    Create Rewards
                </a>
            </div>
            <div class="nav-item">
                <a href="generate_report.php">
                    <i class="fas fa-chart-line"></i>
                    Generate Report
                </a>
            </div>
            <div class="nav-item">
                <a href="logout.php">
                    <i class="fas fa-sign-out-alt"></i>
                    Logout
                </a>
            </div>
        </div>
        
        <div class="scroll-indicator" id="scrollIndicator">
            <i class="fas fa-chevron-down"></i>
        </div>
    </div>

    <div class="main">
        <div class="header">
            <div class="welcome-text">
                <div class="greeting"><?= $greeting ?>,</div>
                <h1><?= htmlspecialchars($staff['StaffName']) ?>!</h1>
                <div class="subtitle">Welcome back to your dashboard. Ready to make a difference today?</div>
            </div>
        </div>

        <div class="stats-bar">
            <div class="stat-item">
                <div class="stat-number"><?= $activeEvents ?></div>
                <div class="stat-label">Active Events</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?= $totalDonations ?></div>
                <div class="stat-label">Total Donations</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?= $confirmedAttendees ?></div>
                <div class="stat-label">Confirmed Attendees</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?= $pendingApplications ?></div>
                <div class="stat-label">Pending Applications</div>
            </div>
        </div>

        <div class="cards-grid">
            <div class="card">
                <div class="card-header">
                    <div class="card-icon account">
                        <i class="fas fa-user-cog"></i>
                    </div>
                    <h3>Account Management</h3>
                </div>
                <div class="card-description">
                    Manage your personal information, preferences, and account settings.
                </div>
                <div class="card-actions">
                    <a href="staff_account.php" class="btn">
                        <i class="fas fa-eye"></i>
                        View Profile
                    </a>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <div class="card-icon events">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <h3>Event Management</h3>
                </div>
                <div class="card-description">
                    Create, manage, and oversee blood donation events and campaigns.
                </div>
                <div class="card-actions">
                    <a href="create_event.php" class="btn">
                        <i class="fas fa-plus"></i>
                        Create Event
                    </a>
                    <a href="staff_view_event.php" class="btn">
                        <i class="fas fa-list"></i>
                        View Events
                    </a>
                    <a href="update_event.php" class="btn">
                        <i class="fas fa-edit"></i>
                        Update Event
                    </a>
                    <a href="delete_event.php" class="btn">
                        <i class="fas fa-trash"></i>
                        Delete Event
                    </a>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <div class="card-icon donations">
                        <i class="fas fa-heartbeat"></i>
                    </div>
                    <h3>Donation Management</h3>
                </div>
                <div class="card-description">
                    Track donations, confirm attendance, and manage donor applications.
                </div>
                <div class="card-actions">
                    <a href="staff_view_donation.php" class="btn">
                        <i class="fas fa-eye"></i>
                        View Donations
                    </a>
                    <a href="confirm_attendance.php" class="btn">
                        <i class="fas fa-check"></i>
                        Confirm Attendance
                    </a>
                    <a href="update_donor_application.php" class="btn">
                        <i class="fas fa-clipboard-check"></i>
                        Update Applications
                    </a>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <div class="card-icon rewards">
                        <i class="fas fa-trophy"></i>
                    </div>
                    <h3>Rewards & Reports</h3>
                </div>
                <div class="card-description">
                    Create donor rewards and generate comprehensive reports and analytics.
                </div>
                <div class="card-actions">
                    <a href="create_reward.php" class="btn">
                        <i class="fas fa-gift"></i>
                        Create Rewards
                    </a>
                    <a href="generate_report.php" class="btn">
                        <i class="fas fa-chart-bar"></i>
                        Generate Report
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Hide scroll indicator when user scrolls or when content doesn't overflow
        document.addEventListener('DOMContentLoaded', function() {
            const sidebarNav = document.querySelector('.sidebar-nav');
            const scrollIndicator = document.getElementById('scrollIndicator');
            
            function checkScrollability() {
                if (sidebarNav.scrollHeight <= sidebarNav.clientHeight) {
                    scrollIndicator.style.display = 'none';
                } else {
                    scrollIndicator.style.display = 'block';
                }
            }
            
            sidebarNav.addEventListener('scroll', function() {
                if (this.scrollTop > 20) {
                    scrollIndicator.style.opacity = '0';
                } else {
                    scrollIndicator.style.opacity = '1';
                }
            });
            
            // Check scrollability on load and resize
            checkScrollability();
            window.addEventListener('resize', checkScrollability);
        });
    </script>
</body>
</html>