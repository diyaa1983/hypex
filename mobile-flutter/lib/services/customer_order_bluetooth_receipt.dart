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
    final cellFs = paperMm == 80 ? 9.5 : 8.0;
    final headStyle = pw.TextStyle(font: bold, fontSize: cellFs);
    final valStyle = pw.TextStyle(font: reg, fontSize: cellFs);
    final valBold = pw.TextStyle(font: bold, fontSize: cellFs);

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
            kvPlain('المستودع', Fmt.str(order['warehouse_name'])),
            pw.SizedBox(height: 5),
            pw.Table(
              border: ThermalTableStyle.border,
              columnWidths: {
                0: const pw.FlexColumnWidth(2.2),
                1: const pw.FlexColumnWidth(0.7),
                2: const pw.FlexColumnWidth(0.55),
                3: const pw.FlexColumnWidth(0.55),
                4: const pw.FlexColumnWidth(0.75),
                5: const pw.FlexColumnWidth(0.65),
                6: const pw.FlexColumnWidth(0.65),
                7: const pw.FlexColumnWidth(0.85),
              },
              children: [
                pw.TableRow(
                  decoration: ThermalTableStyle.headerDecoration,
                  children: [
                    thermalCell('مادة', style: headStyle),
                    thermalCell('وحدة', style: headStyle),
                    thermalCell('كم', style: headStyle),
                    thermalCell('إض', style: headStyle),
                    thermalCell('سعر', style: headStyle, ltr: true),
                    thermalCell('خصم', style: headStyle, ltr: true),
                    thermalCell('ض', style: headStyle, ltr: true),
                    thermalCell('إج', style: headStyle, ltr: true),
                  ],
                ),
                for (var i = 0; i < lines.length; i++)
                  () {
                    final line = lines[i];
                    final qty = Fmt.toDouble(line['qty']);
                    final extra = Fmt.toDouble(line['qty_extra']);
                    final price = Fmt.toDouble(line['unit_price'] ?? line['price']);
                    final disc = Fmt.toDouble(line['discount_pct']);
                    final taxP = Fmt.toDouble(line['tax_rate_percent']);
                    final gross = Fmt.toDouble(line['line_gross'] ?? line['line_total']);
                    final bg = i.isOdd ? ThermalTableStyle.zebraOdd : PdfColors.white;
                    return pw.TableRow(
                      decoration: pw.BoxDecoration(color: bg),
                      children: [
                        thermalCell(Fmt.str(line['item_name']), style: valStyle, align: pw.TextAlign.right),
                        thermalCell(Fmt.str(line['unit_name']), style: valStyle),
                        thermalCell(qty == 0 ? '' : Fmt.trimNum(qty), style: valStyle, ltr: true),
                        thermalCell(extra > 0 ? Fmt.trimNum(extra) : '', style: valStyle, ltr: true),
                        thermalCell(price == 0 ? '' : Fmt.money(price), style: valStyle, ltr: true),
                        thermalCell(disc > 0 ? '${Fmt.trimNum(disc)}%' : '', style: valStyle, ltr: true),
                        thermalCell(taxP > 0 ? '${Fmt.trimNum(taxP)}%' : '', style: valStyle, ltr: true),
                        thermalCell(gross == 0 ? '' : Fmt.money(gross), style: valBold, ltr: true),
                      ],
                    );
                  }(),
              ],
            ),
            pw.SizedBox(height: 6),
            pw.Divider(thickness: .6),
            moneyRow('المجموع الفرعي', subtotal),
            if (discount > 0) moneyRow('الخصم', discount),
            if (tax > 0) moneyRow('الضريبة', tax),
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
