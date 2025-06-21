<?php
session_start();

if (!isset($_SESSION['staff_id'])) {
    header("Location: staff_login.php");
    exit;
}

// Check if we have confirmation data
if (!isset($_SESSION['delete_confirmation'])) {
    $_SESSION['error'] = "No event selected for deletion.";
    header("Location: staff_manage_events.php");
    exit;
}

$confirmData = $_SESSION['delete_confirmation'];
$eventId = $confirmData['event_id'];
$eventTitle = $confirmData['event_title'];
$confirmedRegistrations = $confirmData['confirmed_registrations'];

// Get staff information
$pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$stmt = $pdo->prepare("SELECT * FROM staff WHERE StaffID = ?");
$stmt->execute([$_SESSION['staff_id']]);
$staff = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirm Delete Event - LifeSaver Hub</title>
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
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
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

        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            33% { transform: translateY(-20px) rotate(1deg); }
            66% { transform: translateY(-10px) rotate(-1deg); }
        }

        .confirmation-container {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(25px);
            border-radius: 25px;
            padding: 50px;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            max-width: 600px;
            width: 100%;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .confirmation-container::before {
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

        .confirmation-content {
            position: relative;
            z-index: 1;
        }

        .warning-icon {
            font-size: 5rem;
            color: #ff6b6b;
            margin-bottom: 30px;
            animation: pulse 2s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }

        .confirmation-title {
            font-size: 2.5rem;
            font-weight: 800;
            color: white;
            margin-bottom: 20px;
            text-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
        }

        .confirmation-message {
            color: rgba(255, 255, 255, 0.9);
            font-size: 18px;
            line-height: 1.6;
            margin-bottom: 30px;
        }

        .event-details {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 15px;
            padding: 25px;
            margin: 30px 0;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .event-name {
            font-size: 1.5rem;
            font-weight: 700;
            color: white;
            margin-bottom: 15px;
        }

        .registration-warning {
            background: linear-gradient(135deg, rgba(255, 107, 107, 0.3), rgba(238, 90, 36, 0.3));
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
            border-left: 4px solid #ff6b6b;
        }

        .registration-count {
            font-size: 2rem;
            font-weight: 800;
            color: #ff6b6b;
            margin-bottom: 5px;
        }

        .registration-text {
            color: rgba(255, 255, 255, 0.9);
            font-weight: 600;
        }

        .action-buttons {
            display: flex;
            gap: 20px;
            justify-content: center;
            margin-top: 40px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            padding: 18px 35px;
            border: none;
            border-radius: 50px;
            font-weight: 700;
            font-size: 16px;
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
            min-width: 180px;
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

        .btn-danger {
            background: linear-gradient(135deg, #ff6b6b, #ee5a24);
            color: white;
            box-shadow: 0 10px 30px rgba(255, 107, 107, 0.4);
        }

        .btn-danger:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 18px 40px rgba(255, 107, 107, 0.6);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 2px solid rgba(255, 255, 255, 0.4);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.3);
            border-color: rgba(255, 255, 255, 0.6);
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 18px 40px rgba(0, 0, 0, 0.3);
        }

        .consequences {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 15px;
            padding: 25px;
            margin: 30px 0;
            text-align: left;
        }

        .consequences h4 {
            color: white;
            font-size: 1.2rem;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .consequences ul {
            color: rgba(255, 255, 255, 0.9);
            margin-left: 20px;
        }

        .consequences li {
            margin-bottom: 8px;
            line-height: 1.5;
        }

        @media (max-width: 768px) {
            .confirmation-container {
                padding: 30px 20px;
                margin: 10px;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                min-width: auto;
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="confirmation-container">
        <div class="confirmation-content">
            <i class="fas fa-exclamation-triangle warning-icon"></i>
            
            <h1 class="confirmation-title">Confirm Event Deletion</h1>
            
            <p class="confirmation-message">
                You are about to delete an event that has active registrations. 
                This action will affect multiple donors who have registered for this event.
            </p>

            <div class="event-details">
                <div class="event-name"><?php echo htmlspecialchars($eventTitle); ?></div>
                
                <div class="registration-warning">
                    <div class="registration-count"><?php echo number_format($confirmedRegistrations); ?></div>
                    <div class="registration-text">
                        Confirmed Registration<?php echo $confirmedRegistrations !== 1 ? 's' : ''; ?>
                    </div>
                </div>
            </div>

            <div class="consequences">
                <h4>
                    <i class="fas fa-info-circle"></i>
                    What will happen when you delete this event:
                </h4>
                <ul>
                    <li>The event status will be changed to "Deleted"</li>
                    <li>All <?php echo $confirmedRegistrations; ?> confirmed registration<?php echo $confirmedRegistrations !== 1 ? 's' : ''; ?> will be automatically cancelled</li>
                    <li>Registered donors will need to be notified manually about the cancellation</li>
                    <li>The event can be restored later if needed</li>
                    <li>This action will be logged for audit purposes</li>
                </ul>
            </div>

            <div class="action-buttons">
                <a href="staff_view_event.php?id=<?php echo $eventId; ?>" class="btn btn-secondary">
                    <i class="fas fa-times"></i>
                    Cancel
                </a>
                
                <form method="POST" action="delete_event.php" style="display: inline;">
                    <input type="hidden" name="event_id" value="<?php echo $eventId; ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="force_delete" value="1">
                    <button type="submit" class="btn btn-danger" onclick="return confirmFinalDelete()">
                        <i class="fas fa-trash"></i>
                        Yes, Delete Event
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        function confirmFinalDelete() {
            return confirm('Are you absolutely sure you want to delete this event and cancel all registrations? This action cannot be easily undone.');
        }

        // Clear the session data when user navigates away
        window.addEventListener('beforeunload', function() {
            // This will be handled by the server when the user navigates to another page
        });
    </script>
</body>
</html>

<?php
// Clear the confirmation data from session
unset($_SESSION['delete_confirmation']);
?>