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

/// طلبات شراء العملاء — مدير المبيعات حسب المندوب (اطلاع).
class ManagerCustomerOrdersScreen extends StatefulWidget {
  const ManagerCustomerOrdersScreen({super.key});

  @override
  State<ManagerCustomerOrdersScreen> createState() =>
      _ManagerCustomerOrdersScreenState();
}

class _ManagerCustomerOrdersScreenState
    extends State<ManagerCustomerOrdersScreen> {
  bool _loading = true;
  bool _loadingOrders = false;
  String? _error;
  List<Map<String, dynamic>> _reps = [];
  List<Map<String, dynamic>> _orders = [];
  Map<String, dynamic>? _pager;
  int _repId = 0;
  int _page = 1;
  String? _sentFilter; // null = الكل، '1' مرسلة، '0' غير مرسلة
  DateTime _from = DateTime(DateTime.now().year, DateTime.now().month, 1);
  DateTime _to = DateTime.now();
  final _search = TextEditingController();

  String get _fromIso =>
      '${_from.year.toString().padLeft(4, '0')}-${_from.month.toString().padLeft(2, '0')}-${_from.day.toString().padLeft(2, '0')}';

  String get _toIso =>
      '${_to.year.toString().padLeft(4, '0')}-${_to.month.toString().padLeft(2, '0')}-${_to.day.toString().padLeft(2, '0')}';

  @override
  void initState() {
    super.initState();
    _bootstrap();
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _bootstrap() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final res = await context.read<ApiClient>().getJson(
            AppConfig.managerCustomerOrdersPath,
          );
      if (!mounted) return;
      final reps = (res['reps'] as List? ?? [])
          .whereType<Map>()
          .map((e) => e.cast<String, dynamic>())
          .toList();
      setState(() {
        _reps = reps;
        _loading = false;
        if (_repId < 1 && reps.isNotEmpty) {
          _repId = Fmt.toInt(reps.first['id']);
        }
      });
      if (_repId > 0) await _loadOrders();
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _loading = false;
      });
    }
  }

  Future<void> _loadOrders() async {
    if (_repId < 1) return;
    setState(() {
      _loadingOrders = true;
      _error = null;
    });
    try {
      final query = <String, dynamic>{
        'sales_rep_id': '$_repId',
        'page': _page,
        'from': _fromIso,
        'to': _toIso,
        'q': _search.text.trim(),
      };
      if (_sentFilter != null) query['is_sent'] = _sentFilter;
      final res = await context.read<ApiClient>().getJson(
            AppConfig.managerCustomerOrdersPath,
            query: query,
          );
      if (!mounted) return;
      final pager = (res['pager'] is Map)
          ? (res['pager'] as Map).cast<String, dynamic>()
          : null;
      final serverPage = (pager?['page'] as num?)?.toInt();
      setState(() {
        _orders = (res['orders'] as List? ?? [])
            .whereType<Map>()
            .map((e) => e.cast<String, dynamic>())
            .toList();
        _pager = pager;
        if (serverPage != null && serverPage > 0) _page = serverPage;
        _loadingOrders = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _loadingOrders = false;
      });
    }
  }

  void _resetAndLoad() {
    setState(() => _page = 1);
    _loadOrders();
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
    _resetAndLoad();
  }

  String _repLabel(Map<String, dynamic> r) {
    final name = Fmt.str(r['name_ar']);
    final code = Fmt.str(r['code']);
    if (code.isEmpty) return name;
    return '$name ($code)';
  }

  bool _isSent(Map<String, dynamic> o) {
    final v = o['is_sent'];
    return v == true || v == 1 || '$v' == '1';
  }

  @override
  Widget build(BuildContext context) {
    return MobileScaffold(
      title: const Text('طلبات شراء العملاء'),
      backgroundColor: const Color(0xFFF0F4F8),
      actions: [
        IconButton(
          tooltip: 'تحديث',
          onPressed: _loading || _loadingOrders
              ? null
              : () {
                  if (_repId > 0) {
                    _loadOrders();
                  } else {
                    _bootstrap();
                  }
                },
          icon: const Icon(Icons.refresh_rounded),
        ),
      ],
      body: AsyncView(
        loading: _loading,
        error: _error,
        onRetry: _bootstrap,
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 12, 12, 8),
              child: Column(
                children: [
                  DropdownButtonFormField<int>(
                    value: _repId > 0 ? _repId : null,
                    isExpanded: true,
                    decoration: const InputDecoration(
                      labelText: 'المندوب',
                      border: OutlineInputBorder(),
                      filled: true,
                      fillColor: Colors.white,
                      contentPadding:
                          EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                    ),
                    items: [
                      for (final r in _reps)
                        DropdownMenuItem(
                          value: Fmt.toInt(r['id']),
                          child: Text(
                            _repLabel(r),
                            overflow: TextOverflow.ellipsis,
                          ),
                        ),
                    ],
                    onChanged: _loadingOrders
                        ? null
                        : (v) {
                            if (v == null || v == _repId) return;
                            setState(() {
                              _repId = v;
                              _page = 1;
                            });
                            _loadOrders();
                          },
                  ),
                  const SizedBox(height: 10),
                  Row(
                    children: [
                      Expanded(
                        child: _DateBtn(
                          label: 'من',
                          value: Fmt.dmy(_fromIso),
                          onTap: () => _pickDate(from: true),
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: _DateBtn(
                          label: 'إلى',
                          value: Fmt.dmy(_toIso),
                          onTap: () => _pickDate(from: false),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 10),
                  TextField(
                    controller: _search,
                    onSubmitted: (_) => _resetAndLoad(),
                    decoration: InputDecoration(
                      hintText: 'بحث برقم الطلب أو العميل',
                      filled: true,
                      fillColor: Colors.white,
                      prefixIcon: const Icon(Icons.search_rounded),
                      suffixIcon: _search.text.isEmpty
                          ? IconButton(
                              icon: const Icon(Icons.search_rounded),
                              onPressed: _resetAndLoad,
                            )
                          : IconButton(
                              icon: const Icon(Icons.close_rounded),
                              onPressed: () {
                                _search.clear();
                                _resetAndLoad();
                              },
                            ),
                      border: const OutlineInputBorder(),
                      contentPadding: const EdgeInsets.symmetric(
                        horizontal: 12,
                        vertical: 10,
                      ),
                    ),
                    onChanged: (_) => setState(() {}),
                  ),
                  const SizedBox(height: 8),
                  SingleChildScrollView(
                    scrollDirection: Axis.horizontal,
                    child: Row(
                      children: [
                        for (final e in const [
                          (null, 'الكل'),
                          ('1', 'مرسلة'),
                          ('0', 'غير مرسلة'),
                        ]) ...[
                          Padding(
                            padding: const EdgeInsets.only(left: 6),
                            child: ChoiceChip(
                              label: Text(e.$2),
                              selected: _sentFilter == e.$1,
                              onSelected: _loadingOrders
                                  ? null
                                  : (_) {
                                      if (_sentFilter == e.$1) return;
                                      setState(() {
                                        _sentFilter = e.$1;
                                        _page = 1;
                                      });
                                      _loadOrders();
                                    },
                            ),
                          ),
                        ],
                      ],
                    ),
                  ),
                ],
              ),
            ),
            Expanded(
              child: _repId < 1
                  ? const Center(
                      child: Text(
                        'اختر مندوباً لعرض طلباته.',
                        style: TextStyle(
                          fontWeight: FontWeight.w700,
                          color: AppTheme.textSoft,
                        ),
                      ),
                    )
                  : _loadingOrders
                      ? const Center(child: CircularProgressIndicator())
                      : _orders.isEmpty
                          ? ListView(
                              children: const [
                                SizedBox(height: 70),
                                EmptyState(
                                  message: 'لا توجد طلبات لهذا المندوب.',
                                  icon: Icons.receipt_long_rounded,
                                ),
                              ],
                            )
                          : RefreshIndicator(
                              onRefresh: _loadOrders,
                              child: ListView.builder(
                                padding:
                                    const EdgeInsets.fromLTRB(12, 4, 12, 16),
                                itemCount: _orders.length,
                                itemBuilder: (_, i) {
                                  final o = _orders[i];
                                  final sent = _isSent(o);
                                  return Padding(
                                    padding: const EdgeInsets.only(bottom: 10),
                                    child: AppCard(
                                      onTap: () async {
                                        await context.push(
                                          '/customer-orders/${Fmt.toInt(o['id'])}',
                                        );
                                        if (mounted) _loadOrders();
                                      },
                                      child: Row(
                                        children: [
                                          MiniIcon(
                                            sent
                                                ? Icons.mark_email_read_rounded
                                                : Icons.outbox_rounded,
                                            color: sent
                                                ? AppTheme.success
                                                : AppTheme.amber,
                                          ),
                                          const SizedBox(width: 10),
                                          Expanded(
                                            child: Column(
                                              crossAxisAlignment:
                                                  CrossAxisAlignment.start,
                                              children: [
                                                Text(
                                                  Fmt.str(o['order_no'])
                                                          .isEmpty
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
                                          StatusPill(
                                            text: sent ? 'مرسل' : 'غير مرسل',
                                            color: sent
                                                ? AppTheme.success
                                                : AppTheme.amber,
                                          ),
                                          const SizedBox(width: 4),
                                          const Icon(
                                            Icons.chevron_left_rounded,
                                            color: AppTheme.textSoft,
                                          ),
                                        ],
                                      ),
                                    ),
                                  );
                                },
                              ),
                            ),
            ),
            if (_repId > 0)
              ListPageBar.fromPager(
                _pager,
                onPageChanged: (p) {
                  if (p < 1 || p == _page) return;
                  setState(() => _page = p);
                  _loadOrders();
                },
              ),
          ],
        ),
      ),
    );
  }
}

class _DateBtn extends StatelessWidget {
  const _DateBtn({
    required this.label,
    required this.value,
    required this.onTap,
  });

  final String label;
  final String value;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Colors.white,
      borderRadius: BorderRadius.circular(12),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
          child: Row(
            children: [
              const Icon(Icons.calendar_month_rounded,
                  size: 18, color: AppTheme.primary),
              const SizedBox(width: 8),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      label,
                      style: const TextStyle(
                        fontSize: 11.5,
                        color: AppTheme.textSoft,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    Text(
                      value,
                      style: const TextStyle(
                        fontWeight: FontWeight.w800,
                        fontSize: 13.5,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
