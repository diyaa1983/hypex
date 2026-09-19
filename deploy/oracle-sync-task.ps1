# مزامنة Oracle المستمرة — شغّلها من Task Scheduler كل 15–60 دقيقة
# عدّل المسارات والـ token أدناه

$ErrorActionPreference = "Stop"
$Php = "C:\xampp\php\php.exe"
$Script = "C:\xampp\htdocs\system\api\oracle_sync.php"
# نفس sync_token في config\oracle.local.php
$Token = "CHANGE_ME_LONG_SECRET"
$Entities = "customers,accounts"
$LogDir = "C:\xampp\htdocs\system\storage\logs"
if (-not (Test-Path $LogDir)) { New-Item -ItemType Directory -Force -Path $LogDir | Out-Null }
$Log = Join-Path $LogDir ("oracle-sync-" + (Get-Date -Format "yyyyMMdd") + ".log")

$args = @($Script, "--token=$Token", "--entities=$Entities")
$line = "==== $(Get-Date -Format o) ===="
Add-Content -Path $Log -Value $line
& $Php @args 2>&1 | Tee-Object -FilePath $Log -Append
