<?php

declare(strict_types=1);

final class AuthService
{
    private const LOCKOUT_WINDOW_MINUTES = 15;
    private const LOCKOUT_THRESHOLD      = 5;

    public function __construct(
        private readonly PDO $db,
        private readonly SessionManager $sessionManager,
        private readonly ?MailService $mail = null
    ) {}

    public function register(string $email, string $password, string $displayName): int
    {
        $email = strtolower(trim($email));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Invalid email address.');
        }
        if (strlen($password) < 8) {
            throw new RuntimeException('Password must be at least 8 characters.');
        }

        $stmt = $this->db->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        if ($stmt->fetchColumn() !== false) {
            throw new RuntimeException('An account with this email already exists.');
        }

        $stmt = $this->db->prepare(
            'INSERT INTO users (email, password_hash, display_name) VALUES (:email, :hash, :display_name)'
        );
        $stmt->execute([
            'email'        => $email,
            'hash'         => password_hash($password, PASSWORD_BCRYPT),
            'display_name' => trim($displayName) !== '' ? trim($displayName) : null,
        ]);

        $userId = (int) $this->db->lastInsertId();

        // Attempt to send a verification email. If the mail service is not
        // configured or sending fails, auto-verify so the user isn't locked out.
        $emailSent = false;
        if ($this->mail !== null) {
            try {
                $token   = bin2hex(random_bytes(32));
                $expires = (new DateTimeImmutable('+24 hours'))->format('Y-m-d H:i:s');
                $this->db->prepare(
                    'UPDATE users SET verification_token = :token, verification_token_expires_at = :expires WHERE id = :id'
                )->execute(['token' => $token, 'expires' => $expires, 'id' => $userId]);
                $this->mail->sendVerificationEmail($email, $token);
                $emailSent = true;
            } catch (Throwable) {
                // Fall through to auto-verify below
            }
        }

        if (!$emailSent) {
            $this->db->prepare('UPDATE users SET email_verified = 1 WHERE id = :id')->execute(['id' => $userId]);
        }

        return $userId;
    }

    public function isEmailVerified(string $email): bool
    {
        $stmt = $this->db->prepare('SELECT email_verified FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => strtolower(trim($email))]);
        $val = $stmt->fetchColumn();
        return $val !== false && (bool) $val;
    }

    public function login(string $email, string $password, bool $remember = false): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $email = strtolower(trim($email));
        $ip    = $this->clientIp();

        // Check rate limit before even touching the DB for the password.
        if ($this->isLockedOut($email, $ip)) {
            $_SESSION['login_locked'] = true;
            return false;
        }

        $stmt = $this->db->prepare(
            'SELECT id, email, display_name, password_hash, email_verified FROM users WHERE email = :email LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!is_array($user) || empty($user['password_hash']) || !password_verify($password, (string) $user['password_hash'])) {
            $this->recordFailedAttempt($email, $ip);
            return false;
        }

        // Block unverified password-based accounts.
        if (!(bool) $user['email_verified']) {
            $_SESSION['login_unverified']           = true;
            $_SESSION['pending_verification_email'] = $email;
            return false;
        }

        // Successful login — clear flags and failed attempts for this email.
        unset($_SESSION['login_locked'], $_SESSION['login_unverified'], $_SESSION['pending_verification_email']);
        $this->clearEmailAttempts($email);

        $userId = (int) $user['id'];

        session_regenerate_id(true);
        $_SESSION['user_id']   = $userId;
        $_SESSION['user_name'] = (string) ($user['display_name'] ?? '');
        $_SESSION['email']     = (string) ($user['email'] ?? '');
        $_SESSION['logged_in'] = true;
        $this->db->prepare('UPDATE users SET last_login = NOW() WHERE id = :id')->execute(['id' => $userId]);

        $this->sessionManager->issueSession($userId, $remember);

        return true;
    }

    public function logout(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId > 0) {
            $this->sessionManager->destroySession($userId);
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();
    }

    public function requireLogin(): void
    {
        if ($this->currentUser() !== null) {
            return;
        }
        $redirect = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $location = 'index.php?page=login';
        if ($redirect !== '' && $redirect !== '/') {
            $location .= '&redirect=' . urlencode($redirect);
        }
        header('Location: ' . $location);
        exit;
    }

    public function currentUser(): ?array
    {
        $userId = $this->currentUserId();
        if ($userId === 0) {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT id, email, display_name, profile_picture, email_verified FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            unset($_SESSION['user_id']);
            return null;
        }
        return $row;
    }

    public function currentUserId(): int
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if (isset($_SESSION['user_id']) && (int) $_SESSION['user_id'] > 0) {
            return (int) $_SESSION['user_id'];
        }
        $userId = $this->sessionManager->resolveFromCookie();
        if ($userId !== null && $userId > 0) {
            $_SESSION['user_id'] = $userId;
            return $userId;
        }
        return 0;
    }

    public function activeProfileId(): ?int
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $val = $_SESSION['active_profile_id'] ?? null;
        return ($val === null || (int) $val === 0) ? null : (int) $val;
    }

    public function setActiveProfile(?int $profileId): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if ($profileId === null || $profileId <= 0) {
            unset($_SESSION['active_profile_id']);
        } else {
            $_SESSION['active_profile_id'] = $profileId;
        }
    }

    public function changePassword(int $userId, string $currentPassword, string $newPassword): bool
    {
        $stmt = $this->db->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        if (!is_array($row) || empty($row['password_hash']) || !password_verify($currentPassword, (string) $row['password_hash'])) {
            return false;
        }
        $this->db->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
            ->execute(['hash' => password_hash($newPassword, PASSWORD_BCRYPT), 'id' => $userId]);
        return true;
    }

    public function deleteAccount(int $userId): void
    {
        $this->db->beginTransaction();
        try {
            // Explicit cleanup for tables whose user_id column lacks a FK constraint,
            // or where dual-cascade paths (user + medication) could conflict.
            $this->db->prepare('DELETE FROM user_notifications       WHERE user_id = :uid')->execute(['uid' => $userId]);
            $this->db->prepare('DELETE FROM standalone_pain_mood_logs WHERE user_id = :uid')->execute(['uid' => $userId]);
            $this->db->prepare('DELETE FROM push_subscriptions        WHERE user_id = :uid')->execute(['uid' => $userId]);
            $this->db->prepare('DELETE FROM medication_groups         WHERE user_id = :uid')->execute(['uid' => $userId]);
            $this->db->prepare('DELETE FROM medications               WHERE user_id = :uid')->execute(['uid' => $userId]);
            $this->db->prepare('DELETE FROM users                     WHERE id      = :uid')->execute(['uid' => $userId]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->logout();
    }

    // ── Email verification ────────────────────────────────────────────────────

    public function verifyEmail(string $token): bool
    {
        if ($token === '') {
            return false;
        }
        $stmt = $this->db->prepare(
            'SELECT id FROM users WHERE verification_token = :token AND verification_token_expires_at > NOW() LIMIT 1'
        );
        $stmt->execute(['token' => $token]);
        $userId = $stmt->fetchColumn();

        if ($userId === false) {
            return false;
        }

        $this->db->prepare(
            'UPDATE users SET email_verified = 1, verification_token = NULL, verification_token_expires_at = NULL WHERE id = :id'
        )->execute(['id' => (int) $userId]);

        return true;
    }

    public function sendVerificationEmail(string $email): void
    {
        $email = strtolower(trim($email));
        $stmt  = $this->db->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        if ($stmt->fetchColumn() === false) {
            return; // Silently no-op to avoid email enumeration
        }

        $token   = bin2hex(random_bytes(32));
        $expires = (new DateTimeImmutable('+24 hours'))->format('Y-m-d H:i:s');
        $this->db->prepare(
            'UPDATE users SET verification_token = :token, verification_token_expires_at = :expires WHERE email = :email'
        )->execute(['token' => $token, 'expires' => $expires, 'email' => $email]);

        if ($this->mail !== null) {
            $this->mail->sendVerificationEmail($email, $token);
        }
    }

    // ── Rate limiting (login + forgot-password) ───────────────────────────────

    public function isIpRateLimited(string $ip): bool
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM login_attempts
                 WHERE attempt_type = 'ip' AND identifier = :ip
                   AND attempted_at > NOW() - INTERVAL " . self::LOCKOUT_WINDOW_MINUTES . " MINUTE"
            );
            $stmt->execute(['ip' => $ip]);
            return (int) $stmt->fetchColumn() >= self::LOCKOUT_THRESHOLD;
        } catch (Throwable) {
            return false;
        }
    }

    public function recordIpAttempt(string $ip): void
    {
        try {
            $this->db->prepare(
                "INSERT INTO login_attempts (identifier, attempt_type) VALUES (:ip, 'ip')"
            )->execute(['ip' => $ip]);
        } catch (Throwable) {}
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function isLockedOut(string $email, string $ip): bool
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM login_attempts
                 WHERE attempt_type = 'email' AND identifier = :email
                   AND attempted_at > NOW() - INTERVAL " . self::LOCKOUT_WINDOW_MINUTES . " MINUTE"
            );
            $stmt->execute(['email' => $email]);
            if ((int) $stmt->fetchColumn() >= self::LOCKOUT_THRESHOLD) {
                return true;
            }

            return $this->isIpRateLimited($ip);
        } catch (Throwable) {
            return false; // Fail open — a broken rate-limit table must not block all logins
        }
    }

    private function recordFailedAttempt(string $email, string $ip): void
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO login_attempts (identifier, attempt_type) VALUES (:id, :type)'
            );
            $stmt->execute(['id' => $email, 'type' => 'email']);
            $stmt->execute(['id' => $ip,    'type' => 'ip']);
        } catch (Throwable) {}
    }

    private function clearEmailAttempts(string $email): void
    {
        try {
            $this->db->prepare(
                "DELETE FROM login_attempts WHERE identifier = :email AND attempt_type = 'email'"
            )->execute(['email' => $email]);
        } catch (Throwable) {}
    }

    private function clientIp(): string
    {
        // X-Forwarded-For is caller-controlled and can be spoofed; this is an
        // acceptable trade-off for a basic rate limiter. Use REMOTE_ADDR only
        // if deploying without a trusted reverse proxy.
        $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($forwarded !== '') {
            return trim(explode(',', $forwarded)[0]);
        }
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }
}
