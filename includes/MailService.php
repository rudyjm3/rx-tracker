<?php

declare(strict_types=1);

final class MailService
{
    private readonly string $apiKey;
    private readonly string $fromAddress;
    private readonly string $fromName;
    private readonly string $appUrl;

    public function __construct()
    {
        $this->apiKey      = env_value('RESEND_API_KEY', '');
        $this->fromAddress = env_value('MAIL_FROM_ADDRESS', '');
        $this->fromName    = env_value('MAIL_FROM_NAME', 'RxTracker');
        $this->appUrl      = rtrim(env_value('APP_URL', ''), '/');
    }

    public function sendPasswordReset(string $toEmail, string $token): void
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('RESEND_API_KEY is not configured.');
        }
        if ($this->fromAddress === '') {
            throw new RuntimeException('MAIL_FROM_ADDRESS is not configured.');
        }

        $resetLink = $this->appUrl . '/index.php?page=reset-password&token=' . urlencode($token);

        $payload = json_encode([
            'from'    => $this->fromName . ' <' . $this->fromAddress . '>',
            'to'      => [$toEmail],
            'subject' => 'Reset your RxTracker password',
            'html'    => $this->buildResetHtml($toEmail, $resetLink),
        ], JSON_THROW_ON_ERROR);

        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_POST          => true,
            CURLOPT_POSTFIELDS    => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER    => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
        ]);
        curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException('Failed to send password reset email (HTTP ' . $httpCode . ').');
        }
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
