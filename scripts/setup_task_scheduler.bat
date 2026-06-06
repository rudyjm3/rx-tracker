@echo off
:: ---------------------------------------------------------------
:: RxTracker - Windows Task Scheduler setup
:: Registers a task that runs send_due_push.php every minute.
::
:: Run this script ONCE as Administrator:
::   Right-click setup_task_scheduler.bat -> Run as administrator
::
:: To remove the task later:
::   schtasks /Delete /TN "RxTracker_PushReminders" /F
:: ---------------------------------------------------------------

set TASK_NAME=RxTracker_PushReminders
set PHP_EXE=C:\xampp\php\php.exe
set WRAPPER=%~dp0run_push_cron.bat

:: Verify php.exe exists at the expected path
if not exist "%PHP_EXE%" (
    echo ERROR: php.exe not found at %PHP_EXE%
    echo Edit PHP_EXE in this script to point to your php.exe location.
    pause
    exit /b 1
)

echo Registering task: %TASK_NAME%
echo Wrapper: %WRAPPER%
echo Log:    %~dp0push_cron.log
echo.

schtasks /Create ^
  /TN "%TASK_NAME%" ^
  /TR "%WRAPPER%" ^
  /SC MINUTE ^
  /MO 1 ^
  /RU SYSTEM ^
  /F

if %ERRORLEVEL% == 0 (
    echo.
    echo Task registered successfully. It will fire every minute while Windows is running.
    echo Check %LOG% to confirm pushes are being sent.
) else (
    echo.
    echo ERROR: schtasks failed. Make sure you are running as Administrator.
)

pause
