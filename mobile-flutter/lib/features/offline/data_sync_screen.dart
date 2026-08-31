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
      // ترحيل المعلّق الحقيقي فقط — بدون إنشاء طابور وهمي.
      if (off.serverConnected && off.info.flushableOutbox > 0) {
        await off.flushAndAutoPost();
      }
    });
  }

  Future<void> _pull() async {
    final off = context.read<OfflineController>();
    final ok = await off.pullCatalog(onStep: (s) {
      if (mounted) setState(() => _step = s);
    });
    if (!mounted) return;
    if (ok && off.info.flushableOutbox > 0) {
      await off.flushAndAutoPost();
    }
    if (!mounted) return;
    showSnack(
      context,
      ok
          ? (off.statusMessage ?? 'تم تحديث البيانات.')
          : (off.lastError ?? 'تعذر التحديث.'),
      error: !ok,
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
                pending: info.pendingOutbox,
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
              if (off.lastError != null &&
                  !off.busy &&
                  info.flushableOutbox > 0)
                Padding(
                  padding: const EdgeInsets.only(top: 6),
                  child: Text(
                    'تعذر ترحيل عملية: ${off.lastError!}',
                    style: const TextStyle(color: AppTheme.danger, fontSize: 13),
                    textAlign: TextAlign.center,
                    maxLines: 4,
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
              if (info.pendingOutbox > 0)
                Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: Text(
                    '${info.pendingOutbox} عملية بانتظار الترحيل — تُرحَّل تلقائياً عند عودة الاتصال بدون الحاجة لتحديث البيانات.',
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      color: Colors.black.withValues(alpha: 0.6),
                      fontSize: tablet ? 12 : 13,
                    ),
                  ),
                )
              else
                Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: Text(
                    'لا توجد عمليات معلّقة. عند قطع الاتصال يمكنك العمل من البيانات المحلية، وعند عودة الشبكة تُرحَّل التعديلات تلقائياً.',
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      color: Colors.black.withValues(alpha: 0.55),
                      fontSize: tablet ? 12 : 13,
                    ),
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
      (
        k: 'طلبات غير مرسلة (محلي)',
        v: '${info.ordersPending}',
        highlight: false,
      ),
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
        k: 'عمليات معلّقة (ترحيل)',
        v: '${info.pendingOutbox}',
        highlight: info.pendingOutbox > 0,
      ),
      if (info.errorOutbox > 0)
        (
          k: 'عمليات بحاجة لإعادة محاولة',
          v: '${info.errorOutbox}',
          highlight: true,
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
                  ? 'حدّث وأنت متصل لتحميل بيانات شاشات التابلت. عند قطع الشبكة تعمل محلياً، وعند عودتها تُرحَّل العمليات تلقائياً دون تحديث يدوي.'
                  : '1) اضغط «تحديث البيانات» وأنت متصل: تُحمَّل بيانات الشاشات (عملاء المندوب، الجولات، المواد، الطلبات، كشوف الحساب…).\n'
                      '2) عند قطع الاتصال تعمل كل الشاشات من البيانات المحلية بشكل طبيعي.\n'
                      '3) عند عودة الاتصال تُرحَّل العمليات المعلّقة تلقائياً إلى النظام — بدون الحاجة لضغط تحديث البيانات مرة أخرى.',
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
    required this.pending,
    this.compact = false,
  });
  final bool online;
  final bool catalogReady;
  final int pending;
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
      text = pending > 0
          ? 'Offline — $pending عملية ستُرحَّل تلقائياً عند الاتصال'
          : 'Offline — يعمل من البيانات المحمّلة على الجهاز';
    } else if (!online && !catalogReady) {
      bg = const Color(0xFFFEECEC);
      fg = AppTheme.danger;
      icon = Icons.warning_amber_rounded;
      text = 'Offline وبدون بيانات محلية — حدّث البيانات عند توفر الإنترنت';
    } else if (catalogReady) {
      bg = const Color(0xFFE8F7EE);
      fg = AppTheme.success;
      icon = Icons.cloud_done_rounded;
      text = pending > 0
          ? 'متصل — جاري/بانتظار ترحيل $pending عملية تلقائياً'
          : 'متصل — البيانات المحلية جاهزة للعمل Offline';
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
