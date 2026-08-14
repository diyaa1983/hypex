import 'package:flutter/services.dart';
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;

import '../core/format.dart';
import 'bluetooth_printer_settings.dart';
import 'bluetooth_print_service.dart';
import 'print_brand.dart';

class RepStockBluetoothReceipt {
  RepStockBluetoothReceipt._();

  static Future<String?> printStock(Map<String, dynamic> data) async {
    final cfg = await BluetoothPrinterSettings.load();
    if (!cfg.isConfigured) return 'اختر طابعة Bluetooth من الإعدادات أولاً.';
    return BluetoothPrintService.printPdfBytes(
      await buildThermalPdf(data, paperMm: cfg.paperMm),
      jobName: 'رصيد_المستودع',
      bluetoothOnly: true,
    );
  }

  static Future<Uint8List> buildThermalPdf(Map<String, dynamic> data,
      {required int paperMm}) async {
    final reg = pw.Font.ttf(await rootBundle.load('assets/fonts/Arial.ttf'));
    final bold =
        pw.Font.ttf(await rootBundle.load('assets/fonts/Arial-Bold.ttf'));
    final fs = paperMm == 80 ? 8.5 : 7.5;
    final items = (data['items'] as List? ?? [])
        .whereType<Map>()
        .map((e) => e.cast<String, dynamic>())
        .toList();
    pw.Widget cell(String t, {bool boldText = false}) => pw.Padding(
          padding: const pw.EdgeInsets.symmetric(horizontal: 2, vertical: 2),
          child: pw.Text(t,
              style: pw.TextStyle(font: boldText ? bold : reg, fontSize: fs),
              textAlign: pw.TextAlign.center),
        );
    final brandHeader = await PrintBrand.header(
      paperMm: paperMm,
      bold: bold,
      title: 'رصيد المستودع',
      companyFromDocument: Fmt.str(data['company_name']),
      logoUrlFromDocument: Fmt.str(data['logo_url']),
    );

    final doc = pw.Document();
    doc.addPage(pw.Page(
      pageFormat: PdfPageFormat(
          (paperMm == 80 ? 80 : 58) * PdfPageFormat.mm, double.infinity,
          marginAll: 2 * PdfPageFormat.mm),
      textDirection: pw.TextDirection.rtl,
      theme: pw.ThemeData.withFont(base: reg, bold: bold),
      build: (_) => pw
          .Column(crossAxisAlignment: pw.CrossAxisAlignment.stretch, children: [
        brandHeader,
        pw.SizedBox(height: 3),
        if (Fmt.str(data['warehouse_name']).isNotEmpty)
          pw.Center(
              child: pw.Text(Fmt.str(data['warehouse_name']),
                  style: pw.TextStyle(font: reg, fontSize: fs))),
        if (Fmt.str(data['rep_name']).isNotEmpty)
          pw.Center(
              child: pw.Text('المندوب: ${Fmt.str(data['rep_name'])}',
                  style: pw.TextStyle(font: reg, fontSize: fs))),
        pw.SizedBox(height: 5),
        pw.Table(
            border: pw.TableBorder.all(width: .35, color: PdfColors.grey700),
            columnWidths: const {
              0: pw.FlexColumnWidth(3),
              1: pw.FlexColumnWidth(1),
              2: pw.FlexColumnWidth(1)
            },
            children: [
              pw.TableRow(
                  decoration: const pw.BoxDecoration(color: PdfColors.grey300),
                  children: [
                    cell('المادة', boldText: true),
                    cell('الوحدة', boldText: true),
                    cell('الرصيد', boldText: true)
                  ]),
              for (final item in items)
                pw.TableRow(children: [
                  cell(Fmt.str(item['item_name'] ?? item['name'])),
                  cell(Fmt.str(item['unit_name'])),
                  cell(Fmt.toDouble(item['qty']) == 0
                      ? ''
                      : Fmt.trimNum(Fmt.toDouble(item['qty']))),
                ]),
            ]),
        pw.SizedBox(height: 8),
        pw.Center(
            child: pw.Text('شكراً لتعاملكم',
                style: pw.TextStyle(font: reg, fontSize: fs))),
      ]),
    ));
    return Uint8List.fromList(await doc.save());
  }
}
