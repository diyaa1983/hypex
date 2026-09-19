import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;

/// أدوات مشتركة لطباعة حرارية أوضح.
class ThermalTableStyle {
  ThermalTableStyle._();

  static final border = pw.TableBorder.all(width: 0.6, color: PdfColors.black);
  static final headerDecoration = pw.BoxDecoration(color: PdfColors.grey400);
  static const cellPadding =
      pw.EdgeInsets.symmetric(horizontal: 3, vertical: 4.5);
  static const zebraOdd = PdfColors.grey100;
}

pw.Widget thermalDateText(
  String text, {
  required pw.TextStyle style,
  pw.TextAlign align = pw.TextAlign.right,
}) =>
    pw.Text(
      text,
      textDirection: pw.TextDirection.ltr,
      textAlign: align,
      style: style,
    );

pw.Widget thermalCell(
  String text, {
  required pw.TextStyle style,
  pw.TextAlign align = pw.TextAlign.center,
  bool ltr = false,
  pw.EdgeInsets? padding,
  int maxLines = 2,
  pw.TextOverflow overflow = pw.TextOverflow.clip,
}) {
  final hasArabic = RegExp(r'[\u0600-\u06FF]').hasMatch(text);
  return pw.Padding(
    padding: padding ?? ThermalTableStyle.cellPadding,
    child: pw.Text(
      text,
      textAlign: align,
      textDirection:
          (ltr && !hasArabic) ? pw.TextDirection.ltr : pw.TextDirection.rtl,
      style: style,
      maxLines: maxLines,
      overflow: overflow,
      softWrap: true,
    ),
  );
}
