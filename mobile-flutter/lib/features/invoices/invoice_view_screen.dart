import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../services/invoice_print_helper.dart';
import '../../services/location_service.dart';
import '../../widgets/async_view.dart';

class InvoiceViewScreen extends StatefulWidget {
  const InvoiceViewScreen({super.key, required this.invoiceId});
  final int invoiceId;

  @override
  State<InvoiceViewScreen> createState() => _InvoiceViewScreenState();
}

class _InvoiceViewScreenState extends State<InvoiceViewScreen> {
  bool _loading = true;
  bool _posting = false;
  bool _printing = false;
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
      setState(() {
        _inv = (res['invoice'] as Map?)?.cast<String, dynamic>() ?? {};
        _loading = false;
      });
    } on ApiException catch (e) {
      setState(() {
        _error = e.message;
        _loading = false;
      });
    }
  }

  List<Map<String, dynamic>> get _lines {
    final l = _inv['lines'] ?? _inv['items'] ?? _inv['rows'];
    if (l is List) {
      return l
          .whereType<Map>()
          .map((e) => e.cast<String, dynamic>())
          .toList();
    }
    return [];
  }

  Future<void> _openPdf() async {
    setState(() => _printing = true);
    try {
      await InvoicePrintHelper.openPdf(
        context,
        invoiceId: widget.invoiceId,
        invoiceNo: Fmt.str(_inv['invoice_no']),
      );
    } finally {
      if (mounted) setState(() => _printing = false);
    }
  }

  Future<void> _post() async {
    final s = context.read<SessionController>();
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
      final res = await context.read<ApiClient>().postForm(
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

  @override
  Widget build(BuildContext context) {
    final posted = _inv['is_posted'] == true;
    return Scaffold(
      appBar: AppBar(
        title: const Text('عرض الفاتورة'),
        actions: [
          IconButton(
            tooltip: 'طباعة',
            onPressed: (_loading || _printing) ? null : _openPdf,
            icon: const Icon(Icons.print_outlined),
          ),
          IconButton(
            tooltip: 'PDF',
            onPressed: (_loading || _printing) ? null : _openPdf,
            icon: const Icon(Icons.picture_as_pdf_outlined),
          ),
        ],
      ),
      body: AsyncView(
        loading: _loading,
        error: _error,
        onRetry: _load,
        child: ListView(
          padding: const EdgeInsets.all(12),
          children: [
            Card(
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    _row('رقم الفاتورة', Fmt.str(_inv['invoice_no'])),
                    _row('التاريخ', Fmt.dmy(Fmt.str(_inv['invoice_date']))),
                    _row('العميل', Fmt.str(_inv['customer_name'])),
                    _row('طريقة الدفع', Fmt.str(_inv['payment_label'])),
                    const Divider(),
                    _row('الإجمالي الفرعي',
                        Fmt.money(Fmt.toDouble(_inv['subtotal']))),
                    _row('الضريبة',
                        Fmt.money(Fmt.toDouble(_inv['tax_amount']))),
                    _row('الإجمالي', Fmt.money(Fmt.toDouble(_inv['total'])),
                        bold: true),
                    const SizedBox(height: 8),
                    Align(
                      alignment: Alignment.centerLeft,
                      child: _Badge(
                        text: posted ? 'مرحّلة' : 'غير مرحّلة',
                        color: posted ? AppTheme.success : AppTheme.warn,
                      ),
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 8),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: _printing ? null : _openPdf,
                    icon: _printing
                        ? const SizedBox(
                            width: 16,
                            height: 16,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Icon(Icons.print_outlined),
                    label: const Text('طباعة'),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: FilledButton.tonalIcon(
                    onPressed: _printing ? null : _openPdf,
                    icon: const Icon(Icons.picture_as_pdf_outlined),
                    label: const Text('تحويل PDF'),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            const Padding(
              padding: EdgeInsets.symmetric(horizontal: 6, vertical: 6),
              child: Text('البنود',
                  style:
                      TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
            ),
            if (_lines.isEmpty)
              const EmptyState(message: 'لا توجد بنود.')
            else
              ..._lines.map(_lineCard),
          ],
        ),
      ),
      bottomNavigationBar: (posted || _loading)
          ? null
          : SafeArea(
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: FilledButton.icon(
                  onPressed: _posting ? null : _post,
                  icon: _posting
                      ? const SizedBox(
                          width: 20,
                          height: 20,
                          child: CircularProgressIndicator(
                              strokeWidth: 2, color: Colors.white),
                        )
                      : const Icon(Icons.check_circle_outline),
                  label: const Text('ترحيل الفاتورة'),
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
        l['line_gross'] ?? l['line_total'] ?? l['total'] ?? (qty * price));
    final discInput = Fmt.str(l['line_discount_input']);
    return Card(
      child: ListTile(
        title: Text(name),
        subtitle: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('كمية: ${Fmt.money(qty)} × ${Fmt.money(price)}',
                textDirection: TextDirection.ltr),
            if (disc > 0 || discInput.isNotEmpty)
              Text(
                'خصم: ${discInput.isNotEmpty ? discInput : Fmt.money(disc)}',
                textDirection: TextDirection.ltr,
                style: const TextStyle(fontSize: 12),
              ),
            Text(
              'ضريبة ${Fmt.money(taxPct)}%: ${Fmt.money(tax)}',
              textDirection: TextDirection.ltr,
              style: const TextStyle(fontSize: 12),
            ),
          ],
        ),
        isThreeLine: true,
        trailing: Text(Fmt.money(total),
            textDirection: TextDirection.ltr,
            style: const TextStyle(fontWeight: FontWeight.bold)),
      ),
    );
  }

  Widget _row(String label, String value, {bool bold = false}) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(label, style: const TextStyle(color: Colors.black54)),
          Flexible(
            child: Text(
              value,
              textAlign: TextAlign.left,
              style: TextStyle(
                  fontWeight: bold ? FontWeight.bold : FontWeight.w500),
            ),
          ),
        ],
      ),
    );
  }
}

class _Badge extends StatelessWidget {
  const _Badge({required this.text, required this.color});
  final String text;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(20),
      ),
      child: Text(text,
          style: TextStyle(color: color, fontWeight: FontWeight.bold)),
    );
  }
}
