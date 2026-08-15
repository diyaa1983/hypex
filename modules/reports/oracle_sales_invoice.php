<?php
declare(strict_types=1);

/**
 * استعلام فاتورة بيع Oracle برقم الفاتورة (MAS.DAILY / TYPE=9)
 */

require_once app_path('includes/oracle_sales_invoice.php');
require_once app_path('includes/document_header.php');

$routeKey = 'report_oracle_sales_invoice';
$reportTitle = 'فاتورة بيع Oracle';

$invoiceNo = (int) ($_GET['invoice_no'] ?? $_GET['v_num'] ?? 0);
$year = (int) ($_GET['year'] ?? $_GET['vyear'] ?? 0);
$navAct = strtolower(trim((string) ($_GET['nav'] ?? '')));
$hasNav = in_array($navAct, ['first', 'last', 'prev', 'next'], true);

$result = null;
$err = '';
$showResult = false;
$nav = ['first' => null, 'prev' => null, 'next' => null, 'last' => null];

// شاشة مستند: تفتح دائماً (آخر فاتورة إن لم يُحدد رقم)
if ($hasNav) {
    $key = oracle_sales_invoice_resolve_nav($navAct, $invoiceNo, $year);
    if ($key === null) {
        $err = 'لا توجد فاتورة للتنقّل إليها.';
    } else {
        $invoiceNo = (int) $key['v_num'];
        $year = (int) $key['vyear'];
    }
} elseif ($invoiceNo < 1) {
    $key = oracle_sales_invoice_resolve_nav('last');
    if ($key !== null) {
        $invoiceNo = (int) $key['v_num'];
        $year = (int) $key['vyear'];
    }
}

if ($err === '' && $invoiceNo > 0) {
    $result = oracle_fetch_sales_invoice_by_no($invoiceNo, $year);
    if (empty($result['ok'])) {
        $err = (string) ($result['message'] ?? 'تعذر الاستعلام.');
    } else {
        $showResult = true;
        if (($result['message'] ?? '') !== '' && empty($result['header']) && empty($result['matches'])) {
            $err = (string) $result['message'];
        }
        if (is_array($result['nav'] ?? null)) {
            $nav = $result['nav'];
        }
    }
} elseif ($err === '') {
    $err = 'لا توجد فواتير بيع في Oracle.';
}

$fmtAmt = static function (float $n): string {
    return number_format(round($n, 3), 3, '.', ',');
};
$fmtDate = static function (string $iso): string {
    if ($iso === '') {
        return '—';
    }
    if (function_exists('format_date_dmY')) {
        $d = format_date_dmY($iso);

        return $d !== '' ? $d : $iso;
    }

    return $iso;
};

// أنماط مباشرة (inline) لأن الطباعة/PDF تنسخ منطقة الطباعة فقط بدون أنماط الصفحة
$stMetaTable = 'width:100%;border-collapse:collapse;margin:0 0 .55rem;font-size:.84rem;line-height:1.35';
$stMetaLab = 'border:1px solid #d8dee9;background:#f4f6fa;padding:.22rem .5rem;text-align:right;'
    . 'white-space:nowrap;color:#4b5563;font-weight:600;width:1%';
$stMetaVal = 'border:1px solid #d8dee9;padding:.22rem .5rem;text-align:right;font-weight:700';
$stTotTable = 'border-collapse:collapse;font-size:.86rem;margin-top:.55rem';
$stTotLab = 'border:1px solid #d8dee9;background:#f4f6fa;padding:.24rem .6rem;text-align:right;'
    . 'white-space:nowrap;font-weight:600';
$stTotVal = 'border:1px solid #d8dee9;padding:.24rem .6rem;text-align:left;font-weight:700;min-width:6.5rem';
$stTotLabG = $stTotLab . ';background:#e8edfb;font-weight:800;font-size:.92rem';
$stTotValG = $stTotVal . ';background:#e8edfb;font-weight:800;font-size:.92rem';

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$invCssPath = app_path('assets/css/sales-invoice.css');
$invCssUrl = app_url('assets/css/sales-invoice.css') . (is_file($invCssPath) ? '?v=' . (string) filemtime($invCssPath) : '');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');

$header = is_array($result['header'] ?? null) ? $result['header'] : null;
$lines = is_array($result['lines'] ?? null) ? $result['lines'] : [];
$matches = is_array($result['matches'] ?? null) ? $result['matches'] : [];
if ($header) {
    $invoiceNo = (int) ($header['v_num'] ?? $invoiceNo);
    $year = (int) ($header['vyear'] ?? $year);
}

$baseUrl = app_url('index.php') . '?r=' . rawurlencode($routeKey) . '&run=1';
$hrefKey = static function (?array $k) use ($baseUrl): string {
    if ($k === null || empty($k['v_num'])) {
        return '';
    }

    return $baseUrl . '&invoice_no=' . (int) $k['v_num'] . '&year=' . (int) ($k['vyear'] ?? 0);
};
$hrefNav = static function (string $act) use ($baseUrl, $invoiceNo, $year): string {
    return $baseUrl . '&nav=' . rawurlencode($act)
        . '&invoice_no=' . (int) $invoiceNo . '&year=' . (int) $year;
};
$navBtn = static function (string $href, string $label, string $title, bool $disabled): string {
    if ($disabled || $href === '') {
        return '<span class="btn" style="opacity:.35;pointer-events:none" title="' . esc($title) . '">' . $label . '</span>';
    }

    return '<a class="btn" href="' . esc($href) . '" title="' . esc($title) . '">' . $label . '</a>';
};

$pageDataAttrs = ' data-report-title="' . esc($reportTitle) . '"';
$pageDataAttrs .= ' data-report-route="' . esc($routeKey) . '"';
if ($header) {
    $pageDataAttrs .= ' data-export-label="' . esc('فاتورة ' . ($header['v_num'] ?? '') . '-' . ($header['vyear'] ?? '')) . '"';
}
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($invCssUrl) ?>">

<div class="card report-sales-page"<?= $pageDataAttrs ?>>

    <?php if ($err !== ''): ?>
        <div class="alert alert-error no-print" style="margin-bottom:1rem;"><?= esc($err) ?></div>
    <?php endif; ?>

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print" id="ora-inv-nav-form">
        <input type="hidden" name="r" value="<?= esc($routeKey) ?>">
        <input type="hidden" name="run" value="1">
        <div class="form-row" style="align-items:flex-end;flex-wrap:wrap;gap:.5rem">
            <label class="field" style="flex:0 1 auto">
                <span class="field-label">رقم الفاتورة</span>
                <div style="display:flex;align-items:center;gap:.25rem;direction:ltr;margin-top:.2rem">
                    <?= $navBtn($hrefNav('first'), '«', 'أول فاتورة', false) ?>
                    <?= $navBtn(
                        !empty($nav['prev']) ? $hrefKey($nav['prev']) : $hrefNav('prev'),
                        '‹',
                        'السابق',
                        empty($nav['prev']) && (bool) $header
                    ) ?>
                    <input class="input" type="text" name="invoice_no" id="ora_inv_no"
                           value="<?= $invoiceNo > 0 ? (int) $invoiceNo : '' ?>"
                           inputmode="numeric" dir="ltr" placeholder="رقم" autocomplete="off"
                           style="width:7.5rem;text-align:center"
                           title="أدخل الرقم ثم Enter · الأسهم للتقليب">
                    <?= $navBtn(
                        !empty($nav['next']) ? $hrefKey($nav['next']) : $hrefNav('next'),
                        '›',
                        'التالي',
                        empty($nav['next']) && (bool) $header
                    ) ?>
                    <?= $navBtn($hrefNav('last'), '»', 'آخر فاتورة', false) ?>
                </div>
            </label>
            <label class="field" style="flex:0 1 7rem">
                <span class="field-label">السنة</span>
                <input class="input" type="text" name="year" id="ora_inv_year"
                       value="<?= $year > 0 ? (int) $year : '' ?>"
                       inputmode="numeric" dir="ltr" placeholder="السنة" autocomplete="off">
            </label>
            <button class="btn btn-primary" type="submit">عرض</button>
            <span class="muted" style="font-size:.78rem;align-self:center">← سابق · → تالي · Home أول · End آخر</span>
        </div>
    </form>
    <script>
    (function(){
      var noEl=document.getElementById('ora_inv_no');
      if(!noEl) return;
      var base=<?= json_encode($baseUrl, JSON_UNESCAPED_UNICODE) ?>;
      var curNo=<?= (int) $invoiceNo ?>;
      var curYear=<?= (int) $year ?>;
      function goNav(act){
        location.href=base+'&nav='+encodeURIComponent(act)
          +'&invoice_no='+encodeURIComponent(curNo||0)
          +'&year='+encodeURIComponent(curYear||0);
      }
      noEl.addEventListener('keydown',function(e){
        if(e.key==='ArrowLeft'||e.key==='ArrowUp'){ e.preventDefault(); goNav('prev'); }
        else if(e.key==='ArrowRight'||e.key==='ArrowDown'){ e.preventDefault(); goNav('next'); }
        else if(e.key==='Home'){ e.preventDefault(); goNav('first'); }
        else if(e.key==='End'){ e.preventDefault(); goNav('last'); }
      });
      try{ noEl.focus(); noEl.select(); }catch(err){}
    })();
    </script>

    <?php if ($showResult && $matches && !$header): ?>
        <div class="report-sales-result">
            <p class="muted" style="margin-bottom:.75rem;"><?= esc((string) ($result['message'] ?? 'اختر السنة')) ?></p>
            <div class="report-sales-table-wrap">
                <table class="report-sales-table">
                    <thead>
                    <tr>
                        <th>رقم الفاتورة</th>
                        <th>السنة</th>
                        <th>التاريخ</th>
                        <th>العميل</th>
                        <th>المستودع</th>
                        <th>الإجمالي</th>
                        <th class="no-print"></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($matches as $m): ?>
                        <tr>
                            <td dir="ltr"><code><?= (int) ($m['v_num'] ?? 0) ?></code></td>
                            <td dir="ltr"><?= (int) ($m['vyear'] ?? 0) ?></td>
                            <td dir="ltr"><?= esc($fmtDate((string) ($m['vdate'] ?? ''))) ?></td>
                            <td dir="ltr"><?= esc((string) ($m['cust_acc'] ?? '')) ?></td>
                            <td dir="ltr"><?= (int) ($m['store'] ?? 0) ?></td>
                            <td dir="ltr"><?= esc($fmtAmt((float) ($m['gross'] ?? 0))) ?></td>
                            <td class="no-print">
                                <a class="btn btn-primary" style="padding:.25rem .7rem;font-size:.8rem"
                                   href="<?= esc(app_url('index.php?r=' . $routeKey
                                       . '&run=1&invoice_no=' . (int) ($m['v_num'] ?? 0)
                                       . '&year=' . (int) ($m['vyear'] ?? 0))) ?>">فتح</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($showResult && $header): ?>
        <div class="report-sales-result report-sales-print-area">
            <?= document_print_header_html($reportTitle, db()) ?>

            <?php
            $salesmanTxt = '—';
            if (!empty($header['salesman_no']) || ($header['salesman_name'] ?? '') !== '') {
                $salesmanTxt = (string) (int) ($header['salesman_no'] ?? 0);
                if (($header['salesman_name'] ?? '') !== '') {
                    $salesmanTxt .= ' — ' . (string) $header['salesman_name'];
                }
            }
            ?>
            <table style="<?= $stMetaTable ?>">
                <tr>
                    <td style="<?= $stMetaLab ?>">رقم الفاتورة</td>
                    <td style="<?= $stMetaVal ?>" dir="ltr"><?= (int) ($header['v_num'] ?? 0) ?> / <?= (int) ($header['vyear'] ?? 0) ?></td>
                    <td style="<?= $stMetaLab ?>">التاريخ</td>
                    <td style="<?= $stMetaVal ?>"><?= esc($fmtDate((string) ($header['vdate'] ?? ''))) ?></td>
                    <td style="<?= $stMetaLab ?>">المستودع</td>
                    <td style="<?= $stMetaVal ?>" dir="ltr"><?= (int) ($header['store'] ?? 0) ?></td>
                </tr>
                <tr>
                    <td style="<?= $stMetaLab ?>">رقم العميل</td>
                    <td style="<?= $stMetaVal ?>" dir="ltr"><?= esc((string) ($header['cust_acc'] ?? '')) ?></td>
                    <td style="<?= $stMetaLab ?>">اسم العميل</td>
                    <td style="<?= $stMetaVal ?>" colspan="3"><?= esc((string) ($header['customer_name'] ?? '')) ?: '—' ?></td>
                </tr>
                <tr>
                    <td style="<?= $stMetaLab ?>">البائع</td>
                    <td style="<?= $stMetaVal ?>" colspan="5"><?= esc($salesmanTxt) ?></td>
                </tr>
            </table>

            <div class="report-sales-table-wrap">
                <table class="report-sales-table">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>المادة</th>
                        <th>البيان</th>
                        <th>الفئة</th>
                        <th>التشغيلة</th>
                        <th>الوحدة</th>
                        <th>الكمية</th>
                        <th>بونص</th>
                        <th>السعر</th>
                        <th>ض%</th>
                        <th>الإجمالي</th>
                        <th>الضريبة</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$lines): ?>
                        <tr><td colspan="12" class="muted" style="text-align:center;padding:1rem;">لا بنود</td></tr>
                    <?php endif; ?>
                    <?php
                    $seq = 0;
                    foreach ($lines as $ln):
                        $seq++;
                        ?>
                        <tr>
                            <td><?= $seq ?></td>
                            <td dir="ltr"><code><?= esc((string) ($ln['item'] ?? '')) ?></code></td>
                            <td><?= esc((string) ($ln['item_name'] ?? '—')) ?></td>
                            <td dir="ltr"><?= esc((string) ($ln['cat'] ?? '')) ?></td>
                            <td dir="ltr"><?= esc((string) ($ln['batch'] ?? '')) ?></td>
                            <td dir="ltr"><?= esc((string) ($ln['unit_label'] ?? '—')) ?></td>
                            <td dir="ltr"><?= esc($fmtAmt((float) ($ln['qty'] ?? 0))) ?></td>
                            <td dir="ltr"><?= esc($fmtAmt((float) ($ln['bonus'] ?? 0))) ?></td>
                            <td dir="ltr"><?= esc($fmtAmt((float) ($ln['sell'] ?? 0))) ?></td>
                            <td dir="ltr"><?= esc(rtrim(rtrim(number_format((float) ($ln['tax_pct'] ?? 0), 2, '.', ''), '0'), '.') ?: '0') ?>%</td>
                            <td dir="ltr"><?= esc($fmtAmt((float) ($ln['line_gross'] ?? 0))) ?></td>
                            <td dir="ltr"><?= esc($fmtAmt((float) ($ln['vou_tax'] ?? 0))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php if ($lines): ?>
                    <tfoot>
                    <tr>
                        <td colspan="6">مجموع البنود</td>
                        <td dir="ltr"><?= esc($fmtAmt((float) ($header['qty_sum'] ?? 0))) ?></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td dir="ltr"><?= esc($fmtAmt((float) ($header['gross'] ?? 0))) ?></td>
                        <td dir="ltr"><?= esc($fmtAmt((float) ($header['tax_sum'] ?? 0))) ?></td>
                    </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>

            <?php if ($lines):
                $pd = (float) ($header['per_disc'] ?? 0);
                $discLab = 'الخصم';
                if ($pd > 0) {
                    $discLab .= ' (' . rtrim(rtrim(number_format($pd * 100, 2, '.', ''), '0'), '.') . '%)';
                }
                ?>
            <table style="<?= $stTotTable ?>">
                <tr>
                    <td style="<?= $stTotLab ?>">مجموع الفاتورة</td>
                    <td style="<?= $stTotVal ?>" dir="ltr"><?= esc($fmtAmt((float) ($header['gross'] ?? 0))) ?></td>
                </tr>
                <tr>
                    <td style="<?= $stTotLab ?>"><?= esc($discLab) ?></td>
                    <td style="<?= $stTotVal ?>" dir="ltr"><?= esc($fmtAmt((float) ($header['vou_disc'] ?? 0))) ?></td>
                </tr>
                <tr>
                    <td style="<?= $stTotLab ?>">الصافي قبل الضريبة</td>
                    <td style="<?= $stTotVal ?>" dir="ltr"><?= esc($fmtAmt((float) ($header['net'] ?? 0))) ?></td>
                </tr>
                <tr>
                    <td style="<?= $stTotLab ?>">قيمة الضريبة</td>
                    <td style="<?= $stTotVal ?>" dir="ltr"><?= esc($fmtAmt((float) ($header['tax_sum'] ?? 0))) ?></td>
                </tr>
                <tr>
                    <td style="<?= $stTotLabG ?>">الإجمالي النهائي</td>
                    <td style="<?= $stTotValG ?>" dir="ltr"><?= esc($fmtAmt((float) ($header['total'] ?? 0))) ?></td>
                </tr>
            </table>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>


<div id="sales-inv-export-host" class="sales-inv-export-host" aria-hidden="true"></div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" defer crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="<?= esc($exportJsUrl) ?>" defer></script>
