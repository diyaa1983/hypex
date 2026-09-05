import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/format.dart';
import '../../core/payment_period.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import 'package:latlong2/latlong.dart';

import '../../offline/offline_controller.dart';
import '../../offline/offline_store.dart';
import '../../services/location_service.dart';
import '../../widgets/location_map_picker.dart';
import '../../widgets/async_view.dart';
import '../../widgets/mobile_scaffold.dart';
import '../../widgets/ui_kit.dart';

class CustomerAddScreen extends StatefulWidget {
  const CustomerAddScreen({super.key});

  @override
  State<CustomerAddScreen> createState() => _CustomerAddScreenState();
}

class _CustomerAddScreenState extends State<CustomerAddScreen> {
  final _search = TextEditingController();
  final _name = TextEditingController();
  final _phone = TextEditingController();
  final _address = TextEditingController();

  List<Map<String, dynamic>> _customers = [];
  bool _listLoading = false;
  String? _listError;

  int? _editId;
  String _editCode = '';
  bool _editPendingOracle = false;

  bool _saving = false;
  bool _locating = false;
  double? _latitude;
  double? _longitude;
  double? _accuracy;
  double? _origLat;
  double? _origLng;
  bool _hadSavedGps = false;
  String _paymentPeriod = PaymentPeriod.defaultCode;

  bool get _isEdit => _editId != null && _editId != 0;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _loadCustomers());
  }

  @override
  void dispose() {
    _search.dispose();
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

  Future<void> _loadCustomers() async {
    final offline = context.read<OfflineController>();
    final q = _search.text.trim();
    setState(() {
      _listLoading = _customers.isEmpty;
      _listError = null;
    });
    try {
      if (!offline.online) {
        if (!offline.catalogReady) {
          if (!mounted) return;
          setState(() {
            _listLoading = false;
            _listError = 'لا توجد بيانات محلية. حدّث البيانات وأنت متصل.';
          });
          return;
        }
        final rows = await OfflineStore.instance.searchCustomers(q, limit: 2000);
        if (!mounted) return;
        setState(() {
          _customers = rows
              .map(
                (e) => <String, dynamic>{
                  'id': e['id'],
                  'name': e['name'],
                  'code': e['code'],
                  'phone': e['phone'],
                  'address': e['address'],
                  'latitude': e['latitude'],
                  'longitude': e['longitude'],
                  'payment_period': e['payment_period'],
                  'payment_period_label':
                      PaymentPeriod.labelOf(e['payment_period']),
                },
              )
              .toList();
          _listLoading = false;
          _listError = _customers.isEmpty ? 'لا يوجد عملاء.' : null;
        });
        return;
      }

      final res = await context.read<ApiClient>().getJson(
            AppConfig.partiesPath,
            query: {'type': 'customer', 'q': q},
          );
      final list = (res['parties'] as List? ?? [])
          .whereType<Map>()
          .map((e) => e.cast<String, dynamic>())
          .toList();
      if (!mounted) return;
      if (q.isEmpty) {
        await OfflineStore.instance.replaceCustomersFromLive(list);
      }
      setState(() {
        _customers = list;
        _listLoading = false;
        _listError = list.isEmpty ? 'لا يوجد عملاء.' : null;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      if (offline.catalogReady) {
        final rows =
            await OfflineStore.instance.searchCustomers(q, limit: 2000);
        setState(() {
          _customers = rows
              .map(
                (e) => <String, dynamic>{
                  'id': e['id'],
                  'name': e['name'],
                  'code': e['code'],
                  'phone': e['phone'],
                  'address': e['address'],
                  'latitude': e['latitude'],
                  'longitude': e['longitude'],
                  'payment_period': e['payment_period'],
                  'payment_period_label':
                      PaymentPeriod.labelOf(e['payment_period']),
                },
              )
              .toList();
          _listLoading = false;
          _listError = null;
        });
      } else {
        setState(() {
          _listLoading = false;
          _listError = e.message;
        });
      }
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _listLoading = false;
        _listError = e.toString();
      });
    }
  }

  void _startNew() {
    setState(() {
      _editId = null;
      _editCode = '';
      _editPendingOracle = false;
      _name.clear();
      _phone.clear();
      _address.clear();
      _latitude = null;
      _longitude = null;
      _accuracy = null;
      _origLat = null;
      _origLng = null;
      _hadSavedGps = false;
      _paymentPeriod = PaymentPeriod.defaultCode;
    });
  }

  void _selectCustomer(Map<String, dynamic> c) {
    final lat = c['latitude'] != null ? Fmt.toDouble(c['latitude']) : null;
    final lng = c['longitude'] != null ? Fmt.toDouble(c['longitude']) : null;
    final hasGps = lat != null && lng != null;
    setState(() {
      _editId = Fmt.toInt(c['id']);
      _editCode = Fmt.str(c['code']);
      _editPendingOracle = c['pending_oracle_link'] == true ||
          _editCode.isEmpty ||
          _editCode.startsWith('P-');
      _name.text = Fmt.str(c['name']);
      _phone.text = Fmt.str(c['phone']);
      _address.text = Fmt.str(c['address'] ?? c['address_ar']);
      _latitude = hasGps ? lat : null;
      _longitude = hasGps ? lng : null;
      _accuracy = null;
      _origLat = _latitude;
      _origLng = _longitude;
      _hadSavedGps = hasGps;
      final pay = PaymentPeriod.codeOf(c['payment_period']);
      _paymentPeriod = pay.isEmpty ? PaymentPeriod.defaultCode : pay;
    });
  }

  Future<void> _pickOnMap() async {
    final hasLoc = _latitude != null && _longitude != null;
    final start = hasLoc
        ? LatLng(_latitude!, _longitude!)
        : const LatLng(31.9539, 35.9106);
    final picked = await pickLocationOnMap(
      context,
      initial: start,
      hasInitialLocation: hasLoc,
    );
    if (picked == null || !mounted) return;
    setState(() {
      _latitude = picked.latitude;
      _longitude = picked.longitude;
      _accuracy = null;
    });
    showSnack(context, 'تم تحديد الموقع من الخريطة.');
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
      showSnack(context, 'تم تحديد موقعك الحالي.');
    } catch (e) {
      if (!mounted) return;
      showSnack(context, LocationService.friendlyError(e), error: true);
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

  bool _gpsChanged() {
    final nowHas = _latitude != null && _longitude != null;
    if (_hadSavedGps != nowHas) return true;
    if (!_hadSavedGps || _latitude == null || _longitude == null) return false;
    return (_latitude! - (_origLat ?? _latitude!)).abs() > 1e-6 ||
        (_longitude! - (_origLng ?? _longitude!)).abs() > 1e-6;
  }

  Future<void> _save() async {
    if (_isEdit) {
      await _saveEdit();
    } else {
      await _saveNew();
    }
  }

  Future<void> _saveNew() async {
    final name = _name.text.trim();
    if (name.isEmpty) {
      showSnack(context, 'أدخل اسم العميل', error: true);
      return;
    }
    if (PaymentPeriod.codeOf(_paymentPeriod).isEmpty) {
      showSnack(context, 'اختر فترة السداد', error: true);
      return;
    }
    final s = context.read<SessionController>();
    final offline = context.read<OfflineController>();
    setState(() => _saving = true);
    try {
      final fields = <String, dynamic>{
        'name_ar': name,
        'phone': _phone.text.trim(),
        'address_ar': _address.text.trim(),
        'payment_period': _paymentPeriod,
      };
      if (_latitude != null && _longitude != null) {
        fields['latitude'] = _latitude;
        fields['longitude'] = _longitude;
        if (_accuracy != null) {
          fields['gps_accuracy'] = _accuracy;
        }
      }

      Future<void> saveLocal() async {
        final store = OfflineStore.instance;
        final localId = await store.nextLocalCustomerId();
        await store.upsertLocalCustomer(
          id: localId,
          name: name,
          phone: _phone.text.trim(),
          address: _address.text.trim(),
          latitude: _latitude,
          longitude: _longitude,
          paymentPeriod: _paymentPeriod,
        );
        fields['local_customer_id'] = localId;
        await offline.enqueue(
          kind: 'customer_save',
          path: AppConfig.customerSavePath,
          body: fields,
          method: 'POST_FORM',
        );
        if (!mounted) return;
        showSnack(
          context,
          'حُفظ العميل محلياً — سيُرحَّل تلقائياً عند عودة الاتصال.',
        );
        await _loadCustomers();
        _startNew();
      }

      if (!offline.online && offline.catalogReady) {
        await saveLocal();
        return;
      }
      if (!offline.online && !offline.catalogReady) {
        showSnack(
          context,
          'لا اتصال ولا بيانات محلية. حدّث البيانات أولاً.',
          error: true,
        );
        return;
      }

      try {
        final res = await context.read<ApiClient>().postForm(
              AppConfig.customerSavePath,
              csrf: s.csrf,
              fields: fields,
            );
        if (!mounted) return;
        showSnack(context, (res['message'] ?? 'تم إضافة العميل').toString());
        await _loadCustomers();
        _startNew();
      } on ApiException catch (e) {
        if (offline.catalogReady &&
            (e.message.contains('تعذر الاتصال') ||
                e.message.contains('الإنترنت'))) {
          await saveLocal();
        } else {
          if (!mounted) return;
          showSnack(context, e.message, error: true);
        }
      }
    } on ApiException catch (e) {
      if (!mounted) return;
      showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  Future<void> _saveEdit() async {
    final id = _editId ?? 0;
    if (id == 0) return;
    if (PaymentPeriod.codeOf(_paymentPeriod).isEmpty) {
      showSnack(context, 'اختر فترة السداد', error: true);
      return;
    }
    final s = context.read<SessionController>();
    final offline = context.read<OfflineController>();

    if (_hadSavedGps && _gpsChanged()) {
      final go = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: const Text('تعديل موقع العميل'),
          content: const Text(
            'الموقع محفوظ مسبقاً. سيتم إرسال التعديل لمدير المبيعات للاعتماد، ولن يتغيّر موقع العميل حتى تتم الموافقة.',
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: const Text('إلغاء'),
            ),
            FilledButton(
              onPressed: () => Navigator.pop(ctx, true),
              child: const Text('إرسال للاعتماد'),
            ),
          ],
        ),
      );
      if (go != true || !mounted) return;
    }

    setState(() => _saving = true);
    try {
      final fields = <String, dynamic>{
        'id': id,
        'phone': _phone.text.trim(),
        'address_ar': _address.text.trim(),
        'payment_period': _paymentPeriod,
      };
      if (_latitude != null && _longitude != null) {
        fields['latitude'] = _latitude;
        fields['longitude'] = _longitude;
        if (_accuracy != null) fields['gps_accuracy'] = _accuracy;
      } else {
        fields['clear_gps'] = '1';
      }

      Future<void> saveLocal({String? note}) async {
        await OfflineStore.instance.upsertLocalCustomer(
          id: id,
          name: _name.text.trim(),
          code: _editCode,
          phone: _phone.text.trim(),
          address: _address.text.trim(),
          latitude: _latitude,
          longitude: _longitude,
          paymentPeriod: _paymentPeriod,
        );
        await offline.enqueue(
          kind: 'customer_update',
          path: AppConfig.customerUpdatePath,
          body: fields,
          method: 'POST_FORM',
        );
        if (!mounted) return;
        showSnack(
          context,
          note ?? 'حُفظ التعديل محلياً — سيُرحَّل تلقائياً عند عودة الاتصال.',
        );
        _origLat = _latitude;
        _origLng = _longitude;
        _hadSavedGps = _latitude != null && _longitude != null;
        await _loadCustomers();
      }

      if (!offline.online && offline.catalogReady) {
        await saveLocal();
        return;
      }

      try {
        final res = await context.read<ApiClient>().postForm(
              AppConfig.customerUpdatePath,
              csrf: s.csrf,
              fields: fields,
            );
        if (!mounted) return;
        final msg = Fmt.str(res['message']);
        showSnack(
          context,
          msg.isEmpty ? 'تم حفظ بيانات العميل.' : msg,
        );
        if (res['pending'] == true) {
          setState(() {
            _latitude = _origLat;
            _longitude = _origLng;
            _accuracy = null;
          });
        } else {
          _origLat = _latitude;
          _origLng = _longitude;
          _hadSavedGps = _latitude != null && _longitude != null;
        }
        await _loadCustomers();
      } on ApiException catch (e) {
        if (offline.catalogReady &&
            (e.message.contains('تعذر الاتصال') ||
                e.message.contains('الإنترنت'))) {
          await saveLocal(note: 'لا اتصال — حُفظ التعديل محلياً.');
        } else if (mounted) {
          showSnack(context, e.message, error: true);
        }
      }
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final hasGps = _latitude != null && _longitude != null;
    return MobileScaffold(
      title: Text(_isEdit ? 'تعديل عميل' : 'إضافة / تعديل عميل'),
      body: ListView(
        padding: const EdgeInsets.all(14),
        children: [
          AppCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: TextField(
                        controller: _search,
                        textInputAction: TextInputAction.search,
                        decoration: const InputDecoration(
                          labelText: 'بحث عن عميل',
                          prefixIcon: Icon(Icons.search_rounded),
                          isDense: true,
                        ),
                        onSubmitted: (_) => _loadCustomers(),
                      ),
                    ),
                    const SizedBox(width: 8),
                    IconButton.filledTonal(
                      onPressed: _listLoading ? null : _loadCustomers,
                      icon: _listLoading
                          ? const SizedBox(
                              width: 18,
                              height: 18,
                              child: CircularProgressIndicator(strokeWidth: 2),
                            )
                          : const Icon(Icons.refresh_rounded),
                      tooltip: 'تحديث',
                    ),
                  ],
                ),
                const SizedBox(height: 8),
                FilledButton.tonalIcon(
                  onPressed: _saving ? null : _startNew,
                  icon: const Icon(Icons.person_add_alt_1_rounded),
                  label: const Text('عميل جديد'),
                ),
                const SizedBox(height: 8),
                if (_listError != null)
                  Text(
                    _listError!,
                    style: const TextStyle(color: AppTheme.danger, fontSize: 13),
                  ),
                SizedBox(
                  height: 180,
                  child: _listLoading && _customers.isEmpty
                      ? const Center(child: CircularProgressIndicator())
                      : ListView.separated(
                          itemCount: _customers.length,
                          separatorBuilder: (_, __) =>
                              const Divider(height: 1),
                          itemBuilder: (ctx, i) {
                            final c = _customers[i];
                            final id = Fmt.toInt(c['id']);
                            final selected = _editId == id;
                            final pay =
                                PaymentPeriod.displayOf(c['payment_period']);
                            return ListTile(
                              dense: true,
                              selected: selected,
                              selectedTileColor:
                                  AppTheme.primary.withValues(alpha: 0.08),
                              title: Text(
                                Fmt.str(c['name']),
                                style: const TextStyle(
                                  fontWeight: FontWeight.w700,
                                  fontSize: 14,
                                ),
                              ),
                              subtitle: Text(
                                [
                                  if (Fmt.str(c['code']).isNotEmpty)
                                    Fmt.str(c['code']),
                                  if (pay != '—') pay,
                                ].join(' · '),
                                style: const TextStyle(fontSize: 12),
                              ),
                              trailing: selected
                                  ? const Icon(Icons.check_circle,
                                      color: AppTheme.primary)
                                  : const Icon(Icons.edit_outlined, size: 18),
                              onTap: _saving
                                  ? null
                                  : () => _selectCustomer(c),
                            );
                          },
                        ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 12),
          AppCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  _isEdit
                      ? (_editPendingOracle
                          ? 'تعديل عميل — بانتظار ربط Oracle'
                          : 'تعديل عميل — $_editCode')
                      : 'سيُربط العميل تلقائياً بمندوبك. الرقم سيُحدَّد لاحقاً من Oracle.',
                  style: const TextStyle(
                    fontSize: 13,
                    color: AppTheme.textSoft,
                    height: 1.4,
                  ),
                ),
                const SizedBox(height: 14),
                Text(
                  'فترة السداد *',
                  style: Theme.of(context).textTheme.titleSmall?.copyWith(
                        fontWeight: FontWeight.w700,
                      ),
                ),
                const SizedBox(height: 6),
                ...PaymentPeriod.options.entries.map(
                  (e) => RadioListTile<String>(
                    value: e.key,
                    groupValue: _paymentPeriod,
                    onChanged: _saving || _locating
                        ? null
                        : (v) {
                            if (v != null) {
                              setState(() => _paymentPeriod = v);
                            }
                          },
                    title: Text(e.value),
                    contentPadding: EdgeInsets.zero,
                    dense: true,
                  ),
                ),
                const SizedBox(height: 8),
                TextField(
                  controller: _name,
                  enabled: !_isEdit,
                  textInputAction: TextInputAction.next,
                  decoration: InputDecoration(
                    labelText: _isEdit ? 'اسم العميل' : 'اسم العميل *',
                    prefixIcon: const Icon(Icons.person_rounded),
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
                if (_isEdit) ...[
                  const SizedBox(height: 4),
                  Text(
                    _hadSavedGps
                        ? 'الموقع محفوظ. أي تعديل لاحق يُرسل لمدير المبيعات للاعتماد.'
                        : 'الحفظ الأول للموقع يتم مباشرة.',
                    style: const TextStyle(
                      fontSize: 12,
                      color: AppTheme.textSoft,
                    ),
                  ),
                ],
                const SizedBox(height: 6),
                Text(
                  _gpsLabel,
                  style: const TextStyle(
                    fontSize: 13,
                    color: AppTheme.textSoft,
                  ),
                ),
                const SizedBox(height: 8),
                Row(
                  children: [
                    Expanded(
                      child: OutlinedButton.icon(
                        onPressed: (_saving || _locating) ? null : _pickOnMap,
                        icon: const Icon(Icons.map_rounded),
                        label: const Text('تحديد على الخريطة'),
                      ),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: OutlinedButton.icon(
                        onPressed:
                            (_saving || _locating) ? null : _pickLocation,
                        icon: _locating
                            ? const SizedBox(
                                width: 18,
                                height: 18,
                                child:
                                    CircularProgressIndicator(strokeWidth: 2),
                              )
                            : const Icon(Icons.my_location_rounded),
                        label: Text(
                          _locating ? 'جاري تحديد الموقع...' : 'موقعي الحالي',
                        ),
                      ),
                    ),
                  ],
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
                  label: Text(
                    _saving
                        ? 'جاري الحفظ...'
                        : (_isEdit ? 'حفظ التعديل' : 'حفظ العميل'),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
