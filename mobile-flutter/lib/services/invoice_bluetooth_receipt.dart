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

/// إيصال فاتورة حراري (58/80 مم) — تصميم مختلف عن PDF A4.
class InvoiceBluetoothReceipt {
  InvoiceBluetoothReceipt._();

  static Future<String?> printInvoice(Map<String, dynamic> inv) async {
    final cfg = await BluetoothPrinterSettings.load();
    if (!cfg.isConfigured) {
      return 'اختر طابعة Bluetooth من الإعدادات أولاً.';
    }
    if (kIsWeb || !Platform.isAndroid) {
      return 'الطباعة الحرارية متاحة على أندرويد فقط.';
    }

    try {
      final pdfBytes = await _buildThermalPdf(inv, paperMm: cfg.paperMm);
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
      return 'تعذر طباعة الإيصال: $e';
    }
  }

  static Future<Uint8List> _buildThermalPdf(
    Map<String, dynamic> inv, {
    required int paperMm,
  }) async {
    final fontReg = pw.Font.ttf(
      await rootBundle.load('assets/fonts/Arial.ttf'),
    );
    final fontBold = pw.Font.ttf(
      await rootBundle.load('assets/fonts/Arial-Bold.ttf'),
    );

    final width = paperMm == 80
        ? 80 * PdfPageFormat.mm
        : 58 * PdfPageFormat.mm;
    final pageFormat = PdfPageFormat(
      width,
      double.infinity,
      marginAll: paperMm == 80 ? 3 * PdfPageFormat.mm : 2 * PdfPageFormat.mm,
    );

    final company = Fmt.str(inv['company_name']).isEmpty
        ? 'الشركة'
        : Fmt.str(inv['company_name']);
    final invoiceNo = Fmt.str(inv['invoice_no']);
    final date = Fmt.dmy(Fmt.str(inv['invoice_date'] ?? inv['invoice_date_dmy']));
    final customer = Fmt.str(inv['customer_name']).isEmpty
        ? '—'
        : Fmt.str(inv['customer_name']);
    final qrPayload = Fmt.str(inv['qr_payload']).isNotEmpty
        ? Fmt.str(inv['qr_payload'])
        : (Fmt.str(inv['einv_qr']).isNotEmpty
            ? Fmt.str(inv['einv_qr'])
            : (invoiceNo.isNotEmpty ? 'INV:$invoiceNo' : 'INV'));

    final lines = <Map<String, dynamic>>[];
    final raw = inv['lines'] ?? inv['items'] ?? inv['rows'];
    if (raw is List) {
      for (final e in raw) {
        if (e is Map) lines.add(e.cast<String, dynamic>());
      }
    }

    final subtotal = Fmt.toDouble(inv['subtotal']);
    final discount = Fmt.toDouble(inv['discount_amount'] ?? inv['discount']);
    final tax = Fmt.toDouble(inv['tax_amount']);
    final total = Fmt.toDouble(inv['total']);
    final fs = paperMm == 80 ? 9.0 : 8.0;
    final fsSm = paperMm == 80 ? 8.0 : 7.0;
    final qrSize = paperMm == 80 ? 72.0 : 58.0;

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
              pw.Center(
                child: pw.Text(
                  company,
                  textAlign: pw.TextAlign.center,
                  style: pw.TextStyle(
                    font: fontBold,
                    fontSize: paperMm == 80 ? 13 : 11,
                    fontWeight: pw.FontWeight.bold,
                  ),
                ),
              ),
              pw.SizedBox(height: 4),
              pw.Center(
                child: pw.Text(
                  'فاتورة مبيعات',
                  textAlign: pw.TextAlign.center,
                  style: pw.TextStyle(font: fontBold, fontSize: fs),
                ),
              ),
              pw.SizedBox(height: 6),
              pw.Divider(thickness: 0.8),
              _kv('رقم الفاتورة', invoiceNo.isEmpty ? '—' : invoiceNo, fontReg, fontBold, fsSm),
              _kv('التاريخ', date.isEmpty ? '—' : date, fontReg, fontBold, fsSm),
              _kv('العميل', customer, fontReg, fontBold, fsSm),
              pw.SizedBox(height: 6),
              pw.Center(
                child: pw.BarcodeWidget(
                  barcode: pw.Barcode.qrCode(
                    errorCorrectLevel: pw.BarcodeQRCorrectionLevel.medium,
                  ),
                  data: qrPayload.length > 800
                      ? qrPayload.substring(0, 800)
                      : qrPayload,
                  width: qrSize,
                  height: qrSize,
                ),
              ),
              pw.SizedBox(height: 3),
              pw.Center(
                child: pw.Text(
                  'Please Check In',
                  style: pw.TextStyle(font: fontReg, fontSize: 7),
                ),
              ),
              pw.SizedBox(height: 6),
              pw.Divider(thickness: 0.8),
              pw.Text(
                'المواد',
                textAlign: pw.TextAlign.center,
                style: pw.TextStyle(font: fontBold, fontSize: fs),
              ),
              pw.SizedBox(height: 4),
              ...lines.map((ln) {
                final name = Fmt.str(ln['item_name'] ?? ln['name_ar'] ?? ln['name']);
                final qty = Fmt.toDouble(ln['qty']);
                final price = Fmt.toDouble(
                  ln['unit_price'] ?? ln['price'] ?? ln['sale_price'],
                );
                final lineTotal = Fmt.toDouble(
                  ln['line_total'] ??
                      ln['gross'] ??
                      ln['total'] ??
                      (qty * price),
                );
                return pw.Padding(
                  padding: const pw.EdgeInsets.only(bottom: 5),
                  child: pw.Column(
                    crossAxisAlignment: pw.CrossAxisAlignment.stretch,
                    children: [
                      pw.Text(
                        name.isEmpty ? 'مادة' : name,
                        style: pw.TextStyle(font: fontBold, fontSize: fsSm),
                      ),
                      pw.Row(
                        mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
                        children: [
                          pw.Text(
                            '${Fmt.money(qty)} × ${Fmt.money(price)}',
                            textDirection: pw.TextDirection.ltr,
                            style: pw.TextStyle(font: fontReg, fontSize: fsSm - 0.5),
                          ),
                          pw.Text(
                            Fmt.money(lineTotal),
                            textDirection: pw.TextDirection.ltr,
                            style: pw.TextStyle(font: fontBold, fontSize: fsSm),
                          ),
                        ],
                      ),
                    ],
                  ),
                );
              }),
              pw.Divider(thickness: 0.8),
              if (subtotal > 0)
                _kv('الإجمالي الفرعي', Fmt.money(subtotal), fontReg, fontBold, fsSm),
              if (discount > 0)
                _kv('الخصم', Fmt.money(discount), fontReg, fontBold, fsSm),
              if (tax > 0) _kv('الضريبة', Fmt.money(tax), fontReg, fontBold, fsSm),
              _kv('الإجمالي النهائي', Fmt.money(total), fontReg, fontBold, fs),
              pw.SizedBox(height: 8),
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
