<?php

declare(strict_types=1);

final class GoogleAuthService
{
    private const ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];
    private const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';
    private const JWKS_CACHE_DIR = __DIR__ . '/../storage/cache';
    private const JWKS_CACHE_FILE = 'google_jwks_cache.json';
    private const JWKS_DEFAULT_TTL = 3600;

    /** @var array<string, mixed>|null In-process cache so one request never fetches twice. */
    private ?array $jwksMemoCache = null;

    public function __construct(
        private readonly PDO $db,
        private readonly SessionManager $sessionManager,
        private readonly string $clientId
    ) {}

    public function isConfigured(): bool
    {
        return trim($this->clientId) !== '';
    }

    public function authenticate(string $credential): array
    {
        $claims = $this->verifyIdToken($credential);
        $googleId = (string) ($claims['sub'] ?? '');
        $email = strtolower(trim((string) ($claims['email'] ?? '')));
        if ($googleId === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Google did not return a usable account email.');
        }
        if (($claims['email_verified'] ?? false) !== true && (string) ($claims['email_verified'] ?? '') !== 'true') {
            throw new RuntimeException('Please verify your Google email address before signing in.');
        }

        $name = trim((string) ($claims['name'] ?? '')) ?: null;
        $picture = trim((string) ($claims['picture'] ?? '')) ?: null;

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT id, email, google_id FROM users WHERE google_id = :google_id LIMIT 1');
            $stmt->execute(['google_id' => $googleId]);
            $user = $stmt->fetch();

            if (!is_array($user)) {
                $stmt = $this->db->prepare('SELECT id, email, google_id, display_name FROM users WHERE email = :email LIMIT 1');
                $stmt->execute(['email' => $email]);
                $user = $stmt->fetch();
            }

            if (is_array($user)) {
                $userId = (int) $user['id'];
                $this->db->prepare(
                    'UPDATE users
                     SET google_id = COALESCE(google_id, :google_id),
                         profile_picture = COALESCE(:picture, profile_picture),
                         email_verified = 1,
                         last_login = NOW()
                     WHERE id = :id'
                )->execute(['google_id' => $googleId, 'picture' => $picture, 'id' => $userId]);
            } else {
                $stmt = $this->db->prepare(
                    'INSERT INTO users (email, password_hash, display_name, google_id, profile_picture, email_verified, last_login)
                     VALUES (:email, NULL, :display_name, :google_id, :picture, 1, NOW())'
                );
                $stmt->execute([
                    'email' => $email,
                    'display_name' => $name,
                    'google_id' => $googleId,
                    'picture' => $picture,
                ]);
                $userId = (int) $this->db->lastInsertId();
            }

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $this->startSession($userId);

        return ['user_id' => $userId, 'redirect' => 'index.php'];
    }

    public function linkForUser(int $userId, string $credential): void
    {
        $claims = $this->verifyIdToken($credential);
        $googleId = (string) ($claims['sub'] ?? '');
        $email = strtolower(trim((string) ($claims['email'] ?? '')));
        if ($googleId === '' || $email === '') {
            throw new RuntimeException('Google did not return a usable account.');
        }

        $stmt = $this->db->prepare('SELECT id FROM users WHERE google_id = :google_id AND id <> :id LIMIT 1');
        $stmt->execute(['google_id' => $googleId, 'id' => $userId]);
        if ($stmt->fetchColumn() !== false) {
            throw new RuntimeException('That Google account is already connected to another RxTracker account.');
        }

        $stmt = $this->db->prepare('SELECT email FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $currentEmail = strtolower((string) $stmt->fetchColumn());
        if ($currentEmail !== $email) {
            throw new RuntimeException('Please use the Google account with the same email address as this RxTracker account.');
        }

        $picture = trim((string) ($claims['picture'] ?? '')) ?: null;
        $this->db->prepare('UPDATE users SET google_id = :google_id, profile_picture = COALESCE(:picture, profile_picture), email_verified = 1 WHERE id = :id')
            ->execute(['google_id' => $googleId, 'picture' => $picture, 'id' => $userId]);
    }

    public function unlinkForUser(int $userId): void
    {
        $stmt = $this->db->prepare('SELECT password_hash, google_id FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();
        if (!is_array($user) || empty($user['google_id'])) {
            return;
        }
        if (empty($user['password_hash'])) {
            throw new RuntimeException('Create a password before disconnecting Google sign-in.');
        }
        $this->db->prepare('UPDATE users SET google_id = NULL WHERE id = :id')->execute(['id' => $userId]);
    }

    private function startSession(int $userId): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        session_regenerate_id(true);
        $stmt = $this->db->prepare('SELECT email, display_name FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch() ?: [];
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_name'] = (string) ($user['display_name'] ?? '');
        $_SESSION['email'] = (string) ($user['email'] ?? '');
        $_SESSION['logged_in'] = true;
        $this->sessionManager->issueSession($userId, false);
    }

    private function verifyIdToken(string $jwt): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Google sign-in is not configured.');
        }
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new RuntimeException('Invalid Google token.');
        }
        $header = $this->jsonDecode($this->base64UrlDecode($parts[0]));
        $payload = $this->jsonDecode($this->base64UrlDecode($parts[1]));
        if (($header['alg'] ?? '') !== 'RS256' || empty($header['kid'])) {
            throw new RuntimeException('Unsupported Google token signature.');
        }
        $pem = $this->publicKeyForKid((string) $header['kid']);
        $signed = $parts[0] . '.' . $parts[1];
        $signature = $this->base64UrlDecode($parts[2]);
        if (openssl_verify($signed, $signature, $pem, OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('Invalid Google token signature.');
        }
        if (!in_array((string) ($payload['iss'] ?? ''), self::ISSUERS, true)) {
            throw new RuntimeException('Invalid Google token issuer.');
        }
        if ((string) ($payload['aud'] ?? '') !== $this->clientId) {
            throw new RuntimeException('Invalid Google token audience.');
        }
        $now = time();
        if ((int) ($payload['exp'] ?? 0) < $now) {
            throw new RuntimeException('Your Google sign-in expired. Please try again.');
        }
        // Reject tokens that are not yet valid / issued in the future (small clock
        // skew leeway), so a token minted for later use can't be replayed early.
        $leeway = 60;
        if (isset($payload['nbf']) && (int) $payload['nbf'] > $now + $leeway) {
            throw new RuntimeException('Google token is not yet valid.');
        }
        if (isset($payload['iat']) && (int) $payload['iat'] > $now + $leeway) {
            throw new RuntimeException('Invalid Google token issue time.');
        }
        return $payload;
    }

    private function publicKeyForKid(string $kid): string
    {
        $key = $this->findJwk($this->jwks(false), $kid);
        if ($key === null) {
            // Not in the cached set — Google rotates keys, so force a fresh
            // fetch once before giving up rather than trusting a stale cache.
            $key = $this->findJwk($this->jwks(true), $kid);
        }
        if ($key === null) {
            throw new RuntimeException('Google signing key was not found.');
        }
        return $this->jwkToPem((string) $key['n'], (string) $key['e']);
    }

    private function findJwk(array $jwks, string $kid): ?array
    {
        foreach (($jwks['keys'] ?? []) as $key) {
            if (($key['kid'] ?? '') === $kid && isset($key['n'], $key['e'])) {
                return $key;
            }
        }
        return null;
    }

    /**
     * Returns Google's JWKS document, cached on disk between requests (keyed
     * by the Cache-Control max-age Google sends, capped to a sane range) so
     * a plain file_get_contents() doesn't hit the network on every sign-in.
     */
    private function jwks(bool $forceRefresh): array
    {
        if (!$forceRefresh && $this->jwksMemoCache !== null) {
            return $this->jwksMemoCache;
        }

        $cacheFile = $this->jwksCacheFilePath();

        if (!$forceRefresh) {
            $cached = $this->readJwksCache($cacheFile);
            if ($cached !== null) {
                $this->jwksMemoCache = $cached;
                return $cached;
            }
        }

        $context = stream_context_create(['http' => ['timeout' => 5]]);
        $json = @file_get_contents(self::JWKS_URL, false, $context);
        if ($json === false) {
            // Network hiccup: fall back to a stale cache rather than failing
            // sign-in outright if we have one, however old.
            $stale = $this->readJwksCache($cacheFile, true);
            if ($stale !== null) {
                $this->jwksMemoCache = $stale;
                return $stale;
            }
            throw new RuntimeException('Unable to verify Google sign-in right now.');
        }

        $jwks = $this->jsonDecode($json);
        $ttl = $this->jwksCacheTtl($http_response_header ?? []);
        $written = @file_put_contents(
            $cacheFile,
            json_encode(['expires_at' => time() + $ttl, 'jwks' => $jwks], JSON_THROW_ON_ERROR),
            LOCK_EX
        );
        if ($written !== false) {
            // Belt-and-suspenders on top of the 0700 cache directory: keep the
            // file itself unreadable/unwritable by anyone but the app.
            @chmod($cacheFile, 0600);
        }
        $this->jwksMemoCache = $jwks;

        return $jwks;
    }

    /**
     * Resolves the JWKS cache file path, creating an app-owned, 0700
     * directory for it on first use. This must NOT live in the shared system
     * temp directory: on a multi-tenant host, another local account could
     * pre-create a predictable cache path there with an attacker-controlled
     * key and a far-future expiry, and we'd trust it as genuine.
     */
    private function jwksCacheFilePath(): string
    {
        if (!is_dir(self::JWKS_CACHE_DIR)) {
            @mkdir(self::JWKS_CACHE_DIR, 0700, true);
        }
        @chmod(self::JWKS_CACHE_DIR, 0700);

        return self::JWKS_CACHE_DIR . '/' . self::JWKS_CACHE_FILE;
    }

    private function readJwksCache(string $cacheFile, bool $ignoreExpiry = false): ?array
    {
        // Refuse a symlink outright — following one out of our app-owned
        // cache directory would reintroduce the same untrusted-file risk.
        if (is_link($cacheFile)) {
            return null;
        }
        $raw = @file_get_contents($cacheFile);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['jwks']) || !is_array($decoded['jwks'])) {
            return null;
        }
        if (!$ignoreExpiry && (int) ($decoded['expires_at'] ?? 0) <= time()) {
            return null;
        }
        return $decoded['jwks'];
    }

    private function jwksCacheTtl(array $responseHeaders): int
    {
        foreach ($responseHeaders as $header) {
            if (stripos($header, 'cache-control:') === 0 && preg_match('/max-age=(\d+)/i', $header, $matches)) {
                return max(300, min(86400, (int) $matches[1]));
            }
        }
        return self::JWKS_DEFAULT_TTL;
    }

    private function jwkToPem(string $n, string $e): string
    {
        $modulus = $this->base64UrlDecode($n);
        $exponent = $this->base64UrlDecode($e);
        $components = $this->asn1Sequence($this->asn1Integer($modulus) . $this->asn1Integer($exponent));
        $bitString = "\x00" . $components;
        $publicKey = $this->asn1Sequence($this->asn1Sequence($this->asn1Oid('1.2.840.113549.1.1.1') . "\x05\x00") . "\x03" . $this->asn1Length(strlen($bitString)) . $bitString);
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($publicKey), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private function asn1Integer(string $value): string
    {
        if (ord($value[0]) > 0x7f) {
            $value = "\x00" . $value;
        }
        return "\x02" . $this->asn1Length(strlen($value)) . $value;
    }

    private function asn1Sequence(string $value): string { return "\x30" . $this->asn1Length(strlen($value)) . $value; }

    private function asn1Oid(string $oid): string
    {
        $parts = array_map('intval', explode('.', $oid));
        $bytes = chr(40 * $parts[0] + $parts[1]);
        for ($i = 2; $i < count($parts); $i++) {
            $v = $parts[$i];
            $stack = [chr($v & 0x7f)];
            while ($v >>= 7) { array_unshift($stack, chr(($v & 0x7f) | 0x80)); }
            $bytes .= implode('', $stack);
        }
        return "\x06" . $this->asn1Length(strlen($bytes)) . $bytes;
    }

    private function asn1Length(int $len): string
    {
        if ($len < 128) { return chr($len); }
        $bytes = '';
        while ($len > 0) { $bytes = chr($len & 0xff) . $bytes; $len >>= 8; }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        if ($decoded === false) { throw new RuntimeException('Invalid Google token encoding.'); }
        return $decoded;
    }

    private function jsonDecode(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) { throw new RuntimeException('Invalid Google token payload.'); }
        return $data;
    }
}
