import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:pdf/pdf.dart';
import 'package:printing/printing.dart';

import '../core/theme.dart';

/// معاينة PDF بحجم A4 داخل التطبيق — بدون زر طباعة نظام/Bluetooth.
class PdfA4ViewerScreen extends StatelessWidget {
  const PdfA4ViewerScreen({
    super.key,
    required this.bytes,
    required this.title,
    this.fileName,
  });

  final Uint8List bytes;
  final String title;
  final String? fileName;

  @override
  Widget build(BuildContext context) {
    final name = (fileName == null || fileName!.trim().isEmpty)
        ? 'document.pdf'
        : (fileName!.toLowerCase().endsWith('.pdf')
            ? fileName!
            : '${fileName!}.pdf');

    return Scaffold(
      appBar: AppBar(
        title: Text(title),
        actions: [
          IconButton(
            tooltip: 'مشاركة PDF',
            onPressed: () => Printing.sharePdf(bytes: bytes, filename: name),
            icon: const Icon(Icons.share_outlined),
          ),
        ],
      ),
      body: PdfPreview(
        build: (_) async => bytes,
        initialPageFormat: PdfPageFormat.a4,
        canChangePageFormat: false,
        canChangeOrientation: false,
        canDebug: false,
        allowPrinting: false,
        allowSharing: false,
        useActions: false,
        pdfFileName: name,
        previewPageMargin:
            const EdgeInsets.symmetric(horizontal: 10, vertical: 12),
        scrollViewDecoration: const BoxDecoration(color: Color(0xFFE8EEF5)),
        pdfPreviewPageDecoration: const BoxDecoration(
          color: Colors.white,
          boxShadow: [
            BoxShadow(
              color: Color(0x22000000),
              blurRadius: 8,
              offset: Offset(0, 2),
            ),
          ],
        ),
        loadingWidget: const Center(
          child: CircularProgressIndicator(color: AppTheme.primary),
        ),
      ),
    );
  }
}
