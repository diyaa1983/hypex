<?php
declare(strict_types=1);

/** نافذة خريطة داخل النظام — تُضمَّن في شاشة إحداثيات الفواتير (سطح المكتب والهاتف). */
function sal_invoice_gps_map_modal_html(): string
{
    return '<div id="sal-gps-map-modal" class="sal-gps-map-modal" hidden aria-hidden="true">'
        . '<div class="sal-gps-map-modal__backdrop" data-sal-gps-map-close aria-hidden="true"></div>'
        . '<div class="sal-gps-map-modal__panel" role="dialog" aria-modal="true" aria-labelledby="sal-gps-map-title">'
        . '<header class="sal-gps-map-modal__head">'
        . '<h3 class="sal-gps-map-modal__title" id="sal-gps-map-title">موقع الفاتورة</h3>'
        . '<button type="button" class="sal-gps-map-modal__close" data-sal-gps-map-close aria-label="إغلاق">×</button>'
        . '</header>'
        . '<p class="sal-gps-map-modal__place" id="sal-gps-map-place" hidden></p>'
        . '<p class="sal-gps-map-modal__landmark" id="sal-gps-map-landmark" hidden></p>'
        . '<p class="sal-gps-map-modal__meta muted" id="sal-gps-map-meta"></p>'
        . '<div class="sal-gps-map-modal__frame-wrap">'
        . '<iframe id="sal-gps-map-iframe" class="sal-gps-map-modal__frame" title="خريطة الموقع" loading="lazy"></iframe>'
        . '</div>'
        . '<footer class="sal-gps-map-modal__foot">'
        . '<a id="sal-gps-map-external" class="sal-gps-map-modal__btn sal-gps-map-modal__btn--ghost sal-gps-map-modal__external" href="#" target="_blank" rel="noopener noreferrer">فتح في Google Maps</a>'
        . '<button type="button" class="sal-gps-map-modal__btn sal-gps-map-modal__btn--primary" data-sal-gps-map-close>إغلاق</button>'
        . '</footer>'
        . '</div>'
        . '</div>';
}
