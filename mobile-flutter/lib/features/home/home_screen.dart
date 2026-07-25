import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/async_view.dart';

class _Tile {
  _Tile(this.code, this.label, this.icon);
  final String code;
  final String label;
  final String icon;
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

  /// ربط كود الشاشة بمسار داخل التطبيق.
  static const Map<String, String> _routeMap = {
    'm_sales_invoices': '/invoices/new',
    'm_sales_invoice_list': '/invoices',
    'm_sales_invoice_gps': '/gps/invoices',
    'm_user_gps_locations': '/gps/users',
    'm_party_statement': '/statement',
    'm_receipt': '/receipts/new',
    'm_receipt_list': '/receipts',
    'm_sales_returns': '/returns/new',
    'm_sales_returns_list': '/returns',
    'm_rep_load': '/rep/load',
    'm_rep_custody_list': '/rep/custody',
    'm_rep_return': '/rep/return',
    'm_rep_stock': '/rep/stock',
  };

  static const Map<String, IconData> _iconMap = {
    'invoice': Icons.receipt_long,
    'list': Icons.format_list_bulleted,
    'ledger': Icons.menu_book,
    'receipt': Icons.receipt,
    'receipt-list': Icons.list_alt,
    'return': Icons.assignment_return,
    'return-list': Icons.assignment_returned,
    'map-pin': Icons.location_on_outlined,
    'load': Icons.download,
    'stock': Icons.inventory_2_outlined,
  };

  @override
  void initState() {
    super.initState();
    _load();
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
          .map((t) => _Tile(
                t['code'].toString(),
                t['label'].toString(),
                (t['icon'] ?? 'invoice').toString(),
              ))
          .where((t) => _routeMap.containsKey(t.code))
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

  @override
  Widget build(BuildContext context) {
    final s = context.watch<SessionController>();
    return Scaffold(
      appBar: AppBar(
        title: Text(_company.isEmpty ? 'الرئيسية' : _company),
        actions: [
          IconButton(
            tooltip: 'تسجيل الخروج',
            icon: const Icon(Icons.logout),
            onPressed: () => s.logout(),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        child: AsyncView(
          loading: _loading,
          error: _error,
          onRetry: _load,
          child: _tiles.isEmpty
              ? ListView(
                  children: const [
                    SizedBox(height: 120),
                    EmptyState(message: 'لا توجد شاشات متاحة لحسابك.'),
                  ],
                )
              : GridView.builder(
                  padding: const EdgeInsets.all(14),
                  gridDelegate:
                      const SliverGridDelegateWithFixedCrossAxisCount(
                    crossAxisCount: 2,
                    mainAxisSpacing: 12,
                    crossAxisSpacing: 12,
                    childAspectRatio: 1.05,
                  ),
                  itemCount: _tiles.length,
                  itemBuilder: (context, i) {
                    final t = _tiles[i];
                    return _TileCard(
                      label: t.label,
                      icon: _iconMap[t.icon] ?? Icons.apps,
                      onTap: () => context.push(_routeMap[t.code]!),
                    );
                  },
                ),
        ),
      ),
    );
  }
}

class _TileCard extends StatelessWidget {
  const _TileCard({
    required this.label,
    required this.icon,
    required this.onTap,
  });

  final String label;
  final IconData icon;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: InkWell(
        borderRadius: BorderRadius.circular(14),
        onTap: onTap,
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Container(
              width: 64,
              height: 64,
              decoration: BoxDecoration(
                color: AppTheme.primary.withValues(alpha: 0.10),
                shape: BoxShape.circle,
              ),
              child: Icon(icon, size: 32, color: AppTheme.primary),
            ),
            const SizedBox(height: 12),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 8),
              child: Text(
                label,
                textAlign: TextAlign.center,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(
                    fontSize: 14, fontWeight: FontWeight.w600),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
