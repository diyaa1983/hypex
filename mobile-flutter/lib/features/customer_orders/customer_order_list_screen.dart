import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/format.dart';
import '../../core/theme.dart';
import '../../widgets/async_view.dart';
import '../../widgets/mobile_scaffold.dart';
import '../../widgets/ui_kit.dart';

class CustomerOrderListScreen extends StatefulWidget {
  const CustomerOrderListScreen({super.key});

  @override
  State<CustomerOrderListScreen> createState() =>
      _CustomerOrderListScreenState();
}

class _CustomerOrderListScreenState extends State<CustomerOrderListScreen> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _orders = [];
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
        query: {'q': _search.text.trim()},
      );
      if (!mounted) return;
      setState(() {
        _orders = (data['orders'] as List? ?? [])
            .whereType<Map>()
            .map((e) => e.cast<String, dynamic>())
            .toList();
        _loading = false;
      });
    } on ApiException catch (e) {
      if (mounted)
        setState(() {
          _error = e.message;
          _loading = false;
        });
    }
  }

  @override
  Widget build(BuildContext context) => MobileScaffold(
        title: const Text('طلبات شراء العملاء'),
        actions: [
          IconButton(
              onPressed: _loading ? null : _load,
              icon: const Icon(Icons.refresh_rounded))
        ],
        floatingActionButton: FloatingActionButton.extended(
          onPressed: () => context.push('/customer-orders/new'),
          icon: const Icon(Icons.add_rounded),
          label: const Text('طلب جديد'),
        ),
        body: Column(children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 12, 14, 8),
            child: TextField(
              controller: _search,
              onSubmitted: (_) => _load(),
              decoration: InputDecoration(
                hintText: 'بحث برقم الطلب أو العميل',
                prefixIcon: const Icon(Icons.search_rounded),
                suffixIcon: _search.text.isEmpty
                    ? null
                    : IconButton(
                        icon: const Icon(Icons.close_rounded),
                        onPressed: () {
                          _search.clear();
                          _load();
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
                ? ListView(children: const [
                    SizedBox(height: 70),
                    EmptyState(
                        message: 'لا توجد طلبات شراء.',
                        icon: Icons.shopping_cart_outlined)
                  ])
                : RefreshIndicator(
                    onRefresh: _load,
                    child: ListView.builder(
                      padding: const EdgeInsets.fromLTRB(14, 4, 14, 88),
                      itemCount: _orders.length,
                      itemBuilder: (_, i) {
                        final o = _orders[i];
                        final approved = o['approved'] == true ||
                            o['is_approved'] == true ||
                            Fmt.str(o['status']) == 'approved';
                        return AppCard(
                          onTap: () => context
                              .push('/customer-orders/${Fmt.toInt(o['id'])}'),
                          child: Row(children: [
                            MiniIcon(Icons.shopping_cart_checkout_rounded,
                                color: approved
                                    ? AppTheme.success
                                    : AppTheme.warn),
                            const SizedBox(width: 10),
                            Expanded(
                                child: Column(
                                    crossAxisAlignment:
                                        CrossAxisAlignment.start,
                                    children: [
                                  Text(Fmt.str(o['order_no']).isEmpty
                                      ? '#${Fmt.toInt(o['id'])}'
                                      : Fmt.str(o['order_no'])),
                                  const SizedBox(height: 3),
                                  Text(
                                      '${Fmt.str(o['customer_name'])}  •  ${Fmt.dmy(Fmt.str(o['order_date']))}',
                                      style: const TextStyle(
                                          color: AppTheme.textSoft,
                                          fontSize: 12)),
                                ])),
                            StatusPill(
                                text: approved ? 'معتمد' : 'مسودة',
                                color: approved
                                    ? AppTheme.success
                                    : AppTheme.warn),
                          ]),
                        );
                      },
                    ),
                  ),
          )),
        ]),
      );
}
