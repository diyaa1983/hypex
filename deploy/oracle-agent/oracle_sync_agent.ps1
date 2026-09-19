# Oracle sync agent — reads agent.config.json and runs sync every interval_seconds.
# Run:  .\oracle_sync_agent.ps1
# Once: .\oracle_sync_agent.ps1 -Once
param(
  [string] $ConfigPath = "",
  [switch] $Once
)

$ErrorActionPreference = "Continue"

if ($ConfigPath -eq "") {
  $ConfigPath = Join-Path $PSScriptRoot "agent.config.json"
}

function Get-AgentConfig([string] $path) {
  if (-not (Test-Path -LiteralPath $path)) {
    throw "config not found: $path"
  }
  # UTF8 with or without BOM; strip comments lines starting with //
  $bytes = [System.IO.File]::ReadAllBytes($path)
  if ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) {
    $text = [System.Text.Encoding]::UTF8.GetString($bytes, 3, $bytes.Length - 3)
  } else {
    $text = [System.Text.Encoding]::UTF8.GetString($bytes)
  }
  # remove // line comments (common Notepad mistake if user adds them)
  $lines = $text -split "`r?`n"
  $clean = New-Object System.Collections.Generic.List[string]
  foreach ($ln in $lines) {
    $t = $ln.Trim()
    if ($t.StartsWith("//")) { continue }
    $clean.Add($ln)
  }
  $text = ($clean -join "`n").Trim()
  # trailing commas before } or ] break Windows ConvertFrom-Json
  $text = [regex]::Replace($text, ",\s*([}\]])", '$1')
  try {
    return ($text | ConvertFrom-Json)
  } catch {
    throw "Invalid agent.config.json: $($_.Exception.Message). Fix JSON (use double quotes, no trailing comma, no Arabic quotes)."
  }
}

function Write-AgentLog($cfg, [string] $msg) {
  $logDir = [string] $cfg.log_dir
  $logName = [string] $cfg.log_name
  if ([string]::IsNullOrWhiteSpace($logDir)) {
    $logDir = Join-Path $PSScriptRoot "logs"
  }
  if ([string]::IsNullOrWhiteSpace($logName)) {
    $logName = "oracle-agent"
  }
  if (-not (Test-Path -LiteralPath $logDir)) {
    New-Item -ItemType Directory -Force -Path $logDir | Out-Null
  }
  $file = Join-Path $logDir ($logName + "-" + (Get-Date -Format "yyyyMMdd") + ".log")
  $line = "[{0}] {1}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $msg
  Add-Content -LiteralPath $file -Value $line -Encoding UTF8
  Write-Host $line
}

function Invoke-OracleSync($cfg) {
  if (-not $cfg.enabled) {
    Write-AgentLog $cfg "SKIP enabled=false"
    return 0
  }

  $php = ([string] $cfg.php_exe) -replace '/', '\'
  $script = ([string] $cfg.sync_script) -replace '/', '\'
  $token = [string] $cfg.token
  $entities = [string] $cfg.entities
  if ([string]::IsNullOrWhiteSpace($entities)) { $entities = "customers" }

  if (-not (Test-Path -LiteralPath $php)) {
    Write-AgentLog $cfg "ERROR php not found: $php"
    return 2
  }
  if (-not (Test-Path -LiteralPath $script)) {
    Write-AgentLog $cfg "ERROR sync script not found: $script"
    return 3
  }
  # السكربت الجديد tools/oracle_customers_auto_sync_run.php لا يحتاج token
  $needsToken = $script -match 'oracle_sync\.php'
  if ($needsToken -and ([string]::IsNullOrWhiteSpace($token) -or $token -match 'CHANGE_ME')) {
    Write-AgentLog $cfg "ERROR set token (= sync_token in oracle.local.php)"
    return 4
  }

  Write-AgentLog $cfg "RUN entities=$entities"
  if ($needsToken) {
    $out = & $php $script "--token=$token" "--entities=$entities" 2>&1 | Out-String
  } else {
    $out = & $php $script 2>&1 | Out-String
  }
  $code = $LASTEXITCODE
  if ($null -eq $code) { $code = 0 }
  $short = $out.Trim()
  if ($short.Length -gt 600) { $short = $short.Substring(0, 600) + "..." }
  $short = $short -replace "[\r\n]+", " "
  Write-AgentLog $cfg "EXIT=$code $short"
  return $code
}

Write-Host "Config: $ConfigPath"
Write-Host "Mode: $(if ($Once) { 'ONCE' } else { 'LOOP' })"

if ($Once) {
  $cfg = Get-AgentConfig $ConfigPath
  exit (Invoke-OracleSync $cfg)
}

while ($true) {
  try {
    $cfg = Get-AgentConfig $ConfigPath
    $interval = 60
    try { $interval = [int] $cfg.interval_seconds } catch { $interval = 60 }
    if ($interval -lt 15) { $interval = 15 }
    Invoke-OracleSync $cfg | Out-Null
    Write-AgentLog $cfg "SLEEP ${interval}s"
    Start-Sleep -Seconds $interval
  } catch {
    Write-Host ("ERROR: " + $_.Exception.Message)
    Start-Sleep -Seconds 20
  }
}
