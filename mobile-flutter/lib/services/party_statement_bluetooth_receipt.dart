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
import 'print_brand.dart';
import 'thermal_raster.dart';

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
          generator.imageRaster(
            flattenOnWhite(resized),
            imageFn: PosImageFn.bitImageRaster,
          ),
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
    final totalDebit = Fmt.toDouble(data['total_debit']);
    final totalCredit = Fmt.toDouble(data['total_credit']);
    final closing = Fmt.toDouble(data['closing_balance'] ?? data['balance']);
    final salesRep =
        Fmt.str(data['sales_rep_name'] ?? data['sales_rep_names']);

    final rows = <Map<String, dynamic>>[];
    final raw = data['rows'] ?? data['lines'];
    if (raw is List) {
      for (final e in raw) {
        if (e is Map) rows.add(e.cast<String, dynamic>());
      }
    }

    final fs = paperMm == 80 ? 8.5 : 7.5;
    final fsSm = paperMm == 80 ? 7.5 : 6.5;
    final fsTable = paperMm == 80 ? 6.8 : 5.2;

    final brandHeader = await PrintBrand.header(
      paperMm: paperMm,
      bold: fontBold,
      title: 'كشف حساب $partyLabel',
      companyFromDocument: Fmt.str(data['company_name']),
      logoUrlFromDocument: Fmt.str(data['logo_url']),
    );

    final doc = pw.Document();
    doc.addPage(
      pw.Page(
        pageFormat: pageFormat,
        textDirection: pw.TextDirection.rtl,
        theme: pw.ThemeData.withFont(base: fontReg, bold: fontBold),
        build: (ctx) {
          return pw.Container(
            color: PdfColors.white,
            width: double.infinity,
            child: pw.Column(
            crossAxisAlignment: pw.CrossAxisAlignment.stretch,
            children: [
              brandHeader,
              pw.SizedBox(height: 4),
              pw.Divider(thickness: 0.8),
              _kv('الاسم', partyName, fontReg, fontBold, fsSm),
              if (partyCode.isNotEmpty)
                _kv('الرمز', partyCode, fontReg, fontBold, fsSm),
              if (salesRep.isNotEmpty)
                _kv('المندوب', salesRep, fontReg, fontBold, fsSm),
              _kv(
                'الفترة',
                '${from.isEmpty ? '—' : from} → ${to.isEmpty ? '—' : to}',
                fontReg,
                fontBold,
                fsSm,
              ),
              pw.SizedBox(height: 4),
              pw.Divider(thickness: 0.8),
              _movementsTable(rows, fontReg, fontBold, fsTable, paperMm),
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
          ),
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
    final headStyle = pw.TextStyle(font: bold, fontSize: fs);
    final cellStyle = pw.TextStyle(font: reg, fontSize: fs);
    final balStyle = pw.TextStyle(font: bold, fontSize: fs);
    final group = paperMm == 80;

    pw.Widget cell(
      String text, {
      pw.TextAlign align = pw.TextAlign.right,
      pw.TextStyle? style,
      bool ltr = false,
      int maxLines = 3,
    }) {
      return pw.Padding(
        padding: const pw.EdgeInsets.symmetric(horizontal: 1, vertical: 1.2),
        child: pw.Text(
          text,
          textAlign: align,
          maxLines: maxLines,
          textDirection: ltr ? pw.TextDirection.ltr : pw.TextDirection.rtl,
          style: style ?? cellStyle,
        ),
      );
    }

    // جدول pdf يرتّب الخلايا من اليسار لليمين، لذلك نعكس الترتيب
    // ليظهر الكشف عربياً: التاريخ | البيان | مدين | دائن | رصيد.
    List<pw.Widget> rtl(List<pw.Widget> cells) => cells.reversed.toList();

    final tableRows = <pw.TableRow>[
      pw.TableRow(
        decoration: const pw.BoxDecoration(color: PdfColors.grey300),
        children: rtl([
          cell('التاريخ',
              style: headStyle, align: pw.TextAlign.center, maxLines: 1),
          cell('البيان',
              style: headStyle, align: pw.TextAlign.center, maxLines: 1),
          cell('مدين',
              style: headStyle, align: pw.TextAlign.center, maxLines: 1),
          cell('دائن',
              style: headStyle, align: pw.TextAlign.center, maxLines: 1),
          cell('رصيد',
              style: headStyle, align: pw.TextAlign.center, maxLines: 1),
        ]),
      ),
    ];

    if (rows.isEmpty) {
      tableRows.add(
        pw.TableRow(
          children: rtl([
            cell('—', align: pw.TextAlign.center),
            cell('لا توجد حركات', align: pw.TextAlign.center),
            cell('—', align: pw.TextAlign.center, ltr: true),
            cell('—', align: pw.TextAlign.center, ltr: true),
            cell('—', align: pw.TextAlign.center, ltr: true),
          ]),
        ),
      );
    } else {
      for (final row in rows) {
        final date = _shortDate(
          Fmt.str(
            row['trn_date'] ?? row['date'] ?? row['doc_date'] ?? row['date_dmy'],
          ),
        );
        var desc = Fmt.str(
          row['description'] ??
              row['remark'] ??
              row['doc_type'] ??
              row['type'] ??
              '',
        ).trim();
        final docNo = Fmt.str(row['doc_no']).trim();
        if (docNo.isNotEmpty && !desc.contains(docNo)) {
          desc = desc.isEmpty ? docNo : '$desc ($docNo)';
        }
        final debit = Fmt.toDouble(row['debit']);
        final credit = Fmt.toDouble(row['credit']);
        final balance = Fmt.toDouble(row['balance'] ?? row['running_balance']);
        tableRows.add(
          pw.TableRow(
            children: rtl([
              cell(
                date.isEmpty ? '—' : date,
                align: pw.TextAlign.center,
                ltr: true,
                maxLines: 1,
              ),
              cell(desc.isEmpty ? '—' : desc, maxLines: 3),
              cell(
                debit > 0 ? _amount(debit, group: group) : '',
                align: pw.TextAlign.center,
                ltr: true,
                maxLines: 1,
              ),
              cell(
                credit > 0 ? _amount(credit, group: group) : '',
                align: pw.TextAlign.center,
                ltr: true,
                maxLines: 1,
              ),
              cell(
                _amount(balance, group: group),
                align: pw.TextAlign.center,
                ltr: true,
                maxLines: 1,
                style: balStyle,
              ),
            ]),
          ),
        );
      }
    }

    final dateW = paperMm == 80 ? 30.0 : 23.0;
    final numW = paperMm == 80 ? 34.0 : 25.0;
    final balW = paperMm == 80 ? 38.0 : 27.0;

    return pw.Table(
      border: pw.TableBorder.all(width: 0.3, color: PdfColors.grey700),
      columnWidths: {
        // الأعمدة معكوسة: 0 = رصيد ... 4 = التاريخ
        0: pw.FixedColumnWidth(balW),
        1: pw.FixedColumnWidth(numW),
        2: pw.FixedColumnWidth(numW),
        3: const pw.FlexColumnWidth(1),
        4: pw.FixedColumnWidth(dateW),
      },
      defaultVerticalAlignment: pw.TableCellVerticalAlignment.middle,
      children: tableRows,
    );
  }

  /// مبلغ مختصر للطباعة الحرارية: بدون أصفار زائدة حتى لا ينكسر السطر.
  /// على ورق 58 مم نحذف فواصل الآلاف لتوفير مساحة العمود.
  static String _amount(double v, {bool group = true}) {
    final rounded = (v * 1000).round() / 1000;
    var s = rounded == rounded.roundToDouble()
        ? Fmt.intNum(rounded)
        : Fmt.money(rounded);
    if (s.contains('.')) {
      s = s.replaceFirst(RegExp(r'0+$'), '');
      if (s.endsWith('.')) s = s.substring(0, s.length - 1);
    }
    return group ? s : s.replaceAll(',', '');
  }

  /// تاريخ مختصر dd/MM/yy ليظهر بجانب كل حركة على سطر واحد.
  static String _shortDate(String raw) {
    final v = raw.trim();
    if (v.isEmpty) return '';
    DateTime? d;
    try {
      d = DateTime.parse(v.contains('T') ? v : v.replaceFirst(' ', 'T'));
    } catch (_) {
      d = null;
    }
    if (d == null) {
      final m = RegExp(r'^(\d{1,4})[/\-.](\d{1,2})[/\-.](\d{1,4})').firstMatch(v);
      if (m != null) {
        final a = int.parse(m.group(1)!);
        final b = int.parse(m.group(2)!);
        final c = int.parse(m.group(3)!);
        d = a > 31 ? DateTime(a, b, c) : DateTime(c < 100 ? 2000 + c : c, b, a);
      }
    }
    if (d == null) return v;
    final dd = d.day.toString().padLeft(2, '0');
    final mm = d.month.toString().padLeft(2, '0');
    final yy = (d.year % 100).toString().padLeft(2, '0');
    return '$dd/$mm/$yy';
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
