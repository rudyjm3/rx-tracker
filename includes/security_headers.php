<?php

declare(strict_types=1);

function send_security_headers(): void
{
    // HSTS: tell browsers to always use HTTPS for this domain for 1 year.
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

    // Prevent this app from being embedded in iframes (clickjacking protection).
    header('X-Frame-Options: DENY');

    // Prevent browsers from MIME-sniffing response content.
    header('X-Content-Type-Options: nosniff');

    // Limit Referer header to same-origin on cross-origin requests.
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // Content Security Policy.
    // 'unsafe-inline' on scripts is an interim allowance; audit and replace with
    // nonces before moving to public release.
    // Allows Google GSI (accounts.google.com) and Font Awesome CDN used on auth pages.
    header(
        "Content-Security-Policy: " .
        "default-src 'self'; " .
        "script-src 'self' 'unsafe-inline' https://accounts.google.com https://cdnjs.cloudflare.com; " .
        "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; " .
        "img-src 'self' data: https:; " .
        "font-src 'self' data: https://cdnjs.cloudflare.com; " .
        "connect-src 'self' https://accounts.google.com; " .
        "frame-src https://accounts.google.com"
    );
}
