import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:printing/printing.dart';

import '../core/theme.dart';
import '../services/bluetooth_printer_settings.dart';
import '../services/invoice_bluetooth_receipt.dart';
import '../services/invoice_print_helper.dart';

/// معاينة فاتورة بحجم ورق طابعة Bluetooth (58/80 مم) مع إمكانية الطباعة.
class InvoiceThermalPreviewScreen extends StatefulWidget {
  const InvoiceThermalPreviewScreen({
    super.key,
    required this.invoice,
  });

  final Map<String, dynamic> invoice;

  @override
  State<InvoiceThermalPreviewScreen> createState() =>
      _InvoiceThermalPreviewScreenState();
}

class _InvoiceThermalPreviewScreenState
    extends State<InvoiceThermalPreviewScreen> {
  late Future<_PreviewData> _future;
  bool _printBusy = false;

  @override
  void initState() {
    super.initState();
    _future = _build();
  }

  Future<_PreviewData> _build() async {
    final cfg = await BluetoothPrinterSettings.load();
    final paperMm = cfg.paperMm == 58 ? 58 : 80;
    final bytes = await InvoiceBluetoothReceipt.buildThermalPdf(
      widget.invoice,
      paperMm: paperMm,
    );
    final pages = <Uint8List>[];
    await for (final page in Printing.raster(bytes, dpi: 180)) {
      pages.add(Uint8List.fromList(await page.toPng()));
    }
    if (pages.isEmpty) {
      throw StateError('تعذر تجهيز معاينة الإيصال.');
    }
    return _PreviewData(
        pages: pages, paperMm: paperMm, configured: cfg.isConfigured);
  }

  Future<void> _print() async {
    if (_printBusy) return;
    setState(() => _printBusy = true);
    try {
      await InvoicePrintHelper.printBluetooth(
        context,
        invoice: widget.invoice,
      );
    } finally {
      if (mounted) setState(() => _printBusy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFFE8EEF5),
      appBar: AppBar(
        title: const Text('عرض الفاتورة'),
      ),
      body: FutureBuilder<_PreviewData>(
        future: _future,
        builder: (context, snap) {
          if (snap.connectionState != ConnectionState.done) {
            return const Center(child: CircularProgressIndicator());
          }
          if (snap.hasError || !snap.hasData) {
            return Center(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      snap.error?.toString() ?? 'تعذر عرض الفاتورة.',
                      textAlign: TextAlign.center,
                      style: const TextStyle(color: AppTheme.danger),
                    ),
                    const SizedBox(height: 12),
                    FilledButton(
                      onPressed: () => setState(() => _future = _build()),
                      child: const Text('إعادة المحاولة'),
                    ),
                  ],
                ),
              ),
            );
          }
          final data = snap.data!;
          return Column(
            children: [
              Padding(
                padding: const EdgeInsets.fromLTRB(14, 10, 14, 0),
                child: Row(
                  children: [
                    _PaperChip(paperMm: data.paperMm),
                    const Spacer(),
                    if (!data.configured)
                      const Text(
                        'لم تُضبط طابعة بعد',
                        style: TextStyle(
                          fontSize: 12,
                          color: AppTheme.warn,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                  ],
                ),
              ),
              Expanded(
                child: ListView.builder(
                  padding: const EdgeInsets.fromLTRB(24, 14, 24, 20),
                  itemCount: data.pages.length,
                  itemBuilder: (_, i) {
                    return Center(
                      child: Container(
                        constraints: BoxConstraints(
                          maxWidth: data.paperMm == 80 ? 340 : 260,
                        ),
                        margin: const EdgeInsets.only(bottom: 14),
                        decoration: BoxDecoration(
                          color: Colors.white,
                          borderRadius: BorderRadius.circular(8),
                          boxShadow: [
                            BoxShadow(
                              color: Colors.black.withValues(alpha: 0.08),
                              blurRadius: 12,
                              offset: const Offset(0, 4),
                            ),
                          ],
                        ),
                        child: ClipRRect(
                          borderRadius: BorderRadius.circular(8),
                          child: Image.memory(
                            data.pages[i],
                            fit: BoxFit.fitWidth,
                            filterQuality: FilterQuality.medium,
                          ),
                        ),
                      ),
                    );
                  },
                ),
              ),
            ],
          );
        },
      ),
      bottomNavigationBar: SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(14, 8, 14, 12),
          child: FilledButton.icon(
            onPressed: _printBusy ? null : _print,
            icon: _printBusy
                ? const SizedBox(
                    width: 18,
                    height: 18,
                    child: CircularProgressIndicator(
                      strokeWidth: 2,
                      color: Colors.white,
                    ),
                  )
                : const Icon(Icons.print_outlined, size: 20),
            label: const Text('طباعة'),
          ),
        ),
      ),
    );
  }
}

class _PaperChip extends StatelessWidget {
  const _PaperChip({required this.paperMm});

  final int paperMm;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        color: AppTheme.primary.withValues(alpha: 0.10),
        borderRadius: BorderRadius.circular(20),
      ),
      child: Text(
        'ورق $paperMm مم',
        style: const TextStyle(
          fontSize: 12,
          fontWeight: FontWeight.w800,
          color: AppTheme.primary,
        ),
      ),
    );
  }
}

class _PreviewData {
  _PreviewData({
    required this.pages,
    required this.paperMm,
    required this.configured,
  });

  final List<Uint8List> pages;
  final int paperMm;
  final bool configured;
}
