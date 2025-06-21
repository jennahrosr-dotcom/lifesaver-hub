<?php
session_start();

// Check if staff is logged in
if (!isset($_SESSION['staff_id'])) {
    header("Location: staff_login.php");
    exit;
}

$pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$donationId = $_GET['id'] ?? null;

if (!$donationId || !is_numeric($donationId)) {
    $_SESSION['error'] = "Invalid donation ID.";
    header("Location: staff_view_donation.php");
    exit;
}

// Get existing donation data
$stmt = $pdo->prepare("SELECT * FROM donation WHERE DonationID = ?");
$stmt->execute([$donationId]);
$donation = $stmt->fetch();

if (!$donation) {
    $_SESSION['error'] = "Donation record not found.";
    header("Location: staff_view_donation.php");
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = $_POST['donation_date'];
    $bloodType = strtoupper(trim($_POST['blood_type']));
    $quantity = intval($_POST['quantity']);

    if (!$date || !$bloodType || $quantity <= 0) {
        $_SESSION['error'] = "Please fill in all fields with valid data.";
    } else {
        $update = $pdo->prepare("
            UPDATE donation 
            SET DonationDate = ?, DonationBloodType = ?, DonationQuantity = ?
            WHERE DonationID = ?
        ");
        $update->execute([$date, $bloodType, $quantity, $donationId]);

        $_SESSION['success'] = "Donation details updated successfully.";
        header("Location: staff_view_donation.php");
        exit;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Update Donor Donation</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f5f6fa; padding: 20px; }
        .container { background: white; padding: 25px; max-width: 600px; margin: auto; border-radius: 8px; }
        h2 { margin-bottom: 20px; }
        label { display: block; margin-top: 15px; }
        input[type="text"], input[type="date"], input[type="number"] {
            width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 5px; margin-top: 5px;
        }
        button { margin-top: 20px; padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 4px; }
        button:hover { background: #0056b3; cursor: pointer; }
        .message { padding: 10px; margin-bottom: 20px; }
        .error { background: #f8d7da; color: #721c24; }
    </style>
</head>
<body>
<div class="container">
    <h2>Update Donor Donation Details</h2>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="message error"><?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
    <?php endif; ?>

    <form method="post">
        <label for="donation_date">Donation Date:</label>
        <input type="date" name="donation_date" id="donation_date" required value="<?= htmlspecialchars($donation['DonationDate']) ?>">

        <label for="blood_type">Blood Type (e.g., A+, B-, O):</label>
        <input type="text" name="blood_type" id="blood_type" maxlength="3" required value="<?= htmlspecialchars($donation['DonationBloodType']) ?>">

        <label for="quantity">Quantity Donated (ml):</label>
        <input type="number" name="quantity" id="quantity" required min="1" value="<?= htmlspecialchars($donation['DonationQuantity']) ?>">

        <button type="submit">Update Donation</button>
    </form>
</div>
</body>
</html>
