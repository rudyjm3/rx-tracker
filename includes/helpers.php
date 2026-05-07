<?php

declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect_home(): never
{
    header('Location: index.php');
    exit;
}

function post_string(string $key): string
{
    return trim((string) ($_POST[$key] ?? ''));
}

function today(): string
{
    return (new DateTimeImmutable('now'))->format('Y-m-d');
}
