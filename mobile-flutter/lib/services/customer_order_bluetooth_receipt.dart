import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:flutter/services.dart';

import '../core/format.dart';
import 'bluetooth_printer_settings.dart';
import 'bluetooth_print_service.dart';
import 'print_brand.dart';

/// إيصال حراري لطلب شراء العميل.
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
    pw.Widget kv(String label, String value) => pw.Padding(
          padding: const pw.EdgeInsets.only(bottom: 2),
          child: pw.Row(children: [
            pw.Text('$label: ', style: pw.TextStyle(font: bold, fontSize: fs)),
            pw.Expanded(
              child:
                  pw.Text(value, style: pw.TextStyle(font: reg, fontSize: fs)),
            ),
          ]),
        );
    final cellFs = paperMm == 80 ? 10.0 : 8.5;
    pw.Widget cell(String text, {bool head = false, bool ltr = false}) =>
        pw.Padding(
          padding: const pw.EdgeInsets.symmetric(horizontal: 1.5, vertical: 3),
          child: pw.Text(
            text,
            textAlign: pw.TextAlign.center,
            textDirection: ltr ? pw.TextDirection.ltr : pw.TextDirection.rtl,
            style: pw.TextStyle(
              font: head || !ltr ? bold : reg,
              fontSize: cellFs,
            ),
          ),
        );

    final brandHeader = await PrintBrand.header(
      paperMm: paperMm,
      bold: bold,
      title: 'طلب شراء عميل',
      companyFromDocument: Fmt.str(order['company_name']),
      logoUrlFromDocument: Fmt.str(order['logo_url']),
    );

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
          kv(
              'رقم الطلب',
              Fmt.str(order['order_no']).isEmpty
                  ? '—'
                  : Fmt.str(order['order_no'])),
          kv('التاريخ', Fmt.dmy(Fmt.str(order['order_date']))),
          kv('العميل', Fmt.str(order['customer_name'])),
          kv('المستودع', Fmt.str(order['warehouse_name'])),
          if (Fmt.str(order['sales_rep_name']).isNotEmpty)
            kv('المندوب', Fmt.str(order['sales_rep_name'])),
          pw.SizedBox(height: 5),
          pw.Table(
            border: pw.TableBorder.all(width: .35, color: PdfColors.grey700),
            columnWidths: {
              0: pw.FlexColumnWidth(paperMm == 80 ? 0.85 : 0.8),
              1: pw.FlexColumnWidth(paperMm == 80 ? 0.9 : 0.85),
              2: pw.FlexColumnWidth(paperMm == 80 ? 0.75 : 0.7),
              3: pw.FlexColumnWidth(paperMm == 80 ? 0.85 : 0.8),
              4: const pw.FlexColumnWidth(2.5),
            },
            children: [
              pw.TableRow(
                decoration: const pw.BoxDecoration(color: PdfColors.grey300),
                children: [
                  cell('خصم', head: true),
                  cell('سعر', head: true),
                  cell('إض.', head: true),
                  cell('كمية', head: true),
                  cell('المادة', head: true),
                ],
              ),
              for (final line in lines)
                () {
                  var factor = Fmt.toDouble(line['unit_factor'] ?? 1);
                  if (factor <= 0) factor = 1;
                  final pack = factor > 1.0000001
                      ? ((factor - factor.round()).abs() < 1e-9
                          ? '${factor.round()}'
                          : Fmt.trimNum(factor))
                      : '';
                  var itemName =
                      Fmt.str(line['item_name'] ?? line['name']);
                  if (pack.isNotEmpty) {
                    itemName = '$itemName (تعبئة × $pack)';
                  }
                  final qty = Fmt.toDouble(line['qty']);
                  final extra = Fmt.toDouble(line['qty_extra']);
                  final price = Fmt.toDouble(
                    line['unit_price'] ?? line['price'] ?? line['sale_price'],
                  );
                  final disc = Fmt.toDouble(
                    line['discount_amount'] ?? line['discount'],
                  );
                  return pw.TableRow(children: [
                    cell(disc > 0 ? Fmt.money(disc) : '', ltr: true),
                    cell(price == 0 ? '' : Fmt.money(price), ltr: true),
                    cell(extra > 0 ? Fmt.trimNum(extra) : '', ltr: true),
                    cell(qty == 0 ? '' : Fmt.trimNum(qty), ltr: true),
                    cell(itemName.isEmpty ? 'مادة' : itemName),
                  ]);
                }(),
            ],
          ),
          pw.SizedBox(height: 9),
          pw.Center(
              child: pw.Text('شكراً لتعاملكم',
                  style: pw.TextStyle(font: reg, fontSize: fs))),
        ],
      ),
      ),
    ));
    return Uint8List.fromList(await doc.save());
  }
}
