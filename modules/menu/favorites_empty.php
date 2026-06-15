<?php
declare(strict_types=1);

require_once app_path('includes/nav_helpers.php');

$cssPath = app_path('assets/css/dashboard.css');
$cssUrl = app_url('assets/css/dashboard.css');
if (is_file($cssPath)) {
    $cssUrl .= '?v=' . (string) filemtime($cssPath);
}
echo '<link rel="stylesheet" href="' . esc($cssUrl) . '">' . "\n";
?>
<div class="dashboard-ora nav-hub-ora">
    <header class="dashboard-ora-screen-title" role="banner">
        <h1 class="dashboard-ora-screen-title__text">المفضلة</h1>
        <span class="dashboard-ora-screen-title__meta">لا توجد عناصر بعد</span>
        <?php nav_render_screen_close('favorites_empty'); ?>
    </header>
    <div class="dashboard-ora-workspace">
        <section class="dashboard-ora-panel">
            <h2 class="dashboard-ora-panel__title">كيف تضيف إلى المفضلة؟</h2>
            <div class="dashboard-ora-panel__body nav-hub-ora-help">
                <ol>
                    <li>افتح الشاشة أو التقرير الذي تريد تفضيله من القائمة الجانبية.</li>
                    <li>سيظهر زر صغير على شكل <strong class="nav-hub-ora-star">نجمة ☆</strong> بجانب عنوان الشاشة في الأعلى.</li>
                    <li>اضغط النجمة فتتحول إلى <strong class="nav-hub-ora-star">★</strong> برتقالية، ويُضاف اختصار الشاشة هنا تلقائياً.</li>
                    <li>لإزالة الشاشة من المفضلة، افتحها واضغط النجمة مرة أخرى.</li>
                </ol>
                <p class="muted">ملاحظة: زر النجمة لا يظهر في «لوحة التحكم» ولا في صفحات المجلدات — فقط داخل الشاشات والتقارير الفعلية.</p>
            </div>
        </section>
    </div>
</div>
