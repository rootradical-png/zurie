# Run as Administrator selepas server_metrics_agent.json telah disediakan.
$ErrorActionPreference = 'Stop'
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$Agent = Join-Path $ScriptDir 'server_metrics_agent.ps1'
$Config = Join-Path $ScriptDir 'server_metrics_agent.json'
if (!(Test-Path $Agent)) { throw "Agent tidak ditemui: $Agent" }
if (!(Test-Path $Config)) { throw "Config tidak ditemui: $Config" }

# Lindungi token supaya hanya SYSTEM dan Administrators boleh baca.
& icacls.exe $Config /inheritance:r /grant:r '*S-1-5-18:F' '*S-1-5-32-544:F' | Out-Null

$taskName = 'Zurie NOC Server Metrics'
$command = "powershell.exe -NoProfile -ExecutionPolicy Bypass -File `"$Agent`""
& schtasks.exe /Create /TN $taskName /SC MINUTE /MO 1 /TR $command /RU SYSTEM /F | Out-Host
& schtasks.exe /Run /TN $taskName | Out-Host
Write-Host "Task dipasang: $taskName"
Write-Host "Semak log Task Scheduler jika data belum muncul selepas 2 minit."
