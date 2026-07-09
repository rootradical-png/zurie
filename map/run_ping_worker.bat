@echo off
setlocal
cd /d "%~dp0"
"C:\xampp_baru\php\php.exe" "worker\ping_worker.php"
endlocal
