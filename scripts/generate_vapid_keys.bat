@echo off
:: Generates VAPID keys for Web Push.
:: Run this once: double-click, or run from any terminal.
:: Copy the printed lines into your .env file.

set PHP_EXE=C:\xampp\php\php.exe
set OPENSSL_CONF=C:\xampp\apache\conf\openssl.cnf
set SCRIPT=%~dp0generate_vapid_keys.php

if not exist "%PHP_EXE%" (
    echo ERROR: php.exe not found at %PHP_EXE%
    echo Edit PHP_EXE in this script to match your PHP install path.
    pause
    exit /b 1
)

"%PHP_EXE%" "%SCRIPT%"
pause
