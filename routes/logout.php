<?php

declare(strict_types=1);

/** @var AuthService $auth */

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token((string) ($_POST['csrf_token'] ?? ''))) {
    header('Location: index.php');
    exit;
}

$auth->logout();
header('Location: index.php?page=login');
exit;
