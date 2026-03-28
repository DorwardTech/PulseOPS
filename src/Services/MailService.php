<?php
declare(strict_types=1);

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class MailService
{
    public function __construct(
        private SettingsService $settings
    ) {}

    /**
     * Send an email using SMTP settings from the database.
     */
    public function send(string $to, string $subject, string $htmlBody, ?string $fromName = null): bool
    {
        $mail = new PHPMailer(true);

        try {
            $smtpHost = $this->settings->get('smtp_host', '');
            $smtpPort = (int) $this->settings->get('smtp_port', 587);
            $smtpUser = $this->settings->get('smtp_username', '');
            $smtpPass = $this->settings->get('smtp_password', '');
            $smtpEncryption = $this->settings->get('smtp_encryption', 'tls');
            $defaultFromName = $this->settings->get('smtp_from_name', 'PulseOPS');

            if (empty($smtpHost) || empty($smtpUser)) {
                error_log("MailService: SMTP not configured, skipping email to {$to}");
                return false;
            }

            $mail->isSMTP();
            $mail->Host = $smtpHost;
            $mail->SMTPAuth = true;
            $mail->Username = $smtpUser;
            $mail->Password = $smtpPass;
            $mail->SMTPSecure = $smtpEncryption === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $smtpPort;
            $mail->Timeout = 10;

            $mail->setFrom($smtpUser, $fromName ?? $defaultFromName);
            $mail->addAddress($to);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</li>'], "\n", $htmlBody));

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("MailService: Failed to send to {$to}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send portal welcome email to a new customer portal user.
     */
    public function sendPortalWelcome(string $email, string $name, string $password, string $customerName): bool
    {
        $portalUrl = $this->settings->get('app_url', '') ?: ($_ENV['APP_URL'] ?? 'https://v2.pulseops.com.au');
        $loginUrl = rtrim($portalUrl, '/') . '/portal/login';
        $companyName = $this->settings->get('company_name', '') ?: 'NT Amusements';

        $subject = "Your PulseOPS Portal Access";

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<style>
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background: #f5f5f5; }
    .container { max-width: 600px; margin: 20px auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
    .header { background: #0d6efd; color: #fff; padding: 24px 32px; }
    .header h1 { margin: 0; font-size: 22px; font-weight: 600; }
    .body { padding: 32px; }
    .credentials { background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 6px; padding: 20px; margin: 20px 0; }
    .credentials table { width: 100%; border-collapse: collapse; }
    .credentials td { padding: 6px 0; }
    .credentials td:first-child { font-weight: 600; width: 120px; color: #6c757d; }
    .btn { display: inline-block; background: #0d6efd; color: #fff; text-decoration: none; padding: 12px 28px; border-radius: 6px; font-weight: 600; margin: 16px 0; }
    .features { margin: 20px 0; padding: 0; }
    .features li { padding: 4px 0; }
    .footer { padding: 20px 32px; background: #f8f9fa; border-top: 1px solid #e9ecef; font-size: 13px; color: #6c757d; }
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Welcome to PulseOPS</h1>
    </div>
    <div class="body">
        <p>Hi {$name},</p>
        <p>Your customer portal account for <strong>{$customerName}</strong> has been set up and is ready to use.</p>

        <div class="credentials">
            <table>
                <tr><td>Portal URL</td><td><a href="{$loginUrl}">{$loginUrl}</a></td></tr>
                <tr><td>Email</td><td>{$email}</td></tr>
                <tr><td>Password</td><td><code>{$password}</code></td></tr>
            </table>
        </div>

        <a href="{$loginUrl}" class="btn">Log In to Portal</a>

        <p><strong>What you can do in the portal:</strong></p>
        <ul class="features">
            <li>View your machines and their status</li>
            <li>Track revenue and collection history</li>
            <li>View commission statements and payment details</li>
            <li>Report machine issues directly to our team</li>
            <li>Update your profile and bank details</li>
        </ul>

        <p>Please change your password after your first login via <strong>Settings &gt; Password</strong>.</p>
        <p>If you have any questions or issues logging in, just let us know.</p>
    </div>
    <div class="footer">
        <p>Cheers,<br>{$companyName}</p>
    </div>
</div>
</body>
</html>
HTML;

        return $this->send($email, $subject, $html);
    }
}
