<?php
// Database Configuration - Updated for your existing database structure
$host = 'localhost';        // or 127.0.0.1
$dbname = 'lifesaver';      // your database name
$username = 'root';         // default XAMPP username
$password = '';             // empty password for default XAMPP setup

// PDO Configuration
$dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// Database connection function
function getDbConnection() {
    global $dsn, $username, $password, $options;
    try {
        return new PDO($dsn, $username, $password, $options);
    } catch (PDOException $e) {
        throw new PDOException($e->getMessage(), (int)$e->getCode());
    }
}

// Test connection function (use this to verify your connection works)
function testConnection() {
    try {
        $pdo = getDbConnection();
        return "✅ Database connection successful!";
    } catch (PDOException $e) {
        return "❌ Database connection failed: " . $e->getMessage();
    }
}

// Uncomment the line below ONLY for testing the connection, then comment it back
// echo testConnection();

// Eligibility rules configuration
class EligibilityRules {
    
    // Age limits
    const MIN_AGE = 17;
    const MAX_AGE = 65;
    const SENIOR_WARNING_AGE = 60;
    
    // Deferral periods (in months)
    const SURGERY_DEFERRAL = 6;
    const TRANSFUSION_DEFERRAL = 6;
    const NEEDLE_STICK_DEFERRAL = 6;
    const TATTOO_PIERCING_DEFERRAL = 6;
    const PREGNANCY_DEFERRAL = 6;
    const HIGH_RISK_BEHAVIOR_DEFERRAL = 12;
    
    // Temporary deferral periods (in hours/days)
    const DENTAL_WORK_DEFERRAL_HOURS = 24;
    const ALCOHOL_DEFERRAL_HOURS = 24;
    const IMMUNIZATION_DEFERRAL_WEEKS = 4;
    
    // Permanent deferral conditions
    const PERMANENT_DEFERRAL_CONDITIONS = [
        'HIV',
        'Hepatitis B or Hepatitis C',
        'Sexually Transmitted Disease / Syphilis',
        'Intravenous drug use',
        'Male-to-male sexual contact',
        'HIV positive test (self or partner)',
        'Suspected HIV infection (self or partner)',
        'Extended residence in UK/Ireland during BSE outbreak period',
        'Blood transfusion in UK during risk period',
        'History of high-risk medical treatment'
    ];
    
    // Medical conditions requiring specialist clearance
    const SPECIALIST_CLEARANCE_REQUIRED = [
        'Heart Disease' => 'Cardiologist',
        'Kidney Disease' => 'Nephrologist',
        'Diabetes' => 'Endocrinologist',
        'Mental Illness' => 'Psychiatrist',
        'Epilepsy / Seizures' => 'Neurologist'
    ];
    
    // Risk assessment weights
    const RISK_WEIGHTS = [
        'age_over_60' => 2,
        'recent_illness' => 3,
        'medication_use' => 2,
        'chronic_condition' => 4,
        'recent_procedure' => 5,
        'high_risk_behavior' => 10,
        'infectious_disease_risk' => 10
    ];
    
    public static function calculateRiskScore($responses, $donor) {
        $score = 0;
        
        // Age risk
        if ($donor['age'] >= self::SENIOR_WARNING_AGE) {
            $score += self::RISK_WEIGHTS['age_over_60'];
        }
        
        // Recent illness symptoms
        if (isset($responses['q4b']) && $responses['q4b']['response'] === 'Yes') {
            $score += self::RISK_WEIGHTS['recent_illness'];
        }
        
        // Medication use
        if (isset($responses['q4a']) && $responses['q4a']['response'] === 'Yes') {
            $score += self::RISK_WEIGHTS['medication_use'];
        }
        
        // High-risk behaviors
        $high_risk_questions = ['q14a', 'q14b', 'q14c', 'q14d', 'q14e', 'q14f', 'q14g', 'q14h', 'q14i'];
        foreach ($high_risk_questions as $question) {
            if (isset($responses[$question]) && $responses[$question]['response'] === 'Yes') {
                $score += self::RISK_WEIGHTS['high_risk_behavior'];
                break; // One high-risk behavior is enough for maximum score
            }
        }
        
        return $score;
    }
    
    public static function getDeferralMessage($reason, $period = null) {
        $messages = [
            'age' => 'Age requirement not met. Donors must be between 17-65 years old.',
            'health' => 'Current health status does not meet donation requirements.',
            'medication' => 'Recent medication use requires medical clearance.',
            'surgery' => 'Recent surgical procedure. Please wait 6 months before donating.',
            'transfusion' => 'Recent blood transfusion. Please wait 6 months before donating.',
            'pregnancy' => 'Pregnancy or recent childbirth. Please wait 6 months post-delivery.',
            'high_risk' => 'High-risk behavior identified. Deferral period applies.',
            'infectious_disease' => 'Risk of infectious disease transmission.',
            'permanent' => 'Permanent deferral due to safety regulations.',
            'temporary' => 'Temporary deferral. You may reapply after the specified period.'
        ];
        
        $message = $messages[$reason] ?? 'Deferral required based on assessment.';
        
        if ($period) {
            $message .= " Deferral period: $period";
        }
        
        return $message;
    }
}

// Helper functions for the blood donation system
class BloodDonationHelper {
    
    public static function formatIC($ic) {
        // Format IC number with dashes
        if (strlen($ic) === 12 && is_numeric($ic)) {
            return substr($ic, 0, 6) . '-' . substr($ic, 6, 2) . '-' . substr($ic, 8, 4);
        }
        return $ic;
    }
    
    public static function validateIC($ic) {
        // Remove dashes and validate Malaysian IC format
        $ic = str_replace('-', '', $ic);
        return strlen($ic) === 12 && is_numeric($ic);
    }
    
    public static function calculateAge($birthDate) {
        $birth = new DateTime($birthDate);
        $today = new DateTime();
        return $birth->diff($today)->y;
    }
    
    public static function generateDonationNumber() {
        // Generate unique donation number: BD + Year + Month + Random 6 digits
        return 'BD' . date('Ym') . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
    }
    
    public static function logActivity($action, $userId, $details = []) {
        try {
            $pdo = getDbConnection();
            $stmt = $pdo->prepare("
                INSERT INTO audit_log (user_id, action, table_name, record_id, new_values, ip_address, user_agent, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $userId,
                $action,
                $details['table'] ?? null,
                $details['record_id'] ?? null,
                json_encode($details),
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ]);
            
        } catch (PDOException $e) {
            error_log("Failed to log activity: " . $e->getMessage());
        }
    }
}

// Security functions
class SecurityHelper {
    
    public static function sanitizeInput($input) {
        if (is_array($input)) {
            return array_map([self::class, 'sanitizeInput'], $input);
        }
        
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
    
    public static function validateCSRF($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
    
    public static function generateCSRF() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    
    public static function hashPassword($password) {
        return password_hash($password, PASSWORD_DEFAULT);
    }
    
    public static function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }
}

// Configuration constants
define('SYSTEM_VERSION', '2.0');
define('LAST_UPDATED', '2024-01-15');
define('RULES_VERSION', '2024.1');
define('MIN_PASSWORD_LENGTH', 8);
define('SESSION_TIMEOUT', 3600); // 1 hour
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_DURATION', 900); // 15 minutes

// Error handling
function handleDatabaseError($e) {
    error_log("Database Error: " . $e->getMessage());
    
    if (defined('DEBUG') && DEBUG) {
        return "Database Error: " . $e->getMessage();
    } else {
        return "A system error occurred. Please try again later.";
    }
}

// Session management
function initializeSession() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Regenerate session ID periodically for security
    if (!isset($_SESSION['created'])) {
        $_SESSION['created'] = time();
    } else if (time() - $_SESSION['created'] > 1800) { // 30 minutes
        session_regenerate_id(true);
        $_SESSION['created'] = time();
    }
    
    // Check session timeout
    if (isset($_SESSION['last_activity']) && 
        (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT)) {
        session_unset();
        session_destroy();
        return false;
    }
    
    $_SESSION['last_activity'] = time();
    return true;
}

// Initialize session
initializeSession();

?>