import 'dart:io';

import 'package:esc_pos_utils_plus/esc_pos_utils_plus.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:image/image.dart' as img;
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:print_bluetooth_thermal/print_bluetooth_thermal.dart';
import 'package:printing/printing.dart';

import '../core/format.dart';
import 'bluetooth_print_service.dart';
import 'bluetooth_printer_settings.dart';

/// سند قبض حراري بحجم ورق Bluetooth (58/80 مم).
class ReceiptBluetoothReceipt {
  ReceiptBluetoothReceipt._();

  static String payMethodLabel(String method, {String? fallback}) {
    if (fallback != null && fallback.trim().isNotEmpty) {
      return fallback.trim();
    }
    switch (method.trim().toLowerCase()) {
      case 'check':
      case 'cheque':
        return 'شيك';
      case 'bank':
        return 'تحويل بنكي';
      case 'credit':
        return 'آجل';
      default:
        return 'نقد';
    }
  }

  static Future<String?> printReceipt(Map<String, dynamic> data) async {
    final cfg = await BluetoothPrinterSettings.load();
    if (!cfg.isConfigured) {
      return 'اختر طابعة Bluetooth من الإعدادات أولاً.';
    }
    if (kIsWeb || !Platform.isAndroid) {
      return 'الطباعة الحرارية متاحة على أندرويد فقط.';
    }

    try {
      final pdfBytes = await buildThermalPdf(data, paperMm: cfg.paperMm);
      final okConnect = await BluetoothPrintService.connect(cfg.mac);
      if (!okConnect) {
        return 'تعذر الاتصال بالطابعة «${cfg.displayLabel}». تأكد أنها مشغّلة ومقترنة.';
      }

      final profile = await CapabilityProfile.load();
      final paper = cfg.paperMm == 80 ? PaperSize.mm80 : PaperSize.mm58;
      final generator = Generator(paper, profile);
      final targetWidth = cfg.paperMm == 80 ? 576 : 384;

      final chunks = <int>[];
      chunks.addAll(generator.reset());

      var pages = 0;
      await for (final page in Printing.raster(pdfBytes, dpi: 180)) {
        pages++;
        final png = await page.toPng();
        final decoded = img.decodeImage(Uint8List.fromList(png));
        if (decoded == null) continue;
        var resized = decoded;
        if (decoded.width != targetWidth) {
          resized = img.copyResize(
            decoded,
            width: targetWidth,
            interpolation: img.Interpolation.average,
          );
        }
        chunks.addAll(
          generator.imageRaster(resized, imageFn: PosImageFn.bitImageRaster),
        );
        chunks.addAll(generator.feed(1));
      }
      if (pages == 0) {
        return 'تعذر تجهيز إيصال الطباعة.';
      }
      chunks.addAll(generator.feed(2));
      chunks.addAll(generator.cut());

      final written = await PrintBluetoothThermal.writeBytes(chunks);
      return written ? null : 'فشل إرسال البيانات للطابعة.';
    } catch (e) {
      return 'تعذر طباعة السند: $e';
    }
  }

  static Future<Uint8List> buildThermalPdf(
    Map<String, dynamic> data, {
    required int paperMm,
  }) async {
    final fontReg = pw.Font.ttf(
      await rootBundle.load('assets/fonts/Arial.ttf'),
    );
    final fontBold = pw.Font.ttf(
      await rootBundle.load('assets/fonts/Arial-Bold.ttf'),
    );

    final width = paperMm == 80 ? 80 * PdfPageFormat.mm : 58 * PdfPageFormat.mm;
    final pageFormat = PdfPageFormat(
      width,
      double.infinity,
      marginAll: paperMm == 80 ? 3 * PdfPageFormat.mm : 2 * PdfPageFormat.mm,
    );

    final company = Fmt.str(data['company_name']);
    final voucherNo = Fmt.str(data['voucher_no']);
    final date = Fmt.dmy(
      Fmt.str(data['voucher_date_dmy'] ?? data['voucher_date']),
    );
    final customer = Fmt.str(data['customer_name']).isEmpty
        ? '—'
        : Fmt.str(data['customer_name']);
    final amount = Fmt.toDouble(data['amount']);
    final payMethod = Fmt.str(data['pay_method']);
    final payLabel = payMethodLabel(
      payMethod,
      fallback: Fmt.str(data['pay_label']),
    );
    final notes = Fmt.str(data['notes'] ?? data['description']);
    final checkNo = Fmt.str(data['check_no']);
    final bankName = Fmt.str(data['bank_name']);
    final salesRep = Fmt.str(data['sales_rep_name']);

    final checks = <Map<String, dynamic>>[];
    final rawChecks = data['checks'];
    if (rawChecks is List) {
      for (final e in rawChecks) {
        if (e is Map) checks.add(e.cast<String, dynamic>());
      }
    }

    final fs = paperMm == 80 ? 9.0 : 8.0;
    final fsSm = paperMm == 80 ? 8.0 : 7.0;

    final doc = pw.Document();
    doc.addPage(
      pw.Page(
        pageFormat: pageFormat,
        textDirection: pw.TextDirection.rtl,
        theme: pw.ThemeData.withFont(base: fontReg, bold: fontBold),
        build: (ctx) {
          return pw.Column(
            crossAxisAlignment: pw.CrossAxisAlignment.stretch,
            children: [
              if (company.isNotEmpty) ...[
                pw.Center(
                  child: pw.Text(
                    company,
                    textAlign: pw.TextAlign.center,
                    style: pw.TextStyle(
                      font: fontBold,
                      fontSize: paperMm == 80 ? 12 : 10.5,
                      fontWeight: pw.FontWeight.bold,
                    ),
                  ),
                ),
                pw.SizedBox(height: 3),
              ],
              pw.Center(
                child: pw.Text(
                  'سند قبض',
                  textAlign: pw.TextAlign.center,
                  style: pw.TextStyle(
                    font: fontBold,
                    fontSize: paperMm == 80 ? 12 : 11,
                    fontWeight: pw.FontWeight.bold,
                  ),
                ),
              ),
              pw.SizedBox(height: 5),
              pw.Divider(thickness: 0.8),
              _kv('رقم السند', voucherNo.isEmpty ? '—' : voucherNo, fontReg,
                  fontBold, fsSm),
              _kv('التاريخ', date.isEmpty ? '—' : date, fontReg, fontBold,
                  fsSm),
              _kv('العميل', customer, fontReg, fontBold, fsSm),
              if (salesRep.isNotEmpty)
                _kv('المندوب', salesRep, fontReg, fontBold, fsSm),
              _kv('طريقة الدفع', payLabel, fontReg, fontBold, fsSm),
              if (checkNo.isNotEmpty)
                _kv('رقم الشيك', checkNo, fontReg, fontBold, fsSm),
              if (bankName.isNotEmpty)
                _kv('البنك', bankName, fontReg, fontBold, fsSm),
              pw.SizedBox(height: 4),
              pw.Divider(thickness: 0.8),
              pw.SizedBox(height: 4),
              pw.Center(
                child: pw.Text(
                  'المبلغ',
                  style: pw.TextStyle(font: fontReg, fontSize: fsSm),
                ),
              ),
              pw.SizedBox(height: 2),
              pw.Center(
                child: pw.Text(
                  amount == 0 ? '' : Fmt.money(amount),
                  textDirection: pw.TextDirection.ltr,
                  style: pw.TextStyle(
                    font: fontBold,
                    fontSize: paperMm == 80 ? 16 : 14,
                    fontWeight: pw.FontWeight.bold,
                  ),
                ),
              ),
              if (checks.isNotEmpty) ...[
                pw.SizedBox(height: 6),
                pw.Divider(thickness: 0.8),
                pw.Text(
                  'الشيكات',
                  textAlign: pw.TextAlign.center,
                  style: pw.TextStyle(font: fontBold, fontSize: fs),
                ),
                pw.SizedBox(height: 3),
                ...checks.map((c) {
                  final no = Fmt.str(c['check_no'] ?? c['cheque_no']);
                  final bank = Fmt.str(c['bank_name'] ?? c['bank']);
                  final amt = Fmt.toDouble(c['check_amount'] ?? c['amount']);
                  final due = Fmt.dmy(
                    Fmt.str(c['due_date'] ?? c['check_date'] ?? c['date']),
                  );
                  return pw.Padding(
                    padding: const pw.EdgeInsets.only(bottom: 4),
                    child: pw.Column(
                      crossAxisAlignment: pw.CrossAxisAlignment.stretch,
                      children: [
                        pw.Text(
                          [
                            if (no.isNotEmpty) 'شيك $no',
                            if (bank.isNotEmpty) bank,
                            if (due.isNotEmpty && due != '—') 'استحقاق $due',
                          ].join(' • '),
                          style:
                              pw.TextStyle(font: fontReg, fontSize: fsSm - 0.5),
                        ),
                        pw.Text(
                          amt == 0 ? '' : Fmt.money(amt),
                          textDirection: pw.TextDirection.ltr,
                          style: pw.TextStyle(font: fontBold, fontSize: fsSm),
                        ),
                      ],
                    ),
                  );
                }),
              ],
              if (notes.isNotEmpty) ...[
                pw.SizedBox(height: 4),
                pw.Divider(thickness: 0.8),
                _kv('ملاحظات', notes, fontReg, fontBold, fsSm),
              ],
              pw.SizedBox(height: 10),
              pw.Center(
                child: pw.Text(
                  'شكراً لتعاملكم',
                  style: pw.TextStyle(font: fontReg, fontSize: fsSm),
                ),
              ),
            ],
          );
        },
      ),
    );

    return Uint8List.fromList(await doc.save());
  }

  static pw.Widget _kv(
    String label,
    String value,
    pw.Font reg,
    pw.Font bold,
    double fs,
  ) {
    return pw.Padding(
      padding: const pw.EdgeInsets.only(bottom: 2),
      child: pw.Row(
        crossAxisAlignment: pw.CrossAxisAlignment.start,
        children: [
          pw.Text('$label: ', style: pw.TextStyle(font: bold, fontSize: fs)),
          pw.Expanded(
            child: pw.Text(
              value,
              style: pw.TextStyle(font: reg, fontSize: fs),
            ),
          ),
        ],
      ),
    );
  }
}
