import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/async_view.dart';
import '../../widgets/ui_kit.dart';

class InvoiceListScreen extends StatefulWidget {
  const InvoiceListScreen({super.key, this.embedded = false});

  /// عند العرض داخل شريط التنقّل السفلي لا نُظهر زر الرجوع.
  final bool embedded;

  @override
  State<InvoiceListScreen> createState() => _InvoiceListScreenState();
}

class _InvoiceListScreenState extends State<InvoiceListScreen> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _rows = [];
  String _filter = 'all';
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
      final res = await context.read<ApiClient>().getJson(
        AppConfig.salesInvoiceListPath,
        query: {'filter': _filter, 'q': _search.text.trim()},
      );
      if (!mounted) return;
      setState(() {
        _rows = (res['invoices'] as List? ?? [])
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

  Future<void> _post(Map<String, dynamic> row) async {
    final s = context.read<SessionController>();
    try {
      final res = await context.read<ApiClient>().postForm(
        AppConfig.salesInvoicePostPath,
        fields: {'invoice_id': row['id']},
        csrf: s.csrf,
      );
      if (!mounted) return;
      showSnack(context, (res['message'] ?? 'تم الترحيل').toString());
      await _load();
    } on ApiException catch (e) {
      if (!mounted) return;
      showSnack(context, e.message, error: true);
    }
  }

  Future<void> _delete(Map<String, dynamic> row) async {
    final ok = await _confirm(
      'حذف الفاتورة',
      'سيتم حذف الفاتورة رقم ${row['invoice_no'] ?? ''} نهائياً.',
    );
    if (!ok || !mounted) return;
    final s = context.read<SessionController>();
    try {
      final res = await context.read<ApiClient>().postForm(
        AppConfig.salesInvoiceDeletePath,
        fields: {'invoice_id': row['id']},
        csrf: s.csrf,
      );
      if (!mounted) return;
      showSnack(context, (res['message'] ?? 'تم الحذف').toString());
      await _load();
    } on ApiException catch (e) {
      if (!mounted) return;
      showSnack(context, e.message, error: true);
    }
  }

  Future<bool> _confirm(String title, String body) async {
    final r = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(title),
        content: Text(body),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            style: FilledButton.styleFrom(
              backgroundColor: AppTheme.danger,
              minimumSize: const Size(100, 42),
            ),
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('تأكيد'),
          ),
        ],
      ),
    );
    return r == true;
  }

  @override
  Widget build(BuildContext context) {
    final canDelete = context.read<SessionController>().can('m_sales_invoices');
    return Scaffold(
      backgroundColor: const Color(0xFFF3F5F9),
      appBar: AppBar(
        title: const Text('فواتير المبيعات'),
        automaticallyImplyLeading: !widget.embedded,
        actions: [
          IconButton(
            tooltip: 'تحديث',
            onPressed: _load,
            icon: const Icon(Icons.refresh_rounded),
          ),
        ],
      ),
      body: Column(
        children: [
          Container(
            width: double.infinity,
            color: Colors.white,
            padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                  decoration: BoxDecoration(
                    color: const Color(0xFFE8F4FC),
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: const Row(
                    children: [
                      Text(
                        'قائمة',
                        style: TextStyle(
                          fontSize: 12,
                          fontWeight: FontWeight.w800,
                          color: Color(0xFF0572CE),
                        ),
                      ),
                      SizedBox(width: 10),
                      Expanded(
                        child: Text(
                          'اختر فاتورة ثم طباعة',
                          style: TextStyle(
                            fontSize: 12,
                            fontWeight: FontWeight.w600,
                            color: Color(0xFF64748B),
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 10),
                SizedBox(
                  height: 46,
                  child: DecoratedBox(
                    decoration: BoxDecoration(
                      borderRadius: BorderRadius.circular(12),
                      gradient: const LinearGradient(
                        begin: Alignment.centerRight,
                        end: Alignment.centerLeft,
                        colors: [
                          Color(0xFF1A8FE8),
                          Color(0xFF0572CE),
                          Color(0xFF024D8F),
                        ],
                      ),
                      boxShadow: [
                        BoxShadow(
                          color:
                              const Color(0xFF0572CE).withValues(alpha: 0.28),
                          blurRadius: 12,
                          offset: const Offset(0, 3),
                        ),
                      ],
                    ),
                    child: Material(
                      color: Colors.transparent,
                      child: InkWell(
                        onTap: () =>
                            context.push('/invoices/new').then((_) => _load()),
                        borderRadius: BorderRadius.circular(12),
                        child: const Center(
                          child: Text(
                            '+ فاتورة جديدة',
                            style: TextStyle(
                              color: Colors.white,
                              fontSize: 15,
                              fontWeight: FontWeight.w800,
                            ),
                          ),
                        ),
                      ),
                    ),
                  ),
                ),
                const SizedBox(height: 12),
                _FilterSeg(
                  value: _filter,
                  onChanged: (v) {
                    setState(() => _filter = v);
                    _load();
                  },
                ),
                const SizedBox(height: 10),
                Row(
                  children: [
                    Expanded(
                      child: TextField(
                        controller: _search,
                        textInputAction: TextInputAction.search,
                        decoration: InputDecoration(
                          hintText: 'رقم الفاتورة أو اسم العميل...',
                          prefixIcon:
                              const Icon(Icons.search_rounded, size: 20),
                          filled: true,
                          fillColor: const Color(0xFFF8FAFC),
                          isDense: true,
                          border: OutlineInputBorder(
                            borderRadius: BorderRadius.circular(10),
                            borderSide:
                                const BorderSide(color: Color(0xFFE2E8F0)),
                          ),
                          enabledBorder: OutlineInputBorder(
                            borderRadius: BorderRadius.circular(10),
                            borderSide:
                                const BorderSide(color: Color(0xFFE2E8F0)),
                          ),
                        ),
                        onSubmitted: (_) => _load(),
                      ),
                    ),
                    const SizedBox(width: 8),
                    SizedBox(
                      height: 44,
                      child: FilledButton(
                        onPressed: _load,
                        style: FilledButton.styleFrom(
                          backgroundColor: const Color(0xFF0572CE),
                          shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(10),
                          ),
                        ),
                        child: const Text('بحث'),
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
          const Divider(height: 1),
          Expanded(
            child: RefreshIndicator(
              onRefresh: _load,
              child: AsyncView(
                loading: _loading,
                error: _error,
                onRetry: _load,
                child: _rows.isEmpty
                    ? ListView(
                        children: [
                          const SizedBox(height: 60),
                          EmptyState(
                            message: 'لا توجد فواتير مطابقة.',
                            icon: Icons.receipt_long_rounded,
                            actionLabel: 'فاتورة جديدة',
                            onAction: () => context
                                .push('/invoices/new')
                                .then((_) => _load()),
                          ),
                        ],
                      )
                    : ListView.builder(
                        padding: const EdgeInsets.fromLTRB(12, 10, 12, 24),
                        itemCount: _rows.length,
                        itemBuilder: (_, i) => _InvoiceStrip(
                          row: _rows[i],
                          even: i.isEven,
                          canDelete: canDelete,
                          onTap: () => context
                              .push('/invoices/${_rows[i]['id']}')
                              .then((_) => _load()),
                          onPost: () => _post(_rows[i]),
                          onDelete: () => _delete(_rows[i]),
                        ),
                      ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _FilterSeg extends StatelessWidget {
  const _FilterSeg({required this.value, required this.onChanged});

  final String value;
  final ValueChanged<String> onChanged;

  @override
  Widget build(BuildContext context) {
    const items = {
      'all': 'الكل',
      'unposted': 'غير مرحّلة',
      'posted': 'مرحّلة',
    };
    return Container(
      padding: const EdgeInsets.all(3),
      decoration: BoxDecoration(
        color: const Color(0xFFF1F5F9),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: const Color(0xFFE2E8F0)),
      ),
      child: Row(
        children: items.entries.map((e) {
          final sel = e.key == value;
          return Expanded(
            child: Material(
              color: sel ? const Color(0xFF0572CE) : Colors.transparent,
              borderRadius: BorderRadius.circular(9),
              child: InkWell(
                onTap: () => onChanged(e.key),
                borderRadius: BorderRadius.circular(9),
                child: Padding(
                  padding: const EdgeInsets.symmetric(vertical: 9),
                  child: Text(
                    e.value,
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      fontSize: 12.5,
                      fontWeight: FontWeight.w700,
                      color: sel ? Colors.white : const Color(0xFF475569),
                    ),
                  ),
                ),
              ),
            ),
          );
        }).toList(),
      ),
    );
  }
}

class _InvoiceStrip extends StatelessWidget {
  const _InvoiceStrip({
    required this.row,
    required this.even,
    required this.canDelete,
    required this.onTap,
    required this.onPost,
    required this.onDelete,
  });

  final Map<String, dynamic> row;
  final bool even;
  final bool canDelete;
  final VoidCallback onTap;
  final VoidCallback onPost;
  final VoidCallback onDelete;

  @override
  Widget build(BuildContext context) {
    final posted = row['is_posted'] == true;
    final rep = (row['sales_rep_name'] ?? '').toString().trim();
    return Material(
      color: even ? Colors.white : const Color(0xFFF7F9FB),
      child: InkWell(
        onTap: onTap,
        child: Container(
          padding: const EdgeInsets.fromLTRB(12, 12, 10, 10),
          decoration: const BoxDecoration(
            border: Border(
              bottom: BorderSide(color: Color(0xFFE2E8F0)),
            ),
          ),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                width: 40,
                height: 40,
                decoration: BoxDecoration(
                  color: posted
                      ? const Color(0xFF13A05C).withValues(alpha: 0.12)
                      : const Color(0xFFE08700).withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Icon(
                  Icons.receipt_long_rounded,
                  size: 20,
                  color: posted ? AppTheme.success : AppTheme.warn,
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: Text(
                            '#${row['invoice_no'] ?? '—'}',
                            style: const TextStyle(
                              fontSize: 13,
                              fontWeight: FontWeight.w800,
                              color: Color(0xFF0F172A),
                            ),
                          ),
                        ),
                        StatusPill(
                          text: posted ? 'مرحّلة' : 'غير مرحّلة',
                          color: posted ? AppTheme.success : AppTheme.warn,
                        ),
                      ],
                    ),
                    const SizedBox(height: 4),
                    Text(
                      (row['customer_name'] ?? '—').toString(),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        fontSize: 14,
                        fontWeight: FontWeight.w700,
                        color: Color(0xFF0F172A),
                      ),
                    ),
                    if (rep.isNotEmpty) ...[
                      const SizedBox(height: 2),
                      Text(
                        'المندوب: $rep',
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          fontSize: 12,
                          color: Color(0xFF64748B),
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    ],
                    const SizedBox(height: 4),
                    Text(
                      '${row['invoice_date_dmy'] ?? ''}'
                      '${(row['payment_label'] ?? '').toString().isNotEmpty ? '  •  ${row['payment_label']}' : ''}',
                      textDirection: TextDirection.ltr,
                      style: const TextStyle(
                        fontSize: 12,
                        color: Color(0xFF64748B),
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 8),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text(
                    (row['total_fmt'] ?? '0').toString(),
                    textDirection: TextDirection.ltr,
                    style: const TextStyle(
                      fontSize: 15,
                      fontWeight: FontWeight.w800,
                      color: Color(0xFF024D8F),
                    ),
                  ),
                  const SizedBox(height: 4),
                  Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      if (!posted)
                        IconButton(
                          tooltip: 'ترحيل',
                          visualDensity: VisualDensity.compact,
                          onPressed: onPost,
                          icon: const Icon(
                            Icons.check_circle_outline_rounded,
                            size: 18,
                          ),
                          color: AppTheme.success,
                        ),
                      if (!posted && canDelete)
                        IconButton(
                          tooltip: 'حذف',
                          visualDensity: VisualDensity.compact,
                          onPressed: onDelete,
                          icon: const Icon(
                            Icons.delete_outline_rounded,
                            size: 18,
                          ),
                          color: AppTheme.danger,
                        ),
                    ],
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}
