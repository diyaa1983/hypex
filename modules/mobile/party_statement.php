<?php
declare(strict_types=1);

require_once app_path('includes/mobile_auth.php');

$pdo = db();
$customers = $pdo->query(
    'SELECT id, name_ar FROM crm_customer WHERE is_active = 1 ORDER BY name_ar LIMIT 800'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$suppliers = $pdo->query(
    'SELECT id, name_ar FROM crm_supplier WHERE is_active = 1 ORDER BY name_ar LIMIT 800'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$fromDefault = format_date_dmY(app_default_date_from());
$toDefault = format_date_dmY(app_default_date_to());

$jsV = is_file(app_path('assets/mobile/party-statement.js'))
    ? (string) filemtime(app_path('assets/mobile/party-statement.js'))
    : '';
?>
<div class="m-ora12 m-ora12-invoice m-ora12-party-stmt">
<div class="m-ora12-workspace m-party-stmt-page">
    <section class="m-ora12-panel">
        <h2 class="m-ora12-panel__title">معايير الكشف</h2>
        <div class="m-ora12-panel__body">
        <div class="m-meta-grid m-ora12-meta">
            <div class="m-field m-field--full">
                <span class="m-field-label">نوع الطرف</span>
                <div class="m-seg m-ora12-chips" role="group" aria-label="نوع الطرف">
                    <label class="m-seg-item"><input type="radio" name="m_ps_type" value="customer" checked> عميل</label>
                    <label class="m-seg-item"><input type="radio" name="m_ps_type" value="supplier"> مورد</label>
                </div>
            </div>
            <div class="m-field m-field--full m-ps-pick-block" id="m-ps-pick-customer" data-party-pick="customer">
                <span class="m-field-label">العميل</span>
                <input type="hidden" id="m-ps-customer-id" value="">
                <div class="m-customer-chosen" id="m-ps-customer-chosen" hidden>
                    <span class="m-customer-chosen-name" id="m-ps-customer-label"></span>
                    <button type="button" class="m-ps-pick-clear" id="m-ps-clear-customer" aria-label="إلغاء اختيار العميل">×</button>
                </div>
                <button type="button" class="m-btn m-btn--pick m-btn--block" id="m-ps-open-customer">
                    <svg class="m-btn-pick-ico" viewBox="0 0 16 16" width="18" height="18" aria-hidden="true"><rect x="1.5" y="1.5" width="5.5" height="5.5" rx="1" fill="currentColor"/><rect x="9" y="1.5" width="5.5" height="5.5" rx="1" fill="currentColor"/><rect x="1.5" y="9" width="5.5" height="5.5" rx="1" fill="currentColor"/><rect x="9" y="9" width="5.5" height="5.5" rx="1" fill="currentColor"/></svg>
                    <span class="m-btn-pick-label">اختيار العميل</span>
                </button>
            </div>
            <div class="m-field m-field--full m-ps-pick-block m-ps-pick-block--off" id="m-ps-pick-supplier" data-party-pick="supplier">
                <span class="m-field-label">المورد</span>
                <input type="hidden" id="m-ps-supplier-id" value="">
                <div class="m-customer-chosen" id="m-ps-supplier-chosen" hidden>
                    <span class="m-customer-chosen-name" id="m-ps-supplier-label"></span>
                    <button type="button" class="m-ps-pick-clear" id="m-ps-clear-supplier" aria-label="إلغاء اختيار المورد">×</button>
                </div>
                <button type="button" class="m-btn m-btn--pick m-btn--block" id="m-ps-open-supplier">
                    <svg class="m-btn-pick-ico" viewBox="0 0 16 16" width="18" height="18" aria-hidden="true"><rect x="1.5" y="1.5" width="5.5" height="5.5" rx="1" fill="currentColor"/><rect x="9" y="1.5" width="5.5" height="5.5" rx="1" fill="currentColor"/><rect x="1.5" y="9" width="5.5" height="5.5" rx="1" fill="currentColor"/><rect x="9" y="9" width="5.5" height="5.5" rx="1" fill="currentColor"/></svg>
                    <span class="m-btn-pick-label">اختيار المورد</span>
                </button>
            </div>
            <label class="m-field">
                <span class="m-field-label">من تاريخ</span>
                <input class="m-input" type="text" id="m-ps-from" inputmode="numeric" placeholder="يوم-شهر-سنة" dir="ltr" value="<?= esc($fromDefault) ?>">
            </label>
            <label class="m-field">
                <span class="m-field-label">إلى تاريخ</span>
                <input class="m-input" type="text" id="m-ps-to" inputmode="numeric" placeholder="يوم-شهر-سنة" dir="ltr" value="<?= esc($toDefault) ?>">
            </label>
        </div>
        </div>
    </section>

    <div id="m-ps-loading" class="m-inv-view-loading muted" hidden>جاري التحميل...</div>
    <div id="m-ps-result" class="m-ps-result" hidden>
        <section class="m-ora12-panel m-ps-result-card">
            <h2 class="m-ora12-panel__title" id="m-ps-result-title">نتيجة الكشف</h2>
            <div class="m-ora12-panel__body m-ora12-panel__body--flush">
            <div id="m-ps-summary" class="m-ps-summary" hidden></div>
            <div class="m-ps-table-wrap" id="m-ps-table-wrap">
                <table class="m-ps-table" id="m-ps-table">
                    <thead>
                        <tr>
                            <th class="m-ps-th m-ps-th--date">التاريخ</th>
                            <th class="m-ps-th m-ps-th--desc">البيان</th>
                            <th class="m-ps-th m-ps-th--doc">الرقم</th>
                            <th class="m-ps-th m-ps-th--money">مدين</th>
                            <th class="m-ps-th m-ps-th--money">دائن</th>
                            <th class="m-ps-th m-ps-th--money">الرصيد</th>
                        </tr>
                    </thead>
                    <tbody id="m-ps-lines-body"></tbody>
                    <tfoot id="m-ps-lines-foot" hidden></tfoot>
                </table>
            </div>
            </div>
        </section>
    </div>
</div>
</div>

<div id="m-ps-customer-picker" class="m-picker m-picker--customers" hidden aria-hidden="true">
    <header class="m-picker-head">
        <button type="button" class="m-picker-back" id="m-ps-customer-close" aria-label="رجوع">← رجوع</button>
        <h3 class="m-picker-title">اختيار العميل</h3>
    </header>
    <div class="m-picker-search-wrap">
        <input type="search" class="m-input" id="m-ps-customer-search" placeholder="بحث بالاسم..." autocomplete="off">
    </div>
    <div class="m-picker-body">
        <div id="m-ps-customer-grid" class="m-customer-grid"></div>
        <p id="m-ps-customer-empty" class="m-picker-empty muted" hidden>لا نتائج</p>
    </div>
</div>

<div id="m-ps-supplier-picker" class="m-picker m-picker--customers" hidden aria-hidden="true">
    <header class="m-picker-head">
        <button type="button" class="m-picker-back" id="m-ps-supplier-close" aria-label="رجوع">← رجوع</button>
        <h3 class="m-picker-title">اختيار المورد</h3>
    </header>
    <div class="m-picker-search-wrap">
        <input type="search" class="m-input" id="m-ps-supplier-search" placeholder="بحث بالاسم..." autocomplete="off">
    </div>
    <div class="m-picker-body">
        <div id="m-ps-supplier-grid" class="m-customer-grid"></div>
        <p id="m-ps-supplier-empty" class="m-picker-empty muted" hidden>لا نتائج</p>
    </div>
</div>

<div id="m-ps-pdf-overlay" class="m-inv-pdf-overlay" hidden aria-hidden="true">
    <div id="m-ps-pdf-preview" class="m-inv-pdf-preview"></div>
</div>

<script>
window.MPartyStatement = {
    apiUrl: <?= json_encode(app_url('api/mobile_party_statement.php'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    customers: <?= json_encode($customers, JSON_UNESCAPED_UNICODE) ?>,
    suppliers: <?= json_encode($suppliers, JSON_UNESCAPED_UNICODE) ?>,
    csrf: <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" crossorigin="anonymous"></script>
<script src="<?= esc(app_url('assets/mobile/party-statement.js')) ?><?= $jsV !== '' ? '?v=' . esc($jsV) : '' ?>" defer></script>
