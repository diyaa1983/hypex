import 'package:flutter/material.dart';
import 'package:flutter_foreground_task/flutter_foreground_task.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../offline/offline_controller.dart';
import '../../services/location_tracking_service.dart';
import '../../services/print_brand.dart';
import '../../widgets/async_view.dart';
import '../../widgets/mobile_scaffold.dart';
import '../../widgets/server_status_dot.dart';

/// وصف شاشة داخل التطبيق: المسار + الأيقونة + اللون + القسم.
class TileSpec {
  const TileSpec(this.route, this.icon, this.color, this.group, {this.asset});

  final String route;
  final IconData icon;
  final Color color;
  final String group;

  /// صورة توضيحية بديلة للأيقونة (assets/tiles/xxx.png) إن توفّرت.
  final String? asset;
}

/// خريطة موحّدة لكل شاشات النظام (تُستخدم في الرئيسية والاختصارات).
const Map<String, TileSpec> kTileSpecs = {
  'm_sales_invoices': TileSpec(
    '/invoices/new',
    Icons.note_add_rounded,
    AppTheme.primary,
    'المبيعات',
  ),
  'm_sales_invoice_list': TileSpec(
    '/invoices',
    Icons.receipt_long_rounded,
    AppTheme.primarySoft,
    'المبيعات',
  ),
  'm_customer_add': TileSpec(
    '/customers/new',
    Icons.person_add_alt_1_rounded,
    AppTheme.teal,
    'المبيعات',
    asset: 'assets/tiles/m_customer_add.png',
  ),
  'm_customer_list': TileSpec(
    '/customers',
    Icons.people,
    AppTheme.primary,
    'المبيعات',
    asset: 'assets/tiles/m_customer_list.png',
  ),
  'm_customer_orders_pending': TileSpec(
    '/customer-orders/pending',
    Icons.outbox,
    AppTheme.amber,
    'المبيعات',
    asset: 'assets/tiles/m_customer_orders_pending.png',
  ),
  'm_customer_orders_sent': TileSpec(
    '/customer-orders/sent',
    Icons.mark_email_read,
    AppTheme.success,
    'المبيعات',
    asset: 'assets/tiles/m_customer_orders_sent.png',
  ),
  'm_customer_orders_query': TileSpec(
    '/customer-orders/query',
    Icons.date_range,
    AppTheme.violet,
    'المبيعات',
    asset: 'assets/tiles/m_customer_orders_query.png',
  ),
  'm_customer_order_returns': TileSpec(
    '/customer-order-returns/pending',
    Icons.assignment_return_rounded,
    AppTheme.rose,
    'المبيعات',
    asset: 'assets/tiles/m_customer_order_returns.png',
  ),
  'm_customer_order_returns_pending': TileSpec(
    '/customer-order-returns/pending',
    Icons.assignment_return_rounded,
    AppTheme.amber,
    'المبيعات',
    asset: 'assets/tiles/m_customer_order_returns.png',
  ),
  'm_customer_order_returns_sent': TileSpec(
    '/customer-order-returns/sent',
    Icons.assignment_turned_in_rounded,
    AppTheme.success,
    'المبيعات',
    asset: 'assets/tiles/m_customer_order_returns.png',
  ),
  'm_rep_route_today': TileSpec(
    '/rep/route-today',
    Icons.route_rounded,
    AppTheme.amber,
    'المبيعات',
  ),
  'm_rep_visits': TileSpec(
    '/rep/visits',
    Icons.login_rounded,
    AppTheme.violet,
    'المبيعات',
    asset: 'assets/tiles/m_rep_visits.png',
  ),
  'm_rep_visit_report': TileSpec(
    '/rep/visit-report',
    Icons.assignment_rounded,
    AppTheme.primarySoft,
    'المبيعات',
    asset: 'assets/tiles/m_rep_visit_report.png',
  ),
  'm_sales_returns': TileSpec(
    '/returns/new',
    Icons.keyboard_return_rounded,
    AppTheme.rose,
    'المبيعات',
  ),
  'm_sales_returns_list': TileSpec(
    '/returns',
    Icons.assignment_returned_rounded,
    AppTheme.danger,
    'المبيعات',
  ),
  'm_receipt': TileSpec(
    '/receipts/new',
    Icons.payments_rounded,
    AppTheme.success,
    'المالية',
  ),
  'm_receipt_list': TileSpec(
    '/receipts',
    Icons.account_balance_wallet_rounded,
    AppTheme.teal,
    'المالية',
  ),
  'm_party_statement': TileSpec(
    '/statement',
    Icons.menu_book_rounded,
    AppTheme.violet,
    'المالية',
    asset: 'assets/tiles/m_party_statement.png',
  ),
  'm_sales_movement': TileSpec(
    '/reports/sales-movement',
    Icons.receipt_long_rounded,
    AppTheme.primary,
    'المبيعات',
  ),
  'm_rep_load': TileSpec(
    '/rep/load',
    Icons.local_shipping_rounded,
    AppTheme.amber,
    'عهدة المندوب',
  ),
  'm_rep_return': TileSpec(
    '/rep/return',
    Icons.undo_rounded,
    AppTheme.warn,
    'عهدة المندوب',
  ),
  'm_rep_custody_list': TileSpec(
    '/rep/custody',
    Icons.fact_check_rounded,
    AppTheme.teal,
    'عهدة المندوب',
  ),
  'm_rep_stock': TileSpec(
    '/rep/stock',
    Icons.inventory_2_rounded,
    AppTheme.primary,
    'رصيد المستودع',
  ),
  'm_sales_invoice_gps': TileSpec(
    '/gps/invoices',
    Icons.pin_drop_rounded,
    AppTheme.danger,
    'المواقع',
  ),
  'm_user_gps_locations': TileSpec(
    '/gps/users',
    Icons.travel_explore_rounded,
    AppTheme.violet,
    'المواقع',
  ),
  'm_user_gps_tracker': TileSpec(
    '/gps/tracker',
    Icons.radar_rounded,
    AppTheme.teal,
    'المواقع',
  ),
};

const List<String> _groupOrder = [
  'المبيعات',
  'المالية',
  'عهدة المندوب',
  'رصيد المستودع',
  'المواقع',
];

class _Tile {
  _Tile(this.code, this.label, this.spec);
  final String code;
  final String label;
  final TileSpec spec;
}

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  bool _loading = true;
  String? _error;
  String _company = '';
  List<_Tile> _tiles = [];
  bool _tracking = false;
  String _trackingLabel = 'تتبّع الموقع متوقف';
  bool _trackingOk = false;

  @override
  void initState() {
    super.initState();
    _load();
    _refreshTracking();
    FlutterForegroundTask.addTaskDataCallback(_onTaskData);
  }

  @override
  void dispose() {
    FlutterForegroundTask.removeTaskDataCallback(_onTaskData);
    super.dispose();
  }

  void _onTaskData(Object data) => _refreshTracking();

  Future<void> _refreshTracking() async {
    final on = await LocationTrackingService.isRunning;
    final st = await LocationTrackingService.status();
    final recent = st.lastPing != null &&
        DateTime.now().difference(st.lastPing!).inSeconds < 120;
    final okText = st.lastStatus.contains('تم إرسال') ||
        st.lastStatus.contains('تم تأكيد');
    final label = !on
        ? 'تتبّع الموقع متوقف'
        : (recent && okText)
            ? 'تتبّع الموقع يعمل — آخر إرسال ${_fmtHm(st.lastPing)}'
            : (st.lastStatus.isNotEmpty
                ? 'التتبّع يعمل لكن: ${st.lastStatus}'
                : 'تتبّع الموقع يعمل — بانتظار أول إرسال');
    if (!mounted) return;
    setState(() {
      _tracking = on;
      _trackingOk = on && recent && okText;
      _trackingLabel = label;
    });
  }

  String _fmtHm(DateTime? t) {
    if (t == null) return '';
    final h = t.hour.toString().padLeft(2, '0');
    final m = t.minute.toString().padLeft(2, '0');
    return '$h:$m';
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    final api = context.read<ApiClient>();
    try {
      final res = await api.getJson(AppConfig.homePath);
      final tiles = <_Tile>[];
      for (final t in (res['tiles'] as List? ?? []).whereType<Map>()) {
        final code = t['code'].toString();
        final label = t['label'].toString();
        // شاشة واحدة في الصلاحيات → بلاطتان: غير مرسلة / مرسلة
        if (code == 'm_customer_order_returns') {
          tiles.add(_Tile(
            'm_customer_order_returns_pending',
            'مرتجعات غير مرسلة',
            kTileSpecs['m_customer_order_returns_pending']!,
          ));
          tiles.add(_Tile(
            'm_customer_order_returns_sent',
            'مرتجعات مرسلة',
            kTileSpecs['m_customer_order_returns_sent']!,
          ));
          continue;
        }
        final spec = kTileSpecs[code];
        if (spec == null) continue;
        tiles.add(_Tile(code, label, spec));
      }
      await PrintBrand.remember(
        (res['company_name'] ?? '').toString(),
        (res['logo_url'] ?? '').toString(),
        serverBase: api.base,
      );
      if (!mounted) return;
      setState(() {
        _company = (res['company_name'] ?? '').toString();
        _tiles = tiles;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (e.isUnauthorized && mounted) {
        await context.read<SessionController>().logout();
        return;
      }
      setState(() {
        _error = e.message;
        _loading = false;
      });
    }
  }

  List<_Tile> _group(String name) =>
      _tiles.where((t) => t.spec.group == name).toList();

  /// شبكة واحدة بلا عناوين أقسام، مرتّبة حسب ترتيب الأقسام المنطقي.
  List<_Tile> _orderedTiles() {
    final out = <_Tile>[
      _Tile(
        'm_offline_sync',
        'تحديث البيانات',
        const TileSpec(
          '/offline/sync',
          Icons.cloud_sync_rounded,
          AppTheme.teal,
          'النظام',
        ),
      ),
    ];
    for (final g in _groupOrder) {
      out.addAll(_group(g));
    }
    for (final t in _tiles) {
      if (!out.contains(t)) out.add(t);
    }
    return out;
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<SessionController>();
    return Scaffold(
      backgroundColor: Colors.white,
      body: SafeArea(
        bottom: false,
        child: Column(
          children: [
            _TopBar(
              company: _company.isEmpty ? 'Hypex' : _company,
              version: AppConfig.appVersion,
              onRefresh: _load,
              onLogout: () => MobileScaffold.confirmLogout(context),
            ),
            _UserStrip(
              user: s.userName ?? '',
              tracking: _tracking,
              trackingOk: _trackingOk,
              trackingLabel: _trackingLabel,
            ),
            const _OfflineHomeBanner(),
            Expanded(
              child: RefreshIndicator(
                onRefresh: () async {
                  await _load();
                  await _refreshTracking();
                },
                child: AsyncView(
                  loading: _loading,
                  error: _error,
                  onRetry: _load,
                  child: _tiles.isEmpty
                      ? ListView(
                          children: const [
                            SizedBox(height: 80),
                            EmptyState(
                              message: 'لا توجد شاشات متاحة لحسابك.',
                              icon: Icons.lock_outline_rounded,
                            ),
                          ],
                        )
                      : _TileGrid(tiles: _orderedTiles()),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// شريط أزرق أعلى الشاشة: اسم الشركة والإصدار مع تحديث وخروج.
class _TopBar extends StatelessWidget {
  const _TopBar({
    required this.company,
    required this.version,
    required this.onRefresh,
    required this.onLogout,
  });

  final String company;
  final String version;
  final VoidCallback onRefresh;
  final VoidCallback onLogout;

  @override
  Widget build(BuildContext context) {
    return Container(
      color: AppTheme.primary,
      padding: EdgeInsets.fromLTRB(
        10,
        MediaQuery.sizeOf(context).shortestSide >= 600 ? 4 : 6,
        4,
        MediaQuery.sizeOf(context).shortestSide >= 600 ? 4 : 6,
      ),
      child: Row(
        children: [
          Container(
            width: 30,
            height: 30,
            padding: const EdgeInsets.all(3),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(8),
            ),
            child: Image.asset(
              'assets/branding/logo.png',
              fit: BoxFit.contain,
            ),
          ),
          const SizedBox(width: 9),
          Expanded(
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.center,
              children: [
                Flexible(
                  child: Text(
                    company,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 14.5,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                ),
                const SizedBox(width: 6),
                Text(
                  'v $version',
                  style: TextStyle(
                    color: Colors.white.withValues(alpha: 0.75),
                    fontSize: 11,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ],
            ),
          ),
          IconButton(
            tooltip: 'تحديث',
            visualDensity: VisualDensity.compact,
            onPressed: onRefresh,
            icon: const Icon(Icons.refresh_rounded, color: Colors.white, size: 21),
          ),
          const ServerStatusDot(),
          TextButton.icon(
            onPressed: onLogout,
            icon: const Icon(Icons.logout_rounded, color: Colors.white, size: 26),
            label: const Text(
              'خروج',
              style: TextStyle(
                color: Colors.white,
                fontSize: 15,
                fontWeight: FontWeight.w800,
              ),
            ),
            style: TextButton.styleFrom(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
              minimumSize: const Size(64, 44),
            ),
          ),
        ],
      ),
    );
  }
}

/// شريط المستخدم: الاسم مع مؤشّر تتبّع الموقع وتاريخ اليوم.
class _UserStrip extends StatelessWidget {
  const _UserStrip({
    required this.user,
    required this.tracking,
    required this.trackingOk,
    required this.trackingLabel,
  });

  final String user;
  final bool tracking;
  final bool trackingOk;
  final String trackingLabel;

  @override
  Widget build(BuildContext context) {
    final now = DateTime.now();
    final date =
        '${now.day.toString().padLeft(2, '0')}/${now.month.toString().padLeft(2, '0')}/${now.year}';
    final dot = !tracking
        ? const Color(0xFFFFC46B)
        : (trackingOk ? const Color(0xFF4BE38A) : const Color(0xFFFF8A65));

    return Container(
      color: AppTheme.primaryDark,
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
      child: Row(
        children: [
          Expanded(
            child: Text(
              user.isEmpty ? 'مستخدم' : user,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(
                color: Colors.white,
                fontSize: 12.5,
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
          Tooltip(
            message: trackingLabel,
            child: Container(
              width: 9,
              height: 9,
              decoration: BoxDecoration(color: dot, shape: BoxShape.circle),
            ),
          ),
          const SizedBox(width: 10),
          Text(
            date,
            style: TextStyle(
              color: Colors.white.withValues(alpha: 0.85),
              fontSize: 11.5,
              fontWeight: FontWeight.w600,
            ),
          ),
        ],
      ),
    );
  }
}

class _TileGrid extends StatelessWidget {
  const _TileGrid({required this.tiles});

  final List<_Tile> tiles;

  @override
  Widget build(BuildContext context) {
    final tablet = MediaQuery.sizeOf(context).shortestSide >= 600;
    return LayoutBuilder(
      builder: (context, box) {
        final n = tiles.isEmpty ? 1 : tiles.length;
        final cols = tablet
            ? (box.maxWidth / 148).floor().clamp(5, 8)
            : (box.maxWidth / 125).floor().clamp(3, 6);
        final rows = (n / cols).ceil().clamp(1, 20);
        final padH = tablet ? 12.0 : 8.0;
        final padV = tablet ? 8.0 : 10.0;
        final availW = (box.maxWidth - padH * 2).clamp(1.0, 4000.0);
        final availH = (box.maxHeight - padV * 2).clamp(1.0, 4000.0);
        final cellW = availW / cols;
        final cellH = availH / rows;
        final minCellH = tablet ? 88.0 : 96.0;
        final fit = tablet && cellH >= minCellH;
        final aspect = fit ? (cellW / cellH) : 0.9;
        return GridView.builder(
          physics: fit
              ? const NeverScrollableScrollPhysics()
              : const AlwaysScrollableScrollPhysics(),
          padding: EdgeInsets.fromLTRB(padH, padV, padH, padV),
          gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
            crossAxisCount: cols,
            mainAxisSpacing: tablet ? 4 : 8,
            crossAxisSpacing: tablet ? 4 : 4,
            childAspectRatio: aspect,
          ),
          itemCount: tiles.length,
          itemBuilder: (_, i) {
            final t = tiles[i];
            return FittedBox(
              fit: BoxFit.scaleDown,
              child: _TileButton(
                label: t.label,
                icon: t.spec.icon,
                color: t.spec.color,
                asset: t.spec.asset,
                compact: tablet,
                onTap: () => context.push(t.spec.route),
              ),
            );
          },
        );
      },
    );
  }
}

/// أيقونة ملوّنة كبيرة مع عنوان تحتها — بلا إطار ولا خلفية.
class _TileButton extends StatelessWidget {
  const _TileButton({
    required this.label,
    required this.icon,
    required this.color,
    required this.onTap,
    this.asset,
    this.compact = false,
  });

  final String label;
  final IconData icon;
  final Color color;
  final VoidCallback onTap;
  final String? asset;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final art = asset;
    // اللمس على الأيقونة+النص فقط — لا يفتح عند الضغط في فراغ الخلية.
    return Align(
      alignment: Alignment.topCenter,
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          borderRadius: BorderRadius.circular(12),
          onTap: onTap,
          child: ConstrainedBox(
            constraints: BoxConstraints(maxWidth: compact ? 128 : 112),
            child: Padding(
              padding: EdgeInsets.symmetric(
                horizontal: 2,
                vertical: compact ? 4 : 6,
              ),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  SizedBox(
                    height: compact ? 58 : 68,
                    width: compact ? 58 : 68,
                    child: art != null
                        ? Image.asset(art, fit: BoxFit.contain)
                        : ShaderMask(
                            shaderCallback: (rect) => LinearGradient(
                              begin: Alignment.topCenter,
                              end: Alignment.bottomCenter,
                              colors: [
                                Color.lerp(color, Colors.white, 0.35)!,
                                color,
                                Color.lerp(color, Colors.black, 0.28)!,
                              ],
                              stops: const [0, 0.5, 1],
                            ).createShader(rect),
                            child: Icon(
                              icon,
                              size: compact ? 50 : 60,
                              color: Colors.white,
                              shadows: const [
                                Shadow(
                                  color: Color(0x33101828),
                                  blurRadius: 3,
                                  offset: Offset(0, 2),
                                ),
                              ],
                            ),
                          ),
                  ),
                  const SizedBox(height: 6),
                  Text(
                    label,
                    textAlign: TextAlign.center,
                    maxLines: 3,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                      fontSize: compact ? 12 : 12.5,
                      height: 1.15,
                      fontWeight: FontWeight.w700,
                      color: AppTheme.textMain,
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _OfflineHomeBanner extends StatelessWidget {
  const _OfflineHomeBanner();

  @override
  Widget build(BuildContext context) {
    final off = context.watch<OfflineController>();
    final pending = off.info.pendingOutbox;
    if (off.online && off.catalogReady && pending < 1) {
      return const SizedBox.shrink();
    }
    final Color bg;
    final Color fg;
    final String text;
    if (!off.online && off.catalogReady) {
      bg = const Color(0xFFFFF7E6);
      fg = const Color(0xFF9A6700);
      text = pending > 0
          ? 'Offline — $pending عملية بانتظار الترحيل عند الاتصال'
          : 'Offline — يعمل من البيانات المحلية';
    } else if (!off.online) {
      bg = const Color(0xFFFEECEC);
      fg = AppTheme.danger;
      text = 'Offline بدون بيانات — افتح «تحديث البيانات» عند الاتصال';
    } else if (!off.catalogReady) {
      bg = const Color(0xFFEEF3FA);
      fg = AppTheme.primary;
      text = 'فعّل Offline من بلاطة «تحديث البيانات»';
    } else {
      bg = const Color(0xFFFFF7E6);
      fg = const Color(0xFF9A6700);
      text = '$pending عملية معلّقة — ستُرحَّل تلقائياً';
    }
    return Material(
      color: bg,
      child: InkWell(
        onTap: () => context.push('/offline/sync'),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
          child: Row(
            children: [
              Icon(
                off.online ? Icons.cloud_sync_rounded : Icons.cloud_off_rounded,
                color: fg,
                size: 18,
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  text,
                  style: TextStyle(
                    color: fg,
                    fontWeight: FontWeight.w600,
                    fontSize: 12.5,
                  ),
                ),
              ),
              Icon(Icons.chevron_left, color: fg, size: 18),
            ],
          ),
        ),
      ),
    );
  }
}

