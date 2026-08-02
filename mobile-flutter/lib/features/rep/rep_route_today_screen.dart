import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/format.dart';
import '../../core/theme.dart';
import '../../widgets/mobile_scaffold.dart';
import '../../widgets/ui_kit.dart';

class RepRouteTodayScreen extends StatefulWidget {
  const RepRouteTodayScreen({super.key});

  @override
  State<RepRouteTodayScreen> createState() => _RepRouteTodayScreenState();
}

class _RepRouteTodayScreenState extends State<RepRouteTodayScreen> {
  bool _loading = true;
  String? _error;
  String _routeDate = '';
  bool _geofence = false;
  int _radiusM = 200;
  List<Map<String, dynamic>> _customers = [];
  final _dateCtrl = TextEditingController();

  @override
  void initState() {
    super.initState();
    _routeDate = Fmt.todayIso();
    _dateCtrl.text = _routeDate;
    _load();
  }

  @override
  void dispose() {
    _dateCtrl.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final res = await context.read<ApiClient>().getJson(
            AppConfig.repRouteTodayPath,
            query: {'date': _routeDate},
          );
      if (!mounted) return;
      setState(() {
        _customers = (res['customers'] as List? ?? [])
            .whereType<Map>()
            .map((e) => e.cast<String, dynamic>())
            .toList();
        _routeDate = Fmt.str(res['route_date']).isEmpty
            ? _routeDate
            : Fmt.str(res['route_date']);
        _dateCtrl.text = _routeDate;
        _geofence = res['geofence_required'] == true;
        _radiusM = Fmt.toInt(res['visit_radius_m']);
        if (_radiusM < 1) _radiusM = 200;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _loading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.toString();
        _loading = false;
      });
    }
  }

  Future<void> _pickDate() async {
    final initial = DateTime.tryParse(_routeDate) ?? DateTime.now();
    final picked = await showDatePicker(
      context: context,
      initialDate: initial,
      firstDate: DateTime(initial.year - 1),
      lastDate: DateTime(initial.year + 1),
    );
    if (picked == null) return;
    setState(() {
      _routeDate =
          '${picked.year.toString().padLeft(4, '0')}-${picked.month.toString().padLeft(2, '0')}-${picked.day.toString().padLeft(2, '0')}';
      _dateCtrl.text = _routeDate;
    });
    await _load();
  }

  @override
  Widget build(BuildContext context) {
    return MobileScaffold(
      title: const Text('خط سير اليوم'),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.all(14),
          children: [
            AppCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const Text(
                    'عملاء الزيارة المعينون من الإدارة لهذا التاريخ.',
                    style: TextStyle(color: AppTheme.textSoft, height: 1.4),
                  ),
                  const SizedBox(height: 10),
                  TextField(
                    controller: _dateCtrl,
                    readOnly: true,
                    onTap: _pickDate,
                    decoration: const InputDecoration(
                      labelText: 'التاريخ',
                      suffixIcon: Icon(Icons.calendar_month_rounded),
                    ),
                  ),
                  if (_geofence) ...[
                    const SizedBox(height: 8),
                    Text(
                      'إلزام الموقع مفعّل: يجب التواجد ضمن $_radiusM م من موقع العميل.',
                      style: const TextStyle(
                        color: AppTheme.amber,
                        fontSize: 12.5,
                        height: 1.35,
                      ),
                    ),
                  ],
                ],
              ),
            ),
            const SizedBox(height: 12),
            if (_loading)
              const Padding(
                padding: EdgeInsets.all(24),
                child: Center(child: CircularProgressIndicator()),
              )
            else if (_error != null)
              AppCard(
                child: Text(_error!, style: const TextStyle(color: AppTheme.danger)),
              )
            else if (_customers.isEmpty)
              const AppCard(
                child: Text(
                  'لا يوجد خط سير محفوظ لهذا التاريخ.',
                  style: TextStyle(color: AppTheme.textSoft),
                ),
              )
            else
              AppCard(
                child: Column(
                  children: [
                    for (var i = 0; i < _customers.length; i++) ...[
                      if (i > 0) const Divider(height: 1),
                      ListTile(
                        contentPadding: EdgeInsets.zero,
                        leading: CircleAvatar(
                          radius: 16,
                          backgroundColor: AppTheme.primary.withValues(alpha: 0.12),
                          child: Text(
                            '${i + 1}',
                            style: const TextStyle(
                              color: AppTheme.primary,
                              fontWeight: FontWeight.w700,
                              fontSize: 12,
                            ),
                          ),
                        ),
                        title: Text(
                          Fmt.str(_customers[i]['name']),
                          style: const TextStyle(fontWeight: FontWeight.w700),
                        ),
                        subtitle: Text(
                          '${Fmt.str(_customers[i]['code'])} — '
                          '${_customers[i]['has_gps'] == true ? 'موقع محدد' : 'بدون موقع'}',
                        ),
                      ),
                    ],
                  ],
                ),
              ),
          ],
        ),
      ),
    );
  }
}
