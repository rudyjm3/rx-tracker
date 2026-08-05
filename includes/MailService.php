<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

final class MailService
{
    private readonly string $mailer;

    private readonly string $smtpHost;
    private readonly int $smtpPort;
    private readonly string $smtpEncryption;
    private readonly string $smtpUsername;
    private readonly string $smtpPassword;

    private readonly string $resendApiKey;

    private readonly string $fromAddress;
    private readonly string $fromName;
    private readonly string $appUrl;

    public function __construct()
    {
        $this->mailer = strtolower(env_value('MAIL_MAILER', 'smtp')) === 'resend' ? 'resend' : 'smtp';

        $this->smtpHost       = env_value('SMTP_HOST', '');
        $this->smtpPort       = (int) env_value('SMTP_PORT', '587');
        $this->smtpEncryption = env_value('SMTP_ENCRYPTION', 'tls');
        $this->smtpUsername   = env_value('SMTP_USERNAME', '');
        $this->smtpPassword   = env_value('SMTP_PASSWORD', '');

        $this->resendApiKey = env_value('RESEND_API_KEY', '');

        $this->fromAddress = env_value('MAIL_FROM_ADDRESS', '');
        $this->fromName    = env_value('MAIL_FROM_NAME', 'RxTracker');
        $this->appUrl      = rtrim(env_value('APP_URL', ''), '/');
    }

    public function sendPasswordReset(string $toEmail, string $token): void
    {
        $resetLink = $this->appUrl . '/index.php?page=reset-password&token=' . urlencode($token);

        $this->sendHtmlMail(
            $toEmail,
            'Reset your RxTracker password',
            $this->buildResetHtml($toEmail, $resetLink)
        );
    }

    public function sendVerificationEmail(string $toEmail, string $token): void
    {
        $verifyLink = $this->appUrl . '/index.php?page=verify-email&token=' . urlencode($token);

        $this->sendHtmlMail(
            $toEmail,
            'Verify your RxTracker email address',
            $this->buildVerificationHtml($toEmail, $verifyLink)
        );
    }

    private function sendHtmlMail(string $toEmail, string $subject, string $html): void
    {
        if ($this->fromAddress === '') {
            throw new RuntimeException('MAIL_FROM_ADDRESS is not configured.');
        }

        if ($this->mailer === 'resend') {
            $this->sendViaResend($toEmail, $subject, $html);
        } else {
            $this->sendViaSmtp($toEmail, $subject, $html);
        }
    }

    private function sendViaSmtp(string $toEmail, string $subject, string $html): void
    {
        if ($this->smtpHost === '' || $this->smtpUsername === '' || $this->smtpPassword === '') {
            throw new RuntimeException('SMTP_HOST, SMTP_USERNAME, and SMTP_PASSWORD must be configured.');
        }

        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = $this->smtpHost;
            $mail->Port       = $this->smtpPort;
            $mail->SMTPAuth   = true;
            $mail->Username   = $this->smtpUsername;
            $mail->Password   = $this->smtpPassword;
            $mail->SMTPSecure = $this->smtpEncryption === 'ssl'
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;

            $mail->setFrom($this->fromAddress, $this->fromName);
            $mail->addAddress($toEmail);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $html;

            $mail->send();
        } catch (PHPMailerException $e) {
            throw new RuntimeException('Failed to send email via SMTP: ' . $mail->ErrorInfo, 0, $e);
        }
    }

    private function sendViaResend(string $toEmail, string $subject, string $html): void
    {
        if ($this->resendApiKey === '') {
            throw new RuntimeException('RESEND_API_KEY is not configured.');
        }

        $payload = json_encode([
            'from'    => $this->fromName . ' <' . $this->fromAddress . '>',
            'to'      => [$toEmail],
            'subject' => $subject,
            'html'    => $html,
        ], JSON_THROW_ON_ERROR);

        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->resendApiKey,
                'Content-Type: application/json',
            ],
        ]);
        curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException('Failed to send email via Resend (HTTP ' . $httpCode . ').');
        }
    }

    private function buildVerificationHtml(string $email, string $verifyLink): string
    {
        $safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
        $safeLink  = htmlspecialchars($verifyLink, ENT_QUOTES, 'UTF-8');

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <body style="font-family: Inter, Arial, sans-serif; color: #172033; background: #EAF4FF; margin: 0; padding: 2rem;">
          <div style="max-width: 500px; margin: 0 auto; background: #fff; border-radius: 18px; padding: 2.5rem;">
            <p style="font-size: 1.4rem; font-weight: 800; color: #0754A8; margin-top: 0;">RxTracker</p>
            <h1 style="font-size: 1.3rem; margin: 0 0 1rem;">Verify your email</h1>
            <p>Hi {$safeEmail},</p>
            <p>Thanks for signing up! Click the button below to verify your email address. This link expires in <strong>24 hours</strong>.</p>
            <p style="text-align: center; margin: 2rem 0;">
              <a href="{$safeLink}"
                 style="display: inline-block; background: linear-gradient(135deg, #14CFE0 0%, #0A8AC8 48%, #0754A8 100%);
                        color: #fff; text-decoration: none; padding: 0.9rem 2rem; border-radius: 10px; font-weight: 800;">
                Verify Email
              </a>
            </p>
            <p style="color: #60708A; font-size: 0.88rem;">If you didn't create a RxTracker account, you can safely ignore this email.</p>
          </div>
        </body>
        </html>
        HTML;
    }

    private function buildResetHtml(string $email, string $resetLink): string
    {
        $safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
        $safeLink  = htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8');

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <body style="font-family: Inter, Arial, sans-serif; color: #172033; background: #EAF4FF; margin: 0; padding: 2rem;">
          <div style="max-width: 500px; margin: 0 auto; background: #fff; border-radius: 18px; padding: 2.5rem;">
            <p style="font-size: 1.4rem; font-weight: 800; color: #0754A8; margin-top: 0;">RxTracker</p>
            <h1 style="font-size: 1.3rem; margin: 0 0 1rem;">Reset your password</h1>
            <p>Hi {$safeEmail},</p>
            <p>We received a request to reset your RxTracker password. Click the button below to set a new password. This link expires in <strong>1 hour</strong>.</p>
            <p style="text-align: center; margin: 2rem 0;">
              <a href="{$safeLink}"
                 style="display: inline-block; background: linear-gradient(135deg, #14CFE0 0%, #0A8AC8 48%, #0754A8 100%);
                        color: #fff; text-decoration: none; padding: 0.9rem 2rem; border-radius: 10px; font-weight: 800;">
                Reset Password
              </a>
            </p>
            <p style="color: #60708A; font-size: 0.88rem;">If you didn't request a password reset, you can safely ignore this email. Your password will not change.</p>
          </div>
        </body>
        </html>
        HTML;
    }
}
