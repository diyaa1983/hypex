# تثبيت مهمة Windows: مزامنة عملاء Oracle كل 5 دقائق
# شغّل PowerShell كمسؤول:
#   Set-ExecutionPolicy Bypass -Scope Process -Force
#   cd C:\xampp\htdocs\Hypex\deploy\oracle-agent
#   .\install-customers-sync-task.ps1

param(
  [int] $Minutes = 5,
  [string] $TaskName = "Hypex-Oracle-Customers-Sync"
)

$ErrorActionPreference = "Stop"
$root = (Resolve-Path (Join-Path $PSScriptRoot "..\..")).Path
$php = "C:\xampp\php\php.exe"
$phpIni = "C:\xampp\php\php.ini"
$script = Join-Path $root "tools\oracle_customers_auto_sync_run.php"
$phpArgs = if (Test-Path -LiteralPath $phpIni) {
  "-c `"$phpIni`" `"$script`""
} else {
  "`"$script`""
}

if (-not (Test-Path -LiteralPath $php)) {
  throw "PHP not found: $php"
}
if (-not (Test-Path -LiteralPath $script)) {
  throw "Sync script not found: $script"
}

Write-Host "Root: $root"
Write-Host "Interval: every $Minutes minute(s)"

if (Test-Path -LiteralPath $phpIni) {
  & $php -c $phpIni $script --force | Write-Host
} else {
  & $php $script --force | Write-Host
}

# ملاحظة: [TimeSpan]::MaxValue يسبب خطأ Duration في Task Scheduler
$action = New-ScheduledTaskAction -Execute $php -Argument $phpArgs -WorkingDirectory $root
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) `
  -RepetitionInterval (New-TimeSpan -Minutes $Minutes) `
  -RepetitionDuration (New-TimeSpan -Days 3650)
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
  -StartWhenAvailable -MultipleInstances IgnoreNew
$principal = New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$env:USERNAME" -LogonType Interactive -RunLevel Highest

Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue

try {
  Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal | Out-Null
} catch {
  Write-Host "Register-ScheduledTask failed, trying schtasks.exe ..."
  $tr = if (Test-Path -LiteralPath $phpIni) {
    "`"$php`" -c `"$phpIni`" `"$script`""
  } else {
    "`"$php`" `"$script`""
  }
  $cmd = "schtasks /Create /F /TN `"$TaskName`" /TR `"$tr`" /SC MINUTE /MO $Minutes /RL HIGHEST"
  cmd /c $cmd
  if ($LASTEXITCODE -ne 0) {
    throw "Failed to register scheduled task. Run PowerShell as Administrator and retry."
  }
}

$check = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
if (-not $check) {
  throw "Task was not created. Run this script as Administrator."
}

Write-Host "OK: Task '$TaskName' registered (every $Minutes min). State=$($check.State)"
Write-Host "Enable auto_sync from: /customers/oracle-sync"
Write-Host "Manual once: $php -c $phpIni `"$script`" --force"
Write-Host "Remove task: Unregister-ScheduledTask -TaskName '$TaskName' -Confirm:`$false"
