<?php
declare(strict_types=1);

require_once app_path('includes/app_icons.php');

/**
 * رأس جدول بنود فاتورة البيع/الشراء.
 */
function inv_invoice_line_table_head(bool $showUnitPriceIncl = false): void
{
    ?>
    <tr>
        <th class="sales-inv-col-seq">تسلسل</th>
        <th class="sales-inv-col-sku">رقم المادة</th>
        <th class="sales-inv-col-item">اسم المادة</th>
        <th class="sales-inv-col-qty">الكمية</th>
        <th class="sales-inv-col-qty-extra" title="تُحسب في المخزون مع الكمية؛ المبلغ يعتمد على الكمية فقط">الكمية الإضافية</th>
        <th class="sales-inv-col-price" title="سعر الوحدة قبل الضريبة">الافرادي غ.ش</th>
        <?php if ($showUnitPriceIncl): ?>
            <th class="sales-inv-col-price-incl" title="سعر الوحدة شامل الضريبة حسب نسبة البند">الافرادي ش.</th>
        <?php endif; ?>
        <th class="sales-inv-col-discount" title="على إجمالي المادة (كمية × السعر) قبل الضريبة — نسبة % أو مبلغ">الخصم <span class="sales-inv-disc-hint sales-inv-th-hint">10% أو مبلغ</span></th>
        <th class="sales-inv-col-money" title="بعد خصم المادة وقبل الضريبة">السعر الإجمالي</th>
        <th class="sales-inv-col-money">مبلغ الضريبة</th>
        <th class="sales-inv-col-tax">نسبة الضريبة</th>
        <th class="sales-inv-col-total">الإجمالي مع الضريبة</th>
        <th class="sales-inv-col-del" aria-label="حذف"></th>
    </tr>
    <?php
}

/**
 * صف قالب بند فاتورة.
 *
 * @param array<int, array<string, mixed>> $taxRates
 */
function inv_invoice_line_table_row_template(array $taxRates, string $unitPriceStep, string $amountStep, bool $showUnitPriceIncl = false): void
{
    ?>
    <tr data-line-id="" data-item-id="" data-name-ar="" class="is-entry-row">
        <td class="sales-inv-col-seq"><span class="js-seq"></span></td>
        <td class="sales-inv-col-sku">
            <code class="js-sku"></code>
            <input type="text" class="input js-barcode-inp" placeholder="مسح أو باركود" autocomplete="off" spellcheck="false" title="امسح الباركود أو أدخل رقم المادة">
        </td>
        <td class="sales-inv-item-cell sales-inv-col-item">
            <button type="button" class="sales-inv-item-pick js-pick-open">
                <span class="js-name sales-inv-item-name is-placeholder">اضغط لاختيار المادة</span>
            </button>
        </td>
        <td class="sales-inv-col-qty"><input type="number" class="input input-num js-qty" min="0" step="1" inputmode="decimal" value="" placeholder=""></td>
        <td class="sales-inv-col-qty-extra"><input type="number" class="input input-num js-qty-extra" min="0" step="1" inputmode="decimal" value="" title="كمية إضافية تُحسب في المخزون فقط"></td>
        <td class="sales-inv-col-price"><input type="text" class="input input-num js-price" min="0" step="<?= esc($unitPriceStep) ?>" inputmode="decimal" value="" title="الافرادي غير شامل الضريبة"></td>
        <?php if ($showUnitPriceIncl): ?>
            <td class="sales-inv-col-price-incl"><input type="text" class="input input-num js-price-incl" min="0" step="<?= esc($amountStep) ?>" inputmode="decimal" value="" title="الافرادي شامل الضريبة — يُحدَّث تلقائياً من غ.ش أو أدخله مباشرة"></td>
        <?php endif; ?>
        <td class="sales-inv-col-discount"><input type="text" class="input input-num js-discount" inputmode="decimal" value="" title="خصم على إجمالي المادة قبل الضريبة (كمية × السعر)" autocomplete="off"></td>
        <td class="sales-inv-col-money"><input type="text" class="input input-num js-line-sub" min="0" step="<?= esc($amountStep) ?>" inputmode="decimal" value="" title="بعد الخصم وقبل الضريبة"></td>
        <td class="sales-inv-col-money js-tax-amt"></td>
        <td class="sales-inv-col-tax">
            <select class="input js-tax">
                <?php foreach ($taxRates as $tr): ?>
                    <option value="<?= (int) $tr['id'] ?>" data-rate="<?= esc((string) $tr['rate_percent']) ?>">
                        <?= esc((string) $tr['name_ar']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
        <td class="sales-inv-col-total">
            <input type="text" class="input input-num js-line-gross" inputmode="decimal" value="" title="الإجمالي شامل الضريبة — يُحدَّث السعر تلقائياً" autocomplete="off">
        </td>
        <td class="sales-inv-col-del"><button type="button" class="btn-icon danger js-remove" title="حذف" aria-label="حذف البند"><?= app_icon_svg('trash', 18) ?></button></td>
    </tr>
    <?php
}
