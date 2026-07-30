import 'package:flutter/material.dart';
import 'package:flutter_foreground_task/flutter_foreground_task.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../services/location_tracking_service.dart';
import '../../widgets/async_view.dart';
import '../../widgets/ui_kit.dart';

/// وصف شاشة داخل التطبيق: المسار + الأيقونة + اللون + القسم.
class TileSpec {
  const TileSpec(this.route, this.icon, this.color, this.group);

  final String route;
  final IconData icon;
  final Color color;
  final String group;
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
    AppTheme.primary,
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
    AppTheme.rose,
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
    AppTheme.success,
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
    AppTheme.amber,
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
    AppTheme.teal,
    'عهدة المندوب',
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
    AppTheme.danger,
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

  @override
  Widget build(BuildContext context) {
    final s = context.watch<SessionController>();
    return Scaffold(
      body: Column(
        children: [
          _Header(
            company: _company.isEmpty ? 'النماء' : _company,
            user: s.userName ?? '',
            tracking: _tracking,
            trackingOk: _trackingOk,
            trackingLabel: _trackingLabel,
            onRefresh: _load,
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
                    : ListView(
                        padding: const EdgeInsets.fromLTRB(14, 6, 14, 24),
                        children: [
                          for (final g in _groupOrder)
                            if (_group(g).isNotEmpty) ...[
                              SectionTitle(g),
                              _TileGrid(tiles: _group(g)),
                            ],
                        ],
                      ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _Header extends StatelessWidget {
  const _Header({
    required this.company,
    required this.user,
    required this.tracking,
    required this.trackingOk,
    required this.trackingLabel,
    required this.onRefresh,
  });

  final String company;
  final String user;
  final bool tracking;
  final bool trackingOk;
  final String trackingLabel;
  final VoidCallback onRefresh;

  @override
  Widget build(BuildContext context) {
    return GradientHeader(
      padding: const EdgeInsets.fromLTRB(16, 10, 16, 18),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 42,
                height: 42,
                decoration: BoxDecoration(
                  color: Colors.white.withValues(alpha: 0.18),
                  borderRadius: BorderRadius.circular(13),
                ),
                child: const Icon(
                  Icons.storefront_rounded,
                  color: Colors.white,
                  size: 22,
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      company,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 16,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      user.isEmpty ? 'أهلاً بك' : 'أهلاً، $user',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        color: Colors.white.withValues(alpha: 0.85),
                        fontSize: 12.5,
                      ),
                    ),
                  ],
                ),
              ),
              IconButton(
                tooltip: 'تحديث',
                onPressed: onRefresh,
                icon: const Icon(Icons.refresh_rounded, color: Colors.white),
              ),
            ],
          ),
          const SizedBox(height: 14),
          // عرض حالة فقط — التعديل من الإعدادات بعد كلمة مرور المدير.
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
            decoration: BoxDecoration(
              color: Colors.white.withValues(alpha: 0.15),
              borderRadius: BorderRadius.circular(14),
            ),
            child: Row(
              children: [
                Container(
                  width: 9,
                  height: 9,
                  decoration: BoxDecoration(
                    color: !tracking
                        ? const Color(0xFFFFC46B)
                        : (trackingOk
                            ? const Color(0xFF4BE38A)
                            : const Color(0xFFFF8A65)),
                    shape: BoxShape.circle,
                  ),
                ),
                const SizedBox(width: 9),
                Expanded(
                  child: Text(
                    trackingLabel,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 12.5,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ),
                Icon(
                  Icons.lock_outline_rounded,
                  color: Colors.white.withValues(alpha: 0.55),
                  size: 18,
                ),
              ],
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
    return GridView.builder(
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: 3,
        mainAxisSpacing: 10,
        crossAxisSpacing: 10,
        childAspectRatio: 0.98,
      ),
      itemCount: tiles.length,
      itemBuilder: (_, i) {
        final t = tiles[i];
        return _TileCard(
          label: t.label,
          icon: t.spec.icon,
          color: t.spec.color,
          onTap: () => context.push(t.spec.route),
        );
      },
    );
  }
}

class _TileCard extends StatelessWidget {
  const _TileCard({
    required this.label,
    required this.icon,
    required this.color,
    required this.onTap,
  });

  final String label;
  final IconData icon;
  final Color color;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: AppTheme.surface,
      borderRadius: BorderRadius.circular(16),
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: onTap,
        child: Container(
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(16),
            border: Border.all(color: AppTheme.border),
          ),
          padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 10),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              MiniIcon(icon, color: color, size: 40, iconSize: 20, radius: 13),
              const SizedBox(height: 9),
              Text(
                label,
                textAlign: TextAlign.center,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(
                  fontSize: 11.5,
                  height: 1.3,
                  fontWeight: FontWeight.w700,
                  color: AppTheme.textMain,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
