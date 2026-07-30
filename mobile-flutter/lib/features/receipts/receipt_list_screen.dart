import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../services/document_print_helper.dart';
import '../../widgets/async_view.dart';
import '../../widgets/ui_kit.dart';

class ReceiptListScreen extends StatefulWidget {
  const ReceiptListScreen({super.key, this.embedded = false});

  final bool embedded;

  @override
  State<ReceiptListScreen> createState() => _ReceiptListScreenState();
}

class _ReceiptListScreenState extends State<ReceiptListScreen> {
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
        AppConfig.receiptListPath,
        query: {'filter': _filter, 'q': _search.text.trim()},
      );
      if (!mounted) return;
      setState(() {
        _rows = (res['receipts'] as List? ?? [])
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
        AppConfig.receiptPostPath,
        fields: {'voucher_id': row['id']},
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
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('حذف السند'),
        content: Text('سيتم حذف السند رقم ${row['voucher_no'] ?? ''} نهائياً.'),
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
            child: const Text('حذف'),
          ),
        ],
      ),
    );
    if (ok != true || !mounted) return;
    final s = context.read<SessionController>();
    try {
      final res = await context.read<ApiClient>().postForm(
        AppConfig.receiptDeletePath,
        fields: {'voucher_id': row['id']},
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

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('سندات القبض'),
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
        onPressed: () => context.push('/receipts/new').then((_) => _load()),
        icon: const Icon(Icons.add_rounded, size: 20),
        label: const Text('سند جديد'),
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
                    hintText: 'بحث برقم السند أو العميل...',
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
                SizedBox(
                  height: 36,
                  child: ListView(
                    scrollDirection: Axis.horizontal,
                    children: const {
                      'all': 'الكل',
                      'unposted': 'غير مرحّلة',
                      'posted': 'مرحّلة',
                    }.entries.map((e) {
                      return Padding(
                        padding: const EdgeInsets.only(left: 8),
                        child: ChoiceChip(
                          label: Text(e.value),
                          selected: e.key == _filter,
                          onSelected: (_) {
                            setState(() => _filter = e.key);
                            _load();
                          },
                        ),
                      );
                    }).toList(),
                  ),
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
                            message: 'لا توجد سندات.',
                            icon: Icons.account_balance_wallet_rounded,
                            actionLabel: 'سند جديد',
                            onAction: () => context
                                .push('/receipts/new')
                                .then((_) => _load()),
                          ),
                        ],
                      )
                    : ListView.builder(
                        padding: const EdgeInsets.fromLTRB(14, 12, 14, 90),
                        itemCount: _rows.length,
                        itemBuilder: (_, i) {
                          final row = _rows[i];
                          final id = int.tryParse('${row['id']}') ?? 0;
                          final no = (row['voucher_no'] ?? id).toString();
                          return _ReceiptCard(
                            row: row,
                            onPost: () => _post(row),
                            onDelete: () => _delete(row),
                            onPrint: () {
                              if (id < 1) return;
                              DocumentPrintHelper.printFromApi(
                                context,
                                apiPath: AppConfig.receiptPdfPath,
                                query: {'id': id},
                                jobName: 'سند_$no',
                              );
                            },
                            onPdf: () {
                              if (id < 1) return;
                              DocumentPrintHelper.openPdfFromApi(
                                context,
                                apiPath: AppConfig.receiptPdfPath,
                                query: {'id': id},
                                title: 'سند قبض $no',
                                fileName: 'سند_$no.pdf',
                              );
                            },
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

class _ReceiptCard extends StatelessWidget {
  const _ReceiptCard({
    required this.row,
    required this.onPost,
    required this.onDelete,
    required this.onPrint,
    required this.onPdf,
  });

  final Map<String, dynamic> row;
  final VoidCallback onPost;
  final VoidCallback onDelete;
  final VoidCallback onPrint;
  final VoidCallback onPdf;

  @override
  Widget build(BuildContext context) {
    final posted = row['is_posted'] == true;
    return AppCard(
      padding: const EdgeInsets.fromLTRB(12, 12, 6, 8),
      child: Column(
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              MiniIcon(
                Icons.payments_rounded,
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
                    const SizedBox(height: 4),
                    Text(
                      '#${row['voucher_no'] ?? '—'}  •  '
                      '${row['voucher_date_dmy'] ?? ''}',
                      textDirection: TextDirection.ltr,
                      style: const TextStyle(
                        fontSize: 12,
                        color: AppTheme.textSoft,
                      ),
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
                      (row['amount_fmt'] ?? '0').toString(),
                      textDirection: TextDirection.ltr,
                      style: const TextStyle(
                        fontSize: 15,
                        fontWeight: FontWeight.w800,
                        color: AppTheme.success,
                      ),
                    ),
                  ),
                  const SizedBox(height: 5),
                  Padding(
                    padding: const EdgeInsets.only(left: 6),
                    child: StatusPill(
                      text: posted ? 'مرحّل' : 'غير مرحّل',
                      color: posted ? AppTheme.success : AppTheme.warn,
                    ),
                  ),
                ],
              ),
            ],
          ),
          Row(
            children: [
              if ((row['pay_label'] ?? '').toString().isNotEmpty)
                Text(
                  row['pay_label'].toString(),
                  style: const TextStyle(
                    fontSize: 11.5,
                    color: AppTheme.textSoft,
                  ),
                ),
              const Spacer(),
              IconButton(
                tooltip: 'PDF (A4)',
                visualDensity: VisualDensity.compact,
                onPressed: onPdf,
                icon: const Icon(Icons.picture_as_pdf_outlined, size: 19),
                color: AppTheme.rose,
              ),
              IconButton(
                tooltip: 'طباعة Bluetooth',
                visualDensity: VisualDensity.compact,
                onPressed: onPrint,
                icon: const Icon(Icons.print_outlined, size: 19),
                color: AppTheme.primary,
              ),
              if (!posted)
                IconButton(
                  tooltip: 'ترحيل',
                  visualDensity: VisualDensity.compact,
                  onPressed: onPost,
                  icon: const Icon(Icons.check_circle_outline_rounded, size: 19),
                  color: AppTheme.success,
                ),
              if (!posted)
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
