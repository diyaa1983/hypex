import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../services/return_print_helper.dart';
import '../../widgets/async_view.dart';
import '../../widgets/mobile_scaffold.dart';
import '../../widgets/ui_kit.dart';

class ReturnViewScreen extends StatefulWidget {
  const ReturnViewScreen({super.key, required this.returnId});

  final int returnId;

  @override
  State<ReturnViewScreen> createState() => _ReturnViewScreenState();
}

class _ReturnViewScreenState extends State<ReturnViewScreen> {
  bool _loading = true;
  bool _posting = false;
  bool _printBusy = false;
  bool _previewBusy = false;
  bool _einvoiceBusy = false;
  bool _deleting = false;
  String? _error;
  Map<String, dynamic> _ret = {};

  @override
  void initState() {
    super.initState();
    _load();
  }

  bool _flag(dynamic v) => v == true || v == 1 || v == '1';

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final res = await context.read<ApiClient>().getJson(
        AppConfig.returnViewPath,
        query: {'id': widget.returnId},
      );
      if (!mounted) return;
      setState(() {
        _ret = (res['return'] as Map?)?.cast<String, dynamic>() ?? {};
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
    final l = _ret['lines'];
    if (l is List) {
      return l.whereType<Map>().map((e) => e.cast<String, dynamic>()).toList();
    }
    return [];
  }

  bool get _posted => _flag(_ret['is_posted']);
  bool get _einvSent => _flag(_ret['einv_sent']);
  bool get _canEdit => !_posted && !_einvSent;
  bool get _canDelete => !_posted && !_einvSent;
  bool get _canSendEinvoice =>
      _posted &&
      !_einvSent &&
      _flag(_ret['invoice_einv_sent']) &&
      _ret['einv_tracking_required'] != false;

  Future<void> _printBluetooth() async {
    if (_printBusy) return;
    setState(() => _printBusy = true);
    try {
      await ReturnPrintHelper.printBluetooth(
        context,
        returnId: widget.returnId,
        fallback: _ret,
      );
    } finally {
      if (mounted) setState(() => _printBusy = false);
    }
  }

  Future<void> _openPreview() async {
    if (_previewBusy) return;
    setState(() => _previewBusy = true);
    try {
      await ReturnPrintHelper.openThermalPreview(
        context,
        returnId: widget.returnId,
        fallback: _ret,
      );
    } finally {
      if (mounted) setState(() => _previewBusy = false);
    }
  }

  Future<void> _edit() async {
    if (!_canEdit) return;
    await context.push('/returns/${widget.returnId}/edit');
    if (mounted) await _load();
  }

  Future<void> _sendEinvoice() async {
    if (!_canSendEinvoice || _einvoiceBusy) return;

    final reasonCtrl = TextEditingController(
      text: Fmt.str(_ret['reason_return']),
    );
    final reason = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('إرسال المرتجع للفوترة'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              'مرتجع رقم ${Fmt.str(_ret['return_no'])}\n'
              'سبب الإرجاع مطلوب قبل الإرسال.',
              style: const TextStyle(height: 1.4),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: reasonCtrl,
              autofocus: true,
              maxLines: 3,
              decoration: const InputDecoration(
                labelText: 'سبب الإرجاع',
                hintText: 'مثال: بضاعة تالفة / خطأ في الطلب',
              ),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(ctx, reasonCtrl.text.trim()),
            child: const Text('إرسال'),
          ),
        ],
      ),
    );
    reasonCtrl.dispose();
    if (reason == null || !mounted) return;
    if (reason.length < 3) {
      showSnack(context, 'أدخل سبب إرجاع واضحاً (3 أحرف على الأقل).',
          error: true);
      return;
    }

    setState(() => _einvoiceBusy = true);
    final s = context.read<SessionController>();
    try {
      final res = await context.read<ApiClient>().postForm(
            AppConfig.returnEinvoiceSendPath,
            fields: {
              'return_id': widget.returnId,
              'reason': reason,
            },
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
    if (_posted || _posting) return;
    setState(() => _posting = true);
    final s = context.read<SessionController>();
    try {
      final res = await context.read<ApiClient>().postForm(
            AppConfig.returnPostPath,
            fields: {'return_id': widget.returnId},
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
    if (!_canDelete || _deleting) return;
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('حذف المرتجع'),
        content: Text(
          'سيتم حذف المرتجع رقم ${Fmt.str(_ret['return_no'])} نهائياً.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: AppTheme.danger),
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
            AppConfig.returnDeletePath,
            fields: {'return_id': widget.returnId},
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

  @override
  Widget build(BuildContext context) {
    return MobileScaffold(
      title: Text(
        Fmt.str(_ret['return_no']).isEmpty
            ? 'مرتجع مبيعات'
            : 'مرتجع ${Fmt.str(_ret['return_no'])}',
      ),
      actions: [
        IconButton(
          tooltip: 'تحديث',
          onPressed: _load,
          icon: const Icon(Icons.refresh_rounded),
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
                          Fmt.str(_ret['customer_name']).isEmpty
                              ? '—'
                              : Fmt.str(_ret['customer_name']),
                          style: const TextStyle(
                            fontSize: 16,
                            fontWeight: FontWeight.w800,
                          ),
                        ),
                      ),
                      StatusPill(
                        text: _einvSent
                            ? 'مُرسل للفوترة'
                            : (_posted ? 'مرحّل' : 'غير مرحّل'),
                        color: _einvSent
                            ? AppTheme.violet
                            : (_posted ? AppTheme.success : AppTheme.warn),
                      ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  Text(
                    'التاريخ: ${Fmt.dmy(Fmt.str(_ret['return_date']))}',
                    style: const TextStyle(color: AppTheme.textSoft),
                  ),
                  if (Fmt.str(_ret['invoice_no']).isNotEmpty) ...[
                    const SizedBox(height: 4),
                    Text(
                      'فاتورة البيع: ${Fmt.str(_ret['invoice_no'])}',
                      style: const TextStyle(color: AppTheme.textSoft),
                    ),
                  ],
                  if (Fmt.str(_ret['reason_return']).isNotEmpty) ...[
                    const SizedBox(height: 4),
                    Text(
                      'سبب الإرجاع: ${Fmt.str(_ret['reason_return'])}',
                      style: const TextStyle(color: AppTheme.textSoft),
                    ),
                  ],
                  const SizedBox(height: 10),
                  Text(
                    Fmt.money(Fmt.toDouble(_ret['total'])),
                    textDirection: TextDirection.ltr,
                    style: const TextStyle(
                      fontSize: 22,
                      fontWeight: FontWeight.w900,
                      color: AppTheme.rose,
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 10),
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
                    icon: Icons.receipt_long_outlined,
                    label: 'عرض',
                    color: AppTheme.teal,
                    busy: _previewBusy,
                    onTap: _openPreview,
                  ),
                ),
              ],
            ),
            if (_canEdit || _canSendEinvoice) ...[
              const SizedBox(height: 8),
              Row(
                children: [
                  if (_canEdit)
                    Expanded(
                      child: ActionChipButton(
                        icon: Icons.edit_outlined,
                        label: 'تعديل',
                        color: AppTheme.amber,
                        onTap: _edit,
                      ),
                    ),
                  if (_canEdit && _canSendEinvoice) const SizedBox(width: 8),
                  if (_canSendEinvoice)
                    Expanded(
                      child: ActionChipButton(
                        icon: Icons.send_outlined,
                        label: 'إرسال للفوترة',
                        color: AppTheme.violet,
                        busy: _einvoiceBusy,
                        onTap: _sendEinvoice,
                      ),
                    ),
                ],
              ),
            ],
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
                    if (_canDelete) ...[
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
                              label: const Text('ترحيل المرتجع'),
                            ),
                    ),
                  ],
                ),
              ),
            ),
    );
  }

  Widget _lineCard(Map<String, dynamic> l) {
    final name = Fmt.str(l['name_ar'] ?? l['item_name'] ?? l['line_desc']);
    final qty = Fmt.toDouble(l['qty']);
    final qtyExtra = Fmt.toDouble(l['qty_extra']);
    final price = Fmt.toDouble(l['unit_price']);
    final total = Fmt.toDouble(l['line_gross'] ?? l['line_total']);
    return AppCard(
      padding: const EdgeInsets.all(12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            name.isEmpty ? 'مادة' : name,
            style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 14),
          ),
          const SizedBox(height: 8),
          Row(
            children: [
              Expanded(child: _box('الكمية', Fmt.money(qty))),
              const SizedBox(width: 8),
              Expanded(child: _box('إضافية', Fmt.money(qtyExtra))),
              const SizedBox(width: 8),
              Expanded(child: _box('السعر', Fmt.money(price))),
            ],
          ),
          const SizedBox(height: 8),
          Align(
            alignment: Alignment.centerLeft,
            child: Text(
              Fmt.money(total),
              textDirection: TextDirection.ltr,
              style: const TextStyle(
                fontWeight: FontWeight.w900,
                color: AppTheme.rose,
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _box(String label, String value) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 8),
      decoration: BoxDecoration(
        color: AppTheme.surfaceAlt,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: AppTheme.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            label,
            style: const TextStyle(fontSize: 10.5, color: AppTheme.textSoft),
          ),
          const SizedBox(height: 3),
          Text(
            value,
            textDirection: TextDirection.ltr,
            style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w800),
          ),
        ],
      ),
    );
  }
}
