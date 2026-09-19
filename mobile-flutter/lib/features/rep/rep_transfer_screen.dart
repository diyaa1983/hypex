import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../widgets/async_view.dart';

class _RepLine {
  _RepLine({required this.itemId, required this.name, required this.stock});
  final int itemId;
  final String name;
  final double stock;
  double qty = 1;
}

/// تحميل/إرجاع عهدة المندوب. direction = 'load' أو 'return'.
class RepTransferScreen extends StatefulWidget {
  const RepTransferScreen({super.key, required this.direction});
  final String direction;

  @override
  State<RepTransferScreen> createState() => _RepTransferScreenState();
}

class _RepTransferScreenState extends State<RepTransferScreen> {
  final List<_RepLine> _lines = [];
  bool _saving = false;

  bool get _isReturn => widget.direction == 'return';
  String get _title => _isReturn ? 'إرجاع عهدة' : 'تحميل عهدة';

  Future<void> _addItem() async {
    final it = await showModalBottomSheet<Map<String, dynamic>>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(18)),
      ),
      builder: (_) => _RepItemPicker(direction: widget.direction),
    );
    if (it == null) return;
    final id = Fmt.toInt(it['id']);
    final existing = _lines.where((l) => l.itemId == id).toList();
    if (existing.isNotEmpty) {
      setState(() => existing.first.qty += 1);
    } else {
      setState(() => _lines.add(_RepLine(
            itemId: id,
            name: Fmt.str(it['name_ar'] ?? it['item_name'] ?? it['name']),
            stock: Fmt.toDouble(it['stock_qty']),
          )));
    }
  }

  Future<void> _save() async {
    final picked = _lines.where((l) => l.qty > 0).toList();
    if (picked.isEmpty) {
      showSnack(context, 'أضف بنداً واحداً على الأقل', error: true);
      return;
    }
    final s = context.read<SessionController>();
    setState(() => _saving = true);
    try {
      final res = await context.read<ApiClient>().postForm(
        AppConfig.repTransferPath,
        csrf: s.csrf,
        fields: {
          '_action': 'save_post',
          'direction': widget.direction,
          'move_id': 0,
          'move_date': Fmt.todayIso(),
          'lines_json': jsonEncode(
              picked.map((l) => {'item_id': l.itemId, 'qty': l.qty}).toList()),
        },
      );
      if (!mounted) return;
      showSnack(context, (res['message'] ?? 'تم الحفظ والترحيل').toString());
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
      appBar: AppBar(title: Text(_title)),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _addItem,
        icon: const Icon(Icons.add),
        label: const Text('إضافة مادة'),
      ),
      body: Column(
        children: [
          Expanded(
            child: _lines.isEmpty
                ? const EmptyState(message: 'أضف المواد المراد نقلها.')
                : ListView.builder(
                    padding: const EdgeInsets.all(12),
                    itemCount: _lines.length,
                    itemBuilder: (_, i) {
                      final l = _lines[i];
                      return Card(
                        child: Padding(
                          padding: const EdgeInsets.all(12),
                          child: Row(
                            children: [
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(l.name,
                                        style: const TextStyle(
                                            fontWeight: FontWeight.bold)),
                                    Text('الرصيد: ${Fmt.money(l.stock)}',
                                        textDirection: TextDirection.ltr,
                                        style: const TextStyle(
                                            fontSize: 12,
                                            color: Colors.black54)),
                                  ],
                                ),
                              ),
                              SizedBox(
                                width: 90,
                                child: TextFormField(
                                  initialValue: '1',
                                  keyboardType:
                                      const TextInputType.numberWithOptions(
                                          decimal: true),
                                  textDirection: TextDirection.ltr,
                                  decoration: const InputDecoration(
                                      labelText: 'كمية', isDense: true),
                                  onChanged: (v) => l.qty =
                                      double.tryParse(v.replaceAll(',', '')) ??
                                          0,
                                ),
                              ),
                              IconButton(
                                icon: const Icon(Icons.delete_outline,
                                    color: Colors.red),
                                onPressed: () =>
                                    setState(() => _lines.removeAt(i)),
                              ),
                            ],
                          ),
                        ),
                      );
                    },
                  ),
          ),
          SafeArea(
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: FilledButton.icon(
                onPressed: _saving ? null : _save,
                icon: _saving
                    ? const SizedBox(
                        width: 20,
                        height: 20,
                        child: CircularProgressIndicator(
                            strokeWidth: 2, color: Colors.white),
                      )
                    : const Icon(Icons.check_circle_outline),
                label: Text(_isReturn ? 'إرجاع وترحيل' : 'تحميل وترحيل'),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _RepItemPicker extends StatefulWidget {
  const _RepItemPicker({required this.direction});
  final String direction;

  @override
  State<_RepItemPicker> createState() => _RepItemPickerState();
}

class _RepItemPickerState extends State<_RepItemPicker> {
  final _search = TextEditingController();
  final _searchFocus = FocusNode();
  List<Map<String, dynamic>> _items = [];
  bool _loading = true;
  bool _keyboardEnabled = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load('');
  }

  @override
  void dispose() {
    _search.dispose();
    _searchFocus.dispose();
    super.dispose();
  }

  Future<void> _load(String q) async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final res = await context.read<ApiClient>().getJson(
        AppConfig.repItemsPath,
        query: {
          'direction': widget.direction,
          if (q.isEmpty) 'list': '1' else 'q': q,
          if (widget.direction == 'return') 'positive': '1',
        },
      );
      setState(() {
        _items = (res['items'] as List? ?? [])
            .whereType<Map>()
            .map((e) => e.cast<String, dynamic>())
            .toList();
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
    return Padding(
      padding:
          EdgeInsets.only(bottom: MediaQuery.of(context).viewInsets.bottom),
      child: SizedBox(
        height: MediaQuery.of(context).size.height * 0.85,
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 14, 16, 8),
              child: Row(
                children: [
                  const Text('اختيار مادة',
                      style:
                          TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
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
                focusNode: _searchFocus,
                readOnly: !_keyboardEnabled,
                showCursor: _keyboardEnabled,
                decoration: InputDecoration(
                  hintText: 'بحث بالاسم أو الرمز...',
                  prefixIcon: const Icon(Icons.search),
                  suffixIcon: IconButton(
                    tooltip: _keyboardEnabled
                        ? 'إخفاء لوحة المفاتيح'
                        : 'إظهار لوحة المفاتيح',
                    onPressed: () {
                      if (_keyboardEnabled) {
                        _searchFocus.unfocus();
                        setState(() => _keyboardEnabled = false);
                      } else {
                        setState(() => _keyboardEnabled = true);
                        WidgetsBinding.instance.addPostFrameCallback((_) {
                          if (mounted) _searchFocus.requestFocus();
                        });
                      }
                    },
                    icon: Icon(
                      _keyboardEnabled
                          ? Icons.keyboard_hide_rounded
                          : Icons.keyboard_rounded,
                    ),
                  ),
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
                                final it = _items[i];
                                return ListTile(
                                  title: Text(Fmt.str(it['name_ar'] ??
                                      it['item_name'] ??
                                      it['name'] ??
                                      it['sku'])),
                                  subtitle: Text(
                                      'الرصيد: ${Fmt.money(Fmt.toDouble(it['stock_qty']))}',
                                      textDirection: TextDirection.ltr),
                                  onTap: () => Navigator.pop(context, it),
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
