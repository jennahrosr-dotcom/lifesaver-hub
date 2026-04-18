<?php
session_start();
require_once 'config.php';
$pdo = getDbConnection();
// Enhanced Blood Donation Health Questionnaire System
// Updated to work with new healthquestion table structure (removed StudentID and EventID)

// =======================================
// CONFIGURATION & SECURITY
// =======================================

class HealthQuestionnaireConfig {
    // Database configuration
    const DB_CONFIG = [
        'host' => 'localhost',
        'username' => 'u444867998_achrd',
        'password' => 'xhYphMn?2S',
        'database' => 'u444867998_achrd',
        'charset' => 'utf8mb4'
    ];
    
    // Email configuration
    const EMAIL_CONFIG = [
        'smtp_host' => 'smtp.gmail.com',
        'smtp_port' => 587,
        'smtp_username' => 'jennahrosr@gmail.com',
        'smtp_password' => 'ckgsjiiizwoitino', // Use environment variables in production
        'from_email' => 'jennahrosr@gmail.com',
        'from_name' => 'LifeSaver Hub - Health Assessment'
    ];
    
    // Application settings
    const APP_SETTINGS = [
        'session_timeout' => 3600, // 1 hour
        'max_file_size' => 10485760, // 10MB
        'allowed_file_types' => ['jpg', 'jpeg', 'png', 'pdf'],
        'email_timeout' => 30,
        'timezone' => 'Asia/Kuala_Lumpur' // Added timezone setting for Malaysia
    ];
}

// =======================================
// ENHANCED ERROR HANDLING
// =======================================

class HealthQuestionnaireException extends Exception {
    private $errorCode;
    private $context;
    
    public function __construct($message, $errorCode = 0, $context = [], Exception $previous = null) {
        parent::__construct($message, 0, $previous);
        $this->errorCode = $errorCode;
        $this->context = $context;
    }
    
    public function getErrorCode() {
        return $this->errorCode;
    }
    
    public function getContext() {
        return $this->context;
    }
    
    public function logError() {
        $logMessage = sprintf(
            "[%s] Error Code: %s | Message: %s | Context: %s | File: %s | Line: %d",
            date('Y-m-d H:i:s'),
            $this->errorCode,
            $this->getMessage(),
            json_encode($this->context),
            $this->getFile(),
            $this->getLine()
        );
        error_log($logMessage);
    }
}

// =======================================
// DATE UTILITY CLASS
// =======================================

class DateUtility {
    public static function getCurrentDateTime($format = 'Y-m-d H:i:s') {
        // Set timezone
        date_default_timezone_set(HealthQuestionnaireConfig::APP_SETTINGS['timezone']);
        return date($format);
    }
    
    public static function getCurrentDate($format = 'Y-m-d') {
        // Set timezone
        date_default_timezone_set(HealthQuestionnaireConfig::APP_SETTINGS['timezone']);
        return date($format);
    }
    
    public static function formatDate($dateString, $format = 'l, d F Y') {
        if (empty($dateString)) return 'Date TBD';
        return date($format, strtotime($dateString));
    }
    
    public static function formatDateTime($dateTimeString, $format = 'd F Y, H:i A') {
        if (empty($dateTimeString)) return 'Date TBD';
        return date($format, strtotime($dateTimeString));
    }
}

// =======================================
// DATABASE CONNECTION CLASS
// =======================================

class DatabaseConnection {
    private static $instance = null;
    private $pdo;
    
    private function __construct() {
        try {
            $config = HealthQuestionnaireConfig::DB_CONFIG;
            $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}";
            
            $this->pdo = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false
            ]);
            
            // Set timezone for MySQL connection
            $this->pdo->exec("SET time_zone = '+08:00'"); // Malaysia timezone
        } catch (PDOException $e) {
            throw new HealthQuestionnaireException(
                "Database connection failed", 
                'DB_CONNECTION_ERROR', 
                ['pdo_error' => $e->getMessage()]
            );
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->pdo;
    }
}

// =======================================
// ENHANCED EMAIL SERVICE CLASS
// =======================================

class EmailService {
    private $config;
    private $mailer;
    
    public function __construct() {
        $this->config = HealthQuestionnaireConfig::EMAIL_CONFIG;
        $this->initializeMailer();
    }
    
    private function initializeMailer() {
        // Check for PHPMailer availability
        $phpmailerPaths = [
            __DIR__ . '/PHPMailer/src/PHPMailer.php',
            __DIR__ . '/vendor/phpmailer/phpmailer/src/PHPMailer.php'
        ];
        
        foreach ($phpmailerPaths as $path) {
            if (file_exists($path)) {
                require_once $path;
                require_once dirname($path) . '/SMTP.php';
                require_once dirname($path) . '/Exception.php';
                
                $this->mailer = new PHPMailer\PHPMailer\PHPMailer(true);
                $this->configureSMTP();
                return;
            }
        }
        
        // PHPMailer not found, will use fallback
        $this->mailer = null;
        error_log("PHPMailer not found - will use PHP mail() fallback");
    }
    
    private function configureSMTP() {
        if (!$this->mailer) return;
        
        $this->mailer->isSMTP();
        $this->mailer->Host = $this->config['smtp_host'];
        $this->mailer->SMTPAuth = true;
        $this->mailer->Username = $this->config['smtp_username'];
        $this->mailer->Password = $this->config['smtp_password'];
        $this->mailer->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $this->mailer->Port = $this->config['smtp_port'];
        $this->mailer->Timeout = HealthQuestionnaireConfig::APP_SETTINGS['email_timeout'];
        
        // Enhanced SSL options for XAMPP compatibility
        $this->mailer->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ];
    }
    
    public function sendEligibilityNotification($studentData, $eventData, $registrationId, $healthId) {
        try {
            if ($this->mailer) {
                return $this->sendViaPHPMailer($studentData, $eventData, $registrationId, $healthId);
            } else {
                return $this->sendViaMailFunction($studentData, $eventData, $registrationId, $healthId);
            }
        } catch (Exception $e) {
            throw new HealthQuestionnaireException(
                "Failed to send eligibility notification",
                'EMAIL_SEND_ERROR',
                [
                    'student_email' => $studentData['StudentEmail'],
                    'registration_id' => $registrationId,
                    'error' => $e->getMessage()
                ]
            );
        }
    }
    
    private function sendViaPHPMailer($studentData, $eventData, $registrationId, $healthId) {
        $this->mailer->clearAddresses();
        $this->mailer->clearAttachments();
        
        $this->mailer->setFrom($this->config['from_email'], $this->config['from_name']);
        $this->mailer->addAddress($studentData['StudentEmail'], $studentData['StudentName']);
        $this->mailer->addReplyTo($this->config['from_email'], 'LifeSaver Support');
        
        $this->mailer->isHTML(true);
        $this->mailer->CharSet = 'UTF-8';
        $this->mailer->Subject = "🩺 Congratulations! You're Eligible to Donate Blood - LifeSaver Hub";
        
        $this->mailer->Body = $this->generateHTMLContent($studentData, $eventData, $registrationId, $healthId);
        $this->mailer->AltBody = $this->generatePlainContent($studentData, $eventData, $registrationId, $healthId);
        
        $result = $this->mailer->send();
        
        if ($result) {
            error_log("✅ Email sent successfully via PHPMailer to: " . $studentData['StudentEmail']);
            return true;
        }
        
        return false;
    }
    
    private function sendViaMailFunction($studentData, $eventData, $registrationId, $healthId) {
        $to = $studentData['StudentEmail'];
        $subject = "🩺 Congratulations! You're Eligible to Donate Blood - LifeSaver Hub";
        $message = $this->generateHTMLContent($studentData, $eventData, $registrationId, $healthId);
        
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: ' . $this->config['from_name'] . ' <' . $this->config['from_email'] . '>',
            'Reply-To: ' . $this->config['from_email'],
            'X-Mailer: PHP/' . phpversion()
        ];
        
        $result = mail($to, $subject, $message, implode("\r\n", $headers));
        
        if ($result) {
            error_log("✅ Email sent successfully via mail() to: " . $to);
            return true;
        }
        
        error_log("❌ Failed to send email via mail() to: " . $to);
        return false;
    }
    
    private function generateHTMLContent($studentData, $eventData, $registrationId, $healthId) {
        $studentName = htmlspecialchars($studentData['StudentName']);
        $eventTitle = htmlspecialchars($eventData['EventTitle'] ?? 'Blood Donation Event');
        $eventDate = DateUtility::formatDate($eventData['EventDate']);
        $eventVenue = htmlspecialchars($eventData['EventVenue'] ?? 'Venue TBD');
        $currentDate = DateUtility::getCurrentDateTime('d F Y, H:i A');
        
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <style>
                body { 
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
                    line-height: 1.6; 
                    color: #333; 
                    margin: 0; 
                    padding: 20px; 
                    background-color: #f5f5f5;
                }
                .container { 
                    max-width: 600px; 
                    margin: 0 auto; 
                    background: #ffffff; 
                    border-radius: 15px;
                    overflow: hidden;
                    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
                }
                .header { 
                    background: linear-gradient(135deg, #10b981, #059669); 
                    color: white; 
                    padding: 40px 30px; 
                    text-align: center; 
                }
                .content { padding: 40px 30px; }
                .success-badge { 
                    background: linear-gradient(135deg, #d4f6db, #a7f3d0); 
                    border: 2px solid #10b981;
                    color: #065f46;
                    padding: 25px; 
                    text-align: center; 
                    font-size: 18px; 
                    font-weight: bold; 
                    margin: 30px 0; 
                    border-radius: 10px;
                }
                .info-section {
                    background: #f8f9fa;
                    padding: 20px;
                    border-radius: 8px;
                    margin: 20px 0;
                }
                .date-info {
                    font-size: 12px;
                    color: #666;
                    text-align: center;
                    margin-top: 20px;
                    padding-top: 20px;
                    border-top: 1px solid #eee;
                }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <div style='font-size: 60px; margin-bottom: 20px;'>🩺</div>
                    <h1>HEALTH ASSESSMENT COMPLETE</h1>
                    <h2>You're Eligible to Donate Blood!</h2>
                </div>
                
                <div class='content'>
                    <p>Dear <strong>$studentName</strong>,</p>
                    
                    <div class='success-badge'>
                        🎉 Congratulations! Your health assessment indicates you are ELIGIBLE to donate blood!
                    </div>
                    
                    <div class='info-section'>
                        <p><strong>Registration ID:</strong> $registrationId</p>
                        <p><strong>Health Assessment ID:</strong> $healthId</p>
                        <p><strong>Event:</strong> $eventTitle</p>
                        <p><strong>Date:</strong> $eventDate</p>
                        <p><strong>Venue:</strong> $eventVenue</p>
                    </div>
                    
                    <p>Thank you for your commitment to helping save lives!</p>
                    
                    <div class='date-info'>
                        Assessment completed on: $currentDate (Malaysia Time)
                    </div>
                </div>
            </div>
        </body>
        </html>";
    }
    
    private function generatePlainContent($studentData, $eventData, $registrationId, $healthId) {
        $studentName = $studentData['StudentName'];
        $eventTitle = $eventData['EventTitle'] ?? 'Blood Donation Event';
        $eventDate = DateUtility::formatDate($eventData['EventDate']);
        $currentDate = DateUtility::getCurrentDateTime('d F Y, H:i A');
        
        return "
HEALTH ASSESSMENT COMPLETE - YOU'RE ELIGIBLE TO DONATE BLOOD!

Dear $studentName,

Congratulations! Your health assessment indicates you are ELIGIBLE to donate blood.

Registration ID: $registrationId
Health Assessment ID: $healthId
Event: $eventTitle
Date: $eventDate

Thank you for your commitment to helping save lives!

Assessment completed on: $currentDate (Malaysia Time)

LifeSaver Hub - Blood Donation Management System
";
    }
}

// =======================================
// ENHANCED ELIGIBILITY CHECKER CLASS
// =======================================

class EligibilityChecker {
    private $responses;
    private $healthConditions;
    private $disqualifications = [];
    
    public function __construct($responses, $healthConditions) {
        $this->responses = $responses;
        $this->healthConditions = $healthConditions;
    }
    
    public function checkEligibility() {
        $this->checkBasicHealth();
        $this->checkRecentIllness();
        $this->checkHealthConditions();
        $this->checkRecentProcedures();
        $this->checkHighRiskBehaviors();
        $this->checkFemaleSpecific();
        
        return [
            'eligible' => empty($this->disqualifications),
            'disqualifications' => $this->disqualifications
        ];
    }
    
    private function checkBasicHealth() {
        if ($this->responses['q1'] === 'No') {
            $this->disqualifications[] = "Not feeling well today";
        }
        
        if ($this->responses['q2'] === 'Yes') {
            $this->disqualifications[] = "Blood donation is not for testing purposes";
        }
    }
    
    private function checkRecentIllness() {
        $recentChecks = [
            'q4a' => 'Recent medication',
            'q4b' => 'Recent fever/cold/cough',
            'q4c' => 'Recent headaches',
            'q4d' => 'Recent medical consultation'
        ];
        
        foreach ($recentChecks as $question => $reason) {
            if ($this->responses[$question] === 'Yes') {
                $this->disqualifications[] = $reason;
            }
        }
    }
    
    private function checkHealthConditions() {
        $criticalConditions = [
            'HIV' => 'HIV infection',
            'Hepatitis B or Hepatitis C' => 'Hepatitis infection',
            'Sexually Transmitted Disease / Syphilis' => 'STD/Syphilis'
        ];
        
        foreach ($this->healthConditions as $condition) {
            if (isset($criticalConditions[$condition])) {
                $this->disqualifications[] = $criticalConditions[$condition];
            }
        }
    }
    
    private function checkRecentProcedures() {
        $procedures = [
            'q8' => 'Recent injections',
            'q9' => 'Recent dental work',
            'q10' => 'Recent tattoo/piercing',
            'q11' => 'Recent alcohol consumption'
        ];
        
        foreach ($procedures as $question => $reason) {
            if ($this->responses[$question] === 'Yes') {
                $this->disqualifications[] = $reason;
            }
        }
    }
    
    private function checkHighRiskBehaviors() {
        $riskBehaviors = [
            'q14a' => 'Male-to-male sexual contact',
            'q14b' => 'Contact with sex workers',
            'q14c' => 'Commercial sex',
            'q14f' => 'Illegal drug injection',
            'q14h' => 'HIV positive test',
            'q14i' => 'Suspected HIV'
        ];
        
        foreach ($riskBehaviors as $question => $reason) {
            if ($this->responses[$question] === 'Yes') {
                $this->disqualifications[] = $reason;
            }
        }
    }
    
    private function checkFemaleSpecific() {
        $femaleChecks = [
            'q15a' => 'Currently menstruating',
            'q15b' => 'Pregnancy',
            'q15c' => 'Breastfeeding',
            'q15d' => 'Recent childbirth'
        ];
        
        foreach ($femaleChecks as $question => $reason) {
            if (isset($this->responses[$question]) && $this->responses[$question] === 'Yes') {
                $this->disqualifications[] = $reason;
            }
        }
    }
}

// =======================================
// UPDATED REGISTRATION SERVICE CLASS
// =======================================

class RegistrationService {
    private $db;
    private $emailService;
    
    public function __construct() {
        $this->db = DatabaseConnection::getInstance()->getConnection();
        $this->emailService = new EmailService();
    }
    
    public function registerStudentWithNotification($eventId, $responses, $healthConditions, $eligibilityResult) {
        try {
            $this->db->beginTransaction();
            
            $studentId = $_SESSION['student_id'];
            
            // Validate inputs
            if (empty($eventId) || empty($studentId)) {
                throw new HealthQuestionnaireException(
                    "Event ID and Student ID are required",
                    'INVALID_INPUT'
                );
            }
            
            // Get student and event data using JOIN
            $studentAndEventData = $this->getStudentAndEventDataWithJoin($studentId, $eventId);
            $studentData = $studentAndEventData['student'];
            $eventData = $studentAndEventData['event'];
            
            // Check for existing registration
            $registrationId = $this->getOrCreateRegistration($studentId, $eventId);
            
            // Create health record with new structure (only HealthDate, HealthStatus, RegistrationID)
            $healthId = $this->createHealthRecord($eligibilityResult, $registrationId, $eventData);
            
            $this->db->commit();
            
            // Send email notification for eligible students
            $emailSent = false;
            if ($eligibilityResult['eligible'] && !empty($studentData['StudentEmail'])) {
                try {
                    $emailSent = $this->emailService->sendEligibilityNotification(
                        $studentData, 
                        $eventData, 
                        $registrationId, 
                        $healthId
                    );
                } catch (HealthQuestionnaireException $e) {
                    $e->logError();
                    // Don't fail the registration if email fails
                }
            }
            
            return [
                'success' => true,
                'registration_id' => $registrationId,
                'health_id' => $healthId,
                'email_sent' => $emailSent
            ];
            
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            
            if ($e instanceof HealthQuestionnaireException) {
                $e->logError();
                throw $e;
            }
            
            throw new HealthQuestionnaireException(
                "Registration failed: " . $e->getMessage(),
                'REGISTRATION_ERROR',
                ['original_error' => $e->getMessage()]
            );
        }
    }
    
    // NEW METHOD: Get student and event data using JOIN through registration table
    private function getStudentAndEventDataWithJoin($studentId, $eventId) {
        // Get student data
        $stmt = $this->db->prepare("SELECT * FROM student WHERE StudentID = ?");
        $stmt->execute([$studentId]);
        $student = $stmt->fetch();
        
        if (!$student) {
            throw new HealthQuestionnaireException(
                "Student not found",
                'STUDENT_NOT_FOUND',
                ['student_id' => $studentId]
            );
        }
        
        // Get event data
        $stmt = $this->db->prepare("SELECT * FROM event WHERE EventID = ?");
        $stmt->execute([$eventId]);
        $event = $stmt->fetch();
        
        if (!$event) {
            throw new HealthQuestionnaireException(
                "Event not found",
                'EVENT_NOT_FOUND',
                ['event_id' => $eventId]
            );
        }
        
        return [
            'student' => $student,
            'event' => $event
        ];
    }
    
    // UPDATED METHOD: Get existing registration or create new one with current date
    private function getOrCreateRegistration($studentId, $eventId) {
        // Check for existing registration
        $stmt = $this->db->prepare(
            "SELECT RegistrationID FROM registration 
             WHERE StudentID = ? AND EventID = ? AND RegistrationStatus != 'Cancelled'"
        );
        $stmt->execute([$studentId, $eventId]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            // Return existing registration ID
            return $existing['RegistrationID'];
        }
        
        // Create new registration with current date/time
        $currentDateTime = DateUtility::getCurrentDateTime();
        $stmt = $this->db->prepare(
            "INSERT INTO registration (StudentID, EventID, RegistrationDate, RegistrationStatus, AttendanceStatus) 
             VALUES (?, ?, ?, 'Registered', 'Pending')"
        );
        $stmt->execute([$studentId, $eventId, $currentDateTime]);
        
        return $this->db->lastInsertId();
    }
    
    // UPDATED METHOD: Create health record with new structure and current date
    private function createHealthRecord($eligibilityResult, $registrationId, $eventData) {
        $healthStatus = $eligibilityResult['eligible'] ? 'Eligible' : 'Not Eligible';
        
        // Use current date if event date is not available or in the past
        $healthDate = $eventData['EventDate'];
        if (empty($healthDate) || strtotime($healthDate) < strtotime(DateUtility::getCurrentDate())) {
            $healthDate = DateUtility::getCurrentDate();
        }
        
        // NEW STRUCTURE: Only insert HealthDate, HealthStatus, and RegistrationID
        $stmt = $this->db->prepare(
            "INSERT INTO healthquestion (HealthDate, HealthStatus, RegistrationID) 
             VALUES (?, ?, ?)"
        );
        $stmt->execute([$healthDate, $healthStatus, $registrationId]);
        
        return $this->db->lastInsertId();
    }
    
    // NEW METHOD: Get health records with student and event info using JOIN
    public function getHealthRecordsWithDetails($registrationId = null) {
        $sql = "
            SELECT 
                h.HealthID,
                h.HealthDate,
                h.HealthStatus,
                h.RegistrationID,
                r.StudentID,
                r.EventID,
                r.RegistrationDate,
                r.RegistrationStatus,
                s.StudentName,
                s.StudentEmail,
                e.EventTitle,
                e.EventDate,
                e.EventVenue
            FROM healthquestion h
            INNER JOIN registration r ON h.RegistrationID = r.RegistrationID
            INNER JOIN student s ON r.StudentID = s.StudentID  
            INNER JOIN event e ON r.EventID = e.EventID
        ";
        
        if ($registrationId) {
            $sql .= " WHERE h.RegistrationID = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$registrationId]);
            return $stmt->fetch();
        } else {
            $sql .= " ORDER BY h.HealthDate DESC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll();
        }
    }
}

// =======================================
// ENHANCED INPUT VALIDATOR CLASS
// =======================================

class InputValidator {
    private $errors = [];
    
    public function validateHealthResponses($responses) {
        $required = [
            'q1', 'q2', 'q3', 'q4a', 'q4b', 'q4c', 'q4d', 'q6', 'q8', 'q9', 'q10', 'q11',
            'q12a', 'q12b', 'q12c', 'q12d', 'q13a', 'q13b', 'q13c',
            'q14a', 'q14b', 'q14c', 'q14d', 'q14e', 'q14f', 'q14g', 'q14h', 'q14i'
        ];
        
        foreach ($required as $question) {
            if (empty($responses[$question])) {
                $this->errors[] = "Please answer question $question";
            } elseif (!in_array($responses[$question], ['Yes', 'No'])) {
                $this->errors[] = "Invalid response for question $question";
            }
        }
        
        return empty($this->errors);
    }
    
    public function validateHealthConditions($healthConditions) {
        if (!is_array($healthConditions)) {
            $this->errors[] = "Health conditions must be an array";
            return false;
        }
        
        $validConditions = [
            'Jaundice', 'Hepatitis B or Hepatitis C', 'HIV', 
            'Sexually Transmitted Disease / Syphilis', 'Malaria', 
            'Diabetes', 'High Blood Pressure', 'Heart Disease'
        ];
        
        foreach ($healthConditions as $condition) {
            if (!in_array($condition, $validConditions)) {
                $this->errors[] = "Invalid health condition: $condition";
            }
        }
        
        return empty($this->errors);
    }
    
    public function getErrors() {
        return $this->errors;
    }
    
    public function hasErrors() {
        return !empty($this->errors);
    }
    
    public function clearErrors() {
        $this->errors = [];
    }
}

// =======================================
// ENHANCED SESSION MANAGER
// =======================================

class SessionManager {
    public static function initialize() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Check session timeout
        $timeout = HealthQuestionnaireConfig::APP_SETTINGS['session_timeout'];
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
            self::destroy();
            header("Location: student_login.php?timeout=1");
            exit;
        }
        
        $_SESSION['last_activity'] = time();
    }
    
    public static function requireLogin() {
        self::initialize();
        
        if (!isset($_SESSION['student_id'])) {
            header("Location: student_login.php");
            exit;
        }
    }
    
    public static function destroy() {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
    
    public static function getStudentId() {
        return $_SESSION['student_id'] ?? null;
    }
}

// =======================================
// MAIN APPLICATION LOGIC
// =======================================

try {
    // Initialize session and security
    SessionManager::requireLogin();
    
    // Set error reporting based on environment
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        error_reporting(E_ALL);
        ini_set('display_errors', 1);
    } else {
        error_reporting(0);
        ini_set('display_errors', 0);
    }
    
    // Initialize variables
    $eventId = $_GET['event_id'] ?? $_POST['event_id'] ?? null;
    $errors = [];
    $successMessage = '';
    $eligibilityResult = null;
    
    // Process form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Collect and validate responses
        $validator = new InputValidator();
        $responses = [];
        $healthConditions = $_POST['health_conditions'] ?? [];
        
        // Collect all responses
        $questionFields = [
            'q1', 'q2', 'q3', 'q4a', 'q4b', 'q4c', 'q4d', 'q6', 'q8', 'q9', 'q10', 'q11',
            'q12a', 'q12b', 'q12c', 'q12d', 'q13a', 'q13b', 'q13c',
            'q14a', 'q14b', 'q14c', 'q14d', 'q14e', 'q14f', 'q14g', 'q14h', 'q14i',
            'q15a', 'q15b', 'q15c', 'q15d' // Optional female questions
        ];
        
        foreach ($questionFields as $field) {
            $responses[$field] = $_POST[$field] ?? '';
        }
        
        // Validate input
        if ($validator->validateHealthResponses($responses) && 
            $validator->validateHealthConditions($healthConditions)) {
            
            // Check eligibility
            $eligibilityChecker = new EligibilityChecker($responses, $healthConditions);
            $eligibilityResult = $eligibilityChecker->checkEligibility();
            
            // Register if we have an event ID (registration not required for just checking eligibility)
            if (!empty($eventId)) {
                $registrationService = new RegistrationService();
                $registrationResult = $registrationService->registerStudentWithNotification(
                    $eventId, 
                    $responses, 
                    $healthConditions, 
                    $eligibilityResult
                );
                
                if ($registrationResult['success']) {
                    $currentDate = DateUtility::getCurrentDateTime('d F Y, H:i A');
                    $successMessage = sprintf(
                        "Health assessment completed on %s! Registration ID: %s | Health ID: %s%s",
                        $currentDate,
                        $registrationResult['registration_id'],
                        $registrationResult['health_id'],
                        $registrationResult['email_sent'] ? " | Email sent!" : ""
                    );
                }
            } else {
                // Just show eligibility result without registration
                $currentDate = DateUtility::getCurrentDateTime('d F Y, H:i A');
                $successMessage = "Health assessment completed on $currentDate! " . 
                    ($eligibilityResult['eligible'] ? "You are eligible to donate blood." : "You are currently not eligible to donate blood.");
            }
        } else {
            $errors = $validator->getErrors();
        }
    }
    
    // Get student data for display
    $db = DatabaseConnection::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT * FROM student WHERE StudentID = ?");
    $stmt->execute([SessionManager::getStudentId()]);
    $student = $stmt->fetch();
    
    // Get event details if provided
    $eventDetails = null;
    if ($eventId) {
        $stmt = $db->prepare("SELECT * FROM event WHERE EventID = ?");
        $stmt->execute([$eventId]);
        $eventDetails = $stmt->fetch();
    }
    
} catch (HealthQuestionnaireException $e) {
    $e->logError();
    $errors[] = "System error: " . $e->getMessage();
} catch (Exception $e) {
    $error = new HealthQuestionnaireException(
        "Unexpected error: " . $e->getMessage(),
        'SYSTEM_ERROR'
    );
    $error->logError();
    $errors[] = "A system error occurred. Please try again later.";
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enhanced Health Questionnaire - LifeSaver Hub</title>
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

        /* Animated background elements - Match Dashboard */
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

        /* Enhanced Sidebar - Consistent with notifications.php */
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

        .sidebar-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            transform: rotate(-45deg);
            animation: shimmer 3s infinite;
        }

        @keyframes shimmer {
            0% { transform: translateX(-100%) translateY(-100%) rotate(-45deg); }
            100% { transform: translateX(100%) translateY(100%) rotate(-45deg); }
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

        /* Logo image styling */
        .logo img {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            object-fit: cover;
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.3);
            transition: all 0.3s ease;
            border: 2px solid rgba(255, 255, 255, 0.9);
        }

        .logo:hover img {
            transform: scale(1.1) rotate(5deg);
            box-shadow: 0 8px 30px rgba(102, 126, 234, 0.4);
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

        .nav-section:last-child {
            margin-bottom: 0;
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

        .nav-section-title::after {
            content: '';
            position: absolute;
            bottom: 4px;
            left: 24px;
            width: 30px;
            height: 2px;
            background: linear-gradient(90deg, #667eea, #764ba2);
            border-radius: 1px;
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

        .nav-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(139, 92, 246, 0.1));
            border-radius: 12px;
            opacity: 0;
            transition: all 0.3s ease;
            transform: translateX(-100%);
        }

        .nav-item::after {
            content: '';
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 0;
            height: 2px;
            background: linear-gradient(90deg, #667eea, #764ba2);
            transition: width 0.3s ease;
            border-radius: 1px;
        }

        .nav-item:hover::before, .nav-item.active::before {
            opacity: 1;
            transform: translateX(0);
        }

        .nav-item:hover::after, .nav-item.active::after {
            width: 24px;
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
            position: relative;
            z-index: 1;
            transition: all 0.3s ease;
        }

        .nav-item:hover i, .nav-item.active i {
            color: #667eea;
            transform: scale(1.1);
        }

        .nav-item span {
            position: relative;
            z-index: 1;
            font-size: 14px;
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
            transition: all 0.3s ease;
        }

        .user-info:hover {
            background: rgba(255, 255, 255, 0.95);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.15);
            transform: translateY(-2px);
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
            transition: all 0.3s ease;
            border: 2px solid rgba(255, 255, 255, 0.9);
        }

        .user-info:hover .user-avatar {
            transform: scale(1.05);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
        }

        .user-details h4 {
            font-weight: 700;
            margin-bottom: 2px;
            color: #1a202c;
            font-size: 14px;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        }

        .user-details p {
            font-size: 11px;
            color: #4a5568;
            font-weight: 500;
            opacity: 0.8;
        }

        /* Enhanced Main Content */
        .main-content {
            flex: 1;
            margin-left: 320px;
            padding: 32px;
            background: rgba(248, 249, 250, 0.3);
        }

        .questionnaire-container {
            max-width: 900px;
            margin: 0 auto;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }

        .questionnaire-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 40px;
            text-align: center;
        }

        .questionnaire-header h1 {
            font-size: 2.5rem;
            font-weight: 900;
            margin-bottom: 16px;
        }

        .questionnaire-header p {
            font-size: 18px;
            opacity: 0.9;
        }

        /* Enhanced Status Cards */
        .status-card {
            border-radius: 16px;
            padding: 24px;
            margin: 32px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            position: relative;
            overflow: hidden;
        }

        .status-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea, #764ba2);
        }

        .status-card.success {
            background: linear-gradient(135deg, #d4f6db, #a7f3d0);
            border: 2px solid #10b981;
        }

        .status-card.error {
            background: linear-gradient(135deg, #fed7d7, #feb2b2);
            border: 2px solid #f56565;
        }

        .status-card.info {
            background: linear-gradient(135deg, #e7f3ff, #f0f8ff);
            border: 2px solid #667eea;
        }

        .status-card.warning {
            background: linear-gradient(135deg, #fff3cd, #ffeaa7);
            border: 2px solid #ffc107;
        }

        .status-card h4 {
            margin-bottom: 16px;
            font-size: 18px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-top: 16px;
        }

        .info-item {
            background: rgba(255, 255, 255, 0.8);
            padding: 12px 16px;
            border-radius: 8px;
            border: 1px solid rgba(102, 126, 234, 0.1);
        }

        .info-label {
            font-size: 12px;
            color: #667eea;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 4px;
        }

        .info-value {
            font-size: 14px;
            color: #2d3748;
            font-weight: 500;
        }

        /* Enhanced Form Styles */
        .form-content {
            padding: 40px;
        }

        .question-section {
            background: rgba(248, 249, 250, 0.8);
            border-radius: 16px;
            padding: 32px;
            margin-bottom: 32px;
            border: 1px solid rgba(102, 126, 234, 0.1);
            transition: all 0.3s ease;
        }

        .question-section:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 35px rgba(102, 126, 234, 0.15);
        }

        .question-section h3 {
            color: #2d3748;
            margin-bottom: 24px;
            font-size: 20px;
            font-weight: 700;
            border-bottom: 3px solid #667eea;
            padding-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .question {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid rgba(0, 0, 0, 0.05);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }

        .question:hover {
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.1);
        }

        .question label {
            font-weight: 600;
            color: #2d3748;
            display: block;
            margin-bottom: 12px;
            font-size: 16px;
            line-height: 1.5;
        }

        /* Enhanced Radio and Checkbox Styles */
        .radio-group, .checkbox-group {
            display: flex;
            gap: 24px;
            margin: 12px 0;
            flex-wrap: wrap;
        }

        .radio-group label, .checkbox-group label {
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            padding: 12px 20px;
            border-radius: 10px;
            transition: all 0.3s ease;
            border: 2px solid transparent;
            background: rgba(248, 249, 250, 0.8);
            min-width: 120px;
            justify-content: center;
        }

        .radio-group label:hover, .checkbox-group label:hover {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(139, 92, 246, 0.1));
            border-color: rgba(102, 126, 234, 0.3);
            transform: translateY(-2px);
        }

        .radio-group input[type="radio"], .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: #667eea;
        }

        /* Enhanced Health Conditions Grid */
        .health-conditions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 16px;
            margin: 20px 0;
        }

        .health-conditions label {
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            padding: 16px 20px;
            border-radius: 12px;
            transition: all 0.3s ease;
            border: 2px solid transparent;
            background: rgba(255, 255, 255, 0.9);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .health-conditions label:hover {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(139, 92, 246, 0.1));
            border-color: rgba(102, 126, 234, 0.3);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.15);
        }

        /* Enhanced Critical Section */
        .critical-section {
            background: linear-gradient(135deg, #fff5f5, #fed7d7);
            border: 3px solid #f56565;
            border-radius: 20px;
            padding: 32px;
            margin-bottom: 32px;
            position: relative;
            overflow: hidden;
        }

        .critical-section::before {
            content: '⚠️';
            position: absolute;
            top: -10px;
            right: -10px;
            font-size: 60px;
            opacity: 0.1;
            transform: rotate(15deg);
        }

        .critical-section h4 {
            color: #c53030;
            text-align: center;
            margin-bottom: 20px;
            font-size: 20px;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
        }

        /* Enhanced Submit Section */
        .submit-section {
            text-align: center;
            padding: 40px;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.05), rgba(139, 92, 246, 0.05));
            border-top: 1px solid rgba(102, 126, 234, 0.1);
            position: relative;
        }

        .btn-submit {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 18px 48px;
            border: none;
            border-radius: 16px;
            font-size: 18px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
            text-transform: uppercase;
            letter-spacing: 1px;
            position: relative;
            overflow: hidden;
        }

        .btn-submit::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }

        .btn-submit:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 35px rgba(102, 126, 234, 0.4);
        }

        .btn-submit:hover::before {
            left: 100%;
        }

        .btn-submit:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        /* Enhanced Result Section */
        .result-section {
            margin: 32px;
            padding: 40px;
            border-radius: 24px;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.1);
            position: relative;
            overflow: hidden;
        }

        .result-section.eligible {
            background: linear-gradient(135deg, #c6f6d5, #9ae6b4);
            border: 3px solid #38a169;
        }

        .result-section.not-eligible {
            background: linear-gradient(135deg, #fed7d7, #feb2b2);
            border: 3px solid #f56565;
        }

        .result-icon {
            font-size: 4rem;
            margin-bottom: 20px;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }

        .result-title {
            font-size: 2.2rem;
            font-weight: 900;
            margin-bottom: 16px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .issue-list {
            margin-top: 20px;
            text-align: left;
            max-width: 500px;
            margin-left: auto;
            margin-right: auto;
        }

        .issue-item {
            background: rgba(255, 255, 255, 0.9);
            padding: 12px 16px;
            margin: 8px 0;
            border-radius: 8px;
            border-left: 4px solid #f56565;
            font-weight: 500;
            color: #742a2a;
        }

        /* Enhanced Mobile Responsiveness */
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
            backdrop-filter: blur(10px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }

        /* Enhanced Loading States */
        .loading {
            opacity: 0.7;
            pointer-events: none;
        }

        .loading::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 32px;
            height: 32px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            transform: translate(-50%, -50%);
        }

        @keyframes spin {
            0% { transform: translate(-50%, -50%) rotate(0deg); }
            100% { transform: translate(-50%, -50%) rotate(360deg); }
        }

        /* Enhanced Responsive Design */
        @media (max-width: 968px) {
            .mobile-menu-btn {
                display: block;
            }
            
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.open {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
                padding: 24px 16px;
            }
            
            .health-conditions {
                grid-template-columns: 1fr;
            }
            
            .radio-group, .checkbox-group {
                flex-direction: column;
                gap: 12px;
            }

            .questionnaire-header h1 {
                font-size: 2rem;
            }

            .result-title {
                font-size: 1.8rem;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Enhanced Accessibility */
        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        /* Focus styles for accessibility */
        .question input:focus,
        .btn-submit:focus {
            outline: 3px solid #667eea;
            outline-offset: 2px;
        }

        /* Enhanced Animation Classes */
        .fade-in {
            animation: fadeIn 0.8s ease-in;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .slide-in {
            animation: slideIn 0.6s ease-out;
        }

        @keyframes slideIn {
            from { transform: translateX(-30px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
    </style>
</head>
<body>
    <button class="mobile-menu-btn" onclick="toggleSidebar()" aria-label="Toggle navigation menu">
        <i class="fas fa-bars"></i>
    </button>

    <div class="app-container">
        <!-- Enhanced Sidebar - Consistent with Dashboard -->
        <nav class="sidebar" id="sidebar" role="navigation" aria-label="Main navigation">
            <div class="sidebar-header">
                <a href="student_dashboard.php" class="logo">
                    <img src="images/logo.jpg" alt="LifeSaver Hub Logo" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                    <div style="width: 48px; height: 48px; background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 14px; display: none; align-items: center; justify-content: center; color: white; font-weight: 800; font-size: 18px; box-shadow: 0 6px 20px rgba(102, 126, 234, 0.3); border: 2px solid rgba(255, 255, 255, 0.9);">L</div>
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
                        <a href="student_view_donation.php" class="nav-item">
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
                        </a>
                    </div>
                    
                    <div class="nav-section">
                        <div class="nav-section-title">Health</div>
                        <a href="health_questionnaire.php" class="nav-item active">
                            <i class="fas fa-heartbeat"></i>
                            <span>Health Questionnaire</span>
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
                        <p>ID: <?php echo htmlspecialchars(SessionManager::getStudentId()); ?></p>
                    </div>
                </div>
            </div>
        </nav>

        <main class="main-content" role="main">
            <div class="questionnaire-container fade-in">
                <header class="questionnaire-header">
                    <h1><i class="fas fa-heartbeat"></i> Enhanced Health Questionnaire</h1>
                    <p>Ministry of Health Malaysia - Blood Transfusion Service</p>
                </header>

                <?php if ($eventId && $eventDetails): ?>
                    <div class="status-card info slide-in">
                        <h4><i class="fas fa-calendar-alt"></i> Selected Event</h4>
                        <p><strong>Event:</strong> <?php echo htmlspecialchars($eventDetails['EventTitle'] ?? 'Unknown Event'); ?></p>
                        <p><strong>Date:</strong> <?php echo $eventDetails['EventDate'] ? date('d F Y', strtotime($eventDetails['EventDate'])) : 'Date TBD'; ?></p>
                        <p><strong>Location:</strong> <?php echo htmlspecialchars($eventDetails['EventVenue'] ?? 'Location TBD'); ?></p>
                    </div>
                <?php endif; ?>

                <!-- Enhanced Student Information Display -->
                <div class="status-card info">
                    <h4><i class="fas fa-user"></i> Your Information</h4>
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Name</div>
                            <div class="info-value"><?php echo htmlspecialchars($student['StudentName'] ?? 'Not Available'); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Email</div>
                            <div class="info-value"><?php echo htmlspecialchars($student['StudentEmail'] ?? 'Not Available'); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Phone</div>
                            <div class="info-value"><?php echo htmlspecialchars($student['StudentContact'] ?? 'Not Available'); ?></div>
                        </div>
                    </div>
                </div>

                <!-- Enhanced Status Messages -->
                <?php if (!empty($successMessage)): ?>
                    <div class="status-card success slide-in">
                        <h4><i class="fas fa-check-circle"></i> Assessment Complete!</h4>
                        <p><?php echo htmlspecialchars($successMessage); ?></p>
                    </div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div class="status-card error slide-in">
                        <h4><i class="fas fa-exclamation-triangle"></i> Please correct the following errors:</h4>
                        <ul>
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <!-- Enhanced Eligibility Results -->
                <?php if ($eligibilityResult !== null): ?>
                    <?php if ($eligibilityResult['eligible']): ?>
                        <div class="result-section eligible">
                            <div class="result-icon">✅</div>
                            <div class="result-title">ELIGIBLE TO DONATE</div>
                            <p>Congratulations! You are eligible to donate blood and help save lives.</p>
                            <?php if ($eventId): ?>
                                <p style="margin-top: 16px; font-weight: 600;">Your health record has been saved for this event.</p>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="result-section not-eligible">
                            <div class="result-icon">❌</div>
                            <div class="result-title">NOT ELIGIBLE AT THIS TIME</div>
                            <p>Based on your answers, you are currently not eligible to donate blood.</p>
                            <?php if (!empty($eligibilityResult['disqualifications'])): ?>
                                <div class="issue-list">
                                    <h5 style="margin-bottom: 12px; color: #742a2a;">Reasons for ineligibility:</h5>
                                    <?php foreach ($eligibilityResult['disqualifications'] as $reason): ?>
                                        <div class="issue-item">• <?php echo htmlspecialchars($reason); ?></div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>

                <!-- Enhanced Form with improved UX -->
                <form method="POST" class="form-content" id="healthForm" novalidate>
                    <?php if ($eventId): ?>
                        <input type="hidden" name="event_id" value="<?php echo htmlspecialchars($eventId); ?>">
                    <?php endif; ?>

                    <!-- Basic Health Questions -->
                    <div class="question-section slide-in">
                        <h3><i class="fas fa-user-md"></i> Basic Health Questions</h3>
                        
                        <div class="question">
                            <label for="q1">1. Do you feel healthy today?</label>
                            <div class="radio-group">
                                <label><input type="radio" name="q1" value="Yes" id="q1_yes" required> Yes</label>
                                <label><input type="radio" name="q1" value="No" id="q1_no" required> No</label>
                            </div>
                        </div>

                        <div class="question">
                            <label for="q2">2. Are you here to test your blood for HIV, Hepatitis or Syphilis?</label>
                            <div class="radio-group">
                                <label><input type="radio" name="q2" value="Yes" id="q2_yes" required> Yes</label>
                                <label><input type="radio" name="q2" value="No" id="q2_no" required> No</label>
                            </div>
                        </div>

                        <div class="question">
                            <label for="q3">3. Have you donated blood before?</label>
                            <div class="radio-group">
                                <label><input type="radio" name="q3" value="Yes" id="q3_yes" required> Yes</label>
                                <label><input type="radio" name="q3" value="No" id="q3_no" required> No</label>
                            </div>
                        </div>
                    </div>

                    <!-- Past Week Questions -->
                    <div class="question-section slide-in">
                        <h3><i class="fas fa-calendar-week"></i> Past Week - Have you:</h3>
                        
                        <div class="question">
                            <label for="q4a">4a. Taken any medicine?</label>
                            <div class="radio-group">
                                <label><input type="radio" name="q4a" value="Yes" id="q4a_yes" required> Yes</label>
                                <label><input type="radio" name="q4a" value="No" id="q4a_no" required> No</label>
                            </div>
                        </div>

                        <div class="question">
                            <label for="q4b">4b. Had fever, cold or cough?</label>
                            <div class="radio-group">
                                <label><input type="radio" name="q4b" value="Yes" id="q4b_yes" required> Yes</label>
                                <label><input type="radio" name="q4b" value="No" id="q4b_no" required> No</label>
                            </div>
                        </div>

                        <div class="question">
                            <label for="q4c">4c. Had headaches or migraines?</label>
                            <div class="radio-group">
                                <label><input type="radio" name="q4c" value="Yes" id="q4c_yes" required> Yes</label>
                                <label><input type="radio" name="q4c" value="No" id="q4c_no" required> No</label>
                            </div>
                        </div>

                        <div class="question">
                            <label for="q4d">4d. Seen a doctor for any health problem?</label>
                            <div class="radio-group">
                                <label><input type="radio" name="q4d" value="Yes" id="q4d_yes" required> Yes</label>
                                <label><input type="radio" name="q4d" value="No" id="q4d_no" required> No</label>
                            </div>
                        </div>
                    </div>

                    <!-- Health Conditions Section -->
                    <div class="question-section slide-in">
                        <h3><i class="fas fa-notes-medical"></i> Health Conditions</h3>
                        
                        <div class="question">
                            <label>5. Do you have or have you ever had any of these health problems?</label>
                            <p style="margin-bottom: 16px; color: #4a5568; font-size: 14px;">Check all that apply. If none, leave unchecked.</p>
                            <div class="health-conditions">
                                <label><input type="checkbox" name="health_conditions[]" value="Jaundice"> Jaundice</label>
                                <label><input type="checkbox" name="health_conditions[]" value="Hepatitis B or Hepatitis C"> Hepatitis B/C</label>
                                <label><input type="checkbox" name="health_conditions[]" value="HIV"> HIV</label>
                                <label><input type="checkbox" name="health_conditions[]" value="Sexually Transmitted Disease / Syphilis"> STD/Syphilis</label>
                                <label><input type="checkbox" name="health_conditions[]" value="Malaria"> Malaria</label>
                                <label><input type="checkbox" name="health_conditions[]" value="Diabetes"> Diabetes</label>
                                <label><input type="checkbox" name="health_conditions[]" value="High Blood Pressure"> High Blood Pressure</label>
                                <label><input type="checkbox" name="health_conditions[]" value="Heart Disease"> Heart Disease</label>
                            </div>
                        </div>

                        <div class="question">
                            <label for="q6">6. Does anyone in your family have Hepatitis B or C?</label>
                            <div class="radio-group">
                                <label><input type="radio" name="q6" value="Yes" id="q6_yes" required> Yes</label>
                                <label><input type="radio" name="q6" value="No" id="q6_no" required> No</label>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Activities Section -->
                    <div class="question-section slide-in">
                        <h3><i class="fas fa-clock"></i> Recent Activities</h3>
                        
                        <div class="question">
                            <label for="q8">8. Have you had any injections in the past 4 weeks?</label>
                            <div class="radio-group">
                                <label><input type="radio" name="q8" value="Yes" id="q8_yes" required> Yes</label>
                                <label><input type="radio" name="q8" value="No" id="q8_no" required> No</label>
                            </div>
                        </div>

                        <div class="question">
                            <label for="q9">9. Have you been to the dentist in the past 24 hours?</label>
                            <div class="radio-group">
                                <label><input type="radio" name="q9" value="Yes" id="q9_yes" required> Yes</label>
                                <label><input type="radio" name="q9" value="No" id="q9_no" required> No</label>
                            </div>
                        </div>

                        <div class="question">
                            <label for="q10">10. Have you had tattoo/piercing in the past 6 months?</label>
                            <div class="radio-group">
                                <label><input type="radio" name="q10" value="Yes" id="q10_yes" required> Yes</label>
                                <label><input type="radio" name="q10" value="No" id="q10_no" required> No</label>
                            </div>
                        </div>

                        <div class="question">
                            <label for="q11">11. Have you drunk alcohol in the past 24 hours?</label>
                            <div class="radio-group">
                                <label><input type="radio" name="q11" value="Yes" id="q11_yes" required> Yes</label>
                                <label><input type="radio" name="q11" value="No" id="q11_no" required> No</label>
                            </div>
                        </div>
                    </div>

                    <!-- Medical History Section -->
                    <div class="question-section slide-in">
                        <h3><i class="fas fa-procedures"></i> Medical History</h3>
                        
                        <div class="question">
                            <label for="q12a">12a. Growth hormone injections?</label>
                            <div class="radio-group">
                                <label><input type="radio" name="q12a" value="Yes" id="q12a_yes" required> Yes</label>
                                <label><input type="radio" name="q12a" value="No" id="q12a_no" required> No</label>
                            </div>
                        </div>

                        <div class="question">
                            <label for="q12b">12b. Cornea transplant?</label>
                            <div class="radio-group">
                                <label><input type="radio" name="q12b" value="Yes" id="q12b_yes" required> Yes</label>
                                <label><input type="radio" name="q12b" value="No" id="q12b_no" required> No</label>
                            </div>
                        </div>

                        <div class="question">
                            <label for="q12c">12c. Brain membrane transplant?</label>
                            <div class="radio-group">
                                <label><input type="radio" name="q12c" value="Yes" id="q12c_yes" required> Yes</label>
                                <label><input type="radio" name="q12c" value="No" id="q12c_no" required> No</label>
                            </div>
                        </div>

                        <div class="question">
                            <label for="q12d">12d. Bone marrow transplant?</label>
                            <div class="radio-group">
                                <label><input type="radio" name="q12d" value="Yes" id="q12d_yes" required> Yes</label>
                                <label><input type="radio" name="q12d" value="No" id="q12d_no" required> No</label>
                            </div>
                        </div>
                    </div>

                    <!-- Travel History Section -->
                    <div class="question-section slide-in">
                        <h3><i class="fas fa-globe"></i> Travel History</h3>
                        
                        <div class="question">
                            <label for="q13a">13a. Lived in UK/Ireland for 6+ months (1980-1996)?</label>
                            <div class="radio-group">
                                <label><input type="radio" name="q13a" value="Yes" id="q13a_yes" required> Yes</label>
                                <label><input type="radio" name="q13a" value="No" id="q13a_no" required> No</label>
                            </div>
                        </div>

                        <div class="question">
                            <label for="q13b">13b. Blood transfusion in UK (1980-now)?</label>
                            <div class="radio-group">
                                <label><input type="radio" name="q13b" value="Yes" id="q13b_yes" required> Yes</label>
                                <label><input type="radio" name="q13b" value="No" id="q13b_no" required> No</label>
                            </div>
                        </div>

                        <div class="question">
                            <label for="q13c">13c. Lived in Europe for 5+ years (1980-now)?</label>
                            <div class="radio-group">
                                <label><input type="radio" name="q13c" value="Yes" id="q13c_yes" required> Yes</label>
                                <label><input type="radio" name="q13c" value="No" id="q13c_no" required> No</label>
                            </div>
                        </div>
                    </div>

                    <!-- Critical Questions Section -->
                    <div class="critical-section">
                        <h4><i class="fas fa-exclamation-triangle"></i> CRITICAL QUESTIONS - Answer Honestly</h4>
                        <p style="text-align: center; margin-bottom: 20px; font-weight: 600;">These questions help keep blood safe. You must answer truthfully.</p>
                        
                        <div class="question">
                            <label for="q14a">14a. Male-to-male sexual contact?</label>
                            <div class="radio-group">
                                <label><input type="radio" name="q14a" value="Yes" id="q14a_yes" required> Yes</label>
                                <label><input type="radio" name="q14a" value="No" id="q14a_no" required> No</label>
                            </div>
                        </div>

                        <div class="question">
                            <label for="q14b">14b. Sex with sex workers?</label>
                            <div class="radio-group">
                                <label><input type="radio" name="q14b" value="Yes" id="q14b_yes" required> Yes</label>
                                <label><input type="radio" name="q14b" value="No" id="q14b_no" required> No</label>
                            </div>
                        </div>

                        <div class="question">
                            <label for="q14c">14c. Paid for sex or been paid for sex?</label>
                            <div class="radio-group">
                                <label><input type="radio" name="q14c" value="Yes" id="q14c_yes" required> Yes</label>
                                <label><input type="radio" name="q14c" value="No" id="q14c_no" required> No</label>
                            </div>
                        </div>

                        <div class="question">
                            <label for="q14d">14d. Multiple sexual partners?</label>
                            <div class="radio-group">
                                <label><input type="radio" name="q14d" value="Yes" id="q14d_yes" required> Yes</label>
                                <label><input type="radio" name="q14d" value="No" id="q14d_no" required> No</label>
                            </div>
                        </div>

                        <div class="question">
                            <label for="q14e">14e. New sexual partner in past year?</label>
                            <div class="radio-group">
                                <label><input type="radio" name="q14e" value="Yes" id="q14e_yes" required> Yes</label>
                                <label><input type="radio" name="q14e" value="No" id="q14e_no" required> No</label>
                            </div>
                        </div>

                        <div class="question">
                            <label for="q14f">14f. Injected illegal drugs?</label>
                            <div class="radio-group">
                                <label><input type="radio" name="q14f" value="Yes" id="q14f_yes" required> Yes</label>
                                <label><input type="radio" name="q14f" value="No" id="q14f_no" required> No</label>
                            </div>
                        </div>

                        <div class="question">
                            <label for="q14g">14g. Partner with high-risk behavior?</label>
                            <div class="radio-group">
                                <label><input type="radio" name="q14g" value="Yes" id="q14g_yes" required> Yes</label>
                                <label><input type="radio" name="q14g" value="No" id="q14g_no" required> No</label>
                            </div>
                        </div>

                        <div class="question">
                            <label for="q14h">14h. Positive HIV test?</label>
                            <div class="radio-group">
                                <label><input type="radio" name="q14h" value="Yes" id="q14h_yes" required> Yes</label>
                                <label><input type="radio" name="q14h" value="No" id="q14h_no" required> No</label>
                            </div>
                        </div>

                        <div class="question">
                            <label for="q14i">14i. Think you might have HIV?</label>
                            <div class="radio-group">
                                <label><input type="radio" name="q14i" value="Yes" id="q14i_yes" required> Yes</label>
                                <label><input type="radio" name="q14i" value="No" id="q14i_no" required> No</label>
                            </div>
                        </div>
                    </div>

                    <!-- Female-Specific Questions (Optional) -->
                    <div class="question-section slide-in">
                        <h3><i class="fas fa-venus"></i> For Women</h3>
                        
                        <div class="question">
                            <label for="q15a">15a. Having your period now?</label>
                            <div class="radio-group">
                                <label><input type="radio" name="q15a" value="Yes" id="q15a_yes"> Yes</label>
                                <label><input type="radio" name="q15a" value="No" id="q15a_no"> No</label>
                            </div>
                        </div>

                        <div class="question">
                            <label for="q15b">15b. Pregnant or might be pregnant?</label>
                            <div class="radio-group">
                                <label><input type="radio" name="q15b" value="Yes" id="q15b_yes"> Yes</label>
                                <label><input type="radio" name="q15b" value="No" id="q15b_no"> No</label>
                            </div>
                        </div>

                        <div class="question">
                            <label for="q15c">15c. Breastfeeding?</label>
                            <div class="radio-group">
                                <label><input type="radio" name="q15c" value="Yes" id="q15c_yes"> Yes</label>
                                <label><input type="radio" name="q15c" value="No" id="q15c_no"> No</label>
                            </div>
                        </div>

                        <div class="question">
                            <label for="q15d">15d. Given birth in past 6 months?</label>
                            <div class="radio-group">
                                <label><input type="radio" name="q15d" value="Yes" id="q15d_yes"> Yes</label>
                                <label><input type="radio" name="q15d" value="No" id="q15d_no"> No</label>
                            </div>
                        </div>
                    </div>

                    <!-- Enhanced Submit Section -->
                    <div class="submit-section">
                        <button type="submit" class="btn-submit" id="submitBtn">
                            <i class="fas fa-heartbeat"></i> Check My Eligibility
                        </button>
                        <p style="margin-top: 20px; color: #4a5568; font-size: 14px; font-weight: 500;">
                            All answers are confidential and used only for blood donation safety.<br>
                            <?php if ($eventId): ?>
                                If eligible, you'll be automatically registered for the selected event.
                            <?php else: ?>
                                You can check your eligibility without selecting an event.
                            <?php endif; ?>
                        </p>
                    </div>
                </form>

                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        // Enhanced JavaScript functionality
        class HealthQuestionnaireApp {
            constructor() {
                this.form = document.getElementById('healthForm');
                this.submitBtn = document.getElementById('submitBtn');
                this.init();
            }

            init() {
                this.setupEventListeners();
                this.setupFormValidation();
                this.setupAnimations();
                this.setupAccessibility();
            }

            setupEventListeners() {
                // Mobile menu toggle
                window.toggleSidebar = () => {
                    const sidebar = document.getElementById('sidebar');
                    sidebar.classList.toggle('open');
                };

                // Form submission
                if (this.form) {
                    this.form.addEventListener('submit', (e) => this.handleSubmit(e));
                }

                // Radio button interactions
                document.querySelectorAll('input[type="radio"], input[type="checkbox"]').forEach(input => {
                    input.addEventListener('change', () => this.handleInputChange(input));
                });

                // Close sidebar on outside click (mobile)
                document.addEventListener('click', (e) => this.handleOutsideClick(e));

                // Window resize handler
                window.addEventListener('resize', () => this.handleResize());
            }

            setupFormValidation() {
                if (!this.form) return;

                const inputs = this.form.querySelectorAll('input[required]');
                inputs.forEach(input => {
                    input.addEventListener('blur', () => this.validateInput(input));
                });
            }

            setupAnimations() {
                // Intersection Observer for animations
                const observer = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            entry.target.style.opacity = '1';
                            entry.target.style.transform = 'translateY(0)';
                        }
                    });
                });

                document.querySelectorAll('.question-section').forEach(section => {
                    section.style.opacity = '0';
                    section.style.transform = 'translateY(20px)';
                    section.style.transition = 'all 0.6s ease';
                    observer.observe(section);
                });
            }

            setupAccessibility() {
                // Enhanced keyboard navigation
                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape') {
                        const sidebar = document.getElementById('sidebar');
                        if (sidebar.classList.contains('open')) {
                            sidebar.classList.remove('open');
                        }
                    }
                });

                // Focus management
                document.querySelectorAll('.nav-item').forEach(item => {
                    item.addEventListener('focus', () => {
                        item.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    });
                });
            }

            handleSubmit(e) {
                if (!this.validateForm()) {
                    e.preventDefault();
                    return false;
                }

                // Show loading state
                this.submitBtn.disabled = true;
                this.submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing Assessment...';
                
                // Add loading class to form
                this.form.classList.add('loading');

                return true;
            }

            validateForm() {
                const required = this.form.querySelectorAll('input[required]');
                let isValid = true;
                const missing = [];

                required.forEach(input => {
                    if (input.type === 'radio') {
                        const name = input.name;
                        const checked = this.form.querySelector(`input[name="${name}"]:checked`);
                        if (!checked && !missing.includes(name)) {
                            missing.push(name);
                            isValid = false;
                        }
                    }
                });

                if (!isValid) {
                    this.showValidationError('Please answer all required questions: ' + missing.join(', '));
                }

                return isValid;
            }

            validateInput(input) {
                const question = input.closest('.question');
                let errorElement = question.querySelector('.validation-error');

                if (input.type === 'radio') {
                    const name = input.name;
                    const checked = this.form.querySelector(`input[name="${name}"]:checked`);
                    
                    if (!checked && input.hasAttribute('required')) {
                        if (!errorElement) {
                            errorElement = document.createElement('div');
                            errorElement.className = 'validation-error';
                            errorElement.style.cssText = `
                                color: #e53e3e;
                                font-size: 14px;
                                margin-top: 8px;
                                font-weight: 600;
                            `;
                            question.appendChild(errorElement);
                        }
                        errorElement.textContent = 'This question is required';
                        question.style.borderLeft = '4px solid #e53e3e';
                    } else {
                        if (errorElement) {
                            errorElement.remove();
                        }
                        question.style.borderLeft = '';
                    }
                }
            }

            handleInputChange(input) {
                const label = input.closest('label');
                const question = input.closest('.question');
                
                // Visual feedback for selection
                if (input.type === 'radio') {
                    // Clear other radio buttons in the same group
                    question.querySelectorAll(`input[name="${input.name}"]`).forEach(radio => {
                        const radioLabel = radio.closest('label');
                        radioLabel.style.background = '';
                        radioLabel.style.borderColor = '';
                        radioLabel.style.transform = '';
                    });
                }
                
                if (input.checked) {
                    label.style.background = 'linear-gradient(135deg, rgba(102, 126, 234, 0.2), rgba(139, 92, 246, 0.2))';
                    label.style.borderColor = '#667eea';
                    label.style.transform = 'scale(1.02)';
                    
                    // Add risk warning for high-risk answers
                    if (this.isHighRiskAnswer(input)) {
                        this.showRiskWarning(question);
                    }
                } else if (input.type === 'checkbox') {
                    label.style.background = '';
                    label.style.borderColor = '';
                    label.style.transform = '';
                }

                // Clear validation errors
                this.validateInput(input);
            }

            isHighRiskAnswer(input) {
                const riskQuestions = ['q14a', 'q14b', 'q14c', 'q14f', 'q14h', 'q14i'];
                return riskQuestions.includes(input.name) && input.value === 'Yes';
            }

            showRiskWarning(question) {
                let warning = question.querySelector('.risk-warning');
                if (!warning) {
                    warning = document.createElement('div');
                    warning.className = 'risk-warning';
                    warning.style.cssText = `
                        background: linear-gradient(135deg, #fed7d7, #feb2b2);
                        color: #742a2a;
                        padding: 12px 16px;
                        margin-top: 12px;
                        border-radius: 8px;
                        border: 1px solid #f56565;
                        font-weight: 600;
                        font-size: 14px;
                        animation: slideIn 0.3s ease;
                    `;
                    warning.innerHTML = '<i class="fas fa-exclamation-triangle"></i> This answer may affect your eligibility.';
                    question.appendChild(warning);
                }
            }

            showValidationError(message) {
                // Create or update validation alert
                let alert = document.querySelector('.validation-alert');
                if (!alert) {
                    alert = document.createElement('div');
                    alert.className = 'validation-alert status-card error';
                    alert.style.cssText = `
                        position: fixed;
                        top: 20px;
                        right: 20px;
                        max-width: 400px;
                        z-index: 1002;
                        animation: slideIn 0.3s ease;
                    `;
                    document.body.appendChild(alert);
                }
                
                alert.innerHTML = `
                    <h4><i class="fas fa-exclamation-triangle"></i> Validation Error</h4>
                    <p>${message}</p>
                    <button onclick="this.parentElement.remove()" style="
                        background: none;
                        border: none;
                        color: #742a2a;
                        float: right;
                        font-size: 18px;
                        cursor: pointer;
                        margin-top: -30px;
                    ">&times;</button>
                `;

                // Auto-remove after 5 seconds
                setTimeout(() => {
                    if (alert.parentElement) {
                        alert.remove();
                    }
                }, 5000);
            }

            handleOutsideClick(e) {
                const sidebar = document.getElementById('sidebar');
                const menuBtn = document.querySelector('.mobile-menu-btn');
                
                if (window.innerWidth <= 968 && 
                    !sidebar.contains(e.target) && 
                    !menuBtn.contains(e.target) && 
                    sidebar.classList.contains('open')) {
                    sidebar.classList.remove('open');
                }
            }

            handleResize() {
                const sidebar = document.getElementById('sidebar');
                if (window.innerWidth > 968) {
                    sidebar.classList.remove('open');
                }
            }
        }

        // Initialize the application
        document.addEventListener('DOMContentLoaded', () => {
            new HealthQuestionnaireApp();
            
            // Console logging for debugging
            console.log('🩸 Enhanced LifeSaver Hub - Health Questionnaire Loaded');
            console.log('Student ID:', <?php echo json_encode(SessionManager::getStudentId()); ?>);
            console.log('Event ID:', <?php echo json_encode($eventId); ?>);
            console.log('Student Email:', <?php echo json_encode($student['StudentEmail'] ?? null); ?>);
            console.log('✅ Updated system ready with new database structure');
        });

        // Progressive Web App features
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/sw.js')
                    .then(registration => {
                        console.log('SW registered: ', registration);
                    })
                    .catch(registrationError => {
                        console.log('SW registration failed: ', registrationError);
                    });
            });
        }

        // Performance monitoring
        window.addEventListener('load', () => {
            const loadTime = performance.now();
            console.log(`Page loaded in ${loadTime.toFixed(2)}ms`);
            
            // Report performance to analytics if available
            if (typeof gtag !== 'undefined') {
                gtag('event', 'page_load_time', {
                    value: Math.round(loadTime),
                    event_category: 'Performance'
                });
            }
        });
    </script>
</body>
</html>