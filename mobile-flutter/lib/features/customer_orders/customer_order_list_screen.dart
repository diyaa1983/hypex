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
  Map<String, dynamic>? _pager;
  int _page = 1;
  final _search = TextEditingController();
  String _status = ''; // '' | draft | approved

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
      final query = <String, dynamic>{
        'q': _search.text.trim(),
        'page': _page,
      };
      if (_status.isNotEmpty) query['status'] = _status;
      final data = await context.read<ApiClient>().getJson(
            AppConfig.customerOrderListPath,
            query: query,
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
      if (mounted) {
        setState(() {
          _error = e.message;
          _loading = false;
        });
      }
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

  bool _isApproved(Map<String, dynamic> o) =>
      o['approved'] == true ||
      o['is_approved'] == true ||
      Fmt.str(o['status']) == 'approved';

  @override
  Widget build(BuildContext context) => MobileScaffold(
        title: const Text('طلبات شراء العملاء'),
        actions: [
          IconButton(
              onPressed: _loading ? null : _load,
              icon: const Icon(Icons.refresh_rounded))
        ],
        floatingActionButton: FloatingActionButton.extended(
          onPressed: () async {
            await context.push('/customers');
            if (mounted) _load();
          },
          icon: const Icon(Icons.people_rounded),
          label: const Text('اختيار عميل وزيارته'),
        ),
        body: Column(children: [
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
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 0, 14, 8),
            child: Wrap(
              spacing: 8,
              children: [
                ChoiceChip(
                  label: const Text('الكل'),
                  selected: _status == '',
                  onSelected: (_) {
                    setState(() {
                      _status = '';
                      _page = 1;
                    });
                    _load();
                  },
                ),
                ChoiceChip(
                  label: const Text('مسودة'),
                  selected: _status == 'draft',
                  onSelected: (_) {
                    setState(() {
                      _status = 'draft';
                      _page = 1;
                    });
                    _load();
                  },
                ),
                ChoiceChip(
                  label: const Text('معتمد'),
                  selected: _status == 'approved',
                  onSelected: (_) {
                    setState(() {
                      _status = 'approved';
                      _page = 1;
                    });
                    _load();
                  },
                ),
              ],
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
                        final approved = _isApproved(o);
                        final lines = Fmt.toInt(o['line_count']);
                        final qty = Fmt.toDouble(o['total_qty']);
                        return AppCard(
                          onTap: () async {
                            await context.push(
                                '/customer-orders/${Fmt.toInt(o['id'])}');
                            if (mounted) _load();
                          },
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
                                  if (Fmt.str(o['sales_rep_name']).isNotEmpty)
                                    Text(
                                      'المندوب: ${Fmt.str(o['sales_rep_name'])}',
                                      style: const TextStyle(
                                          color: AppTheme.textSoft,
                                          fontSize: 12),
                                    ),
                                  Text(
                                    'بنود $lines  •  كمية ${Fmt.trimNum(qty)}',
                                    style: const TextStyle(
                                        color: AppTheme.textSoft, fontSize: 12),
                                  ),
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
          ListPageBar.fromPager(_pager, onPageChanged: _changePage),
        ]),
      );
}
