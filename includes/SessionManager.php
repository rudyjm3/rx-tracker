<?php

declare(strict_types=1);

final class SessionManager
{
    private const COOKIE_NAME = 'rx_remember';
    private const COOKIE_DAYS = 30;

    public function __construct(private readonly PDO $db) {}

    public function issueSession(int $userId, bool $remember): void
    {
        if (!$remember) {
            return;
        }

        $token   = bin2hex(random_bytes(32));
        $expires = new DateTimeImmutable('+' . self::COOKIE_DAYS . ' days');

        $stmt = $this->db->prepare(
            'INSERT INTO user_sessions (user_id, session_token, user_agent, ip_address, expires_at)
             VALUES (:user_id, :token, :user_agent, :ip, :expires_at)'
        );
        $stmt->execute([
            'user_id'    => $userId,
            'token'      => $token,
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'ip'         => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'expires_at' => $expires->format('Y-m-d H:i:s'),
        ]);

        setcookie(self::COOKIE_NAME, $token, [
            'expires'  => $expires->getTimestamp(),
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    public function resolveFromCookie(): ?int
    {
        $token = (string) ($_COOKIE[self::COOKIE_NAME] ?? '');
        if ($token === '') {
            return null;
        }

        $stmt = $this->db->prepare(
            'SELECT id, user_id, expires_at FROM user_sessions
             WHERE session_token = :token LIMIT 1'
        );
        $stmt->execute(['token' => $token]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return null;
        }

        if (new DateTimeImmutable($row['expires_at']) < new DateTimeImmutable('now')) {
            $this->deleteSessionById((int) $row['id']);
            return null;
        }

        $newToken   = bin2hex(random_bytes(32));
        $newExpires = new DateTimeImmutable('+' . self::COOKIE_DAYS . ' days');

        $this->db->prepare(
            'UPDATE user_sessions SET session_token = :new_token, expires_at = :expires_at WHERE id = :id'
        )->execute([
            'new_token'  => $newToken,
            'expires_at' => $newExpires->format('Y-m-d H:i:s'),
            'id'         => (int) $row['id'],
        ]);

        setcookie(self::COOKIE_NAME, $newToken, [
            'expires'  => $newExpires->getTimestamp(),
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        return (int) $row['user_id'];
    }

    public function destroySession(int $userId): void
    {
        $token = (string) ($_COOKIE[self::COOKIE_NAME] ?? '');
        if ($token !== '') {
            $this->db->prepare(
                'DELETE FROM user_sessions WHERE session_token = :token'
            )->execute(['token' => $token]);
        }

        $this->db->prepare(
            'DELETE FROM user_sessions WHERE user_id = :user_id AND expires_at < NOW()'
        )->execute(['user_id' => $userId]);

        setcookie(self::COOKIE_NAME, '', [
            'expires'  => time() - 86400,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    private function deleteSessionById(int $id): void
    {
        $this->db->prepare('DELETE FROM user_sessions WHERE id = :id')->execute(['id' => $id]);
    }
}
