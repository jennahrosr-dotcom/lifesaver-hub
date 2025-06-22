<?php
// email_config.php - Updated for Student schema
class EmailConfig {
    // SMTP Configuration - UPDATE THESE WITH YOUR SETTINGS
    const SMTP_HOST = 'smtp.gmail.com';
    const SMTP_PORT = 587;
    const SMTP_USERNAME = 'jennahrosr@gmail.com'; // Your Gmail
    const SMTP_PASSWORD = 'ckgsjiiizwoitino'; // Gmail App Password
    const SMTP_ENCRYPTION = 'tls';
    
    // Email Settings
    const FROM_EMAIL = 'noreply@lifesaver.com';
    const FROM_NAME = 'LifeSaver Hub';
    const REPLY_TO = 'support@lifesaver.com';
    
    // Website URL
    const WEBSITE_URL = 'http://localhost/lifesaver';
}

// student_email_service.php - Email service adapted for your Student schema
require_once 'vendor/autoload.php'; // PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class StudentEmailService {
    private $pdo;
    private $mailer;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->initializeMailer();
    }
    
    private function initializeMailer() {
        $this->mailer = new PHPMailer(true);
        
        try {
            $this->mailer->isSMTP();
            $this->mailer->Host = EmailConfig::SMTP_HOST;
            $this->mailer->SMTPAuth = true;
            $this->mailer->Username = EmailConfig::SMTP_USERNAME;
            $this->mailer->Password = EmailConfig::SMTP_PASSWORD;
            $this->mailer->SMTPSecure = EmailConfig::SMTP_ENCRYPTION;
            $this->mailer->Port = EmailConfig::SMTP_PORT;
            
            $this->mailer->setFrom(EmailConfig::FROM_EMAIL, EmailConfig::FROM_NAME);
            $this->mailer->addReplyTo(EmailConfig::REPLY_TO, EmailConfig::FROM_NAME);
            $this->mailer->isHTML(true);
        } catch (Exception $e) {
            error_log("Email configuration error: " . $e->getMessage());
        }
    }
    
    /**
     * Send notification for upcoming blood drive
     */
    public function sendUpcomingEventNotification($eventId) {
        try {
            // Get event details
            $eventStmt = $this->pdo->prepare("
                SELECT e.*, v.VenueName, v.VenueAddress 
                FROM event e 
                LEFT JOIN venue v ON e.VenueID = v.VenueID 
                WHERE e.EventID = ?
            ");
            $eventStmt->execute([$eventId]);
            $event = $eventStmt->fetch();
            
            if (!$event) {
                throw new Exception("Event not found");
            }
            
            // Get all students with email notifications enabled
            $studentsStmt = $this->pdo->prepare("
                SELECT StudentID, StudentName, StudentEmail 
                FROM student 
                WHERE StudentEmail IS NOT NULL 
                AND StudentEmail != '' 
                AND EmailNotifications = 1
            ");
            $studentsStmt->execute();
            $students = $studentsStmt->fetchAll();
            
            $successCount = 0;
            $errorCount = 0;
            
            foreach ($students as $student) {
                try {
                    $this->sendSingleEmail(
                        $student['StudentEmail'],
                        $student['StudentName'],
                        'Upcoming Blood Drive - ' . $event['EventTitle'],
                        $this->getUpcomingEventTemplate($event, $student)
                    );
                    
                    // Log notification
                    $this->logNotification(
                        $student['StudentID'],
                        'Event',
                        'Upcoming Blood Drive Notification',
                        "You have been notified about the upcoming blood drive: " . $event['EventTitle'],
                        $eventId,
                        'email'
                    );
                    
                    $successCount++;
                } catch (Exception $e) {
                    error_log("Failed to send email to {$student['StudentEmail']}: " . $e->getMessage());
                    $errorCount++;
                }
            }
            
            return [
                'success' => true,
                'sent' => $successCount,
                'failed' => $errorCount,
                'message' => "Sent to {$successCount} students, {$errorCount} failed"
            ];
            
        } catch (Exception $e) {
            error_log("Upcoming event notification error: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Send confirmation when student registers
     */
    public function sendRegistrationConfirmation($registrationId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT r.*, s.StudentName, s.StudentEmail, e.EventTitle, e.EventDate, 
                       e.StartTime, e.EndTime, v.VenueName, v.VenueAddress
                FROM registration r
                JOIN student s ON r.StudentID = s.StudentID
                JOIN event e ON r.EventID = e.EventID
                LEFT JOIN venue v ON e.VenueID = v.VenueID
                WHERE r.RegistrationID = ?
            ");
            $stmt->execute([$registrationId]);
            $registration = $stmt->fetch();
            
            if (!$registration) {
                throw new Exception("Registration not found");
            }
            
            $this->sendSingleEmail(
                $registration['StudentEmail'],
                $registration['StudentName'],
                'Registration Confirmed - ' . $registration['EventTitle'],
                $this->getRegistrationConfirmationTemplate($registration)
            );
            
            // Log notification
            $this->logNotification(
                $registration['StudentID'],
                'Registration',
                'Registration Confirmed',
                "Your registration for {$registration['EventTitle']} has been confirmed.",
                $registration['EventID'],
                'email'
            );
            
            return ['success' => true, 'message' => 'Confirmation email sent'];
            
        } catch (Exception $e) {
            error_log("Registration confirmation error: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Send thank you email after donation completion
     */
    public function sendDonationCompletionNotification($registrationId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT r.*, s.StudentName, s.StudentEmail, e.EventTitle, e.EventDate
                FROM registration r
                JOIN student s ON r.StudentID = s.StudentID
                JOIN event e ON r.EventID = e.EventID
                WHERE r.RegistrationID = ?
            ");
            $stmt->execute([$registrationId]);
            $registration = $stmt->fetch();
            
            if (!$registration) {
                throw new Exception("Registration not found");
            }
            
            $this->sendSingleEmail(
                $registration['StudentEmail'],
                $registration['StudentName'],
                'Thank You for Your Blood Donation!',
                $this->getCompletionTemplate($registration)
            );
            
            // Log notification
            $this->logNotification(
                $registration['StudentID'],
                'System',
                'Donation Completed',
                "Thank you for completing your blood donation at {$registration['EventTitle']}.",
                $registration['EventID'],
                'email'
            );
            
            return ['success' => true, 'message' => 'Completion email sent'];
            
        } catch (Exception $e) {
            error_log("Completion notification error: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Send single email
     */
    private function sendSingleEmail($to, $toName, $subject, $body) {
        try {
            $this->mailer->clearAddresses();
            $this->mailer->addAddress($to, $toName);
            $this->mailer->Subject = $subject;
            $this->mailer->Body = $body;
            $this->mailer->send();
        } catch (Exception $e) {
            throw new Exception("Email sending failed: " . $e->getMessage());
        }
    }
    
    /**
     * Log notification in database
     */
    private function logNotification($studentId, $type, $title, $message, $eventId = null, $channel = 'system') {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO notification (StudentID, NotificationType, NotificationTitle, 
                                        NotificationMessage, EventID, CreatedDate, IsRead, Channel) 
                VALUES (?, ?, ?, ?, ?, NOW(), 0, ?)
            ");
            $stmt->execute([$studentId, $type, $title, $message, $eventId, $channel]);
        } catch (Exception $e) {
            error_log("Notification logging error: " . $e->getMessage());
        }
    }
    
    /**
     * Email template for upcoming events
     */
    private function getUpcomingEventTemplate($event, $student) {
        $eventDate = date('l, F j, Y', strtotime($event['EventDate']));
        $startTime = date('g:i A', strtotime($event['StartTime']));
        $endTime = date('g:i A', strtotime($event['EndTime']));
        
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 20px; }
                .container { max-width: 600px; margin: 0 auto; background: #ffffff; }
                .header { background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { padding: 30px; background: #f9f9f9; }
                .event-card { background: #ffffff; border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px; margin: 20px 0; }
                .button { display: inline-block; background: #667eea; color: white; padding: 12px 25px; text-decoration: none; border-radius: 5px; margin: 10px 0; }
                .footer { background: #f0f0f0; padding: 20px; text-align: center; color: #666; font-size: 12px; border-radius: 0 0 10px 10px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>🩸 LifeSaver Hub</h1>
                    <h2>Upcoming Blood Drive</h2>
                </div>
                <div class='content'>
                    <p>Dear {$student['StudentName']},</p>
                    
                    <p>We're excited to inform you about an upcoming blood donation drive! Your participation can save lives.</p>
                    
                    <div class='event-card'>
                        <h3>📅 Event Details</h3>
                        <p><strong>Event:</strong> {$event['EventTitle']}</p>
                        <p><strong>Date:</strong> {$eventDate}</p>
                        <p><strong>Time:</strong> {$startTime} - {$endTime}</p>
                        <p><strong>Location:</strong> {$event['VenueName']}<br>{$event['VenueAddress']}</p>
                        <p><strong>Description:</strong> {$event['EventDescription']}</p>
                    </div>
                    
                    <div style='text-align: center;'>
                        <a href='" . EmailConfig::WEBSITE_URL . "/events.php?id={$event['EventID']}' class='button'>Register Now</a>
                    </div>
                    
                    <p><strong>Why donate blood?</strong></p>
                    <ul>
                        <li>Save up to 3 lives with one donation</li>
                        <li>Help patients with cancer, blood disorders, and trauma</li>
                        <li>Quick and safe process</li>
                        <li>Free health screening included</li>
                    </ul>
                    
                    <p>Thank you for considering blood donation!</p>
                    
                    <p>Best regards,<br><strong>LifeSaver Hub Team</strong></p>
                </div>
                <div class='footer'>
                    <p>LifeSaver Hub - Making Blood Donation Simple and Accessible</p>
                </div>
            </div>
        </body>
        </html>";
    }
    
    /**
     * Email template for registration confirmation
     */
    private function getRegistrationConfirmationTemplate($registration) {
        $eventDate = date('l, F j, Y', strtotime($registration['EventDate']));
        $startTime = date('g:i A', strtotime($registration['StartTime']));
        $endTime = date('g:i A', strtotime($registration['EndTime']));
        
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 20px; }
                .container { max-width: 600px; margin: 0 auto; background: #ffffff; }
                .header { background: linear-gradient(135deg, #10ac84, #00d2d3); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { padding: 30px; background: #f9f9f9; }
                .confirmation-box { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 20px; border-radius: 8px; margin: 20px 0; text-align: center; }
                .event-details { background: #ffffff; border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px; margin: 20px 0; }
                .tips-box { background: #fff3cd; border: 1px solid #ffeaa7; padding: 20px; border-radius: 8px; margin: 20px 0; }
                .footer { background: #f0f0f0; padding: 20px; text-align: center; color: #666; font-size: 12px; border-radius: 0 0 10px 10px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>🩸 LifeSaver Hub</h1>
                    <h2>Registration Confirmed!</h2>
                </div>
                <div class='content'>
                    <div class='confirmation-box'>
                        <h3>✅ Your registration has been confirmed!</h3>
                        <p>Registration ID: <strong>{$registration['RegistrationID']}</strong></p>
                    </div>
                    
                    <p>Dear {$registration['StudentName']},</p>
                    
                    <p>Thank you for registering to donate blood! Your generosity will help save lives.</p>
                    
                    <div class='event-details'>
                        <h3>📅 Your Appointment Details</h3>
                        <p><strong>Event:</strong> {$registration['EventTitle']}</p>
                        <p><strong>Date:</strong> {$eventDate}</p>
                        <p><strong>Time:</strong> {$startTime} - {$endTime}</p>
                        <p><strong>Location:</strong> {$registration['VenueName']}<br>{$registration['VenueAddress']}</p>
                    </div>
                    
                    <div class='tips-box'>
                        <h3>💡 Preparation Tips</h3>
                        <ul>
                            <li>Get plenty of sleep the night before</li>
                            <li>Eat a healthy meal before donating</li>
                            <li>Drink plenty of water</li>
                            <li>Bring a valid photo ID</li>
                            <li>Avoid alcohol 24 hours before donation</li>
                            <li>Wear comfortable clothing</li>
                        </ul>
                    </div>
                    
                    <p>We'll send you reminder notifications closer to the event date.</p>
                    
                    <p>If you need to cancel or reschedule, please contact us as soon as possible.</p>
                    
                    <p>Thank you for being a hero!</p>
                    
                    <p>Best regards,<br><strong>LifeSaver Hub Team</strong></p>
                </div>
                <div class='footer'>
                    <p>Questions? Contact us at support@lifesaver.com</p>
                </div>
            </div>
        </body>
        </html>";
    }
    
    /**
     * Email template for donation completion
     */
    private function getCompletionTemplate($registration) {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 20px; }
                .container { max-width: 600px; margin: 0 auto; background: #ffffff; }
                .header { background: linear-gradient(135deg, #10ac84, #00d2d3); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { padding: 30px; background: #f9f9f9; }
                .thank-you-box { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 25px; border-radius: 8px; margin: 20px 0; text-align: center; }
                .impact-stats { background: #ffffff; border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px; margin: 20px 0; text-align: center; }
                .stat-number { font-size: 2em; font-weight: bold; color: #10ac84; }
                .aftercare-tips { background: #e2f3ff; border: 1px solid #b3d9ff; padding: 20px; border-radius: 8px; margin: 20px 0; }
                .footer { background: #f0f0f0; padding: 20px; text-align: center; color: #666; font-size: 12px; border-radius: 0 0 10px 10px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>🩸 LifeSaver Hub</h1>
                    <h2>Thank You, Hero!</h2>
                </div>
                <div class='content'>
                    <div class='thank-you-box'>
                        <h3>🏆 Congratulations! You've just saved lives!</h3>
                        <p>Your blood donation has been successfully completed.</p>
                    </div>
                    
                    <p>Dear {$registration['StudentName']},</p>
                    
                    <p>We want to express our heartfelt gratitude for your generous blood donation at {$registration['EventTitle']}. Your selfless act will make a real difference!</p>
                    
                    <div class='impact-stats'>
                        <h3>🌟 Your Impact</h3>
                        <div class='stat-number'>3</div>
                        <p>Lives You Can Save</p>
                        <p>Your donation can be separated into red blood cells, plasma, and platelets - helping multiple patients with different needs.</p>
                    </div>
                    
                    <div class='aftercare-tips'>
                        <h3>💊 Post-Donation Care</h3>
                        <ul>
                            <li>Keep the bandage on for at least 4 hours</li>
                            <li>Drink plenty of fluids over the next 24 hours</li>
                            <li>Avoid heavy lifting today</li>
                            <li>Eat iron-rich foods</li>
                            <li>Contact us if you feel unwell</li>
                        </ul>
                    </div>
                    
                    <p><strong>What happens next?</strong></p>
                    <ul>
                        <li>Your blood will be tested and processed within 24 hours</li>
                        <li>It will be distributed to hospitals in need</li>
                        <li>You'll be eligible to donate again in 8 weeks</li>
                    </ul>
                    
                    <p>Thank you for being a real-life hero!</p>
                    
                    <p>With immense gratitude,<br><strong>LifeSaver Hub Team</strong></p>
                </div>
                <div class='footer'>
                    <p>Share your good deed with friends and encourage them to donate too!</p>
                </div>
            </div>
        </body>
        </html>";
    }
}

// Integration hooks for your existing code
class StudentEmailHooks {
    private $emailService;
    
    public function __construct($pdo) {
        $this->emailService = new StudentEmailService($pdo);
    }
    
    /**
     * Call when creating a new event
     */
    public function onEventCreated($eventId) {
        return $this->emailService->sendUpcomingEventNotification($eventId);
    }
    
    /**
     * Call when student registers for event
     */
    public function onStudentRegistered($registrationId) {
        return $this->emailService->sendRegistrationConfirmation($registrationId);
    }
    
    /**
     * Call when student completes donation
     */
    public function onDonationCompleted($registrationId) {
        return $this->emailService->sendDonationCompletionNotification($registrationId);
    }
}

// Simple test script - create test_email.php
/*
<?php
require_once 'student_email_service.php';

$pdo = new PDO("mysql:host=localhost;dbname=lifesaver", "root", "");
$emailService = new StudentEmailService($pdo);

try {
    // Test email configuration
    $emailService->sendSingleEmail(
        'your-test-email@gmail.com',
        'Test User',
        'LifeSaver Hub - Test Email',
        '<h2>🩸 Test Successful!</h2><p>Email system is working correctly!</p>'
    );
    echo "✅ Test email sent successfully!";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>
*/

// Usage examples:
/*
// When creating an event (in your admin panel):
$hooks = new StudentEmailHooks($pdo);
$result = $hooks->onEventCreated($eventId);

// When student registers:
$hooks = new StudentEmailHooks($pdo);
$result = $hooks->onStudentRegistered($registrationId);

// When donation is completed:
$hooks = new StudentEmailHooks($pdo);
$result = $hooks->onDonationCompleted($registrationId);
*/
?>