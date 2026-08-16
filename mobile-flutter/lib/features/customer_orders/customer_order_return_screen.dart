import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/async_view.dart';
import '../../widgets/mobile_scaffold.dart';
import '../../widgets/party_picker.dart';
import '../../widgets/ui_kit.dart';

/// مرتجعات طلبات شراء العملاء — قائمة + إنشاء مسودة (جزئي) + عرض/ترحيل.
class CustomerOrderReturnScreen extends StatefulWidget {
  const CustomerOrderReturnScreen({
    super.key,
    this.fixedStatus,
    this.title,
    this.enableMultiSelect = false,
  });

  /// `draft` | `posted` | null (الكل)
  final String? fixedStatus;
  final String? title;
  final bool enableMultiSelect;

  @override
  State<CustomerOrderReturnScreen> createState() =>
      _CustomerOrderReturnScreenState();
}

class _CustomerOrderReturnScreenState extends State<CustomerOrderReturnScreen> {
  Party? _customer;
  DateTime _from = DateTime(DateTime.now().year, DateTime.now().month, 1);
  DateTime _to = DateTime.now();
  late String _status;

  bool _loading = true;
  bool _busy = false;
  String? _error;
  List<Map<String, dynamic>> _rows = [];
  final _selected = <int>{};

  String get _fromIso =>
      '${_from.year.toString().padLeft(4, '0')}-${_from.month.toString().padLeft(2, '0')}-${_from.day.toString().padLeft(2, '0')}';

  String get _toIso =>
      '${_to.year.toString().padLeft(4, '0')}-${_to.month.toString().padLeft(2, '0')}-${_to.day.toString().padLeft(2, '0')}';

  @override
  void initState() {
    super.initState();
    _status = widget.fixedStatus ?? '';
    _load();
  }

  Future<void> _pickCustomer() async {
    final p = await pickParty(context);
    if (p != null) setState(() => _customer = p);
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

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final query = <String, dynamic>{
        'from': _fromIso,
        'to': _toIso,
      };
      if (_customer != null) query['customer_id'] = _customer!.id;
      if (_status.isNotEmpty) query['status'] = _status;
      final data = await context.read<ApiClient>().getJson(
            AppConfig.customerOrderReturnPath,
            query: query,
          );
      if (!mounted) return;
      setState(() {
        _rows = (data['returns'] as List? ?? [])
            .whereType<Map>()
            .map((e) => e.cast<String, dynamic>())
            .toList();
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

  bool _isPosted(Map<String, dynamic> r) =>
      Fmt.str(r['status']) == 'posted' ||
      r['is_posted'] == true ||
      r['is_posted'] == 1;

  Future<void> _openDetail(int id) async {
    if (id < 1) return;
    await Navigator.of(context).push<void>(
      MaterialPageRoute(
        builder: (_) => _CustomerOrderReturnDetailPage(returnId: id),
      ),
    );
    if (mounted) _load();
  }

  Future<void> _newReturn() async {
    final orderId = await showModalBottomSheet<int>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(18)),
      ),
      builder: (_) => _ReturnableOrdersSheet(
        customerId: _customer?.id,
      ),
    );
    if (orderId == null || orderId < 1 || !mounted) return;

    final lines = await showModalBottomSheet<List<Map<String, dynamic>>>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(18)),
      ),
      builder: (_) => _ReturnLinesPickerSheet(orderId: orderId),
    );
    if (lines == null || lines.isEmpty || !mounted) return;

    try {
      final res = await context.read<ApiClient>().postJson(
            AppConfig.customerOrderReturnPath,
            body: {'order_id': orderId, 'lines': lines},
            csrf: context.read<SessionController>().csrf,
          );
      if (!mounted) return;
      final id = Fmt.toInt(res['id']);
      showSnack(context, (res['message'] ?? 'تم حفظ المرتجع كمسودة.').toString());
      if (id > 0) await _openDetail(id);
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message, error: true);
    }
  }

  Future<void> _deleteSelected() async {
    if (_selected.isEmpty) return;
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('حذف المرتجعات'),
        content: Text('حذف ${_selected.length} مرتجع مسودة؟'),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: const Text('إلغاء')),
          FilledButton(
              onPressed: () => Navigator.pop(ctx, true),
              child: const Text('حذف')),
        ],
      ),
    );
    if (ok != true || !mounted) return;
    setState(() => _busy = true);
    try {
      final csrf = context.read<SessionController>().csrf;
      for (final id in _selected.toList()) {
        await context.read<ApiClient>().postJson(
              AppConfig.customerOrderReturnPath,
              body: {'action': 'delete', 'id': id},
              csrf: csrf,
            );
      }
      _selected.clear();
      if (mounted) {
        showSnack(context, 'تم الحذف.');
        await _load();
      }
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _postSelected() async {
    if (_selected.isEmpty) return;
    setState(() => _busy = true);
    try {
      final csrf = context.read<SessionController>().csrf;
      var n = 0;
      for (final id in _selected.toList()) {
        await context.read<ApiClient>().postJson(
              AppConfig.customerOrderReturnPath,
              body: {'action': 'post', 'id': id},
              csrf: csrf,
            );
        n++;
      }
      _selected.clear();
      if (mounted) {
        showSnack(context, 'تم ترحيل $n مرتجع.');
        await _load();
      }
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final lockedStatus = widget.fixedStatus != null;
    return MobileScaffold(
      title: Text(widget.title ?? 'مرتجع طلب شراء'),
      actions: [
        if (widget.enableMultiSelect && _selected.isNotEmpty) ...[
          IconButton(
            onPressed: _busy ? null : _postSelected,
            icon: const Icon(Icons.send_rounded),
            tooltip: 'ترحيل المحددة',
          ),
          IconButton(
            onPressed: _busy ? null : _deleteSelected,
            icon: const Icon(Icons.delete_outline_rounded),
            tooltip: 'حذف المحددة',
          ),
        ],
        IconButton(
          onPressed: _loading ? null : _load,
          icon: const Icon(Icons.refresh_rounded),
          tooltip: 'تحديث',
        ),
      ],
      floatingActionButton: widget.fixedStatus == 'posted'
          ? null
          : FloatingActionButton.extended(
              onPressed: _newReturn,
              icon: const Icon(Icons.add_rounded, size: 20),
              label: const Text('مرتجع جديد'),
            ),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 12, 14, 8),
            child: Column(
              children: [
                InkWell(
                  onTap: _pickCustomer,
                  borderRadius: BorderRadius.circular(14),
                  child: InputDecorator(
                    decoration: InputDecoration(
                      labelText: 'العميل (اختياري)',
                      suffixIcon: _customer == null
                          ? const Icon(Icons.person_search_rounded)
                          : IconButton(
                              tooltip: 'مسح',
                              onPressed: () =>
                                  setState(() => _customer = null),
                              icon: const Icon(Icons.clear_rounded),
                            ),
                    ),
                    child: Text(
                      _customer == null
                          ? 'كل العملاء…'
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
                if (!lockedStatus) ...[
                  const SizedBox(height: 10),
                  Align(
                    alignment: Alignment.centerRight,
                    child: Wrap(
                      spacing: 8,
                      runSpacing: 6,
                      children: [
                        for (final opt in const [
                          ('', 'الكل'),
                          ('draft', 'مسودة'),
                          ('posted', 'مرحّل'),
                        ])
                          FilterChip(
                            label: Text(opt.$2),
                            selected: _status == opt.$1,
                            onSelected: (_) =>
                                setState(() => _status = opt.$1),
                          ),
                      ],
                    ),
                  ),
                ],
                const SizedBox(height: 10),
                SizedBox(
                  width: double.infinity,
                  child: FilledButton.icon(
                    onPressed: _loading ? null : _load,
                    icon: const Icon(Icons.filter_alt_rounded),
                    label: const Text('عرض'),
                  ),
                ),
              ],
            ),
          ),
          Expanded(
            child: AsyncView(
              loading: _loading,
              error: _error,
              onRetry: _load,
              child: _rows.isEmpty
                  ? ListView(
                      children: [
                        const SizedBox(height: 60),
                        EmptyState(
                          message: 'لا توجد مرتجعات ضمن الفترة.',
                          icon: Icons.assignment_return_rounded,
                          actionLabel: widget.fixedStatus == 'posted'
                              ? null
                              : 'مرتجع جديد',
                          onAction: widget.fixedStatus == 'posted'
                              ? null
                              : _newReturn,
                        ),
                      ],
                    )
                  : RefreshIndicator(
                      onRefresh: _load,
                      child: ListView.builder(
                        padding: const EdgeInsets.fromLTRB(14, 4, 14, 90),
                        itemCount: _rows.length,
                        itemBuilder: (_, i) {
                          final r = _rows[i];
                          final id = Fmt.toInt(r['id']);
                          final posted = _isPosted(r);
                          final checked = _selected.contains(id);
                          return AppCard(
                            onTap: () => _openDetail(id),
                            child: Row(
                              children: [
                                if (widget.enableMultiSelect && !posted)
                                  Checkbox(
                                    value: checked,
                                    onChanged: (v) {
                                      setState(() {
                                        if (v == true) {
                                          _selected.add(id);
                                        } else {
                                          _selected.remove(id);
                                        }
                                      });
                                    },
                                  ),
                                MiniIcon(
                                  Icons.assignment_return_rounded,
                                  color: posted
                                      ? AppTheme.success
                                      : AppTheme.rose,
                                ),
                                const SizedBox(width: 10),
                                Expanded(
                                  child: Column(
                                    crossAxisAlignment:
                                        CrossAxisAlignment.start,
                                    children: [
                                      Text(
                                        Fmt.str(r['customer_name']).isEmpty
                                            ? '—'
                                            : Fmt.str(r['customer_name']),
                                        maxLines: 1,
                                        overflow: TextOverflow.ellipsis,
                                        style: const TextStyle(
                                          fontWeight: FontWeight.w800,
                                        ),
                                      ),
                                      const SizedBox(height: 2),
                                      Text(
                                        [
                                          Fmt.str(r['return_no']).isEmpty
                                              ? '#$id'
                                              : Fmt.str(r['return_no']),
                                          if (Fmt.str(r['order_no'])
                                              .isNotEmpty)
                                            'طلب ${Fmt.str(r['order_no'])}',
                                          Fmt.dmy(Fmt.str(r['return_date'])),
                                        ].join(' • '),
                                        style: const TextStyle(
                                          fontSize: 12.5,
                                          color: AppTheme.textSoft,
                                        ),
                                      ),
                                    ],
                                  ),
                                ),
                                Column(
                                  crossAxisAlignment: CrossAxisAlignment.end,
                                  children: [
                                    Text(
                                      Fmt.money(Fmt.toDouble(r['total'])),
                                      textDirection: TextDirection.ltr,
                                      style: const TextStyle(
                                        fontWeight: FontWeight.w800,
                                        color: AppTheme.rose,
                                      ),
                                    ),
                                    const SizedBox(height: 4),
                                    StatusPill(
                                      text: posted ? 'مرحّل' : 'مسودة',
                                      color: posted
                                          ? AppTheme.success
                                          : AppTheme.warn,
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
        ],
      ),
    );
  }
}

/// اختيار طلب قابل للإرجاع.
class _ReturnableOrdersSheet extends StatefulWidget {
  const _ReturnableOrdersSheet({this.customerId});

  final int? customerId;

  @override
  State<_ReturnableOrdersSheet> createState() => _ReturnableOrdersSheetState();
}

class _ReturnableOrdersSheetState extends State<_ReturnableOrdersSheet> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _orders = [];

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
    try {
      final query = <String, dynamic>{'action': 'returnable'};
      if (widget.customerId != null && widget.customerId! > 0) {
        query['customer_id'] = widget.customerId;
      }
      final data = await context.read<ApiClient>().getJson(
            AppConfig.customerOrderReturnPath,
            query: query,
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
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _loading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final h = MediaQuery.sizeOf(context).height * 0.72;
    return SizedBox(
      height: h,
      child: Column(
        children: [
          const SizedBox(height: 10),
          Container(
            width: 40,
            height: 4,
            decoration: BoxDecoration(
              color: const Color(0xFFE2E8F0),
              borderRadius: BorderRadius.circular(99),
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 14, 8, 8),
            child: Row(
              children: [
                const Expanded(
                  child: Text(
                    'اختر طلباً للإرجاع',
                    style: TextStyle(
                      fontSize: 16,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                ),
                IconButton(
                  onPressed: _loading ? null : _load,
                  icon: const Icon(Icons.refresh_rounded),
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
                  ? const EmptyState(
                      message: 'لا توجد طلبات قابلة للإرجاع.',
                      icon: Icons.shopping_cart_outlined,
                    )
                  : ListView.builder(
                      padding: const EdgeInsets.fromLTRB(14, 4, 14, 24),
                      itemCount: _orders.length,
                      itemBuilder: (_, i) {
                        final o = _orders[i];
                        final id = Fmt.toInt(o['id']);
                        return AppCard(
                          onTap: () => Navigator.pop(context, id),
                          child: Row(
                            children: [
                              const MiniIcon(
                                Icons.shopping_cart_checkout_rounded,
                                color: AppTheme.violet,
                              ),
                              const SizedBox(width: 10),
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(
                                      Fmt.str(o['customer_name']).isEmpty
                                          ? '—'
                                          : Fmt.str(o['customer_name']),
                                      maxLines: 1,
                                      overflow: TextOverflow.ellipsis,
                                      style: const TextStyle(
                                        fontWeight: FontWeight.w800,
                                      ),
                                    ),
                                    Text(
                                      [
                                        Fmt.str(o['order_no']).isEmpty
                                            ? '#$id'
                                            : Fmt.str(o['order_no']),
                                        Fmt.dmy(Fmt.str(o['order_date'])),
                                      ].join(' • '),
                                      style: const TextStyle(
                                        fontSize: 12.5,
                                        color: AppTheme.textSoft,
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                              Text(
                                Fmt.money(Fmt.toDouble(o['total'])),
                                textDirection: TextDirection.ltr,
                                style: const TextStyle(
                                  fontWeight: FontWeight.w800,
                                ),
                              ),
                            ],
                          ),
                        );
                      },
                    ),
            ),
          ),
        ],
      ),
    );
  }
}

class _CustomerOrderReturnDetailPage extends StatefulWidget {
  const _CustomerOrderReturnDetailPage({required this.returnId});

  final int returnId;

  @override
  State<_CustomerOrderReturnDetailPage> createState() =>
      _CustomerOrderReturnDetailPageState();
}

class _CustomerOrderReturnDetailPageState
    extends State<_CustomerOrderReturnDetailPage> {
  bool _loading = true;
  bool _posting = false;
  String? _error;
  Map<String, dynamic> _doc = {};

  @override
  void initState() {
    super.initState();
    _load();
  }

  bool get _posted => Fmt.str(_doc['status']) == 'posted';

  List<Map<String, dynamic>> get _lines {
    final l = _doc['lines'];
    if (l is List) {
      return l.whereType<Map>().map((e) => e.cast<String, dynamic>()).toList();
    }
    return [];
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final res = await context.read<ApiClient>().getJson(
            AppConfig.customerOrderReturnPath,
            query: {'id': widget.returnId},
          );
      if (!mounted) return;
      setState(() {
        _doc = (res['return'] as Map?)?.cast<String, dynamic>() ?? {};
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

  Future<void> _post() async {
    if (_posted || _posting) return;
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('ترحيل المرتجع'),
        content: Text(
          'ترحيل المرتجع ${Fmt.str(_doc['return_no'])}؟',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('ترحيل'),
          ),
        ],
      ),
    );
    if (ok != true || !mounted) return;

    setState(() => _posting = true);
    try {
      final res = await context.read<ApiClient>().postJson(
            AppConfig.customerOrderReturnPath,
            body: {'action': 'post', 'id': widget.returnId},
            csrf: context.read<SessionController>().csrf,
          );
      if (!mounted) return;
      showSnack(context, (res['message'] ?? 'تم ترحيل المرتجع.').toString());
      await _load();
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _posting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final returnNo = Fmt.str(_doc['return_no']);
    return MobileScaffold(
      title: Text(returnNo.isEmpty ? 'عرض المرتجع' : 'مرتجع $returnNo'),
      actions: [
        IconButton(
          onPressed: _load,
          icon: const Icon(Icons.refresh_rounded),
          tooltip: 'تحديث',
        ),
      ],
      body: AsyncView(
        loading: _loading,
        error: _error,
        onRetry: _load,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(14, 14, 14, 24),
          children: [
            AppCard(
              padding: const EdgeInsets.all(14),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          Fmt.str(_doc['customer_name']).isEmpty
                              ? '—'
                              : Fmt.str(_doc['customer_name']),
                          style: const TextStyle(
                            fontSize: 16,
                            fontWeight: FontWeight.w800,
                          ),
                        ),
                      ),
                      StatusPill(
                        text: _posted ? 'مرحّل' : 'مسودة',
                        color: _posted ? AppTheme.success : AppTheme.warn,
                      ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  Text(
                    'رقم المرتجع: ${returnNo.isEmpty ? '—' : returnNo}',
                    style: const TextStyle(color: AppTheme.textSoft),
                  ),
                  if (Fmt.str(_doc['order_no']).isNotEmpty) ...[
                    const SizedBox(height: 4),
                    Text(
                      'طلب الشراء: ${Fmt.str(_doc['order_no'])}',
                      style: const TextStyle(color: AppTheme.textSoft),
                    ),
                  ],
                  const SizedBox(height: 4),
                  Text(
                    'التاريخ: ${Fmt.dmy(Fmt.str(_doc['return_date']))}',
                    style: const TextStyle(color: AppTheme.textSoft),
                  ),
                  if (Fmt.str(_doc['warehouse_name']).isNotEmpty) ...[
                    const SizedBox(height: 4),
                    Text(
                      'المستودع: ${Fmt.str(_doc['warehouse_name'])}',
                      style: const TextStyle(color: AppTheme.textSoft),
                    ),
                  ],
                  const SizedBox(height: 10),
                  Text(
                    Fmt.money(Fmt.toDouble(_doc['total'])),
                    textDirection: TextDirection.ltr,
                    style: const TextStyle(
                      fontSize: 22,
                      fontWeight: FontWeight.w900,
                      color: AppTheme.rose,
                    ),
                  ),
                  if (Fmt.toDouble(_doc['subtotal']) > 0 ||
                      Fmt.toDouble(_doc['tax_amount']) > 0) ...[
                    const SizedBox(height: 6),
                    Text(
                      'صافي: ${Fmt.money(Fmt.toDouble(_doc['subtotal']))}'
                      '  •  ضريبة: ${Fmt.money(Fmt.toDouble(_doc['tax_amount']))}',
                      style: const TextStyle(
                        fontSize: 12.5,
                        color: AppTheme.textSoft,
                      ),
                    ),
                  ],
                ],
              ),
            ),
            SectionTitle(
              'البنود',
              icon: Icons.list_alt_rounded,
              trailing: Text(
                '${_lines.length} بند',
                style: const TextStyle(
                  fontSize: 12,
                  color: AppTheme.textSoft,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ),
            if (_lines.isEmpty)
              const EmptyState(message: 'لا توجد بنود.')
            else
              ..._lines.map(_lineCard),
          ],
        ),
      ),
      bottomNavigationBar: (_loading || _posted)
          ? null
          : SafeArea(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(14, 8, 14, 12),
                child: FilledButton.icon(
                  onPressed: _posting ? null : _post,
                  icon: _posting
                      ? const SizedBox(
                          width: 18,
                          height: 18,
                          child: CircularProgressIndicator(
                            strokeWidth: 2,
                            color: Colors.white,
                          ),
                        )
                      : const Icon(Icons.check_circle_outline_rounded, size: 19),
                  label: const Text('ترحيل'),
                ),
              ),
            ),
    );
  }

  Widget _lineCard(Map<String, dynamic> ln) {
    final qty = Fmt.toDouble(ln['qty']);
    final price = Fmt.toDouble(ln['unit_price']);
    final total = Fmt.toDouble(
      ln['line_gross'] ?? ln['line_total'] ?? (qty * price),
    );
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: AppCard(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              Fmt.str(ln['item_name']).isEmpty ? '—' : Fmt.str(ln['item_name']),
              style: const TextStyle(fontWeight: FontWeight.w800),
            ),
            const SizedBox(height: 4),
            Text(
              [
                if (Fmt.str(ln['unit_name']).isNotEmpty)
                  Fmt.str(ln['unit_name']),
                'كمية ${Fmt.trimNum(qty)}',
                if (Fmt.toDouble(ln['qty_extra']) > 0)
                  'إضافية ${Fmt.trimNum(Fmt.toDouble(ln['qty_extra']))}',
                Fmt.money(price),
              ].join(' • '),
              style: const TextStyle(
                fontSize: 12.5,
                color: AppTheme.textSoft,
              ),
            ),
            const SizedBox(height: 6),
            Align(
              alignment: Alignment.centerLeft,
              child: Text(
                Fmt.money(total),
                textDirection: TextDirection.ltr,
                style: const TextStyle(
                  fontWeight: FontWeight.w800,
                  color: AppTheme.rose,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// اختيار بنود وكميات المرتجع الجزئي من طلب معتمد.
class _ReturnLinesPickerSheet extends StatefulWidget {
  const _ReturnLinesPickerSheet({required this.orderId});
  final int orderId;

  @override
  State<_ReturnLinesPickerSheet> createState() =>
      _ReturnLinesPickerSheetState();
}

class _ReturnLineEdit {
  _ReturnLineEdit(this.source) {
    maxQty = Fmt.toDouble(source['qty']);
    qty = maxQty;
    selected = true;
  }

  final Map<String, dynamic> source;
  double maxQty = 0;
  double qty = 0;
  bool selected = true;

  Map<String, dynamic>? toPayload() {
    if (!selected || qty <= 0) return null;
    final out = Map<String, dynamic>.from(source);
    out['qty'] = qty;
    out['order_line_id'] = Fmt.toInt(source['id']);
    final unitPrice = Fmt.toDouble(source['unit_price']);
    final disc = Fmt.toDouble(source['discount_pct']);
    final lineTotal = qty * unitPrice * (1 - disc / 100);
    out['line_total'] = lineTotal;
    out['line_gross'] = lineTotal;
    return out;
  }
}

class _ReturnLinesPickerSheetState extends State<_ReturnLinesPickerSheet> {
  bool _loading = true;
  String? _error;
  final _lines = <_ReturnLineEdit>[];

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
    try {
      final res = await context.read<ApiClient>().getJson(
            AppConfig.customerOrderViewPath,
            query: {'id': widget.orderId},
          );
      if (!mounted) return;
      final order = (res['order'] as Map?)?.cast<String, dynamic>() ?? {};
      final raw = (order['lines'] as List? ?? order['items'] as List? ?? []);
      setState(() {
        _lines
          ..clear()
          ..addAll(
            raw
                .whereType<Map>()
                .map((e) => _ReturnLineEdit(e.cast<String, dynamic>())),
          );
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

  void _confirm() {
    final payload = _lines
        .map((l) => l.toPayload())
        .whereType<Map<String, dynamic>>()
        .toList();
    if (payload.isEmpty) {
      showSnack(context, 'حدد مادة واحدةً واحدةً واحداً على الأقل.', error: true);
      return;
    }
    Navigator.pop(context, payload);
  }

  @override
  Widget build(BuildContext context) {
    final h = MediaQuery.sizeOf(context).height * 0.78;
    return SizedBox(
      height: h,
      child: Column(
        children: [
          const SizedBox(height: 10),
          Container(
            width: 40,
            height: 4,
            decoration: BoxDecoration(
              color: const Color(0xFFE2E8F0),
              borderRadius: BorderRadius.circular(99),
            ),
          ),
          const Padding(
            padding: EdgeInsets.fromLTRB(16, 14, 16, 8),
            child: Align(
              alignment: Alignment.centerRight,
              child: Text(
                'اختر المواد والكميات للإرجاع',
                style: TextStyle(fontSize: 16, fontWeight: FontWeight.w800),
              ),
            ),
          ),
          Expanded(
            child: AsyncView(
              loading: _loading,
              error: _error,
              onRetry: _load,
              child: _lines.isEmpty
                  ? const EmptyState(message: 'لا بنود في الطلب.')
                  : ListView.builder(
                      padding: const EdgeInsets.fromLTRB(12, 0, 12, 12),
                      itemCount: _lines.length,
                      itemBuilder: (_, i) {
                        final l = _lines[i];
                        return AppCard(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.stretch,
                            children: [
                              CheckboxListTile(
                                contentPadding: EdgeInsets.zero,
                                value: l.selected,
                                onChanged: (v) => setState(
                                    () => l.selected = v ?? false),
                                title: Text(
                                  Fmt.str(l.source['item_name']),
                                  style: const TextStyle(
                                    fontWeight: FontWeight.w800,
                                  ),
                                ),
                                subtitle: Text(
                                  'الحد الأقصى: ${Fmt.trimNum(l.maxQty)}',
                                  style: const TextStyle(
                                    color: AppTheme.textSoft,
                                  ),
                                ),
                              ),
                              if (l.selected)
                                Row(
                                  children: [
                                    const Text('الكمية: '),
                                    IconButton(
                                      onPressed: () => setState(() {
                                        l.qty = (l.qty - 1).clamp(0, l.maxQty);
                                      }),
                                      icon: const Icon(Icons.remove_circle_outline),
                                    ),
                                    Text(
                                      Fmt.trimNum(l.qty),
                                      style: const TextStyle(
                                        fontWeight: FontWeight.w800,
                                        fontSize: 16,
                                      ),
                                    ),
                                    IconButton(
                                      onPressed: () => setState(() {
                                        l.qty = (l.qty + 1).clamp(0, l.maxQty);
                                      }),
                                      icon: const Icon(Icons.add_circle_outline),
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
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 0, 14, 16),
            child: SizedBox(
              width: double.infinity,
              child: FilledButton.icon(
                onPressed: _loading ? null : _confirm,
                icon: const Icon(Icons.check_rounded),
                label: const Text('اعتماد المواد المحددة'),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
