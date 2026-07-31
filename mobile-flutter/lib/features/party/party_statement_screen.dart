import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/format.dart';
import '../../services/document_print_helper.dart';
import '../../widgets/async_view.dart';
import '../../widgets/party_picker.dart';

class PartyStatementScreen extends StatefulWidget {
  const PartyStatementScreen({super.key});

  @override
  State<PartyStatementScreen> createState() => _PartyStatementScreenState();
}

class _PartyStatementScreenState extends State<PartyStatementScreen> {
  String _type = 'customer';
  Party? _party;
  DateTime _from = DateTime(DateTime.now().year, 1, 1);
  DateTime _to = DateTime.now();

  bool _loading = false;
  String? _error;
  Map<String, dynamic>? _result;

  Future<void> _pick() async {
    final p = await pickParty(context, type: _type);
    if (p != null) setState(() => _party = p);
  }

  Future<void> _pickDate(bool from) async {
    final init = from ? _from : _to;
    final d = await showDatePicker(
      context: context,
      initialDate: init,
      firstDate: DateTime(2015),
      lastDate: DateTime(2100),
    );
    if (d != null) {
      setState(() {
        if (from) {
          _from = d;
        } else {
          _to = d;
        }
      });
    }
  }

  Future<void> _run() async {
    if (_party == null) {
      showSnack(context, 'اختر الطرف أولاً', error: true);
      return;
    }
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final res = await context.read<ApiClient>().getJson(
        AppConfig.partyStatementPath,
        query: {
          'party_type': _type,
          'party_id': _party!.id,
          'from': _iso(_from),
          'to': _iso(_to),
        },
      );
      setState(() {
        _result = res;
        _loading = false;
      });
    } on ApiException catch (e) {
      setState(() {
        _error = e.message;
        _loading = false;
      });
    }
  }

  String _iso(DateTime d) =>
      '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  @override
  Widget build(BuildContext context) {
    final rows = (_result?['rows'] as List? ?? [])
        .whereType<Map>()
        .map((e) => e.cast<String, dynamic>())
        .toList();
    return Scaffold(
      appBar: AppBar(
        title: const Text('كشف حساب'),
        actions: [
          if (_result != null && _party != null)
            IconButton(
              tooltip: 'طباعة Bluetooth',
              onPressed: _loading
                  ? null
                  : () => DocumentPrintHelper.printFromApi(
                        context,
                        apiPath: AppConfig.partyStatementPdfPath,
                        query: {
                          'party_type': _type,
                          'party_id': _party!.id,
                          'from': _iso(_from),
                          'to': _iso(_to),
                        },
                        jobName: 'كشف_${_party!.name}',
                      ),
              icon: const Icon(Icons.print_outlined),
            ),
        ],
      ),
      body: Column(
        children: [
          Card(
            margin: const EdgeInsets.all(10),
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Column(
                children: [
                  Row(
                    children: [
                      ChoiceChip(
                        label: const Text('عميل'),
                        selected: _type == 'customer',
                        onSelected: (_) => setState(() {
                          _type = 'customer';
                          _party = null;
                        }),
                      ),
                      const SizedBox(width: 8),
                      ChoiceChip(
                        label: const Text('مورد'),
                        selected: _type == 'supplier',
                        onSelected: (_) => setState(() {
                          _type = 'supplier';
                          _party = null;
                        }),
                      ),
                    ],
                  ),
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    leading: const Icon(Icons.badge_outlined),
                    title: Text(_party?.name ??
                        (_type == 'customer' ? 'اختر العميل' : 'اختر المورد')),
                    trailing: const Icon(Icons.chevron_left),
                    onTap: _pick,
                  ),
                  Row(
                    children: [
                      Expanded(
                        child: OutlinedButton.icon(
                          icon: const Icon(Icons.calendar_today, size: 16),
                          label: Text('من: ${Fmt.dmy(_iso(_from))}'),
                          onPressed: () => _pickDate(true),
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: OutlinedButton.icon(
                          icon: const Icon(Icons.calendar_today, size: 16),
                          label: Text('إلى: ${Fmt.dmy(_iso(_to))}'),
                          onPressed: () => _pickDate(false),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  FilledButton.icon(
                    onPressed: _loading ? null : _run,
                    icon: const Icon(Icons.search),
                    label: const Text('عرض الكشف'),
                  ),
                ],
              ),
            ),
          ),
          Expanded(
            child: AsyncView(
              loading: _loading,
              error: _error,
              onRetry: _run,
              child: _result == null
                  ? const EmptyState(
                      message: 'اختر الطرف والفترة ثم اعرض الكشف.',
                      icon: Icons.menu_book_outlined,
                    )
                  : Column(
                      children: [
                        _summary(),
                        const Divider(height: 1),
                        Expanded(
                          child: rows.isEmpty
                              ? const EmptyState(message: 'لا توجد حركات.')
                              : ListView.separated(
                                  itemCount: rows.length,
                                  separatorBuilder: (_, __) =>
                                      const Divider(height: 1),
                                  itemBuilder: (_, i) => _rowTile(rows[i]),
                                ),
                        ),
                      ],
                    ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _summary() {
    final r = _result!;
    return Padding(
      padding: const EdgeInsets.all(12),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceAround,
        children: [
          _stat('رصيد أول', Fmt.money(Fmt.toDouble(r['opening_balance']))),
          _stat('مدين', Fmt.money(Fmt.toDouble(r['total_debit']))),
          _stat('دائن', Fmt.money(Fmt.toDouble(r['total_credit']))),
          _stat('الرصيد', Fmt.money(Fmt.toDouble(r['closing_balance'])),
              bold: true),
        ],
      ),
    );
  }

  Widget _stat(String label, String value, {bool bold = false}) {
    return Column(
      children: [
        Text(label, style: const TextStyle(fontSize: 12, color: Colors.black54)),
        const SizedBox(height: 2),
        Text(value,
            textDirection: TextDirection.ltr,
            style: TextStyle(
                fontWeight: bold ? FontWeight.bold : FontWeight.w600)),
      ],
    );
  }

  Widget _rowTile(Map<String, dynamic> row) {
    final desc = Fmt.str(row['description'] ?? row['doc_type'] ?? row['type']);
    final date = Fmt.dmy(Fmt.str(row['date'] ?? row['doc_date']));
    final debit = Fmt.toDouble(row['debit']);
    final credit = Fmt.toDouble(row['credit']);
    final balance = Fmt.toDouble(row['balance'] ?? row['running_balance']);
    return ListTile(
      dense: true,
      title: Text(desc.isEmpty ? '—' : desc),
      subtitle: Text(date, textDirection: TextDirection.ltr),
      trailing: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          Text(
            debit > 0 ? 'مدين ${Fmt.money(debit)}' : 'دائن ${Fmt.money(credit)}',
            textDirection: TextDirection.ltr,
            style: const TextStyle(fontSize: 12),
          ),
          Text('الرصيد ${Fmt.money(balance)}',
              textDirection: TextDirection.ltr,
              style: const TextStyle(
                  fontSize: 12, fontWeight: FontWeight.bold)),
        ],
      ),
    );
  }
}
