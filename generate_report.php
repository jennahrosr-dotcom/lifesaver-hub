<?php
session_start();

// Check staff session
if (!isset($_SESSION['staff_id'])) {
    header("Location: staff_login.php");
    exit;
}

require_once 'libs/tcpdf/tcpdf.php'; // Make sure TCPDF is installed here

$pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$success = '';
$error = '';

// Fetch event list for dropdown
$eventList = $pdo->query("SELECT EventID, EventTitle FROM event ORDER BY EventDate DESC")->fetchAll();

// Handle new report submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['report_content'])) {
    $eventId = $_POST['event_id'];
    $content = trim($_POST['report_content']);

    if (!$eventId || !$content) {
        $error = "All fields are required.";
    } else {
        $stmt = $pdo->prepare("INSERT INTO report (ReportContent, ReportDate, EventID) VALUES (?, CURDATE(), ?)");
        $stmt->execute([$content, $eventId]);
        $success = "Report submitted.";
    }
}

// Handle filters
$filterEventId = $_GET['filter_event'] ?? '';
$filterStartDate = $_GET['start_date'] ?? '';
$filterEndDate = $_GET['end_date'] ?? '';

$filterSQL = "WHERE 1";
$params = [];

if ($filterEventId !== '') {
    $filterSQL .= " AND r.EventID = ?";
    $params[] = $filterEventId;
}
if ($filterStartDate && $filterEndDate) {
    $filterSQL .= " AND r.ReportDate BETWEEN ? AND ?";
    $params[] = $filterStartDate;
    $params[] = $filterEndDate;
}

$reportStmt = $pdo->prepare("
    SELECT r.ReportID, r.ReportContent, r.ReportDate, e.EventTitle 
    FROM report r 
    JOIN event e ON r.EventID = e.EventID 
    $filterSQL
    ORDER BY r.ReportDate DESC
");
$reportStmt->execute($params);
$reports = $reportStmt->fetchAll();

// Export to PDF
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $pdf = new TCPDF();
    $pdf->AddPage();
    $pdf->SetFont('dejavusans', '', 11);
    $pdf->Write(0, 'Event Reports', '', 0, 'L', true);

    foreach ($reports as $report) {
        $pdf->Ln(5);
        $pdf->MultiCell(0, 6, "Report ID: " . $report['ReportID'], 0, 1);
        $pdf->MultiCell(0, 6, "Event: " . $report['EventTitle'], 0, 1);
        $pdf->MultiCell(0, 6, "Date: " . $report['ReportDate'], 0, 1);
        $pdf->MultiCell(0, 6, "Content:\n" . $report['ReportContent'], 0, 1);
        $pdf->Ln();
    }

    $pdf->Output('Event_Reports.pdf', 'D');
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Report Generator + Filter + PDF</title>
    <style>
        body { font-family: Arial; background: #f0f2f5; padding: 20px; }
        .container { max-width: 1000px; margin: auto; background: #fff; padding: 20px; border-radius: 8px; }
        h2 { margin-bottom: 20px; }
        label { display: block; margin-top: 10px; }
        select, textarea, input[type="date"] { width: 100%; padding: 10px; border-radius: 5px; border: 1px solid #ccc; }
        button, .btn { margin-top: 15px; padding: 8px 16px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer; text-decoration: none; display: inline-block; }
        button:hover, .btn:hover { background: #0056b3; }
        .success, .error { margin-top: 10px; padding: 10px; border-radius: 5px; }
        .success { background: #d4edda; color: #155724; }
        .error { background: #f8d7da; color: #721c24; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ccc; padding: 10px; }
        th { background: #eee; }
        form.inline { display: inline; }
    </style>
</head>
<body>
<div class="container">
    <h2>📝 Generate New Report</h2>

    <?php if ($success): ?><div class="success"><?= $success ?></div><?php endif; ?>
    <?php if ($error): ?><div class="error"><?= $error ?></div><?php endif; ?>

    <form method="POST">
        <label>Select Event</label>
        <select name="event_id" required>
            <option value="">-- Choose Event --</option>
            <?php foreach ($eventList as $e): ?>
                <option value="<?= $e['EventID'] ?>"><?= htmlspecialchars($e['EventTitle']) ?></option>
            <?php endforeach; ?>
        </select>

        <label>Report Content</label>
        <textarea name="report_content" rows="6" required></textarea>

        <button type="submit">Submit Report</button>
    </form>

    <hr>
    <h2>🔍 Filter & Export Reports</h2>
    <form method="GET">
        <label>Filter by Event:</label>
        <select name="filter_event">
            <option value="">-- All Events --</option>
            <?php foreach ($eventList as $e): ?>
                <option value="<?= $e['EventID'] ?>" <?= $filterEventId == $e['EventID'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($e['EventTitle']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label>Date Range:</label>
        <input type="date" name="start_date" value="<?= $filterStartDate ?>">
        <input type="date" name="end_date" value="<?= $filterEndDate ?>">

        <button type="submit">Apply Filter</button>
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'pdf'])) ?>" class="btn">Export PDF</a>
    </form>

    <?php if ($reports): ?>
        <h3 style="margin-top: 30px;">🗂 Report Results (<?= count($reports) ?>)</h3>
        <table>
            <thead>
                <tr>
                    <th>Report ID</th>
                    <th>Event</th>
                    <th>Date</th>
                    <th>Content</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reports as $r): ?>
                    <tr>
                        <td><?= $r['ReportID'] ?></td>
                        <td><?= htmlspecialchars($r['EventTitle']) ?></td>
                        <td><?= $r['ReportDate'] ?></td>
                        <td><?= nl2br(htmlspecialchars($r['ReportContent'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>No reports found.</p>
    <?php endif; ?>
</div>
</body>
</html>
