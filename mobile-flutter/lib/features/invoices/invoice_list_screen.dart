import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../services/invoice_print_helper.dart';
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
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => context.push('/invoices/new').then((_) => _load()),
        icon: const Icon(Icons.add_rounded, size: 20),
        label: const Text('فاتورة جديدة'),
      ),
      body: Column(
        children: [
          Container(
            color: AppTheme.surface,
            padding: const EdgeInsets.fromLTRB(14, 12, 14, 10),
            child: Column(
              children: [
                TextField(
                  controller: _search,
                  textInputAction: TextInputAction.search,
                  decoration: InputDecoration(
                    hintText: 'بحث برقم الفاتورة أو العميل...',
                    prefixIcon: const Icon(Icons.search_rounded, size: 20),
                    suffixIcon: _search.text.isEmpty
                        ? null
                        : IconButton(
                            icon: const Icon(Icons.close_rounded, size: 18),
                            onPressed: () {
                              _search.clear();
                              _load();
                            },
                          ),
                  ),
                  onChanged: (_) => setState(() {}),
                  onSubmitted: (_) => _load(),
                ),
                const SizedBox(height: 10),
                _FilterChips(
                  value: _filter,
                  onChanged: (v) {
                    setState(() => _filter = v);
                    _load();
                  },
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
                        padding: const EdgeInsets.fromLTRB(14, 12, 14, 90),
                        itemCount: _rows.length,
                        itemBuilder: (_, i) => _InvoiceCard(
                          row: _rows[i],
                          canDelete: canDelete,
                          onTap: () => context
                              .push('/invoices/${_rows[i]['id']}')
                              .then((_) => _load()),
                          onPost: () => _post(_rows[i]),
                          onDelete: () => _delete(_rows[i]),
                          onPdf: () => InvoicePrintHelper.openPdf(
                            context,
                            invoiceId: (_rows[i]['id'] as num).toInt(),
                            invoiceNo: (_rows[i]['invoice_no'] ?? '').toString(),
                          ),
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

class _FilterChips extends StatelessWidget {
  const _FilterChips({required this.value, required this.onChanged});

  final String value;
  final ValueChanged<String> onChanged;

  @override
  Widget build(BuildContext context) {
    const items = {
      'all': 'الكل',
      'unposted': 'غير مرحّلة',
      'posted': 'مرحّلة',
    };
    return SizedBox(
      height: 36,
      child: ListView(
        scrollDirection: Axis.horizontal,
        children: items.entries.map((e) {
          final sel = e.key == value;
          return Padding(
            padding: const EdgeInsets.only(left: 8),
            child: ChoiceChip(
              label: Text(e.value),
              selected: sel,
              onSelected: (_) => onChanged(e.key),
            ),
          );
        }).toList(),
      ),
    );
  }
}

class _InvoiceCard extends StatelessWidget {
  const _InvoiceCard({
    required this.row,
    required this.canDelete,
    required this.onTap,
    required this.onPost,
    required this.onDelete,
    required this.onPdf,
  });

  final Map<String, dynamic> row;
  final bool canDelete;
  final VoidCallback onTap;
  final VoidCallback onPost;
  final VoidCallback onDelete;
  final VoidCallback onPdf;

  @override
  Widget build(BuildContext context) {
    final posted = row['is_posted'] == true;
    return AppCard(
      onTap: onTap,
      padding: const EdgeInsets.fromLTRB(12, 12, 6, 8),
      child: Column(
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              MiniIcon(
                Icons.receipt_long_rounded,
                color: posted ? AppTheme.success : AppTheme.warn,
              ),
              const SizedBox(width: 11),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      (row['customer_name'] ?? '—').toString(),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        fontSize: 14.5,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    if ((row['sales_rep_name'] ?? '').toString().trim().isNotEmpty) ...[
                      const SizedBox(height: 2),
                      Text(
                        'المندوب: ${(row['sales_rep_name'] ?? '').toString().trim()}',
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          fontSize: 12,
                          color: AppTheme.textSoft,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    ],
                    const SizedBox(height: 4),
                    Row(
                      children: [
                        Text(
                          '#${row['invoice_no'] ?? '—'}',
                          style: const TextStyle(
                            fontSize: 12,
                            color: AppTheme.textSoft,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                        const SizedBox(width: 8),
                        const Text('•',
                            style: TextStyle(color: AppTheme.textSoft)),
                        const SizedBox(width: 8),
                        Text(
                          (row['invoice_date_dmy'] ?? '').toString(),
                          textDirection: TextDirection.ltr,
                          style: const TextStyle(
                            fontSize: 12,
                            color: AppTheme.textSoft,
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Padding(
                    padding: const EdgeInsets.only(left: 6),
                    child: Text(
                      (row['total_fmt'] ?? '0').toString(),
                      textDirection: TextDirection.ltr,
                      style: const TextStyle(
                        fontSize: 15,
                        fontWeight: FontWeight.w800,
                        color: AppTheme.primary,
                      ),
                    ),
                  ),
                  const SizedBox(height: 5),
                  Padding(
                    padding: const EdgeInsets.only(left: 6),
                    child: StatusPill(
                      text: posted ? 'مرحّلة' : 'غير مرحّلة',
                      color: posted ? AppTheme.success : AppTheme.warn,
                    ),
                  ),
                ],
              ),
            ],
          ),
          const SizedBox(height: 4),
          Row(
            children: [
              if ((row['payment_label'] ?? '').toString().isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(right: 4),
                  child: Text(
                    row['payment_label'].toString(),
                    style: const TextStyle(
                      fontSize: 11.5,
                      color: AppTheme.textSoft,
                    ),
                  ),
                ),
              const Spacer(),
              IconButton(
                tooltip: 'عرض',
                visualDensity: VisualDensity.compact,
                onPressed: onTap,
                icon: const Icon(Icons.visibility_outlined, size: 19),
                color: AppTheme.textSoft,
              ),
              IconButton(
                tooltip: 'طباعة PDF',
                visualDensity: VisualDensity.compact,
                onPressed: onPdf,
                icon: const Icon(Icons.picture_as_pdf_outlined, size: 19),
                color: AppTheme.textSoft,
              ),
              if (!posted)
                IconButton(
                  tooltip: 'ترحيل',
                  visualDensity: VisualDensity.compact,
                  onPressed: onPost,
                  icon: const Icon(Icons.check_circle_outline_rounded, size: 19),
                  color: AppTheme.success,
                ),
              if (!posted && canDelete)
                IconButton(
                  tooltip: 'حذف',
                  visualDensity: VisualDensity.compact,
                  onPressed: onDelete,
                  icon: const Icon(Icons.delete_outline_rounded, size: 19),
                  color: AppTheme.danger,
                ),
            ],
          ),
        ],
      ),
    );
  }
}
