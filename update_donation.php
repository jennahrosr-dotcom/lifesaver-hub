<?php
session_start();

if (!isset($_SESSION['student_id'])) {
    header("Location: student_login.php");
    exit;
}

require 'db.php';

// Get current user data
$stmt = $pdo->prepare("SELECT * FROM student WHERE StudentID = ?");
$stmt->execute([$_SESSION['student_id']]);
$student = $stmt->fetch();

// Get registration data
$stmt = $pdo->prepare("SELECT * FROM registration WHERE StudentID = ? ORDER BY RegistrationDate DESC LIMIT 1");
$stmt->execute([$_SESSION['student_id']]);
$registration = $stmt->fetch();

if (!$registration) {
    $_SESSION['error'] = "No donation application found to update.";
    header("Location: student_view_donation.php");
    exit;
}

$errors = [];
$success_message = '';

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize and validate inputs
    $name = trim($_POST['name'] ?? '');
    $ic_new = trim($_POST['ic_new'] ?? '');
    $passport = trim($_POST['passport'] ?? '');
    $birth_date = $_POST['birth_date'] ?? '';
    $age = intval($_POST['age'] ?? 0);
    $gender = $_POST['gender'] ?? '';
    $email = trim($_POST['email'] ?? '');
    $phone_mobile = trim($_POST['phone_mobile'] ?? '');
    $address_current = trim($_POST['address_current'] ?? '');

    // Basic validation
    if (empty($name)) $errors[] = "Full name is required";
    if (empty($ic_new) && empty($passport)) $errors[] = "IC Number or Passport is required";
    if (empty($birth_date)) $errors[] = "Date of birth is required";
    if (empty($gender)) $errors[] = "Gender is required";
    if ($age < 17 || $age > 65) $errors[] = "Age must be between 17-65 years old";
    if (empty($phone_mobile)) $errors[] = "Mobile phone is required";
    if (empty($address_current)) $errors[] = "Home address is required";
    if (empty($email)) $errors[] = "Email is required";

    // Email validation
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address";
    }

    // Check if email already exists for other users
    if (!empty($email) && $email !== $student['StudentEmail']) {
        $email_check = $pdo->prepare("SELECT StudentID FROM student WHERE StudentEmail = ? AND StudentID != ?");
        $email_check->execute([$email, $_SESSION['student_id']]);
        if ($email_check->fetch()) {
            $errors[] = "This email address is already registered to another user.";
        }
    }

    // If no validation errors, update database
    if (empty($errors)) {
        try {
            // Start transaction
            $pdo->beginTransaction();
            
            // Update student table
            $student_query = "UPDATE student SET 
                StudentName = ?, 
                StudentEmail = ?, 
                StudentPhone = ?, 
                StudentIC = ?,
                StudentGender = ?,
                StudentAge = ?,
                StudentAddress = ?
                WHERE StudentID = ?";
            
            $student_stmt = $pdo->prepare($student_query);
            $student_stmt->execute([
                $name, 
                $email, 
                $phone_mobile,
                $ic_new ?: $passport,
                $gender,
                $age,
                $address_current,
                $_SESSION['student_id']
            ]);
            
            // Update registration table
            $reg_query = "UPDATE registration SET 
                RegistrationName = ?, 
                RegistrationIC = ?, 
                RegistrationPassport = ?, 
                RegistrationBirthdate = ?, 
                RegistrationAge = ?, 
                RegistrationGender = ?, 
                RegistrationEmail = ?, 
                RegistrationPhoneNumber = ?, 
                RegistrationAddress = ?
                WHERE RegistrationID = ?";
            
            $reg_stmt = $pdo->prepare($reg_query);
            $reg_stmt->execute([
                $name, 
                $ic_new, 
                $passport, 
                $birth_date, 
                $age, 
                $gender, 
                $email, 
                $phone_mobile, 
                $address_current,
                $registration['RegistrationID']
            ]);
            
            // Insert notification about the update
            $notif_query = "INSERT INTO notification (
                StudentID,
                NotificationTitle, 
                NotificationMessage, 
                NotificationType, 
                CreatedDate, 
                IsRead
            ) VALUES (?, ?, ?, ?, NOW(), 0)";
            
            $notification_message = "Your donation application information has been successfully updated.";
            $notif_stmt = $pdo->prepare($notif_query);
            $notif_stmt->execute([
                $_SESSION['student_id'],
                "Information Updated",
                $notification_message,
                "System"
            ]);
            
            // Commit transaction
            $pdo->commit();
            
            // Update session name if changed
            $current_session_name = $_SESSION['donor_name'] ?? '';
            if ($name !== $current_session_name) {
                $_SESSION['donor_name'] = $name;
            }
            
            $success_message = "Your information has been successfully updated!";
            
            // Refresh data
            $stmt = $pdo->prepare("SELECT * FROM student WHERE StudentID = ?");
            $stmt->execute([$_SESSION['student_id']]);
            $student = $stmt->fetch();
            
            $stmt = $pdo->prepare("SELECT * FROM registration WHERE StudentID = ? ORDER BY RegistrationDate DESC LIMIT 1");
            $stmt->execute([$_SESSION['student_id']]);
            $registration = $stmt->fetch();
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $pdo->rollback();
            $errors[] = "Update failed: " . $e->getMessage();
            error_log("Update error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Donation Information - LifeSaver Hub</title>
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

        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            min-height: 100vh;
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            font-weight: 600;
            margin-bottom: 20px;
            padding: 10px 15px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            transition: all 0.3s ease;
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateX(-5px);
        }

        .update-header {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(25px);
            border-radius: 25px;
            padding: 30px 40px;
            margin-bottom: 30px;
            box-shadow: 0 15px 50px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
            text-align: center;
        }

        .update-header h1 {
            color: white;
            font-size: 2.2rem;
            font-weight: 800;
            margin-bottom: 10px;
            text-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
        }

        .update-header p {
            color: rgba(255, 255, 255, 0.9);
            font-size: 16px;
            font-weight: 500;
        }

        .form-container {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(25px);
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .success-message {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            border: 2px solid #28a745;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 25px;
            color: #155724;
            box-shadow: 0 8px 25px rgba(40, 167, 69, 0.3);
            text-align: center;
            font-weight: 600;
        }

        .error-messages {
            background-color: rgba(255, 107, 107, 0.2);
            border: 2px solid #ff6b6b;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 25px;
            color: #c92a2a;
        }

        .error-messages ul {
            list-style-type: none;
            margin: 0;
            padding: 0;
        }

        .error-messages li {
            margin-bottom: 5px;
            font-weight: 600;
        }

        .form-section {
            margin-bottom: 30px;
        }

        .form-section h3 {
            color: white;
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            color: rgba(255, 255, 255, 0.9);
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .required {
            color: #ff6b6b;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 15px 20px;
            border: 2px solid rgba(255, 255, 255, 0.2);
            border-radius: 15px;
            background: rgba(255, 255, 255, 0.1);
            color: white;
            font-size: 16px;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }

        .form-group input::placeholder,
        .form-group textarea::placeholder {
            color: rgba(255, 255, 255, 0.6);
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: rgba(255, 255, 255, 0.5);
            background: rgba(255, 255, 255, 0.2);
            box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
        }

        .radio-group {
            display: flex;
            gap: 20px;
            margin-top: 10px;
        }

        .radio-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .radio-item input[type="radio"] {
            width: auto;
            margin: 0;
        }

        .radio-item label {
            margin: 0;
            font-size: 16px;
            cursor: pointer;
        }

        .submit-section {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.2);
        }

        .btn-update {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            padding: 15px 30px;
            border: none;
            border-radius: 15px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-right: 15px;
        }

        .btn-update:hover {
            background: linear-gradient(135deg, #1e7e34, #17a2b8);
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        .btn-cancel {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.2), rgba(255, 255, 255, 0.1));
            color: white;
            padding: 15px 30px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 15px;
            font-size: 16px;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.3s ease;
            display: inline-block;
        }

        .btn-cancel:hover {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.3), rgba(255, 255, 255, 0.2));
            transform: translateY(-2px);
        }

        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }
            
            .form-container {
                padding: 25px;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .radio-group {
                flex-direction: column;
                gap: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="student_view_donation.php" class="back-btn">
            <i class="fas fa-arrow-left"></i>
            Back to Donation Information
        </a>

        <div class="update-header">
            <h1><i class="fas fa-edit"></i> Update Donation Information</h1>
            <p>Correct any wrong information in your blood donation application</p>
        </div>

        <div class="form-container">
            <?php if ($success_message): ?>
                <div class="success-message">
                    <i class="fas fa-check-circle"></i>
                    <?= htmlspecialchars($success_message) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="error-messages">
                    <h4><i class="fas fa-exclamation-triangle"></i> Please correct the following errors:</h4>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li>• <?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="updateForm">
                <div class="form-section">
                    <h3><i class="fas fa-user"></i> Personal Information</h3>
                    
                    <div class="form-row">
                        <div class="form-group full-width">
                            <label for="name">
                                <i class="fas fa-user"></i>
                                Full Name <span class="required">*</span>
                            </label>
                            <input type="text" id="name" name="name" value="<?= htmlspecialchars($registration['RegistrationName'] ?? $student['StudentName']) ?>" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="ic_new">
                                <i class="fas fa-id-card"></i>
                                IC Number
                            </label>
                            <input type="text" id="ic_new" name="ic_new" value="<?= htmlspecialchars($registration['RegistrationIC'] ?? '') ?>" placeholder="YYMMDD-XX-XXXX" maxlength="14">
                        </div>
                        <div class="form-group">
                            <label for="passport">
                                <i class="fas fa-passport"></i>
                                Passport Number (if no IC)
                            </label>
                            <input type="text" id="passport" name="passport" value="<?= htmlspecialchars($registration['RegistrationPassport'] ?? '') ?>" maxlength="20">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="birth_date">
                                <i class="fas fa-calendar"></i>
                                Date of Birth <span class="required">*</span>
                            </label>
                            <input type="date" id="birth_date" name="birth_date" value="<?= htmlspecialchars($registration['RegistrationBirthdate'] ?? '') ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="age">
                                <i class="fas fa-birthday-cake"></i>
                                Age <span class="required">*</span>
                            </label>
                            <input type="number" id="age" name="age" value="<?= htmlspecialchars($registration['RegistrationAge'] ?? '') ?>" min="17" max="65" readonly>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>
                                <i class="fas fa-venus-mars"></i>
                                Gender <span class="required">*</span>
                            </label>
                            <div class="radio-group">
                                <div class="radio-item">
                                    <input type="radio" id="male" name="gender" value="Male" <?= ($registration['RegistrationGender'] ?? '') === 'Male' ? 'checked' : '' ?> required>
                                    <label for="male">Male</label>
                                </div>
                                <div class="radio-item">
                                    <input type="radio" id="female" name="gender" value="Female" <?= ($registration['RegistrationGender'] ?? '') === 'Female' ? 'checked' : '' ?> required>
                                    <label for="female">Female</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="phone_mobile">
                                <i class="fas fa-phone"></i>
                                Mobile Phone <span class="required">*</span>
                            </label>
                            <input type="tel" id="phone_mobile" name="phone_mobile" value="<?= htmlspecialchars($registration['RegistrationPhoneNumber'] ?? '') ?>" required placeholder="01X-XXXXXXX">
                        </div>
                        <div class="form-group">
                            <label for="email">
                                <i class="fas fa-envelope"></i>
                                Email <span class="required">*</span>
                            </label>
                            <input type="email" id="email" name="email" value="<?= htmlspecialchars($registration['RegistrationEmail'] ?? '') ?>" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group full-width">
                            <label for="address_current">
                                <i class="fas fa-map-marker-alt"></i>
                                Home Address <span class="required">*</span>
                            </label>
                            <textarea id="address_current" name="address_current" required placeholder="Enter your complete home address" rows="3"><?= htmlspecialchars($registration['RegistrationAddress'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="submit-section">
                    <button type="submit" class="btn-update" id="updateBtn">
                        <i class="fas fa-save"></i>
                        Update Information
                    </button>
                    <a href="student_view_donation.php" class="btn-cancel">
                        <i class="fas fa-times"></i>
                        Cancel
                    </a>
                    
                    <p style="margin-top: 20px; color: rgba(255, 255, 255, 0.8); font-size: 14px;">
                        <i class="fas fa-info-circle"></i>
                        Only update information that needs correction. All changes will be reviewed.
                    </p>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Auto-calculate age based on birth date
        document.getElementById('birth_date').addEventListener('change', function() {
            const birthDate = new Date(this.value);
            const today = new Date();
            let age = today.getFullYear() - birthDate.getFullYear();
            const monthDiff = today.getMonth() - birthDate.getMonth();
            
            if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
                age--;
            }
            
            document.getElementById('age').value = age;
            
            // Validate age
            if (age < 17 || age > 65) {
                document.getElementById('age').style.borderColor = '#ff6b6b';
                document.getElementById('age').style.backgroundColor = 'rgba(255, 107, 107, 0.1)';
            } else {
                document.getElementById('age').style.borderColor = 'rgba(255, 255, 255, 0.2)';
                document.getElementById('age').style.backgroundColor = 'rgba(255, 255, 255, 0.1)';
            }
        });

        // IC Number formatting
        document.getElementById('ic_new').addEventListener('input', function() {
            let value = this.value.replace(/\D/g, ''); // Remove non-digits
            if (value.length >= 6) {
                value = value.substring(0, 6) + '-' + value.substring(6, 8) + '-' + value.substring(8, 12);
            }
            this.value = value;
        });

        // Form validation
        document.getElementById('updateForm').addEventListener('submit', function(e) {
            const name = document.getElementById('name').value.trim();
            const ic = document.getElementById('ic_new').value.trim();
            const passport = document.getElementById('passport').value.trim();
            const phone = document.getElementById('phone_mobile').value.trim();
            const email = document.getElementById('email').value.trim();
            const address = document.getElementById('address_current').value.trim();
            
            if (!name || (!ic && !passport) || !phone || !email || !address) {
                alert('Please fill in all required fields.');
                e.preventDefault();
                return false;
            }
            
            // Confirm update
            if (!confirm('Are you sure you want to update your information? This will replace your current details.')) {
                e.preventDefault();
                return false;
            }
            
            // Show loading state
            const btn = document.getElementById('updateBtn');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
            btn.disabled = true;
        });

        // Enable/disable passport field based on IC input
        document.getElementById('ic_new').addEventListener('input', function() {
            const passportField = document.getElementById('passport');
            if (this.value.trim()) {
                passportField.disabled = true;
                passportField.required = false;
                passportField.style.opacity = '0.5';
            } else {
                passportField.disabled = false;
                passportField.required = true;
                passportField.style.opacity = '1';
            }
        });

        document.getElementById('passport').addEventListener('input', function() {
            const icField = document.getElementById('ic_new');
            if (this.value.trim()) {
                icField.disabled = true;
                icField.required = false;
                icField.style.opacity = '0.5';
            } else {
                icField.disabled = false;
                icField.required = true;
                icField.style.opacity = '1';
            }
        });

        // Initialize the form state
        window.addEventListener('load', function() {
            const ic = document.getElementById('ic_new').value.trim();
            const passport = document.getElementById('passport').value.trim();
            
            if (ic) {
                document.getElementById('passport').disabled = true;
                document.getElementById('passport').required = false;
                document.getElementById('passport').style.opacity = '0.5';
            } else if (passport) {
                document.getElementById('ic_new').disabled = true;
                document.getElementById('ic_new').required = false;
                document.getElementById('ic_new').style.opacity = '0.5';
            }
        });
    </script>
</body>
</html>