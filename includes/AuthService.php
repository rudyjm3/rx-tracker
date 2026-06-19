<?php

declare(strict_types=1);

final class AuthService
{
    public function __construct(
        private readonly PDO $db,
        private readonly SessionManager $sessionManager
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

        return (int) $this->db->lastInsertId();
    }

    public function login(string $email, string $password, bool $remember = false): bool
    {
        $email = strtolower(trim($email));
        $stmt = $this->db->prepare(
            'SELECT id, password_hash FROM users WHERE email = :email LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!is_array($user) || !password_verify($password, (string) $user['password_hash'])) {
            return false;
        }

        $userId = (int) $user['id'];

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION['user_id'] = $userId;

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
            'SELECT id, email, display_name FROM users WHERE id = :id LIMIT 1'
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

    public function changePassword(int $userId, string $currentPassword, string $newPassword): bool
    {
        $stmt = $this->db->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        if (!is_array($row) || !password_verify($currentPassword, (string) $row['password_hash'])) {
            return false;
        }
        $this->db->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
            ->execute(['hash' => password_hash($newPassword, PASSWORD_BCRYPT), 'id' => $userId]);
        return true;
    }

    public function deleteAccount(int $userId): void
    {
        $this->db->prepare('DELETE FROM push_subscriptions WHERE user_id = :uid')->execute(['uid' => $userId]);
        $this->db->prepare('DELETE FROM medication_groups WHERE user_id = :uid')->execute(['uid' => $userId]);
        $this->db->prepare('DELETE FROM medications WHERE user_id = :uid')->execute(['uid' => $userId]);
        $this->db->prepare('DELETE FROM users WHERE id = :uid')->execute(['uid' => $userId]);
        $this->logout();
    }
}
