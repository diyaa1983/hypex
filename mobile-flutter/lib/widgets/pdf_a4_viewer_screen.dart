import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:printing/printing.dart';

import '../core/theme.dart';

/// معاينة PDF داخل التطبيق عبر تحويل الصفحات إلى صور
/// (أكثر ثباتاً من PdfPreview مع ملفات mPDF العربية).
class PdfA4ViewerScreen extends StatefulWidget {
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
  State<PdfA4ViewerScreen> createState() => _PdfA4ViewerScreenState();
}

class _PdfA4ViewerScreenState extends State<PdfA4ViewerScreen> {
  late final Future<List<Uint8List>> _pagesFuture;

  String get _fileName {
    final name = (widget.fileName == null || widget.fileName!.trim().isEmpty)
        ? 'document.pdf'
        : widget.fileName!.trim();
    return name.toLowerCase().endsWith('.pdf') ? name : '$name.pdf';
  }

  @override
  void initState() {
    super.initState();
    _pagesFuture = _rasterPages();
  }

  Future<List<Uint8List>> _rasterPages() async {
    final bytes = widget.bytes;
    if (bytes.length < 5) {
      throw StateError('ملف PDF فارغ أو تالف.');
    }
    final head = String.fromCharCodes(bytes.take(5));
    if (!head.startsWith('%PDF')) {
      throw StateError('السيرفر لم يُرجع ملف PDF صالحاً.');
    }

    final pages = <Uint8List>[];
    await for (final page in Printing.raster(bytes, dpi: 145)) {
      final png = await page.toPng();
      pages.add(Uint8List.fromList(png));
    }
    if (pages.isEmpty) {
      throw StateError('تعذر عرض صفحات PDF.');
    }
    return pages;
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFFE8EEF5),
      appBar: AppBar(
        title: Text(widget.title),
        actions: [
          IconButton(
            tooltip: 'مشاركة PDF',
            onPressed: () => Printing.sharePdf(
              bytes: widget.bytes,
              filename: _fileName,
            ),
            icon: const Icon(Icons.share_outlined),
          ),
        ],
      ),
      body: FutureBuilder<List<Uint8List>>(
        future: _pagesFuture,
        builder: (context, snap) {
          if (snap.connectionState != ConnectionState.done) {
            return const Center(
              child: CircularProgressIndicator(color: AppTheme.primary),
            );
          }
          if (snap.hasError) {
            return Center(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    const Icon(Icons.picture_as_pdf_outlined,
                        size: 42, color: AppTheme.danger),
                    const SizedBox(height: 12),
                    Text(
                      snap.error.toString().replaceFirst('Bad state: ', ''),
                      textAlign: TextAlign.center,
                      style: const TextStyle(
                        fontSize: 14,
                        fontWeight: FontWeight.w700,
                        color: AppTheme.textSoft,
                      ),
                    ),
                    const SizedBox(height: 16),
                    FilledButton.icon(
                      onPressed: () => Printing.sharePdf(
                        bytes: widget.bytes,
                        filename: _fileName,
                      ),
                      icon: const Icon(Icons.share_outlined),
                      label: const Text('مشاركة الملف'),
                    ),
                  ],
                ),
              ),
            );
          }

          final pages = snap.data ?? const <Uint8List>[];
          return ListView.separated(
            padding: const EdgeInsets.fromLTRB(12, 12, 12, 24),
            itemCount: pages.length,
            separatorBuilder: (_, __) => const SizedBox(height: 12),
            itemBuilder: (_, i) {
              return DecoratedBox(
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(8),
                  boxShadow: const [
                    BoxShadow(
                      color: Color(0x22000000),
                      blurRadius: 8,
                      offset: Offset(0, 2),
                    ),
                  ],
                ),
                child: ClipRRect(
                  borderRadius: BorderRadius.circular(8),
                  child: InteractiveViewer(
                    minScale: 0.8,
                    maxScale: 3.5,
                    child: Image.memory(
                      pages[i],
                      fit: BoxFit.fitWidth,
                      filterQuality: FilterQuality.medium,
                    ),
                  ),
                ),
              );
            },
          );
        },
      ),
    );
  }
}
