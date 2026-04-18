<?php
session_start();
require_once 'db.php';

$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['signup_username'] ?? '');
    $email = trim($_POST['signup_email'] ?? '');
    $contact = trim($_POST['signup_contact'] ?? '');
    $password = $_POST['signup_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $ic = trim($_POST['student_ic'] ?? '');
    $gender = $_POST['student_gender'] ?? '';
    $age = $_POST['student_age'] ?? '';
    $address = trim($_POST['student_address'] ?? '');

    // Validation
    if (!$name || !$email || !$contact || !$password || !$confirmPassword || !$ic || !$gender || !$age || !$address) {
        $error = "Please fill in all required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";
    } elseif (!preg_match('/^[0-9\-\+\s\(\)]+$/', $contact)) {
        $error = "Invalid contact number.";
    } elseif (!preg_match('/^\d{6}-\d{2}-\d{4}$/', $ic)) {
        $error = "Invalid IC format. Please use format: XXXXXX-XX-XXXX";
    } elseif (!is_numeric($age) || $age < 18 || $age > 65) {
        $error = "Age must be between 18 and 65 years old.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } elseif ($password !== $confirmPassword) {
        $error = "Passwords do not match.";
    } else {
        // Check if email already exists
        $stmt = $conn->prepare("SELECT StudentID FROM student WHERE StudentEmail = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $error = "Email already registered.";
        } else {
            // Check if IC already exists
            $icStmt = $conn->prepare("SELECT StudentID FROM student WHERE StudentIC = ?");
            $icStmt->bind_param("s", $ic);
            $icStmt->execute();
            $icStmt->store_result();

            if ($icStmt->num_rows > 0) {
                $error = "IC number already registered.";
            } else {
                // Insert student with all fields
                $hashedPassword = $password;
                $insertStmt = $conn->prepare("INSERT INTO student (StudentName, StudentContact, StudentEmail, StudentPassword, StudentIC, StudentGender, StudentAge, StudentAddress) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $insertStmt->bind_param("ssssssss", $name, $contact, $email, $hashedPassword, $ic, $gender, $age, $address);

                if ($insertStmt->execute()) {
                    $success = true;
                    header("refresh:3;url=student_login.php");
                } else {
                    $error = "Registration failed. Please try again.";
                }
                $insertStmt->close();
            }
            $icStmt->close();
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Sign Up - LifeSaver Hub</title>
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
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px 0;
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

        .container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.2);
            width: 100%;
            max-width: 600px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea, #764ba2);
            border-radius: 24px 24px 0 0;
        }

        .header {
            margin-bottom: 32px;
        }

        .container h1 {
            font-size: 2.5rem;
            font-weight: 900;
            color: #2d3748;
            margin-bottom: 12px;
            text-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
        }

        .container h1 i {
            background: linear-gradient(135deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-size: 2rem;
        }

        .subtitle {
            color: #4a5568;
            font-size: 16px;
            font-weight: 500;
            margin-bottom: 8px;
        }

        .description {
            color: #667eea;
            font-size: 14px;
            font-weight: 600;
            background: rgba(102, 126, 234, 0.1);
            padding: 12px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            display: block;
            font-weight: 700;
            margin-bottom: 8px;
            color: #2d3748;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .form-group label i {
            margin-right: 8px;
            opacity: 0.8;
            color: #667eea;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 15px 20px;
            border: 2px solid rgba(102, 126, 234, 0.2);
            border-radius: 15px;
            font-size: 16px;
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
            color: #2d3748;
            font-weight: 500;
            font-family: inherit;
        }

        .form-group input::placeholder,
        .form-group textarea::placeholder {
            color: rgba(45, 55, 72, 0.6);
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            background: rgba(255, 255, 255, 0.95);
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
            transform: scale(1.02);
        }

        .form-group select option {
            background: rgba(255, 255, 255, 0.95);
            color: #2d3748;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .form-help {
            font-size: 12px;
            color: rgba(45, 55, 72, 0.7);
            margin-top: 5px;
            font-style: italic;
        }

        .submit-btn {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 16px 32px;
            width: 100%;
            border: none;
            border-radius: 50px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            text-transform: uppercase;
            letter-spacing: 1px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
        }

        .submit-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }

        .submit-btn:hover::before {
            left: 100%;
        }

        .submit-btn:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 15px 35px rgba(102, 126, 234, 0.6);
        }

        .submit-btn:disabled {
            background: linear-gradient(135deg, #95a5a6, #bdc3c7);
            cursor: not-allowed;
            transform: none;
            box-shadow: 0 4px 15px rgba(149, 165, 166, 0.3);
        }

        .message {
            margin-bottom: 24px;
            padding: 20px 25px;
            border-radius: 15px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 15px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
        }

        .error-message {
            background: linear-gradient(135deg, rgba(220, 53, 69, 0.9), rgba(255, 107, 107, 0.9));
            color: white;
            border-left: 4px solid #dc3545;
        }

        .success-message {
            background: linear-gradient(135deg, rgba(16, 172, 132, 0.9), rgba(0, 210, 211, 0.9));
            color: white;
            border-left: 4px solid #10ac84;
        }

        .back-link {
            margin-top: 24px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #667eea;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            padding: 12px 24px;
            border-radius: 25px;
            background: rgba(102, 126, 234, 0.1);
            transition: all 0.3s ease;
        }

        .back-link:hover {
            background: rgba(102, 126, 234, 0.2);
            transform: translateY(-2px);
            text-decoration: none;
            color: #667eea;
        }

        .password-strength {
            margin-top: 8px;
            font-size: 12px;
        }

        .strength-bar {
            height: 4px;
            border-radius: 2px;
            margin-top: 4px;
            transition: all 0.3s ease;
        }

        .strength-weak { background: #e74c3c; width: 33%; }
        .strength-medium { background: #f39c12; width: 66%; }
        .strength-strong { background: #27ae60; width: 100%; }

        /* Responsive Design */
        @media (max-width: 768px) {
            .container {
                margin: 10px;
                padding: 30px 24px;
                max-width: 95%;
            }

            .container h1 {
                font-size: 2rem;
            }

            .form-grid {
                grid-template-columns: 1fr;
                gap: 16px;
            }

            .form-group {
                margin-bottom: 16px;
            }
        }

        @media (max-width: 480px) {
            .container {
                padding: 24px 20px;
            }

            .container h1 {
                font-size: 1.8rem;
            }

            .form-group input,
            .form-group select,
            .form-group textarea {
                padding: 12px 16px;
                font-size: 14px;
            }
        }

        /* Animation for loading */
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

        .container {
            animation: fadeInUp 0.6s ease-out forwards;
        }

        /* Scrollbar Styling */
        ::-webkit-scrollbar {
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: rgba(102, 126, 234, 0.1);
        }

        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #764ba2, #667eea);
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>
            <i class="fas fa-user-plus"></i>
            Student Registration
        </h1>
        <div class="subtitle">Join LifeSaver Hub - Make a Difference</div>
        <div class="description">Create your account to participate in blood donation events and save lives</div>
    </div>

    <?php if ($error): ?>
        <div class="message error-message">
            <i class="fas fa-exclamation-triangle"></i>
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="message success-message">
            <i class="fas fa-check-circle"></i>
            Registration successful! Redirecting to login page...
        </div>
    <?php endif; ?>

    <form method="POST" action="" id="signupForm">
        <div class="form-grid">
            <div class="form-group">
                <label for="signup-username">
                    <i class="fas fa-user"></i>
                    Name
                </label>
                <input type="text" id="signup-username" name="signup_username" required 
                       value="<?= htmlspecialchars($_POST['signup_username'] ?? '') ?>"
                       placeholder="Enter your name">
                <div class="form-help">As per your IC or official documents</div>
            </div>

            <div class="form-group">
                <label for="signup-email">
                    <i class="fas fa-envelope"></i>
                    Email Address
                </label>
                <input type="email" id="signup-email" name="signup_email" required 
                       value="<?= htmlspecialchars($_POST['signup_email'] ?? '') ?>"
                       placeholder="your.email@example.com">
                <div class="form-help">We'll use this for event notifications</div>
            </div>

            <div class="form-group">
                <label for="signup-contact">
                    <i class="fas fa-phone"></i>
                    Contact Number
                </label>
                <input type="text" id="signup-contact" name="signup_contact" required 
                       value="<?= htmlspecialchars($_POST['signup_contact'] ?? '') ?>"
                       placeholder="01X-XXXXXXX">
                <div class="form-help">Include country code if applicable</div>
            </div>

            <div class="form-group">
                <label for="student-ic">
                    <i class="fas fa-id-card"></i>
                    IC Number
                </label>
                <input type="text" id="student-ic" name="student_ic" required 
                       value="<?= htmlspecialchars($_POST['student_ic'] ?? '') ?>"
                       placeholder="XXXXXX-XX-XXXX"
                       pattern="^\d{6}-\d{2}-\d{4}$">
                <div class="form-help">Format: XXXXXX-XX-XXXX (with dashes)</div>
            </div>

            <div class="form-group">
                <label for="student-gender">
                    <i class="fas fa-venus-mars"></i>
                    Gender
                </label>
                <select id="student-gender" name="student_gender" required>
                    <option value="">Select Gender</option>
                    <option value="Male" <?= ($_POST['student_gender'] ?? '') === 'Male' ? 'selected' : '' ?>>Male</option>
                    <option value="Female" <?= ($_POST['student_gender'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
                </select>
            </div>

            <div class="form-group">
                <label for="student-age">
                    <i class="fas fa-calendar-alt"></i>
                    Age
                </label>
                <input type="number" id="student-age" name="student_age" required 
                       value="<?= htmlspecialchars($_POST['student_age'] ?? '') ?>"
                       placeholder="Enter your age"
                       min="18" max="65">
                <div class="form-help">Must be between 18-65 years old to donate</div>
            </div>
        </div>

        <div class="form-group full-width">
            <label for="student-address">
                <i class="fas fa-map-marker-alt"></i>
                Address
            </label>
            <textarea id="student-address" name="student_address" required 
                      placeholder="Enter your complete address"><?= htmlspecialchars($_POST['student_address'] ?? '') ?></textarea>
            <div class="form-help">Provide your complete residential address</div>
        </div>

        <div class="form-grid">
            <div class="form-group">
                <label for="signup-password">
                    <i class="fas fa-lock"></i>
                    Password
                </label>
                <input type="password" id="signup-password" name="signup_password" required
                       placeholder="Create a strong password">
                <div class="password-strength" id="passwordStrength" style="display: none;">
                    <div class="strength-bar" id="strengthBar"></div>
                    <span id="strengthText"></span>
                </div>
                <div class="form-help">Minimum 6 characters</div>
            </div>

            <div class="form-group">
                <label for="confirm-password">
                    <i class="fas fa-lock"></i>
                    Confirm Password
                </label>
                <input type="password" id="confirm-password" name="confirm_password" required
                       placeholder="Re-enter your password">
                <div class="form-help">Must match the password above</div>
            </div>
        </div>

        <button type="submit" class="submit-btn" id="submitBtn">
            <i class="fas fa-user-plus"></i>
            Create Account
        </button>
    </form>

    <a href="student_login.php" class="back-link">
        <i class="fas fa-arrow-left"></i>
        Back to Login
    </a>
</div>

<script>
    // Password strength checker
    document.getElementById('signup-password').addEventListener('input', function() {
        const password = this.value;
        const strengthDiv = document.getElementById('passwordStrength');
        const strengthBar = document.getElementById('strengthBar');
        const strengthText = document.getElementById('strengthText');
        
        if (password.length === 0) {
            strengthDiv.style.display = 'none';
            return;
        }
        
        strengthDiv.style.display = 'block';
        
        let strength = 0;
        if (password.length >= 6) strength++;
        if (password.match(/[a-z]/)) strength++;
        if (password.match(/[A-Z]/)) strength++;
        if (password.match(/[0-9]/)) strength++;
        if (password.match(/[^a-zA-Z0-9]/)) strength++;
        
        strengthBar.className = 'strength-bar';
        
        if (strength < 3) {
            strengthBar.classList.add('strength-weak');
            strengthText.textContent = 'Weak';
            strengthText.style.color = '#e74c3c';
        } else if (strength < 4) {
            strengthBar.classList.add('strength-medium');
            strengthText.textContent = 'Medium';
            strengthText.style.color = '#f39c12';
        } else {
            strengthBar.classList.add('strength-strong');
            strengthText.textContent = 'Strong';
            strengthText.style.color = '#27ae60';
        }
    });

    // IC number formatting
    document.getElementById('student-ic').addEventListener('input', function() {
        let value = this.value.replace(/\D/g, ''); // Remove all non-digits
        if (value.length >= 6) {
            value = value.substring(0, 6) + '-' + value.substring(6);
        }
        if (value.length >= 9) {
            value = value.substring(0, 9) + '-' + value.substring(9, 13);
        }
        this.value = value;
    });

    // Contact number validation
    document.getElementById('signup-contact').addEventListener('input', function() {
        // Allow only numbers, spaces, dashes, plus signs, and parentheses
        this.value = this.value.replace(/[^0-9\-\+\s\(\)]/g, '');
    });

    // Form submission with loading state
    document.getElementById('signupForm').addEventListener('submit', function() {
        const submitBtn = document.getElementById('submitBtn');
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating Account...';
        submitBtn.disabled = true;

        // Re-enable button after 10 seconds (fallback)
        setTimeout(() => {
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }, 10000);
    });

    // Confirm password validation
    document.getElementById('confirm-password').addEventListener('blur', function() {
        const password = document.getElementById('signup-password').value;
        const confirmPassword = this.value;
        
        if (confirmPassword && password !== confirmPassword) {
            this.style.borderColor = '#e74c3c';
            this.style.background = 'rgba(231, 76, 60, 0.1)';
        } else if (confirmPassword && password === confirmPassword) {
            this.style.borderColor = '#27ae60';
            this.style.background = 'rgba(39, 174, 96, 0.1)';
        }
    });

    // Age validation
    document.getElementById('student-age').addEventListener('blur', function() {
        const age = parseInt(this.value);
        if (age && (age < 18 || age > 65)) {
            this.style.borderColor = '#e74c3c';
            this.style.background = 'rgba(231, 76, 60, 0.1)';
        } else if (age && age >= 18 && age <= 65) {
            this.style.borderColor = '#27ae60';
            this.style.background = 'rgba(39, 174, 96, 0.1)';
        }
    });

    // Auto-hide alerts after 5 seconds
    document.addEventListener('DOMContentLoaded', function() {
        const alerts = document.querySelectorAll('.message');
        alerts.forEach(function(alert) {
            if (!alert.classList.contains('success-message')) {
                setTimeout(function() {
                    alert.style.opacity = '0';
                    setTimeout(function() {
                        alert.remove();
                    }, 300);
                }, 5000);
            }
        });
    });

    // Focus management for accessibility
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Tab') {
            document.body.classList.add('keyboard-navigation');
        }
    });

    document.addEventListener('mousedown', function() {
        document.body.classList.remove('keyboard-navigation');
    });

    // Add CSS for keyboard navigation
    const keyboardStyle = document.createElement('style');
    keyboardStyle.textContent = `
        .keyboard-navigation input:focus,
        .keyboard-navigation select:focus,
        .keyboard-navigation textarea:focus,
        .keyboard-navigation .submit-btn:focus,
        .keyboard-navigation .back-link:focus {
            outline: 3px solid #667eea;
            outline-offset: 2px;
        }
    `;
    document.head.appendChild(keyboardStyle);

    console.log('✅ Student Registration Form Loaded');
    console.log('- Complete student information form');
    console.log('- Password strength validation');
    console.log('- IC number formatting');
    console.log('- Age and contact validation');
    console.log('- Responsive design');
    console.log('- Modern UI with animations');
</script>
</body>
</html>