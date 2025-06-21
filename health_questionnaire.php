<?php
session_start();

// Check if student is logged in
if (!isset($_SESSION['donor_id'])) {
    header("Location: donor_login.php");
    exit;
}

$pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$donorId = $_SESSION['donor_id'];

// Get donor information
$stmt = $pdo->prepare("SELECT * FROM donor WHERE DonorID = ?");
$stmt->execute([$donorId]);
$donor = $stmt->fetch();

if (!$donor) {
    session_destroy();
    header("Location: donor_login.php");
    exit;
}

// Check if event ID is provided
$eventId = null;
if (isset($_GET['event_id']) && !empty($_GET['event_id'])) {
    $eventId = (int)$_GET['event_id'];
    
    // Verify event exists and is active
    $eventStmt = $pdo->prepare("SELECT * FROM event WHERE EventID = ? AND EventStatus IN ('Upcoming', 'Ongoing')");
    $eventStmt->execute([$eventId]);
    $event = $eventStmt->fetch();
    
    if (!$event) {
        $_SESSION['error'] = "Event not found or no longer available.";
        header("Location: events.php");
        exit;
    }
    
    // Check if user has already completed questionnaire for this event
    // First check if user has any registrations for this event
    $regCheckStmt = $pdo->prepare("SELECT RegistrationID FROM registration WHERE DonorID = ? AND EventID = ? ORDER BY RegistrationDate DESC LIMIT 1");
    $regCheckStmt->execute([$donorId, $eventId]);
    $existingRegistration = $regCheckStmt->fetch();
    
    if ($existingRegistration) {
        // Check if health questionnaire exists for this registration
        $existingStmt = $pdo->prepare("SELECT * FROM healthquestion WHERE RegistrationID = ? ORDER BY HealthDate DESC LIMIT 1");
        $existingStmt->execute([$existingRegistration['RegistrationID']]);
        $existingQuestionnaire = $existingStmt->fetch();
        
        if ($existingQuestionnaire) {
            if ($existingQuestionnaire['HealthStatus'] == 'Eligible') {
                // Already eligible - redirect to donation application
                $_SESSION['success'] = "You have already completed the health questionnaire and are eligible to donate.";
                header("Location: apply_donation.php?event_id=" . $eventId);
                exit;
            } else {
                // Previously marked as ineligible - show result without allowing retake
                $_SESSION['eligibility_issues'] = ["Based on your previous health questionnaire, you are not eligible to donate for this event."];
                $_SESSION['questionnaire_completed'] = true;
            }
        }
    }
} else {
    $_SESSION['error'] = "Event ID is required.";
    header("Location: events.php");
    exit;
}

// Health questionnaire questions
$healthQuestions = [
    'age' => [
        'question' => 'Are you between 18 and 65 years old?',
        'type' => 'radio',
        'required' => true,
        'category' => 'basic'
    ],
    'weight' => [
        'question' => 'Do you weigh at least 50kg (110 lbs)?',
        'type' => 'radio',
        'required' => true,
        'category' => 'basic'
    ],
    'feeling_well' => [
        'question' => 'Are you feeling well today?',
        'type' => 'radio',
        'required' => true,
        'category' => 'health'
    ],
    'recent_illness' => [
        'question' => 'Have you had any cold, flu, or other illness in the past 2 weeks?',
        'type' => 'radio',
        'required' => true,
        'category' => 'health'
    ],
    'medications' => [
        'question' => 'Are you currently taking any medications (excluding vitamins and birth control)?',
        'type' => 'radio',
        'required' => true,
        'category' => 'health'
    ],
    'antibiotics' => [
        'question' => 'Have you taken antibiotics in the past 7 days?',
        'type' => 'radio',
        'required' => true,
        'category' => 'health'
    ],
    'dental_work' => [
        'question' => 'Have you had dental work (including cleaning) in the past 24 hours?',
        'type' => 'radio',
        'required' => true,
        'category' => 'health'
    ],
    'pregnancy' => [
        'question' => 'Are you currently pregnant or have you been pregnant in the past 6 months?',
        'type' => 'radio',
        'required' => true,
        'category' => 'health',
        'condition' => 'female'
    ],
    'breastfeeding' => [
        'question' => 'Are you currently breastfeeding?',
        'type' => 'radio',
        'required' => true,
        'category' => 'health',
        'condition' => 'female'
    ],
    'last_donation' => [
        'question' => 'Has it been at least 8 weeks (56 days) since your last blood donation?',
        'type' => 'radio',
        'required' => true,
        'category' => 'donation'
    ],
    'blood_transfusion' => [
        'question' => 'Have you received a blood transfusion in the past 12 months?',
        'type' => 'radio',
        'required' => true,
        'category' => 'medical'
    ],
    'surgery' => [
        'question' => 'Have you had any surgery in the past 6 months?',
        'type' => 'radio',
        'required' => true,
        'category' => 'medical'
    ],
    'chronic_conditions' => [
        'question' => 'Do you have any chronic medical conditions (diabetes, heart disease, cancer, etc.)?',
        'type' => 'radio',
        'required' => true,
        'category' => 'medical'
    ],
    'travel_malaria' => [
        'question' => 'Have you traveled to a malaria-endemic area in the past 12 months?',
        'type' => 'radio',
        'required' => true,
        'category' => 'travel'
    ],
    'tattoo_piercing' => [
        'question' => 'Have you gotten a tattoo or piercing in the past 4 months?',
        'type' => 'radio',
        'required' => true,
        'category' => 'lifestyle'
    ],
    'drug_use' => [
        'question' => 'Have you ever used intravenous drugs (not prescribed by a doctor)?',
        'type' => 'radio',
        'required' => true,
        'category' => 'lifestyle'
    ],
    'alcohol_24h' => [
        'question' => 'Have you consumed alcohol in the past 24 hours?',
        'type' => 'radio',
        'required' => true,
        'category' => 'lifestyle'
    ],
    'sleep' => [
        'question' => 'Did you get at least 6 hours of sleep last night?',
        'type' => 'radio',
        'required' => true,
        'category' => 'lifestyle'
    ],
    'eaten_today' => [
        'question' => 'Have you eaten a meal within the past 4 hours?',
        'type' => 'radio',
        'required' => true,
        'category' => 'lifestyle'
    ]
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $responses = [];
    $eligibilityIssues = [];
    
    // Collect all responses
    foreach ($healthQuestions as $key => $question) {
        if (isset($_POST[$key])) {
            $responses[$key] = $_POST[$key];
        }
    }
    
    // Evaluate eligibility based on responses
    
    // Basic eligibility
    if (isset($responses['age']) && $responses['age'] === 'no') {
        $eligibilityIssues[] = "You must be between 18-65 years old to donate blood.";
    }
    
    if (isset($responses['weight']) && $responses['weight'] === 'no') {
        $eligibilityIssues[] = "You must weigh at least 50kg (110 lbs) to donate blood.";
    }
    
    // Health status
    if (isset($responses['feeling_well']) && $responses['feeling_well'] === 'no') {
        $eligibilityIssues[] = "You must be feeling well on the day of donation.";
    }
    
    if (isset($responses['recent_illness']) && $responses['recent_illness'] === 'yes') {
        $eligibilityIssues[] = "You must wait at least 2 weeks after recovering from any illness.";
    }
    
    if (isset($responses['antibiotics']) && $responses['antibiotics'] === 'yes') {
        $eligibilityIssues[] = "You must wait at least 7 days after finishing antibiotics.";
    }
    
    if (isset($responses['dental_work']) && $responses['dental_work'] === 'yes') {
        $eligibilityIssues[] = "You must wait at least 24 hours after dental work.";
    }
    
    // Pregnancy and breastfeeding
    if (isset($responses['pregnancy']) && $responses['pregnancy'] === 'yes') {
        $eligibilityIssues[] = "Pregnant women and those who gave birth within 6 months cannot donate blood.";
    }
    
    if (isset($responses['breastfeeding']) && $responses['breastfeeding'] === 'yes') {
        $eligibilityIssues[] = "Breastfeeding mothers cannot donate blood.";
    }
    
    // Donation history
    if (isset($responses['last_donation']) && $responses['last_donation'] === 'no') {
        $eligibilityIssues[] = "You must wait at least 8 weeks (56 days) between blood donations.";
    }
    
    // Medical history
    if (isset($responses['blood_transfusion']) && $responses['blood_transfusion'] === 'yes') {
        $eligibilityIssues[] = "You must wait 12 months after receiving a blood transfusion.";
    }
    
    if (isset($responses['surgery']) && $responses['surgery'] === 'yes') {
        $eligibilityIssues[] = "You must wait at least 6 months after major surgery.";
    }
    
    if (isset($responses['chronic_conditions']) && $responses['chronic_conditions'] === 'yes') {
        $eligibilityIssues[] = "Chronic medical conditions may affect your eligibility. Please consult with medical staff.";
    }
    
    // Travel and lifestyle
    if (isset($responses['travel_malaria']) && $responses['travel_malaria'] === 'yes') {
        $eligibilityIssues[] = "You must wait 12 months after traveling to malaria-endemic areas.";
    }
    
    if (isset($responses['tattoo_piercing']) && $responses['tattoo_piercing'] === 'yes') {
        $eligibilityIssues[] = "You must wait 4 months after getting a tattoo or piercing.";
    }
    
    if (isset($responses['drug_use']) && $responses['drug_use'] === 'yes') {
        $eligibilityIssues[] = "History of intravenous drug use permanently disqualifies you from donating.";
    }
    
    if (isset($responses['alcohol_24h']) && $responses['alcohol_24h'] === 'yes') {
        $eligibilityIssues[] = "You must wait 24 hours after consuming alcohol.";
    }
    
    if (isset($responses['sleep']) && $responses['sleep'] === 'no') {
        $eligibilityIssues[] = "You need adequate rest (at least 6 hours of sleep) before donating.";
    }
    
    if (isset($responses['eaten_today']) && $responses['eaten_today'] === 'no') {
        $eligibilityIssues[] = "You must eat a meal within 4 hours before donating.";
    }
    
    // Save questionnaire response to database
    try {
        // First create a registration if it doesn't exist
        $regStmt = $pdo->prepare("SELECT RegistrationID FROM registration WHERE DonorID = ? AND EventID = ?");
        $regStmt->execute([$donorId, $eventId]);
        $registration = $regStmt->fetch();
        
        if (!$registration) {
            // Create new registration
            $createRegStmt = $pdo->prepare("
                INSERT INTO registration (DonorID, EventID, RegistrationDate, RegistrationStatus) 
                VALUES (?, ?, NOW(), 'Pending')
            ");
            $createRegStmt->execute([$donorId, $eventId]);
            $registrationId = $pdo->lastInsertId();
        } else {
            $registrationId = $registration['RegistrationID'];
        }
        
        // Determine health status
        $healthStatus = empty($eligibilityIssues) ? 'Eligible' : 'Not Eligible';
        
        // Save to healthquestion table
        $saveStmt = $pdo->prepare("
            INSERT INTO healthquestion (RegistrationID, HealthDate, HealthStatus) 
            VALUES (?, CURDATE(), ?)
        ");
        
        $saveStmt->execute([$registrationId, $healthStatus]);
        
        if (empty($eligibilityIssues)) {
            // Eligible - redirect to donation registration
            $_SESSION['questionnaire_passed'] = true;
            $_SESSION['success'] = "Congratulations! You are eligible to donate blood.";
            header("Location: apply_donation.php?event_id=" . $eventId);
            exit;
        } else {
            // Not eligible - show reasons
            $_SESSION['eligibility_issues'] = $eligibilityIssues;
        }
        
    } catch (Exception $e) {
        $_SESSION['error'] = "Error saving questionnaire: " . $e->getMessage();
    }
}

// Check for eligibility issues from session
$eligibilityIssues = isset($_SESSION['eligibility_issues']) ? $_SESSION['eligibility_issues'] : [];
$questionnaireCompleted = isset($_SESSION['questionnaire_completed']) ? $_SESSION['questionnaire_completed'] : false;
unset($_SESSION['eligibility_issues'], $_SESSION['questionnaire_completed']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Health Questionnaire - LifeSaver Hub</title>
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

        .navbar {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(25px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            padding: 15px 0;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .navbar-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .navbar-brand {
            display: flex;
            align-items: center;
            gap: 15px;
            color: white;
            text-decoration: none;
            font-size: 24px;
            font-weight: 700;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        .navbar-brand::before {
            content: '🩸';
            font-size: 2rem;
            animation: pulse 2s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
            color: white;
            background: rgba(255, 255, 255, 0.1);
            padding: 10px 20px;
            border-radius: 25px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 30px;
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
            text-align: center;
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
        }

        .page-header h1 {
            font-size: 3rem;
            font-weight: 800;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 20px;
            text-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
            margin: 0 0 15px 0;
        }

        .page-header h1 i {
            background: linear-gradient(135deg, #f093fb, #00d2d3);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-size: 2.5rem;
            animation: bounce 2s ease-in-out infinite;
        }

        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% { transform: translateY(0); }
            40% { transform: translateY(-10px); }
            60% { transform: translateY(-5px); }
        }

        .page-header p {
            color: rgba(255, 255, 255, 0.9);
            font-size: 18px;
            font-weight: 500;
            margin-bottom: 20px;
        }

        .event-info {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 15px;
            padding: 20px;
            margin-top: 20px;
        }

        .event-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: white;
            margin-bottom: 10px;
        }

        .event-details {
            color: rgba(255, 255, 255, 0.8);
            font-size: 14px;
        }

        .questionnaire-form {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(25px);
            border-radius: 25px;
            padding: 40px;
            margin-bottom: 30px;
            box-shadow: 0 15px 50px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .form-section {
            margin-bottom: 40px;
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: white;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
            padding-bottom: 15px;
            border-bottom: 2px solid rgba(255, 255, 255, 0.2);
        }

        .section-title i {
            background: linear-gradient(135deg, #f093fb, #00d2d3);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .question-group {
            margin-bottom: 25px;
            padding: 25px;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 15px;
            border-left: 4px solid rgba(255, 255, 255, 0.3);
            transition: all 0.3s ease;
        }

        .question-group:hover {
            background: rgba(255, 255, 255, 0.12);
            transform: translateX(5px);
        }

        .question-text {
            font-size: 16px;
            font-weight: 600;
            color: white;
            margin-bottom: 15px;
            line-height: 1.5;
        }

        .radio-group {
            display: flex;
            gap: 25px;
        }

        .radio-option {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .radio-option:hover {
            transform: scale(1.05);
        }

        .radio-option input[type="radio"] {
            appearance: none;
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255, 255, 255, 0.4);
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            cursor: pointer;
            position: relative;
            transition: all 0.3s ease;
        }

        .radio-option input[type="radio"]:checked {
            border-color: #00d2d3;
            background: linear-gradient(135deg, #00d2d3, #667eea);
        }

        .radio-option input[type="radio"]:checked::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 8px;
            height: 8px;
            background: white;
            border-radius: 50%;
            transform: translate(-50%, -50%);
        }

        .radio-option label {
            color: rgba(255, 255, 255, 0.9);
            font-weight: 500;
            cursor: pointer;
        }

        .progress-bar {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            height: 8px;
            margin-bottom: 30px;
            overflow: hidden;
        }

        .progress-fill {
            background: linear-gradient(135deg, #00d2d3, #667eea);
            height: 100%;
            border-radius: 10px;
            transition: width 0.3s ease;
            box-shadow: 0 2px 10px rgba(0, 210, 211, 0.3);
        }

        .form-actions {
            display: flex;
            gap: 20px;
            justify-content: center;
            margin-top: 40px;
            padding-top: 30px;
            border-top: 1px solid rgba(255, 255, 255, 0.2);
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
            min-width: 200px;
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

        .btn-primary {
            background: linear-gradient(135deg, #10ac84, #00d2d3);
            color: white;
            box-shadow: 0 10px 30px rgba(16, 172, 132, 0.4);
        }

        .btn-primary:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 18px 40px rgba(16, 172, 132, 0.6);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 2px solid rgba(255, 255, 255, 0.4);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.3);
            border-color: rgba(255, 255, 255, 0.6);
            transform: translateY(-3px) scale(1.05);
        }

        .eligibility-result {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(25px);
            border-radius: 25px;
            padding: 40px;
            margin-bottom: 30px;
            box-shadow: 0 15px 50px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
            text-align: center;
        }

        .eligibility-result.not-eligible {
            border-left: 6px solid #ff6b6b;
            background: linear-gradient(135deg, rgba(255, 107, 107, 0.2), rgba(238, 90, 36, 0.1));
        }

        .result-icon {
            font-size: 4rem;
            margin-bottom: 20px;
            color: #ff6b6b;
            animation: shake 0.5s ease-in-out;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-10px); }
            75% { transform: translateX(10px); }
        }

        .result-title {
            font-size: 2rem;
            font-weight: 800;
            color: white;
            margin-bottom: 15px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        .result-description {
            color: rgba(255, 255, 255, 0.9);
            font-size: 16px;
            margin-bottom: 25px;
            line-height: 1.6;
        }

        .eligibility-issues {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 15px;
            padding: 25px;
            margin: 25px 0;
            text-align: left;
        }

        .issues-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: white;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .issue-item {
            background: rgba(255, 107, 107, 0.1);
            border-left: 4px solid #ff6b6b;
            padding: 15px;
            margin-bottom: 12px;
            border-radius: 8px;
            color: rgba(255, 255, 255, 0.9);
            line-height: 1.5;
        }

        .conditional-question {
            display: none;
        }

        .conditional-question.show {
            display: block;
        }

        @media (max-width: 768px) {
            .container {
                padding: 20px 15px;
            }
            
            .navbar-content {
                padding: 0 15px;
                flex-direction: column;
                gap: 15px;
            }
            
            .page-header {
                padding: 30px 20px;
            }
            
            .page-header h1 {
                font-size: 2rem;
                flex-direction: column;
            }
            
            .questionnaire-form {
                padding: 25px 20px;
            }
            
            .radio-group {
                flex-direction: column;
                gap: 15px;
            }
            
            .form-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar">
        <div class="navbar-content">
            <a href="donor_dashboard.php" class="navbar-brand">LifeSaver Hub</a>
            <div class="user-info">
                <i class="fas fa-user-circle"></i>
                <span><?php echo htmlspecialchars($donor['DonorName']); ?></span>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container">
        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-content">
                <h1>
                    <i class="fas fa-heartbeat"></i>
                    Health Questionnaire
                </h1>
                <p>Please answer all questions honestly to determine your eligibility for blood donation</p>
                
                <div class="event-info">
                    <div class="event-title">
                        <i class="fas fa-calendar-alt"></i>
                        <?php echo htmlspecialchars($event['EventTitle']); ?>
                    </div>
                    <div class="event-details">
                        <i class="fas fa-map-marker-alt"></i>
                        <?php echo htmlspecialchars($event['EventVenue']); ?>
                        <span style="margin-left: 15px;">
                            <i class="fas fa-calendar"></i>
                            <?php echo date('F j, Y', strtotime($event['EventDate'])); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($eligibilityIssues)): ?>
            <!-- Eligibility Result - Not Eligible -->
            <div class="eligibility-result not-eligible">
                <div class="result-icon">
                    <i class="fas fa-times-circle"></i>
                </div>
                <h2 class="result-title">Not Eligible to Donate</h2>
                <p class="result-description">
                    Unfortunately, based on your responses, you are currently not eligible to donate blood. 
                    These medical restrictions are in place to protect both your health and the safety of blood recipients. 
                    Please wait for the appropriate time period or consult with a healthcare provider before attempting to donate again.
                </p>
                
                <div class="eligibility-issues">
                    <div class="issues-title">
                        <i class="fas fa-exclamation-triangle"></i>
                        Eligibility Issues:
                    </div>
                    <?php foreach ($eligibilityIssues as $issue): ?>
                        <div class="issue-item">
                            <i class="fas fa-info-circle" style="margin-right: 10px; color: #ff6b6b;"></i>
                            <?php echo htmlspecialchars($issue); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <?php if ($questionnaireCompleted): ?>
                    <div style="background: rgba(255, 255, 255, 0.1); border-radius: 15px; padding: 20px; margin-top: 25px;">
                        <p style="color: rgba(255, 255, 255, 0.9); font-weight: 600; text-align: center;">
                            <i class="fas fa-info-circle" style="margin-right: 8px; color: #00d2d3;"></i>
                            Your health questionnaire has been recorded. You cannot retake the questionnaire for this event.
                        </p>
                    </div>
                <?php endif; ?>
                
                <div class="form-actions">
                    <a href="events.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i>
                        Back to Events
                    </a>
                    <a href="donor_dashboard.php" class="btn btn-primary">
                        <i class="fas fa-home"></i>
                        Go to Dashboard
                    </a>
                </div>
            </div>
        <?php elseif ($questionnaireCompleted): ?>
            <!-- Already completed but eligible case is handled in the redirect above -->
        <?php else: ?>
            <!-- Health Questionnaire Form -->
            <div class="questionnaire-form">
                <!-- Progress Bar -->
                <div class="progress-bar">
                    <div class="progress-fill" id="progressBar" style="width: 0%;"></div>
                </div>
                
                <form method="POST" action="" id="healthQuestionnaireForm">
                    <!-- Basic Information Section -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="fas fa-user"></i>
                            Basic Information
                        </h3>
                        
                        <div class="question-group">
                            <div class="question-text">
                                <?php echo $healthQuestions['age']['question']; ?>
                                <span style="color: #ff6b6b;">*</span>
                            </div>
                            <div class="radio-group">
                                <div class="radio-option">
                                    <input type="radio" id="age_yes" name="age" value="yes" required>
                                    <label for="age_yes">Yes</label>
                                </div>
                                <div class="radio-option">
                                    <input type="radio" id="age_no" name="age" value="no" required>
                                    <label for="age_no">No</label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="question-group">
                            <div class="question-text">
                                <?php echo $healthQuestions['weight']['question']; ?>
                                <span style="color: #ff6b6b;">*</span>
                            </div>
                            <div class="radio-group">
                                <div class="radio-option">
                                    <input type="radio" id="weight_yes" name="weight" value="yes" required>
                                    <label for="weight_yes">Yes</label>
                                </div>
                                <div class="radio-option">
                                    <input type="radio" id="weight_no" name="weight" value="no" required>
                                    <label for="weight_no">No</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Current Health Section -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="fas fa-stethoscope"></i>
                            Current Health Status
                        </h3>
                        
                        <div class="question-group">
                            <div class="question-text">
                                <?php echo $healthQuestions['feeling_well']['question']; ?>
                                <span style="color: #ff6b6b;">*</span>
                            </div>
                            <div class="radio-group">
                                <div class="radio-option">
                                    <input type="radio" id="feeling_well_yes" name="feeling_well" value="yes" required>
                                    <label for="feeling_well_yes">Yes</label>
                                </div>
                                <div class="radio-option">
                                    <input type="radio" id="feeling_well_no" name="feeling_well" value="no" required>
                                    <label for="feeling_well_no">No</label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="question-group">
                            <div class="question-text">
                                <?php echo $healthQuestions['recent_illness']['question']; ?>
                                <span style="color: #ff6b6b;">*</span>
                            </div>
                            <div class="radio-group">
                                <div class="radio-option">
                                    <input type="radio" id="recent_illness_yes" name="recent_illness" value="yes" required>
                                    <label for="recent_illness_yes">Yes</label>
                                </div>
                                <div class="radio-option">
                                    <input type="radio" id="recent_illness_no" name="recent_illness" value="no" required>
                                    <label for="recent_illness_no">No</label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="question-group">
                            <div class="question-text">
                                <?php echo $healthQuestions['medications']['question']; ?>
                                <span style="color: #ff6b6b;">*</span>
                            </div>
                            <div class="radio-group">
                                <div class="radio-option">
                                    <input type="radio" id="medications_yes" name="medications" value="yes" required>
                                    <label for="medications_yes">Yes</label>
                                </div>
                                <div class="radio-option">
                                    <input type="radio" id="medications_no" name="medications" value="no" required>
                                    <label for="medications_no">No</label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="question-group">
                            <div class="question-text">
                                <?php echo $healthQuestions['antibiotics']['question']; ?>
                                <span style="color: #ff6b6b;">*</span>
                            </div>
                            <div class="radio-group">
                                <div class="radio-option">
                                    <input type="radio" id="antibiotics_yes" name="antibiotics" value="yes" required>
                                    <label for="antibiotics_yes">Yes</label>
                                </div>
                                <div class="radio-option">
                                    <input type="radio" id="antibiotics_no" name="antibiotics" value="no" required>
                                    <label for="antibiotics_no">No</label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="question-group">
                            <div class="question-text">
                                <?php echo $healthQuestions['dental_work']['question']; ?>
                                <span style="color: #ff6b6b;">*</span>
                            </div>
                            <div class="radio-group">
                                <div class="radio-option">
                                    <input type="radio" id="dental_work_yes" name="dental_work" value="yes" required>
                                    <label for="dental_work_yes">Yes</label>
                                </div>
                                <div class="radio-option">
                                    <input type="radio" id="dental_work_no" name="dental_work" value="no" required>
                                    <label for="dental_work_no">No</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Women's Health Section -->
                    <div class="form-section" id="womensHealthSection">
                        <h3 class="section-title">
                            <i class="fas fa-venus"></i>
                            Women's Health (Answer if Female)
                        </h3>
                        
                        <div class="question-group">
                            <div class="question-text">
                                <?php echo $healthQuestions['pregnancy']['question']; ?>
                                <span style="color: #ff6b6b;">*</span>
                            </div>
                            <div class="radio-group">
                                <div class="radio-option">
                                    <input type="radio" id="pregnancy_yes" name="pregnancy" value="yes">
                                    <label for="pregnancy_yes">Yes</label>
                                </div>
                                <div class="radio-option">
                                    <input type="radio" id="pregnancy_no" name="pregnancy" value="no">
                                    <label for="pregnancy_no">No</label>
                                </div>
                                <div class="radio-option">
                                    <input type="radio" id="pregnancy_na" name="pregnancy" value="na">
                                    <label for="pregnancy_na">Not Applicable (Male)</label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="question-group">
                            <div class="question-text">
                                <?php echo $healthQuestions['breastfeeding']['question']; ?>
                                <span style="color: #ff6b6b;">*</span>
                            </div>
                            <div class="radio-group">
                                <div class="radio-option">
                                    <input type="radio" id="breastfeeding_yes" name="breastfeeding" value="yes">
                                    <label for="breastfeeding_yes">Yes</label>
                                </div>
                                <div class="radio-option">
                                    <input type="radio" id="breastfeeding_no" name="breastfeeding" value="no">
                                    <label for="breastfeeding_no">No</label>
                                </div>
                                <div class="radio-option">
                                    <input type="radio" id="breastfeeding_na" name="breastfeeding" value="na">
                                    <label for="breastfeeding_na">Not Applicable (Male)</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Donation History Section -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="fas fa-history"></i>
                            Donation History
                        </h3>
                        
                        <div class="question-group">
                            <div class="question-text">
                                <?php echo $healthQuestions['last_donation']['question']; ?>
                                <span style="color: #ff6b6b;">*</span>
                            </div>
                            <div class="radio-group">
                                <div class="radio-option">
                                    <input type="radio" id="last_donation_yes" name="last_donation" value="yes" required>
                                    <label for="last_donation_yes">Yes</label>
                                </div>
                                <div class="radio-option">
                                    <input type="radio" id="last_donation_no" name="last_donation" value="no" required>
                                    <label for="last_donation_no">No</label>
                                </div>
                                <div class="radio-option">
                                    <input type="radio" id="last_donation_first" name="last_donation" value="first" required>
                                    <label for="last_donation_first">First Time Donor</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Medical History Section -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="fas fa-file-medical"></i>
                            Medical History
                        </h3>
                        
                        <div class="question-group">
                            <div class="question-text">
                                <?php echo $healthQuestions['blood_transfusion']['question']; ?>
                                <span style="color: #ff6b6b;">*</span>
                            </div>
                            <div class="radio-group">
                                <div class="radio-option">
                                    <input type="radio" id="blood_transfusion_yes" name="blood_transfusion" value="yes" required>
                                    <label for="blood_transfusion_yes">Yes</label>
                                </div>
                                <div class="radio-option">
                                    <input type="radio" id="blood_transfusion_no" name="blood_transfusion" value="no" required>
                                    <label for="blood_transfusion_no">No</label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="question-group">
                            <div class="question-text">
                                <?php echo $healthQuestions['surgery']['question']; ?>
                                <span style="color: #ff6b6b;">*</span>
                            </div>
                            <div class="radio-group">
                                <div class="radio-option">
                                    <input type="radio" id="surgery_yes" name="surgery" value="yes" required>
                                    <label for="surgery_yes">Yes</label>
                                </div>
                                <div class="radio-option">
                                    <input type="radio" id="surgery_no" name="surgery" value="no" required>
                                    <label for="surgery_no">No</label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="question-group">
                            <div class="question-text">
                                <?php echo $healthQuestions['chronic_conditions']['question']; ?>
                                <span style="color: #ff6b6b;">*</span>
                            </div>
                            <div class="radio-group">
                                <div class="radio-option">
                                    <input type="radio" id="chronic_conditions_yes" name="chronic_conditions" value="yes" required>
                                    <label for="chronic_conditions_yes">Yes</label>
                                </div>
                                <div class="radio-option">
                                    <input type="radio" id="chronic_conditions_no" name="chronic_conditions" value="no" required>
                                    <label for="chronic_conditions_no">No</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Travel History Section -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="fas fa-globe"></i>
                            Travel History
                        </h3>
                        
                        <div class="question-group">
                            <div class="question-text">
                                <?php echo $healthQuestions['travel_malaria']['question']; ?>
                                <span style="color: #ff6b6b;">*</span>
                            </div>
                            <div class="radio-group">
                                <div class="radio-option">
                                    <input type="radio" id="travel_malaria_yes" name="travel_malaria" value="yes" required>
                                    <label for="travel_malaria_yes">Yes</label>
                                </div>
                                <div class="radio-option">
                                    <input type="radio" id="travel_malaria_no" name="travel_malaria" value="no" required>
                                    <label for="travel_malaria_no">No</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Lifestyle Section -->
                    <div class="form-section">
                        <h3 class="section-title">
                            <i class="fas fa-life-ring"></i>
                            Lifestyle & Recent Activities
                        </h3>
                        
                        <div class="question-group">
                            <div class="question-text">
                                <?php echo $healthQuestions['tattoo_piercing']['question']; ?>
                                <span style="color: #ff6b6b;">*</span>
                            </div>
                            <div class="radio-group">
                                <div class="radio-option">
                                    <input type="radio" id="tattoo_piercing_yes" name="tattoo_piercing" value="yes" required>
                                    <label for="tattoo_piercing_yes">Yes</label>
                                </div>
                                <div class="radio-option">
                                    <input type="radio" id="tattoo_piercing_no" name="tattoo_piercing" value="no" required>
                                    <label for="tattoo_piercing_no">No</label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="question-group">
                            <div class="question-text">
                                <?php echo $healthQuestions['drug_use']['question']; ?>
                                <span style="color: #ff6b6b;">*</span>
                            </div>
                            <div class="radio-group">
                                <div class="radio-option">
                                    <input type="radio" id="drug_use_yes" name="drug_use" value="yes" required>
                                    <label for="drug_use_yes">Yes</label>
                                </div>
                                <div class="radio-option">
                                    <input type="radio" id="drug_use_no" name="drug_use" value="no" required>
                                    <label for="drug_use_no">No</label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="question-group">
                            <div class="question-text">
                                <?php echo $healthQuestions['alcohol_24h']['question']; ?>
                                <span style="color: #ff6b6b;">*</span>
                            </div>
                            <div class="radio-group">
                                <div class="radio-option">
                                    <input type="radio" id="alcohol_24h_yes" name="alcohol_24h" value="yes" required>
                                    <label for="alcohol_24h_yes">Yes</label>
                                </div>
                                <div class="radio-option">
                                    <input type="radio" id="alcohol_24h_no" name="alcohol_24h" value="no" required>
                                    <label for="alcohol_24h_no">No</label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="question-group">
                            <div class="question-text">
                                <?php echo $healthQuestions['sleep']['question']; ?>
                                <span style="color: #ff6b6b;">*</span>
                            </div>
                            <div class="radio-group">
                                <div class="radio-option">
                                    <input type="radio" id="sleep_yes" name="sleep" value="yes" required>
                                    <label for="sleep_yes">Yes</label>
                                </div>
                                <div class="radio-option">
                                    <input type="radio" id="sleep_no" name="sleep" value="no" required>
                                    <label for="sleep_no">No</label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="question-group">
                            <div class="question-text">
                                <?php echo $healthQuestions['eaten_today']['question']; ?>
                                <span style="color: #ff6b6b;">*</span>
                            </div>
                            <div class="radio-group">
                                <div class="radio-option">
                                    <input type="radio" id="eaten_today_yes" name="eaten_today" value="yes" required>
                                    <label for="eaten_today_yes">Yes</label>
                                </div>
                                <div class="radio-option">
                                    <input type="radio" id="eaten_today_no" name="eaten_today" value="no" required>
                                    <label for="eaten_today_no">No</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Form Actions -->
                    <div class="form-actions">
                        <a href="events.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i>
                            Cancel
                        </a>
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-check-circle"></i>
                            Submit Questionnaire
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('healthQuestionnaireForm');
            const progressBar = document.getElementById('progressBar');
            const submitBtn = document.getElementById('submitBtn');
            
            // Get all radio inputs
            const radioInputs = form.querySelectorAll('input[type="radio"]');
            const totalQuestions = form.querySelectorAll('.question-group').length;
            
            // Update progress bar
            function updateProgress() {
                const answeredQuestions = new Set();
                
                radioInputs.forEach(input => {
                    if (input.checked) {
                        answeredQuestions.add(input.name);
                    }
                });
                
                const progress = (answeredQuestions.size / totalQuestions) * 100;
                progressBar.style.width = progress + '%';
                
                // Enable/disable submit button
                if (answeredQuestions.size === totalQuestions) {
                    submitBtn.disabled = false;
                    submitBtn.style.opacity = '1';
                } else {
                    submitBtn.disabled = true;
                    submitBtn.style.opacity = '0.6';
                }
            }
            
            // Add event listeners to all radio inputs
            radioInputs.forEach(input => {
                input.addEventListener('change', updateProgress);
            });
            
            // Form validation before submission
            form.addEventListener('submit', function(e) {
                const answeredQuestions = new Set();
                
                radioInputs.forEach(input => {
                    if (input.checked) {
                        answeredQuestions.add(input.name);
                    }
                });
                
                if (answeredQuestions.size < totalQuestions) {
                    e.preventDefault();
                    alert('Please answer all questions before submitting the questionnaire.');
                    return false;
                }
                
                // Show loading state
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                submitBtn.disabled = true;
            });
            
            // Initialize progress
            updateProgress();
            
            // Add smooth scrolling for better UX
            const questionGroups = document.querySelectorAll('.question-group');
            questionGroups.forEach(group => {
                const radioOptions = group.querySelectorAll('input[type="radio"]');
                radioOptions.forEach(radio => {
                    radio.addEventListener('change', function() {
                        // Add visual feedback
                        group.style.borderLeftColor = '#00d2d3';
                        group.style.transform = 'translateX(0)';
                        
                        setTimeout(() => {
                            group.style.borderLeftColor = 'rgba(255, 255, 255, 0.3)';
                        }, 1000);
                    });
                });
            });
            
            // Auto-scroll to next unanswered question
            radioInputs.forEach(input => {
                input.addEventListener('change', function() {
                    setTimeout(() => {
                        const unansweredGroup = findNextUnansweredQuestion();
                        if (unansweredGroup) {
                            unansweredGroup.scrollIntoView({
                                behavior: 'smooth',
                                block: 'center'
                            });
                        }
                    }, 500);
                });
            });
            
            function findNextUnansweredQuestion() {
                const answeredQuestions = new Set();
                
                radioInputs.forEach(input => {
                    if (input.checked) {
                        answeredQuestions.add(input.name);
                    }
                });
                
                const allQuestionNames = [...new Set(Array.from(radioInputs).map(input => input.name))];
                
                for (let questionName of allQuestionNames) {
                    if (!answeredQuestions.has(questionName)) {
                        return document.querySelector(`input[name="${questionName}"]`).closest('.question-group');
                    }
                }
                
                return null;
            }
        });
    </script>
</body>
</html>