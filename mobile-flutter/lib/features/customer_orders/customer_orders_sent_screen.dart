import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/format.dart';
import '../../core/theme.dart';
import '../../widgets/async_view.dart';
import '../../widgets/list_page_bar.dart';
import '../../widgets/mobile_scaffold.dart';
import '../../widgets/ui_kit.dart';

/// الطلبات المرسلة — عرض فقط.
class CustomerOrdersSentScreen extends StatefulWidget {
  const CustomerOrdersSentScreen({super.key});

  @override
  State<CustomerOrdersSentScreen> createState() =>
      _CustomerOrdersSentScreenState();
}

class _CustomerOrdersSentScreenState extends State<CustomerOrdersSentScreen> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _orders = [];
  Map<String, dynamic>? _pager;
  int _page = 1;
  final _search = TextEditingController();

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final data = await context.read<ApiClient>().getJson(
            AppConfig.customerOrderListPath,
            query: {
              'is_sent': 1,
              'page': _page,
              'q': _search.text.trim(),
            },
          );
      if (!mounted) return;
      final pager = (data['pager'] is Map)
          ? (data['pager'] as Map).cast<String, dynamic>()
          : null;
      final serverPage = (pager?['page'] as num?)?.toInt();
      setState(() {
        _orders = (data['orders'] as List? ?? [])
            .whereType<Map>()
            .map((e) => e.cast<String, dynamic>())
            .toList();
        _pager = pager;
        if (serverPage != null && serverPage > 0) _page = serverPage;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _loading = false;
      });
    }
  }

  void _changePage(int page) {
    if (page < 1 || page == _page) return;
    setState(() => _page = page);
    _load();
  }

  void _resetAndLoad() {
    setState(() => _page = 1);
    _load();
  }

  @override
  Widget build(BuildContext context) {
    return MobileScaffold(
      title: const Text('الطلبات المرسلة'),
      actions: [
        IconButton(
          onPressed: _loading ? null : _load,
          icon: const Icon(Icons.refresh_rounded),
        ),
      ],
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 12, 14, 8),
            child: TextField(
              controller: _search,
              onSubmitted: (_) => _resetAndLoad(),
              decoration: InputDecoration(
                hintText: 'بحث برقم الطلب أو العميل',
                prefixIcon: const Icon(Icons.search_rounded),
                suffixIcon: _search.text.isEmpty
                    ? null
                    : IconButton(
                        icon: const Icon(Icons.close_rounded),
                        onPressed: () {
                          _search.clear();
                          _resetAndLoad();
                        },
                      ),
              ),
              onChanged: (_) => setState(() {}),
            ),
          ),
          Expanded(
            child: AsyncView(
              loading: _loading,
              error: _error,
              onRetry: _load,
              child: _orders.isEmpty
                  ? ListView(
                      children: const [
                        SizedBox(height: 70),
                        EmptyState(
                          message: 'لا توجد طلبات مرسلة.',
                          icon: Icons.mark_email_read_rounded,
                        ),
                      ],
                    )
                  : RefreshIndicator(
                      onRefresh: _load,
                      child: ListView.builder(
                        padding: const EdgeInsets.fromLTRB(14, 4, 14, 24),
                        itemCount: _orders.length,
                        itemBuilder: (_, i) {
                          final o = _orders[i];
                          return AppCard(
                            onTap: () async {
                              await context.push(
                                '/customer-orders/${Fmt.toInt(o['id'])}',
                              );
                              if (mounted) _load();
                            },
                            child: Row(
                              children: [
                                const MiniIcon(
                                  Icons.mark_email_read_rounded,
                                  color: AppTheme.success,
                                ),
                                const SizedBox(width: 10),
                                Expanded(
                                  child: Column(
                                    crossAxisAlignment:
                                        CrossAxisAlignment.start,
                                    children: [
                                      Text(
                                        Fmt.str(o['order_no']).isEmpty
                                            ? '#${Fmt.toInt(o['id'])}'
                                            : Fmt.str(o['order_no']),
                                        style: const TextStyle(
                                          fontWeight: FontWeight.w800,
                                        ),
                                      ),
                                      const SizedBox(height: 3),
                                      Text(
                                        '${Fmt.str(o['customer_name'])}  •  ${Fmt.dmy(Fmt.str(o['order_date']))}',
                                        style: const TextStyle(
                                          color: AppTheme.textSoft,
                                          fontSize: 12.5,
                                        ),
                                      ),
                                      Text(
                                        'الإجمالي: ${Fmt.money(Fmt.toDouble(o['total']))}',
                                        style: const TextStyle(
                                          color: AppTheme.textSoft,
                                          fontSize: 12.5,
                                        ),
                                      ),
                                    ],
                                  ),
                                ),
                                const Icon(
                                  Icons.chevron_left_rounded,
                                  color: AppTheme.textSoft,
                                ),
                              ],
                            ),
                          );
                        },
                      ),
                    ),
            ),
          ),
          ListPageBar.fromPager(_pager, onPageChanged: _changePage),
        ],
      ),
    );
  }
}
