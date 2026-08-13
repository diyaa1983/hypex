<?php
declare(strict_types=1);

/**
 * تنبيهات الترويسة (جرس): شيكات + سندات تسليم بلا فاتورة + مستندات غير مرحّلة + فوترة غير مرسلة.
 *
 * @return array{
 *   enabled:bool,
 *   checks:list<array<string,mixed>>,
 *   alert_checks:list<array<string,mixed>>,
 *   delivery_alerts:list<array<string,mixed>>,
 *   unposted_alerts:list<array<string,mixed>>,
 *   einvoice_alerts:list<array<string,mixed>>,
 *   customer_order_alerts:list<array<string,mixed>>,
 *   summary:array{total:int,overdue:int,today:int,soon:int,alert_count:int,delivery_count:int,unposted_count:int,einvoice_count:int,customer_order_count:int},
 *   soon_days:int,
 *   _needs_refresh?:bool
 * }
 */
function header_check_notifications_collect(PDO $pdo, bool $forceFresh = false): array
{
    // للتخطيط السريع: أعِد الكاش فوراً ولا تُبطئ الصفحة — التحديث عبر AJAX
    if (!$forceFresh) {
        return header_check_notifications_for_layout();
    }

    return header_check_notifications_collect_fresh($pdo);
}

/** قراءة سريعة من الجلسة دون استعلامات ثقيلة (مسار التنقّل). */
function header_check_notifications_for_layout(): array
{
    $empty = header_check_notifications_empty_payload();
    $cached = $_SESSION['_header_check_notify_v3'] ?? null;
    if (is_array($cached) && isset($cached['data']) && is_array($cached['data'])) {
        $data = $cached['data'];
        $age = time() - (int) ($cached['at'] ?? 0);
        $data['_needs_refresh'] = $age >= 90;
        return $data;
    }

    // أول زيارة: لا نُبطئ الصفحة — الجرس يُحدَّث بعد الرسم
    $empty['enabled'] = header_check_notifications_user_can_see_cheap();
    $empty['_needs_refresh'] = true;

    return $empty;
}

/** @return array<string,mixed> */
function header_check_notifications_empty_payload(): array
{
    return [
        'enabled' => false,
        'checks' => [],
        'alert_checks' => [],
        'delivery_alerts' => [],
        'unposted_alerts' => [],
        'einvoice_alerts' => [],
        'customer_order_alerts' => [],
        'visit_checkout_alerts' => [],
        'summary' => [
            'total' => 0,
            'overdue' => 0,
            'today' => 0,
            'soon' => 0,
            'alert_count' => 0,
            'delivery_count' => 0,
            'unposted_count' => 0,
            'einvoice_count' => 0,
            'customer_order_count' => 0,
            'visit_checkout_count' => 0,
        ],
        'soon_days' => 7,
    ];
}

/** فحص صلاحيات رخيص من الجلسة فقط (بدون تحميل وحدات الإشعارات). */
function header_check_notifications_user_can_see_cheap(): bool
{
    $codes = [
        'cash_receipt', 'cash_receipts_list',
        'sales_invoices', 'sales_invoices_list',
        'sales_returns', 'sales_returns_list',
        'purchase_invoices', 'purchase_invoices_list',
        'purchase_returns', 'purchase_returns_list',
        'cash_payment', 'cash_payments_list',
        'journal_entries', 'warehouse_moves', 'inventory_stocktake',
        'sales_delivery', 'sales_customer_orders_approve',
        'sales_rep_visit_checkout_approve',
        'sales_einvoice', 'm_sales_einvoice',
    ];
    foreach ($codes as $code) {
        if (user_can($code)) {
            return true;
        }
    }

    return false;
}

function header_check_notifications_collect_fresh(PDO $pdo): array
{
    $empty = header_check_notifications_empty_payload();

    $canChecks = user_can('cash_receipt') || user_can('cash_receipts_list');
    require_once app_path('includes/sal_delivery_notifications.php');
    $canDelivery = sal_delivery_notifications_user_can_see();
    require_once app_path('includes/header_unposted_notifications.php');
    $canUnposted = header_unposted_notifications_user_can_see();
    require_once app_path('includes/sal_einvoice_notifications.php');
    $canEinvoice = sal_einvoice_notifications_user_can_see();
    require_once app_path('includes/sal_customer_order.php');
    $canCustomerOrders = sal_customer_order_notifications_user_can_see();
    require_once app_path('includes/sal_rep_visit.php');
    $canVisitCheckout = sal_rep_visit_checkout_notifications_user_can_see();

    if (!$canChecks && !$canDelivery && !$canUnposted && !$canEinvoice && !$canCustomerOrders && !$canVisitCheckout) {
        $_SESSION['_header_check_notify_v3'] = ['at' => time(), 'data' => $empty];

        return $empty;
    }

    $checks = [];
    $alertChecks = [];
    $deliveryAlerts = [];
    $unpostedAlerts = [];
    $einvoiceAlerts = [];
    $customerOrderAlerts = [];
    $visitCheckoutAlerts = [];
    $summary = [
        'total' => 0,
        'overdue' => 0,
        'today' => 0,
        'soon' => 0,
        'alert_count' => 0,
        'delivery_count' => 0,
        'unposted_count' => 0,
        'einvoice_count' => 0,
        'customer_order_count' => 0,
        'visit_checkout_count' => 0,
    ];
    $soonDays = 7;

    if ($canChecks) {
        require_once app_path('includes/fin_voucher_checks.php');
        require_once app_path('includes/fin_check_due_email.php');
        $settings = fin_check_due_email_settings($pdo);
        $soonDays = max(7, (int) ($settings['days_before'] ?? 5));
        $checks = fin_voucher_checks_pending_collection($pdo);

        foreach ($checks as $chk) {
            $summary['total']++;
            $urgency = (string) ($chk['urgency'] ?? '');
            if ($urgency === 'overdue') {
                $summary['overdue']++;
            } elseif ($urgency === 'today') {
                $summary['today']++;
            } elseif ($urgency === 'soon') {
                $summary['soon']++;
            }
            if (in_array($urgency, ['overdue', 'today', 'soon'], true)) {
                $alertChecks[] = $chk;
            }
        }
    }

    if ($canDelivery) {
        $deliveryAlerts = sal_delivery_uninvoiced_alerts($pdo);
        $summary['delivery_count'] = count($deliveryAlerts);
    }

    if ($canUnposted) {
        $unpostedAlerts = header_unposted_notifications_collect($pdo);
        $summary['unposted_count'] = count($unpostedAlerts);
        if (count($unpostedAlerts) >= HEADER_UNPOSTED_PANEL_MAX) {
            $summary['unposted_count'] = header_unposted_notifications_count($pdo);
        }
    }

    if ($canEinvoice) {
        $einvoiceAlerts = sal_einvoice_unsent_alerts_collect($pdo);
        $summary['einvoice_count'] = count($einvoiceAlerts);
        if (count($einvoiceAlerts) >= 20) {
            $summary['einvoice_count'] = sal_einvoice_unsent_count($pdo);
        }
    }

    if ($canCustomerOrders) {
        $customerOrderAlerts = sal_customer_order_pending_approve_alerts($pdo, 20);
        $summary['customer_order_count'] = count($customerOrderAlerts);
        if (count($customerOrderAlerts) >= 20) {
            $summary['customer_order_count'] = sal_customer_order_pending_approve_count($pdo);
        }
    }

    if ($canVisitCheckout) {
        $visitCheckoutAlerts = sal_rep_visit_pending_checkout_alerts($pdo, 20);
        $summary['visit_checkout_count'] = count($visitCheckoutAlerts);
        if (count($visitCheckoutAlerts) >= 20) {
            $summary['visit_checkout_count'] = sal_rep_visit_pending_checkout_count($pdo);
        }
    }

    $summary['alert_count'] = count($alertChecks)
        + $summary['delivery_count']
        + $summary['unposted_count']
        + $summary['einvoice_count']
        + $summary['customer_order_count']
        + $summary['visit_checkout_count'];

    $data = [
        'enabled' => true,
        'checks' => $checks,
        'alert_checks' => $alertChecks,
        'delivery_alerts' => $deliveryAlerts,
        'unposted_alerts' => $unpostedAlerts,
        'einvoice_alerts' => $einvoiceAlerts,
        'customer_order_alerts' => $customerOrderAlerts,
        'visit_checkout_alerts' => $visitCheckoutAlerts,
        'summary' => $summary,
        'soon_days' => $soonDays,
        '_needs_refresh' => false,
    ];
    $_SESSION['_header_check_notify_v3'] = ['at' => time(), 'data' => $data];

    return $data;
}

function header_check_notifications_invalidate_cache(): void
{
    unset($_SESSION['_header_check_notify_v3'], $_SESSION['_header_check_notify_v2']);
}

function header_check_notifications_user_can_see(): bool
{
    if (user_can('cash_receipt') || user_can('cash_receipts_list')) {
        return true;
    }
    require_once app_path('includes/sal_delivery_notifications.php');
    if (sal_delivery_notifications_user_can_see()) {
        return true;
    }
    require_once app_path('includes/header_unposted_notifications.php');
    if (header_unposted_notifications_user_can_see()) {
        return true;
    }
    require_once app_path('includes/sal_einvoice_notifications.php');
    if (sal_einvoice_notifications_user_can_see()) {
        return true;
    }
    require_once app_path('includes/sal_customer_order.php');
    if (sal_customer_order_notifications_user_can_see()) {
        return true;
    }
    require_once app_path('includes/sal_rep_visit.php');

    return sal_rep_visit_checkout_notifications_user_can_see();
}

function render_header_check_notifications(array $data): void
{
    if (!($data['enabled'] ?? false)) {
        return;
    }

    $summary = $data['summary'] ?? [];
    $alertCount = (int) ($summary['alert_count'] ?? 0);
    $alertChecks = $data['alert_checks'] ?? [];
    $deliveryAlerts = $data['delivery_alerts'] ?? [];
    $unpostedAlerts = $data['unposted_alerts'] ?? [];
    $einvoiceAlerts = $data['einvoice_alerts'] ?? [];
    $customerOrderAlerts = $data['customer_order_alerts'] ?? [];
    $visitCheckoutAlerts = $data['visit_checkout_alerts'] ?? [];
    $unpostedCount = (int) ($summary['unposted_count'] ?? 0);
    $einvoiceCount = (int) ($summary['einvoice_count'] ?? 0);
    $customerOrderCount = (int) ($summary['customer_order_count'] ?? 0);
    $visitCheckoutCount = (int) ($summary['visit_checkout_count'] ?? 0);
    $checksJson = json_encode($data['checks'] ?? [], JSON_UNESCAPED_UNICODE);
    if ($checksJson === false) {
        $checksJson = '[]';
    }

    $dashboardUrl = app_url('index.php?r=dashboard');
    $salesInvoicesUrl = app_url('index.php?r=sales_invoices');
    $customerOrdersApproveUrl = app_url('index.php?r=sales_customer_orders_approve');
    $visitCheckoutApproveUrl = app_url('index.php?r=sales_rep_visit_checkout_approve');
    $soonDays = (int) ($data['soon_days'] ?? 7);
    $hasChecks = $alertChecks !== [];
    $hasDeliveries = $deliveryAlerts !== [];
    $hasUnposted = $unpostedAlerts !== [];
    $hasEinvoice = $einvoiceAlerts !== [];
    $hasCustomerOrders = $customerOrderAlerts !== [];
    $hasVisitCheckout = $visitCheckoutAlerts !== [];
    $salesInvoicesListUrl = app_url('index.php?r=sales_invoices_list&filter=unposted');
    $salesDocumentsListUrl = app_url('index.php?r=sales_documents_list');
    $salesReturnsDocumentsListUrl = app_url('index.php?r=sales_returns_documents_list');
    $purchaseInvoicesListUrl = app_url('index.php?r=purchase_invoices_list&filter=unposted');
    $cashReceiptsListUrl = app_url('index.php?r=cash_receipts_list&filter=unposted');
    $cashPaymentsListUrl = app_url('index.php?r=cash_payments_list&filter=unposted');
    require_once app_path('includes/app_icons.php');
    $bellClass = 'app-check-bell js-app-check-bell';
    if ($alertCount > 0) {
        $bellClass .= ' has-alerts';
    }
    $needsRefresh = !empty($data['_needs_refresh']);
    $refreshUrl = app_url('api/header_notifications_refresh.php');
    ?>
    <div class="app-check-bell-wrap no-print"
         data-needs-refresh="<?= $needsRefresh ? '1' : '0' ?>"
         data-refresh-url="<?= esc($refreshUrl) ?>">
        <button type="button"
                class="<?= esc($bellClass) ?>"
                aria-label="التنبيهات<?= $alertCount > 0 ? ' — ' . $alertCount . ' تنبيه' : '' ?>"
                aria-expanded="false"
                aria-haspopup="true"
                title="التنبيهات<?= $alertCount > 0 ? ' (' . $alertCount . ')' : '' ?>">
            <span class="app-check-bell-icon" aria-hidden="true"><?= app_icon_svg('bell', 22) ?></span>
            <span class="app-check-bell-badge js-check-bell-badge" aria-hidden="true"<?= $alertCount > 0 ? '' : ' hidden' ?>><?= $alertCount > 99 ? '99+' : ($alertCount > 0 ? (string) $alertCount : '') ?></span>
        </button>
        <div class="app-check-bell-panel" hidden role="dialog" aria-label="قائمة التنبيهات">
            <header class="app-check-bell-panel-head">
                <strong>التنبيهات</strong>
                <?php if ($alertCount > 0): ?>
                <span class="app-check-bell-panel-count"><?= (int) $alertCount ?> تنبيه</span>
                <?php endif; ?>
            </header>
            <?php if (!$hasChecks && !$hasDeliveries && !$hasUnposted && !$hasEinvoice && !$hasCustomerOrders && !$hasVisitCheckout): ?>
            <p class="app-check-bell-panel-empty">لا توجد تنبيهات حالياً.</p>
            <?php else: ?>
            <?php if ($hasVisitCheckout): ?>
            <p class="app-check-bell-section-title">خروج يدوي من زيارة بانتظار الاعتماد<?= $visitCheckoutCount > 0 ? ' (' . $visitCheckoutCount . ')' : '' ?></p>
            <ul class="app-check-bell-list">
                <?php foreach ($visitCheckoutAlerts as $vc): ?>
                <li>
                    <a class="app-check-bell-item app-check-bell-item--link"
                       href="<?= esc((string) ($vc['url'] ?? $visitCheckoutApproveUrl)) ?>">
                        <span class="dashboard-check-status dashboard-check-status--pending">
                            <?= esc((string) ($vc['urgency_label'] ?? 'بانتظار اعتماد الخروج')) ?>
                        </span>
                        <span class="app-check-bell-item-main">
                            <span class="app-check-bell-item-no"><?= esc((string) (($vc['customer_code'] ?? '') !== '' ? $vc['customer_code'] : '—')) ?></span>
                            <span class="app-check-bell-item-party">
                                <?= esc((string) ($vc['customer_name'] ?? '—')) ?>
                                <?php if (trim((string) ($vc['sales_rep_name'] ?? '')) !== ''): ?>
                                · <?= esc((string) $vc['sales_rep_name']) ?>
                                <?php endif; ?>
                            </span>
                        </span>
                        <span class="app-check-bell-item-meta"><?= esc((string) ($vc['created_at'] ?? '—')) ?></span>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($visitCheckoutCount > count($visitCheckoutAlerts)): ?>
            <p class="app-check-bell-panel-more muted">و<?= $visitCheckoutCount - count($visitCheckoutAlerts) ?> طلباً إضافياً…</p>
            <?php endif; ?>
            <?php endif; ?>
            <?php if ($hasCustomerOrders): ?>
            <p class="app-check-bell-section-title">طلبات شراء بانتظار الاعتماد<?= $customerOrderCount > 0 ? ' (' . $customerOrderCount . ')' : '' ?></p>
            <ul class="app-check-bell-list">
                <?php foreach ($customerOrderAlerts as $ord): ?>
                <li>
                    <a class="app-check-bell-item app-check-bell-item--link"
                       href="<?= esc((string) ($ord['url'] ?? $customerOrdersApproveUrl)) ?>">
                        <span class="dashboard-check-status dashboard-check-status--pending">
                            <?= esc((string) ($ord['urgency_label'] ?? 'بانتظار الاعتماد')) ?>
                        </span>
                        <span class="app-check-bell-item-main">
                            <span class="app-check-bell-item-no"><?= esc((string) (($ord['order_no'] ?? '') !== '' ? $ord['order_no'] : '—')) ?></span>
                            <span class="app-check-bell-item-party">
                                <?= esc((string) ($ord['customer_name'] ?? '—')) ?>
                                <?php if (trim((string) ($ord['sales_rep_name'] ?? '')) !== ''): ?>
                                · <?= esc((string) $ord['sales_rep_name']) ?>
                                <?php endif; ?>
                            </span>
                        </span>
                        <span class="app-check-bell-item-meta">
                            <?php
                            $ordDate = trim((string) ($ord['order_date'] ?? ''));
                            echo $ordDate !== '' ? esc(format_date_dmY($ordDate)) : '—';
                            ?>
                        </span>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($customerOrderCount > count($customerOrderAlerts)): ?>
            <p class="app-check-bell-panel-more muted">و<?= $customerOrderCount - count($customerOrderAlerts) ?> طلباً إضافياً…</p>
            <?php endif; ?>
            <?php endif; ?>
            <?php if ($hasUnposted): ?>
            <p class="app-check-bell-section-title">مستندات بحاجة ترحيل<?= $unpostedCount > 0 ? ' (' . $unpostedCount . ')' : '' ?></p>
            <ul class="app-check-bell-list">
                <?php foreach ($unpostedAlerts as $doc): ?>
                <li>
                    <a class="app-check-bell-item app-check-bell-item--link"
                       href="<?= esc((string) ($doc['url'] ?? '#')) ?>">
                        <span class="dashboard-check-status dashboard-check-status--unposted">
                            <?= esc((string) ($doc['type_label'] ?? 'بحاجة ترحيل')) ?>
                        </span>
                        <span class="app-check-bell-item-main">
                            <span class="app-check-bell-item-no"><?= esc((string) (($doc['doc_no'] ?? '') !== '' ? $doc['doc_no'] : '—')) ?></span>
                            <?php if (trim((string) ($doc['party_name'] ?? '')) !== ''): ?>
                            <span class="app-check-bell-item-party"><?= esc((string) $doc['party_name']) ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="app-check-bell-item-meta">
                            <?php
                            $docDate = trim((string) ($doc['doc_date'] ?? ''));
                            echo $docDate !== '' ? esc(format_date_dmY($docDate)) : '—';
                            $docAmount = (float) ($doc['amount'] ?? 0);
                            if ($docAmount > 0.000001) {
                                echo ' · ' . esc(format_money($docAmount));
                            }
                            ?>
                        </span>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($unpostedCount > count($unpostedAlerts)): ?>
            <p class="app-check-bell-panel-more muted">و<?= $unpostedCount - count($unpostedAlerts) ?> مستنداً إضافياً…</p>
            <?php endif; ?>
            <?php endif; ?>
            <?php if ($hasEinvoice): ?>
            <p class="app-check-bell-section-title">لم تُرسل للفوترة<?= $einvoiceCount > 0 ? ' (' . $einvoiceCount . ')' : '' ?></p>
            <ul class="app-check-bell-list">
                <?php foreach ($einvoiceAlerts as $doc): ?>
                <li>
                    <a class="app-check-bell-item app-check-bell-item--link"
                       href="<?= esc((string) ($doc['url'] ?? '#')) ?>">
                        <span class="dashboard-check-status dashboard-check-status--einvoice">
                            <?= esc((string) ($doc['urgency_label'] ?? 'لم تُرسل للفوترة')) ?>
                        </span>
                        <span class="app-check-bell-item-main">
                            <span class="app-check-bell-item-no"><?= esc((string) (($doc['doc_no'] ?? '') !== '' ? $doc['doc_no'] : '—')) ?></span>
                            <span class="app-check-bell-item-party">
                                <?= esc((string) ($doc['type_label'] ?? '')) ?>
                                <?php if (trim((string) ($doc['party_name'] ?? '')) !== ''): ?>
                                · <?= esc((string) $doc['party_name']) ?>
                                <?php endif; ?>
                            </span>
                        </span>
                        <span class="app-check-bell-item-meta">
                            <?php
                            $docDate = trim((string) ($doc['doc_date'] ?? ''));
                            echo $docDate !== '' ? esc(format_date_dmY($docDate)) : '—';
                            $docAmount = (float) ($doc['amount'] ?? 0);
                            if ($docAmount > 0.000001) {
                                echo ' · ' . esc(format_money($docAmount));
                            }
                            if (trim((string) ($doc['ref_invoice_no'] ?? '')) !== '') {
                                echo ' · فاتورة ' . esc((string) $doc['ref_invoice_no']);
                            }
                            ?>
                        </span>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($einvoiceCount > count($einvoiceAlerts)): ?>
            <p class="app-check-bell-panel-more muted">و<?= $einvoiceCount - count($einvoiceAlerts) ?> مستنداً إضافياً…</p>
            <?php endif; ?>
            <?php endif; ?>
            <?php if ($hasDeliveries): ?>
            <p class="app-check-bell-section-title">سندات تسليم بلا فاتورة مرحّلة</p>
            <ul class="app-check-bell-list">
                <?php foreach (array_slice($deliveryAlerts, 0, 8) as $del): ?>
                <li>
                    <a class="app-check-bell-item app-check-bell-item--link"
                       href="<?= esc($salesInvoicesUrl) ?>">
                        <span class="dashboard-check-status dashboard-check-status--pending">بلا فاتورة</span>
                        <span class="app-check-bell-item-main">
                            <span class="app-check-bell-item-no"><?= esc((string) ($del['delivery_no'] ?? '—')) ?></span>
                            <span class="app-check-bell-item-party"><?= esc((string) ($del['customer_name'] ?? '—')) ?></span>
                        </span>
                        <span class="app-check-bell-item-meta">
                            <?= esc(format_date_dmY((string) ($del['delivery_date'] ?? ''))) ?>
                        </span>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php if (count($deliveryAlerts) > 8): ?>
            <p class="app-check-bell-panel-more muted">و<?= count($deliveryAlerts) - 8 ?> سنداً إضافياً…</p>
            <?php endif; ?>
            <?php endif; ?>
            <?php if ($hasChecks): ?>
            <p class="app-check-bell-section-title">شيكات مستحقة</p>
            <ul class="app-check-bell-list">
                <?php foreach (array_slice($alertChecks, 0, 12) as $chk): ?>
                <li>
                    <button type="button"
                            class="app-check-bell-item js-check-alert-open"
                            data-check-id="<?= (int) ($chk['check_id'] ?? 0) ?>"
                            data-title="تفاصيل الشيك">
                        <span class="dashboard-check-status dashboard-check-status--<?= esc((string) ($chk['urgency'] ?? 'pending')) ?>">
                            <?= esc((string) ($chk['urgency_label'] ?? '')) ?>
                        </span>
                        <span class="app-check-bell-item-main">
                            <span class="app-check-bell-item-no"><?= esc((string) (($chk['check_no'] ?? '') !== '' ? $chk['check_no'] : '—')) ?></span>
                            <span class="app-check-bell-item-party"><?= esc((string) ($chk['party_name'] ?? '—')) ?></span>
                        </span>
                        <span class="app-check-bell-item-meta">
                            <?php
                            $due = trim((string) ($chk['due_date'] ?? ''));
                            echo $due !== '' ? esc(format_date_dmY($due)) : '—';
                            ?>
                            · <?= esc(format_money((float) ($chk['amount'] ?? 0))) ?>
                        </span>
                    </button>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php if (count($alertChecks) > 12): ?>
            <p class="app-check-bell-panel-more muted">و<?= count($alertChecks) - 12 ?> شيكاً إضافياً…</p>
            <?php endif; ?>
            <?php endif; ?>
            <?php endif; ?>
            <footer class="app-check-bell-panel-foot">
                <?php if ((int) ($summary['overdue'] ?? 0) > 0): ?>
                <button type="button" class="btn btn-ghost btn-sm js-check-alert-open"
                        data-filter="overdue" data-title="شيكات متأخرة">متأخر (<?= (int) $summary['overdue'] ?>)</button>
                <?php endif; ?>
                <?php if ((int) ($summary['today'] ?? 0) > 0): ?>
                <button type="button" class="btn btn-ghost btn-sm js-check-alert-open"
                        data-filter="today" data-title="مستحقة اليوم">اليوم (<?= (int) $summary['today'] ?>)</button>
                <?php endif; ?>
                <?php if ((int) ($summary['soon'] ?? 0) > 0): ?>
                <button type="button" class="btn btn-ghost btn-sm js-check-alert-open"
                        data-filter="soon" data-title="قريبة الاستحقاق">قريب (<?= (int) $summary['soon'] ?>)</button>
                <?php endif; ?>
                <?php if ($hasCustomerOrders && user_can('sales_customer_orders_approve')): ?>
                <a class="btn btn-ghost btn-sm" href="<?= esc($customerOrdersApproveUrl) ?>">اعتماد الطلبات</a>
                <?php endif; ?>
                <?php if ($hasDeliveries): ?>
                <a class="btn btn-ghost btn-sm" href="<?= esc($salesInvoicesUrl) ?>">فواتير المبيعات</a>
                <?php endif; ?>
                <?php if ($unpostedCount > 0): ?>
                <?php if (user_can('sales_invoices') || user_can('sales_invoices_list')): ?>
                <a class="btn btn-ghost btn-sm" href="<?= esc($salesInvoicesListUrl) ?>">فواتير بيع</a>
                <?php endif; ?>
                <?php if (user_can('purchase_invoices') || user_can('purchase_invoices_list')): ?>
                <a class="btn btn-ghost btn-sm" href="<?= esc($purchaseInvoicesListUrl) ?>">فواتير شراء</a>
                <?php endif; ?>
                <?php if (user_can('cash_receipt') || user_can('cash_receipts_list')): ?>
                <a class="btn btn-ghost btn-sm" href="<?= esc($cashReceiptsListUrl) ?>">سندات قبض</a>
                <?php endif; ?>
                <?php if (user_can('cash_payment') || user_can('cash_payments_list')): ?>
                <a class="btn btn-ghost btn-sm" href="<?= esc($cashPaymentsListUrl) ?>">سندات صرف</a>
                <?php endif; ?>
                <?php endif; ?>
                <?php if ($einvoiceCount > 0): ?>
                <?php if (user_can('sales_invoices') || user_can('sales_documents_list')): ?>
                <a class="btn btn-ghost btn-sm" href="<?= esc($salesDocumentsListUrl) ?>">فواتير بيع</a>
                <?php endif; ?>
                <?php if (user_can('sales_returns') || user_can('sales_returns_documents_list')): ?>
                <a class="btn btn-ghost btn-sm" href="<?= esc($salesReturnsDocumentsListUrl) ?>">مرتجعات</a>
                <?php endif; ?>
                <?php endif; ?>
                <a class="btn btn-primary btn-sm" href="<?= esc($dashboardUrl) ?>">لوحة التحكم</a>
            </footer>
        </div>
    </div>
    <?php
}

function render_header_check_notifications_modal(): void
{
    if (!header_check_notifications_user_can_see()) {
        return;
    }

    $apiReceiptUrl = app_url('api/fin_receipt_view.php');
    ?>
    <div id="app-check-notify-modal" class="dashboard-check-modal" hidden
         data-api-receipt="<?= esc($apiReceiptUrl) ?>">
        <div class="dashboard-check-modal-backdrop" aria-hidden="true"></div>
        <div class="dashboard-check-modal-panel" role="dialog" aria-modal="true" aria-labelledby="app-check-notify-modal-title">
            <header class="dashboard-check-modal-header">
                <h3 id="app-check-notify-modal-title" class="dashboard-check-modal-title">تفاصيل الشيك</h3>
                <button type="button" class="dashboard-check-modal-close js-check-modal-close" aria-label="إغلاق">×</button>
            </header>
            <div class="dashboard-check-modal-body"></div>
            <footer class="dashboard-check-modal-foot"></footer>
        </div>
    </div>
    <?php
}
