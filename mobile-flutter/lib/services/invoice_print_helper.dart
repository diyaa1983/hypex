import 'dart:io';

import 'package:flutter/material.dart';
import 'package:open_filex/open_filex.dart';
import 'package:path_provider/path_provider.dart';
import 'package:provider/provider.dart';

import '../core/api_client.dart';
import '../core/config.dart';
import '../widgets/async_view.dart';

/// طباعة / PDF لفاتورة المبيعات عبر تنزيل ملف السيرفر بجلسة التطبيق.
class InvoicePrintHelper {
  InvoicePrintHelper._();

  static Future<void> openPdf(
    BuildContext context, {
    required int invoiceId,
    required String invoiceNo,
  }) async {
    if (invoiceId < 1) {
      showSnack(context, 'احفظ الفاتورة أولاً قبل الطباعة.', error: true);
      return;
    }
    showSnack(context, 'جاري تجهيز PDF...');
    final api = context.read<ApiClient>();
    final dir = await getTemporaryDirectory();
    final safeNo = invoiceNo.trim().isEmpty
        ? 'invoice_$invoiceId'
        : invoiceNo.replaceAll(RegExp(r'[^\w\-]+'), '_');

    try {
      final bytes = await api.downloadBytes(
        AppConfig.salesInvoicePdfPath,
        query: {'id': invoiceId},
      );
      final file = File('${dir.path}/فاتورة_$safeNo.pdf');
      await file.writeAsBytes(bytes, flush: true);
      final result = await OpenFilex.open(file.path);
      if (!context.mounted) return;
      if (result.type != ResultType.done) {
        showSnack(
          context,
          result.message.isNotEmpty
              ? result.message
              : 'تم حفظ الملف لكن تعذر فتحه تلقائياً.',
          error: true,
        );
      }
      return;
    } on ApiException catch (e) {
      // مسار بديل: فتح معاينة HTML للطباعة من المتصفح.
      try {
        await _openHtmlPreview(
          api: api,
          invoiceId: invoiceId,
          safeNo: safeNo,
          dir: dir,
        );
        if (!context.mounted) return;
        showSnack(
          context,
          'تعذر PDF المباشر — فُتحت معاينة للطباعة من المتصفح.',
        );
      } catch (_) {
        if (!context.mounted) return;
        showSnack(context, e.message, error: true);
      }
    } catch (e) {
      if (!context.mounted) return;
      showSnack(context, 'تعذر فتح PDF: $e', error: true);
    }
  }

  static Future<void> _openHtmlPreview({
    required ApiClient api,
    required int invoiceId,
    required String safeNo,
    required Directory dir,
  }) async {
    final res = await api.getJson(
      AppConfig.salesInvoicePrintPath,
      query: {'id': invoiceId},
    );
    final html = (res['html_pdf'] as String?) ??
        (res['html'] as String?) ??
        '';
    if (html.trim().isEmpty) {
      throw ApiException('لا توجد معاينة طباعة للفاتورة.');
    }
    final file = File('${dir.path}/فاتورة_$safeNo.html');
    await file.writeAsString(html, flush: true);
    final result = await OpenFilex.open(file.path);
    if (result.type != ResultType.done) {
      throw ApiException(
        result.message.isNotEmpty
            ? result.message
            : 'تعذر فتح معاينة الطباعة.',
      );
    }
  }
}
