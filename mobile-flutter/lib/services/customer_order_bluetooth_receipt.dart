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
    final headStyle = pw.TextStyle(font: bold, fontSize: cellFs);
    final valStyle = pw.TextStyle(font: reg, fontSize: cellFs);
    final valBold = pw.TextStyle(font: bold, fontSize: cellFs);
    final cellPad = paperMm == 80
        ? const pw.EdgeInsets.symmetric(horizontal: 2, vertical: 3)
        : const pw.EdgeInsets.symmetric(horizontal: 1.2, vertical: 2);
    final salesRep = Fmt.str(
      order['sales_rep_name'] ?? order['sales_rep'] ?? order['rep_name'],
    );

    pw.Widget compactCell(
      String text, {
      pw.TextStyle? style,
      pw.TextAlign align = pw.TextAlign.center,
      bool ltr = false,
      int maxLines = 1,
    }) =>
        thermalCell(
          text,
          style: style ?? valStyle,
          align: align,
          ltr: ltr,
          padding: cellPad,
          maxLines: maxLines,
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
            pw.Text('$label: ', style: pw.TextStyle(font: bold, fontSize: fs)),
            pw.Expanded(
              child:
                  pw.Text(value, style: pw.TextStyle(font: reg, fontSize: fs)),
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
            pw.SizedBox(height: 5),
            pw.Table(
              border: ThermalTableStyle.border,
              defaultVerticalAlignment: pw.TableCellVerticalAlignment.middle,
              columnWidths: {
                0: pw.FlexColumnWidth(paperMm == 80 ? 0.85 : 0.8),
                1: const pw.FlexColumnWidth(0.5),
                2: const pw.FlexColumnWidth(0.5),
                3: pw.FlexColumnWidth(paperMm == 80 ? 0.7 : 0.65),
                4: const pw.FlexColumnWidth(0.45),
                5: const pw.FlexColumnWidth(0.45),
                6: const pw.FlexColumnWidth(0.55),
                7: const pw.FlexColumnWidth(2.7),
              },
              children: [
                pw.TableRow(
                  decoration: ThermalTableStyle.headerDecoration,
                  children: rtlRow([
                    compactCell('مادة', style: headStyle, maxLines: 1),
                    compactCell('وحدة', style: headStyle, maxLines: 1),
                    compactCell('كمية', style: headStyle, ltr: true),
                    compactCell('إضافي', style: headStyle, ltr: true),
                    compactCell('سعر', style: headStyle, ltr: true),
                    compactCell('خصم', style: headStyle, ltr: true),
                    compactCell('ضريبة', style: headStyle, ltr: true),
                    compactCell('إجمالي', style: headStyle, ltr: true),
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
                        compactCell(Fmt.str(line['unit_name']), style: valStyle),
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
}
