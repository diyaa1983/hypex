<?php
declare(strict_types=1);

/**
 * صلاحيات مؤشرات لوحة التحكم — كل عنصر في الشاشة الرئيسية له كود مستقل في sys_screen.
 *
 * @return list<array{code:string, label:string}>
 */
function dashboard_widgets_catalog(): array
{
    return [
        ['code' => 'dashboard_kpi_sales', 'label' => 'مؤشرات المبيعات (إجمالي + الشهر + صافي)'],
        ['code' => 'dashboard_kpi_journal_daily', 'label' => 'مؤشر القيود اليومية (مدخلة / مرحّلة)'],
        ['code' => 'dashboard_kpi_purchases', 'label' => 'مؤشر المشتريات'],
        ['code' => 'dashboard_kpi_cashflow', 'label' => 'مؤشرات المقبوضات'],
        ['code' => 'dashboard_kpi_receivables', 'label' => 'فواتير البيع غير المسددة'],
        ['code' => 'dashboard_kpi_payables', 'label' => 'فواتير الشراء غير المدفوعة'],
        ['code' => 'dashboard_panel_treasury', 'label' => 'لوحة الصندوق والحسابات'],
        ['code' => 'dashboard_panel_liabilities', 'label' => 'لوحة المستحقات'],
        ['code' => 'dashboard_panel_checks', 'label' => 'مؤشرات الشيكات الواردة والصادرة'],
        ['code' => 'dashboard_panel_recent_sales', 'label' => 'آخر فواتير المبيعات'],
    ];
}

/** @return list<string> */
function dashboard_widget_codes(): array
{
    $codes = [];
    foreach (dashboard_widgets_catalog() as $row) {
        $code = trim((string) ($row['code'] ?? ''));
        if ($code !== '') {
            $codes[] = $code;
        }
    }

    return $codes;
}

function dashboard_widget_can(string $code): bool
{
    return user_can($code);
}
