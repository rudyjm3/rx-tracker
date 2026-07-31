<?php

declare(strict_types=1);

require __DIR__ . '/../includes/SessionManager.php';
require __DIR__ . '/../includes/GoogleAuthService.php';

function assertTrue_(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Regression test: GoogleAuthService::publicKeyForKid() must not hit the
 * network on every sign-in. These exercise the private disk-cache helpers
 * directly via reflection (no real network call), since the JWKS endpoint
 * itself isn't reachable/mockable from this offline test.
 */

$db = new PDO('sqlite::memory:');
$sessionManager = new SessionManager($db);
$service = new GoogleAuthService($db, $sessionManager, 'test-client-id');

$reflection = new ReflectionClass($service);

function invokePrivate(ReflectionClass $reflection, object $instance, string $method, array $args = []): mixed
{
    $m = $reflection->getMethod($method);
    $m->setAccessible(true);
    return $m->invokeArgs($instance, $args);
}

$cacheFile = sys_get_temp_dir() . '/jwks_cache_test_' . bin2hex(random_bytes(6)) . '.json';
$sampleJwks = ['keys' => [['kid' => 'abc123', 'n' => 'nval', 'e' => 'AQAB']]];

// A fresh, non-expired cache entry should be returned as-is.
file_put_contents($cacheFile, json_encode(['expires_at' => time() + 3600, 'jwks' => $sampleJwks]));
$fresh = invokePrivate($reflection, $service, 'readJwksCache', [$cacheFile, false]);
assertTrue_(is_array($fresh) && $fresh === $sampleJwks, 'A non-expired cache entry should be returned by readJwksCache().');

// An expired entry must not be returned when honoring expiry.
file_put_contents($cacheFile, json_encode(['expires_at' => time() - 10, 'jwks' => $sampleJwks]));
$expired = invokePrivate($reflection, $service, 'readJwksCache', [$cacheFile, false]);
assertTrue_($expired === null, 'An expired cache entry must not be returned when honoring expiry.');

// The same expired entry is usable as a stale fallback when told to ignore expiry.
$stale = invokePrivate($reflection, $service, 'readJwksCache', [$cacheFile, true]);
assertTrue_(is_array($stale) && $stale === $sampleJwks, 'An expired cache entry should still be usable as a stale fallback.');

// A missing cache file must not error, just report no cache.
$missing = invokePrivate($reflection, $service, 'readJwksCache', [$cacheFile . '.does-not-exist', false]);
assertTrue_($missing === null, 'A missing cache file should yield null, not throw.');

// TTL is parsed from Cache-Control max-age and clamped to a sane range.
$ttlFromHeader = invokePrivate($reflection, $service, 'jwksCacheTtl', [['HTTP/1.1 200 OK', 'Cache-Control: public, max-age=21600, must-revalidate']]);
assertTrue_($ttlFromHeader === 21600, 'jwksCacheTtl() should parse max-age from Cache-Control header.');

$ttlClampedLow = invokePrivate($reflection, $service, 'jwksCacheTtl', [['Cache-Control: max-age=10']]);
assertTrue_($ttlClampedLow === 300, 'jwksCacheTtl() should clamp very small max-age values up to a 300s floor.');

$ttlClampedHigh = invokePrivate($reflection, $service, 'jwksCacheTtl', [['Cache-Control: max-age=999999']]);
assertTrue_($ttlClampedHigh === 86400, 'jwksCacheTtl() should clamp very large max-age values down to an 86400s ceiling.');

$ttlDefault = invokePrivate($reflection, $service, 'jwksCacheTtl', [[]]);
assertTrue_($ttlDefault === 3600, 'jwksCacheTtl() should fall back to the default TTL when no Cache-Control header is present.');

unlink($cacheFile);

// The cache must live in an app-owned, non-world-writable directory — not the
// shared system temp dir, where another local account on a shared host could
// pre-plant a forged JWKS at a predictable path (P1 finding from PR #63 review).
$resolvedPath = invokePrivate($reflection, $service, 'jwksCacheFilePath');
assertTrue_(!str_starts_with($resolvedPath, sys_get_temp_dir()), 'JWKS cache file must not live under the shared system temp directory.');
$resolvedDir = dirname($resolvedPath);
assertTrue_(is_dir($resolvedDir), 'jwksCacheFilePath() should create its cache directory.');
$dirPerms = fileperms($resolvedDir) & 0777;
assertTrue_($dirPerms === 0700, "JWKS cache directory should be 0700, got " . decoct($dirPerms) . '.');
if (is_file($resolvedPath)) {
    unlink($resolvedPath);
}
rmdir($resolvedDir);

// A symlinked cache path must be refused outright, even if it points at an
// otherwise well-formed, non-expired cache document.
$symlinkTarget = sys_get_temp_dir() . '/jwks_cache_test_target_' . bin2hex(random_bytes(6)) . '.json';
$symlinkPath = sys_get_temp_dir() . '/jwks_cache_test_link_' . bin2hex(random_bytes(6)) . '.json';
file_put_contents($symlinkTarget, json_encode(['expires_at' => time() + 3600, 'jwks' => $sampleJwks]));
symlink($symlinkTarget, $symlinkPath);
$viaSymlink = invokePrivate($reflection, $service, 'readJwksCache', [$symlinkPath, false]);
assertTrue_($viaSymlink === null, 'readJwksCache() must refuse to follow a symlinked cache path.');
unlink($symlinkPath);
unlink($symlinkTarget);

echo "GoogleAuthServiceJwksCacheTest passed: JWKS disk cache read/expiry/TTL logic behaves correctly.\n";
