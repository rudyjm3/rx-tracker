<?php

declare(strict_types=1);

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/security_headers.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/SessionManager.php';
require __DIR__ . '/includes/AuthService.php';
require __DIR__ . '/includes/GoogleAuthService.php';
require __DIR__ . '/includes/MailService.php';
require __DIR__ . '/includes/MedicationRepository.php';
require __DIR__ . '/includes/FamilyProfileRepository.php';
require __DIR__ . '/includes/PushNotificationService.php';
require __DIR__ . '/includes/SideEffectRepository.php';
require __DIR__ . '/includes/PainChartRenderer.php';
require __DIR__ . '/includes/MoodChartRenderer.php';
require __DIR__ . '/includes/DoctorVisitReport.php';
require_once __DIR__ . '/includes/OnboardingService.php';
require_once __DIR__ . '/includes/InventoryEstimator.php';
if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
}

send_security_headers();

$page = (string) ($_GET['page'] ?? 'dashboard');

$sessionManager = new SessionManager(db());
$mail           = new MailService();
$auth           = new AuthService(db(), $sessionManager, $mail);
$googleAuth     = new GoogleAuthService(db(), $sessionManager, env_value('GOOGLE_CLIENT_ID', ''));

// Public auth routes — served before login check
$authPages = ['login', 'register', 'forgot-password', 'reset-password', 'terms', 'privacy', 'google-login', 'verify-email', 'resend-verification'];
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

if ($page === 'google-link') {
    require __DIR__ . '/routes/google_link.php';
    exit;
}

if ($page === 'google-unlink') {
    require __DIR__ . '/routes/google_unlink.php';
    exit;
}

if ($page === 'profile') {
    require __DIR__ . '/routes/profile.php';
    exit;
}

if ($page === 'family') {
    header('Location: index.php?page=profile');
    exit;
}

// Resolve active family profile for this session (validate ownership).
$familyRepo     = new FamilyProfileRepository(db());
$activeProfileId = $auth->activeProfileId();
if ($activeProfileId !== null) {
    $activeProfile = $familyRepo->findProfile($activeProfileId, $auth->currentUserId());
    if ($activeProfile === null) {
        $auth->setActiveProfile(null);
        $activeProfileId = null;
    }
}
$activeProfile   = $activeProfileId !== null ? ($activeProfile ?? null) : null;
$familyProfiles  = $familyRepo->profilesForUser($auth->currentUserId());

$repository    = new MedicationRepository(db(), $auth->currentUserId(), $activeProfileId);

// Redirect new users (no active medications + onboarding not completed) to the setup wizard
$bypassPages = ['onboarding', 'onboarding-actions', 'settings', 'profile', 'logout'];
if ($page === 'onboarding' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require __DIR__ . '/routes/onboarding_actions.php';
    exit;
}
$showResumeBanner = false;
if (!in_array($page, $bypassPages, true)) {
    $obService = new OnboardingService($repository);
    if (!$obService->isCompleted() && !$obService->isSkipped() && $repository->activeMedicationCount() === 0) {
        $obService->getOrCreateProgress();
        header('Location: index.php?page=onboarding');
        exit;
    }
    if ($obService->isSkipped() && $repository->activeMedicationCount() === 0) {
        $showResumeBanner = true;
    }
}

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
