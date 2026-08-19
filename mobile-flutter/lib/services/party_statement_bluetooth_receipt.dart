import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;

import '../core/format.dart';
import 'bluetooth_print_service.dart';
import 'bluetooth_printer_settings.dart';
import 'print_brand.dart';
import 'thermal_print_widgets.dart';

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
      return BluetoothPrintService.printPdfBytes(
        pdfBytes,
        jobName: 'كشف_${Fmt.str(data['party_name'])}',
      );
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

    final cheques = <Map<String, dynamic>>[];
    final rawCheques = data['cheques'];
    if (rawCheques is List) {
      for (final e in rawCheques) {
        if (e is Map) cheques.add(e.cast<String, dynamic>());
      }
    }
    final chequeTotal = Fmt.toDouble(data['cheque_total']);

    final fs = paperMm == 80 ? 13.0 : 11.0;
    final fsSm = paperMm == 80 ? 11.0 : 9.5;
    final fsTable = paperMm == 80 ? 10.0 : 8.5;

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
              _periodRow(from, to, fontReg, fontBold, fsSm),
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
              pw.Divider(thickness: 0.8),
              _chequesTable(
                cheques,
                chequeTotal,
                fontReg,
                fontBold,
                fsTable,
                paperMm,
              ),
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
      bool rtlDate = false,
    }) {
      if (rtlDate) {
        return thermalCell(
          text,
          style: style ?? pw.TextStyle(font: reg, fontSize: fs * 0.85),
          align: align,
          ltr: true,
          maxLines: maxLines,
        );
      }
      return thermalCell(
        text,
        style: style ?? cellStyle,
        align: align,
        ltr: ltr,
        maxLines: maxLines,
      );
    }

    // جدول pdf يرتّب الخلايا من اليسار لليمين، لذلك نعكس الترتيب
    // ليظهر الكشف عربياً: التاريخ | البيان | مدين | دائن | رصيد.
    List<pw.Widget> rtl(List<pw.Widget> cells) => cells.reversed.toList();

    final tableRows = <pw.TableRow>[
      pw.TableRow(
        decoration: ThermalTableStyle.headerDecoration,
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
                rtlDate: true,
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

    final dateW = paperMm == 80 ? 36.0 : 28.0;
    final numW = paperMm == 80 ? 34.0 : 25.0;
    final balW = paperMm == 80 ? 38.0 : 27.0;

    return pw.Table(
      border: ThermalTableStyle.border,
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

  static pw.Widget _chequesTable(
    List<Map<String, dynamic>> cheques,
    double chequeTotal,
    pw.Font reg,
    pw.Font bold,
    double fs,
    int paperMm,
  ) {
    final headStyle = pw.TextStyle(font: bold, fontSize: fs);
    final cellStyle = pw.TextStyle(font: reg, fontSize: fs);
    pw.Widget cell(
      String text, {
      bool head = false,
      bool ltr = false,
      bool strong = false,
    }) {
      return pw.Padding(
        padding: const pw.EdgeInsets.symmetric(horizontal: 1.5, vertical: 2.4),
        child: pw.Text(
          text,
          textAlign: pw.TextAlign.center,
          textDirection: ltr ? pw.TextDirection.ltr : pw.TextDirection.rtl,
          style: head || strong ? headStyle : cellStyle,
        ),
      );
    }

    List<pw.Widget> rtl(List<pw.Widget> cells) => cells.reversed.toList();
    final rows = <pw.TableRow>[
      pw.TableRow(
        decoration: ThermalTableStyle.headerDecoration,
        children: rtl([
          cell('الشيك', head: true),
          cell('التاريخ', head: true),
          cell('قيمة الشيك', head: true),
          cell('تاريخ القبض', head: true),
        ]),
      ),
    ];
    if (cheques.isEmpty) {
      rows.add(
        pw.TableRow(
          children: rtl([
            cell('لا توجد شيكات قيد التحصيل.'),
            cell(''),
            cell(''),
            cell(''),
          ]),
        ),
      );
    } else {
      var sum = 0.0;
      for (final ch in cheques) {
        final no = Fmt.str(ch['chq_no'] ?? ch['cheque_no'] ?? ch['check_no']);
        final date = _shortDate(
          Fmt.str(ch['chq_date'] ?? ch['due_date'] ?? ch['date']),
        );
        final amt = Fmt.toDouble(ch['amount'] ?? ch['amt']);
        sum += amt;
        final recv = _shortDate(
          Fmt.str(ch['receipt_date'] ?? ch['recv_date']),
        );
        rows.add(
          pw.TableRow(
            children: rtl([
              cell(no.isEmpty ? '—' : no, ltr: true),
              cell(date.isEmpty ? '—' : date, ltr: true),
              cell(_amount(amt, group: paperMm == 80), ltr: true, strong: true),
              cell(recv.isEmpty ? '—' : recv, ltr: true),
            ]),
          ),
        );
      }
      if (chequeTotal <= 0) chequeTotal = sum;
    }
    rows.add(
      pw.TableRow(
        children: rtl([
          cell('مجموع الشيكات قيد التحصيل', strong: true),
          cell(''),
          cell(
            _amount(chequeTotal, group: paperMm == 80),
            ltr: true,
            strong: true,
          ),
          cell(''),
        ]),
      ),
    );

    return pw.Column(
      crossAxisAlignment: pw.CrossAxisAlignment.stretch,
      children: [
        pw.Text(
          'الشيكات قيد التحصيل',
          textAlign: pw.TextAlign.center,
          style: pw.TextStyle(font: bold, fontSize: fs + 1),
        ),
        pw.SizedBox(height: 4),
        pw.Table(
          border: ThermalTableStyle.border,
          columnWidths: {
            0: const pw.FlexColumnWidth(1.2),
            1: const pw.FlexColumnWidth(1.3),
            2: const pw.FlexColumnWidth(1.2),
            3: const pw.FlexColumnWidth(1.1),
          },
          children: rows,
        ),
      ],
    );
  }

  static pw.Widget _periodRow(
    String from,
    String to,
    pw.Font reg,
    pw.Font bold,
    double fs,
  ) {
    final labelStyle = pw.TextStyle(font: bold, fontSize: fs);
    final valStyle = pw.TextStyle(font: reg, fontSize: fs);
    final fromTxt = from.isEmpty ? '—' : from;
    final toTxt = to.isEmpty ? '—' : to;
    return pw.Padding(
      padding: const pw.EdgeInsets.only(bottom: 2),
      child: pw.Row(
        crossAxisAlignment: pw.CrossAxisAlignment.start,
        children: [
          pw.Text('الفترة: ', style: labelStyle),
          pw.Expanded(
            child: pw.Wrap(
              alignment: pw.WrapAlignment.start,
              crossAxisAlignment: pw.WrapCrossAlignment.center,
              children: [
                pw.Text('من ', style: valStyle),
                thermalDateText(fromTxt, style: valStyle),
                pw.Text(' إلى ', style: valStyle),
                thermalDateText(toTxt, style: valStyle),
              ],
            ),
          ),
        ],
      ),
    );
  }

  static pw.Widget _kv(
    String label,
    String value,
    pw.Font reg,
    pw.Font bold,
    double fs, {
    bool rtlDate = false,
  }) {
    final valStyle = pw.TextStyle(font: reg, fontSize: fs);
    return pw.Padding(
      padding: const pw.EdgeInsets.only(bottom: 2),
      child: pw.Row(
        crossAxisAlignment: pw.CrossAxisAlignment.start,
        children: [
          pw.Text('$label: ', style: pw.TextStyle(font: bold, fontSize: fs)),
          pw.Expanded(
            child: rtlDate
                ? thermalDateText(value, style: valStyle)
                : pw.Text(value, style: valStyle),
          ),
        ],
      ),
    );
  }
}
