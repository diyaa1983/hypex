<?php
declare(strict_types=1);

/**
 * مبيعات المندوب من Oracle: المواد والفئات خلال فترة.
 */

require_once app_path('includes/oracle_rep_item_sales.php');
require_once app_path('includes/document_header.php');
require_once app_path('includes/crm_sales_rep_schema.php');

$pdo = db();
crm_sales_rep_ensure_schema($pdo);

$routeKey = 'report_oracle_rep_item_sales';
$reportTitle = 'مبيعات المندوب حسب المادة والفئة (Oracle)';
$cfg = oracle_rep_net_sales_cfg();

$parseOracleRepNo = static function (string $code): int {
    $code = trim($code);
    if ($code !== '' && preg_match('/^[1-9]\d*$/', $code)) {
        return (int) $code;
    }
    if (preg_match('/(\d+)/', $code, $m)) {
        $n = (int) $m[1];
        if ($n > 0 && $n < 100000) {
            return $n;
        }
    }

    return 0;
};

// قائمة المندوبين من هايبكس فقط — لا نعرض مندوبي Oracle غير الموجودين بالنظام
$hypex = $pdo->query(
    'SELECT code, name_ar FROM crm_sales_rep WHERE is_active = 1 ORDER BY name_ar'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$hypexNameByMan = [];
$reps = [];
$seen = [];
foreach ($hypex as $r) {
    $manNo = $parseOracleRepNo((string) ($r['code'] ?? ''));
    if ($manNo < 1 || isset($seen[$manNo])) {
        continue;
    }
    $nm = trim((string) ($r['name_ar'] ?? ''));
    if ($nm === '') {
        continue;
    }
    $seen[$manNo] = true;
    $hypexNameByMan[$manNo] = $nm;
    $reps[] = [
        'id' => $manNo,
        'code' => (string) $manNo,
        'name_ar' => $nm,
    ];
}
usort($reps, static fn (array $a, array $b): int => strcmp((string) $a['name_ar'], (string) $b['name_ar']));

// POST لتجنب 414 عند اختيار كثير من القيم؛ GET لفتح الصفحة
$oraReq = static function (string $key, mixed $default = null): mixed {
    if (array_key_exists($key, $_POST)) {
        return $_POST[$key];
    }
    if (array_key_exists($key, $_GET)) {
        return $_GET[$key];
    }

    return $default;
};
$rawSalesRepIds = $oraReq('sales_rep_id', []);
if (!is_array($rawSalesRepIds)) {
    $rawSalesRepIds = $rawSalesRepIds !== '' && $rawSalesRepIds !== null ? [$rawSalesRepIds] : [];
}
$salesRepIds = [];
foreach ($rawSalesRepIds as $rid) {
    $rid = (int) $rid;
    if ($rid > 0) {
        $salesRepIds[$rid] = $rid;
    }
}
$salesRepIds = array_values($salesRepIds);
$salesRepId = count($salesRepIds) === 1 ? $salesRepIds[0] : 0;
$rawCustomerIds = $oraReq('customer_id', []);
if (!is_array($rawCustomerIds)) {
    $rawCustomerIds = $rawCustomerIds !== '' && $rawCustomerIds !== null ? [$rawCustomerIds] : [];
}
$customerIds = [];
foreach ($rawCustomerIds as $cid) {
    $cid = (int) $cid;
    if ($cid > 0) {
        $customerIds[$cid] = $cid;
    }
}
$customerIds = array_values($customerIds);
$rawCategoryIds = $oraReq('category_id', []);
if (!is_array($rawCategoryIds)) {
    $rawCategoryIds = $rawCategoryIds !== '' && $rawCategoryIds !== null ? [$rawCategoryIds] : [];
}
$categoryIds = [];
foreach ($rawCategoryIds as $cid) {
    $cid = trim((string) $cid);
    if ($cid === 'none' || ctype_digit($cid)) {
        $categoryIds[$cid] = $cid;
    }
}
$categoryIds = array_values($categoryIds);
$rawYears = $oraReq('report_year', []);
if (!is_array($rawYears)) {
    $rawYears = $rawYears !== '' && $rawYears !== null ? [$rawYears] : [];
}
$reportYears = [];
foreach ($rawYears as $y) {
    $y = (int) $y;
    if ($y >= 2000 && $y <= 2100) {
        $reportYears[$y] = $y;
    }
}
$reportYears = array_values($reportYears);
$rawMonths = $oraReq('report_month', []);
if (!is_array($rawMonths)) {
    $rawMonths = $rawMonths !== '' && $rawMonths !== null ? [$rawMonths] : [];
}
$reportMonths = [];
foreach ($rawMonths as $m) {
    $m = (int) $m;
    if ($m >= 1 && $m <= 12) {
        $reportMonths[$m] = $m;
    }
}
$reportMonths = array_values($reportMonths);
$customers = $pdo->query(
    'SELECT c.id, c.name_ar, c.code, c.oracle_key, IFNULL(r.code, \'\') AS primary_rep_code
     FROM crm_customer c
     LEFT JOIN crm_sales_rep r ON r.id = c.sales_rep_id
     WHERE c.is_active = 1
     ORDER BY c.name_ar'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$custRepCodes = [];
try {
    $linkRows = $pdo->query(
        'SELECT csr.customer_id, r.code
         FROM crm_customer_sales_rep csr
         INNER JOIN crm_sales_rep r ON r.id = csr.sales_rep_id'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($linkRows as $lr) {
        $cid = (string) (int) ($lr['customer_id'] ?? 0);
        $code = trim((string) ($lr['code'] ?? ''));
        if ($cid === '0' || $code === '') {
            continue;
        }
        if (!isset($custRepCodes[$cid])) {
            $custRepCodes[$cid] = [];
        }
        $custRepCodes[$cid][$code] = $code;
    }
} catch (Throwable $e) {
    $custRepCodes = [];
}
foreach ($customers as $cust) {
    $cid = (string) (int) ($cust['id'] ?? 0);
    if ($cid === '0') {
        continue;
    }
    if (!isset($custRepCodes[$cid])) {
        $custRepCodes[$cid] = [];
    }
    $primary = trim((string) ($cust['primary_rep_code'] ?? ''));
    if ($primary !== '') {
        $custRepCodes[$cid][$primary] = $primary;
    }
}
try {
    // فئات النظام الحالي فقط — بدون فئات مزامَنة من Oracle
    try {
        $categories = $pdo->query(
            "SELECT id, name_ar FROM inv_item_category
             WHERE is_active = 1
               AND (oracle_key IS NULL OR TRIM(IFNULL(oracle_key, '')) = '')
             ORDER BY name_ar"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $eCat) {
        $categories = $pdo->query(
            'SELECT id, name_ar FROM inv_item_category WHERE is_active = 1 ORDER BY name_ar'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    $categories = $pdo->query(
        'SELECT id, name_ar FROM inv_item_category ORDER BY name_ar'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
$from = date('Y-01-01');
$to = function_exists('app_default_date_to') ? app_default_date_to() : date('Y-m-d');
$store = (int) ($oraReq('store', $cfg['default_store']));
if ($store < 1) {
    $store = (int) $cfg['default_store'];
}

$fmtAmt = static function (float $n): string {
    return number_format(round($n, 3), 3, '.', ',');
};
$fmtQty = static function (float $n): string {
    $s = number_format($n, 3, '.', ',');

    return rtrim(rtrim($s, '0'), '.');
};

$err = '';
$showResult = false;
$rows = [];
$totals = ['qty' => 0.0, 'bonus' => 0.0, 'amount' => 0.0, 'reps' => 0, 'items' => 0];
$repName = '';
$submitted = $oraReq('run') !== null;

if ($submitted) {
    $period = oracle_rep_item_sales_periods($from, $to, $reportYears, $reportMonths);
    $from = $period['from'];
    $to = $period['to'];
    if ($from > $to) {
        $err = 'فترة السنة/الشهر غير صالحة.';
    } else {
        if (count($salesRepIds) === 1) {
            $repName = $hypexNameByMan[$salesRepIds[0]] ?? ('مندوب ' . $salesRepIds[0]);
        } elseif (count($salesRepIds) > 1) {
            $repName = count($salesRepIds) . ' مندوبين';
        }
        $scopeCustomerIds = $customerIds;
        $filterOracleRep = true;
        $selectedRepCodes = [];
        foreach ($salesRepIds as $rid) {
            $selectedRepCodes[(string) $rid] = (string) $rid;
        }
        $accToRep = [];
        if ($salesRepIds !== []) {
            $filterOracleRep = false;
            $linkedIds = [];
            foreach ($customers as $cust) {
                $cid = (int) ($cust['id'] ?? 0);
                if ($cid < 1) {
                    continue;
                }
                $codes = $custRepCodes[(string) $cid] ?? [];
                $hit = false;
                foreach ($selectedRepCodes as $code) {
                    if (isset($codes[$code])) {
                        $hit = true;
                        break;
                    }
                }
                if ($hit) {
                    $linkedIds[$cid] = $cid;
                }
            }
            if ($scopeCustomerIds !== []) {
                $allow = array_fill_keys($scopeCustomerIds, true);
                $scopeCustomerIds = [];
                foreach ($linkedIds as $cid) {
                    if (isset($allow[$cid])) {
                        $scopeCustomerIds[] = $cid;
                    }
                }
            } else {
                $scopeCustomerIds = array_values($linkedIds);
            }
        }
        $custAccs = [];
        $accSource = $scopeCustomerIds !== [] ? $scopeCustomerIds : ($salesRepIds !== [] ? [] : $customerIds);
        foreach ($accSource as $cid) {
            foreach ($customers as $cust) {
                if ((int) ($cust['id'] ?? 0) !== $cid) {
                    continue;
                }
                $raw = trim((string) ($cust['oracle_key'] ?? ''));
                if ($raw === '') {
                    $raw = trim((string) ($cust['code'] ?? ''));
                }
                $digits = ltrim(preg_replace('/\D+/', '', $raw) ?? '', '0');
                if ($digits !== '') {
                    $custAccs[$digits] = $digits;
                    $codes = $custRepCodes[(string) $cid] ?? [];
                    $primary = trim((string) ($cust['primary_rep_code'] ?? ''));
                    $prefer = '';
                    if ($selectedRepCodes !== []) {
                        if ($primary !== '' && isset($selectedRepCodes[$primary])) {
                            $prefer = $primary;
                        } else {
                            foreach ($codes as $code) {
                                if (isset($selectedRepCodes[$code])) {
                                    $prefer = $code;
                                    break;
                                }
                            }
                        }
                    } else {
                        $prefer = $primary !== '' ? $primary : (string) (array_values($codes)[0] ?? '');
                    }
                    if ($prefer !== '') {
                        $accToRep[$digits] = (int) $prefer;
                    }
                }
                break;
            }
        }
        $custAccs = array_values($custAccs);
        $catNames = [];
        foreach ($categoryIds as $cid) {
            if ($cid === 'none') {
                $catNames['بدون فئة'] = 'بدون فئة';
                continue;
            }
            foreach ($categories as $cat) {
                if ((string) ($cat['id'] ?? '') !== $cid) {
                    continue;
                }
                $name = trim((string) ($cat['name_ar'] ?? ''));
                if ($name !== '') {
                    $catNames[$name] = $name;
                }
                break;
            }
        }
        $catNames = array_values($catNames);
        if ($salesRepIds !== [] && $scopeCustomerIds === []) {
            $err = 'لا يوجد عملاء مربوطون بالمندوبين المحددين في النظام.';
        } elseif ($salesRepIds !== [] && $custAccs === []) {
            $err = 'عملاء المندوبين المحددين غير مربوطين بحساب في Oracle.';
        } elseif ($customerIds !== [] && $custAccs === []) {
            $err = 'العملاء المحددون غير مربوطين بحساب في Oracle.';
        } else {
        $result = oracle_fetch_rep_item_sales(
            $from,
            $to,
            $store,
            count($salesRepIds) === 1 ? $salesRepIds[0] : null,
            $custAccs,
            $catNames,
            $period['year_months'],
            $filterOracleRep
        );
        if (empty($result['ok'])) {
            $err = (string) ($result['message'] ?? 'تعذر الاستعلام من Oracle.');
        } else {
            $showResult = true;
            $rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
            $nameByMan = $hypexNameByMan;
            foreach ($reps as $rp) {
                $id = (int) ($rp['id'] ?? 0);
                $nm = trim((string) ($rp['name_ar'] ?? ''));
                if ($id > 0 && $nm !== '' && !isset($nameByMan[$id])) {
                    $nameByMan[$id] = $nm;
                }
            }
            foreach ($rows as &$row) {
                $accKey = ltrim(preg_replace('/\D+/', '', (string) ($row['customer_acc'] ?? '')) ?? '', '0');
                $no = (int) ($row['rep_no'] ?? 0);
                if ($filterOracleRep === false && $accKey !== '' && isset($accToRep[$accKey])) {
                    $no = (int) $accToRep[$accKey];
                    $row['rep_no'] = $no;
                }
                $current = trim((string) ($row['rep_name'] ?? ''));
                if ($no > 0 && isset($hypexNameByMan[$no])) {
                    $row['rep_name'] = $hypexNameByMan[$no];
                } elseif ($current === '' || preg_match('/^مندوب\s+\d+$/u', $current)) {
                    $row['rep_name'] = $nameByMan[$no] ?? ($no > 0 ? ('مندوب ' . $no) : $current);
                }
            }
            unset($row);
            $totals = is_array($result['totals'] ?? null) ? $result['totals'] : $totals;
            if ($rows === [] && ($result['message'] ?? '') !== '') {
                $err = (string) $result['message'];
            }
        }
        }
    }
}

$pickerCustomers = [];
foreach ($customers as $cust) {
    $cid = (string) (int) ($cust['id'] ?? 0);
    $pickerCustomers[] = [
        'id' => $cid,
        'label' => (string) ($cust['name_ar'] ?? ''),
        'code' => (string) ($cust['code'] ?? ''),
        'rep_codes' => array_values($custRepCodes[$cid] ?? []),
    ];
}
$pickerCategories = [['id' => 'none', 'label' => 'بدون فئة', 'code' => '']];
foreach ($categories as $cat) {
    $pickerCategories[] = [
        'id' => (string) (int) ($cat['id'] ?? 0),
        'label' => (string) ($cat['name_ar'] ?? ''),
        'code' => '',
    ];
}
$customersJson = str_replace('<', '\u003c', json_encode($pickerCustomers, JSON_UNESCAPED_UNICODE) ?: '[]');
$categoriesJson = str_replace('<', '\u003c', json_encode($pickerCategories, JSON_UNESCAPED_UNICODE) ?: '[]');
$monthLabels = [
    1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل',
    5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس',
    9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر',
];
$pickerYears = [];
$yearNow = (int) date('Y');
for ($y = $yearNow; $y >= $yearNow - 8; $y--) {
    $pickerYears[] = ['id' => (string) $y, 'label' => (string) $y, 'code' => ''];
}
$pickerMonths = [];
foreach ($monthLabels as $num => $label) {
    $pickerMonths[] = ['id' => (string) $num, 'label' => $label, 'code' => ''];
}
$yearsJson = str_replace('<', '\u003c', json_encode($pickerYears, JSON_UNESCAPED_UNICODE) ?: '[]');
$monthsJson = str_replace('<', '\u003c', json_encode($pickerMonths, JSON_UNESCAPED_UNICODE) ?: '[]');
$pickerReps = [];
foreach ($reps as $rp) {
    $pickerReps[] = [
        'id' => (string) (int) ($rp['id'] ?? 0),
        'label' => (string) ($rp['name_ar'] ?? ''),
        'code' => (string) ($rp['code'] ?? ''),
    ];
}
$repsJson = str_replace('<', '\u003c', json_encode($pickerReps, JSON_UNESCAPED_UNICODE) ?: '[]');
$selectedJson = str_replace('<', '\u003c', json_encode([
    'reps' => array_map('strval', $salesRepIds),
    'customers' => array_map('strval', $customerIds),
    'categories' => array_values($categoryIds),
    'years' => array_map('strval', $reportYears),
    'months' => array_map('strval', $reportMonths),
], JSON_UNESCAPED_UNICODE) ?: '{}');
$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<style>
.ora-multi-actions { display:flex; gap:.35rem; flex-wrap:wrap; }
.ora-multi-actions button {
  flex:1 1 auto; border:1px solid #cbd5e1; background:#f8fafc; border-radius:6px;
  padding:.35rem .45rem; font:700 12px Arial, Helvetica, sans-serif; color:#1e3a5f; cursor:pointer;
}
.ora-portrait-wrap { max-width: none; overflow-x: auto; }
.ora-portrait-table { width: 100%; table-layout: auto; border-collapse: collapse; }
.ora-portrait-table th.ora-vhead {
  writing-mode: horizontal-tb;
  white-space: nowrap;
  height: auto;
  vertical-align: middle;
  text-align: center;
  font-size: .82rem;
  padding: .55rem .45rem;
  position: sticky;
  top: 0;
  background: #f1f5f9;
  z-index: 2;
}
.ora-portrait-table td { vertical-align: middle; padding: .4rem .45rem; }
.ora-portrait-table td.ora-code,
.ora-portrait-table td[dir="ltr"] {
  white-space: nowrap;
  word-break: keep-all;
  font-variant-numeric: tabular-nums;
}
.ora-portrait-table td.ora-name { min-width: 14rem; white-space: normal; }
.ora-portrait-table th.ora-cat-col { white-space: normal; min-width: 6.5rem; text-align: center; }
.ora-portrait-table td.ora-cust-name { font-weight: 700; min-width: 12rem; white-space: normal; }
.ora-tools { display: flex; gap: .5rem; margin: 0 0 .75rem; }
.ora-multi-btn,
.report-sales-page .report-cust-pick-inp {
  width: 100%;
  height: 2.4rem;
  text-align: right;
  cursor: pointer;
  background: #fff !important;
  color: #0f172a;
  font-family: Arial, Helvetica, sans-serif;
  font-size: 15px;
  font-weight: 700;
  line-height: 1.4;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  padding: 0 .7rem;
  box-sizing: border-box;
}
.report-sales-page .field-label {
  font-family: Arial, Helvetica, sans-serif;
  font-size: 13px;
  font-weight: 700;
  color: #334155;
}
.ora-multi-panel,
.report-cust-pick-list.is-floating {
  font-family: Arial, Helvetica, sans-serif;
  font-size: 15px;
  line-height: 1.45;
  color: #0f172a;
  background: #fff;
  border: 1px solid #cbd5e1;
  border-radius: 10px;
  box-shadow: 0 12px 32px rgba(15, 23, 42, .16);
  padding: .35rem;
}
.ora-multi-panel {
  position: fixed; z-index: 100600; display: flex; flex-direction: column; gap: .35rem;
  max-height: min(22rem, 70vh); min-width: 18rem; box-sizing: border-box;
}
.ora-multi-panel[hidden] { display: none !important; }
.ora-multi-q {
  width: 100%; box-sizing: border-box; background: #fff; border: 1px solid #cbd5e1;
  border-radius: 6px; padding: .45rem .6rem; color: #0f172a;
  font-family: Arial, Helvetica, sans-serif; font-size: 14px; font-weight: 600;
}
.ora-multi-q:focus { outline: none; border-color: #1e3a5f; background: #fff; }
.ora-multi-list { overflow: auto; max-height: 16rem; background: #fff; }
.ora-multi-item,
.report-cust-pick-list.is-floating .report-cust-pick-item,
.report-cust-pick-list.is-floating .report-cust-pick-empty {
  display: flex !important;
  flex-direction: row;
  justify-content: flex-start !important;
  align-items: center;
  gap: .6rem;
  width: 100%;
  margin: 0;
  padding: .5rem .7rem;
  direction: rtl;
  text-align: right;
  cursor: pointer;
  box-sizing: border-box;
  font-family: Arial, Helvetica, sans-serif;
  font-size: 15px;
  font-weight: 600;
  line-height: 1.45;
  color: #0f172a;
  background: #fff;
  border: 0;
  border-bottom: 1px solid #f1f5f9;
  border-radius: 0;
}
.ora-multi-item:hover,
.report-cust-pick-list.is-floating .report-cust-pick-item:hover {
  background: #eef2ff;
}
.ora-multi-item input[type="checkbox"] { width: 1rem; height: 1rem; margin: 0; flex: 0 0 auto; }
.ora-multi-name { flex: 1 1 auto; min-width: 0; text-align: right; }
.ora-multi-code { flex: 0 0 auto; font-size: 12px; font-weight: 700; color: #64748b; direction: ltr; }
.report-cust-pick-list.is-floating .report-cust-pick-empty { cursor: default; color: #64748b; font-weight: 600; }
.ora-view[hidden] { display: none !important; }
#ora-rep-filter.ora-filter-row { max-width: none; margin-bottom: .75rem; }
#ora-rep-filter .ora-filter-grid {
  display: grid;
  grid-template-columns:
    minmax(0, 1.35fr)
    minmax(0, 1.35fr)
    minmax(0, 1.05fr)
    minmax(7.2rem, .85fr)
    minmax(7.2rem, .85fr)
    4.5rem;
  gap: .35rem .5rem;
  align-items: end;
  width: 100%;
}
#ora-rep-filter .ora-filter-grid > label { min-width: 0 !important; flex: none !important; }
#ora-rep-filter .ora-filter-grid .input,
#ora-rep-filter .ora-filter-grid .ora-multi-btn,
#ora-rep-filter .date-dmy-field {
  width: 100% !important;
  min-width: 0 !important;
  height: 2.35rem;
  box-sizing: border-box;
}
#ora-rep-filter .ora-multi-btn { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ora-detail-table td.ora-name { min-width: 18rem; white-space: normal; }
.ora-cust-band td { background: #f4f7fb; font-weight: 800; font-size: 1rem; }
.ora-cat-sum td { background: #e8eef8; font-weight: 800; }
.ora-cust-block { margin: 0 0 1.35rem; }
.ora-cust-head {
  background: #f4f7fb;
  border: 1px solid #d5deea;
  border-bottom: 0;
  padding: .7rem .9rem;
}
.ora-cust-head .ora-rep { font-weight: 800; color: #1e3a5f; }
.ora-cust-head .ora-who { font-weight: 800; font-size: 1.02rem; margin-top: .25rem; }
.ora-cat-head { cursor: pointer; }
.ora-cat-head td { background: #e8eef8; font-weight: 800; }
.ora-arrow { display: inline-block; width: 1.1rem; }
.ora-arrow::before { content: "◂"; }
.ora-cat-head[data-open="1"] .ora-arrow::before { content: "▾"; }
.ora-cust-final td { background: #e7f4ea; font-weight: 800; }
.ora-rep-total, .ora-grand {
  margin: .35rem 0 1rem;
  font-weight: 800;
  padding: .45rem .7rem;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
}
.ora-grand { background: #eef2f7; }
@media print {
  @page { size: A4 portrait; margin: 8mm 6mm 10mm 6mm; }
  .ora-portrait-table th.ora-vhead { position: static; }
  .ora-portrait-table { font-size: 9pt; }
  tr.ora-item[hidden] { display: table-row !important; }
  .ora-arrow { display: none; }
}
</style>
<div class="card report-sales-page" data-report-title="<?= esc($reportTitle) ?>" data-report-route="<?= esc($routeKey) ?>">
    <?php if ($err !== ''): ?>
        <div class="alert alert-error no-print" style="margin-bottom:1rem;"><?= esc($err) ?></div>
    <?php endif; ?>
    <p class="muted no-print" style="margin:0 0 .5rem;font-size:.85rem;">
        قوائم المندوب والفئة من النظام الحالي. المبيعات من Oracle حسب السنة والشهر. عند اختيار مندوب تُعرض كل مبيعات عملائه الحاليين (بما فيها ما بيع سابقاً تحت مندوب آخر).
    </p>
    <form method="post" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print ora-filter-row" id="ora-rep-filter">
        <input type="hidden" name="r" value="<?= esc($routeKey) ?>">
        <input type="hidden" name="run" value="1">
        <div class="form-row ora-filter-grid">
            <label class="field">
                <span class="field-label">المندوب</span>
                <div class="ora-multi" id="ora-rep-multi" data-ora-name="sales_rep_id" data-ora-empty="كل المندوبين">
                    <button type="button" class="input ora-multi-btn" data-ora-multi-btn>كل المندوبين</button>
                    <div class="ora-multi-panel" data-ora-multi-panel hidden>
                        <div class="ora-multi-actions">
                            <button type="button" data-ora-all>تحديد الكل</button>
                            <button type="button" data-ora-none>كل المندوبين</button>
                        </div>
                        <input type="text" class="input ora-multi-q" data-ora-multi-q placeholder="بحث بالاسم أو الرقم…" autocomplete="off">
                        <div class="ora-multi-list" data-ora-multi-list></div>
                    </div>
                </div>
            </label>
            <label class="field">
                <span class="field-label">العميل</span>
                <div class="ora-multi" id="ora-cust-multi" data-ora-name="customer_id" data-ora-empty="كل العملاء" data-ora-collapse-all="1">
                    <button type="button" class="input ora-multi-btn" data-ora-multi-btn>كل العملاء</button>
                    <div class="ora-multi-panel" data-ora-multi-panel hidden>
                        <div class="ora-multi-actions">
                            <button type="button" data-ora-all>تحديد الكل</button>
                            <button type="button" data-ora-none>كل العملاء</button>
                        </div>
                        <input type="text" class="input ora-multi-q" data-ora-multi-q placeholder="بحث بالاسم أو الرمز…" autocomplete="off">
                        <div class="ora-multi-list" data-ora-multi-list></div>
                    </div>
                </div>
            </label>
            <label class="field">
                <span class="field-label">الفئة</span>
                <div class="ora-multi" id="ora-cat-multi" data-ora-name="category_id" data-ora-empty="كل الفئات">
                    <button type="button" class="input ora-multi-btn" data-ora-multi-btn>كل الفئات</button>
                    <div class="ora-multi-panel" data-ora-multi-panel hidden>
                        <input type="text" class="input ora-multi-q" data-ora-multi-q placeholder="بحث عن فئة…" autocomplete="off">
                        <div class="ora-multi-list" data-ora-multi-list></div>
                    </div>
                </div>
            </label>
            <label class="field">
                <span class="field-label">السنة</span>
                <div class="ora-multi" id="ora-year-multi" data-ora-name="report_year" data-ora-empty="كل السنوات">
                    <button type="button" class="input ora-multi-btn" data-ora-multi-btn>كل السنوات</button>
                    <div class="ora-multi-panel" data-ora-multi-panel hidden>
                        <div class="ora-multi-actions">
                            <button type="button" data-ora-all>تحديد الكل</button>
                            <button type="button" data-ora-none>كل السنوات</button>
                        </div>
                        <input type="text" class="input ora-multi-q" data-ora-multi-q placeholder="بحث عن سنة…" autocomplete="off">
                        <div class="ora-multi-list" data-ora-multi-list></div>
                    </div>
                </div>
            </label>
            <label class="field">
                <span class="field-label">الشهر</span>
                <div class="ora-multi" id="ora-month-multi" data-ora-name="report_month" data-ora-empty="كل الأشهر">
                    <button type="button" class="input ora-multi-btn" data-ora-multi-btn>كل الأشهر</button>
                    <div class="ora-multi-panel" data-ora-multi-panel hidden>
                        <div class="ora-multi-actions">
                            <button type="button" data-ora-all>تحديد الكل</button>
                            <button type="button" data-ora-none>كل الأشهر</button>
                        </div>
                        <input type="text" class="input ora-multi-q" data-ora-multi-q placeholder="بحث عن شهر…" autocomplete="off">
                        <div class="ora-multi-list" data-ora-multi-list></div>
                    </div>
                </div>
            </label>
            <label class="field">
                <span class="field-label">المستودع</span>
                <input class="input" type="number" name="store" value="<?= (int) $store ?>" min="1" dir="ltr">
            </label>
        </div>
        <div style="margin-top:0.5rem;">
            <button class="btn btn-primary" type="submit">عرض التقرير</button>
        </div>
    </form>

    <?php if ($showResult): ?>
        <div class="report-sales-result report-sales-print-area">
            <?= document_print_header_html($reportTitle, $pdo) ?>
            <div class="doc-print-meta">
                <table>
                    <tr>
                        <td>
                            <strong>المندوب:</strong> <?= $repName !== '' ? esc($repName) : 'الكل' ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>من:</strong> <?= esc(format_date_dmY($from)) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>إلى:</strong> <?= esc(format_date_dmY($to)) ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>المستودع:</strong> <?= (int) $store ?>
                        </td>
                    </tr>
                </table>
            </div>
            <?php
            // by-item = التصميم السابق. by-customer = الفئات أعمدة وأسماء العملاء صفوف.
            $oraLayout = 'by-customer';
            ?>
            <?php if ($rows === []): ?>
                <p class="muted">لا توجد مبيعات في الفترة المحددة.</p>
            <?php elseif ($oraLayout === 'by-item'): ?>
                <?php
                $n = count($rows);
                $i = 0;
                $oraGid = 0;
                while ($i < $n):
                    $repStart = $i;
                    $no = (int) ($rows[$i]['rep_no'] ?? 0);
                    while ($i < $n && (int) ($rows[$i]['rep_no'] ?? 0) === $no) {
                        $i++;
                    }
                    $repRows = array_slice($rows, $repStart, $i - $repStart);
                    $repLabel = trim((string) ($repRows[0]['rep_name'] ?? ''));
                    if ($repLabel === '' && $no > 0) {
                        $repLabel = 'مندوب ' . $no;
                    }
                    $customers = [];
                    $j = 0;
                    $repCount = count($repRows);
                    while ($j < $repCount) {
                        $acc = (string) ($repRows[$j]['customer_acc'] ?? '');
                        $slice = [];
                        while ($j < $repCount && (string) ($repRows[$j]['customer_acc'] ?? '') === $acc) {
                            $slice[] = $repRows[$j];
                            $j++;
                        }
                        $customers[] = $slice;
                    }
                    $repQty = 0.0;
                    $repAmt = 0.0;
                    foreach ($customers as $slice):
                        $custAcc = (string) ($slice[0]['customer_acc'] ?? '');
                        $custLabel = trim((string) ($slice[0]['customer_name'] ?? '')) ?: ($custAcc !== '' ? $custAcc : '—');
                        $cats = [];
                        $c = 0;
                        $sliceCount = count($slice);
                        while ($c < $sliceCount) {
                            $cat = trim((string) ($slice[$c]['cat_name'] ?? '')) ?: 'بدون فئة';
                            $lines = [];
                            $catQty = 0.0;
                            $catAmt = 0.0;
                            while ($c < $sliceCount && (trim((string) ($slice[$c]['cat_name'] ?? '')) ?: 'بدون فئة') === $cat) {
                                $catQty += (float) ($slice[$c]['qty'] ?? 0);
                                $catAmt += (float) ($slice[$c]['amount'] ?? 0);
                                $lines[] = $slice[$c];
                                $c++;
                            }
                            $cats[] = ['cat' => $cat, 'lines' => $lines, 'qty' => $catQty, 'amt' => $catAmt];
                        }
                        $custQty = 0.0;
                        $custAmt = 0.0;
                        foreach ($cats as $g) {
                            $custQty += $g['qty'];
                            $custAmt += $g['amt'];
                        }
                        $repQty += $custQty;
                        $repAmt += $custAmt;
                        ?>
                        <section class="ora-cust-block">
                            <header class="ora-cust-head">
                                <div class="ora-rep">المندوب: <?= esc($repLabel) ?></div>
                                <div class="ora-who">العميل: <?= esc($custLabel) ?></div>
                            </header>
                            <div class="report-sales-table-wrap ora-portrait-wrap">
                                <table class="report-sales-table ora-portrait-table" data-export-table>
                                    <thead>
                                        <tr>
                                            <th class="ora-vhead">فئة</th>
                                            <th class="ora-vhead">ايدي</th>
                                            <th class="ora-vhead">كود</th>
                                            <th class="ora-vhead">اسم المادة</th>
                                            <th class="ora-vhead">الكمية</th>
                                            <th class="ora-vhead">المبلغ</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($cats as $g):
                                            $oraGid++;
                                            $groupId = 'ora-g-' . $oraGid;
                                            ?>
                                            <tr class="ora-cat-head" data-ora-toggle="<?= esc($groupId) ?>" data-open="0">
                                                <td colspan="4"><span class="ora-arrow" aria-hidden="true"></span><?= esc($g['cat']) ?></td>
                                                <td dir="ltr"><?= esc($fmtQty($g['qty'])) ?></td>
                                                <td class="col-money" dir="ltr"><?= esc($fmtAmt($g['amt'])) ?></td>
                                            </tr>
                                            <?php foreach ($g['lines'] as $r):
                                                $idCode = trim((string) ($r['item_code'] ?? ''));
                                                $barcode = trim((string) ($r['item_barcode'] ?? ''));
                                                $sku = trim((string) ($r['item_sku'] ?? ''));
                                                $code = $barcode !== '' ? $barcode : ($sku !== '' && $sku !== $idCode ? $sku : '');
                                                ?>
                                                <tr class="ora-item" data-ora-lines="<?= esc($groupId) ?>" hidden>
                                                    <td><?= esc($g['cat']) ?></td>
                                                    <td class="ora-code" dir="ltr"><?= esc($idCode) ?></td>
                                                    <td class="ora-code" dir="ltr"><?= esc($code) ?></td>
                                                    <td class="ora-name"><?= esc((string) ($r['item_name'] ?? '')) ?></td>
                                                    <td dir="ltr"><?= esc($fmtQty((float) ($r['qty'] ?? 0))) ?></td>
                                                    <td class="col-money" dir="ltr"><?= esc($fmtAmt((float) ($r['amount'] ?? 0))) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endforeach; ?>
                                        <tr class="ora-cust-final">
                                            <td colspan="4">المجموع النهائي لمبيعات العميل</td>
                                            <td dir="ltr"><?= esc($fmtQty($custQty)) ?></td>
                                            <td class="col-money" dir="ltr"><?= esc($fmtAmt($custAmt)) ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    <?php endforeach; ?>
                    <p class="ora-rep-total">إجمالي المندوب <?= esc($repLabel) ?> — الكمية <?= esc($fmtQty($repQty)) ?> — المبلغ <?= esc($fmtAmt($repAmt)) ?></p>
                <?php endwhile; ?>
                <p class="ora-grand">الإجمالي — الكمية <?= esc($fmtQty((float) ($totals['qty'] ?? 0))) ?> — المبلغ <?= esc($fmtAmt((float) ($totals['amount'] ?? 0))) ?></p>
            <?php else: ?>
                <?php
                $summaryParts = [];
                $detailParts = [];
                $n = count($rows);
                $i = 0;
                while ($i < $n):
                    $repStart = $i;
                    $no = (int) ($rows[$i]['rep_no'] ?? 0);
                    while ($i < $n && (int) ($rows[$i]['rep_no'] ?? 0) === $no) {
                        $i++;
                    }
                    $repRows = array_slice($rows, $repStart, $i - $repStart);
                    $repLabel = trim((string) ($repRows[0]['rep_name'] ?? ''));
                    if ($repLabel === '' && $no > 0) {
                        $repLabel = 'مندوب ' . $no;
                    }
                    $catOrder = [];
                    $custMap = [];
                    foreach ($repRows as $r) {
                        $cat = trim((string) ($r['cat_name'] ?? '')) ?: 'بدون فئة';
                        if (!in_array($cat, $catOrder, true)) {
                            $catOrder[] = $cat;
                        }
                        $acc = (string) ($r['customer_acc'] ?? '');
                        if (!isset($custMap[$acc])) {
                            $label = trim((string) ($r['customer_name'] ?? ''));
                            $custMap[$acc] = [
                                'label' => $label !== '' ? $label : ($acc !== '' ? $acc : '—'),
                                'cats' => [],
                            ];
                        }
                        if (!isset($custMap[$acc]['cats'][$cat])) {
                            $custMap[$acc]['cats'][$cat] = ['amt' => 0.0, 'items' => []];
                        }
                        $itemName = trim((string) ($r['item_name'] ?? ''));
                        if ($itemName === '') {
                            $itemName = trim((string) ($r['item_code'] ?? ''));
                        }
                        $barcode = trim((string) ($r['item_barcode'] ?? ''));
                        if ($barcode === '' && preg_match('/\d{8,}/', $itemName, $bm)) {
                            $barcode = $bm[0];
                        }
                        $saleDate = trim((string) ($r['sale_date'] ?? ''));
                        $itemKey = trim((string) ($r['item_code'] ?? '')) . "\n" . $itemName . "\n" . $saleDate;
                        if (!isset($custMap[$acc]['cats'][$cat]['items'][$itemKey])) {
                            $custMap[$acc]['cats'][$cat]['items'][$itemKey] = [
                                'name' => $itemName,
                                'barcode' => $barcode,
                                'sale_date' => $saleDate,
                                'qty' => 0.0,
                                'amount' => 0.0,
                            ];
                        } elseif ($custMap[$acc]['cats'][$cat]['items'][$itemKey]['barcode'] === '' && $barcode !== '') {
                            $custMap[$acc]['cats'][$cat]['items'][$itemKey]['barcode'] = $barcode;
                        }
                        $lineAmt = (float) ($r['amount'] ?? 0);
                        $lineQty = (float) ($r['qty'] ?? 0);
                        $custMap[$acc]['cats'][$cat]['amt'] += $lineAmt;
                        $custMap[$acc]['cats'][$cat]['items'][$itemKey]['qty'] += $lineQty;
                        $custMap[$acc]['cats'][$cat]['items'][$itemKey]['amount'] += $lineAmt;
                    }
                    usort($catOrder, static function (string $a, string $b): int {
                        if ($a === 'بدون فئة') {
                            return 1;
                        }
                        if ($b === 'بدون فئة') {
                            return -1;
                        }
                        return strcmp($a, $b);
                    });
                    $colTotals = array_fill(0, count($catOrder), 0.0);
                    $repAmt = 0.0;
                    ob_start();
                    ?>
                    <section class="ora-cust-block">
                        <header class="ora-cust-head">
                            <div class="ora-rep">المندوب: <?= esc($repLabel) ?></div>
                        </header>
                        <div class="report-sales-table-wrap ora-portrait-wrap">
                            <table class="report-sales-table ora-portrait-table" data-export-table>
                                <thead>
                                    <tr>
                                        <th class="ora-vhead">العميل</th>
                                        <?php foreach ($catOrder as $cat): ?>
                                            <th class="ora-vhead ora-cat-col"><?= esc($cat) ?></th>
                                        <?php endforeach; ?>
                                        <th class="ora-vhead">المجموع</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($custMap as $slot):
                                        $rowAmt = 0.0;
                                        ?>
                                        <tr>
                                            <td class="ora-cust-name"><?= esc($slot['label']) ?></td>
                                            <?php foreach ($catOrder as $idx => $cat):
                                                $amt = (float) ($slot['cats'][$cat]['amt'] ?? 0);
                                                $colTotals[$idx] += $amt;
                                                $rowAmt += $amt;
                                                ?>
                                                <td dir="ltr"><?= $amt != 0.0 ? esc($fmtAmt($amt)) : '' ?></td>
                                            <?php endforeach; ?>
                                            <td class="col-money" dir="ltr"><?= esc($fmtAmt($rowAmt)) ?></td>
                                        </tr>
                                        <?php $repAmt += $rowAmt; ?>
                                    <?php endforeach; ?>
                                    <tr class="ora-cust-final">
                                        <td>المجموع</td>
                                        <?php foreach ($colTotals as $amt): ?>
                                            <td dir="ltr"><?= esc($fmtAmt($amt)) ?></td>
                                        <?php endforeach; ?>
                                        <td class="col-money" dir="ltr"><?= esc($fmtAmt($repAmt)) ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                    <?php
                    $summaryParts[] = (string) ob_get_clean();
                    ob_start();
                    ?>
                    <section class="ora-cust-block">
                        <header class="ora-cust-head">
                            <div class="ora-rep">المندوب: <?= esc($repLabel) ?></div>
                        </header>
                        <div class="report-sales-table-wrap ora-portrait-wrap">
                            <table class="report-sales-table ora-portrait-table ora-detail-table" data-export-table>
                                <thead>
                                    <tr>
                                        <th class="ora-vhead">التاريخ</th>
                                        <th class="ora-vhead">الفئة</th>
                                        <th class="ora-vhead">المادة</th>
                                        <th class="ora-vhead">الباركود</th>
                                        <th class="ora-vhead">الكمية</th>
                                        <th class="ora-vhead">المبلغ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($custMap as $slot):
                                        $custQty = 0.0;
                                        $custAmt = 0.0;
                                        $chunks = '';
                                        foreach ($catOrder as $cat) {
                                            $items = array_values($slot['cats'][$cat]['items'] ?? []);
                                            if ($items === []) {
                                                continue;
                                            }
                                            usort($items, static function (array $a, array $b): int {
                                                $d = strcmp((string) ($a['sale_date'] ?? ''), (string) ($b['sale_date'] ?? ''));
                                                if ($d !== 0) {
                                                    return $d;
                                                }

                                                return strcmp((string) $a['name'], (string) $b['name']);
                                            });
                                            $catQty = 0.0;
                                            ob_start();
                                            foreach ($items as $it) {
                                                $catQty += (float) $it['qty'];
                                                $showName = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/\d{8,}/', ' ', (string) $it['name'])));
                                                if ($showName === '') {
                                                    $showName = (string) $it['name'];
                                                }
                                                $saleIso = trim((string) ($it['sale_date'] ?? ''));
                                                $saleShow = $saleIso;
                                                if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $saleIso, $dm)) {
                                                    $saleShow = $dm[3] . '-' . $dm[2] . '-' . $dm[1];
                                                }
                                                ?>
                                                <tr>
                                                    <td dir="ltr"><?= esc($saleShow !== '' ? $saleShow : '—') ?></td>
                                                    <td><?= esc($cat) ?></td>
                                                    <td class="ora-name"><?= esc($showName) ?></td>
                                                    <td class="ora-code" dir="ltr"><?= esc((string) ($it['barcode'] ?? '')) ?></td>
                                                    <td dir="ltr"><?= esc($fmtQty((float) $it['qty'])) ?></td>
                                                    <td class="col-money" dir="ltr"><?= esc($fmtAmt((float) $it['amount'])) ?></td>
                                                </tr>
                                                <?php
                                            }
                                            $itemRows = (string) ob_get_clean();
                                            $chunks .= $itemRows;
                                            $chunks .= '<tr class="ora-cat-sum"><td colspan="4">مجموع ' . esc($cat) . '</td><td dir="ltr">'
                                                . esc($fmtQty($catQty)) . '</td><td class="col-money" dir="ltr">'
                                                . esc($fmtAmt((float) ($slot['cats'][$cat]['amt'] ?? 0))) . '</td></tr>';
                                            $custQty += $catQty;
                                            $custAmt += (float) ($slot['cats'][$cat]['amt'] ?? 0);
                                        }
                                        if ($chunks === '') {
                                            continue;
                                        }
                                        ?>
                                        <tr class="ora-cust-band"><td colspan="6"><?= esc($slot['label']) ?></td></tr>
                                        <?= $chunks ?>
                                        <tr class="ora-cust-final">
                                            <td colspan="4">المجموع النهائي لمبيعات العميل</td>
                                            <td dir="ltr"><?= esc($fmtQty($custQty)) ?></td>
                                            <td class="col-money" dir="ltr"><?= esc($fmtAmt($custAmt)) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                    <?php
                    $detailParts[] = (string) ob_get_clean();
                endwhile;
                $grandHtml = '<p class="ora-grand">الإجمالي — المبلغ ' . esc($fmtAmt((float) ($totals['amount'] ?? 0))) . '</p>';
                ?>
                <div class="ora-tools no-print">
                    <button type="button" class="btn" id="ora-excel-btn">Excel</button>
                    <button type="button" class="btn" id="ora-detail-btn">تفصيلي</button>
                </div>
                <div class="ora-view" id="ora-view-summary"><?= implode('', $summaryParts) . $grandHtml ?></div>
                <div class="ora-view" id="ora-view-detail" hidden><?= implode('', $detailParts) . $grandHtml ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<script type="application/json" id="report-sales-reps-json"><?= $repsJson ?></script>
<script type="application/json" id="report-sales-customers-json"><?= $customersJson ?></script>
<script type="application/json" id="report-sales-categories-json"><?= $categoriesJson ?></script>
<script type="application/json" id="report-sales-years-json"><?= $yearsJson ?></script>
<script type="application/json" id="report-sales-months-json"><?= $monthsJson ?></script>
<script type="application/json" id="ora-filter-selected"><?= $selectedJson ?></script>
<script src="<?= esc($exportJsUrl) ?>"></script>
<script>
function initOraMulti(root, items, selected) {
  if (!root) return null;
  var btn = root.querySelector('[data-ora-multi-btn]');
  var panel = root.querySelector('[data-ora-multi-panel]');
  var q = root.querySelector('[data-ora-multi-q]');
  var list = root.querySelector('[data-ora-multi-list]');
  var name = root.getAttribute('data-ora-name');
  var emptyLabel = root.getAttribute('data-ora-empty') || 'الكل';
  var collapseAll = root.getAttribute('data-ora-collapse-all') === '1';
  var allItems = Array.isArray(items) ? items.slice() : [];
  var visible = allItems.slice();
  var sel = {};
  (selected || []).forEach(function (id) { sel[String(id)] = true; });
  function selectedIds() { return Object.keys(sel).filter(function (k) { return sel[k]; }); }
  function allVisibleSelected() {
    if (!visible.length) return false;
    for (var i = 0; i < visible.length; i += 1) {
      if (!sel[String(visible[i].id)]) return false;
    }
    return true;
  }
  function emitIds() {
    var ids = selectedIds();
    if (collapseAll && allVisibleSelected()) return [];
    return ids;
  }
  function labelOf() {
    var ids = selectedIds();
    if (!ids.length || (collapseAll && allVisibleSelected())) return emptyLabel;
    if (ids.length === 1) {
      var one = allItems.find(function (it) { return String(it.id) === ids[0]; })
        || visible.find(function (it) { return String(it.id) === ids[0]; });
      return one ? one.label : ids[0];
    }
    return ids.length + ' محدد';
  }
  function syncHidden() {
    root.querySelectorAll('input[data-ora-hidden]').forEach(function (n) { n.remove(); });
    emitIds().forEach(function (id) {
      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      input.value = id;
      input.setAttribute('data-ora-hidden', '1');
      root.appendChild(input);
    });
    btn.textContent = labelOf();
  }
  function render() {
    var needle = String(q.value || '').trim().toLowerCase();
    list.innerHTML = '';
    visible.forEach(function (it) {
      var text = String(it.label || '');
      var code = String(it.code || '');
      var hay = (text + ' ' + code).toLowerCase();
      if (needle && hay.indexOf(needle) < 0) return;
      var row = document.createElement('div');
      row.className = 'ora-multi-item';
      var cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.checked = !!sel[String(it.id)];
      cb.addEventListener('change', function () {
        if (cb.checked) sel[String(it.id)] = true;
        else delete sel[String(it.id)];
        syncHidden();
      });
      var span = document.createElement('span');
      span.className = 'ora-multi-name';
      span.textContent = text;
      row.appendChild(cb);
      row.appendChild(span);
      if (code) {
        var codeEl = document.createElement('span');
        codeEl.className = 'ora-multi-code';
        codeEl.textContent = code;
        row.appendChild(codeEl);
      }
      row.addEventListener('click', function (ev) {
        if (ev.target === cb) return;
        cb.checked = !cb.checked;
        cb.dispatchEvent(new Event('change'));
      });
      list.appendChild(row);
    });
  }
  function place() {
    var rect = btn.getBoundingClientRect();
    if (panel.parentElement !== document.body) document.body.appendChild(panel);
    var width = Math.max(rect.width, 280);
    var left = rect.left;
    if (left + width > window.innerWidth - 8) left = Math.max(8, window.innerWidth - width - 8);
    panel.style.position = 'fixed';
    panel.style.top = Math.round(rect.bottom + 4) + 'px';
    panel.style.left = Math.round(left) + 'px';
    panel.style.width = Math.round(width) + 'px';
    panel.style.right = 'auto';
    panel.style.zIndex = '100600';
  }
  function setVisible(next) {
    visible = Array.isArray(next) ? next.slice() : [];
    var allowed = {};
    visible.forEach(function (it) { allowed[String(it.id)] = true; });
    Object.keys(sel).forEach(function (id) {
      if (!allowed[id]) delete sel[id];
    });
    syncHidden();
    if (!panel.hidden) render();
  }
  function selectedNow() { return selectedIds(); }
  var allBtn = panel.querySelector('[data-ora-all]');
  var noneBtn = panel.querySelector('[data-ora-none]');
  if (allBtn) {
    allBtn.addEventListener('click', function (ev) {
      ev.preventDefault();
      ev.stopPropagation();
      sel = {};
      visible.forEach(function (it) { sel[String(it.id)] = true; });
      syncHidden();
      render();
    });
  }
  if (noneBtn) {
    noneBtn.addEventListener('click', function (ev) {
      ev.preventDefault();
      ev.stopPropagation();
      sel = {};
      syncHidden();
      render();
    });
  }
  btn.addEventListener('click', function (ev) {
    ev.preventDefault();
    ev.stopPropagation();
    var open = panel.hidden;
    document.querySelectorAll('[data-ora-multi-panel]').forEach(function (p) { p.hidden = true; });
    panel.hidden = !open;
    if (!panel.hidden) { place(); render(); q.focus(); }
  });
  q.addEventListener('input', render);
  q.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') ev.preventDefault(); });
  document.addEventListener('click', function (ev) {
    if (!root.contains(ev.target) && !panel.contains(ev.target)) panel.hidden = true;
  });
  window.addEventListener('resize', function () { if (!panel.hidden) place(); });
  syncHidden();
  return { setVisible: setVisible, selectedIds: selectedNow };
}
document.addEventListener('DOMContentLoaded', function () {
  var selected = { reps: [], customers: [], categories: [], years: [], months: [] };
  try { selected = JSON.parse((document.getElementById('ora-filter-selected') || {}).textContent || '{}'); } catch (e3) {}
  var repItems = [];
  var custItems = [];
  var catItems = [];
  var yearItems = [];
  var monthItems = [];
  try { repItems = JSON.parse(document.getElementById('report-sales-reps-json').textContent || '[]'); } catch (eR) {}
  try { custItems = JSON.parse(document.getElementById('report-sales-customers-json').textContent || '[]'); } catch (e4) {}
  try { catItems = JSON.parse(document.getElementById('report-sales-categories-json').textContent || '[]'); } catch (e5) {}
  try { yearItems = JSON.parse(document.getElementById('report-sales-years-json').textContent || '[]'); } catch (e6) {}
  try { monthItems = JSON.parse(document.getElementById('report-sales-months-json').textContent || '[]'); } catch (e7) {}
  var repMulti = initOraMulti(document.getElementById('ora-rep-multi'), repItems, selected.reps || []);
  var custMulti = initOraMulti(document.getElementById('ora-cust-multi'), custItems, selected.customers || []);
  initOraMulti(document.getElementById('ora-cat-multi'), catItems, selected.categories || []);
  initOraMulti(document.getElementById('ora-year-multi'), yearItems, selected.years || []);
  initOraMulti(document.getElementById('ora-month-multi'), monthItems, selected.months || []);
  function filterCustomersByReps() {
    if (!custMulti) return;
    var codes = repMulti && repMulti.selectedIds ? repMulti.selectedIds() : [];
    var next = !codes.length
      ? custItems
      : custItems.filter(function (it) {
          var rc = Array.isArray(it.rep_codes) ? it.rep_codes : [];
          return codes.some(function (c) { return rc.indexOf(String(c)) >= 0; });
        });
    custMulti.setVisible(next);
  }
  var repRoot = document.getElementById('ora-rep-multi');
  if (repRoot) {
    repRoot.addEventListener('change', filterCustomersByReps);
    repRoot.addEventListener('click', function () { setTimeout(filterCustomersByReps, 0); });
  }
  filterCustomersByReps();
});
document.addEventListener('click', function (ev) {
  var excelBtn = ev.target.closest && ev.target.closest('#ora-excel-btn');
  if (excelBtn) {
    var view = document.querySelector('.ora-view:not([hidden])');
    if (!view) return;
    var html = '<html><head><meta charset="UTF-8"></head><body dir="rtl">' + view.innerHTML + '</body></html>';
    var blob = new Blob(['\uFEFF' + html], { type: 'application/vnd.ms-excel;charset=utf-8' });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = 'مبيعات-المندوب-حسب-الفئة.xls';
    document.body.appendChild(a);
    a.click();
    setTimeout(function () { URL.revokeObjectURL(url); a.remove(); }, 200);
    return;
  }
  var detailBtn = ev.target.closest && ev.target.closest('#ora-detail-btn');
  if (detailBtn) {
    var sum = document.getElementById('ora-view-summary');
    var det = document.getElementById('ora-view-detail');
    if (!sum || !det) return;
    var openDetail = det.hasAttribute('hidden');
    if (openDetail) {
      det.removeAttribute('hidden');
      sum.setAttribute('hidden', '');
      detailBtn.textContent = 'ملخص';
    } else {
      sum.removeAttribute('hidden');
      det.setAttribute('hidden', '');
      detailBtn.textContent = 'تفصيلي';
    }
    return;
  }
  var head = ev.target.closest && ev.target.closest('[data-ora-toggle]');
  if (!head) return;
  var id = head.getAttribute('data-ora-toggle');
  var open = head.getAttribute('data-open') === '1';
  head.setAttribute('data-open', open ? '0' : '1');
  document.querySelectorAll('[data-ora-lines="' + id + '"]').forEach(function (tr) {
    tr.hidden = open;
  });
});
</script>
