<?php

declare(strict_types=1);

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/MedicationRepository.php';
require __DIR__ . '/includes/PushNotificationService.php';
if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
}

// Auth middleware goes here when added.

$repository    = new MedicationRepository(db());
$error         = null;
$notice        = null;
$today         = today();
$currentTime   = (new DateTimeImmutable())->format('H:i');
$page          = (string) ($_GET['page'] ?? 'dashboard');
$requestAction = (string) ($_GET['action'] ?? '');
$jsonResponse  = false;

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $requestAction !== '') {
    require __DIR__ . '/routes/api.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require __DIR__ . '/routes/actions.php';
}

require __DIR__ . '/routes/pages.php';
