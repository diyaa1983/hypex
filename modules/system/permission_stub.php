<?php
declare(strict_types=1);
?>
<div class="card">
    <p class="muted">هذه صلاحية وظيفية (زر في شريط فاتورة البيع) وليست شاشة مستقلة.</p>
    <a class="btn btn-secondary btn-sm" href="<?= esc(app_url('index.php?r=sales_invoices')) ?>">فاتورة مبيعات</a>
    <a class="btn btn-ghost btn-sm" href="<?= esc(app_url('index.php?r=dashboard')) ?>">لوحة التحكم</a>
</div>
