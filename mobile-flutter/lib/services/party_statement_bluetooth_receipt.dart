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

/// كشف حساب حراري بحجم ورق Bluetooth (58/80 مم).
class PartyStatementBluetoothReceipt {
  PartyStatementBluetoothReceipt._();

  static Future<String?> printStatement(Map<String, dynamic> data) async {
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
      return 'تعذر طباعة الكشف: $e';
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

    final partyType = Fmt.str(data['party_type']);
    final partyLabel = partyType == 'supplier' ? 'مورد' : 'عميل';
    final partyName =
        Fmt.str(data['party_name']).isEmpty ? '—' : Fmt.str(data['party_name']);
    final partyCode = Fmt.str(data['party_code']);
    final from = Fmt.dmy(Fmt.str(data['from_dmy'] ?? data['from']));
    final to = Fmt.dmy(Fmt.str(data['to_dmy'] ?? data['to']));
    final opening = Fmt.toDouble(data['opening_balance']);
    final totalDebit = Fmt.toDouble(data['total_debit']);
    final totalCredit = Fmt.toDouble(data['total_credit']);
    final closing = Fmt.toDouble(data['closing_balance']);

    final rows = <Map<String, dynamic>>[];
    final raw = data['rows'];
    if (raw is List) {
      for (final e in raw) {
        if (e is Map) rows.add(e.cast<String, dynamic>());
      }
    }

    final fs = paperMm == 80 ? 8.5 : 7.5;
    final fsSm = paperMm == 80 ? 7.5 : 6.5;

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
                  'كشف حساب $partyLabel',
                  textAlign: pw.TextAlign.center,
                  style: pw.TextStyle(
                    font: fontBold,
                    fontSize: paperMm == 80 ? 12 : 10.5,
                    fontWeight: pw.FontWeight.bold,
                  ),
                ),
              ),
              pw.SizedBox(height: 4),
              pw.Divider(thickness: 0.8),
              _kv('الاسم', partyName, fontReg, fontBold, fsSm),
              if (partyCode.isNotEmpty)
                _kv('الرمز', partyCode, fontReg, fontBold, fsSm),
              _kv(
                'الفترة',
                '${from.isEmpty ? '—' : from} → ${to.isEmpty ? '—' : to}',
                fontReg,
                fontBold,
                fsSm,
              ),
              _kv('رصيد أول المدة', Fmt.money(opening), fontReg, fontBold,
                  fsSm),
              pw.SizedBox(height: 4),
              pw.Divider(thickness: 0.8),
              _movementsTable(rows, fontReg, fontBold, fsSm, paperMm),
              pw.SizedBox(height: 4),
              pw.Divider(thickness: 0.8),
              _kv('إجمالي المدين', Fmt.money(totalDebit), fontReg, fontBold,
                  fsSm),
              _kv('إجمالي الدائن', Fmt.money(totalCredit), fontReg, fontBold,
                  fsSm),
              _kv('الرصيد الختامي', Fmt.money(closing), fontReg, fontBold, fs),
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

  static pw.Widget _movementsTable(
    List<Map<String, dynamic>> rows,
    pw.Font reg,
    pw.Font bold,
    double fs,
    int paperMm,
  ) {
    final headStyle = pw.TextStyle(font: bold, fontSize: fs - 0.3);
    final cellStyle = pw.TextStyle(font: reg, fontSize: fs - 0.5);

    pw.Widget cell(
      String text, {
      pw.TextAlign align = pw.TextAlign.right,
      pw.TextStyle? style,
      bool ltr = false,
    }) {
      return pw.Padding(
        padding: const pw.EdgeInsets.symmetric(horizontal: 1.5, vertical: 1.5),
        child: pw.Text(
          text,
          textAlign: align,
          maxLines: 2,
          textDirection: ltr ? pw.TextDirection.ltr : pw.TextDirection.rtl,
          style: style ?? cellStyle,
        ),
      );
    }

    final tableRows = <pw.TableRow>[
      pw.TableRow(
        decoration: const pw.BoxDecoration(color: PdfColors.grey300),
        children: [
          cell('التاريخ', style: headStyle, align: pw.TextAlign.center),
          cell('البيان', style: headStyle),
          cell('مدين', style: headStyle, align: pw.TextAlign.center),
          cell('دائن', style: headStyle, align: pw.TextAlign.center),
          cell('رصيد', style: headStyle, align: pw.TextAlign.center),
        ],
      ),
    ];

    if (rows.isEmpty) {
      tableRows.add(
        pw.TableRow(
          children: [
            cell('—', align: pw.TextAlign.center),
            cell('لا توجد حركات'),
            cell('—', align: pw.TextAlign.center, ltr: true),
            cell('—', align: pw.TextAlign.center, ltr: true),
            cell('—', align: pw.TextAlign.center, ltr: true),
          ],
        ),
      );
    } else {
      for (final row in rows) {
        final date = Fmt.dmy(Fmt.str(row['date'] ?? row['doc_date']));
        final desc = Fmt.str(
          row['description'] ?? row['doc_type'] ?? row['type'],
        );
        final debit = Fmt.toDouble(row['debit']);
        final credit = Fmt.toDouble(row['credit']);
        final balance = Fmt.toDouble(row['balance'] ?? row['running_balance']);
        tableRows.add(
          pw.TableRow(
            children: [
              cell(
                date.isEmpty ? '—' : date,
                align: pw.TextAlign.center,
                ltr: true,
              ),
              cell(desc.isEmpty ? '—' : desc),
              cell(
                debit > 0 ? Fmt.money(debit) : '',
                align: pw.TextAlign.center,
                ltr: true,
              ),
              cell(
                credit > 0 ? Fmt.money(credit) : '',
                align: pw.TextAlign.center,
                ltr: true,
              ),
              cell(
                Fmt.money(balance),
                align: pw.TextAlign.center,
                ltr: true,
                style: pw.TextStyle(font: bold, fontSize: fs - 0.5),
              ),
            ],
          ),
        );
      }
    }

    return pw.Table(
      border: pw.TableBorder.all(width: 0.3, color: PdfColors.grey700),
      columnWidths: {
        0: pw.FlexColumnWidth(paperMm == 80 ? 1.4 : 1.3),
        1: const pw.FlexColumnWidth(2.4),
        2: pw.FlexColumnWidth(paperMm == 80 ? 1.1 : 1.0),
        3: pw.FlexColumnWidth(paperMm == 80 ? 1.1 : 1.0),
        4: pw.FlexColumnWidth(paperMm == 80 ? 1.2 : 1.1),
      },
      defaultVerticalAlignment: pw.TableCellVerticalAlignment.middle,
      children: tableRows,
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
