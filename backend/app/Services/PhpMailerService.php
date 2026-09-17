<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

/**
 * Service to send emails directly using the PHPMailer library.
 */
class PhpMailerService
{
    /**
     * Send an HTML email via PHPMailer using configured SMTP credentials.
     *
     * @param string $to Recipient email address
     * @param string $subject Email subject line
     * @param string $htmlBody Full HTML email body
     * @param string $textBody Optional plain text alternative
     * @return bool True if sent successfully, false on failure
     */
    public function send(string $to, string $subject, string $htmlBody, string $textBody = ''): bool
    {
        $mail = new PHPMailer(true);

        try {
            // SMTP Server Configuration
            $mail->isSMTP();
            $mail->Host = config('mail.mailers.smtp.host', env('MAIL_HOST', 'smtp.gmail.com'));
            $mail->SMTPAuth = true;
            $mail->Username = config('mail.mailers.smtp.username', env('MAIL_USERNAME', ''));
            $mail->Password = config('mail.mailers.smtp.password', env('MAIL_PASSWORD', ''));

            $port = (int) config('mail.mailers.smtp.port', env('MAIL_PORT', 587));
            $encryption = strtolower((string) config('mail.mailers.smtp.encryption', env('MAIL_ENCRYPTION', 'tls')));

            if ($encryption === 'ssl' || $port === 465) {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($encryption === 'tls' || $port === 587) {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            }

            $mail->Port = $port;
            $mail->CharSet = 'UTF-8';
            $mail->Timeout = 15;

            // Sender and Recipient
            $fromAddress = config('mail.from.address', env('MAIL_FROM_ADDRESS', 'noreply@primepowersystem.com'));
            $fromName = config('mail.from.name', env('MAIL_FROM_NAME', 'PrimePower Manpower HRIS'));

            $mail->setFrom($fromAddress, $fromName);
            $mail->addAddress($to);

            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = $textBody ?: strip_tags($htmlBody);

            $mail->send();

            Log::info('PHPMailer: Successfully sent email.', [
                'to' => $to,
                'subject' => $subject,
            ]);

            return true;
        } catch (Exception $e) {
            Log::error('PHPMailer error: Failed to send email.', [
                'to' => $to,
                'subject' => $subject,
                'error' => $mail->ErrorInfo ?: $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Send a 6-digit Two-Factor Authentication OTP code using PHPMailer.
     *
     * @param string $toEmail Recipient email address
     * @param string $code 6-digit verification code
     * @param int $ttlSeconds Expiration in seconds
     * @param string $recipientName Optional recipient name
     * @return bool
     */
    public function sendLoginOtp(string $toEmail, string $code, int $ttlSeconds = 120, string $recipientName = ''): bool
    {
        $minutes = max(1, (int) ceil($ttlSeconds / 60));
        $timeLabel = $minutes === 1 ? '1 minute (60 seconds)' : "{$minutes} minutes ({$ttlSeconds} seconds)";
        $subject = "Your PrimePower sign-in code is {$code}";

        $greeting = $recipientName ? "Hello {$recipientName}," : "Hello,";

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$subject}</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f4f6f8; margin: 0; padding: 24px; color: #1e293b;">
    <table align="center" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 520px; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);">
        <tr>
            <td style="background-color: #0284c7; padding: 24px; text-align: center;">
                <h1 style="color: #ffffff; margin: 0; font-size: 20px; font-weight: 700; letter-spacing: 0.5px;">
                    PrimePower Manpower Services
                </h1>
                <p style="color: #e0f2fe; margin: 4px 0 0 0; font-size: 13px;">
                    Human Resource Information System
                </p>
            </td>
        </tr>
        <tr>
            <td style="padding: 32px 28px;">
                <p style="margin-top: 0; font-size: 15px; color: #334155;">{$greeting}</p>
                <p style="font-size: 14px; line-height: 1.5; color: #475569;">
                    You are receiving this email because you attempted to sign in to your PrimePower HRIS account. Here is your one-time verification code:
                </p>

                <div style="background-color: #f0f9ff; border: 2px dashed #0284c7; border-radius: 8px; padding: 18px; text-align: center; margin: 24px 0;">
                    <span style="font-family: 'Courier New', Courier, monospace; font-size: 32px; font-weight: 800; letter-spacing: 8px; color: #0369a1;">
                        {$code}
                    </span>
                </div>

                <p style="font-size: 13px; color: #64748b; margin-bottom: 20px;">
                    ⏱️ This code will expire in <strong>{$timeLabel}</strong> and can only be used once.
                </p>

                <hr style="border: none; border-top: 1px solid #e2e8f0; margin: 24px 0;">

                <div style="background-color: #fffbeb; border-left: 4px solid #f59e0b; padding: 12px; border-radius: 4px;">
                    <p style="margin: 0; font-size: 12px; color: #92400e; line-height: 1.4;">
                        <strong>Security Notice:</strong> If you did not initiate this sign-in request, please inform your HR administrator immediately as your password may have been compromised.
                    </p>
                </div>
            </td>
        </tr>
        <tr>
            <td style="background-color: #f8fafc; padding: 16px 24px; text-align: center; border-top: 1px solid #e2e8f0;">
                <p style="margin: 0; font-size: 11px; color: #94a3b8;">
                    PrimePower Manpower Services Inc. &bull; Enterprise HRIS Portal
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;

        $text = "PrimePower Sign-in Verification\n\n"
            ."Your one-time 6-digit sign-in code is: {$code}\n\n"
            ."This code expires in {$timeLabel} and can only be used once.\n\n"
            ."If you did not try to sign in, tell your administrator immediately.";

        return $this->send($toEmail, $subject, $html, $text);
    }
}
