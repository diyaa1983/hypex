import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../widgets/async_view.dart';
import '../../widgets/party_picker.dart';

class ReceiptFormScreen extends StatefulWidget {
  const ReceiptFormScreen({super.key});

  @override
  State<ReceiptFormScreen> createState() => _ReceiptFormScreenState();
}

class _ReceiptFormScreenState extends State<ReceiptFormScreen> {
  Party? _customer;
  String _payMethod = 'cash';
  final _amount = TextEditingController();
  final _notes = TextEditingController();
  final _checkNo = TextEditingController();
  final _bankName = TextEditingController();
  bool _saving = false;

  @override
  void dispose() {
    _amount.dispose();
    _notes.dispose();
    _checkNo.dispose();
    _bankName.dispose();
    super.dispose();
  }

  Future<void> _pickCustomer() async {
    final p = await pickParty(context, type: 'customer');
    if (p != null) setState(() => _customer = p);
  }

  Future<void> _save() async {
    if (_customer == null) {
      showSnack(context, 'اختر العميل', error: true);
      return;
    }
    final amount = double.tryParse(_amount.text.replaceAll(',', '')) ?? 0;
    if (amount <= 0 && _payMethod == 'cash') {
      showSnack(context, 'أدخل المبلغ', error: true);
      return;
    }
    final s = context.read<SessionController>();
    setState(() => _saving = true);
    try {
      final fields = <String, dynamic>{
        '_action': 'save_receipt',
        'voucher_id': 0,
        'party_type': 'customer',
        'voucher_date': Fmt.todayIso(),
        'customer_id': _customer!.id,
        'pay_method': _payMethod,
        'amount': amount,
        'notes': _notes.text.trim(),
      };
      if (_payMethod == 'check') {
        fields['check_amount'] = amount;
        fields['check_no'] = _checkNo.text.trim();
        fields['bank_name'] = _bankName.text.trim();
      }
      final res = await context.read<ApiClient>().postForm(
        AppConfig.receiptSaveRoute,
        csrf: s.csrf,
        fields: fields,
      );
      if (!mounted) return;
      showSnack(context, (res['message'] ?? 'تم حفظ السند').toString());
      context.pop();
    } on ApiException catch (e) {
      if (!mounted) return;
      showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('سند قبض جديد')),
      body: ListView(
        padding: const EdgeInsets.all(14),
        children: [
          Card(
            child: ListTile(
              leading: const Icon(Icons.person_outline),
              title: Text(_customer?.name ?? 'اختر العميل'),
              trailing: const Icon(Icons.chevron_left),
              onTap: _pickCustomer,
            ),
          ),
          const SizedBox(height: 8),
          const Text('طريقة الدفع'),
          const SizedBox(height: 6),
          Row(
            children: [
              ChoiceChip(
                label: const Text('نقدي'),
                selected: _payMethod == 'cash',
                onSelected: (_) => setState(() => _payMethod = 'cash'),
              ),
              const SizedBox(width: 8),
              ChoiceChip(
                label: const Text('شيك'),
                selected: _payMethod == 'check',
                onSelected: (_) => setState(() => _payMethod = 'check'),
              ),
            ],
          ),
          const SizedBox(height: 14),
          TextField(
            controller: _amount,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            textDirection: TextDirection.ltr,
            decoration: const InputDecoration(
              labelText: 'المبلغ',
              prefixIcon: Icon(Icons.attach_money),
            ),
          ),
          if (_payMethod == 'check') ...[
            const SizedBox(height: 12),
            TextField(
              controller: _checkNo,
              decoration: const InputDecoration(
                labelText: 'رقم الشيك',
                prefixIcon: Icon(Icons.numbers),
              ),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _bankName,
              decoration: const InputDecoration(
                labelText: 'اسم البنك',
                prefixIcon: Icon(Icons.account_balance),
              ),
            ),
          ],
          const SizedBox(height: 12),
          TextField(
            controller: _notes,
            maxLines: 2,
            decoration: const InputDecoration(
              labelText: 'ملاحظات',
              prefixIcon: Icon(Icons.notes),
            ),
          ),
          const SizedBox(height: 20),
          FilledButton.icon(
            onPressed: _saving ? null : _save,
            icon: _saving
                ? const SizedBox(
                    width: 20,
                    height: 20,
                    child: CircularProgressIndicator(
                        strokeWidth: 2, color: Colors.white),
                  )
                : const Icon(Icons.save_outlined),
            label: const Text('حفظ السند'),
          ),
        ],
      ),
    );
  }
}
