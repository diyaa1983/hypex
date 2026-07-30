<?php
declare(strict_types=1);

require_once app_path('includes/app_icons.php');

/**
 * صف إدخال افتراضي في جدول بنود الفاتورة (يظهر حتى قبل تحميل JS).
 *
 * @var string $bootstrapLineId
 * @var string $priceStep
 * @var string|null $unitPriceStep
 * @var string|null $amountStep
 * @var array<int, array<string, mixed>> $taxRates
 */
$bootstrapLineId = $bootstrapLineId ?? ('L-boot-' . str_replace('.', '', uniqid('', true)));
$showUnitPriceIncl = !empty($showUnitPriceIncl);
$unitPriceStep = $unitPriceStep ?? ($priceStep ?? '0.01');
$amountStep = $amountStep ?? '0.01';
?>
<tr data-line-id="<?= esc((string) $bootstrapLineId) ?>" data-item-id="" data-name-ar="" class="is-entry-row">
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
    <td class="sales-inv-col-qty-extra"><input type="number" class="input input-num js-qty-extra" min="0" step="1" inputmode="decimal" value="" title="كمية إضافية تُحسب في المخزون فقط"></td>
    <td class="sales-inv-col-price"><input type="text" class="input input-num js-price" min="0" step="<?= esc((string) $unitPriceStep) ?>" inputmode="decimal" value="" title="الافرادي غير شامل الضريبة"></td>
    <?php if ($showUnitPriceIncl): ?>
        <td class="sales-inv-col-price-incl"><input type="text" class="input input-num js-price-incl" min="0" step="<?= esc((string) $amountStep) ?>" inputmode="decimal" value="" title="الافرادي شامل الضريبة"></td>
    <?php endif; ?>
    <td class="sales-inv-col-discount"><input type="text" class="input input-num js-discount" inputmode="decimal" value="" title="نسبة % أو مبلغ ثابت قبل الضريبة" autocomplete="off"></td>
    <td class="sales-inv-col-money"><input type="text" class="input input-num js-line-sub" min="0" step="<?= esc((string) $amountStep) ?>" inputmode="decimal" value="" title="بعد الخصم وقبل الضريبة"></td>
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
        <input type="text" class="input input-num js-line-gross" inputmode="decimal" value="" title="الإجمالي شامل الضريبة" autocomplete="off">
    </td>
    <td class="sales-inv-col-del"><button type="button" class="btn-icon danger js-remove" title="حذف" aria-label="حذف البند" style="visibility:hidden"><?= app_icon_svg('trash', 18) ?></button></td>
</tr>
