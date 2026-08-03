# Windows Server / PowerShell — تحديث من GitHub
# شغّل من مجلد المشروع على السيرفر:
#   powershell -ExecutionPolicy Bypass -File deploy\update.ps1

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root

$Branch = if ($env:DEPLOY_BRANCH) { $env:DEPLOY_BRANCH } else { "main" }
$Remote = if ($env:DEPLOY_REMOTE) { $env:DEPLOY_REMOTE } else { "origin" }

Write-Host "==> $Root"
Write-Host "==> git fetch $Remote $Branch"

if (-not (Test-Path "config\database.local.php")) {
    Write-Warning "config/database.local.php missing"
}
if (-not (Test-Path "config\app.local.php")) {
    Write-Warning "config/app.local.php missing"
}

git fetch $Remote $Branch
git merge --ff-only "$Remote/$Branch"

@("logs", "uploads", "uploads\logos", "data\zk_cache") | ForEach-Object {
    New-Item -ItemType Directory -Force -Path $_ | Out-Null
}

Write-Host "==> Updated. Open http://STATIC_IP/"
