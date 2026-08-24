import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../../core/theme.dart';
import '../../offline/offline_controller.dart';
import '../../widgets/async_view.dart';

/// شاشة تحديث بيانات Offline — تحميل الكتالوج للعمل بدون إنترنت.
class DataSyncScreen extends StatefulWidget {
  const DataSyncScreen({super.key});

  @override
  State<DataSyncScreen> createState() => _DataSyncScreenState();
}

class _DataSyncScreenState extends State<DataSyncScreen> {
  String _step = '';

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      context.read<OfflineController>().refreshInfo();
    });
  }

  Future<void> _pull() async {
    final off = context.read<OfflineController>();
    final ok = await off.pullCatalog(onStep: (s) {
      if (mounted) setState(() => _step = s);
    });
    if (!mounted) return;
    showSnack(
      context,
      ok
          ? (off.statusMessage ?? 'تم تحديث البيانات.')
          : (off.lastError ?? 'تعذر التحديث.'),
      error: !ok,
    );
  }

  Future<void> _flush() async {
    final off = context.read<OfflineController>();
    final n = await off.flushOutbox(silent: false);
    if (!mounted) return;
    showSnack(
      context,
      off.statusMessage ??
          (n > 0 ? 'تم ترحيل $n عملية.' : 'لا توجد عمليات معلّقة.'),
      error: off.lastError != null && n == 0,
    );
  }

  String _fmtAt(String? iso) {
    if (iso == null || iso.isEmpty) return 'لم يتم التحديث بعد';
    try {
      final d = DateTime.parse(iso).toLocal();
      return DateFormat('yyyy-MM-dd HH:mm').format(d);
    } catch (_) {
      return iso;
    }
  }

  @override
  Widget build(BuildContext context) {
    final off = context.watch<OfflineController>();
    final info = off.info;

    return Scaffold(
      appBar: AppBar(
        title: const Text('تحديث البيانات'),
        backgroundColor: AppTheme.primary,
        foregroundColor: Colors.white,
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          _StatusBanner(online: off.online, catalogReady: off.catalogReady),
          const SizedBox(height: 14),
          Card(
            elevation: 0,
            color: Colors.white,
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(14),
              side: BorderSide(color: Colors.black.withValues(alpha: 0.06)),
            ),
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const Text(
                    'كيف يعمل وضع Offline؟',
                    style: TextStyle(fontWeight: FontWeight.w800, fontSize: 16),
                  ),
                  const SizedBox(height: 8),
                  Text(
                    '1) اضغط «تحديث البيانات» وأنت متصل بالإنترنت لتحميل العملاء والمواد والمستودعات على الجهاز.\n'
                    '2) عند انقطاع الإنترنت يبقى التطبيق يعمل من البيانات المحلية.\n'
                    '3) عند عودة الاتصال تُرحَّل العمليات المعلّقة تلقائياً.',
                    style: TextStyle(
                      color: Colors.black.withValues(alpha: 0.7),
                      height: 1.45,
                    ),
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 14),
          Card(
            elevation: 0,
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(14),
              side: BorderSide(color: Colors.black.withValues(alpha: 0.06)),
            ),
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                children: [
                  _kv('آخر تحديث', _fmtAt(info.syncedAt)),
                  _kv('العملاء', '${info.customers}'),
                  _kv('المواد', '${info.items}'),
                  _kv('المستودعات', '${info.warehouses}'),
                  _kv('صفوف الرصيد', '${info.stockRows}'),
                  _kv(
                    'أسباب عدم الطلب (زيارة)',
                    '${info.noOrderReasons}',
                    highlight: info.hasData && info.noOrderReasons < 1,
                  ),
                  _kv('نصف قطر الزيارة', '${info.visitRadiusM} م'),
                  _kv(
                    'بانتظار الترحيل',
                    '${info.pendingOutbox}',
                    highlight: info.pendingOutbox > 0,
                  ),
                ],
              ),
            ),
          ),
          if (info.hasData && info.noOrderReasons < 1)
            Padding(
              padding: const EdgeInsets.only(top: 10),
              child: Text(
                'تنبيه: أسباب زيارة العميل غير محمّلة. انشر API المحدّث ثم اضغط تحديث البيانات مرة أخرى.',
                textAlign: TextAlign.center,
                style: TextStyle(
                  color: AppTheme.danger.withValues(alpha: 0.9),
                  fontWeight: FontWeight.w600,
                ),
              ),
            ),
          if (off.busy) ...[
            const SizedBox(height: 16),
            LinearProgressIndicator(
              value: off.phase == OfflinePhase.pulling && off.pullProgress > 0
                  ? off.pullProgress
                  : null,
            ),
            const SizedBox(height: 8),
            Text(
              _step.isNotEmpty ? _step : (off.statusMessage ?? 'جاري العمل…'),
              textAlign: TextAlign.center,
              style: TextStyle(color: Colors.black.withValues(alpha: 0.65)),
            ),
          ],
          const SizedBox(height: 18),
          FilledButton.icon(
            onPressed: off.busy ? null : _pull,
            icon: const Icon(Icons.cloud_download_rounded),
            label: const Text('تحديث البيانات الآن'),
            style: FilledButton.styleFrom(
              backgroundColor: AppTheme.primary,
              padding: const EdgeInsets.symmetric(vertical: 14),
            ),
          ),
          const SizedBox(height: 10),
          OutlinedButton.icon(
            onPressed: off.busy || !off.online || info.pendingOutbox < 1
                ? null
                : _flush,
            icon: const Icon(Icons.cloud_upload_rounded),
            label: Text(
              info.pendingOutbox > 0
                  ? 'ترحيل المعلّق الآن (${info.pendingOutbox})'
                  : 'لا توجد عمليات معلّقة',
            ),
          ),
          if (off.lastError != null && !off.busy) ...[
            const SizedBox(height: 12),
            Text(
              off.lastError!,
              style: const TextStyle(color: AppTheme.danger),
              textAlign: TextAlign.center,
            ),
          ],
        ],
      ),
    );
  }

  Widget _kv(String k, String v, {bool highlight = false}) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        children: [
          Expanded(
            child: Text(
              k,
              style: TextStyle(color: Colors.black.withValues(alpha: 0.6)),
            ),
          ),
          Text(
            v,
            style: TextStyle(
              fontWeight: FontWeight.w700,
              color: highlight ? AppTheme.amber : null,
            ),
          ),
        ],
      ),
    );
  }
}

class _StatusBanner extends StatelessWidget {
  const _StatusBanner({required this.online, required this.catalogReady});
  final bool online;
  final bool catalogReady;

  @override
  Widget build(BuildContext context) {
    final Color bg;
    final Color fg;
    final String text;
    final IconData icon;
    if (!online && catalogReady) {
      bg = const Color(0xFFFFF7E6);
      fg = const Color(0xFF9A6700);
      icon = Icons.cloud_off_rounded;
      text = 'Offline — يعمل من البيانات المحمّلة على الجهاز';
    } else if (!online && !catalogReady) {
      bg = const Color(0xFFFEECEC);
      fg = AppTheme.danger;
      icon = Icons.warning_amber_rounded;
      text = 'Offline وبدون بيانات محلية — حدّث البيانات عند توفر الإنترنت';
    } else if (catalogReady) {
      bg = const Color(0xFFE8F7EE);
      fg = AppTheme.success;
      icon = Icons.cloud_done_rounded;
      text = 'متصل — البيانات المحلية جاهزة للعمل Offline';
    } else {
      bg = const Color(0xFFEEF3FA);
      fg = AppTheme.primary;
      icon = Icons.cloud_download_rounded;
      text = 'متصل — اضغط تحديث البيانات لتفعيل Offline';
    }
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: bg,
        borderRadius: BorderRadius.circular(12),
      ),
      child: Row(
        children: [
          Icon(icon, color: fg),
          const SizedBox(width: 10),
          Expanded(
            child: Text(text, style: TextStyle(color: fg, fontWeight: FontWeight.w600)),
          ),
        ],
      ),
    );
  }
}
