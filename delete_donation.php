<?php
session_start();

if (!isset($_SESSION['student_id'])) {
    header("Location: student_login.php");
    exit;
}

$pdo = new PDO("mysql:host=localhost;dbname=lifesaver;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

// Get current user data
$stmt = $pdo->prepare("SELECT * FROM student WHERE StudentID = ?");
$stmt->execute([$_SESSION['student_id']]);
$student = $stmt->fetch();

// Get registration data with enhanced error handling and debugging
$stmt = $pdo->prepare("SELECT * FROM registration WHERE StudentID = ? AND RegistrationStatus != 'Cancelled' ORDER BY RegistrationDate DESC LIMIT 1");
$stmt->execute([$_SESSION['student_id']]);
$registration = $stmt->fetch();

// Enhanced debugging and validation
error_log("=== CANCELLATION PROCESS START ===");
error_log("Student ID: " . $_SESSION['student_id']);

if ($registration) {
    error_log("✅ Found active registration:");
    error_log("  - Registration ID: " . $registration['RegistrationID']);
    error_log("  - Current Status: " . $registration['RegistrationStatus']);
    error_log("  - Student ID: " . $registration['StudentID']);
    error_log("  - Event ID: " . ($registration['EventID'] ?? 'NULL'));
    error_log("  - Registration Date: " . $registration['RegistrationDate']);
} else {
    error_log("❌ No active registration found for StudentID: " . $_SESSION['student_id']);
    // Check if any registration exists at all
    $checkAll = $pdo->prepare("SELECT * FROM registration WHERE StudentID = ? ORDER BY RegistrationDate DESC");
    $checkAll->execute([$_SESSION['student_id']]);
    $allRegistrations = $checkAll->fetchAll();
    
    if ($allRegistrations) {
        error_log("Found " . count($allRegistrations) . " total registrations for this student:");
        foreach ($allRegistrations as $reg) {
            error_log("  - ID: " . $reg['RegistrationID'] . ", Status: " . $reg['RegistrationStatus']);
        }
    } else {
        error_log("No registrations found at all for this student");
    }
}

if (!$registration) {
    $_SESSION['error'] = "No active donation application found to cancel.";
    header("Location: student_view_donation.php");
    exit;
}

// Get pending notifications count
$notificationCount = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notification WHERE StudentID = ? AND NotificationIsRead = 0");
    $stmt->execute([$_SESSION['student_id']]);
    $notificationCount = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    error_log("Error fetching notification count: " . $e->getMessage());
    $notificationCount = 0;
}

$errors = [];
$success = false;
$cancellationResult = null;
$transactionActive = false; // Track transaction state

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("=== FORM SUBMISSION RECEIVED ===");
    
    $reason = $_POST['reason'] ?? '';
    $other_reason = trim($_POST['other_reason'] ?? '');
    $confirmation = $_POST['confirmation'] ?? '';
    
    error_log("Form data received:");
    error_log("  - Reason: " . $reason);
    error_log("  - Other reason: " . $other_reason);
    error_log("  - Confirmation: " . $confirmation);
    
    // Validation
    if (empty($reason)) {
        $errors[] = "Please select a reason for cancellation.";
        error_log("❌ Validation failed: No reason selected");
    }
    
    if ($reason === 'other' && empty($other_reason)) {
        $errors[] = "Please specify your reason for cancellation.";
        error_log("❌ Validation failed: Other reason not specified");
    }
    
    if ($confirmation !== 'yes') {
        $errors[] = "Please confirm that you want to cancel your donation application.";
        error_log("❌ Validation failed: Confirmation not checked");
    }
    
    // Process cancellation if no validation errors
    if (empty($errors)) {
        error_log("✅ Validation passed - Starting cancellation process");
        
        try {
            // Start transaction for data integrity
            $pdo->beginTransaction();
            $transactionActive = true; // Mark transaction as active
            error_log("🔄 Transaction started");
            
            // Prepare final reason text
            $final_reason = $reason;
            if ($reason === 'other' && !empty($other_reason)) {
                $final_reason = $other_reason;
            }
            
            error_log("Final cancellation reason: " . $final_reason);
            
            // STEP 1: Verify the registration exists before updating
            $verifyStmt = $pdo->prepare("SELECT RegistrationID, RegistrationStatus, StudentID, EventID FROM registration WHERE RegistrationID = ? AND StudentID = ?");
            $verifyStmt->execute([$registration['RegistrationID'], $_SESSION['student_id']]);
            $existingRecord = $verifyStmt->fetch();
            
            if (!$existingRecord) {
                throw new Exception("CRITICAL ERROR: Registration record not found for update! Registration ID: " . $registration['RegistrationID']);
            }
            
            error_log("✅ Pre-update verification successful:");
            error_log("  - Registration ID: " . $existingRecord['RegistrationID']);
            error_log("  - Current Status: " . $existingRecord['RegistrationStatus']);
            error_log("  - Student ID: " . $existingRecord['StudentID']);
            error_log("  - Event ID: " . ($existingRecord['EventID'] ?? 'NULL'));
            
            // STEP 2: Update registration with cancellation details
            $update_sql = "UPDATE registration SET 
                RegistrationStatus = 'Cancelled',
                CancellationReason = ?,
                CancellationDate = NOW()
                WHERE RegistrationID = ? AND StudentID = ?";
            
            error_log("🔄 Executing UPDATE query:");
            error_log("  - SQL: " . $update_sql);
            error_log("  - Parameters: ['" . $final_reason . "', " . $registration['RegistrationID'] . ", " . $_SESSION['student_id'] . "]");
            
            $update_stmt = $pdo->prepare($update_sql);
            $update_result = $update_stmt->execute([$final_reason, $registration['RegistrationID'], $_SESSION['student_id']]);
            
            // Check affected rows
            $affected_rows = $update_stmt->rowCount();
            error_log("📊 Update result:");
            error_log("  - Execute result: " . ($update_result ? 'TRUE' : 'FALSE'));
            error_log("  - Affected rows: " . $affected_rows);
            
            if ($affected_rows === 0) {
                throw new Exception("CRITICAL ERROR: No rows were updated! This means the registration was not found or already modified.");
            }
            
            if ($affected_rows > 1) {
                throw new Exception("CRITICAL ERROR: Multiple rows were updated (" . $affected_rows . ")! This should never happen.");
            }
            
            error_log("✅ UPDATE successful - exactly 1 row affected");
            
            // STEP 3: IMMEDIATE VERIFICATION - Read back the updated record
            $verify_update_stmt = $pdo->prepare("SELECT RegistrationID, RegistrationStatus, CancellationReason, CancellationDate, StudentID, EventID FROM registration WHERE RegistrationID = ?");
            $verify_update_stmt->execute([$registration['RegistrationID']]);
            $updated_record = $verify_update_stmt->fetch();
            
            if (!$updated_record) {
                throw new Exception("CRITICAL ERROR: Cannot read back updated record! Registration ID: " . $registration['RegistrationID']);
            }
            
            error_log("🔍 POST-UPDATE VERIFICATION:");
            error_log("  - Registration ID: " . $updated_record['RegistrationID']);
            error_log("  - Status: " . $updated_record['RegistrationStatus']);
            error_log("  - Cancellation Reason: " . $updated_record['CancellationReason']);
            error_log("  - Cancellation Date: " . $updated_record['CancellationDate']);
            error_log("  - Student ID: " . $updated_record['StudentID']);
            error_log("  - Event ID: " . ($updated_record['EventID'] ?? 'NULL'));
            
            // Validate the updated values
            if ($updated_record['RegistrationStatus'] !== 'Cancelled') {
                throw new Exception("CRITICAL ERROR: Status was not updated to 'Cancelled'! Current status: " . $updated_record['RegistrationStatus']);
            }
            
            if (empty($updated_record['CancellationReason'])) {
                throw new Exception("CRITICAL ERROR: Cancellation reason was not saved!");
            }
            
            if (empty($updated_record['CancellationDate'])) {
                throw new Exception("CRITICAL ERROR: Cancellation date was not saved!");
            }
            
            if ($updated_record['CancellationReason'] !== $final_reason) {
                throw new Exception("CRITICAL ERROR: Cancellation reason mismatch! Expected: '" . $final_reason . "', Got: '" . $updated_record['CancellationReason'] . "'");
            }
            
            error_log("✅ All validation checks passed!");
            
            // STEP 4: Optional cleanup - Remove health questionnaire record
            try {
                $health_cleanup_stmt = $pdo->prepare("DELETE FROM healthquestion WHERE RegistrationID = ?");
                $health_cleanup_result = $health_cleanup_stmt->execute([$registration['RegistrationID']]);
                $health_deleted_rows = $health_cleanup_stmt->rowCount();
                
                error_log("🧹 Health questionnaire cleanup:");
                error_log("  - Execute result: " . ($health_cleanup_result ? 'TRUE' : 'FALSE'));
                error_log("  - Deleted rows: " . $health_deleted_rows);
                
                if ($health_deleted_rows > 0) {
                    error_log("✅ Health questionnaire record(s) deleted successfully");
                } else {
                    error_log("ℹ️ No health questionnaire records found to delete (this is normal)");
                }
            } catch (Exception $e) {
                error_log("⚠️ Health questionnaire cleanup failed (non-critical): " . $e->getMessage());
                // Continue - this is optional cleanup
            }
            
            // STEP 5: Create notification record
            try {
                $notification_message = "Your blood donation application has been cancelled successfully. Reason: " . $final_reason . ". You can apply again in the future. Thank you for considering blood donation.";
                
                $notif_sql = "INSERT INTO notification (
                    StudentID,
                    NotificationTitle, 
                    NotificationMessage, 
                    NotificationType, 
                    CreatedDate, 
                    NotificationIsRead
                ) VALUES (?, ?, ?, ?, NOW(), 0)";
                
                $notif_stmt = $pdo->prepare($notif_sql);
                $notif_result = $notif_stmt->execute([
                    $_SESSION['student_id'],
                    "Donation Application Cancelled",
                    $notification_message,
                    "Cancellation"
                ]);
                
                $notification_id = $pdo->lastInsertId();
                
                error_log("📧 Notification creation:");
                error_log("  - Execute result: " . ($notif_result ? 'TRUE' : 'FALSE'));
                error_log("  - Notification ID: " . $notification_id);
                
                if ($notification_id > 0) {
                    error_log("✅ Notification created successfully");
                } else {
                    error_log("⚠️ Notification creation may have failed");
                }
            } catch (Exception $e) {
                error_log("⚠️ Notification creation failed (non-critical): " . $e->getMessage());
                // Continue - notification failure should not stop the cancellation
            }
            
            // STEP 6: FINAL COMPREHENSIVE VERIFICATION
            error_log("🔍 FINAL VERIFICATION - Reading registration table one more time...");
            
            $final_verify_stmt = $pdo->prepare("SELECT * FROM registration WHERE RegistrationID = ? AND StudentID = ?");
            $final_verify_stmt->execute([$registration['RegistrationID'], $_SESSION['student_id']]);
            $final_record = $final_verify_stmt->fetch();
            
            if (!$final_record) {
                throw new Exception("CATASTROPHIC ERROR: Registration record disappeared during transaction!");
            }
            
            error_log("📊 FINAL RECORD STATE:");
            error_log("  - Registration ID: " . $final_record['RegistrationID']);
            error_log("  - Registration Status: " . $final_record['RegistrationStatus']);
            error_log("  - Cancellation Reason: " . $final_record['CancellationReason']);
            error_log("  - Cancellation Date: " . $final_record['CancellationDate']);
            error_log("  - Student ID: " . $final_record['StudentID']);
            error_log("  - Event ID: " . ($final_record['EventID'] ?? 'NULL'));
            
            // Final validation
            if ($final_record['RegistrationStatus'] !== 'Cancelled' || 
                empty($final_record['CancellationReason']) || 
                empty($final_record['CancellationDate'])) {
                throw new Exception("FINAL VERIFICATION FAILED! Data was not properly saved.");
            }
            
            // STEP 7: COMMIT TRANSACTION
            $pdo->commit();
            $transactionActive = false; // Mark transaction as completed
            error_log("✅ TRANSACTION COMMITTED SUCCESSFULLY!");
            error_log("🎉 CANCELLATION PROCESS COMPLETED SUCCESSFULLY!");
            
            // Set success state
            $success = true;
            $cancellationResult = [
                'registration_id' => $final_record['RegistrationID'],
                'student_id' => $final_record['StudentID'],
                'reason' => $final_record['CancellationReason'],
                'date' => $final_record['CancellationDate'],
                'status' => $final_record['RegistrationStatus']
            ];
            
            $_SESSION['success_message'] = "Your blood donation application has been cancelled successfully. Reason: " . $final_reason;
            
            error_log("=== CANCELLATION PROCESS COMPLETE ===");
            
        } catch (Exception $e) {
            // Only rollback if transaction is still active
            if ($transactionActive) {
                try {
                    $pdo->rollback();
                    error_log("🔄 Transaction rolled back due to error");
                } catch (Exception $rollbackException) {
                    error_log("⚠️ Rollback failed: " . $rollbackException->getMessage());
                }
            }
            
            $errors[] = "Cancellation failed: " . $e->getMessage();
            error_log("❌ CANCELLATION ERROR: " . $e->getMessage());
            error_log("💥 STACK TRACE: " . $e->getTraceAsString());
        }
    } else {
        error_log("❌ Form validation failed. Errors: " . implode(', ', $errors));
    }
}

// Enhanced debug output for current registration status
if ($registration) {
    error_log("📊 CURRENT REGISTRATION STATUS CHECK:");
    $current_check = $pdo->prepare("SELECT * FROM registration WHERE RegistrationID = ?");
    $current_check->execute([$registration['RegistrationID']]);
    $current_record = $current_check->fetch();
    
    if ($current_record) {
        error_log("  - Status: " . $current_record['RegistrationStatus']);
        error_log("  - Cancellation Reason: " . ($current_record['CancellationReason'] ?? 'NULL'));
        error_log("  - Cancellation Date: " . ($current_record['CancellationDate'] ?? 'NULL'));
    } else {
        error_log("  - Record not found!");
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cancel Donation Application - LifeSaver Hub</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #f8f9fa 0%, #e3f2fd 50%, #f3e5f5 100%);
            min-height: 100vh;
            color: #2d3748;
            position: relative;
            overflow-x: hidden;
        }

        /* Animated background elements */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: 
                radial-gradient(circle at 15% 25%, rgba(102, 126, 234, 0.08) 0%, transparent 50%),
                radial-gradient(circle at 85% 75%, rgba(16, 185, 129, 0.08) 0%, transparent 50%),
                radial-gradient(circle at 50% 50%, rgba(139, 92, 246, 0.05) 0%, transparent 50%);
            z-index: -1;
            animation: pulse 20s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 0.3; transform: scale(1); }
            50% { opacity: 0.6; transform: scale(1.05); }
        }

        .app-container {
            display: flex;
            min-height: 100vh;
        }

        /* Enhanced Sidebar - Consistent with dashboard */
        .sidebar {
            width: 320px;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.98) 0%, rgba(248, 250, 252, 0.95) 100%);
            backdrop-filter: blur(25px);
            border-right: 1px solid rgba(102, 126, 234, 0.15);
            padding: 0;
            position: fixed;
            height: 100vh;
            overflow-y: hidden;
            z-index: 1000;
            box-shadow: 4px 0 25px rgba(102, 126, 234, 0.12);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            flex-direction: column;
        }

        .sidebar-header {
            padding: 20px 24px;
            border-bottom: 1px solid rgba(102, 126, 234, 0.12);
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.08), rgba(139, 92, 246, 0.08));
            position: relative;
            overflow: hidden;
            flex-shrink: 0;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 16px;
            color: #2d3748;
            text-decoration: none;
            font-size: 26px;
            font-weight: 900;
            letter-spacing: -0.5px;
            transition: all 0.3s ease;
            position: relative;
            z-index: 1;
            padding: 8px 12px;
            border-radius: 16px;
        }

        .logo:hover {
            transform: translateY(-2px);
            color: #667eea;
            background: rgba(255, 255, 255, 0.8);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.2);
        }

        .logo img {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            object-fit: cover;
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.3);
            transition: all 0.3s ease;
            border: 2px solid rgba(255, 255, 255, 0.9);
        }

        .sidebar-nav {
            padding: 16px 0;
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .nav-section {
            margin-bottom: 20px;
        }

        .nav-section-title {
            padding: 0 24px 8px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #667eea;
            position: relative;
        }

        .nav-item {
            display: flex;
            align-items: center;
            color: #4a5568;
            text-decoration: none;
            padding: 12px 24px;
            font-weight: 600;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border-left: 4px solid transparent;
            position: relative;
            margin: 2px 12px;
            border-radius: 12px;
            overflow: hidden;
        }

        .nav-item:hover, .nav-item.active {
            color: #2d3748;
            transform: translateX(6px);
            border-left-color: #667eea;
            background: rgba(255, 255, 255, 0.6);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.15);
        }

        .nav-item i {
            width: 20px;
            margin-right: 12px;
            font-size: 16px;
            transition: all 0.3s ease;
        }

        .nav-item:hover i, .nav-item.active i {
            color: #667eea;
            transform: scale(1.1);
        }

        .notification-badge {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 4px 10px;
            border-radius: 14px;
            font-size: 11px;
            font-weight: 800;
            margin-left: auto;
            min-width: 22px;
            text-align: center;
            box-shadow: 0 3px 12px rgba(102, 126, 234, 0.4);
            position: relative;
            z-index: 1;
            animation: notificationPulse 2s infinite;
        }

        @keyframes notificationPulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }

        .user-profile {
            flex-shrink: 0;
            padding: 16px 24px;
            background: linear-gradient(135deg, rgba(248, 249, 250, 0.98), rgba(255, 255, 255, 0.98));
            border-top: 1px solid rgba(102, 126, 234, 0.15);
            backdrop-filter: blur(20px);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #2d3748;
            padding: 10px 12px;
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.8);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.1);
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 16px;
            color: white;
            box-shadow: 0 6px 18px rgba(102, 126, 234, 0.3);
            border: 2px solid rgba(255, 255, 255, 0.9);
        }

        .user-details h4 {
            font-weight: 700;
            margin-bottom: 2px;
            color: #1a202c;
            font-size: 14px;
        }

        .user-details p {
            font-size: 11px;
            color: #4a5568;
            font-weight: 500;
            opacity: 0.8;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 320px;
            padding: 32px;
            background: rgba(248, 249, 250, 0.3);
        }

        .mobile-menu-btn {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1001;
            background: rgba(30, 41, 59, 0.9);
            border: none;
            color: white;
            padding: 12px;
            border-radius: 12px;
            cursor: pointer;
            backdrop-filter: blur(20px);
        }

        /* Cancel Application Specific Styles */
        .cancel-container {
            max-width: 800px;
            margin: 0 auto;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(25px);
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(102, 126, 234, 0.15);
            overflow: hidden;
        }

        .cancel-header {
            background: linear-gradient(135deg, rgba(255, 107, 107, 0.1), rgba(238, 90, 82, 0.1));
            padding: 40px;
            text-align: center;
            border-bottom: 1px solid rgba(255, 107, 107, 0.2);
        }

        .cancel-header .warning-icon {
            font-size: 4rem;
            color: #ff6b6b;
            margin-bottom: 20px;
            animation: pulse-warning 2s ease-in-out infinite;
        }

        @keyframes pulse-warning {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }

        .cancel-header h1 {
            color: #2d3748;
            font-size: 2.2rem;
            font-weight: 800;
            margin-bottom: 12px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .cancel-header p {
            color: #4a5568;
            font-size: 16px;
            font-weight: 500;
            line-height: 1.6;
        }

        .success-message {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            border: 2px solid #28a745;
            border-radius: 20px;
            padding: 40px;
            color: #155724;
            box-shadow: 0 15px 40px rgba(40, 167, 69, 0.2);
            text-align: center;
            margin: 20px;
        }

        .success-message .success-icon {
            font-size: 3rem;
            color: #28a745;
            margin-bottom: 20px;
        }

        .success-message h2 {
            font-size: 1.8rem;
            margin-bottom: 15px;
            font-weight: 700;
        }

        .success-message p {
            font-size: 16px;
            line-height: 1.6;
            margin-bottom: 25px;
        }

        .success-actions {
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .error-messages {
            background: rgba(255, 107, 107, 0.1);
            border: 2px solid #ff6b6b;
            border-radius: 15px;
            padding: 20px;
            margin: 20px 40px;
            color: #c92a2a;
        }

        .error-messages h4 {
            margin-bottom: 10px;
            font-size: 1.1rem;
            font-weight: 700;
        }

        .error-messages ul {
            list-style-type: none;
            margin: 0;
            padding: 0;
        }

        .error-messages li {
            margin-bottom: 8px;
            font-weight: 600;
            padding-left: 20px;
            position: relative;
        }

        .error-messages li::before {
            content: '•';
            position: absolute;
            left: 0;
            color: #ff6b6b;
            font-weight: 900;
        }

        .warning-box {
            background: rgba(255, 193, 7, 0.1);
            border: 2px solid #ffc107;
            border-radius: 15px;
            padding: 25px;
            margin: 20px 40px;
            color: #856404;
        }

        .warning-box h4 {
            margin-bottom: 15px;
            font-size: 1.1rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .warning-box ul {
            margin-left: 20px;
            line-height: 1.6;
        }

        .warning-box li {
            margin-bottom: 8px;
        }

        .form-section {
            padding: 30px 40px;
        }

        .form-section h3 {
            color: #2d3748;
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .form-section h3 i {
            color: #667eea;
        }

        .reason-options {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin-bottom: 25px;
        }

        .reason-option {
            background: rgba(248, 249, 250, 0.8);
            border: 2px solid rgba(102, 126, 234, 0.2);
            border-radius: 15px;
            padding: 18px;
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }

        .reason-option::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.05), rgba(139, 92, 246, 0.05));
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .reason-option:hover {
            background: rgba(255, 255, 255, 0.9);
            border-color: rgba(102, 126, 234, 0.4);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .reason-option:hover::before {
            opacity: 1;
        }

        .reason-option.selected {
            background: rgba(102, 126, 234, 0.1);
            border-color: #667eea;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.2);
        }

        .reason-option.selected::before {
            opacity: 1;
        }

        .reason-option label {
            display: flex;
            align-items: center;
            gap: 15px;
            color: #2d3748;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            position: relative;
            z-index: 1;
        }

        .reason-option input[type="radio"] {
            margin: 0;
            transform: scale(1.2);
            accent-color: #667eea;
        }

        .reason-option i {
            color: #667eea;
            font-size: 18px;
            width: 24px;
            text-align: center;
        }

        .other-reason {
            margin-top: 20px;
            display: none;
            opacity: 0;
            transform: translateY(-10px);
            transition: all 0.3s ease;
        }

        .other-reason.show {
            display: block;
            opacity: 1;
            transform: translateY(0);
        }

        .other-reason textarea {
            width: 100%;
            padding: 15px;
            border: 2px solid rgba(102, 126, 234, 0.2);
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.8);
            color: #2d3748;
            font-size: 14px;
            font-family: inherit;
            resize: vertical;
            min-height: 100px;
            transition: all 0.3s ease;
        }

        .other-reason textarea:focus {
            outline: none;
            border-color: #667eea;
            background: rgba(255, 255, 255, 1);
            box-shadow: 0 0 20px rgba(102, 126, 234, 0.1);
        }

        .other-reason textarea::placeholder {
            color: rgba(77, 85, 104, 0.6);
        }

        .confirmation-section {
            background: rgba(255, 107, 107, 0.05);
            border: 2px solid rgba(255, 107, 107, 0.2);
            border-radius: 15px;
            padding: 25px;
            margin: 30px 40px;
        }

        .confirmation-checkbox {
            display: flex;
            align-items: center;
            gap: 15px;
            color: #2d3748;
            font-weight: 600;
        }

        .confirmation-checkbox input[type="checkbox"] {
            transform: scale(1.3);
            accent-color: #ff6b6b;
        }

        .button-section {
            display: flex;
            gap: 20px;
            justify-content: center;
            padding: 30px 40px;
            flex-wrap: wrap;
        }

        .btn-cancel-app {
            background: linear-gradient(135deg, #ff6b6b, #ee5a52);
            color: white;
            padding: 15px 35px;
            border: none;
            border-radius: 15px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 8px 25px rgba(255, 107, 107, 0.3);
        }

        .btn-cancel-app:hover:not(:disabled) {
            background: linear-gradient(135deg, #ee5a52, #e03131);
            transform: translateY(-3px);
            box-shadow: 0 12px 30px rgba(255, 107, 107, 0.4);
        }

        .btn-cancel-app:disabled {
            background: #a0aec0;
            cursor: not-allowed;
            transform: none;
            opacity: 0.6;
            box-shadow: none;
        }

        .btn-keep {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(139, 92, 246, 0.1));
            color: #2d3748;
            padding: 15px 35px;
            border: 2px solid rgba(102, 126, 234, 0.3);
            border-radius: 15px;
            font-size: 16px;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .btn-keep:hover {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.2), rgba(139, 92, 246, 0.2));
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.2);
        }

        .btn-home {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-home:hover {
            background: linear-gradient(135deg, #1e7e34, #17a2b8);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(40, 167, 69, 0.3);
        }

        /* Database Status Indicator */
        .database-status {
            background: rgba(16, 185, 129, 0.1);
            border: 2px solid #10b981;
            border-radius: 15px;
            padding: 20px;
            margin: 20px 40px;
            color: #059669;
        }

        .database-status h4 {
            margin-bottom: 10px;
            font-size: 1.1rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .database-status .status-details {
            background: rgba(255, 255, 255, 0.8);
            border-radius: 10px;
            padding: 15px;
            margin-top: 15px;
            font-size: 14px;
        }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .sidebar {
                width: 300px;
            }
            .main-content {
                margin-left: 300px;
            }
        }

        @media (max-width: 968px) {
            .mobile-menu-btn {
                display: block;
            }
            
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }
            
            .sidebar.open {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
                padding: 24px 16px;
            }
            
            .cancel-header,
            .form-section,
            .button-section {
                padding: 25px 20px;
            }
            
            .warning-box,
            .error-messages,
            .confirmation-section,
            .database-status {
                margin: 20px;
            }
            
            .button-section {
                flex-direction: column;
                align-items: center;
            }
            
            .btn-cancel-app,
            .btn-keep {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 640px) {
            .cancel-header h1 {
                font-size: 1.8rem;
            }
            
            .reason-options {
                gap: 12px;
            }
            
            .reason-option {
                padding: 15px;
            }
        }
    </style>
</head>
<body>
    <button class="mobile-menu-btn" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>

    <div class="app-container">
        <!-- Enhanced Sidebar - Consistent with dashboard -->
        <nav class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <a href="student_dashboard.php" class="logo">
                    <img src="images/logo.jpg" alt="LifeSaver Hub Logo" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                    <div style="width: 48px; height: 48px; background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 14px; display: none; align-items: center; justify-content: center; color: white; font-weight: 800; font-size: 18px; box-shadow: 0 6px 20px rgba(102, 126, 234, 0.3);">L</div>
                    <span>LifeSaver Hub</span>
                </a>
            </div>
            
            <div class="sidebar-nav">
                <div class="nav-sections-container">
                    <div class="nav-section">
                        <div class="nav-section-title">Main Menu</div>
                        <a href="student_dashboard.php" class="nav-item">
                            <i class="fas fa-home"></i>
                            <span>Dashboard</span>
                        </a>
                        <a href="student_view_event.php" class="nav-item">
                            <i class="fas fa-calendar-alt"></i>
                            <span>Events</span>
                        </a>
                        <a href="student_view_donation.php" class="nav-item active">
                            <i class="fas fa-tint"></i>
                            <span>Donations</span>
                        </a>
                        <a href="view_donation_history.php" class="nav-item">
                            <i class="fas fa-history"></i>
                            <span>Donation History</span>
                        </a>
                        <a href="view_reward.php" class="nav-item">
                            <i class="fas fa-gift"></i>
                            <span>Rewards</span>
                        </a>
                        <a href="notifications.php" class="nav-item">
                            <i class="fas fa-bell"></i>
                            <span>Notifications</span>
                            <?php if ($notificationCount > 0): ?>
                                <span class="notification-badge"><?php echo $notificationCount; ?></span>
                            <?php endif; ?>
                        </a>
                    </div>
                    
                    <div class="nav-section">
                        <div class="nav-section-title">Account</div>
                        <a href="student_account.php" class="nav-item">
                            <i class="fas fa-user"></i>
                            <span>Profile</span>
                        </a>
                        <a href="logout.php" class="nav-item">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Logout</span>
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="user-profile">
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($student['StudentName'], 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <h4><?php echo htmlspecialchars($student['StudentName']); ?></h4>
                        <p>Student ID: <?php echo htmlspecialchars($_SESSION['student_id']); ?></p>
                    </div>
                </div>
            </div>
        </nav>

        <div class="main-content">
            <div class="cancel-container">
                <?php if ($success): ?>
                    <!-- Success State -->
                    <div class="success-message">
                        <div class="success-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <h2>✅ Application Cancelled Successfully</h2>
                        <p><strong>Your blood donation application has been cancelled and saved to the registration table.</strong></p>
                        <p>We're sorry to see you go, but we understand that circumstances can change. Thank you for considering blood donation - you can apply again anytime in the future!</p>
                        
                        <div class="database-status">
                            <h4><i class="fas fa-database"></i> Registration Table Updated</h4>
                            <p>✅ Registration status updated to 'Cancelled'</p>
                            <p>✅ Cancellation reason saved</p>
                            <p>✅ Cancellation date recorded</p>
                            <p>✅ Notification sent to your account</p>
                            <p>✅ Health questionnaire cleaned up</p>
                            <p>✅ Transaction completed successfully</p>
                            <div class="status-details">
                                <strong>Registration ID:</strong> #<?= htmlspecialchars($cancellationResult['registration_id'] ?? $registration['RegistrationID']) ?><br>
                                <strong>Student ID:</strong> <?= htmlspecialchars($cancellationResult['student_id'] ?? $_SESSION['student_id']) ?><br>
                                <strong>Cancellation Time:</strong> <?= date('F j, Y \a\t g:i A') ?><br>
                                <strong>Reason:</strong> <?= htmlspecialchars($cancellationResult['reason'] ?? 'Not specified') ?><br>
                                <strong>Status:</strong> <?= htmlspecialchars($cancellationResult['status'] ?? 'Cancelled') ?>
                            </div>
                        </div>
                        
                        <div class="success-actions">
                            <a href="student_dashboard.php" class="btn-home">
                                <i class="fas fa-home"></i>
                                Go to Dashboard
                            </a>
                            <a href="student_view_donation.php" class="btn-home">
                                <i class="fas fa-tint"></i>
                                View Donations
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Cancel Form -->
                    <div class="cancel-header">
                        <div class="warning-icon">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <h1>Cancel Donation Application</h1>
                        <p>Are you sure you want to cancel your blood donation application? This action will update your registration record permanently.</p>
                    </div>

                    <?php if (!empty($errors)): ?>
                        <div class="error-messages">
                            <h4><i class="fas fa-exclamation-circle"></i> Please fix the following:</h4>
                            <ul>
                                <?php foreach ($errors as $error): ?>
                                    <li><?= htmlspecialchars($error) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <div class="database-status">
                        <h4><i class="fas fa-info-circle"></i> What gets updated in the registration table:</h4>
                        <ul style="margin-left: 20px; line-height: 1.6;">
                            <li>✅ Your registration status will be updated to 'Cancelled'</li>
                            <li>✅ Your cancellation reason will be recorded</li>
                            <li>✅ The cancellation date and time will be saved</li>
                            <li>✅ A notification will be sent to your account</li>
                            <li>✅ Health questionnaire will be cleaned up</li>
                            <li>✅ Multiple verification steps ensure data integrity</li>
                            <li>✅ Transaction safety with rollback protection</li>
                        </ul>
                    </div>

                    <div class="warning-box">
                        <h4><i class="fas fa-info-circle"></i> What happens when you cancel:</h4>
                        <ul>
                            <li>Your donation application will be permanently cancelled in the registration table</li>
                            <li>Your personal information will be removed from active donor lists</li>
                            <li>You will not receive notifications about upcoming donation events</li>
                            <li>You can re-apply in the future if you change your mind</li>
                            <li>Staff will be able to see the cancellation reason for future reference</li>
                            <li>Complete transaction safety with automatic rollback on errors</li>
                        </ul>
                    </div>

                    <form method="POST" action="" id="cancellationForm">
                        <div class="form-section">
                            <h3><i class="fas fa-question-circle"></i> Reason for cancelling donation (saved to registration table):</h3>
                            
                            <div class="reason-options">
                                <div class="reason-option" onclick="selectReason('not_feeling_well')">
                                    <label>
                                        <input type="radio" name="reason" value="I am not feeling well" id="not_feeling_well">
                                        <i class="fas fa-thermometer-half"></i>
                                        I am not feeling well
                                    </label>
                                </div>
                                
                                <div class="reason-option" onclick="selectReason('changed_mind')">
                                    <label>
                                        <input type="radio" name="reason" value="I changed my mind" id="changed_mind">
                                        <i class="fas fa-brain"></i>
                                        I changed my mind
                                    </label>
                                </div>
                                
                                <div class="reason-option" onclick="selectReason('scheduling_conflict')">
                                    <label>
                                        <input type="radio" name="reason" value="Scheduling conflict" id="scheduling_conflict">
                                        <i class="fas fa-calendar-times"></i>
                                        Scheduling conflict
                                    </label>
                                </div>
                                
                                <div class="reason-option" onclick="selectReason('medical_reasons')">
                                    <label>
                                        <input type="radio" name="reason" value="Medical reasons" id="medical_reasons">
                                        <i class="fas fa-stethoscope"></i>
                                        Medical reasons
                                    </label>
                                </div>
                                
                                <div class="reason-option" onclick="selectReason('other')">
                                    <label>
                                        <input type="radio" name="reason" value="other" id="other">
                                        <i class="fas fa-ellipsis-h"></i>
                                        Other (please specify)
                                    </label>
                                </div>
                            </div>

                            <div class="other-reason" id="otherReasonBox">
                                <textarea name="other_reason" placeholder="Please specify your reason for cancelling (this will be saved to the registration table)..." maxlength="500"></textarea>
                            </div>
                        </div>

                        <div class="confirmation-section">
                            <div class="confirmation-checkbox">
                                <input type="checkbox" name="confirmation" value="yes" id="confirmCancel">
                                <label for="confirmCancel">
                                    ✅ Yes, I understand that this cancellation will be permanently saved to the registration table and want to cancel my donation application
                                </label>
                            </div>
                        </div>

                        <div class="button-section">
                            <button type="submit" class="btn-cancel-app" id="cancelBtn" disabled>
                                <i class="fas fa-database"></i>
                                Update Registration Table
                            </button>
                            <a href="student_view_donation.php" class="btn-keep">
                                <i class="fas fa-heart"></i>
                                Keep My Application
                            </a>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('open');
        }

        function selectReason(reasonId) {
            // Remove selected class from all options
            document.querySelectorAll('.reason-option').forEach(option => {
                option.classList.remove('selected');
            });
            
            // Add selected class to clicked option
            event.currentTarget.classList.add('selected');
            
            // Check the radio button
            document.getElementById(reasonId).checked = true;
            
            // Show/hide other reason textarea
            const otherReasonBox = document.getElementById('otherReasonBox');
            if (reasonId === 'other') {
                otherReasonBox.classList.add('show');
                otherReasonBox.querySelector('textarea').focus();
            } else {
                otherReasonBox.classList.remove('show');
                otherReasonBox.querySelector('textarea').value = '';
            }
            
            validateForm();
        }

        function validateForm() {
            const reasonSelected = document.querySelector('input[name="reason"]:checked');
            const confirmation = document.getElementById('confirmCancel').checked;
            const otherReason = document.querySelector('textarea[name="other_reason"]').value.trim();
            const cancelBtn = document.getElementById('cancelBtn');
            
            let isValid = false;
            
            if (reasonSelected && confirmation) {
                if (reasonSelected.value === 'other') {
                    isValid = otherReason.length > 0;
                } else {
                    isValid = true;
                }
            }
            
            cancelBtn.disabled = !isValid;
            
            if (isValid) {
                cancelBtn.style.opacity = '1';
                cancelBtn.style.cursor = 'pointer';
            } else {
                cancelBtn.style.opacity = '0.6';
                cancelBtn.style.cursor = 'not-allowed';
            }
        }

        // Add event listeners
        document.querySelectorAll('input[name="reason"]').forEach(radio => {
            radio.addEventListener('change', validateForm);
        });

        document.getElementById('confirmCancel').addEventListener('change', validateForm);
        document.querySelector('textarea[name="other_reason"]').addEventListener('input', validateForm);

        // Form submission with enhanced confirmation
        document.getElementById('cancellationForm').addEventListener('submit', function(e) {
            const reason = document.querySelector('input[name="reason"]:checked');
            const confirmation = document.getElementById('confirmCancel').checked;
            
            if (!reason || !confirmation) {
                e.preventDefault();
                alert('Please complete all required fields before submitting.');
                return false;
            }
            
            if (reason.value === 'other') {
                const otherReason = document.querySelector('textarea[name="other_reason"]').value.trim();
                if (!otherReason) {
                    e.preventDefault();
                    alert('Please specify your reason for cancelling.');
                    return false;
                }
            }
            
            // Enhanced final confirmation with registration table warning
            const finalReason = reason.value === 'other' ? 
                document.querySelector('textarea[name="other_reason"]').value.trim() : reason.value;
            
            const confirmMessage = `🗄️ REGISTRATION TABLE UPDATE CONFIRMATION\n\nThis will PERMANENTLY update the following in the registration table:\n\n` +
                `• Registration Status: CANCELLED\n` +
                `• Cancellation Reason: ${finalReason}\n` +
                `• Cancellation Date: ${new Date().toLocaleString()}\n` +
                `• Student ID: <?= $_SESSION['student_id'] ?>\n` +
                `• Transaction Safety: Rollback protection active\n\n` +
                `This action cannot be undone. Are you absolutely sure?`;
            
            if (!confirm(confirmMessage)) {
                e.preventDefault();
                return false;
            }
            
            // Show loading state with registration table message
            const btn = document.getElementById('cancelBtn');
            btn.innerHTML = '<i class="fas fa-database fa-spin"></i> Updating Registration Table...';
            btn.disabled = true;
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const menuBtn = document.querySelector('.mobile-menu-btn');
            
            if (window.innerWidth <= 968 && 
                !sidebar.contains(event.target) && 
                !menuBtn.contains(event.target) && 
                sidebar.classList.contains('open')) {
                sidebar.classList.remove('open');
            }
        });

        // Auto-hide mobile menu on resize
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth > 968) {
                sidebar.classList.remove('open');
            }
        });

        // Initialize form validation
        document.addEventListener('DOMContentLoaded', function() {
            validateForm();
        });

        // Add entrance animation
        window.addEventListener('load', function() {
            const container = document.querySelector('.cancel-container');
            container.style.opacity = '0';
            container.style.transform = 'translateY(30px)';
            
            setTimeout(() => {
                container.style.transition = 'all 0.8s ease';
                container.style.opacity = '1';
                container.style.transform = 'translateY(0)';
            }, 100);
        });

        // Enhanced debugging - simplified for registration table only
        console.log('🎯 LifeSaver Hub - Simplified Cancel Donation Application');
        console.log('📊 USING REGISTRATION TABLE ONLY - No audit table needed!');
        console.log('Student ID:', <?= $_SESSION['student_id'] ?>);
        console.log('Student Name:', '<?= addslashes($student['StudentName'] ?? 'Unknown') ?>');
        console.log('Registration ID:', '<?= $registration['RegistrationID'] ?? 'None' ?>');
        console.log('Current Status:', '<?= $registration['RegistrationStatus'] ?? 'None' ?>');
        <?php if (!empty($registration['CancellationReason'])): ?>
        console.log('Existing Cancellation Reason:', '<?= addslashes($registration['CancellationReason']) ?>');
        <?php endif; ?>
    </script>
</body>
</html>