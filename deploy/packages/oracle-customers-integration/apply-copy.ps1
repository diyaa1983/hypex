param(
  [Parameter(Mandatory = $true)]
  [string] $Target
)
$ErrorActionPreference = "Stop"
$Here = Split-Path -Parent $MyInvocation.MyCommand.Path
if (-not (Test-Path $Target)) { throw "Target not found: $Target" }

$pairs = @(
  @("config\oracle.local.example.php", "config\oracle.local.example.php"),
  @("includes\oracle_pdo.php", "includes\oracle_pdo.php"),
  @("includes\oracle_customer_sync.php", "includes\oracle_customer_sync.php"),
  @("modules\system\oracle_customers_sync.php", "modules\system\oracle_customers_sync.php"),
  @("database\migrations\245_crm_customer_oracle_key.sql", "database\migrations\245_crm_customer_oracle_key.sql"),
  @("database\migrations\246_oracle_customers_sync_screen.sql", "database\migrations\246_oracle_customers_sync_screen.sql"),
  @("patch\routes.php.FULL", "config\routes.php"),
  @("patch\nav_menu.php.FULL", "config\nav_menu.php"),
  @("patch\index.php.FULL", "index.php")
)

foreach ($p in $pairs) {
  $src = Join-Path $Here $p[0]
  $dst = Join-Path $Target $p[1]
  $dir = Split-Path $dst -Parent
  if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Force -Path $dir | Out-Null }
  Copy-Item -Force $src $dst
  Write-Host "OK  $($p[1])"
}

$sample = Join-Path $Here "config\oracle.local.php.SAMPLE"
$ora = Join-Path $Target "config\oracle.local.php"
if (-not (Test-Path $ora)) {
  Copy-Item $sample $ora
  Write-Host "CREATED config\oracle.local.php (from SAMPLE) — edit password if needed"
} else {
  Write-Host "KEEP existing config\oracle.local.php"
}

Write-Host ""
Write-Host "Done. Restart Apache if you changed PHP extensions. Open index.php?r=oracle_customers_sync"