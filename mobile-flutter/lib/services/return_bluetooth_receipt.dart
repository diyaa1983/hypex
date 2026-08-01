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

/// إيصال مرتجع مبيعات حراري (58/80 مم).
class ReturnBluetoothReceipt {
  ReturnBluetoothReceipt._();

  static Future<String?> printReturn(Map<String, dynamic> data) async {
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
      return 'تعذر طباعة المرتجع: $e';
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

    final company = Fmt.str(data['company_name']).isEmpty
        ? 'الشركة'
        : Fmt.str(data['company_name']);
    final returnNo = Fmt.str(data['return_no']);
    final date = Fmt.dmy(
      Fmt.str(data['return_date_dmy'] ?? data['return_date']),
    );
    final customer = Fmt.str(data['customer_name']).isEmpty
        ? '—'
        : Fmt.str(data['customer_name']);
    final invoiceNo = Fmt.str(data['invoice_no']);
    final qrPayload = Fmt.str(data['qr_payload']).isNotEmpty
        ? Fmt.str(data['qr_payload'])
        : (Fmt.str(data['einv_qr']).isNotEmpty
            ? Fmt.str(data['einv_qr'])
            : (returnNo.isNotEmpty ? 'RET:$returnNo' : 'RET'));

    final lines = <Map<String, dynamic>>[];
    final raw = data['lines'] ?? data['items'] ?? data['rows'];
    if (raw is List) {
      for (final e in raw) {
        if (e is Map) lines.add(e.cast<String, dynamic>());
      }
    }

    final subtotal = Fmt.toDouble(data['subtotal']);
    final tax = Fmt.toDouble(data['tax_amount']);
    final total = Fmt.toDouble(data['total']);
    final notes = Fmt.str(data['notes']);
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
                  'مرتجع مبيعات',
                  textAlign: pw.TextAlign.center,
                  style: pw.TextStyle(font: fontBold, fontSize: fs),
                ),
              ),
              pw.SizedBox(height: 6),
              pw.Divider(thickness: 0.8),
              _kv(
                'رقم المرتجع',
                returnNo.isEmpty ? '—' : returnNo,
                fontReg,
                fontBold,
                fsSm,
              ),
              _kv(
                'التاريخ',
                date.isEmpty ? '—' : date,
                fontReg,
                fontBold,
                fsSm,
              ),
              _kv('العميل', customer, fontReg, fontBold, fsSm),
              if (invoiceNo.isNotEmpty)
                _kv('فاتورة البيع', invoiceNo, fontReg, fontBold, fsSm),
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
              pw.SizedBox(height: 6),
              pw.Divider(thickness: 0.8),
              pw.Text(
                'المواد',
                textAlign: pw.TextAlign.center,
                style: pw.TextStyle(font: fontBold, fontSize: fs),
              ),
              pw.SizedBox(height: 4),
              _itemsTable(lines, fontReg, fontBold, fsSm, paperMm),
              pw.SizedBox(height: 4),
              pw.Divider(thickness: 0.8),
              if (subtotal > 0)
                _kv(
                  'الإجمالي الفرعي',
                  Fmt.money(subtotal),
                  fontReg,
                  fontBold,
                  fsSm,
                ),
              if (tax > 0)
                _kv('الضريبة', Fmt.money(tax), fontReg, fontBold, fsSm),
              _kv('الإجمالي النهائي', Fmt.money(total), fontReg, fontBold, fs),
              if (notes.isNotEmpty) ...[
                pw.SizedBox(height: 4),
                pw.Divider(thickness: 0.8),
                _kv('ملاحظات', notes, fontReg, fontBold, fsSm),
              ],
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

  static pw.Widget _itemsTable(
    List<Map<String, dynamic>> lines,
    pw.Font reg,
    pw.Font bold,
    double fs,
    int paperMm,
  ) {
    final headStyle = pw.TextStyle(font: bold, fontSize: fs - 0.5);
    final cellStyle = pw.TextStyle(font: reg, fontSize: fs - 0.5);
    final nameStyle = pw.TextStyle(font: bold, fontSize: fs - 0.5);

    pw.Widget cell(
      String text, {
      pw.TextAlign align = pw.TextAlign.right,
      pw.TextStyle? style,
      bool ltr = false,
    }) {
      return pw.Padding(
        padding: const pw.EdgeInsets.symmetric(horizontal: 2, vertical: 2),
        child: pw.Text(
          text,
          textAlign: align,
          textDirection: ltr ? pw.TextDirection.ltr : pw.TextDirection.rtl,
          style: style ?? cellStyle,
        ),
      );
    }

    final rows = <pw.TableRow>[
      pw.TableRow(
        decoration: const pw.BoxDecoration(color: PdfColors.grey300),
        children: [
          cell('المادة', style: headStyle),
          cell('كمية', align: pw.TextAlign.center, style: headStyle),
          cell('سعر', align: pw.TextAlign.center, style: headStyle),
          cell('الإجمالي', align: pw.TextAlign.center, style: headStyle),
        ],
      ),
    ];

    for (final ln in lines) {
      final qty = Fmt.toDouble(ln['qty']);
      if (qty <= 0) continue;
      final name = Fmt.str(ln['item_name'] ?? ln['name_ar'] ?? ln['name']);
      final price = Fmt.toDouble(
        ln['unit_price'] ?? ln['price'] ?? ln['sale_price'],
      );
      final lineTotal = Fmt.toDouble(
        ln['line_gross'] ??
            ln['line_total'] ??
            ln['gross'] ??
            ln['total'] ??
            (qty * price),
      );
      rows.add(
        pw.TableRow(
          children: [
            cell(name.isEmpty ? 'مادة' : name, style: nameStyle),
            cell(
              qty == 0 ? '' : Fmt.money(qty),
              align: pw.TextAlign.center,
              ltr: true,
            ),
            cell(
              price == 0 ? '' : Fmt.money(price),
              align: pw.TextAlign.center,
              ltr: true,
            ),
            cell(
              lineTotal == 0 ? '' : Fmt.money(lineTotal),
              align: pw.TextAlign.center,
              ltr: true,
              style: nameStyle,
            ),
          ],
        ),
      );
    }

    return pw.Table(
      border: pw.TableBorder.all(width: 0.35, color: PdfColors.grey700),
      columnWidths: {
        0: const pw.FlexColumnWidth(3.2),
        1: pw.FlexColumnWidth(paperMm == 80 ? 1.1 : 1.0),
        2: pw.FlexColumnWidth(paperMm == 80 ? 1.3 : 1.2),
        3: pw.FlexColumnWidth(paperMm == 80 ? 1.5 : 1.4),
      },
      defaultVerticalAlignment: pw.TableCellVerticalAlignment.middle,
      children: rows,
    );
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
