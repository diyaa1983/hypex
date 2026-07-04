#Requires -Version 5.1
<#
  تشخيص مزامنة ZKT — يعرض آخر البصمات وحالة Flag
#>
param()

$ErrorActionPreference = 'Stop'
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ConfigPath = Join-Path $ScriptDir 'zk_sync.local.php'

function Read-PhpConfig([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path)) {
        throw 'Missing config: tools\zk_sync.local.php'
    }
    $text = Get-Content -LiteralPath $Path -Raw -Encoding UTF8
    function Get-Val([string]$key) {
        if ($text -match ("'{0}'\s*=>\s*'([^']*)'" -f [regex]::Escape($key))) {
            return ($Matches[1] -replace '\\\\', '\')
        }
        if ($text -match ("'{0}'\s*=>\s*(true|false)" -f [regex]::Escape($key))) {
            return ($Matches[1] -eq 'true')
        }
        return $null
    }
    return [pscustomobject]@{
        MdbPath = Get-Val 'mdb_path'
        UseFlag = if ($null -ne (Get-Val 'use_flag')) { [bool](Get-Val 'use_flag') } else { $true }
    }
}

function Open-MdbConnection([string]$MdbPath) {
    if (-not (Test-Path -LiteralPath $MdbPath)) {
        throw "Fingerprint file not found: $MdbPath"
    }
    $providers = @(
        ("Provider=Microsoft.ACE.OLEDB.12.0;Data Source=" + $MdbPath + ";Persist Security Info=False;")
        ("Provider=Microsoft.Jet.OLEDB.4.0;Data Source=" + $MdbPath + ";")
    )
    $conn = New-Object -ComObject ADODB.Connection
    $lastErr = $null
    foreach ($connStr in $providers) {
        try {
            $conn.Open($connStr)
            return $conn
        } catch {
            $lastErr = $_.Exception.Message
        }
    }
    throw "Cannot open Access DB. Install Microsoft Access Database Engine. $lastErr"
}

function Test-HasFlagColumn([object]$Conn) {
    try {
        $null = $Conn.Execute('SELECT TOP 1 [Flag] FROM CHECKINOUT')
        return $true
    } catch {
        return $false
    }
}

function Format-CheckTimeValue($raw) {
    if ($null -eq $raw) { return '' }
    if ($raw -is [datetime]) { return $raw.ToString('yyyy-MM-dd HH:mm:ss') }
    return ([string]$raw).Trim()
}

Write-Host ''
Write-Host '=== ZKT Sync Diagnose ===' -ForegroundColor Cyan
Write-Host ''

$cfg = Read-PhpConfig -Path $ConfigPath
if ([string]::IsNullOrWhiteSpace($cfg.MdbPath)) {
    $cfg.MdbPath = Join-Path (Split-Path $ScriptDir -Parent) 'att2000.mdb'
}

Write-Host ("MDB: {0}" -f $cfg.MdbPath)
Write-Host ("use_flag: {0}" -f $cfg.UseFlag)
Write-Host ''

$conn = Open-MdbConnection -MdbPath $cfg.MdbPath
$hasFlag = Test-HasFlagColumn -Conn $conn

$rsTotal = $conn.Execute('SELECT COUNT(*) FROM CHECKINOUT')
$total = if (-not $rsTotal.EOF) { [int]$rsTotal.Fields.Item(0).Value } else { 0 }

$pending = -1
if ($hasFlag) {
    $rsPending = $conn.Execute('SELECT COUNT(*) FROM CHECKINOUT WHERE ([Flag] = 0 OR [Flag] IS NULL)')
    $pending = if (-not $rsPending.EOF) { [int]$rsPending.Fields.Item(0).Value } else { 0 }
}

Write-Host ("Total CHECKINOUT rows: {0}" -f $total)
if ($hasFlag) {
    Write-Host ("Pending (Flag=0 or NULL): {0}" -f $pending) -ForegroundColor $(if ($pending -gt 0) { 'Green' } else { 'Yellow' })
    Write-Host ("Already Flag=1: {0}" -f ($total - $pending))
    if ($cfg.UseFlag -and $pending -eq 0 -and $total -gt 0) {
        Write-Host ''
        Write-Host 'All rows have Flag=1 — agent will skip them.' -ForegroundColor Yellow
        Write-Host 'Fix: set Flag=0 on new row in Access, OR use_flag=false in zk_sync.local.php'
    }
} else {
    Write-Host 'No Flag column in CHECKINOUT.'
}

Write-Host ''
Write-Host 'Last 5 punches in Access:' -ForegroundColor Cyan
if ($hasFlag) {
    $sql = @'
SELECT TOP 5 c.USERID, c.CHECKTIME, c.CHECKTYPE, c.[Flag], u.BADGENUMBER, u.NAME
FROM CHECKINOUT AS c
LEFT JOIN USERINFO AS u ON u.USERID = c.USERID
ORDER BY c.CHECKTIME DESC
'@
} else {
    $sql = @'
SELECT TOP 5 c.USERID, c.CHECKTIME, c.CHECKTYPE, u.BADGENUMBER, u.NAME
FROM CHECKINOUT AS c
LEFT JOIN USERINFO AS u ON u.USERID = c.USERID
ORDER BY c.CHECKTIME DESC
'@
}
$rs = $conn.Execute($sql)
$idx = 0
while (-not $rs.EOF) {
    $idx++
    $checkStr = Format-CheckTimeValue $rs.Fields.Item('CHECKTIME').Value
    $flagStr = if ($hasFlag) { [string]$rs.Fields.Item('Flag').Value } else { 'n/a' }
    Write-Host ("  {0}. USERID={1} TIME={2} Flag={3} BADGE={4}" -f $idx,
        $rs.Fields.Item('USERID').Value,
        $checkStr,
        $flagStr,
        $rs.Fields.Item('BADGENUMBER').Value)
    $rs.MoveNext()
}

Write-Host ''
Write-Host ('Log: {0}' -f (Join-Path $ScriptDir 'zk_sync.log'))
Write-Host ''
pause
