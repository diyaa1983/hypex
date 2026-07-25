import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/api_client.dart';
import '../core/config.dart';

class Party {
  Party(this.id, this.name, this.code);
  final int id;
  final String name;
  final String code;
}

/// اختيار عميل/مورد عبر شاشة بحث.
Future<Party?> pickParty(
  BuildContext context, {
  String type = 'customer',
}) {
  return showModalBottomSheet<Party>(
    context: context,
    isScrollControlled: true,
    backgroundColor: Colors.white,
    shape: const RoundedRectangleBorder(
      borderRadius: BorderRadius.vertical(top: Radius.circular(18)),
    ),
    builder: (_) => _PartyPickerSheet(type: type),
  );
}

class _PartyPickerSheet extends StatefulWidget {
  const _PartyPickerSheet({required this.type});
  final String type;

  @override
  State<_PartyPickerSheet> createState() => _PartyPickerSheetState();
}

class _PartyPickerSheetState extends State<_PartyPickerSheet> {
  final _search = TextEditingController();
  List<Party> _items = [];
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load('');
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _load(String q) async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final res = await context.read<ApiClient>().getJson(
        AppConfig.partiesPath,
        query: {'type': widget.type, 'q': q},
      );
      final list = (res['parties'] as List? ?? [])
          .whereType<Map>()
          .map((e) => Party(
                (e['id'] as num?)?.toInt() ?? 0,
                (e['name'] ?? '').toString(),
                (e['code'] ?? '').toString(),
              ))
          .toList();
      setState(() {
        _items = list;
        _loading = false;
      });
    } on ApiException catch (e) {
      setState(() {
        _error = e.message;
        _loading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final title = widget.type == 'supplier' ? 'اختيار مورد' : 'اختيار عميل';
    return Padding(
      padding: EdgeInsets.only(
        bottom: MediaQuery.of(context).viewInsets.bottom,
      ),
      child: SizedBox(
        height: MediaQuery.of(context).size.height * 0.8,
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 14, 16, 8),
              child: Row(
                children: [
                  Text(title,
                      style: const TextStyle(
                          fontSize: 18, fontWeight: FontWeight.bold)),
                  const Spacer(),
                  IconButton(
                    icon: const Icon(Icons.close),
                    onPressed: () => Navigator.pop(context),
                  ),
                ],
              ),
            ),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16),
              child: TextField(
                controller: _search,
                autofocus: true,
                decoration: const InputDecoration(
                  hintText: 'بحث بالاسم أو الرمز...',
                  prefixIcon: Icon(Icons.search),
                ),
                onChanged: (v) => _load(v.trim()),
              ),
            ),
            const SizedBox(height: 8),
            Expanded(
              child: _loading
                  ? const Center(child: CircularProgressIndicator())
                  : _error != null
                      ? Center(child: Text(_error!))
                      : _items.isEmpty
                          ? const Center(child: Text('لا نتائج'))
                          : ListView.separated(
                              itemCount: _items.length,
                              separatorBuilder: (_, __) =>
                                  const Divider(height: 1),
                              itemBuilder: (_, i) {
                                final p = _items[i];
                                return ListTile(
                                  title: Text(p.name),
                                  subtitle:
                                      p.code.isEmpty ? null : Text(p.code),
                                  onTap: () => Navigator.pop(context, p),
                                );
                              },
                            ),
            ),
          ],
        ),
      ),
    );
  }
}
