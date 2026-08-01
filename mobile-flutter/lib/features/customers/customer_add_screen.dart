import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/format.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../services/location_service.dart';
import '../../widgets/async_view.dart';
import '../../widgets/mobile_scaffold.dart';
import '../../widgets/ui_kit.dart';

class CustomerAddScreen extends StatefulWidget {
  const CustomerAddScreen({super.key});

  @override
  State<CustomerAddScreen> createState() => _CustomerAddScreenState();
}

class _CustomerAddScreenState extends State<CustomerAddScreen> {
  final _name = TextEditingController();
  final _phone = TextEditingController();
  final _address = TextEditingController();
  bool _saving = false;
  bool _locating = false;
  double? _latitude;
  double? _longitude;
  double? _accuracy;

  @override
  void dispose() {
    _name.dispose();
    _phone.dispose();
    _address.dispose();
    super.dispose();
  }

  String get _gpsLabel {
    if (_latitude == null || _longitude == null) {
      return 'لم يُحدَّد موقع بعد.';
    }
    return 'الموقع: ${Fmt.trimNum(_latitude!)} ، ${Fmt.trimNum(_longitude!)}';
  }

  Future<void> _pickLocation() async {
    setState(() => _locating = true);
    try {
      final pos = await LocationService.requirePosition();
      if (!mounted) return;
      setState(() {
        _latitude = pos.latitude;
        _longitude = pos.longitude;
        _accuracy = pos.accuracy;
      });
      showSnack(context, 'تم تحديد موقع العميل.');
    } catch (e) {
      if (!mounted) return;
      showSnack(context, e.toString(), error: true);
    } finally {
      if (mounted) setState(() => _locating = false);
    }
  }

  void _clearLocation() {
    setState(() {
      _latitude = null;
      _longitude = null;
      _accuracy = null;
    });
  }

  Future<void> _save() async {
    final name = _name.text.trim();
    if (name.isEmpty) {
      showSnack(context, 'أدخل اسم العميل', error: true);
      return;
    }
    final s = context.read<SessionController>();
    setState(() => _saving = true);
    try {
      final fields = <String, dynamic>{
        'name_ar': name,
        'phone': _phone.text.trim(),
        'address_ar': _address.text.trim(),
      };
      if (_latitude != null && _longitude != null) {
        fields['latitude'] = _latitude;
        fields['longitude'] = _longitude;
        if (_accuracy != null) {
          fields['gps_accuracy'] = _accuracy;
        }
      }
      final res = await context.read<ApiClient>().postForm(
            AppConfig.customerSavePath,
            csrf: s.csrf,
            fields: fields,
          );
      if (!mounted) return;
      showSnack(context, (res['message'] ?? 'تم إضافة العميل').toString());
      final cust = res['customer'];
      if (cust is Map && context.canPop()) {
        context.pop({
          'id': (cust['id'] as num?)?.toInt() ?? 0,
          'name': (cust['name'] ?? name).toString(),
          'code': (cust['code'] ?? '').toString(),
        });
      } else if (context.canPop()) {
        context.pop(true);
      }
    } on ApiException catch (e) {
      if (!mounted) return;
      showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final hasGps = _latitude != null && _longitude != null;
    return MobileScaffold(
      title: const Text('إضافة عميل'),
      body: ListView(
        padding: const EdgeInsets.all(14),
        children: [
          AppCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                const Text(
                  'سيُربط العميل تلقائياً بمندوبك المسجّل على الحساب.',
                  style: TextStyle(
                    fontSize: 13,
                    color: AppTheme.textSoft,
                    height: 1.4,
                  ),
                ),
                const SizedBox(height: 14),
                TextField(
                  controller: _name,
                  textInputAction: TextInputAction.next,
                  decoration: const InputDecoration(
                    labelText: 'اسم العميل *',
                    prefixIcon: Icon(Icons.person_rounded),
                  ),
                ),
                const SizedBox(height: 10),
                TextField(
                  controller: _phone,
                  keyboardType: TextInputType.phone,
                  textDirection: TextDirection.ltr,
                  textInputAction: TextInputAction.next,
                  decoration: const InputDecoration(
                    labelText: 'رقم التلفون',
                    prefixIcon: Icon(Icons.phone_rounded),
                  ),
                ),
                const SizedBox(height: 10),
                TextField(
                  controller: _address,
                  minLines: 2,
                  maxLines: 4,
                  decoration: const InputDecoration(
                    labelText: 'العنوان',
                    prefixIcon: Icon(Icons.location_on_outlined),
                    alignLabelWithHint: true,
                  ),
                ),
                const SizedBox(height: 14),
                Text(
                  'موقع العميل (GPS)',
                  style: Theme.of(context).textTheme.titleSmall?.copyWith(
                        fontWeight: FontWeight.w700,
                      ),
                ),
                const SizedBox(height: 6),
                Text(
                  _gpsLabel,
                  style: const TextStyle(
                    fontSize: 13,
                    color: AppTheme.textSoft,
                  ),
                ),
                const SizedBox(height: 8),
                OutlinedButton.icon(
                  onPressed: (_saving || _locating) ? null : _pickLocation,
                  icon: _locating
                      ? const SizedBox(
                          width: 18,
                          height: 18,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : const Icon(Icons.my_location_rounded),
                  label: Text(
                      _locating ? 'جاري تحديد الموقع...' : 'تحديد الموقع'),
                ),
                if (hasGps) ...[
                  const SizedBox(height: 6),
                  TextButton.icon(
                    onPressed: _saving ? null : _clearLocation,
                    icon: const Icon(Icons.clear_rounded),
                    label: const Text('مسح الموقع'),
                  ),
                ],
                const SizedBox(height: 16),
                FilledButton.icon(
                  onPressed: _saving || _locating ? null : _save,
                  icon: _saving
                      ? const SizedBox(
                          width: 18,
                          height: 18,
                          child: CircularProgressIndicator(
                            strokeWidth: 2,
                            color: Colors.white,
                          ),
                        )
                      : const Icon(Icons.save_rounded),
                  label: Text(_saving ? 'جاري الحفظ...' : 'حفظ العميل'),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
