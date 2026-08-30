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
          const line = PdfColor.fromInt(0xFF475569);
          const headBg = PdfColor.fromInt(0xFF334155);
          const stripe = PdfColor.fromInt(0xFFEEF2F7);
          pw.Widget cell(
            String text, {
            required pw.Font font,
            bool header = false,
            bool stripeRow = false,
          }) {
            return pw.Container(
              alignment: pw.Alignment.center,
              padding: const pw.EdgeInsets.symmetric(horizontal: 4, vertical: 5),
              decoration: pw.BoxDecoration(
                color: header
                    ? headBg
                    : (stripeRow ? stripe : PdfColors.white),
                border: pw.Border.all(color: line, width: 0.7),
              ),
              child: pw.Text(
                text,
                textAlign: pw.TextAlign.center,
                style: pw.TextStyle(
                  font: font,
                  fontSize: header ? 8 : 7.5,
                  color: header ? PdfColors.white : PdfColors.black,
                  fontWeight: header ? pw.FontWeight.bold : pw.FontWeight.normal,
                ),
              ),
            );
          }

          final table = pw.Table(
            border: pw.TableBorder.all(color: line, width: 0.8),
            defaultColumnWidth: const pw.FlexColumnWidth(),
            defaultVerticalAlignment: pw.TableCellVerticalAlignment.middle,
            children: [
              pw.TableRow(
                children: [
                  for (final h in headers)
                    cell(h, font: fontBold, header: true),
                ],
              ),
              for (var i = 0; i < rows.length; i++)
                pw.TableRow(
                  children: [
                    for (var c = 0; c < headers.length; c++)
                      cell(
                        c < rows[i].length ? rows[i][c] : '',
                        font: fontReg,
                        stripeRow: i.isOdd,
                      ),
                  ],
                ),
            ],
          );
          return [
            table,
            if (footer != null && footer.isNotEmpty) ...[
              pw.SizedBox(height: 8),
              pw.Container(
                width: double.infinity,
                padding: const pw.EdgeInsets.symmetric(
                  horizontal: 8,
                  vertical: 6,
                ),
                decoration: pw.BoxDecoration(
                  color: stripe,
                  border: pw.Border.all(color: line, width: 0.8),
                ),
                child: pw.Text(
                  footer,
                  textAlign: pw.TextAlign.right,
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
