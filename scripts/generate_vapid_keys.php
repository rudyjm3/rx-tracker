<?php

declare(strict_types=1);

/**
 * One-time VAPID key generation script.
 *
 * Run once from the project root:
 *   php scripts/generate_vapid_keys.php
 *
 * Copy the printed lines into your .env file, then restart Apache
 * (or re-run the cron script) so the new vars are picked up.
 */

// Windows/XAMPP note: OpenSSL reads OPENSSL_CONF from the process environment
// before PHP starts, so putenv() here cannot fix it. Use generate_vapid_keys.bat
// instead — it sets OPENSSL_CONF=C:\xampp\apache\conf\openssl.cnf before launching PHP.

$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php not found. Run: composer install\n");
    exit(1);
}

require $autoload;

if (!class_exists(\Minishlink\WebPush\VAPID::class)) {
    fwrite(STDERR, "minishlink/web-push not installed. Run: composer require minishlink/web-push\n");
    exit(1);
}

$keys = \Minishlink\WebPush\VAPID::createVapidKeys();

echo "\n";
echo "# Paste these lines into your .env file:\n";
echo "PUSH_VAPID_PUBLIC_KEY={$keys['publicKey']}\n";
echo "PUSH_VAPID_PRIVATE_KEY={$keys['privateKey']}\n";
echo "PUSH_VAPID_SUBJECT=mailto:you@example.com\n";
echo "\n";
echo "Done. Keep the private key secret — never commit it.\n";
