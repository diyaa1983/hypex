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
<div class="dashboard-ora nav-hub-ora nav-hub-ora--favorites">
    <header class="dashboard-ora-screen-title" role="banner">
        <h1 class="dashboard-ora-screen-title__text">المفضلة</h1>
        <span class="dashboard-ora-screen-title__meta">لا توجد اختصارات بعد</span>
        <?php nav_render_screen_close('favorites_empty'); ?>
    </header>
    <div class="dashboard-ora-workspace nav-fav-workspace">
        <section class="nav-fav-gallery" aria-label="المفضلة فارغة">
            <div class="nav-fav-gallery__stage">
                <div class="nav-fav-empty-card">
                    <div class="nav-fav-empty-card__icon" aria-hidden="true">☆</div>
                    <h2 class="nav-fav-empty-card__title">أضف شاشاتك المفضلة</h2>
                    <ol class="nav-fav-empty-card__steps">
                        <li>افتح أي شاشة أو تقرير من القائمة.</li>
                        <li>اضغط نجمة <strong>☆</strong> بجانب عنوان الشاشة.</li>
                        <li>ستظهر هنا كمربع اختصار سريع في الوسط.</li>
                    </ol>
                </div>
            </div>
        </section>
    </div>
</div>
