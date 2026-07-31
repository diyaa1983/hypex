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
      final pdfBytes = await buildThermalPdf(inv, paperMm: cfg.paperMm);
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

  /// بناء إيصال حراري PDF بعرض 58 أو 80 مم للمعاينة أو الطباعة.
  static Future<Uint8List> buildThermalPdf(
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
    final paymentLabel = _paymentTypeLabel(
      Fmt.str(inv['payment_type'] ?? inv['payment_label'] ?? inv['pay_type']),
    );
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
    var discount = Fmt.toDouble(inv['discount_amount'] ?? inv['discount']);
    if (discount <= 0) {
      for (final ln in lines) {
        discount += Fmt.toDouble(ln['discount_amount'] ?? ln['discount']);
      }
    }
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
              if (paymentLabel.isNotEmpty)
                _kv('طريقة الدفع', paymentLabel, fontReg, fontBold, fsSm),
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
              if (discount > 0)
                _kv('الخصم', Fmt.money(discount), fontReg, fontBold, fsSm),
              if (tax > 0)
                _kv('الضريبة', Fmt.money(tax), fontReg, fontBold, fsSm),
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

  static String _paymentTypeLabel(String raw) {
    final v = raw.trim().toLowerCase();
    if (v.isEmpty) return '';
    if (v == 'cash' || v == 'نقدي' || v.contains('نقد')) return 'نقدي';
    if (v == 'credit' ||
        v == 'ذمم' ||
        v == 'آجل' ||
        v.contains('ذمم') ||
        v.contains('آجل')) {
      return 'ذمم';
    }
    return raw.trim();
  }

  static pw.Widget _itemsTable(
    List<Map<String, dynamic>> lines,
    pw.Font reg,
    pw.Font bold,
    double fs,
    int paperMm,
  ) {
    final headFs = paperMm == 80 ? fs - 0.5 : fs - 1.0;
    final headStyle = pw.TextStyle(font: bold, fontSize: headFs);
    final cellStyle = pw.TextStyle(font: reg, fontSize: headFs);
    final nameStyle = pw.TextStyle(font: bold, fontSize: headFs);

    final showExtra = lines.any((ln) => Fmt.toDouble(ln['qty_extra']) > 0);
    final showDisc = lines.any((ln) {
      final disc = Fmt.toDouble(ln['discount_amount'] ?? ln['discount']);
      final discInput = Fmt.str(ln['line_discount_input']);
      return disc > 0 || discInput.isNotEmpty;
    });

    pw.Widget cell(
      String text, {
      pw.TextAlign align = pw.TextAlign.right,
      pw.TextStyle? style,
      bool ltr = false,
    }) {
      return pw.Padding(
        padding: const pw.EdgeInsets.symmetric(horizontal: 1.5, vertical: 2),
        child: pw.Text(
          text,
          textAlign: align,
          textDirection: ltr ? pw.TextDirection.ltr : pw.TextDirection.rtl,
          style: style ?? cellStyle,
        ),
      );
    }

    // الجدول LTR داخلياً → نعكس العناصر ليظهر يمين→يسار:
    // يمين: # | المادة | كمية | إضافي؟ | سعر | خصم؟ :يسار
    List<pw.Widget> rowCells({
      required String seq,
      required String name,
      required String qty,
      String? extra,
      required String price,
      String? disc,
    }) {
      final cells = <pw.Widget>[
        if (showDisc)
          cell(disc ?? '—', align: pw.TextAlign.center, ltr: true),
        cell(price, align: pw.TextAlign.center, ltr: true),
        if (showExtra)
          cell(extra ?? '—', align: pw.TextAlign.center, ltr: true),
        cell(qty, align: pw.TextAlign.center, ltr: true),
        cell(name, style: nameStyle),
        cell(seq, align: pw.TextAlign.center, ltr: true),
      ];
      return cells;
    }

    final rows = <pw.TableRow>[
      pw.TableRow(
        decoration: const pw.BoxDecoration(color: PdfColors.grey300),
        children: [
          if (showDisc)
            cell('خصم', align: pw.TextAlign.center, style: headStyle),
          cell('سعر', align: pw.TextAlign.center, style: headStyle),
          if (showExtra)
            cell('إض.', align: pw.TextAlign.center, style: headStyle),
          cell('كمية', align: pw.TextAlign.center, style: headStyle),
          cell('المادة', style: headStyle),
          cell('#', align: pw.TextAlign.center, style: headStyle),
        ],
      ),
    ];

    var seq = 0;
    for (final ln in lines) {
      seq++;
      final name = Fmt.str(
        ln['item_name'] ?? ln['name_ar'] ?? ln['name'] ?? ln['line_desc'],
      );
      final qty = Fmt.toDouble(ln['qty']);
      final qtyExtra = Fmt.toDouble(ln['qty_extra']);
      final price = Fmt.toDouble(
        ln['unit_price'] ?? ln['price'] ?? ln['sale_price'],
      );
      final disc = Fmt.toDouble(ln['discount_amount'] ?? ln['discount']);
      final discInput = Fmt.str(ln['line_discount_input']);
      final discLabel = discInput.isNotEmpty
          ? discInput
          : (disc > 0 ? Fmt.money(disc) : '—');

      rows.add(
        pw.TableRow(
          children: rowCells(
            seq: '$seq',
            name: name.isEmpty ? 'مادة' : name,
            qty: Fmt.money(qty),
            extra: qtyExtra > 0 ? Fmt.money(qtyExtra) : '—',
            price: Fmt.money(price),
            disc: discLabel,
          ),
        ),
      );
    }

    // عرض الأعمدة بنفس ترتيب العناصر (يسار→يمين = معكوس القراءة)
    final widths = <int, pw.TableColumnWidth>{};
    var i = 0;
    if (showDisc) {
      widths[i++] = pw.FlexColumnWidth(paperMm == 80 ? 0.95 : 0.9); // خصم
    }
    widths[i++] = pw.FlexColumnWidth(paperMm == 80 ? 1.0 : 0.95); // سعر
    if (showExtra) {
      widths[i++] = pw.FlexColumnWidth(paperMm == 80 ? 0.85 : 0.8); // إض
    }
    widths[i++] = pw.FlexColumnWidth(paperMm == 80 ? 0.95 : 0.9); // كمية
    widths[i++] = const pw.FlexColumnWidth(3.0); // اسم
    widths[i++] = pw.FlexColumnWidth(paperMm == 80 ? 0.55 : 0.5); // #

    return pw.Table(
      border: pw.TableBorder.all(width: 0.35, color: PdfColors.grey700),
      columnWidths: widths,
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
