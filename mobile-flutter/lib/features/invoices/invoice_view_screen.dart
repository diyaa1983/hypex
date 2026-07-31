import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../services/invoice_print_helper.dart';
import '../../services/location_service.dart';
import '../../widgets/async_view.dart';
import '../../widgets/ui_kit.dart';

class InvoiceViewScreen extends StatefulWidget {
  const InvoiceViewScreen({super.key, required this.invoiceId});

  final int invoiceId;

  @override
  State<InvoiceViewScreen> createState() => _InvoiceViewScreenState();
}

class _InvoiceViewScreenState extends State<InvoiceViewScreen> {
  bool _loading = true;
  bool _posting = false;
  bool _printBusy = false;
  bool _deleting = false;
  String? _error;
  Map<String, dynamic> _inv = {};

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
        AppConfig.salesInvoiceViewPath,
        query: {'id': widget.invoiceId},
      );
      if (!mounted) return;
      setState(() {
        _inv = (res['invoice'] as Map?)?.cast<String, dynamic>() ?? {};
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

  List<Map<String, dynamic>> get _lines {
    final l = _inv['lines'] ?? _inv['items'] ?? _inv['rows'];
    if (l is List) {
      return l.whereType<Map>().map((e) => e.cast<String, dynamic>()).toList();
    }
    return [];
  }

  Future<void> _printBluetooth() async {
    if (_printBusy) return;
    setState(() => _printBusy = true);
    try {
      final inv = Map<String, dynamic>.from(_inv);
      inv['id'] = widget.invoiceId;
      await InvoicePrintHelper.printBluetooth(context, invoice: inv);
    } finally {
      if (mounted) setState(() => _printBusy = false);
    }
  }

  Future<void> _post() async {
    final s = context.read<SessionController>();
    final api = context.read<ApiClient>();
    setState(() => _posting = true);
    final gps = await LocationService.tryGetPosition();
    try {
      final fields = <String, dynamic>{'invoice_id': widget.invoiceId};
      if (gps != null) {
        fields['latitude'] = gps.latitude;
        fields['longitude'] = gps.longitude;
        fields['gps_accuracy'] = gps.accuracy;
        fields['gps_source'] = 'mobile';
      }
      final res = await api.postForm(
        AppConfig.salesInvoicePostPath,
        fields: fields,
        csrf: s.csrf,
      );
      if (!mounted) return;
      showSnack(context, (res['message'] ?? 'تم الترحيل').toString());
      await _load();
    } on ApiException catch (e) {
      if (!mounted) return;
      showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _posting = false);
    }
  }

  Future<void> _delete() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('حذف الفاتورة'),
        content: Text(
          'سيتم حذف الفاتورة رقم ${Fmt.str(_inv['invoice_no'])} نهائياً.',
        ),
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

    setState(() => _deleting = true);
    final s = context.read<SessionController>();
    try {
      final res = await context.read<ApiClient>().postForm(
        AppConfig.salesInvoiceDeletePath,
        fields: {'invoice_id': widget.invoiceId},
        csrf: s.csrf,
      );
      if (!mounted) return;
      showSnack(context, (res['message'] ?? 'تم الحذف').toString());
      context.pop();
    } on ApiException catch (e) {
      if (!mounted) return;
      showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _deleting = false);
    }
  }

  Future<void> _openMap() async {
    final lat = Fmt.toDouble(_inv['latitude'] ?? _inv['gps_latitude']);
    final lng = Fmt.toDouble(_inv['longitude'] ?? _inv['gps_longitude']);
    if (lat == 0 && lng == 0) {
      showSnack(context, 'لا يوجد موقع مسجّل لهذه الفاتورة.', error: true);
      return;
    }
    final uri = Uri.parse('https://maps.google.com/?q=$lat,$lng');
    if (!await launchUrl(uri, mode: LaunchMode.externalApplication)) {
      if (!mounted) return;
      showSnack(context, 'تعذر فتح الخريطة.', error: true);
    }
  }

  void _copySummary() {
    final text = 'فاتورة ${Fmt.str(_inv['invoice_no'])}\n'
        'التاريخ: ${Fmt.dmy(Fmt.str(_inv['invoice_date']))}\n'
        'العميل: ${Fmt.str(_inv['customer_name'])}\n'
        'الإجمالي: ${Fmt.money(Fmt.toDouble(_inv['total']))}';
    Clipboard.setData(ClipboardData(text: text));
    showSnack(context, 'تم نسخ ملخص الفاتورة.');
  }

  @override
  Widget build(BuildContext context) {
    final posted = _inv['is_posted'] == true;
    final canDelete =
        context.read<SessionController>().can('m_sales_invoices') && !posted;

    return Scaffold(
      appBar: AppBar(
        title: const Text('تفاصيل الفاتورة'),
        actions: [
          IconButton(
            tooltip: 'تحديث',
            onPressed: _loading ? null : _load,
            icon: const Icon(Icons.refresh_rounded),
          ),
          PopupMenuButton<String>(
            tooltip: 'خيارات',
            icon: const Icon(Icons.more_vert_rounded),
            onSelected: (v) {
              switch (v) {
                case 'print':
                  _printBluetooth();
                case 'copy':
                  _copySummary();
                case 'map':
                  _openMap();
                case 'delete':
                  _delete();
              }
            },
            itemBuilder: (_) => [
              const PopupMenuItem(
                value: 'print',
                child: _MenuRow(Icons.print_outlined, 'طباعة Bluetooth'),
              ),
              const PopupMenuItem(
                value: 'copy',
                child: _MenuRow(Icons.copy_rounded, 'نسخ الملخّص'),
              ),
              const PopupMenuItem(
                value: 'map',
                child: _MenuRow(Icons.map_outlined, 'موقع الفاتورة'),
              ),
              if (canDelete)
                const PopupMenuItem(
                  value: 'delete',
                  child: _MenuRow(
                    Icons.delete_outline_rounded,
                    'حذف الفاتورة',
                    color: AppTheme.danger,
                  ),
                ),
            ],
          ),
        ],
      ),
      body: AsyncView(
        loading: _loading,
        error: _error,
        onRetry: _load,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(14, 14, 14, 24),
          children: [
            _SummaryCard(inv: _inv, posted: posted),
            const SizedBox(height: 4),
            Row(
              children: [
                Expanded(
                  child: ActionChipButton(
                    icon: Icons.print_outlined,
                    label: 'طباعة',
                    busy: _printBusy,
                    onTap: _printBluetooth,
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: ActionChipButton(
                    icon: Icons.map_outlined,
                    label: 'الموقع',
                    color: AppTheme.teal,
                    onTap: _openMap,
                  ),
                ),
              ],
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
      bottomNavigationBar: _loading
          ? null
          : SafeArea(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(14, 8, 14, 12),
                child: Row(
                  children: [
                    if (canDelete) ...[
                      SizedBox(
                        width: 52,
                        height: 50,
                        child: OutlinedButton(
                          onPressed: _deleting ? null : _delete,
                          style: OutlinedButton.styleFrom(
                            padding: EdgeInsets.zero,
                            foregroundColor: AppTheme.danger,
                            side: const BorderSide(color: Color(0xFFF3C9C6)),
                          ),
                          child: _deleting
                              ? const SizedBox(
                                  width: 18,
                                  height: 18,
                                  child: CircularProgressIndicator(
                                    strokeWidth: 2,
                                  ),
                                )
                              : const Icon(Icons.delete_outline_rounded),
                        ),
                      ),
                      const SizedBox(width: 8),
                    ],
                    Expanded(
                      child: posted
                          ? OutlinedButton.icon(
                              onPressed: _printBusy ? null : _printBluetooth,
                              icon: const Icon(Icons.print_outlined, size: 19),
                              label: const Text('طباعة Bluetooth'),
                            )
                          : FilledButton.icon(
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
                                  : const Icon(
                                      Icons.check_circle_outline_rounded,
                                      size: 19,
                                    ),
                              label: const Text('ترحيل الفاتورة'),
                            ),
                    ),
                  ],
                ),
              ),
            ),
    );
  }

  Widget _lineCard(Map<String, dynamic> l) {
    final name = Fmt.str(l['item_name'] ?? l['name_ar'] ?? l['name']);
    final qty = Fmt.toDouble(l['qty'] ?? l['quantity']);
    final price = Fmt.toDouble(l['unit_price'] ?? l['price']);
    final disc = Fmt.toDouble(l['discount_amount']);
    final tax = Fmt.toDouble(l['tax_amount']);
    final taxPct = Fmt.toDouble(l['tax_rate_percent']);
    final total = Fmt.toDouble(
      l['line_gross'] ?? l['line_total'] ?? l['total'] ?? (qty * price),
    );
    final discInput = Fmt.str(l['line_discount_input']);

    return AppCard(
      padding: const EdgeInsets.all(12),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const MiniIcon(Icons.inventory_2_outlined, color: AppTheme.violet),
          const SizedBox(width: 11),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  name,
                  style: const TextStyle(
                    fontSize: 14,
                    fontWeight: FontWeight.w700,
                  ),
                ),
                const SizedBox(height: 6),
                Wrap(
                  spacing: 6,
                  runSpacing: 6,
                  children: [
                    _chip('${Fmt.money(qty)} × ${Fmt.money(price)}'),
                    if (disc > 0 || discInput.isNotEmpty)
                      _chip(
                        'خصم ${discInput.isNotEmpty ? discInput : Fmt.money(disc)}',
                        color: AppTheme.rose,
                      ),
                    if (tax > 0 || taxPct > 0)
                      _chip(
                        'ضريبة ${Fmt.money(taxPct)}% = ${Fmt.money(tax)}',
                        color: AppTheme.amber,
                      ),
                  ],
                ),
              ],
            ),
          ),
          const SizedBox(width: 8),
          Text(
            Fmt.money(total),
            textDirection: TextDirection.ltr,
            style: const TextStyle(
              fontWeight: FontWeight.w800,
              fontSize: 14.5,
              color: AppTheme.primary,
            ),
          ),
        ],
      ),
    );
  }

  Widget _chip(String text, {Color color = AppTheme.textSoft}) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.10),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Text(
        text,
        textDirection: TextDirection.ltr,
        style: TextStyle(
          fontSize: 11,
          fontWeight: FontWeight.w700,
          color: color,
        ),
      ),
    );
  }
}

class _SummaryCard extends StatelessWidget {
  const _SummaryCard({required this.inv, required this.posted});

  final Map<String, dynamic> inv;
  final bool posted;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        gradient: AppTheme.brandGradient,
        borderRadius: BorderRadius.circular(20),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  Fmt.str(inv['customer_name']),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 16,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ),
              StatusPill(
                text: posted ? 'مرحّلة' : 'غير مرحّلة',
                color: Colors.white,
              ),
            ],
          ),
          const SizedBox(height: 4),
          Text(
            'فاتورة #${Fmt.str(inv['invoice_no'])} • '
            '${Fmt.dmy(Fmt.str(inv['invoice_date']))}',
            style: TextStyle(
              color: Colors.white.withValues(alpha: 0.85),
              fontSize: 12.5,
            ),
          ),
          const SizedBox(height: 16),
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: Colors.white.withValues(alpha: 0.15),
              borderRadius: BorderRadius.circular(14),
            ),
            child: Column(
              children: [
                _line('الإجمالي الفرعي', Fmt.money(Fmt.toDouble(inv['subtotal']))),
                _line('الخصم', Fmt.money(Fmt.toDouble(inv['discount_amount']))),
                _line('الضريبة', Fmt.money(Fmt.toDouble(inv['tax_amount']))),
                Divider(color: Colors.white.withValues(alpha: 0.25), height: 18),
                _line(
                  'الإجمالي النهائي',
                  Fmt.money(Fmt.toDouble(inv['total'])),
                  bold: true,
                ),
              ],
            ),
          ),
          if (Fmt.str(inv['payment_label']).isNotEmpty) ...[
            const SizedBox(height: 10),
            Row(
              children: [
                const Icon(
                  Icons.credit_card_rounded,
                  color: Colors.white70,
                  size: 15,
                ),
                const SizedBox(width: 6),
                Text(
                  Fmt.str(inv['payment_label']),
                  style: const TextStyle(color: Colors.white70, fontSize: 12.5),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }

  Widget _line(String label, String value, {bool bold = false}) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(
            label,
            style: TextStyle(
              color: Colors.white.withValues(alpha: bold ? 1 : 0.8),
              fontSize: bold ? 14 : 12.5,
              fontWeight: bold ? FontWeight.w700 : FontWeight.w500,
            ),
          ),
          Text(
            value,
            textDirection: TextDirection.ltr,
            style: TextStyle(
              color: Colors.white,
              fontSize: bold ? 18 : 13,
              fontWeight: bold ? FontWeight.w900 : FontWeight.w700,
            ),
          ),
        ],
      ),
    );
  }
}

class _MenuRow extends StatelessWidget {
  const _MenuRow(this.icon, this.label, {this.color = AppTheme.textMain});

  final IconData icon;
  final String label;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Icon(icon, size: 18, color: color),
        const SizedBox(width: 10),
        Text(label, style: TextStyle(fontSize: 14, color: color)),
      ],
    );
  }
}
