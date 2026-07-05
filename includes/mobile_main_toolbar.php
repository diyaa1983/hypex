<?php
declare(strict_types=1);

/**
 * شريط الأدوات السفلي الرئيسي — يُضمَّن في layout_mobile.php لكل شاشات الهاتف.
 */
function mobile_main_toolbar_html(): string
{
    require_once app_path('includes/mobile_icons.php');

    $tb = 'm-btn m-toolbar-btn';
    $buttons = mobile_toolbar_button_html('save', 'حفظ', 'save', $tb . ' m-btn--success')
        . mobile_toolbar_button_html('open', 'عرض', 'open', $tb . ' m-btn--ghost')
        . mobile_toolbar_button_html('edit', 'تعديل', 'edit', $tb . ' m-btn--success')
        . mobile_toolbar_button_html('delete', 'حذف', 'trash', $tb . ' m-btn--danger')
        . mobile_toolbar_button_html('run', 'عرض', 'run', $tb . ' m-btn--primary')
        . mobile_toolbar_button_html('print', 'طباعة', 'print', $tb . ' m-btn--secondary')
        . mobile_toolbar_button_html('pdf', 'PDF', 'pdf', $tb . ' m-btn--primary')
        . mobile_toolbar_button_html('post', 'ترحيل', 'post', $tb . ' m-btn--primary')
        . mobile_toolbar_button_html('einvoice', 'فوترة', 'einvoice', $tb . ' m-btn--secondary')
        . mobile_toolbar_button_html('camera', 'تصوير', 'camera', $tb . ' m-btn--secondary');

    return '<footer id="m-main-toolbar" class="m-action-dock m-main-toolbar" aria-label="أدوات الشاشة">'
        . '<p class="m-action-dock-title" id="m-toolbar-title" hidden></p>'
        . '<div class="m-action-dock-actions" id="m-toolbar-actions">'
        . $buttons
        . '</div></footer>';
}
