import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:flutter/services.dart';

import '../core/format.dart';
import 'bluetooth_printer_settings.dart';
import 'bluetooth_print_service.dart';
import 'print_brand.dart';
import 'thermal_print_widgets.dart';

/// إيصال حراري لطلب شراء العميل — 8 أعمدة + ملخص.
class CustomerOrderBluetoothReceipt {
  CustomerOrderBluetoothReceipt._();

  static Future<String?> printOrder(Map<String, dynamic> order) async {
    final cfg = await BluetoothPrinterSettings.load();
    if (!cfg.isConfigured) return 'اختر طابعة Bluetooth من الإعدادات أولاً.';
    final bytes = await buildThermalPdf(order, paperMm: cfg.paperMm);
    return BluetoothPrintService.printPdfBytes(
      bytes,
      jobName: 'طلب_شراء_${Fmt.str(order['order_no'])}',
      bluetoothOnly: true,
    );
  }

  static Future<Uint8List> buildThermalPdf(
    Map<String, dynamic> order, {
    required int paperMm,
  }) async {
    final reg = pw.Font.ttf(await rootBundle.load('assets/fonts/Arial.ttf'));
    final bold =
        pw.Font.ttf(await rootBundle.load('assets/fonts/Arial-Bold.ttf'));
    final width = (paperMm == 80 ? 80 : 58) * PdfPageFormat.mm;
    final format = PdfPageFormat(
      width,
      double.infinity,
      marginAll: (paperMm == 80 ? 3 : 2) * PdfPageFormat.mm,
    );
    final lines = (order['lines'] as List? ?? order['items'] as List? ?? [])
        .whereType<Map>()
        .map((e) => e.cast<String, dynamic>())
        .toList();
    final fs = paperMm == 80 ? 12.0 : 10.0;
    final cellFs = paperMm == 80 ? 8.0 : 6.5;
    final headFs = paperMm == 80 ? 5.5 : 4.8;
    final headStyle = pw.TextStyle(font: bold, fontSize: headFs);
    final valStyle = pw.TextStyle(font: reg, fontSize: cellFs);
    final valBold = pw.TextStyle(font: bold, fontSize: cellFs);
    final cellPad = paperMm == 80
        ? const pw.EdgeInsets.symmetric(horizontal: 2, vertical: 3)
        : const pw.EdgeInsets.symmetric(horizontal: 1.2, vertical: 2);
    final headPad = paperMm == 80
        ? const pw.EdgeInsets.symmetric(horizontal: 0.6, vertical: 3)
        : const pw.EdgeInsets.symmetric(horizontal: 0.4, vertical: 2.5);
    final salesRep = Fmt.str(
      order['sales_rep_name'] ?? order['sales_rep'] ?? order['rep_name'],
    );

    pw.Widget compactCell(
      String text, {
      pw.TextStyle? style,
      pw.TextAlign align = pw.TextAlign.center,
      bool ltr = false,
      int maxLines = 1,
      pw.TextOverflow overflow = pw.TextOverflow.clip,
    }) =>
        thermalCell(
          text,
          style: style ?? valStyle,
          align: align,
          ltr: ltr,
          padding: cellPad,
          maxLines: maxLines,
          overflow: overflow,
        );

    pw.Widget headerCell(String text) => thermalCell(
          text,
          style: headStyle,
          padding: headPad,
          maxLines: 1,
          overflow: pw.TextOverflow.visible,
        );

    // الجدول LTR داخلياً → نعكس الخلايا ليُقرأ من اليمين:
    // Item | Unit | Qty | Extra | Price | Disc | Tax | Total
    List<pw.Widget> rtlRow(List<pw.Widget> cells) => cells.reversed.toList();

    pw.Widget kv(String label, String value) => pw.Padding(
          padding: const pw.EdgeInsets.only(bottom: 2),
          child: pw.Row(children: [
            pw.Text('$label: ', style: pw.TextStyle(font: bold, fontSize: fs)),
            pw.Expanded(
              child: thermalDateText(
                value,
                style: pw.TextStyle(font: reg, fontSize: fs),
              ),
            ),
          ]),
        );

    pw.Widget kvPlain(String label, String value) => pw.Padding(
          padding: const pw.EdgeInsets.only(bottom: 2),
          child: pw.Row(children: [
            pw.Text(
              '$label: ',
              textDirection: pw.TextDirection.rtl,
              style: pw.TextStyle(font: bold, fontSize: fs),
            ),
            pw.Expanded(
              child: pw.Text(
                value,
                textDirection: pw.TextDirection.rtl,
                textAlign: pw.TextAlign.right,
                style: pw.TextStyle(font: reg, fontSize: fs),
              ),
            ),
          ]),
        );

    pw.Widget moneyRow(String label, double v) => pw.Padding(
          padding: const pw.EdgeInsets.symmetric(vertical: 1.5),
          child: pw.Row(
            mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
            children: [
              pw.Text(label, style: valStyle),
              pw.Text(Fmt.money(v),
                  textDirection: pw.TextDirection.ltr, style: valBold),
            ],
          ),
        );

    final brandHeader = await PrintBrand.header(
      paperMm: paperMm,
      bold: bold,
      title: 'طلب شراء عميل',
      companyFromDocument: Fmt.str(order['company_name']),
      logoUrlFromDocument: Fmt.str(order['logo_url']),
    );

    final subtotal = Fmt.toDouble(order['subtotal']);
    final discount = Fmt.toDouble(order['discount_total']);
    final tax = Fmt.toDouble(order['tax_total']);
    final grand = Fmt.toDouble(order['grand_total']);

    final doc = pw.Document();
    doc.addPage(pw.Page(
      pageFormat: format,
      textDirection: pw.TextDirection.rtl,
      theme: pw.ThemeData.withFont(base: reg, bold: bold),
      build: (_) => pw.Container(
        color: PdfColors.white,
        width: double.infinity,
        child: pw.Column(
          crossAxisAlignment: pw.CrossAxisAlignment.stretch,
          children: [
            brandHeader,
            pw.SizedBox(height: 3),
            pw.Divider(thickness: .8),
            kvPlain(
              'رقم الطلب',
              Fmt.str(order['order_no']).isEmpty
                  ? '—'
                  : Fmt.str(order['order_no']),
            ),
            kv('التاريخ', Fmt.dmy(Fmt.str(order['order_date']))),
            kvPlain('العميل', Fmt.str(order['customer_name'])),
            if (salesRep.isNotEmpty) kvPlain('المندوب', salesRep),
            kvPlain(
              'النوع',
              Fmt.str(order['payment_type']) == 'cash' ? 'نقدي' : 'ذمم',
            ),
            if (Fmt.str(order['notes']).trim().isNotEmpty)
              kvPlain('ملاحظات', Fmt.str(order['notes']).trim()),
            pw.SizedBox(height: 5),
            pw.Table(
              border: ThermalTableStyle.border,
              defaultVerticalAlignment: pw.TableCellVerticalAlignment.middle,
              columnWidths: {
                // بعد العكس: إجمالي | ضريبة | خصم | سعر | إضافي | كمية | وحدة | الصنف
                0: pw.FlexColumnWidth(paperMm == 80 ? 0.78 : 0.74),
                1: pw.FlexColumnWidth(paperMm == 80 ? 0.72 : 0.68),
                2: const pw.FlexColumnWidth(0.58),
                3: const pw.FlexColumnWidth(0.58),
                4: const pw.FlexColumnWidth(0.6),
                5: const pw.FlexColumnWidth(0.64),
                6: pw.FlexColumnWidth(paperMm == 80 ? 0.7 : 0.72),
                7: pw.FlexColumnWidth(paperMm == 80 ? 2.0 : 1.75),
              },
              children: [
                pw.TableRow(
                  decoration: ThermalTableStyle.headerDecoration,
                  children: rtlRow([
                    headerCell('الصنف'),
                    headerCell('الوحدة'),
                    headerCell('الكمية'),
                    headerCell('إضافي'),
                    headerCell('السعر'),
                    headerCell('الخصم'),
                    headerCell('الضريبة'),
                    headerCell('الإجمالي'),
                  ]),
                ),
                for (var i = 0; i < lines.length; i++)
                  () {
                    final line = lines[i];
                    final qty = Fmt.toDouble(line['qty']);
                    final extra = Fmt.toDouble(line['qty_extra']);
                    final price =
                        Fmt.toDouble(line['unit_price'] ?? line['price']);
                    final disc = Fmt.toDouble(line['discount_pct']);
                    final taxP = Fmt.toDouble(line['tax_rate_percent']);
                    final gross = Fmt.toDouble(
                        line['line_gross'] ?? line['line_total']);
                    final bg =
                        i.isOdd ? ThermalTableStyle.zebraOdd : PdfColors.white;
                    return pw.TableRow(
                      decoration: pw.BoxDecoration(color: bg),
                      children: rtlRow([
                        compactCell(
                          Fmt.str(line['item_name']),
                          style: valStyle,
                          align: pw.TextAlign.right,
                          maxLines: 2,
                        ),
                        compactCell(
                          Fmt.str(line['unit_name']),
                          style: valStyle.copyWith(
                            fontSize: paperMm == 80 ? 7.0 : 6.0,
                          ),
                          maxLines: 2,
                          overflow: pw.TextOverflow.span,
                        ),
                        compactCell(
                          qty == 0 ? '' : Fmt.trimNum(qty),
                          style: valStyle,
                          ltr: true,
                        ),
                        compactCell(
                          extra > 0 ? Fmt.trimNum(extra) : '',
                          style: valStyle,
                          ltr: true,
                        ),
                        compactCell(
                          price == 0 ? '' : Fmt.money(price),
                          style: valStyle,
                          ltr: true,
                        ),
                        compactCell(
                          disc > 0 ? '${Fmt.trimNum(disc)}%' : '',
                          style: valStyle,
                          ltr: true,
                        ),
                        compactCell(
                          taxP > 0 ? '${Fmt.trimNum(taxP)}%' : '',
                          style: valStyle,
                          ltr: true,
                        ),
                        compactCell(
                          gross == 0 ? '' : Fmt.money(gross),
                          style: valBold,
                          ltr: true,
                        ),
                      ]),
                    );
                  }(),
              ],
            ),
            pw.SizedBox(height: 6),
            pw.Divider(thickness: .6),
            moneyRow('المجموع الفرعي', subtotal),
            if (discount > 0) moneyRow('مجموع الخصم', discount),
            if (tax > 0) moneyRow('قيمة الضريبة', tax),
            moneyRow('الإجمالي النهائي', grand),
            pw.SizedBox(height: 9),
            pw.Center(
              child: pw.Text('شكراً لتعاملكم',
                  style: pw.TextStyle(font: reg, fontSize: fs)),
            ),
          ],
        ),
      ),
    ));
    return Uint8List.fromList(await doc.save());
  }

  /// PDF أفقي A4 بنفس أعمدة جدول التطبيق (بدون باركود).
  static Future<Uint8List> buildA4Pdf(Map<String, dynamic> order) async {
    final reg = pw.Font.ttf(await rootBundle.load('assets/fonts/Arial.ttf'));
    final bold =
        pw.Font.ttf(await rootBundle.load('assets/fonts/Arial-Bold.ttf'));
    final lines = (order['lines'] as List? ?? order['items'] as List? ?? [])
        .whereType<Map>()
        .map((e) => e.cast<String, dynamic>())
        .toList();
    final brandHeader = await PrintBrand.header(
      paperMm: 80,
      bold: bold,
      title: 'طلب شراء عميل',
      companyFromDocument: Fmt.str(order['company_name']),
      logoUrlFromDocument: Fmt.str(order['logo_url']),
    );
    final salesRep = Fmt.str(
      order['sales_rep_name'] ?? order['sales_rep'] ?? order['rep_name'],
    );
    final headStyle = pw.TextStyle(font: bold, fontSize: 8);
    final cellStyle = pw.TextStyle(font: reg, fontSize: 8);
    final cellBold = pw.TextStyle(font: bold, fontSize: 8);

    pw.Widget th(String t) => pw.Padding(
          padding: const pw.EdgeInsets.symmetric(horizontal: 3, vertical: 4),
          child: pw.Text(t, style: headStyle, textAlign: pw.TextAlign.center),
        );
    pw.Widget td(String t, {bool ltr = false, bool boldText = false}) =>
        pw.Padding(
          padding: const pw.EdgeInsets.symmetric(horizontal: 3, vertical: 3),
          child: pw.Text(
            t,
            style: boldText ? cellBold : cellStyle,
            textAlign: ltr ? pw.TextAlign.left : pw.TextAlign.center,
            textDirection: ltr ? pw.TextDirection.ltr : pw.TextDirection.rtl,
          ),
        );

    // الجدول LTR داخلياً → نعكس الخلايا ليُقرأ من اليمين:
    // تسلسل | المادة | الوحدة | الكمية | إضافي | السعر غ ش | السعر ش | الخصم | الضريبة | المجموع
    List<pw.Widget> rtlCells(List<pw.Widget> cells) => cells.reversed.toList();
    final headers = rtlCells([
      th('تسلسل'),
      th('المادة'),
      th('الوحدة'),
      th('الكمية'),
      th('إضافي'),
      th('السعر غ ش'),
      th('السعر ش'),
      th('الخصم'),
      th('الضريبة'),
      th('المجموع'),
    ]);

    final doc = pw.Document();
    doc.addPage(
      pw.MultiPage(
        pageFormat: PdfPageFormat.a4.landscape,
        textDirection: pw.TextDirection.rtl,
        theme: pw.ThemeData.withFont(base: reg, bold: bold),
        margin: const pw.EdgeInsets.all(16),
        build: (_) => [
          brandHeader,
          pw.SizedBox(height: 8),
          pw.Text(
            'رقم الطلب: ${Fmt.str(order['order_no']).isEmpty ? '—' : Fmt.str(order['order_no'])}'
            '   التاريخ: ${Fmt.dmy(Fmt.str(order['order_date']))}'
            '   العميل: ${Fmt.str(order['customer_name'])}'
            '${salesRep.isEmpty ? '' : '   المندوب: $salesRep'}'
            '   النوع: ${Fmt.str(order['payment_type']) == 'cash' ? 'نقدي' : 'ذمم'}',
            style: pw.TextStyle(font: bold, fontSize: 10),
          ),
          if (Fmt.str(order['notes']).trim().isNotEmpty) ...[
            pw.SizedBox(height: 4),
            pw.Text(
              'ملاحظات: ${Fmt.str(order['notes']).trim()}',
              style: pw.TextStyle(font: reg, fontSize: 9),
            ),
          ],
          pw.SizedBox(height: 8),
          pw.Table(
            border: pw.TableBorder.all(color: PdfColors.blueGrey300, width: 0.4),
            columnWidths: {
              0: const pw.FlexColumnWidth(1.0),
              1: const pw.FlexColumnWidth(0.8),
              2: const pw.FlexColumnWidth(0.7),
              3: const pw.FlexColumnWidth(1.0),
              4: const pw.FlexColumnWidth(1.0),
              5: const pw.FlexColumnWidth(0.7),
              6: const pw.FlexColumnWidth(0.7),
              7: const pw.FlexColumnWidth(1.0),
              8: const pw.FlexColumnWidth(2.2),
              9: const pw.FlexColumnWidth(0.55),
            },
            children: [
              pw.TableRow(
                decoration: const pw.BoxDecoration(color: PdfColors.blueGrey50),
                children: headers,
              ),
              for (var i = 0; i < lines.length; i++)
                () {
                  final line = lines[i];
                  final price =
                      Fmt.toDouble(line['unit_price'] ?? line['price']);
                  final taxP = Fmt.toDouble(line['tax_rate_percent']);
                  final priceInc = Fmt.toDouble(line['unit_price_inclusive']) >
                          0
                      ? Fmt.toDouble(line['unit_price_inclusive'])
                      : price * (1 + taxP / 100);
                  return pw.TableRow(
                    decoration: pw.BoxDecoration(
                      color: i.isOdd ? PdfColors.grey100 : PdfColors.white,
                    ),
                    children: rtlCells([
                      td('${i + 1}', ltr: true),
                      td(Fmt.str(line['item_name'])),
                      td(Fmt.str(line['unit_name'])),
                      td(Fmt.trimNum(Fmt.toDouble(line['qty'])), ltr: true),
                      td(Fmt.trimNum(Fmt.toDouble(line['qty_extra'])),
                          ltr: true),
                      td(Fmt.money(price), ltr: true),
                      td(Fmt.money(priceInc), ltr: true),
                      td('${Fmt.trimNum(Fmt.toDouble(line['discount_pct']))}%',
                          ltr: true),
                      td('${Fmt.trimNum(taxP)}%', ltr: true),
                      td(
                        Fmt.money(Fmt.toDouble(
                            line['line_gross'] ?? line['line_total'])),
                        ltr: true,
                        boldText: true,
                      ),
                    ]),
                  );
                }(),
            ],
          ),
          pw.SizedBox(height: 10),
          pw.Align(
            alignment: pw.Alignment.centerRight,
            child: pw.SizedBox(
              width: 220,
              child: pw.Column(
                children: [
                  _a4Money(reg, bold, 'المجموع الفرعي',
                      Fmt.toDouble(order['subtotal'])),
                  _a4Money(reg, bold, 'الخصم',
                      Fmt.toDouble(order['discount_total'])),
                  _a4Money(
                      reg, bold, 'الضريبة', Fmt.toDouble(order['tax_total'])),
                  _a4Money(
                      reg, bold, 'الصافي', Fmt.toDouble(order['grand_total'])),
                ],
              ),
            ),
          ),
        ],
      ),
    );
    return Uint8List.fromList(await doc.save());
  }

  static pw.Widget _a4Money(
      pw.Font reg, pw.Font bold, String label, double v) {
    return pw.Padding(
      padding: const pw.EdgeInsets.symmetric(vertical: 2),
      child: pw.Row(
        mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
        children: [
          pw.Text(label, style: pw.TextStyle(font: bold, fontSize: 10)),
          pw.Text(Fmt.money(v),
              textDirection: pw.TextDirection.ltr,
              style: pw.TextStyle(font: bold, fontSize: 10)),
        ],
      ),
    );
  }
}
