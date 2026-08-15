import 'package:flutter/material.dart';
import 'package:flutter_foreground_task/flutter_foreground_task.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../services/location_tracking_service.dart';
import '../../services/print_brand.dart';
import '../../widgets/async_view.dart';
import '../../widgets/mobile_scaffold.dart';

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
  ),
  'm_customer_list': TileSpec(
    '/customers',
    Icons.people,
    AppTheme.primary,
    'المبيعات',
  ),
  'm_customer_orders_pending': TileSpec(
    '/customer-orders/pending',
    Icons.outbox,
    AppTheme.amber,
    'المبيعات',
  ),
  'm_customer_orders_sent': TileSpec(
    '/customer-orders/sent',
    Icons.mark_email_read,
    AppTheme.success,
    'المبيعات',
  ),
  'm_customer_orders_query': TileSpec(
    '/customer-orders/query',
    Icons.date_range,
    AppTheme.violet,
    'المبيعات',
  ),
  'm_customer_order_returns': TileSpec(
    '/customer-order-returns',
    Icons.assignment_return_rounded,
    AppTheme.rose,
    'المبيعات',
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
  ),
  'm_rep_visit_report': TileSpec(
    '/rep/visit-report',
    Icons.assignment_rounded,
    AppTheme.primarySoft,
    'المبيعات',
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
      final tiles = (res['tiles'] as List? ?? [])
          .whereType<Map>()
          .where((t) => kTileSpecs.containsKey(t['code'].toString()))
          .map(
            (t) => _Tile(
              t['code'].toString(),
              t['label'].toString(),
              kTileSpecs[t['code'].toString()]!,
            ),
          )
          .toList();
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
    final out = <_Tile>[];
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
      padding: const EdgeInsets.fromLTRB(10, 6, 4, 6),
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
          IconButton(
            tooltip: 'خروج',
            visualDensity: VisualDensity.compact,
            onPressed: onLogout,
            icon: const Icon(Icons.logout_rounded, color: Colors.white, size: 21),
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
    return LayoutBuilder(
      builder: (context, box) {
        // عمود لكل ~100 بكسل: 4 أعمدة على الهاتف و6 على التابلت.
        final cols = (box.maxWidth / 100).floor().clamp(3, 7);
        return GridView.builder(
          padding: const EdgeInsets.fromLTRB(8, 12, 8, 24),
          gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
            crossAxisCount: cols,
            mainAxisSpacing: 10,
            crossAxisSpacing: 4,
            childAspectRatio: 0.82,
          ),
          itemCount: tiles.length,
          itemBuilder: (_, i) {
            final t = tiles[i];
            return _TileButton(
              label: t.label,
              icon: t.spec.icon,
              color: t.spec.color,
              asset: t.spec.asset,
              onTap: () => context.push(t.spec.route),
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
  });

  final String label;
  final IconData icon;
  final Color color;
  final VoidCallback onTap;
  final String? asset;

  @override
  Widget build(BuildContext context) {
    final art = asset;
    return InkWell(
      borderRadius: BorderRadius.circular(12),
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 2, vertical: 8),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.start,
          children: [
            SizedBox(
              height: 48,
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
                        size: 44,
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
            const SizedBox(height: 5),
            Expanded(
              child: Text(
                label,
                textAlign: TextAlign.center,
                maxLines: 3,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(
                  fontSize: 10.5,
                  height: 1.2,
                  fontWeight: FontWeight.w700,
                  color: AppTheme.textMain,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
