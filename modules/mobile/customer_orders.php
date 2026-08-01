<?php
declare(strict_types=1);
require_once app_path('includes/sal_customer_order.php');
$pdo=db(); sal_customer_order_ensure_schema($pdo);
?>
<div class="m-ora12"><div class="m-ora12-workspace"><section class="m-ora12-panel"><h2 class="m-ora12-panel__title">طلبات شراء العملاء</h2><div class="m-ora12-panel__body">
<p class="muted">أنشئ أو عدّل الطلب من تطبيق المندوب، ثم يظهر هنا بحالته.</p><div id="customer-orders-list">جاري التحميل…</div>
</div></section></div></div>
<script>(function(){fetch(<?= json_encode(app_url('api/mobile_customer_order_list.php')) ?>).then(function(r){return r.json()}).then(function(x){var el=document.getElementById('customer-orders-list');if(!x.ok){el.textContent='تعذر تحميل الطلبات.';return}el.innerHTML=x.orders.length?'<ul>'+x.orders.map(function(o){return '<li><strong>'+o.order_no+'</strong> — '+o.customer_name+' — '+(o.status==='approved'?'معتمد':'مسودة')+'</li>'}).join('')+'</ul>':'لا توجد طلبات.'}).catch(function(){document.getElementById('customer-orders-list').textContent='تعذر تحميل الطلبات.'})})();</script>
