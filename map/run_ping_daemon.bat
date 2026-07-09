@echo off
setlocal
cd /d "%~dp0"
echo ZURIE Network Map Ping Daemon
 echo Tutup tetingkap ini untuk berhenti.
echo.
"C:\xampp_baru\php\php.exe" "worker\ping_daemon.php"
pause
endlocal
