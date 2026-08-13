<?php
declare(strict_types=1);

require_once app_path('includes/mobile_auth.php');
require_once app_path('includes/crm_sales_rep_schema.php');
require_once app_path('includes/sal_rep_route.php');

if (!user_can('m_rep_route_today') && !user_can('m_customer_orders') && !user_can('m_sales_invoices') && !user_is_system_admin()) {
    http_response_code(403);
    echo '<div class="m-alert m-alert--error">لا توجد صلاحية.</div>';
    return;
}

$pdo = db();
$uid = (int) (current_user()['id'] ?? 0);
$repId = crm_sales_rep_id_for_user($pdo, $uid);
$date = trim((string) ($_GET['date'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

$data = $repId
    ? sal_rep_route_customers_for_date($pdo, $repId, $date)
    : ['route' => null, 'customers' => [], 'route_date' => $date];
$customers = $data['customers'];
$geofenceOn = sal_rep_visit_geofence_setting_enabled($pdo);
$radius = (int) sal_rep_visit_radius_m();
?>
<div class="m-ora12 m-ora12-invoice">
<div class="m-ora12-workspace">
    <div class="m-card" style="margin-bottom:0.75rem;">
        <h2 style="margin:0 0 0.35rem;font-size:1.05rem;">جولات المندوبين</h2>
        <p class="muted" style="margin:0;">
            التاريخ: <strong dir="ltr"><?= esc(format_date_dmY($date)) ?></strong>
            — عدد العملاء: <strong><?= count($customers) ?></strong>
        </p>
        <?php if ($geofenceOn): ?>
            <p class="muted" style="margin:0.35rem 0 0;font-size:0.85rem;">
                الإلزام بالموقع مفعّل: يجب أن تكون ضمن حوالي <?= $radius ?>م من موقع العميل لعمل فاتورة أو طلب.
            </p>
        <?php endif; ?>
        <form method="get" action="<?= esc(mobile_url()) ?>" class="form-row" style="margin-top:0.65rem;gap:0.5rem;align-items:end;">
            <input type="hidden" name="r" value="m_rep_route_today">
            <label class="field" style="flex:1;">
                <span class="field-label">تاريخ آخر</span>
                <input class="input" type="date" name="date" value="<?= esc($date) ?>">
            </label>
            <button class="btn btn-secondary" type="submit">عرض</button>
        </form>
    </div>

    <?php if ($repId === null): ?>
        <div class="m-alert m-alert--error">حسابك غير مربوط بمندوب مبيعات.</div>
    <?php elseif ($customers === []): ?>
        <div class="m-alert m-alert--ok">لا يوجد خط سير محفوظ لهذا التاريخ.</div>
    <?php else: ?>
        <div class="m-card" style="padding:0;overflow:hidden;">
            <ul style="list-style:none;margin:0;padding:0;">
                <?php foreach ($customers as $i => $c): ?>
                    <li style="display:flex;gap:0.65rem;align-items:flex-start;padding:0.75rem 0.85rem;border-bottom:1px solid #e2e8f0;">
                        <span style="flex-shrink:0;width:1.6rem;height:1.6rem;border-radius:999px;background:#eff6ff;color:#1d4ed8;display:grid;place-items:center;font-size:0.75rem;font-weight:700;"><?= $i + 1 ?></span>
                        <div style="min-width:0;flex:1;">
                            <div style="font-weight:700;"><?= esc((string) $c['name']) ?></div>
                            <div class="muted" style="font-size:0.82rem;">
                                <code><?= esc((string) $c['code']) ?></code>
                                —
                                <?= !empty($c['has_gps']) ? '📍 موقع محدد' : 'بدون موقع على الخريطة' ?>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
</div>
</div>
