import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:printing/printing.dart';
import 'package:provider/provider.dart';

import '../core/api_client.dart';
import '../widgets/async_view.dart';
import '../widgets/pdf_a4_viewer_screen.dart';
import 'bluetooth_print_service.dart';
import 'bluetooth_printer_settings.dart';

/// طباعة Bluetooth ومعاينة PDF (A4) — مساران منفصلان.
class DocumentPrintHelper {
  DocumentPrintHelper._();

  /// طباعة مباشرة على طابعة Bluetooth المحفوظة فقط (بدون حوار النظام).
  static Future<void> printFromApi(
    BuildContext context, {
    required String apiPath,
    Map<String, dynamic>? query,
    required String jobName,
  }) async {
    final cfg = await BluetoothPrinterSettings.load();
    if (!cfg.isConfigured) {
      if (!context.mounted) return;
      showSnack(
        context,
        'اختر طابعة Bluetooth من الإعدادات أولاً.',
        error: true,
      );
      return;
    }

    if (!context.mounted) return;
    showSnack(context, 'جاري الطباعة على ${cfg.displayLabel}...');
    final api = context.read<ApiClient>();
    try {
      final bytes = await api.downloadBytes(apiPath, query: query);
      if (!context.mounted) return;
      final err = await BluetoothPrintService.printPdfBytes(
        Uint8List.fromList(bytes),
        jobName: jobName,
        bluetoothOnly: true,
      );
      if (!context.mounted) return;
      if (err != null) {
        showSnack(context, err, error: true);
      } else {
        showSnack(context, 'تم إرسال المستند لطابعة Bluetooth.');
      }
    } on ApiException catch (e) {
      if (!context.mounted) return;
      showSnack(context, e.message, error: true);
    } catch (e) {
      if (!context.mounted) return;
      showSnack(context, 'تعذر الطباعة: $e', error: true);
    }
  }

  /// تنزيل PDF بحجم A4 وعرضه داخل التطبيق (مثل طباعة الويندوز).
  static Future<void> openPdfFromApi(
    BuildContext context, {
    required String apiPath,
    Map<String, dynamic>? query,
    required String title,
    String? fileName,
  }) async {
    showSnack(context, 'جاري تجهيز PDF (A4)...');
    final api = context.read<ApiClient>();
    final nav = Navigator.of(context);
    try {
      final bytes = await api.downloadBytes(apiPath, query: query);
      if (!context.mounted) return;
      if (bytes.isEmpty) {
        showSnack(context, 'ملف PDF فارغ.', error: true);
        return;
      }
      final head = String.fromCharCodes(bytes.take(5));
      if (!head.startsWith('%PDF')) {
        showSnack(
          context,
          'تعذر إنشاء PDF على السيرفر (رد غير صالح).',
          error: true,
        );
        return;
      }
      await nav.push(
        MaterialPageRoute<void>(
          builder: (_) => PdfA4ViewerScreen(
            bytes: Uint8List.fromList(bytes),
            title: title,
            fileName: fileName ?? title,
          ),
        ),
      );
    } on ApiException catch (e) {
      if (!context.mounted) return;
      showSnack(context, e.message, error: true);
    } catch (e) {
      if (!context.mounted) return;
      showSnack(context, 'تعذر فتح PDF: $e', error: true);
    }
  }

  /// مشاركة PDF مباشرة (WhatsApp / Drive / …).
  static Future<void> sharePdfFromApi(
    BuildContext context, {
    required String apiPath,
    Map<String, dynamic>? query,
    required String fileName,
  }) async {
    showSnack(context, 'جاري تجهيز PDF...');
    final api = context.read<ApiClient>();
    try {
      final bytes = await api.downloadBytes(apiPath, query: query);
      if (!context.mounted) return;
      if (bytes.isEmpty) {
        showSnack(context, 'ملف PDF فارغ.', error: true);
        return;
      }
      final head = String.fromCharCodes(bytes.take(5));
      if (!head.startsWith('%PDF')) {
        showSnack(context, 'تعذر إنشاء PDF على السيرفر.', error: true);
        return;
      }
      final name = fileName.toLowerCase().endsWith('.pdf') ? fileName : '$fileName.pdf';
      await Printing.sharePdf(bytes: Uint8List.fromList(bytes), filename: name);
    } on ApiException catch (e) {
      if (!context.mounted) return;
      showSnack(context, e.message, error: true);
    } catch (e) {
      if (!context.mounted) return;
      showSnack(context, 'تعذر مشاركة PDF: $e', error: true);
    }
  }

  static Future<void> sharePdfBytes(
    Uint8List bytes, {
    required String fileName,
  }) async {
    final name = fileName.toLowerCase().endsWith('.pdf') ? fileName : '$fileName.pdf';
    await Printing.sharePdf(bytes: bytes, filename: name);
  }
}
