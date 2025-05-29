<?php
session_start();

// Ensure only staff can access
if (!isset($_SESSION['staff_id'])) {
    header("Location: staff_login.php");
    exit;
}

$pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$success = false;
$error = '';
$undone = false;

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $eventId = $_GET['id'];

    // Handle undo
    if (isset($_GET['undo']) && $_GET['undo'] == 'true') {
        $stmt = $pdo->prepare("UPDATE event SET EventStatus = 'Upcoming' WHERE EventID = ?");
        if ($stmt->execute([$eventId])) {
            $undone = true;
        } else {
            $error = "Failed to undo the deletion.";
        }
    } else {
        // Soft delete: update EventStatus to Deleted
        $stmt = $pdo->prepare("UPDATE event SET EventStatus = 'Deleted' WHERE EventID = ?");
        if ($stmt->execute([$eventId])) {
            $success = true;
        } else {
            $error = "Failed to delete event.";
        }
    }
} else {
    $error = "Invalid event ID.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Delete Event - LifeSaver Hub</title>
    <style>
        body {
            background-color: #f4f6fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding: 40px;
            text-align: center;
        }
        .box {
            background: white;
            max-width: 500px;
            margin: auto;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        h2 {
            color: #d62828;
            margin-bottom: 20px;
        }
        .message {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: bold;
        }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .undo-link {
            display: inline-block;
            background-color: #ffc107;
            color: black;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 6px;
            font-weight: bold;
            margin-top: 10px;
        }
        .undo-link:hover {
            background-color: #e0a800;
        }
        .back-btn {
            display: inline-block;
            margin-top: 20px;
            padding: 10px 25px;
            background: #1d3557;
            color: white;
            text-decoration: none;
            border-radius: 8px;
        }
        .back-btn:hover {
            background: #0c2a4e;
        }
    </style>
</head>
<body>
<div class="box">
    <h2>Delete Event</h2>

    <?php if ($success): ?>
        <div class="message success">
            ✅ Event marked as deleted.
        </div>
        <a href="delete_event.php?id=<?= $eventId ?>&undo=true" class="undo-link">Undo Delete</a>
    <?php elseif ($undone): ?>
        <div class="message success">
            🔄 Deletion undone. Event is now active.
        </div>
    <?php elseif ($error): ?>
        <div class="message error">
            ❌ <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <a href="view_event.php" class="back-btn">Back to Events</a>
</div>
</body>
</html>
