<?php
namespace App\Src\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class EmailService
{
    private PHPMailer $mailer;

    public function __construct()
    {
        $this->mailer = new PHPMailer(true);
        $this->configure();
    }

    private function configure(): void
    {
        // fail early instead of silently using fake mail settings
        $smtpUser = config('smtp.user');
        $smtpPass = config('smtp.pass');
        if (!$smtpUser || !$smtpPass) {
            throw new \RuntimeException('SMTP credentials not configured. Set SMTP_USER and SMTP_PASS in .env or your process environment.');
        }

        $this->mailer->isSMTP();
        $this->mailer->Host = config('smtp.host', 'smtp.gmail.com');
        $this->mailer->SMTPAuth = true;
        $this->mailer->Username = $smtpUser;
        $this->mailer->Password = $smtpPass;
        $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $this->mailer->Port = (int)config('smtp.port', 587);
        
        $fromEmail = config('smtp.from', $smtpUser);
        $this->mailer->setFrom($fromEmail, 'POC: Article Summarizer Security');
        
        // avoid hanging the reset flow if SMTP stalls
        $this->mailer->Timeout = 15;
    }

    public function sendPasswordResetEmail(string $to, string $otp): bool
    {
        try {
            $this->mailer->clearAddresses();
            $this->mailer->clearAllRecipients();
            $this->mailer->addAddress($to);
            $this->mailer->isHTML(true);
            $this->mailer->Subject = 'Your Password Reset OTP';
            
            $safeOtp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');

            $this->mailer->Body = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #333;'>
                    <h2 style='color: #1a1a1a;'>Password Reset Request</h2>
                    <p>We received a request to reset your password. Here is your One-Time Password (OTP):</p>
                    <div style='background: #f4f4f4; padding: 15px; text-align: center; font-size: 26px; font-weight: bold; letter-spacing: 5px; margin: 25px 0;'>
                        {$safeOtp}
                    </div>
                    <p><strong>This code will expire in 15 minutes.</strong></p>
                    <hr style='border: none; border-top: 1px solid #eee; margin: 30px 0;'>
                    <p style='color: #888; font-size: 12px;'>If you did not request a password reset, please ignore this email.</p>
                </div>
            ";
            
            $this->mailer->AltBody = "Your Password Reset OTP is: {$safeOtp}.\nIt expires in 15 minutes.";
            
            $this->mailer->send();
            return true;
        } catch (Exception $e) {
            error_log('Email error: ' . $e->getMessage());
            return false;
        }
    }
}
