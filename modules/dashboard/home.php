<?php
declare(strict_types=1);

require_once app_path('includes/dashboard_stats.php');
require_once app_path('includes/dashboard_permissions.php');

$pdo = db();
$dash = dashboard_collect($pdo);

$cssPath = app_path('assets/css/dashboard.css');
$cssUrl = app_url('assets/css/dashboard.css');
if (is_file($cssPath)) {
    $cssUrl .= '?v=' . (string) filemtime($cssPath);
}
echo '<link rel="stylesheet" href="' . esc($cssUrl) . '">';

$hero = $dash['hero'];
$highlights = $dash['highlights'];
$sensitiveAccounts = $dash['sensitive_accounts'] ?? [];
$liabilities = $dash['liabilities'] ?? [];
$sections = $dash['sections'];
$recentSales = $dash['recent_sales'];
$checkAlerts = $dash['check_alerts'] ?? [];
$checkSummary = $dash['check_alerts_summary'] ?? [
    'total' => 0, 'overdue' => 0, 'today' => 0, 'soon' => 0, 'due' => 0, 'amount_due' => 0.0, 'total_amount' => 0.0,
];
$checkOutAlerts = $dash['check_out_alerts'] ?? [];
$checkOutSummary = $dash['check_out_alerts_summary'] ?? [
    'total' => 0, 'overdue' => 0, 'today' => 0, 'soon' => 0, 'due' => 0, 'amount_due' => 0.0,
];
$showCheckKpis = dashboard_widget_can('dashboard_panel_checks');
$allCheckAlerts = array_merge($checkAlerts, $checkOutAlerts);
$checksJson = json_encode($allCheckAlerts, JSON_UNESCAPED_UNICODE);
if ($checksJson === false) {
    $checksJson = '[]';
}
$apiReceiptUrl = app_url('api/fin_receipt_view.php');
$apiPaymentUrl = app_url('api/fin_payment_view.php');
$finChecksUrl = app_url('index.php?r=fin_checks&status=pending');
$finOutgoingUrl = app_url('index.php?r=fin_outgoing_checks');
$kpiToneClass = static function (string $tone): string {
    return match ($tone) {
        'primary' => ' dashboard-ora-kpi--primary',
        'success' => ' dashboard-ora-kpi--success',
        'warn' => ' dashboard-ora-kpi--warn',
        'danger' => ' dashboard-ora-kpi--danger',
        default => '',
    };
};
$renderAccountKpi = static function (array $item) use ($kpiToneClass): void {
    $itemUrl = trim((string) ($item['url'] ?? ''));
    $tone = $kpiToneClass((string) ($item['tone'] ?? ''));
    $itemClick = !empty($item['click_filter'])
        ? ' dashboard-ora-kpi--clickable dashboard-kpi--clickable js-check-alert-open'
        : '';
    $details = $item['details'] ?? [];
    if ((!is_array($details) || $details === []) && !empty($item['hint'])) {
        $details = [[
            'label' => (string) $item['hint'],
            'value' => (string) ($item['value'] ?? ''),
        ]];
    }
    $hasTip = is_array($details) && $details !== [];
    $classes = 'dashboard-ora-kpi dashboard-ora-kpi--uniform' . $tone . $itemClick;

    if ($itemClick !== '') {
        ?>
        <button type="button"
            class="<?= $classes ?>"
            data-filter="<?= esc((string) $item['click_filter']) ?>"
            data-title="<?= esc((string) ($item['label'] ?? 'شيكات قيد التحصيل')) ?>"
            title="اضغط لعرض التفاصيل">
        <?php
    } elseif ($itemUrl !== '') {
        ?>
        <a class="<?= $classes ?>" href="<?= esc($itemUrl) ?>" title="عرض كشف الحساب">
        <?php
    } else {
        ?>
        <article class="<?= $classes ?>">
        <?php
    }
    ?>
        <span class="dashboard-ora-kpi-label"><?= esc((string) ($item['label'] ?? '')) ?></span>
        <span class="dashboard-ora-kpi-value"><?= esc((string) ($item['value'] ?? '')) ?></span>
        <?php if (!empty($item['hint'])): ?>
        <span class="dashboard-ora-kpi-hint"><?= esc((string) $item['hint']) ?></span>
        <?php endif; ?>
        <?php if ($hasTip): ?>
        <div class="dashboard-ora-kpi-tip" role="tooltip">
            <span class="dashboard-ora-kpi-tip__title"><?= esc((string) ($item['label'] ?? '')) ?></span>
            <ul class="dashboard-ora-kpi-tip-list">
                <?php foreach ($details as $row): ?>
                <?php
                $rowUrl = trim((string) ($row['url'] ?? ''));
                $rowWarn = ((string) ($row['tone'] ?? '')) === 'warn'
                    ? ' dashboard-ora-kpi-tip-row--warn'
                    : '';
                $rowCode = trim((string) ($row['code'] ?? ''));
                ?>
                <li class="dashboard-ora-kpi-tip-row<?= $rowWarn ?>">
                    <?php if ($rowUrl !== ''): ?>
                    <a class="dashboard-ora-kpi-tip-link" href="<?= esc($rowUrl) ?>">
                        <span class="dashboard-ora-kpi-tip-name">
                            <?php if ($rowCode !== ''): ?>
                            <span class="dashboard-ora-kpi-tip-code"><?= esc($rowCode) ?></span>
                            <?php endif; ?>
                            <?= esc((string) ($row['label'] ?? '')) ?>
                        </span>
                        <span class="dashboard-ora-kpi-tip-amt"><?= esc((string) ($row['value'] ?? '')) ?></span>
                    </a>
                    <?php else: ?>
                    <span class="dashboard-ora-kpi-tip-static">
                        <span class="dashboard-ora-kpi-tip-name">
                            <?php if ($rowCode !== ''): ?>
                            <span class="dashboard-ora-kpi-tip-code"><?= esc($rowCode) ?></span>
                            <?php endif; ?>
                            <?= esc((string) ($row['label'] ?? '')) ?>
                        </span>
                        <span class="dashboard-ora-kpi-tip-amt"><?= esc((string) ($row['value'] ?? '')) ?></span>
                    </span>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    <?php
    if ($itemClick !== '') {
        echo '</button>';
    } elseif ($itemUrl !== '') {
        echo '</a>';
    } else {
        echo '</article>';
    }
};
$userLabel = $hero['user'] !== '' ? $hero['user'] : 'مستخدم النظام';
$headerMeta = esc($hero['company']) . ' · ' . esc($hero['weekday']) . ' ' . esc($hero['date']);
?>
<div class="dashboard-ora">
    <header class="dashboard-ora-screen-title" role="banner">
        <h1 class="dashboard-ora-screen-title__text">لوحة التحكم — مرحباً، <?= esc($userLabel) ?></h1>
        <span class="dashboard-ora-screen-title__meta"><?= $headerMeta ?></span>
    </header>

    <div class="dashboard-ora-workspace">

    <?php if ($highlights !== []): ?>
    <section class="dashboard-ora-panel" aria-label="مؤشرات رئيسية">
        <h2 class="dashboard-ora-panel__title">مؤشرات رئيسية</h2>
        <div class="dashboard-ora-panel__body">
            <div class="dashboard-ora-kpi-grid">
                <?php foreach ($highlights as $kpi): ?>
                <?php
                $kpiClick = !empty($kpi['click_filter'])
                    ? ' dashboard-ora-kpi--clickable dashboard-kpi--clickable js-check-alert-open'
                    : '';
                $kpiUrl = trim((string) ($kpi['url'] ?? ''));
                $tag = $kpiClick !== '' ? 'button' : ($kpiUrl !== '' ? 'a' : 'article');
                ?>
                <<?= $tag ?> class="dashboard-ora-kpi<?= $kpiToneClass((string) ($kpi['tone'] ?? '')) ?><?= $kpiClick ?>"
                    <?php if ($kpiClick !== ''): ?>
                    type="button"
                    data-filter="<?= esc((string) $kpi['click_filter']) ?>"
                    data-direction="<?= esc((string) ($kpi['direction'] ?? '')) ?>"
                    data-alert-days="<?= (int) ($kpi['alert_days'] ?? 7) ?>"
                    data-title="<?= esc((string) ($kpi['label'] ?? 'شيكات قيد الاستحقاق')) ?>"
                    title="اضغط لعرض التفاصيل"
                    <?php elseif ($kpiUrl !== ''): ?>
                    href="<?= esc($kpiUrl) ?>"
                    <?php endif; ?>>
                    <span class="dashboard-ora-kpi-label"><?= esc($kpi['label']) ?></span>
                    <span class="dashboard-ora-kpi-value"><?= esc($kpi['value']) ?></span>
                    <?php if (!empty($kpi['hint'])): ?>
                    <span class="dashboard-ora-kpi-hint"><?= esc($kpi['hint']) ?></span>
                    <?php endif; ?>
                </<?= $tag ?>>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($sensitiveAccounts !== []): ?>
    <section class="dashboard-ora-panel" aria-label="الصندوق والحسابات">
        <h2 class="dashboard-ora-panel__title">الصندوق والحسابات</h2>
        <p class="dashboard-ora-panel__sub">أرصدة من الدفتر العام — الصندوق، البنوك، الذمم، والمخزون</p>
        <div class="dashboard-ora-panel__body">
            <div class="dashboard-ora-kpi-grid dashboard-ora-kpi-grid--treasury">
                <?php foreach ($sensitiveAccounts as $item): ?>
                <?php $renderAccountKpi($item); ?>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($liabilities !== []): ?>
    <section class="dashboard-ora-panel" aria-label="المستحقات">
        <h2 class="dashboard-ora-panel__title">المستحقات</h2>
        <p class="dashboard-ora-panel__sub">أرصدة من الدفتر العام — رواتب، ضمان اجتماعي، ضرائب</p>
        <div class="dashboard-ora-panel__body">
            <div class="dashboard-ora-kpi-grid dashboard-ora-kpi-grid--liabilities">
                <?php foreach ($liabilities as $item): ?>
                <?php $renderAccountKpi($item); ?>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <?php
    require_once app_path('includes/fin_check_due_email.php');
    require_once app_path('includes/fin_out_check_due_email.php');
    $inEmailCfgUi = fin_check_due_email_settings($pdo);
    $outEmailCfgUi = fin_out_check_due_email_settings($pdo);
    $inAlertDaysUi = max(1, min(60, (int) ($inEmailCfgUi['days_before'] ?? 5)));
    $outAlertDaysUi = max(1, min(60, (int) ($outEmailCfgUi['days_before'] ?? 5)));
    $inOnDueUi = !empty($inEmailCfgUi['on_due_day']);
    $outOnDueUi = !empty($outEmailCfgUi['on_due_day']);
    $checkInAlertWindow = static function (array $c, int $daysBefore, bool $onDueDay): bool {
        if (!array_key_exists('days_until_due', $c) || $c['days_until_due'] === null || $c['days_until_due'] === '') {
            return false;
        }
        $d = (int) $c['days_until_due'];
        if ($d < 0) {
            return true;
        }
        if ($d === 0) {
            return $onDueDay;
        }

        return $d > 0 && $d <= $daysBefore;
    };
    $inDueRows = array_values(array_filter(
        $checkAlerts,
        static function (array $c) use ($checkInAlertWindow, $inAlertDaysUi, $inOnDueUi): bool {
            return $checkInAlertWindow($c, $inAlertDaysUi, $inOnDueUi);
        }
    ));
    $outDueRows = array_values(array_filter(
        $checkOutAlerts,
        static function (array $c) use ($checkInAlertWindow, $outAlertDaysUi, $outOnDueUi): bool {
            return $checkInAlertWindow($c, $outAlertDaysUi, $outOnDueUi);
        }
    ));
    ?>
    <?php if ($inDueRows !== [] || $outDueRows !== []): ?>
    <section class="dashboard-ora-panel" aria-label="تفاصيل الشيكات قيد الاستحقاق">
        <h2 class="dashboard-ora-panel__title">تفاصيل الشيكات قيد الاستحقاق</h2>
        <p class="dashboard-ora-panel__sub">قائمة مختصرة للشيكات المتأخرة والمستحقة قريباً — اضغط الصف للتفاصيل</p>

        <?php if ($inDueRows !== []): ?>
        <h3 class="dashboard-ora-subtitle">واردة</h3>
        <div class="dashboard-ora-panel__body dashboard-ora-panel__body--flush">
            <div class="dashboard-ora-table-wrap">
                <table class="dashboard-ora-table">
                    <thead>
                        <tr>
                            <th>الحالة</th>
                            <th>رقم الشيك</th>
                            <th>البنك</th>
                            <th>العميل</th>
                            <th>سند القبض</th>
                            <th>تاريخ الاستحقاق</th>
                            <th>المبلغ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($inDueRows as $chk): ?>
                        <?php
                        $urgency = (string) ($chk['urgency'] ?? 'pending');
                        $rowClass = 'dashboard-ora-row--' . esc($urgency);
                        ?>
                        <tr class="dashboard-ora-row-click dashboard-check-row-click js-check-alert-open <?= $rowClass ?>"
                            role="button" tabindex="0"
                            data-check-id="<?= (int) ($chk['check_id'] ?? 0) ?>"
                            data-direction="in"
                            title="اضغط لعرض تفاصيل الشيك">
                            <td>
                                <span class="dashboard-ora-status dashboard-ora-status--<?= esc($urgency) ?>">
                                    <?= esc((string) ($chk['urgency_label'] ?? '')) ?>
                                </span>
                            </td>
                            <td><?= esc((string) ($chk['check_no'] !== '' ? $chk['check_no'] : '—')) ?></td>
                            <td><?= esc((string) ($chk['bank_name'] !== '' ? $chk['bank_name'] : '—')) ?></td>
                            <td><?= esc((string) ($chk['party_name'] !== '' ? $chk['party_name'] : '—')) ?></td>
                            <td>
                                <?php if (!empty($chk['url'])): ?>
                                <a href="<?= esc((string) $chk['url']) ?>" onclick="event.stopPropagation()"><?= esc((string) $chk['voucher_no']) ?></a>
                                <?php else: ?>
                                <?= esc((string) $chk['voucher_no']) ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $dueDisp = (string) ($chk['due_date'] ?? '');
                                echo $dueDisp !== '' ? esc(format_date_dmY($dueDisp)) : '—';
                                ?>
                            </td>
                            <td dir="ltr" style="text-align:end"><?= esc(format_money((float) ($chk['amount'] ?? 0))) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($outDueRows !== []): ?>
        <h3 class="dashboard-ora-subtitle">صادرة</h3>
        <div class="dashboard-ora-panel__body dashboard-ora-panel__body--flush">
            <div class="dashboard-ora-table-wrap">
                <table class="dashboard-ora-table">
                    <thead>
                        <tr>
                            <th>الحالة</th>
                            <th>رقم الشيك</th>
                            <th>البنك</th>
                            <th>المستفيد</th>
                            <th>سند الصرف</th>
                            <th>تاريخ الاستحقاق</th>
                            <th>المبلغ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($outDueRows as $chk): ?>
                        <?php
                        $urgency = (string) ($chk['urgency'] ?? 'pending');
                        $rowClass = 'dashboard-ora-row--' . esc($urgency);
                        ?>
                        <tr class="dashboard-ora-row-click dashboard-check-row-click js-check-alert-open <?= $rowClass ?>"
                            role="button" tabindex="0"
                            data-check-id="<?= (int) ($chk['check_id'] ?? 0) ?>"
                            data-direction="out"
                            title="اضغط لعرض تفاصيل الشيك">
                            <td>
                                <span class="dashboard-ora-status dashboard-ora-status--<?= esc($urgency) ?>">
                                    <?= esc((string) ($chk['urgency_label'] ?? '')) ?>
                                </span>
                            </td>
                            <td><?= esc((string) ($chk['check_no'] !== '' ? $chk['check_no'] : '—')) ?></td>
                            <td><?= esc((string) ($chk['bank_name'] !== '' ? $chk['bank_name'] : '—')) ?></td>
                            <td><?= esc((string) ($chk['party_name'] !== '' ? $chk['party_name'] : '—')) ?></td>
                            <td>
                                <?php if (!empty($chk['url'])): ?>
                                <a href="<?= esc((string) $chk['url']) ?>" onclick="event.stopPropagation()"><?= esc((string) $chk['voucher_no']) ?></a>
                                <?php else: ?>
                                <?= esc((string) $chk['voucher_no']) ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $dueDisp = (string) ($chk['due_date'] ?? '');
                                echo $dueDisp !== '' ? esc(format_date_dmY($dueDisp)) : '—';
                                ?>
                            </td>
                            <td dir="ltr" style="text-align:end"><?= esc(format_money((float) ($chk['amount'] ?? 0))) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <p class="dashboard-ora-foot">
            <a href="<?= esc($finChecksUrl) ?>">الشيكات الواردة</a>
            &nbsp;|&nbsp;
            <a href="<?= esc($finOutgoingUrl) ?>">الشيكات الصادرة</a>
        </p>
    </section>
    <?php endif; ?>

    <?php if ($sections !== []): ?>
    <div class="dashboard-ora-grid" aria-label="ملخص الأقسام">
        <?php foreach ($sections as $sec): ?>
        <section class="dashboard-ora-panel">
            <h2 class="dashboard-ora-panel__title"><?= esc($sec['icon'] . ' ' . $sec['title']) ?></h2>
            <div class="dashboard-ora-panel__body">
                <div class="dashboard-ora-metric-grid">
                    <?php foreach ($sec['metrics'] as $m): ?>
                    <div class="dashboard-ora-metric<?= ($m['tone'] ?? '') === 'warn' ? ' dashboard-ora-metric--warn' : '' ?>">
                        <span class="dashboard-ora-metric-label"><?= esc($m['label']) ?></span>
                        <span class="dashboard-ora-metric-value"><?= esc($m['value']) ?></span>
                        <?php if (!empty($m['hint'])): ?>
                        <span class="dashboard-ora-metric-label" style="margin-top:0.12rem"><?= esc($m['hint']) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php if (!empty($sec['links'])): ?>
            <nav class="dashboard-ora-section-links" aria-label="اختصارات <?= esc($sec['title']) ?>">
                <?php foreach ($sec['links'] as $lnk): ?>
                <a class="dashboard-ora-btn" href="<?= esc($lnk['url']) ?>"><?= esc($lnk['label']) ?></a>
                <?php endforeach; ?>
            </nav>
            <?php endif; ?>
        </section>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($recentSales !== []): ?>
    <section class="dashboard-ora-panel">
        <h2 class="dashboard-ora-panel__title">آخر فواتير المبيعات</h2>
        <div class="dashboard-ora-panel__body dashboard-ora-panel__body--flush">
            <div class="dashboard-ora-table-wrap">
                <table class="dashboard-ora-table">
                    <thead>
                        <tr>
                            <th>الرقم</th>
                            <th>التاريخ</th>
                            <th>العميل</th>
                            <th>الإجمالي</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentSales as $row): ?>
                        <tr>
                            <td><a href="<?= esc($row['url']) ?>"><?= esc($row['no']) ?></a></td>
                            <td><?= esc($row['date']) ?></td>
                            <td><?= esc($row['party']) ?></td>
                            <td dir="ltr" style="text-align:end"><?= esc($row['total']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($sections === [] && $highlights === [] && $sensitiveAccounts === [] && $liabilities === [] && !$showCheckKpis && $recentSales === []): ?>
    <section class="dashboard-ora-panel">
        <h2 class="dashboard-ora-panel__title">بدء الاستخدام</h2>
        <div class="dashboard-ora-panel__body">
            <p class="dashboard-ora-empty">
                من القائمة الجانبية اختر المجال (مثل <strong>المبيعات</strong>) ثم المجموعة لفتح الشاشات.
                عند فتح أي شاشة يظهر شريط علوي؛ للعودة للقائمة استخدم زر <strong>خروج</strong>.
            </p>
        </div>
    </section>
    <?php endif; ?>

    </div><!-- .dashboard-ora-workspace -->
</div>

<?php if ($showCheckKpis): ?>
<script type="application/json" id="dashboard-checks-json"><?= $checksJson ?></script>
<div id="dashboard-check-modal" class="dashboard-check-modal" hidden
     data-api-receipt="<?= esc($apiReceiptUrl) ?>"
     data-api-payment="<?= esc($apiPaymentUrl) ?>">
    <div class="dashboard-check-modal-backdrop" aria-hidden="true"></div>
    <div class="dashboard-check-modal-panel" role="dialog" aria-modal="true" aria-labelledby="dashboard-check-modal-title">
        <header class="dashboard-check-modal-header">
            <h3 id="dashboard-check-modal-title" class="dashboard-check-modal-title">تفاصيل الشيك</h3>
            <button type="button" class="dashboard-check-modal-close js-check-modal-close" aria-label="إغلاق">×</button>
        </header>
        <div class="dashboard-check-modal-body"></div>
        <footer class="dashboard-check-modal-foot"></footer>
    </div>
</div>
<script src="<?= esc(app_url('assets/js/check-alerts-ui.js')) ?><?= is_file(app_path('assets/js/check-alerts-ui.js')) ? '?v=' . (string) filemtime(app_path('assets/js/check-alerts-ui.js')) : '' ?>" defer></script>
<?php endif; ?>
