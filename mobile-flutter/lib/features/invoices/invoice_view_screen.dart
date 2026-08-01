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
  bool _previewBusy = false;
  bool _einvoiceBusy = false;
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

  bool get _posted => _inv['is_posted'] == true;
  bool get _einvSent => _inv['einv_sent'] == true;
  bool get _canEdit => !_posted && !_einvSent;
  bool get _canSendEinvoice =>
      _posted && !_einvSent && _inv['einv_tracking_required'] != false;

  Map<String, dynamic> get _invoicePayload {
    final inv = Map<String, dynamic>.from(_inv);
    inv['id'] = widget.invoiceId;
    return inv;
  }

  Future<void> _printBluetooth() async {
    if (_printBusy) return;
    setState(() => _printBusy = true);
    try {
      await InvoicePrintHelper.printBluetooth(
        context,
        invoice: _invoicePayload,
      );
    } finally {
      if (mounted) setState(() => _printBusy = false);
    }
  }

  Future<void> _openPreview() async {
    if (_previewBusy) return;
    setState(() => _previewBusy = true);
    try {
      await InvoicePrintHelper.openThermalPreview(
        context,
        invoice: _invoicePayload,
      );
    } finally {
      if (mounted) setState(() => _previewBusy = false);
    }
  }

  Future<void> _edit() async {
    if (!_canEdit) return;
    await context.push('/invoices/${widget.invoiceId}/edit');
    if (mounted) await _load();
  }

  Future<void> _sendEinvoice() async {
    if (!_canSendEinvoice || _einvoiceBusy) return;
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('إرسال للفوترة'),
        content: Text(
          'إرسال الفاتورة رقم ${Fmt.str(_inv['invoice_no'])} إلى نظام الفوترة؟',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('إرسال'),
          ),
        ],
      ),
    );
    if (ok != true || !mounted) return;

    setState(() => _einvoiceBusy = true);
    final s = context.read<SessionController>();
    try {
      final res = await context.read<ApiClient>().postForm(
            AppConfig.salesInvoiceEinvoiceSendPath,
            fields: {'invoice_id': widget.invoiceId},
            csrf: s.csrf,
          );
      if (!mounted) return;
      showSnack(context, (res['message'] ?? 'تم الإرسال للفوترة').toString());
      await _load();
    } on ApiException catch (e) {
      if (!mounted) return;
      showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _einvoiceBusy = false);
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
    final canDelete =
        context.read<SessionController>().can('m_sales_invoices') && _canEdit;

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
            _SummaryCard(
              inv: _inv,
              posted: _posted,
              einvSent: _einvSent,
            ),
            const SizedBox(height: 10),
            _ActionGrid(
              canEdit: _canEdit,
              canSendEinvoice: _canSendEinvoice,
              printBusy: _printBusy,
              previewBusy: _previewBusy,
              einvoiceBusy: _einvoiceBusy,
              onPrint: _printBluetooth,
              onPreview: _openPreview,
              onEdit: _edit,
              onEinvoice: _sendEinvoice,
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
                      child: _posted
                          ? FilledButton.icon(
                              onPressed: _printBusy ? null : _printBluetooth,
                              icon: _printBusy
                                  ? const SizedBox(
                                      width: 18,
                                      height: 18,
                                      child: CircularProgressIndicator(
                                        strokeWidth: 2,
                                        color: Colors.white,
                                      ),
                                    )
                                  : const Icon(Icons.print_outlined, size: 19),
                              label: const Text('طباعة'),
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
    final nameRaw =
        Fmt.str(l['item_name'] ?? l['name_ar'] ?? l['name'] ?? l['line_desc']);
    final unitName = Fmt.str(l['unit_name']);
    final name = unitName.isEmpty
        ? nameRaw
        : (nameRaw.isEmpty ? unitName : '$nameRaw ($unitName)');
    final qty = Fmt.toDouble(l['qty'] ?? l['quantity']);
    final qtyExtra = Fmt.toDouble(l['qty_extra']);
    final price = Fmt.toDouble(l['unit_price'] ?? l['price']);
    final disc = Fmt.toDouble(l['discount_amount']);
    final tax = Fmt.toDouble(l['tax_amount']);
    final taxPct = Fmt.toDouble(l['tax_rate_percent']);
    final net = Fmt.toDouble(
      l['line_subtotal'] ?? (qty * price - disc),
    );
    final total = Fmt.toDouble(
      l['line_gross'] ?? l['line_total'] ?? l['total'] ?? (net + tax),
    );
    final discInput = Fmt.str(l['line_discount_input']);

    return AppCard(
      padding: const EdgeInsets.fromLTRB(12, 10, 12, 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const MiniIcon(
                Icons.inventory_2_outlined,
                color: AppTheme.violet,
                size: 32,
                iconSize: 17,
                radius: 10,
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  name,
                  style: const TextStyle(
                    fontSize: 14,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Row(
            children: [
              Expanded(child: _fieldBox('كمية', Fmt.money(qty))),
              const SizedBox(width: 5),
              Expanded(
                child: _fieldBox(
                  'إض.',
                  qtyExtra > 0 ? Fmt.money(qtyExtra) : '',
                ),
              ),
              const SizedBox(width: 5),
              Expanded(
                child: _fieldBox(
                  'وحدة',
                  unitName.isNotEmpty ? unitName : '—',
                ),
              ),
              const SizedBox(width: 5),
              Expanded(child: _fieldBox('سعر', Fmt.money(price))),
              const SizedBox(width: 5),
              Expanded(
                child: _fieldBox(
                  'خصم',
                  discInput.isNotEmpty
                      ? discInput
                      : (disc > 0 ? Fmt.money(disc) : ''),
                ),
              ),
              const SizedBox(width: 5),
              Expanded(
                child: _fieldBox(
                  'ضريبة',
                  taxPct > 0 ? '${Fmt.money(taxPct)}%' : '',
                ),
              ),
            ],
          ),
          const SizedBox(height: 6),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              _tiny('صافي', Fmt.money(net)),
              _tiny('ضريبة', Fmt.money(tax)),
              _tiny('الإجمالي', Fmt.money(total), strong: true),
            ],
          ),
        ],
      ),
    );
  }

  Widget _fieldBox(String label, String value) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 5),
      decoration: BoxDecoration(
        color: AppTheme.surfaceAlt,
        borderRadius: BorderRadius.circular(8),
        border: Border.all(color: AppTheme.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          Text(
            label,
            style: const TextStyle(fontSize: 9.5, color: AppTheme.textSoft),
          ),
          const SizedBox(height: 2),
          Text(
            value,
            textDirection: TextDirection.ltr,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(
              fontSize: 12,
              fontWeight: FontWeight.w800,
            ),
          ),
        ],
      ),
    );
  }

  Widget _tiny(String label, String value, {bool strong = false}) {
    return Column(
      children: [
        Text(
          label,
          style: const TextStyle(fontSize: 10.5, color: AppTheme.textSoft),
        ),
        const SizedBox(height: 2),
        Text(
          value,
          textDirection: TextDirection.ltr,
          style: TextStyle(
            fontSize: strong ? 13.5 : 12.5,
            fontWeight: strong ? FontWeight.w900 : FontWeight.w700,
            color: strong ? AppTheme.primary : AppTheme.textMain,
          ),
        ),
      ],
    );
  }
}

class _ActionGrid extends StatelessWidget {
  const _ActionGrid({
    required this.canEdit,
    required this.canSendEinvoice,
    required this.printBusy,
    required this.previewBusy,
    required this.einvoiceBusy,
    required this.onPrint,
    required this.onPreview,
    required this.onEdit,
    required this.onEinvoice,
  });

  final bool canEdit;
  final bool canSendEinvoice;
  final bool printBusy;
  final bool previewBusy;
  final bool einvoiceBusy;
  final VoidCallback onPrint;
  final VoidCallback onPreview;
  final VoidCallback onEdit;
  final VoidCallback onEinvoice;

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Row(
          children: [
            Expanded(
              child: ActionChipButton(
                icon: Icons.print_outlined,
                label: 'طباعة',
                busy: printBusy,
                onTap: onPrint,
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: ActionChipButton(
                icon: Icons.receipt_long_outlined,
                label: 'عرض',
                color: AppTheme.teal,
                busy: previewBusy,
                onTap: onPreview,
              ),
            ),
          ],
        ),
        if (canEdit || canSendEinvoice) ...[
          const SizedBox(height: 8),
          Row(
            children: [
              if (canEdit)
                Expanded(
                  child: ActionChipButton(
                    icon: Icons.edit_outlined,
                    label: 'تعديل',
                    color: AppTheme.amber,
                    onTap: onEdit,
                  ),
                ),
              if (canEdit && canSendEinvoice) const SizedBox(width: 8),
              if (canSendEinvoice)
                Expanded(
                  child: ActionChipButton(
                    icon: Icons.send_outlined,
                    label: 'إرسال للفوترة',
                    color: AppTheme.violet,
                    busy: einvoiceBusy,
                    onTap: onEinvoice,
                  ),
                ),
            ],
          ),
        ],
      ],
    );
  }
}

class _SummaryCard extends StatelessWidget {
  const _SummaryCard({
    required this.inv,
    required this.posted,
    required this.einvSent,
  });

  final Map<String, dynamic> inv;
  final bool posted;
  final bool einvSent;

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
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      Fmt.str(inv['customer_name']),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 16,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    if (Fmt.str(inv['sales_rep_name']).trim().isNotEmpty) ...[
                      const SizedBox(height: 4),
                      Text(
                        'المندوب: ${Fmt.str(inv['sales_rep_name']).trim()}',
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(
                          color: Colors.white.withValues(alpha: 0.88),
                          fontSize: 12.5,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    ],
                  ],
                ),
              ),
              StatusPill(
                text: posted ? 'مرحّلة' : 'غير مرحّلة',
                color: Colors.white,
              ),
            ],
          ),
          if (einvSent) ...[
            const SizedBox(height: 6),
            StatusPill(
              text: 'مُرسلة للفوترة',
              color: Colors.white,
              icon: Icons.verified_outlined,
            ),
          ],
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
                _line('الإجمالي الفرعي',
                    Fmt.money(Fmt.toDouble(inv['subtotal']))),
                _line('الخصم', Fmt.money(Fmt.toDouble(inv['discount_amount']))),
                _line('الضريبة', Fmt.money(Fmt.toDouble(inv['tax_amount']))),
                Divider(
                    color: Colors.white.withValues(alpha: 0.25), height: 18),
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
