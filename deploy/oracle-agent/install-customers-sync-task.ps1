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
$script = Join-Path $root "tools\oracle_customers_auto_sync_run.php"

if (-not (Test-Path -LiteralPath $php)) {
  throw "PHP not found: $php"
}
if (-not (Test-Path -LiteralPath $script)) {
  throw "Sync script not found: $script"
}

Write-Host "Root: $root"
Write-Host "Interval: every $Minutes minute(s)"

& $php $script --force | Write-Host

$action = New-ScheduledTaskAction -Execute $php -Argument "`"$script`"" -WorkingDirectory $root
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) `
  -RepetitionInterval (New-TimeSpan -Minutes $Minutes) `
  -RepetitionDuration ([TimeSpan]::MaxValue)
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
  -StartWhenAvailable -MultipleInstances IgnoreNew
$principal = New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$env:USERNAME" -LogonType Interactive -RunLevel Highest

Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue
Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal | Out-Null

Write-Host "OK: Task '$TaskName' registered (every $Minutes min)."
Write-Host "Enable auto_sync from: /customers/oracle-sync"
Write-Host "Manual once: $php `"$script`" --force"
Write-Host "Remove task: Unregister-ScheduledTask -TaskName '$TaskName' -Confirm:`$false"
