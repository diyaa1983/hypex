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
import '../../widgets/party_picker.dart';
import '../../widgets/ui_kit.dart';

/// استعلام طلبات عملاء حسب العميل (أو الكل) والتاريخ.
class CustomerOrdersQueryScreen extends StatefulWidget {
  const CustomerOrdersQueryScreen({super.key});

  @override
  State<CustomerOrdersQueryScreen> createState() =>
      _CustomerOrdersQueryScreenState();
}

class _CustomerOrdersQueryScreenState extends State<CustomerOrdersQueryScreen> {
  Party? _customer;
  bool _allCustomers = true;
  DateTime _from = DateTime(DateTime.now().year, DateTime.now().month, 1);
  DateTime _to = DateTime.now();

  bool _loading = false;
  bool _ran = false;
  String? _error;
  List<Map<String, dynamic>> _orders = [];
  Map<String, dynamic>? _pager;
  int _page = 1;

  String get _fromIso =>
      '${_from.year.toString().padLeft(4, '0')}-${_from.month.toString().padLeft(2, '0')}-${_from.day.toString().padLeft(2, '0')}';

  String get _toIso =>
      '${_to.year.toString().padLeft(4, '0')}-${_to.month.toString().padLeft(2, '0')}-${_to.day.toString().padLeft(2, '0')}';

  Future<void> _pickCustomer() async {
    final p = await pickParty(context);
    if (p != null) {
      setState(() {
        _customer = p;
        _allCustomers = false;
      });
    }
  }

  Future<void> _pickDate({required bool from}) async {
    final initial = from ? _from : _to;
    final picked = await showDatePicker(
      context: context,
      initialDate: initial,
      firstDate: DateTime(2015),
      lastDate: DateTime(2100),
    );
    if (picked == null) return;
    setState(() {
      if (from) {
        _from = picked;
      } else {
        _to = picked;
      }
    });
  }

  Future<void> _load({bool resetPage = false}) async {
    if (!_allCustomers && _customer == null) {
      showSnack(context, 'اختر العميل أو فعّل جميع العملاء.', error: true);
      return;
    }
    if (resetPage) _page = 1;
    setState(() {
      _loading = true;
      _error = null;
      _ran = true;
    });
    try {
      final query = <String, dynamic>{
        'from': _fromIso,
        'to': _toIso,
        'page': _page,
      };
      if (!_allCustomers && _customer != null) {
        query['customer_id'] = _customer!.id;
      }
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

  @override
  Widget build(BuildContext context) {
    return MobileScaffold(
      title: const Text('طلبات عملاء'),
      actions: [
        IconButton(
          onPressed: _loading ? null : () => _load(resetPage: true),
          icon: const Icon(Icons.search_rounded),
          tooltip: 'بحث',
        ),
      ],
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 12, 14, 8),
            child: Column(
              children: [
                SwitchListTile.adaptive(
                  contentPadding: EdgeInsets.zero,
                  title: const Text(
                    'جميع العملاء',
                    style: TextStyle(fontWeight: FontWeight.w700),
                  ),
                  value: _allCustomers,
                  onChanged: (v) => setState(() {
                    _allCustomers = v;
                    if (v) _customer = null;
                  }),
                ),
                if (!_allCustomers)
                  InkWell(
                    onTap: _pickCustomer,
                    borderRadius: BorderRadius.circular(14),
                    child: InputDecorator(
                      decoration: const InputDecoration(
                        labelText: 'العميل',
                        suffixIcon: Icon(Icons.person_search_rounded),
                      ),
                      child: Text(
                        _customer == null
                            ? 'اختر العميل…'
                            : [
                                if (_customer!.code.isNotEmpty) _customer!.code,
                                _customer!.name,
                              ].where((s) => s.isNotEmpty).join(' — '),
                        style: TextStyle(
                          color: _customer == null
                              ? AppTheme.textSoft
                              : AppTheme.textMain,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    ),
                  ),
                const SizedBox(height: 10),
                Row(
                  children: [
                    Expanded(
                      child: InkWell(
                        onTap: () => _pickDate(from: true),
                        borderRadius: BorderRadius.circular(12),
                        child: InputDecorator(
                          decoration: const InputDecoration(
                            labelText: 'من تاريخ',
                            suffixIcon: Icon(Icons.calendar_month_rounded),
                            isDense: true,
                          ),
                          child: Text(Fmt.dmy(_fromIso)),
                        ),
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: InkWell(
                        onTap: () => _pickDate(from: false),
                        borderRadius: BorderRadius.circular(12),
                        child: InputDecorator(
                          decoration: const InputDecoration(
                            labelText: 'إلى تاريخ',
                            suffixIcon: Icon(Icons.calendar_month_rounded),
                            isDense: true,
                          ),
                          child: Text(Fmt.dmy(_toIso)),
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 10),
                SizedBox(
                  width: double.infinity,
                  child: FilledButton.icon(
                    onPressed: _loading ? null : () => _load(resetPage: true),
                    icon: const Icon(Icons.filter_alt_rounded),
                    label: const Text('عرض الطلبات'),
                  ),
                ),
              ],
            ),
          ),
          Expanded(
            child: !_ran
                ? const Center(
                    child: Text(
                      'حدد الفترة ثم اضغط عرض.',
                      style: TextStyle(color: AppTheme.textSoft),
                    ),
                  )
                : AsyncView(
                    loading: _loading,
                    error: _error,
                    onRetry: () => _load(resetPage: true),
                    child: _orders.isEmpty
                        ? ListView(
                            children: const [
                              SizedBox(height: 70),
                              EmptyState(
                                message: 'لا توجد طلبات ضمن الفترة.',
                                icon: Icons.date_range_rounded,
                              ),
                            ],
                          )
                        : RefreshIndicator(
                            onRefresh: () => _load(),
                            child: ListView.builder(
                              padding:
                                  const EdgeInsets.fromLTRB(14, 4, 14, 24),
                              itemCount: _orders.length,
                              itemBuilder: (_, i) {
                                final o = _orders[i];
                                return AppCard(
                                  onTap: () => context.push(
                                    '/customer-orders/${Fmt.toInt(o['id'])}',
                                  ),
                                  child: Column(
                                    crossAxisAlignment:
                                        CrossAxisAlignment.stretch,
                                    children: [
                                      Text(
                                        Fmt.str(o['customer_name']).isEmpty
                                            ? '—'
                                            : Fmt.str(o['customer_name']),
                                        style: const TextStyle(
                                          fontWeight: FontWeight.w800,
                                          fontSize: 14.5,
                                        ),
                                      ),
                                      const SizedBox(height: 6),
                                      Row(
                                        children: [
                                          Expanded(
                                            child: Text(
                                              Fmt.dmy(Fmt.str(o['order_date'])),
                                              style: const TextStyle(
                                                color: AppTheme.textSoft,
                                                fontWeight: FontWeight.w700,
                                              ),
                                            ),
                                          ),
                                          Text(
                                            'مواد: ${Fmt.trimNum(Fmt.toDouble(o['total_qty'] ?? o['line_count']))}',
                                            style: const TextStyle(
                                              color: AppTheme.textSoft,
                                              fontWeight: FontWeight.w700,
                                              fontSize: 12.5,
                                            ),
                                          ),
                                          const SizedBox(width: 12),
                                          Text(
                                            Fmt.money(Fmt.toDouble(o['total'])),
                                            textDirection: TextDirection.ltr,
                                            style: const TextStyle(
                                              fontWeight: FontWeight.w800,
                                              fontSize: 14.5,
                                            ),
                                          ),
                                        ],
                                      ),
                                    ],
                                  ),
                                );
                              },
                            ),
                          ),
                  ),
          ),
          if (_ran) ListPageBar.fromPager(_pager, onPageChanged: _changePage),
        ],
      ),
    );
  }
}
