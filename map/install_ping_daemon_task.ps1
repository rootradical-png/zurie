# Jalankan PowerShell ini sebagai Administrator.
$ErrorActionPreference = 'Stop'

$TaskName = 'ZURIE Network Map Ping Daemon'
$PhpExe = 'C:\xampp_baru\php\php.exe'
$MapDir = 'C:\xampp_baru\htdocs\zurie\map'
$Daemon = Join-Path $MapDir 'worker\ping_daemon.php'

if (-not (Test-Path $PhpExe)) {
    throw "PHP tidak ditemui: $PhpExe"
}
if (-not (Test-Path $Daemon)) {
    throw "Daemon tidak ditemui: $Daemon"
}

$Action = New-ScheduledTaskAction `
    -Execute $PhpExe `
    -Argument ('"' + $Daemon + '"') `
    -WorkingDirectory $MapDir

$Trigger = New-ScheduledTaskTrigger -AtStartup
$Principal = New-ScheduledTaskPrincipal `
    -UserId 'SYSTEM' `
    -LogonType ServiceAccount `
    -RunLevel Highest

$Settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -RestartCount 999 `
    -RestartInterval (New-TimeSpan -Minutes 1) `
    -ExecutionTimeLimit (New-TimeSpan -Days 3650) `
    -MultipleInstances IgnoreNew

Register-ScheduledTask `
    -TaskName $TaskName `
    -Action $Action `
    -Trigger $Trigger `
    -Principal $Principal `
    -Settings $Settings `
    -Description 'Ping peranti ZURIE Network Map secara berterusan.' `
    -Force | Out-Null

Start-ScheduledTask -TaskName $TaskName
Write-Host "SIAP: Task '$TaskName' telah dipasang dan dimulakan." -ForegroundColor Green
Write-Host "Semak status di Task Scheduler atau buka /zurie/map dan lihat LAST UPDATE."
