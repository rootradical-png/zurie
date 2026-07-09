@echo off
cd /d "%~dp0"
type nul > "data\STOP_PING_DAEMON"
echo Arahan berhenti telah dihantar kepada ping daemon.
timeout /t 3 /nobreak >nul
