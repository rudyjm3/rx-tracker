<?php

declare(strict_types=1);

/**
 * One-time migration: creates the first user account and scopes all existing
 * data to that user. Run after 001_add_users.sql, before 002_add_user_id_to_tables.sql.
 *
 * Usage:
 *   php scripts/migrate_to_first_user.php
 *   php scripts/migrate_to_first_user.php --email=you@example.com --name="Your Name"
 */

require __DIR__ . '/../config/database.php';

$pdo = db();

// Guard: check if users table even exists
try {
    $check = $pdo->query('SELECT COUNT(*) FROM users');
} catch (Throwable) {
    fwrite(STDERR, "Error: users table not found. Run database/migrations/001_add_users.sql first.\n");
    exit(1);
}

// Guard: already migrated
if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
    fwrite(STDOUT, "Migration already complete — a user already exists.\n");
    exit(0);
}

// Parse CLI args
$opts = getopt('', ['email:', 'name:']);
$email = (string) ($opts['email'] ?? '');
$name  = (string) ($opts['name'] ?? '');

if ($email === '') {
    fwrite(STDOUT, "Enter the email address for the first user account: ");
    $email = trim((string) fgets(STDIN));
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Error: invalid email address.\n");
    exit(1);
}

fwrite(STDOUT, "Enter a password (min 8 characters): ");
// Hide input on unix terminals
$password = '';
if (stream_isatty(STDIN)) {
    system('stty -echo');
    $password = trim((string) fgets(STDIN));
    system('stty echo');
    fwrite(STDOUT, "\n");
} else {
    $password = trim((string) fgets(STDIN));
}

if (strlen($password) < 8) {
    fwrite(STDERR, "Error: password must be at least 8 characters.\n");
    exit(1);
}

if ($name === '') {
    fwrite(STDOUT, "Enter a display name (optional, press Enter to skip): ");
    $name = trim((string) fgets(STDIN));
}

// Insert first user
$stmt = $pdo->prepare(
    'INSERT INTO users (email, password_hash, display_name) VALUES (:email, :hash, :name)'
);
$stmt->execute([
    'email' => strtolower($email),
    'hash'  => password_hash($password, PASSWORD_BCRYPT),
    'name'  => $name !== '' ? $name : null,
]);
$userId = (int) $pdo->lastInsertId();
fwrite(STDOUT, "Created user id={$userId} ({$email})\n");

// Apply 002 migration
fwrite(STDOUT, "Applying 002_add_user_id_to_tables.sql...\n");
$sql002 = file_get_contents(__DIR__ . '/../database/migrations/002_add_user_id_to_tables.sql');
if ($sql002 === false) {
    fwrite(STDERR, "Error: could not read 002_add_user_id_to_tables.sql\n");
    exit(1);
}

// Filter out comment lines and empty lines, then split on semicolons
$statements = array_filter(
    array_map('trim', explode(';', $sql002)),
    static fn (string $s): bool => $s !== '' && !str_starts_with(ltrim($s), '--')
);

try {
    foreach ($statements as $statement) {
        if (trim($statement) === '') {
            continue;
        }
        $pdo->exec($statement);
        fwrite(STDOUT, "  OK: " . substr(trim($statement), 0, 60) . "...\n");
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Error applying migration: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Statement: " . substr($statement ?? '', 0, 120) . "\n");
    exit(1);
}

fwrite(STDOUT, "\nMigration complete! You can now log in with:\n");
fwrite(STDOUT, "  Email:    {$email}\n");
fwrite(STDOUT, "  Password: (the one you entered)\n");
