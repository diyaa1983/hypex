import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/api_client.dart';
import '../widgets/async_view.dart';
import 'bluetooth_print_service.dart';
import 'bluetooth_printer_settings.dart';

/// طباعة / PDF موحدة لكل شاشات الأندرويد عبر الطابعة المحفوظة.
class DocumentPrintHelper {
  DocumentPrintHelper._();

  /// تنزيل PDF من مسار API ثم طباعته على Bluetooth (أو حوار النظام).
  static Future<void> printFromApi(
    BuildContext context, {
    required String apiPath,
    Map<String, dynamic>? query,
    required String jobName,
    bool preferSystemDialog = false,
  }) async {
    showSnack(context, 'جاري تجهيز الطباعة...');
    final api = context.read<ApiClient>();
    final cfg = await BluetoothPrinterSettings.load();
    final expectBt = !preferSystemDialog && cfg.isConfigured;
    try {
      final bytes = await api.downloadBytes(apiPath, query: query);
      if (!context.mounted) return;
      final err = await BluetoothPrintService.printPdfBytes(
        Uint8List.fromList(bytes),
        jobName: jobName,
        preferSystemDialog: preferSystemDialog,
      );
      if (!context.mounted) return;
      if (err != null) {
        showSnack(context, err, error: true);
      } else if (expectBt) {
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
}
