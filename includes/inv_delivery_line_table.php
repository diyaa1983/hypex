<?php
declare(strict_types=1);

require_once app_path('includes/app_icons.php');

/**
 * رأس جدول بنود سند التسليم — نفس هيكل فاتورة المبيعات (بدون أعمدة الأسعار).
 */
function inv_delivery_line_table_head(): void
{
    ?>
    <tr>
        <th class="sales-inv-col-seq">تسلسل</th>
        <th class="sales-inv-col-sku">رقم المادة</th>
        <th class="sales-inv-col-item">اسم المادة</th>
        <th class="sales-inv-col-qty">الكمية</th>
        <th class="sales-inv-col-del" aria-label="حذف"></th>
    </tr>
    <?php
}

/**
 * صف قالب / إدخال بند سند التسليم.
 */
function inv_delivery_line_table_row_template(): void
{
    ?>
    <tr data-line-id="" data-item-id="" data-name-ar="" class="is-entry-row">
        <td class="sales-inv-col-seq"><span class="js-seq"></span></td>
        <td class="sales-inv-col-sku">
            <code class="js-sku"></code>
            <input type="text" class="input js-barcode-inp" placeholder="مسح أو باركود" autocomplete="off" spellcheck="false" title="امسح الباركود أو أدخل رقم المادة">
        </td>
        <td class="sales-inv-item-cell sales-inv-col-item">
            <div class="sales-inv-item-lov is-empty">
                <button type="button" class="sales-inv-item-lov-btn js-pick-open" title="اختيار المادة (F3)" aria-label="اختيار المادة (F3)"></button>
                <kbd class="sales-inv-field-hotkey sales-inv-item-hotkey" aria-hidden="true">F3</kbd>
                <span class="js-name sales-inv-item-name is-placeholder"></span>
            </div>
        </td>
        <td class="sales-inv-col-qty"><input type="number" class="input input-num js-qty" min="0" step="1" inputmode="decimal" value="" placeholder=""></td>
        <td class="sales-inv-col-del"><button type="button" class="btn-icon danger js-remove" title="حذف" aria-label="حذف البند"><?= app_icon_svg('trash', 18) ?></button></td>
    </tr>
    <?php
}
