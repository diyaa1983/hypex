import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../../core/theme.dart';
import '../../offline/offline_controller.dart';
import '../../offline/offline_store.dart';
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
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      if (!mounted) return;
      final off = context.read<OfflineController>();
      await off.refreshInfo();
      if (!mounted) return;
      await off.flushAndAutoPost();
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
    final n = await off.flushAndAutoPost(silent: false);
    if (!mounted) return;
    showSnack(
      context,
      off.statusMessage ??
          (n > 0 ? 'تم ترحيل $n عملية.' : 'لا توجد عمليات معلّقة.'),
      error: off.lastError != null && n == 0 && off.info.pendingOutbox > 0,
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
    final size = MediaQuery.sizeOf(context);
    final pad = MediaQuery.paddingOf(context);
    final tablet = size.shortestSide >= 600;
    final wide = size.width >= 900;
    final compact = size.height < 780 && !tablet;
    final gap = tablet ? 10.0 : 12.0;
    final stats = _statRows(off, info);

    return Scaffold(
      appBar: AppBar(
        title: const Text('تحديث البيانات'),
        backgroundColor: AppTheme.primary,
        foregroundColor: Colors.white,
      ),
      body: SafeArea(
        child: Padding(
          padding: EdgeInsets.fromLTRB(
            tablet ? 20 : 14,
            tablet ? 12 : 10,
            tablet ? 20 : 14,
            10 + pad.bottom.clamp(0, 8),
          ),
          child: Column(
            children: [
              _StatusBanner(
                online: off.serverConnected || off.online,
                catalogReady: off.catalogReady,
                compact: tablet || compact,
              ),
              SizedBox(height: gap),
              Expanded(
                child: LayoutBuilder(
                  builder: (context, constraints) {
                    final body = wide
                        ? Row(
                            crossAxisAlignment: CrossAxisAlignment.stretch,
                            children: [
                              Expanded(flex: 5, child: _helpCard(compact: true)),
                              SizedBox(width: gap),
                              Expanded(
                                flex: 7,
                                child: _statsCard(
                                  stats,
                                  compact: true,
                                  columns: 2,
                                ),
                              ),
                            ],
                          )
                        : Column(
                            children: [
                              _helpCard(compact: tablet || compact),
                              SizedBox(height: gap),
                              Expanded(
                                child: _statsCard(
                                  stats,
                                  compact: tablet || compact,
                                  columns: tablet ? 2 : 1,
                                ),
                              ),
                            ],
                          );
                    if (constraints.maxHeight.isFinite &&
                        constraints.maxHeight > 0) {
                      return body;
                    }
                    return SingleChildScrollView(child: body);
                  },
                ),
              ),
              if (info.hasData && info.noOrderReasons < 1)
                Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: Text(
                    'تنبيه: أسباب زيارة العميل غير محمّلة. انشر API المحدّث ثم اضغط تحديث البيانات مرة أخرى.',
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      color: AppTheme.danger.withValues(alpha: 0.9),
                      fontWeight: FontWeight.w600,
                      fontSize: tablet ? 12 : 13,
                    ),
                  ),
                ),
              if (off.busy) ...[
                const SizedBox(height: 8),
                LinearProgressIndicator(
                  value: off.phase == OfflinePhase.pulling &&
                          off.pullProgress > 0
                      ? off.pullProgress
                      : null,
                ),
                const SizedBox(height: 6),
                Text(
                  _step.isNotEmpty
                      ? _step
                      : (off.statusMessage ?? 'جاري العمل…'),
                  textAlign: TextAlign.center,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    color: Colors.black.withValues(alpha: 0.65),
                    fontSize: 13,
                  ),
                ),
              ],
              if (off.lastError != null && !off.busy)
                Padding(
                  padding: const EdgeInsets.only(top: 6),
                  child: Text(
                    off.lastError!,
                    style: const TextStyle(color: AppTheme.danger, fontSize: 13),
                    textAlign: TextAlign.center,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
              SizedBox(height: gap),
              FilledButton.icon(
                onPressed: off.busy ? null : _pull,
                icon: const Icon(Icons.cloud_download_rounded),
                label: const Text('تحديث البيانات الآن'),
                style: FilledButton.styleFrom(
                  backgroundColor: AppTheme.primary,
                  minimumSize: Size(double.infinity, tablet ? 52 : 48),
                  textStyle: const TextStyle(
                    fontWeight: FontWeight.w800,
                    fontSize: 15,
                  ),
                ),
              ),
              const SizedBox(height: 8),
              OutlinedButton.icon(
                onPressed: off.busy || info.pendingOutbox < 1 ? null : _flush,
                icon: const Icon(Icons.cloud_upload_rounded),
                label: Text(
                  info.pendingOutbox > 0
                      ? 'ترحيل المعلّق الآن (${info.pendingOutbox})'
                      : 'لا توجد عمليات معلّقة',
                ),
                style: OutlinedButton.styleFrom(
                  minimumSize: Size(double.infinity, tablet ? 46 : 44),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  List<({String k, String v, bool highlight})> _statRows(
    OfflineController off,
    OfflineSyncInfo info,
  ) {
    return [
      (k: 'آخر تحديث', v: _fmtAt(info.syncedAt), highlight: false),
      (k: 'العملاء', v: '${info.customers}', highlight: false),
      (k: 'المواد', v: '${info.items}', highlight: false),
      (k: 'المستودعات', v: '${info.warehouses}', highlight: false),
      (k: 'صفوف الرصيد', v: '${info.stockRows}', highlight: false),
      (
        k: 'أسباب عدم الطلب (زيارة)',
        v: '${info.noOrderReasons}',
        highlight: info.hasData && info.noOrderReasons < 1,
      ),
      (k: 'نصف قطر الزيارة', v: '${info.visitRadiusM} م', highlight: false),
      (k: 'أيام الجولة (كاش)', v: '${info.routeDays}', highlight: false),
      (
        k: 'صفوف تقرير الزيارات',
        v: '${info.visitReportRows}',
        highlight: false,
      ),
      (k: 'طلبات غير مرسلة (محلي)', v: '${info.ordersPending}', highlight: false),
      (k: 'طلبات مرسلة (محلي)', v: '${info.ordersSent}', highlight: false),
      (
        k: 'كشوف حساب Oracle (محلي)',
        v: '${info.oracleStatements}',
        highlight: false,
      ),
      if (info.cacheFrom != null && (info.cacheFrom ?? '').isNotEmpty)
        (
          k: 'نافذة الكاش',
          v: '${info.cacheFrom} ← ${info.cacheTo ?? ''}',
          highlight: false,
        ),
      (
        k: 'بانتظار الترحيل',
        v: '${info.pendingOutbox}',
        highlight: info.pendingOutbox > 0,
      ),
      if (off.flushScheduledAt != null)
        (
          k: 'ترحيل تلقائي مجدول',
          v: _fmtAt(off.flushScheduledAt!.toIso8601String()),
          highlight: true,
        ),
    ];
  }

  Widget _helpCard({required bool compact}) {
    return Card(
      elevation: 0,
      color: Colors.white,
      margin: EdgeInsets.zero,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(14),
        side: BorderSide(color: Colors.black.withValues(alpha: 0.06)),
      ),
      child: Padding(
        padding: EdgeInsets.all(compact ? 12 : 16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: [
            const Text(
              'كيف يعمل وضع Offline؟',
              style: TextStyle(fontWeight: FontWeight.w800, fontSize: 15),
            ),
            SizedBox(height: compact ? 6 : 8),
            Text(
              compact
                  ? 'حدّث وأنت متصل، ثم اعمل بدون إنترنت. عند عودة الشبكة تُرحَّل العمليات تلقائياً.'
                  : '1) اضغط «تحديث البيانات» وأنت متصل: يُحمَّل العملاء منذ آخر تحديث، وجولات المندوب، وكشوف حساب العملاء، مع المواد والطلبات.\n'
                      '2) بدون إنترنت يمكنك إضافة وتعديل وحذف العملاء، وحفظ الطلبات، وتسجيل دخول/خروج الزيارة.\n'
                      '3) عند عودة الاتصال تُرحَّل العمليات تلقائياً، وكذلك عند فتح شاشات العملاء والجولات وكشف الحساب.',
              style: TextStyle(
                color: Colors.black.withValues(alpha: 0.7),
                height: 1.4,
                fontSize: compact ? 13 : 14,
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _statsCard(
    List<({String k, String v, bool highlight})> stats, {
    required bool compact,
    int columns = 1,
  }) {
    final grid = columns > 1
        ? GridView.builder(
            physics: const BouncingScrollPhysics(),
            itemCount: stats.length,
            gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
              crossAxisCount: columns,
              mainAxisExtent: compact ? 36 : 40,
              crossAxisSpacing: 12,
              mainAxisSpacing: 0,
            ),
            itemBuilder: (_, i) => _kv(
              stats[i].k,
              stats[i].v,
              highlight: stats[i].highlight,
              compact: compact,
            ),
          )
        : ListView.builder(
            physics: const BouncingScrollPhysics(),
            itemCount: stats.length,
            itemBuilder: (_, i) => _kv(
              stats[i].k,
              stats[i].v,
              highlight: stats[i].highlight,
              compact: compact,
            ),
          );

    return Card(
      elevation: 0,
      margin: EdgeInsets.zero,
      clipBehavior: Clip.antiAlias,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(14),
        side: BorderSide(color: Colors.black.withValues(alpha: 0.06)),
      ),
      child: Padding(
        padding: EdgeInsets.fromLTRB(
          compact ? 12 : 16,
          compact ? 8 : 12,
          compact ? 12 : 16,
          compact ? 8 : 12,
        ),
        child: grid,
      ),
    );
  }

  Widget _kv(
    String k,
    String v, {
    bool highlight = false,
    bool compact = false,
  }) {
    return Padding(
      padding: EdgeInsets.symmetric(vertical: compact ? 3 : 5),
      child: Row(
        children: [
          Expanded(
            child: Text(
              k,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                color: Colors.black.withValues(alpha: 0.6),
                fontSize: compact ? 12.5 : 14,
              ),
            ),
          ),
          const SizedBox(width: 8),
          Flexible(
            child: Text(
              v,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              textAlign: TextAlign.left,
              style: TextStyle(
                fontWeight: FontWeight.w700,
                fontSize: compact ? 12.5 : 14,
                color: highlight ? AppTheme.amber : null,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _StatusBanner extends StatelessWidget {
  const _StatusBanner({
    required this.online,
    required this.catalogReady,
    this.compact = false,
  });
  final bool online;
  final bool catalogReady;
  final bool compact;

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
      padding: EdgeInsets.symmetric(
        horizontal: 12,
        vertical: compact ? 10 : 12,
      ),
      decoration: BoxDecoration(
        color: bg,
        borderRadius: BorderRadius.circular(12),
      ),
      child: Row(
        children: [
          Icon(icon, color: fg, size: compact ? 22 : 24),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              text,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                color: fg,
                fontWeight: FontWeight.w600,
                fontSize: compact ? 13 : 14,
              ),
            ),
          ),
        ],
      ),
    );
  }
}
