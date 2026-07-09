# Personal NOC Server Metrics Agent - Windows
# Simpan config sebagai server_metrics_agent.json dalam folder yang sama.
$ErrorActionPreference = 'Stop'
$AgentVersion = '1.1.0-win'
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ConfigPath = Join-Path $ScriptDir 'server_metrics_agent.json'

if (!(Test-Path $ConfigPath)) { throw "Config tidak ditemui: $ConfigPath" }
$config = Get-Content $ConfigPath -Raw | ConvertFrom-Json
if ([string]::IsNullOrWhiteSpace($config.device_id) -or [string]::IsNullOrWhiteSpace($config.token) -or [string]::IsNullOrWhiteSpace($config.push_url)) {
    throw 'device_id, token dan push_url wajib diisi.'
}

function Get-WmiCompat([string]$ClassName, [string]$Filter = '') {
    if (Get-Command Get-CimInstance -ErrorAction SilentlyContinue) {
        if ($Filter) { return Get-CimInstance -ClassName $ClassName -Filter $Filter }
        return Get-CimInstance -ClassName $ClassName
    }
    if ($Filter) { return Get-WmiObject -Class $ClassName -Filter $Filter }
    return Get-WmiObject -Class $ClassName
}

$processors = @(Get-WmiCompat 'Win32_Processor')
$cpu = if ($processors.Count) { [math]::Round((($processors | Measure-Object -Property LoadPercentage -Average).Average), 2) } else { $null }
$os = Get-WmiCompat 'Win32_OperatingSystem'
$totalMb = [math]::Round([double]$os.TotalVisibleMemorySize / 1024, 0)
$freeMb = [math]::Round([double]$os.FreePhysicalMemory / 1024, 0)
$usedMb = [math]::Max(0, $totalMb - $freeMb)
$memoryPct = if ($totalMb -gt 0) { [math]::Round(($usedMb / $totalMb) * 100, 2) } else { $null }

$disks = @()
foreach ($disk in @(Get-WmiCompat 'Win32_LogicalDisk' 'DriveType=3')) {
    if (-not $disk.Size) { continue }
    $totalGb = [math]::Round([double]$disk.Size / 1GB, 2)
    $freeGb = [math]::Round([double]$disk.FreeSpace / 1GB, 2)
    $usedGb = [math]::Round($totalGb - $freeGb, 2)
    $percent = if ($totalGb -gt 0) { [math]::Round(($usedGb / $totalGb) * 100, 2) } else { 0 }
    $disks += [ordered]@{ name = [string]$disk.DeviceID; mount = [string]$disk.DeviceID; filesystem = [string]$disk.FileSystem; total_gb = $totalGb; used_gb = $usedGb; free_gb = $freeGb; percent = $percent }
}

$boot = if ($os.LastBootUpTime -is [datetime]) { [datetime]$os.LastBootUpTime } else { [Management.ManagementDateTimeConverter]::ToDateTime([string]$os.LastBootUpTime) }
$uptime = [math]::Max(0, [int64]((Get-Date) - $boot).TotalSeconds)

$services = @()
foreach ($name in @($config.services)) {
    if ([string]::IsNullOrWhiteSpace([string]$name)) { continue }
    $svc = Get-Service -Name ([string]$name) -ErrorAction SilentlyContinue
    $services += [ordered]@{
        name = "Service: $([string]$name)"
        status = if ($svc) { [string]$svc.Status } else { 'NOT_FOUND' }
    }
}

# XAMPP lazimnya menjalankan Apache/MariaDB sebagai proses biasa, bukan Windows Service.
# Config contoh: "processes": ["httpd", "mysqld"]
foreach ($configuredName in @($config.processes)) {
    $rawName = [string]$configuredName
    if ([string]::IsNullOrWhiteSpace($rawName)) { continue }

    $processName = [System.IO.Path]::GetFileNameWithoutExtension($rawName.Trim())
    $matched = @(Get-Process -Name $processName -ErrorAction SilentlyContinue)

    if ($matched.Count -gt 0) {
        $paths = @($matched | ForEach-Object {
            try { $_.Path } catch { $null }
        } | Where-Object { -not [string]::IsNullOrWhiteSpace([string]$_) } | Select-Object -Unique)

        $displayName = if ($processName -ieq 'httpd') {
            'Apache XAMPP (httpd.exe)'
        } elseif ($processName -ieq 'mysqld' -or $processName -ieq 'mariadbd') {
            'MariaDB XAMPP (' + $processName + '.exe)'
        } else {
            'Process: ' + $processName + '.exe'
        }

        $services += [ordered]@{
            name = $displayName
            status = 'RUNNING x' + $matched.Count
        }
    } else {
        $services += [ordered]@{
            name = 'Process: ' + $processName + '.exe'
            status = 'NOT_FOUND'
        }
    }
}

$payload = [ordered]@{
    device_id = [string]$config.device_id
    hostname = $env:COMPUTERNAME
    os_name = [string]$os.Caption
    agent_version = $AgentVersion
    collected_at = (Get-Date).ToString('o')
    cpu_percent = $cpu
    memory = [ordered]@{ total_mb = [int64]$totalMb; used_mb = [int64]$usedMb; free_mb = [int64]$freeMb; percent = $memoryPct }
    disks = $disks
    uptime_seconds = $uptime
    load = @{}
    services = $services
}

$headers = @{ Authorization = "Bearer $($config.token)" }
$timeout = if ($config.timeout_seconds) { [int]$config.timeout_seconds } else { 20 }
$json = $payload | ConvertTo-Json -Depth 8 -Compress
$result = Invoke-RestMethod -Uri ([string]$config.push_url) -Method Post -Headers $headers -Body $json -ContentType 'application/json; charset=utf-8' -TimeoutSec $timeout
if (-not $result.ok) { throw "Push gagal: $($result.error)" }
Write-Output "OK $($result.device_id) $($result.received_at)"
