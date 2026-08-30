import 'dart:typed_data';

import 'package:flutter/services.dart';
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;

import 'print_brand.dart';

/// تقرير جدولي A4 (طلبات / زيارات) بخط عربي.
class ReportTablePdf {
  ReportTablePdf._();

  static Future<Uint8List> build({
    required String title,
    String subtitle = '',
    required List<String> headers,
    required List<List<String>> rows,
    String? footer,
    bool landscape = false,
  }) async {
    final fontReg = pw.Font.ttf(await rootBundle.load('assets/fonts/Arial.ttf'));
    final fontBold =
        pw.Font.ttf(await rootBundle.load('assets/fonts/Arial-Bold.ttf'));
    final theme = pw.ThemeData.withFont(base: fontReg, bold: fontBold);
    final brand = await PrintBrand.header(
      paperMm: 80,
      bold: fontBold,
      title: title,
    );

    final pageFormat =
        landscape ? PdfPageFormat.a4.landscape : PdfPageFormat.a4;
    final doc = pw.Document(theme: theme);
    doc.addPage(
      pw.MultiPage(
        pageFormat: pageFormat,
        theme: theme,
        textDirection: pw.TextDirection.rtl,
        margin: const pw.EdgeInsets.fromLTRB(14, 16, 14, 18),
        header: (_) => pw.Column(
          crossAxisAlignment: pw.CrossAxisAlignment.stretch,
          children: [
            brand,
            if (subtitle.isNotEmpty) pw.SizedBox(height: 6),
            if (subtitle.isNotEmpty)
              pw.Text(
                subtitle,
                textAlign: pw.TextAlign.center,
                style: pw.TextStyle(font: fontReg, fontSize: 9),
              ),
            pw.SizedBox(height: 8),
          ],
        ),
        build: (ctx) {
          final table = pw.TableHelper.fromTextArray(
            headers: headers,
            data: rows,
            headerStyle: pw.TextStyle(
              font: fontBold,
              fontSize: 8,
              color: PdfColors.white,
              fontWeight: pw.FontWeight.bold,
            ),
            headerDecoration: const pw.BoxDecoration(color: PdfColor.fromInt(0xFF5B6B7C)),
            cellStyle: pw.TextStyle(font: fontReg, fontSize: 7.5),
            cellAlignment: pw.Alignment.center,
            headerAlignment: pw.Alignment.center,
            cellPadding: const pw.EdgeInsets.symmetric(horizontal: 3, vertical: 4),
            border: pw.TableBorder.all(color: PdfColors.blueGrey300, width: 0.4),
          );
          return [
            table,
            if (footer != null && footer.isNotEmpty) ...[
              pw.SizedBox(height: 10),
              pw.Align(
                alignment: pw.Alignment.centerRight,
                child: pw.Text(
                  footer,
                  style: pw.TextStyle(
                    font: fontBold,
                    fontSize: 10,
                    fontWeight: pw.FontWeight.bold,
                  ),
                ),
              ),
            ],
          ];
        },
      ),
    );
    return Uint8List.fromList(await doc.save());
  }
}
