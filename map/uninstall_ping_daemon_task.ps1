# Jalankan PowerShell ini sebagai Administrator.
$TaskName = 'ZURIE Network Map Ping Daemon'
Stop-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue
Write-Host "Task '$TaskName' telah dibuang." -ForegroundColor Yellow
