<?php

declare(strict_types=1);

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/SessionManager.php';
require __DIR__ . '/includes/AuthService.php';
require __DIR__ . '/includes/MailService.php';
require __DIR__ . '/includes/MedicationRepository.php';
require __DIR__ . '/includes/PushNotificationService.php';
if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
}

$page = (string) ($_GET['page'] ?? 'dashboard');

$auth = new AuthService(db(), new SessionManager(db()));

// Public auth routes — served before login check
$authPages = ['login', 'register', 'forgot-password', 'reset-password'];
if (in_array($page, $authPages, true)) {
    $routeFile = __DIR__ . '/routes/' . str_replace('-', '_', $page) . '.php';
    require $routeFile;
    exit;
}

if ($page === 'logout') {
    require __DIR__ . '/routes/logout.php';
    exit;
}

$auth->requireLogin();

if ($page === 'profile') {
    require __DIR__ . '/routes/profile.php';
    exit;
}

$repository    = new MedicationRepository(db(), $auth->currentUserId());
$error         = null;
$notice        = null;
$today         = today();
$currentTime   = (new DateTimeImmutable())->format('H:i');
$requestAction = (string) ($_GET['action'] ?? '');
$jsonResponse  = false;

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $requestAction !== '') {
    require __DIR__ . '/routes/api.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require __DIR__ . '/routes/actions.php';
}

require __DIR__ . '/routes/pages.php';
