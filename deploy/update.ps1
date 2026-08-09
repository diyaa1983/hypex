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

# Node UI (hypex-node)
$nodeDir = Join-Path $Root "hypex-node"
if (Test-Path $nodeDir) {
    Write-Host "==> Updating hypex-node"
    $envFile = Join-Path $nodeDir ".env"
    $envEx = Join-Path $nodeDir ".env.example"
    if (-not (Test-Path $envFile) -and (Test-Path $envEx)) {
        Copy-Item $envEx $envFile
        Write-Warning "Created hypex-node/.env from example — edit DB, PHP_BASE_URL, SESSION_SECRET"
    }
    if (Get-Command npm -ErrorAction SilentlyContinue) {
        Push-Location $nodeDir
        try {
            if (Test-Path "package-lock.json") { npm ci --omit=dev } else { npm install --omit=dev }
        } finally {
            Pop-Location
        }
        if (Get-Command pm2 -ErrorAction SilentlyContinue) {
            $desc = pm2 describe hypex-node 2>$null
            if ($LASTEXITCODE -eq 0) {
                pm2 restart hypex-node --update-env
            } else {
                pm2 start (Join-Path $nodeDir "src\server.js") --name hypex-node --cwd $nodeDir
                pm2 save 2>$null
            }
            Write-Host "    Node via pm2 (hypex-node)"
        } else {
            Write-Warning "pm2 not found — install (npm i -g pm2) or run: cd hypex-node; npm start"
        }
    } else {
        Write-Warning "npm/Node missing — install Node 18+ then re-run deploy\update.ps1"
    }
}

Write-Host "==> Updated."
Write-Host "    PHP:  http://STATIC_IP/"
Write-Host "    Node: http://STATIC_IP:3000/ (or reverse-proxy if configured)"
